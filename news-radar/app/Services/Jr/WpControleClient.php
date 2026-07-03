<?php

namespace App\Services\Jr;

use Illuminate\Support\Facades\Http;

/**
 * Cliente do WP de CONTROLE (controle.jornalrazao.com) via REST. Esta fase só
 * cria RASCUNHO (status=draft) — NUNCA publica. Autentica com Application
 * Password (Basic auth) do usuário JR_WP_USER (lorran). Lê credencial do .env.
 *
 * Editoria → categoria (id WP). Fallback geral. Byline institucional via author.
 */
class WpControleClient
{
    private string $base;
    private string $user;
    private string $appPassword;

    /** editoria (slug do reescritor) → category id no controle. */
    private const CATEGORIA = [
        'seguranca' => 1, 'politica' => 2, 'economia' => 38, 'saude' => 8,
        'educacao' => 9, 'transito' => 1195, 'infraestrutura' => 1287,
        'meioambiente' => 15, 'cultura' => 10, 'esporte' => 37,
        'entretenimento' => 36, 'turismo' => 1198, 'tecnologia' => 7,
        'geral' => 19538,
    ];

    /** Conta institucional "Redação" (id 39) — byline dos rascunhos do elo. */
    public const AUTOR_BYLINE = 39;

    public function __construct()
    {
        $this->base = rtrim((string) env('JR_WP_REST_BASE', ''), '/');
        $this->user = (string) env('JR_WP_USER', '');
        $this->appPassword = (string) env('JR_WP_APP_PASSWORD', '');
    }

    public function categoriaId(string $editoria): int
    {
        return self::CATEGORIA[$editoria] ?? self::CATEGORIA['geral'];
    }

    /**
     * BLOCO 6 (simplificar 03/07): nome de tag → id (busca; cria se não
     * existir; "term_exists" devolve o id existente). Fail-open por tag.
     *
     * @return int[]
     */
    public function tagIds(array $nomes): array
    {
        $ids = [];
        foreach (array_unique($nomes) as $nome) {
            try {
                $r = $this->req()->get($this->base . '/wp/v2/tags', ['search' => $nome, 'per_page' => 10, '_fields' => 'id,name']);
                $id = null;
                foreach ((array) $r->json() as $t) {
                    if (mb_strtolower((string) ($t['name'] ?? '')) === mb_strtolower($nome)) {
                        $id = (int) $t['id'];
                        break;
                    }
                }
                if (! $id) {
                    $c = $this->req()->post($this->base . '/wp/v2/tags', ['name' => $nome]);
                    $id = (int) ($c->json('id') ?: data_get($c->json(), 'data.term_id', 0));
                }
                if ($id) {
                    $ids[] = $id;
                }
            } catch (\Throwable) {
                // tag nunca bloqueia o draft
            }
        }

        return $ids;
    }

    /** Confirma auth (read-only). @return array{ok:bool,id:?int,roles:array} */
    public function whoAmI(): array
    {
        $r = $this->req()->get($this->base . '/wp/v2/users/me', ['context' => 'edit', '_fields' => 'id,slug,roles']);

        return ['ok' => $r->successful(), 'id' => $r->json('id'), 'roles' => $r->json('roles') ?? []];
    }

    /**
     * Cria um RASCUNHO. Retorna {id, edit_url, link, status}.
     *
     * @param  array  $r  saída do PautaReescritor (titulo, linha_fina, materia, editoria, tags)
     *
     * @throws \RuntimeException em falha HTTP
     */
    public function criarRascunho(array $r, string $capturaMessageId): array
    {
        $body = $this->montarCorpo($r['materia'], $r['lacunas'] ?? [], $capturaMessageId, $r['cidade'] ?? null);

        $payload = [
            'status'   => 'draft',                 // SEMPRE rascunho nesta fase
            'title'    => $r['titulo'],
            'content'  => $body,
            'excerpt'  => $r['linha_fina'] ?? '',
            'author'   => self::AUTOR_BYLINE,
            'categories' => [$this->categoriaId($r['editoria'] ?? 'geral')],
        ];

        // GOAL SIMPLIFICAR (03/07) — BLOCO 6: TAGS no draft (antes eram
        // ignoradas). Cidade sempre vira tag (o controle não tem categoria por
        // cidade — cidades vivem como tags). Falha de tag NUNCA bloqueia o draft.
        $tags = array_filter(array_map('trim', array_merge(
            (array) ($r['tags'] ?? []),
            [$r['cidade'] ?? ''],
        )));
        if ($tags && ($ids = $this->tagIds($tags)) !== []) {
            $payload['tags'] = $ids;
        }

        $resp = $this->req()->post($this->base . '/wp/v2/posts', $payload);
        if (! $resp->successful()) {
            throw new \RuntimeException('WP POST falhou HTTP ' . $resp->status() . ': ' . mb_substr($resp->body(), 0, 300));
        }

        $id = (int) $resp->json('id');

        return [
            'id'       => $id,
            'status'   => (string) $resp->json('status'),
            'link'     => (string) $resp->json('link'),
            'edit_url' => $this->base === '' ? '' : preg_replace('#/wp-json$#', '', $this->base) . '/wp-admin/post.php?post=' . $id . '&action=edit',
        ];
    }

