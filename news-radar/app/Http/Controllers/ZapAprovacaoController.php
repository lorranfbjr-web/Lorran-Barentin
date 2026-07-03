<?php

namespace App\Http\Controllers;

use App\Services\Jr\WpControleClient;
use App\Services\Jr\ZapRascunhos;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * BLOCO 2 (03/07) — APROVAÇÃO POR ✅ NO WHATSAPP (flywheel salto 2).
 *
 * Webhook on-message-received da instância de ALERTA ("3…", JRLINK_ALERT_ZAPI_*)
 * — NUNCA da 276 (captura) nem da 884 (disparador). Fluxo: no grupo RASCUNHOS,
 * responder ✅ (reply em cima da mensagem [AUTO]/Mesa; reação também vale) →
 * casa com a entrega pelo messageId citado (jr_rascunho_entregas) → cria
 * DRAFT no WP (WpControleClient — status draft SEMPRE, trava dura do dia) →
 * responde no grupo com o link do admin. ❌ → marca descartado (feedback).
 *
 * SEGURANÇA (todas as camadas, fail-closed):
 *   1. token secreto no path (hash_equals; sem env = rota morta 404);
 *   2. instanceId do payload TEM que ser a instância de alerta;
 *   3. só mensagens do grupo RASCUNHOS (phone == canal configurado);
 *   4. allowlist de aprovadores (RADAR_CIVICO_APROVADORES, participantPhone);
 *   5. idempotente: 2º ✅ na mesma entrega devolve o draft existente, não duplica.
 * Payload cru vai pra storage/app/jr-zap-aprovacao/ (auditoria/debug).
 */
