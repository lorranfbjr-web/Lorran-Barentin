<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * Publica os relatórios DOM/SC (markdown em /home/jr/goals) como HTML mobile-first
 * em public/, pra abrir pelo link. Aditivo/isolado; só lê os .md e gera .html.
 * Regenerável: rode de novo após editar os relatórios.
 */
class JrDomRelatorioHtml extends Command
{
    protected $signature = 'jr:dom-relatorio-html';

    protected $description = 'Gera HTML mobile-first dos relatórios DOM/SC (RELATORIO + SCOPING) em public/.';

    /** [arquivo md => [destino html, título]] */
    private const MAPA = [
        '/home/jr/goals/RELATORIO-dom-producao.md' => ['relatorio-dom-producao.html', 'Relatório · DOM/SC Produção 24/7'],
        '/home/jr/goals/SCOPING-cobertura-dom.md' => ['scoping-cobertura-dom.html', 'Cobertura DOM/SC · cidades prioritárias'],
    ];

    public function handle(): int
    {
        $conv = new GithubFlavoredMarkdownConverter([
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
        ]);

        $gerados = [];
        foreach (self::MAPA as $md => [$destino, $titulo]) {
            if (! is_file($md)) {
                $this->warn("Não achei {$md} — pulando.");

                continue;
            }
            $corpo = (string) $conv->convert(file_get_contents($md));
            $html = $this->moldura($titulo, $corpo);
            $caminho = public_path($destino);
            file_put_contents($caminho, $html);
            $gerados[] = $destino;
            $this->info("✓ {$destino}");
        }

        if ($gerados) {
            $this->newLine();
            $this->info('No ar:');
            foreach ($gerados as $g) {
                $this->line('  ' . rtrim((string) config('app.url'), '/') . '/' . $g);
            }
        }

        return self::SUCCESS;
    }

    private function moldura(string $titulo, string $corpo): string
    {
        $gerado = now()->format('d/m/Y H:i');

        return <<<HTML
<!DOCTYPE html><html lang="pt-BR"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>{$titulo}</title>
<style>
:root{--navy:#0D2481;--red:#E63946;--green:#2D6A4F;--amber:#D4A373;--ink:#16181d;--muted:#6b7280;--line:#e6e8ee;--bg:#f5f6fa;--card:#fff}
*{box-sizing:border-box}body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:var(--bg);color:var(--ink);line-height:1.55;font-size:15px}
header{background:var(--navy);color:#fff;padding:16px}
header h1{margin:0;font-size:17px;font-weight:800}header .sub{font-size:12px;opacity:.82;margin-top:3px}
.wrap{max-width:820px;margin:0 auto;padding:16px}
.md{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:16px 16px 24px}
.md h1{font-size:21px;margin:18px 0 8px;color:var(--navy);line-height:1.25}
.md h1:first-child{margin-top:0}
.md h2{font-size:16px;margin:22px 0 8px;color:var(--navy);border-top:1px solid var(--line);padding-top:16px}
.md h3{font-size:14px;margin:16px 0 6px}
.md p{margin:9px 0}
.md ul,.md ol{padding-left:20px;margin:8px 0}
.md li{margin:4px 0}
.md a{color:var(--navy);word-break:break-word}
.md code{background:#eef1f8;color:var(--navy);padding:1px 5px;border-radius:5px;font-size:12.5px}
.md strong{font-weight:700}
.md hr{border:none;border-top:1px solid var(--line);margin:18px 0}
.md blockquote{border-left:3px solid var(--amber);background:#fff7ed;margin:10px 0;padding:8px 12px;border-radius:0 8px 8px 0;color:#7a3f12}
.md table{border-collapse:collapse;width:100%;font-size:12.5px;margin:12px 0;display:block;overflow-x:auto}
.md th{background:#f0f2f8;color:var(--navy);text-align:left;padding:7px 9px;white-space:nowrap}
.md td{padding:7px 9px;border-top:1px solid var(--line);vertical-align:top}
.md tr:nth-child(even) td{background:#fafbfe}
footer{padding:18px 16px 40px;text-align:center;color:var(--muted);font-size:11px}
</style></head><body>
<header><h1>📄 {$titulo}</h1><div class="sub">Jornal Razão · DOM/SC · uso interno</div></header>
<div class="wrap"><div class="md">{$corpo}</div></div>
<footer>Renderizado em {$gerado} · fonte markdown em /home/jr/goals</footer>
</body></html>
HTML;
    }
}
