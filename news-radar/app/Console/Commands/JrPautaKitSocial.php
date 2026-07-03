<?php

namespace App\Console\Commands;

use App\Services\Jr\JanelaSilencio;
use App\Services\Jr\JuizLlm;
use App\Services\Jr\ZapRascunhos;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * BLOCO 5 (03/07) — KIT SOCIAL: matéria nova publicada no site (jr_publicado,
 * espelho do jrlink:publicados-sync) vira um [KIT] de divulgação no grupo
 * RASCUNHOS: legenda IG rascunho + 3 hashtags locais + título/subtítulo pro
 * card do Gerador v3. O HUMANO posta — cross-posting é MANUAL, o comando não
 * toca em rede social nenhuma.
 *
 * Insumo do LLM é SÓ o que jr_publicado tem (título + categoria + slug + data):
 * o prompt proíbe inventar fato — legenda informativa a partir do título, sem
 * enfeitar. BLOCO 8a: mensagem LIMPA — legenda final pronta pra colar, zero
 * meta-nota no conteúdo; "revisar antes de postar" mora na linha operacional.
 *
 * GUARD-RAILS: dedup 1 kit por slug (jr_kit_social, gravado só após envio OK —
 * falha reaparece no próximo ciclo), janela de silêncio (Bloco 0b), fail-closed
 * sem credencial Z-API/grupo (imprime e não envia, não grava).
 *   --dry    : gera e imprime o kit, sem enviar e sem gravar dedup
 *   --slug=  : força uma matéria específica (ainda respeita o dedup)
 *   --max=   : quantas matérias recentes sem kit processar (default 1)
 */
class JrPautaKitSocial extends Command
{
    protected $signature = 'jrpauta:kit-social '
        .'{--dry : gera e imprime o kit, não envia nem grava dedup} '
        .'{--slug= : slug específico de jr_publicado} '
        .'{--max=1 : máx de matérias recentes sem kit por run}';

    protected $description = 'Gera [KIT] de divulgação (legenda IG + hashtags + card Gerador v3) da matéria nova publicada e entrega no grupo RASCUNHOS. Humano posta; cross-posting manual.';

    private const SITE_BASE = 'https://jornaldetijucas.com.br';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');

        if (! $dry && JanelaSilencio::ativa(config('radar_civico.alertas'))) {
            $this->info('Silêncio: kit social espera o dia acordar.');

            return self::SUCCESS;
        }

        $materias = $this->materias();
        if ($materias->isEmpty()) {
            $this->info('Nenhuma matéria publicada sem kit — nada a fazer.');

            return self::SUCCESS;
        }

        $juiz = new JuizLlm;
        $zap = new ZapRascunhos;
        $grupo = (string) config('radar_civico.canais.rascunhos');
        $modelo = (string) config('radar_civico.rascunho.modelo', 'claude-opus-4-8');