class ZapAprovacaoController extends Controller
{
    public function __invoke(Request $request, string $token)
    {
        $cfg = (array) config('radar_civico.aprovacao');
        $esperado = (string) ($cfg['hook_token'] ?? '');
        if ($esperado === '' || ! hash_equals($esperado, $token)) {
            abort(404); // rota "não existe" pra quem não tem o token
        }

        $p = $request->json()->all();
        $this->arquivar($p);

        // 2) só eventos da instância de ALERTA (a "3…")
        $instancia = (string) config('radar_civico.rascunho.instance');
        if ($instancia === '' || (string) ($p['instanceId'] ?? '') !== $instancia) {
            return response()->json(['ok' => true, 'skip' => 'instancia']);
        }

        // 3) só o grupo RASCUNHOS
        $grupo = (string) config('radar_civico.canais.rascunhos');
        if (! ($p['isGroup'] ?? false) || $grupo === '' || (string) ($p['phone'] ?? '') !== $grupo) {
            return response()->json(['ok' => true, 'skip' => 'grupo']);
        }

        // sinal: ✅/❌ em texto de reply OU reação
        [$sinal, $refId] = $this->sinal($p);
        if ($sinal === null || $refId === '') {
            return response()->json(['ok' => true, 'skip' => 'sem-sinal']);
        }

        // 4) allowlist de aprovadores
        $quem = (string) ($p['participantPhone'] ?? '');
        $aprovadores = (array) ($cfg['aprovadores'] ?? []);
        if ($quem === '' || ! in_array($quem, $aprovadores, true)) {
            Log::info('jr-zap-aprovacao: sinal de não-aprovador ignorado', ['quem' => mb_substr($quem, 0, 4).'****']);

            return response()->json(['ok' => true, 'skip' => 'aprovador']);
        }

        // casa pela mensagem de TEXTO ou pela FOTO (Bloco 8: o rascunho são
        // duas mensagens — ✅ em qualquer uma vale; a foto chega primeiro e é
        // o alvo natural da reação).
        $entrega = DB::table('jr_rascunho_entregas')->where('message_id', $refId)->first()
            ?? DB::table('jr_rascunho_entregas')
                ->whereRaw("json_extract(payload, '$.foto_message_id') = ?", [$refId])
                ->first();
        if (! $entrega) {
            // ✅ em REPLY (gesto deliberado de aprovar) que não casa com nada:
            // avisa em vez de morrer em silêncio — o aprovador acharia que
            // aprovou. Reação solta em outra mensagem segue skip silencioso.
            $foiReply = (string) ($p['referenceMessageId'] ?? '') === $refId;
            if ($sinal === 'aprova' && $foiReply) {
                $zapAviso = new ZapRascunhos;
                if ($zapAviso->configurado() && $grupo !== '') {
                    $zapAviso->texto('⚠️ Esse ✅ não casou com nenhum rascunho meu — responda (reply) em cima da mensagem do rascunho.', $grupo);
                }
            }

            return response()->json(['ok' => true, 'skip' => 'ref-desconhecida']);
        }

        $zap = new ZapRascunhos;

        if ($sinal === 'nao') {
            if ($entrega->descartado_em === null) {
                DB::table('jr_rascunho_entregas')->where('id', $entrega->id)->update([
                    'descartado_em' => Carbon::now(),
                    'aprovado_por' => $quem,
                    'updated_at' => Carbon::now(),
                ]);
                // aprende: descarte vira feedback pro ajuste fino do gate
                Log::info('jr-zap-aprovacao: descartado', ['ato_ref' => $entrega->ato_ref, 'gate' => $entrega->gate_motivo]);
            }

            return response()->json(['ok' => true, 'acao' => 'descartado']);
        }

        // ✅ — idempotente: draft já existe? só reafirma no grupo, não duplica.
        if ($entrega->wp_post_id) {
            return response()->json(['ok' => true, 'acao' => 'ja-tinha-draft', 'wp_post_id' => $entrega->wp_post_id]);
        }

        try {
            $draft = $this->criarDraft($entrega);
        } catch (\Throwable $e) {
            Log::error('jr-zap-aprovacao: WP draft falhou', ['ato_ref' => $entrega->ato_ref, 'err' => mb_substr($e->getMessage(), 0, 200)]);
            if ($zap->configurado() && $grupo !== '') {
                $zap->texto("⚠️ Não consegui criar o draft de {$entrega->ato_ref} no WP — ver log.", $grupo);
            }

            return response()->json(['ok' => false, 'erro' => 'wp'], 200); // 200 pro Z-API não reentregar
        }

        DB::table('jr_rascunho_entregas')->where('id', $entrega->id)->update([
            'wp_post_id' => $draft['id'],
            'aprovado_por' => $quem,
            'aprovado_em' => Carbon::now(),
            'descartado_em' => null,
            'updated_at' => Carbon::now(),
        ]);

        // BLOCO 8c: foto oficial vira featured image do draft (caption =
        // crédito). Falha de upload NUNCA bloqueia o draft — só avisa.
        [$mediaId, $avisoFoto] = $this->anexarFoto($entrega, $draft['id']);

        if ($zap->configurado() && $grupo !== '') {
            $zap->texto("📝 Draft no WP (rascunho, NÃO publicado): {$draft['edit_url']}"
                .($avisoFoto !== '' ? "\n{$avisoFoto}" : '')
                ."\n_{$entrega->ato_ref} aprovado por ✅_", $grupo);
        }

        return response()->json(['ok' => true, 'acao' => 'draft-criado', 'wp_post_id' => $draft['id'], 'wp_media_id' => $mediaId]);
    }

    /**
     * ✅ = aprova, ❌ = descarta. Aceita reply de texto (referenceMessageId
     * top-level, formato real dos payloads Z-API capturados) e reação
     * (reaction.value + reaction.referencedMessage.messageId).
     *
     * @return array{0: ?string, 1: string} [sinal aprova|nao|null, messageId citado]
     */
    private function sinal(array $p): array
    {
        $aprova = ['✅', '✔️', '✔'];
        $nega = ['❌', '❎', '✖️'];

        $texto = trim((string) data_get($p, 'text.message', ''));
        $refTexto = (string) ($p['referenceMessageId'] ?? '');
        if ($texto !== '' && $refTexto !== '') {
            if (in_array($texto, $aprova, true)) {
                return ['aprova', $refTexto];
            }
            if (in_array($texto, $nega, true)) {
                return ['nao', $refTexto];
            }
        }

        $reacao = trim((string) data_get($p, 'reaction.value', ''));
        $refReacao = (string) data_get($p, 'reaction.referencedMessage.messageId', '');
        if ($reacao !== '' && $refReacao !== '') {
            if (in_array($reacao, $aprova, true)) {
                return ['aprova', $refReacao];
            }
            if (in_array($reacao, $nega, true)) {
                return ['nao', $refReacao];
            }
        }

        return [null, ''];
    }

