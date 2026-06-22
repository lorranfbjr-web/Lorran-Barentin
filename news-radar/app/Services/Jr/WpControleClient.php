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