    /**
     * PEÇA 3 — sobe a foto na biblioteca de mídia do controle e devolve o id.
     * Crédito vai no caption (legenda visível) e no alt (acessibilidade) —
     * preservar crédito de foto oficial é obrigatório.
     *
     * @return array{id:int, source_url:string}
     *
     * @throws \RuntimeException em falha HTTP
     */
    public function uploadMidia(string $absPath, string $filename, string $credito, string $altText): array
    {
        if (! is_file($absPath)) {
            throw new \RuntimeException('arquivo de mídia não existe: ' . $absPath);
        }
        $bin = (string) file_get_contents($absPath);
        $mime = function_exists('mime_content_type') ? (mime_content_type($absPath) ?: 'image/jpeg') : 'image/jpeg';

        $resp = $this->req()
            ->withHeaders([
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Content-Type' => $mime,
            ])
            ->withBody($bin, $mime)
            ->post($this->base . '/wp/v2/media');

        if (! $resp->successful()) {
            throw new \RuntimeException('WP media upload falhou HTTP ' . $resp->status() . ': ' . mb_substr($resp->body(), 0, 300));
        }
        $id = (int) $resp->json('id');

        // crédito no caption + alt + title (legenda visível e acessibilidade).
        $leg = $credito ? ('Foto: ' . $credito) : '';
        $this->req()->post($this->base . '/wp/v2/media/' . $id, [
            'caption' => $leg,
            'alt_text' => $altText !== '' ? $altText : $leg,
            'title' => $altText !== '' ? $altText : $leg,
            'description' => $leg,
        ]);

        return ['id' => $id, 'source_url' => (string) $resp->json('source_url')];
    }

    /** PEÇA 3 — seta a foto destacada do post. Mantém status=draft (não publica). */
    public function setFeaturedMedia(int $postId, int $mediaId): void
    {
        $resp = $this->req()->post($this->base . '/wp/v2/posts/' . $postId, [
            'featured_media' => $mediaId,
        ]);
        if (! $resp->successful()) {
            throw new \RuntimeException('WP set featured_media falhou HTTP ' . $resp->status() . ': ' . mb_substr($resp->body(), 0, 300));
        }
    }

    /** GET de um post (campos para o revisor pós-post — Peça 4). */
    public function getPost(int $postId): array
    {
        $r = $this->req()->get($this->base . '/wp/v2/posts/' . $postId, [
            'context' => 'edit',
            '_fields' => 'id,status,title,content,excerpt,categories,featured_media',
        ]);
        if (! $r->successful()) {
            throw new \RuntimeException('WP GET post falhou HTTP ' . $r->status());
        }

        return $r->json() ?? [];
    }

    /** Legenda (caption) de um item de mídia — pra o revisor checar o crédito. */
    public function getMediaCaption(int $mediaId): string
    {
        $r = $this->req()->get($this->base . '/wp/v2/media/' . $mediaId, ['context' => 'edit', '_fields' => 'caption,alt_text']);
        if (! $r->successful()) {
            return '';
        }

        return trim(strip_tags((string) ($r->json('caption.rendered') ?? $r->json('caption.raw') ?? '')));
    }

    /**
     * Atualiza o corpo (content) de um draft. Usado pra (a) inserir a linha de
     * crédito da foto e (b) auto-fix seguro do revisor (Peça 4). Mantém draft.
     */
    public function atualizarConteudo(int $postId, string $rawContent): void
    {
        $resp = $this->req()->post($this->base . '/wp/v2/posts/' . $postId, [
            'content' => $rawContent,
        ]);
        if (! $resp->successful()) {
            throw new \RuntimeException('WP update content falhou HTTP ' . $resp->status() . ': ' . mb_substr($resp->body(), 0, 300));
        }
    }

    /** Raw content (edit context) de um post. */
    public function getRawContent(int $postId): string
    {
        $r = $this->req()->get($this->base . '/wp/v2/posts/' . $postId, ['context' => 'edit', '_fields' => 'content']);

        return (string) ($r->json('content.raw') ?? '');
    }

    /** Corpo HTML: matéria + rodapé de proveniência (rascunho interno). */
    private function montarCorpo(string $materia, array $lacunas, string $msgId, ?string $cidade): string
    {
        $paras = array_filter(array_map('trim', preg_split('/\n\n+/', $materia)));
        $html = '';
        foreach ($paras as $p) {
            $html .= '<p>' . e($p) . "</p>\n";
        }

        $nota = '<!-- RASCUNHO AUTOMÁTICO · elo jrpauta:publicar-rascunho · captura=' . $msgId
            . ($cidade ? ' · cidade=' . $cidade : '') . ' · revisar antes de publicar -->';

        if ($lacunas) {
            $li = '';
            foreach ($lacunas as $l) {
                $li .= '<li>' . e($l) . '</li>';
            }
            // Bloco de revisão (some na publicação se o editor apagar) — não é corpo.
            $nota .= "\n<!-- LACUNAS A APURAR:\n" . strip_tags(str_replace('</li>', "\n", $li)) . "\n-->";
        }

        return $nota . "\n" . $html;
    }

    private function req()
    {
        return Http::withBasicAuth($this->user, $this->appPassword)
            ->acceptJson()
            ->timeout(30);
    }
}