        foreach ($materias as $m) {
            $this->info(sprintf('Gerando kit: "%s" (%s)', mb_strimwidth((string) $m->titulo, 0, 70, '…'), $m->slug));

            $kit = $this->gerar($juiz, $m, $modelo);
            if (! $kit) {
                $this->warn("LLM falhou em {$m->slug} — pulo (tenta no próximo ciclo).");

                continue;
            }
            $msg = $this->montar($m, $kit);

            if ($dry) {
                $this->line('');
                $this->line('───── [DRY] kit gerado (não enviado, não gravado) ─────');
                $this->line($msg);
                $this->line('────────────────────────────────────────────────────────');

                continue;
            }

            // fail-closed: sem credencial/grupo o kit NÃO circula e NÃO grava dedup
            if (! $zap->configurado() || $grupo === '') {
                $this->warn('[SEM CREDENCIAL Z-API/grupo] Kit gerado mas NÃO enviado:');
                $this->line($msg);

                continue;
            }

            $messageId = $zap->texto($msg, $grupo);
            if ($messageId === null) {
                $this->error("Z-API recusou {$m->slug} — nada registrado, tenta no próximo ciclo.");

                continue;
            }

            DB::table('jr_kit_social')->insertOrIgnore([
                'slug' => $m->slug,
                'message_id' => $messageId,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
            $this->info("[KIT] {$m->slug} entregue (messageId {$messageId}).");
        }

        return self::SUCCESS;
    }

    /**
     * Matérias alvo: --slug força uma; default = mais recentes (publicado_em
     * desc) SEM kit em jr_kit_social, limitadas por --max. Dedup vale sempre.
     */
    private function materias(): Collection
    {
        $base = DB::table('jr_publicado')
            ->leftJoin('jr_kit_social', 'jr_kit_social.slug', '=', 'jr_publicado.slug')
            ->whereNull('jr_kit_social.id')
            ->select('jr_publicado.slug', 'jr_publicado.titulo', 'jr_publicado.categoria', 'jr_publicado.publicado_em');

        if (($slug = trim((string) $this->option('slug'))) !== '') {
            $row = $base->where('jr_publicado.slug', $slug)->first();
            if (! $row) {
                $ja = DB::table('jr_kit_social')->where('slug', $slug)->exists();
                $this->warn($ja
                    ? "Slug '{$slug}' já tem kit (jr_kit_social) — máx 1 por matéria."
                    : "Slug '{$slug}' não existe em jr_publicado.");
            }

            return collect($row ? [$row] : []);
        }

        $max = max(1, (int) $this->option('max'));

        return $base->orderByDesc('jr_publicado.publicado_em')->limit($max)->get();
    }

    /**
     * Kit via LLM (mesmo padrão de chamada/parse do RascunhoCivico::gerar).
     * Devolve ['legenda','hashtags'(array 3),'card_titulo','card_subtitulo'] ou [].
     */
    private function gerar(JuizLlm $juiz, object $m, string $modelo): array
    {
        $out = $juiz->completarJson($this->prompt($m), 'kit_social', 1, $modelo);

        // completarJson devolve array; pode vir [{...}] ou {...}
        $r = $out[0] ?? $out;
        if (! is_array($r) || trim((string) ($r['legenda'] ?? '')) === '') {
            return [];
        }

        // BLOCO 8a: legenda FINAL limpa — meta-nota de revisão sai do conteúdo
        // (vai pra linha operacional do montar), o humano copia e cola direto.
        $legenda = trim(preg_replace('/\n*✏️\s*revisar antes de postar\s*$/u', '', trim((string) $r['legenda'])));

        // 3 hashtags, todas com # na frente, sem espaço interno
        $tags = array_values(array_filter(array_map(function ($t) {
            $t = preg_replace('/\s+/', '', trim((string) $t));

            return $t === '' || $t === '#' ? null : (str_starts_with($t, '#') ? $t : '#'.$t);
        }, (array) ($r['hashtags'] ?? []))));
        $tags = array_slice($tags ?: ['#Tijucas', '#SC', '#JornalRazao'], 0, 3);

        return [
            'legenda' => $legenda,
            'hashtags' => $tags,
            // limites duros do card do Gerador v3 (o prompt já pede curto)
            'card_titulo' => mb_strimwidth(trim((string) ($r['card_titulo'] ?? $m->titulo)), 0, 60, '…'),
            'card_subtitulo' => mb_strimwidth(trim((string) ($r['card_subtitulo'] ?? '')), 0, 90, '…'),
        ];
    }

    /**
     * BLOCO 8a — mensagem LIMPA (pronta pra copiar e colar): legenda final +
     * hashtags + link + card, e UMA linha operacional depois do separador.
     * Zero meta-nota no conteúdo — [KIT]/avisos moram na linha operacional.
     */
    private function montar(object $m, array $kit): string
    {
        $link = self::SITE_BASE.'/'.$m->slug.'/';

        $linhas = [
            $kit['legenda'],
            '',
            implode(' ', $kit['hashtags']),
            '',
            '🔗 '.$link,
            '',
            'Card: '.$kit['card_titulo'],
        ];
        if ($kit['card_subtitulo'] !== '') {
            $linhas[] = $kit['card_subtitulo'];
        }
        $linhas[] = '───';
        $linhas[] = '🤖 kit social · revisar antes de postar · humano posta';

        return implode("\n", $linhas);
    }

    private function prompt(object $m): string
    {
        $categoria = trim((string) ($m->categoria ?? '')) ?: '—';
        $quando = trim((string) ($m->publicado_em ?? '')) ?: '—';
        $titulo = trim((string) $m->titulo);

        return <<<PROMPT
Você é o social media do Jornal Razão (jornalismo local sério de Santa Catarina,
região de Tijucas). Uma matéria ACABOU DE SER PUBLICADA no site e você monta o
KIT de divulgação pro Instagram. Você só tem o TÍTULO e a CATEGORIA — nada mais.

REGRAS (inegociáveis):
- ZERO fato inventado: use SOMENTE a informação que está no título. Não acrescente
  número, nome, causa, desfecho, local ou detalhe que o título não diga. Não
  enfeite, não especule, não prometa ("saiba mais" pode; "veja as fotos" NÃO,
  você não sabe se tem foto).
- Tom sóbrio e informativo do JR: direto, sem sensacionalismo, sem caixa alta,
  sem excesso de emoji (no máximo 1, e só se couber bem). Português do Brasil.
- Legenda IG: 2 a 4 frases curtas reafirmando o fato do título e convidando a
  ler a matéria completa no site (link na bio). Entregue a legenda FINAL, pronta
  pra colar — NENHUMA nota interna, instrução ou aviso de revisão no texto.
- Hashtags: EXATAMENTE 3, LOCAIS — a cidade se o título/slug indicar uma
  (ex.: #Tijucas, #PortoBelo, #NovaTrento, #CanelinhaSC, #SaoJoaoBatista,
  #Bombinhas), sempre #SC, e uma da editoria/tema (ex.: #Seguranca, #Transito,
  #Educacao). Sem espaço, sem acento problemático se preferir.
- Card do Gerador v3: título com NO MÁXIMO 60 caracteres e subtítulo com NO
  MÁXIMO 90 — pode reescrever/encurtar o título da matéria, mas sem mudar o fato.

MATÉRIA:
Título: {$titulo}
Categoria: {$categoria}
Slug: {$m->slug}
Publicada em: {$quando}

Responda APENAS com um array JSON de UM objeto, sem comentários, exatamente assim:
[{"legenda":"... (legenda final, sem nota interna)","hashtags":["#Cidade","#SC","#Tema"],"card_titulo":"... (<=60 chars)","card_subtitulo":"... (<=90 chars)"}]
PROMPT;
    }
}
