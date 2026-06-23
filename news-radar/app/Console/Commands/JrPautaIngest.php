<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class JrPautaIngest extends Command
{
    protected $signature = 'jrpauta:ingest '
        . '{--dir= : Override do diretório de captura (default: storage/app/jr-pauta-capture)} '
        . '{--incremental : Só processa arquivos novos desde o último cursor (pro scheduler — evita reler o backlog inteiro)}';

    protected $description = 'Lê os JSON crus de captura de pauta (Z-API) e os ingere, idempotente, em jr_pauta_capturas. Somente leitura dos arquivos crus; não envia nada.';

    /** Regex da NOSSA saída do disparador (ruído). */
    private const JR_REGEX = '/Jornal Raz[aã]o\s*#JR\d+/iu';

    /**
     * Mapeamento best-effort cidade -> regex de token literal no nome do grupo.
     * Ordem importa (Balneário Camboriú antes de Camboriú). NÃO inventa cidade:
     * só casa quando o token aparece literalmente.
     */
    private const CIDADE_PATTERNS = [
        ['Balneário Camboriú', '/balne[aá]rio\s*cambori[uú]|\bbc\b/iu'],
        ['Itajaí',             '/itaja[ií]/iu'],
        ['Florianópolis',      '/florian[oó]polis|floripa/iu'],
        ['São José',           '/s[aã]o\s*jos[eé]/iu'],
        ['Camboriú',           '/cambori[uú]/iu'],
        ['Penha',              '/\bpenha\b/iu'],
        ['Itapema',            '/itapema/iu'],
        ['Navegantes',         '/navegantes/iu'],
        ['Porto Belo',         '/porto\s*belo/iu'],
        ['Bombinhas',          '/bombinhas/iu'],
        ['Brusque',            '/brusque/iu'],
        ['Tijucas',            '/tijucas/iu'],
        ['Itapoá',             '/itapo[aá]/iu'],
        ['Barra Velha',        '/barra\s*velha/iu'],
        ['Blumenau',           '/blumenau/iu'],
        ['Joinville',          '/joinville/iu'],
        ['Lages',              '/\blages\b/iu'],
    ];

    public function handle(): int
    {
        $dir = $this->option('dir') ?: storage_path('app/jr-pauta-capture');
        $base = storage_path('app');

        if (! is_dir($dir)) {
            $this->error("Diretório não encontrado: {$dir}");
            return self::FAILURE;
        }

        $files = glob(rtrim($dir, '/') . '/*.json');
        sort($files);

        // Modo incremental (scheduler): só processa o que chegou depois do
        // último cursor. Os nomes começam com Ymd-His, então comparar por
        // basename já é cronológico. Evita reler os ~56k arquivos a cada run.
        $cursorPath = rtrim($dir, '/') . '/.ingest-cursor';
        $cursor = null;
        if ($this->option('incremental')) {
            $cursor = is_file($cursorPath) ? trim((string) file_get_contents($cursorPath)) : '';
            if ($cursor !== '') {
                $files = array_values(array_filter($files, fn ($p) => basename($p) > $cursor));
            }
        }

        $total = count($files);
        $this->info("Encontrados {$total} arquivos em {$dir}"
            . ($this->option('incremental') ? " (incremental desde \"{$cursor}\")" : ''));

        $now = Carbon::now();
        $rows = [];
        $skippedParse = 0;
        $skippedPriv = 0;
        $maxBasename = $cursor ?? '';

        foreach ($files as $path) {
            // cursor avança por TODO arquivo visto (inclusive descartado), pra o
            // incremental não reprocessar o que já foi avaliado.
            $bn = basename($path);
            if ($bn > $maxBasename) {
                $maxBasename = $bn;
            }

            $json = json_decode((string) file_get_contents($path), true);
            if (! is_array($json)) {
                $skippedParse++;
                $this->warn("JSON inválido, ignorado (não apagado): {$path}");
                continue;
            }

            $p = isset($json['all']) && is_array($json['all']) ? $json['all'] : [];

            // FILTRO DE PRIVACIDADE (Parte A): defesa em profundidade — mesmo que
            // um arquivo individual/denylist tenha escapado pro disco (backlog
            // anterior ao filtro da rota), aqui ele NÃO vira linha no banco.
            $chatNameRaw = $p['chatName'] ?? null;
            $isGroupRaw  = (bool) ($p['isGroup'] ?? false);
            if (! \App\Services\Jr\CapturaFiltro::aceita($chatNameRaw, $isGroupRaw)) {
                $skippedPriv++;
                continue;
            }

            // raw_path relativo a storage/app quando possível.
            $rawPath = str_starts_with($path, $base . '/')
                ? substr($path, strlen($base) + 1)
                : $path;

            // message_id: usa o do Z-API; fallback estável pelo nome do arquivo
            // para NÃO descartar capturas sem messageId e manter idempotência.
            $messageId = $p['messageId'] ?? $p['id'] ?? $json['messageId'] ?? null;
            if (! $messageId) {
                $messageId = 'nomsgid:' . basename($path);
            }

            $chatName = $p['chatName'] ?? null;
            $isGroup  = (bool) ($p['isGroup'] ?? false);

            $fonteTipo = $this->classificarFonte($chatName, $isGroup);
            [$tipoConteudo, $texto] = $this->classificarConteudo($p);
            $cidade = $fonteTipo === 'imprensa_oficial' ? $this->detectarCidade($chatName) : null;

            $rows[$messageId] = [
                'message_id'      => $messageId,
                'momment'         => is_numeric($p['momment'] ?? null) ? (int) $p['momment'] : null,
                'chat_name'       => $chatName,
                'sender_name'     => $p['senderName'] ?? null,
                'is_group'        => $isGroup,
                'from_me'         => (bool) ($p['fromMe'] ?? false),
                'is_status_reply' => (bool) ($p['isStatusReply'] ?? false),
                'is_newsletter'   => (bool) ($p['isNewsletter'] ?? false),
                'fonte_tipo'      => $fonteTipo,
                'tipo_conteudo'   => $tipoConteudo,
                'fonte_cidade'    => $cidade,
                'texto'           => $texto,
                'raw_path'        => $rawPath,
                'created_at'      => $now,
                'updated_at'      => $now,
            ];
        }

        // Upsert idempotente: conflito em message_id atualiza tudo menos created_at.
        $payload = array_values($rows);
        $upsertCols = [
            'momment', 'chat_name', 'sender_name', 'is_group', 'from_me',
            'is_status_reply', 'is_newsletter', 'fonte_tipo', 'tipo_conteudo',
            'fonte_cidade', 'texto', 'raw_path', 'updated_at',
        ];
        foreach (array_chunk($payload, 200) as $chunk) {
            DB::table('jr_pauta_capturas')->upsert($chunk, ['message_id'], $upsertCols);
        }

        // Grava o cursor (último basename visto) pro próximo run incremental.
        if ($this->option('incremental') && $maxBasename !== '') {
            @file_put_contents($cursorPath, $maxBasename);
        }

        $this->info(sprintf(
            'Ingestão concluída. Inseridos/atualizados: %d · cortados por privacidade (individual/denylist): %d · JSON inválido: %d.',
            count($payload), $skippedPriv, $skippedParse
        ));
        $this->relatorio();

        return self::SUCCESS;
    }

    private function classificarFonte(?string $chatName, bool $isGroup): string
    {
        if ($chatName !== null && preg_match(self::JR_REGEX, $chatName)) {
            return 'distribuicao_jr';
        }
        if ($isGroup) {
            return 'imprensa_oficial';
        }
        return 'individual';
    }

    /** @return array{0:string,1:?string} [tipo_conteudo, texto] */
    private function classificarConteudo(array $p): array
    {
        // texto pode vir como string (testes locais) ou {message:...} (Z-API).
        $texto = null;
        if (isset($p['text'])) {
            if (is_array($p['text'])) {
                $texto = $p['text']['message'] ?? null;
            } elseif (is_string($p['text'])) {
                $texto = $p['text'];
            }
            if ($texto !== null && trim($texto) !== '') {
                return ['texto', $texto];
            }
        }
        if (isset($p['audio'])) {
            return ['audio', null];
        }
        if (isset($p['image'])) {
            $cap = is_array($p['image']) ? ($p['image']['caption'] ?? null) : null;
            return ['imagem', $cap];
        }
        if (isset($p['sticker'])) {
            return ['sticker', null];
        }
        // Demais (video, document, reaction, notification, etc.) => outro,
        // aproveitando legenda quando houver.
        $cap = null;
        foreach (['video', 'document'] as $k) {
            if (isset($p[$k]) && is_array($p[$k]) && ! empty($p[$k]['caption'])) {
                $cap = $p[$k]['caption'];
                break;
            }
        }
        return ['outro', $cap];
    }

    private function detectarCidade(?string $chatName): ?string
    {
        if (! $chatName) {
            return null;
        }
        foreach (self::CIDADE_PATTERNS as [$cidade, $regex]) {
            if (preg_match($regex, $chatName)) {
                return $cidade;
            }
        }
        return null;
    }

    private function relatorio(): void
    {
        $t = 'jr_pauta_capturas';
        $total = DB::table($t)->count();

        $this->newLine();
        $this->line('========== RELATÓRIO jr_pauta_capturas ==========');
        $this->line("TOTAL GERAL: {$total}");

        $this->newLine();
        $this->line('-- Por fonte_tipo --');
        foreach (DB::table($t)->select('fonte_tipo', DB::raw('count(*) c'))
                    ->groupBy('fonte_tipo')->orderByDesc('c')->get() as $r) {
            $this->line(sprintf('%-20s %d', $r->fonte_tipo, $r->c));
        }

        $this->newLine();
        $this->line('-- Por tipo_conteudo --');
        foreach (DB::table($t)->select('tipo_conteudo', DB::raw('count(*) c'))
                    ->groupBy('tipo_conteudo')->orderByDesc('c')->get() as $r) {
            $this->line(sprintf('%-20s %d', $r->tipo_conteudo, $r->c));
        }

        $this->newLine();
        $this->line('-- TOP 15 chatName (volume) --');
        foreach (DB::table($t)->select('chat_name', DB::raw('count(*) c'))
                    ->groupBy('chat_name')->orderByDesc('c')->limit(15)->get() as $r) {
            $this->line(sprintf('%4d  %s', $r->c, $r->chat_name ?? '(null)'));
        }

        $this->newLine();
        $this->line('-- TOP 15 grupos de imprensa (volume) --');
        foreach (DB::table($t)->where('fonte_tipo', 'imprensa_oficial')
                    ->select('chat_name', DB::raw('count(*) c'))
                    ->groupBy('chat_name')->orderByDesc('c')->limit(15)->get() as $r) {
            $this->line(sprintf('%4d  %s', $r->c, $r->chat_name ?? '(null)'));
        }

        $this->newLine();
        $this->line('-- fonte_cidade preenchida vs null --');
        $comCidade = DB::table($t)->whereNotNull('fonte_cidade')->count();
        $semCidade = DB::table($t)->whereNull('fonte_cidade')->count();
        $this->line("preenchida: {$comCidade}");
        $this->line("null:       {$semCidade}");
        $this->newLine();
        $this->line('-- fonte_cidade por valor (imprensa) --');
        foreach (DB::table($t)->whereNotNull('fonte_cidade')
                    ->select('fonte_cidade', DB::raw('count(*) c'))
                    ->groupBy('fonte_cidade')->orderByDesc('c')->get() as $r) {
            $this->line(sprintf('%4d  %s', $r->c, $r->fonte_cidade));
        }
        $this->line('=================================================');
    }
}