    /**
     * DRAFT no WP a partir do payload estruturado da entrega; entregas antigas
     * (payload NULL) caem no texto formatado da fila da Mesa. Status draft
     * SEMPRE — publicar é decisão do Lorran, no admin.
     */
    private function criarDraft(object $entrega): array
    {
        $payload = $entrega->payload ? (array) json_decode($entrega->payload, true) : [];

        if (! empty($payload['titulo'])) {
            $r = [
                'titulo' => (string) $payload['titulo'],
                'linha_fina' => (string) ($payload['lead'] ?? ''),
                'materia' => trim(($payload['lead'] ?? '')."\n\n".($payload['corpo'] ?? '')),
                'lacunas' => (array) ($payload['checklist'] ?? []),
                'cidade' => (string) ($payload['municipio'] ?? ''),
                'editoria' => 'geral',
            ];
        } else {
            $fila = DB::table('jr_pauta_fila')->where('ato_ref', $entrega->ato_ref)->first();
            $texto = (string) ($fila->rascunho ?? '');
            if ($texto === '') {
                throw new \RuntimeException('entrega sem payload e sem rascunho na fila');
            }
            $r = [
                'titulo' => mb_substr((string) ($fila->objeto ?: $entrega->ato_ref), 0, 120),
                'linha_fina' => '',
                'materia' => $texto,
                'lacunas' => ['Entrega antiga sem payload estruturado — revisar formatação.'],
                'cidade' => (string) ($fila->municipio ?? ''),
                'editoria' => 'geral',
            ];
        }

        return app(WpControleClient::class)->criarRascunho($r, 'zap-aprovacao:'.$entrega->message_id);
    }

    /**
     * BLOCO 8c — sobe a foto oficial (guardada pelo auto-rascunho em
     * storage/app/foto-oficial, caminho no payload) pro WP media e seta como
     * featured image do draft. Fail-open: qualquer falha devolve aviso de 1
     * linha e o draft segue sem foto.
     *
     * @return array{0: ?int, 1: string} [media_id, aviso pro grupo ('' = ok)]
     */
    private function anexarFoto(object $entrega, int $postId): array
    {
        $payload = $entrega->payload ? (array) json_decode($entrega->payload, true) : [];
        $rel = (string) ($payload['foto_path'] ?? '');
        if ($rel === '') {
            return [null, ''];  // entrega sem foto oficial — nada a subir
        }
        $abs = storage_path('app/'.ltrim($rel, '/'));

        try {
            $wp = app(WpControleClient::class);
            $media = $wp->uploadMidia(
                $abs,
                basename($abs),
                (string) ($payload['foto_credito'] ?? ''),
                (string) ($payload['titulo'] ?? '')
            );
            $wp->setFeaturedMedia($postId, $media['id']);

            // BLOCO 6 (simplificar 03/07): crédito da foto TAMBÉM no corpo
            // (antes só caption/alt — dependia do tema exibir). Igual ao
            // pipeline frio (inserirCreditoCorpo). Fail-open.
            $credito = trim((string) ($payload['foto_credito'] ?? ''));
            if ($credito !== '') {
                try {
                    $wp->atualizarConteudo($postId,
                        $wp->getRawContent($postId) . "\n<p><em>Foto: " . e($credito) . '</em></p>');
                } catch (\Throwable) {
                    // crédito no corpo nunca bloqueia o draft
                }
            }

            return [$media['id'], ''];
        } catch (\Throwable $e) {
            Log::warning('jr-zap-aprovacao: foto não subiu pro WP', ['ato_ref' => $entrega->ato_ref, 'err' => mb_substr($e->getMessage(), 0, 200)]);

            return [null, '⚠️ foto oficial não subiu — draft criado SEM imagem destacada.'];
        }
    }

    /** Auditoria: payload cru (JSON) com carimbo — espelha o padrão da captura. */
    private function arquivar(array $p): void
    {
        try {
            $dir = storage_path('app/jr-zap-aprovacao');
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $nome = now()->format('Ymd-His').'-'.substr(bin2hex(random_bytes(3)), 0, 4).'.json';
            @file_put_contents($dir.'/'.$nome, json_encode(['received_at' => now()->toIso8601String(), 'all' => $p], JSON_UNESCAPED_UNICODE));
        } catch (\Throwable) {
            // auditoria nunca derruba o hook
        }
    }
}
