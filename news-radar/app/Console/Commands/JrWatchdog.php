<?php

namespace App\Console\Commands;

use Cron\CronExpression;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * WATCHDOG v0 — Camada 0 (READ-ONLY). NÃO muta pipeline, NÃO notifica, NÃO toca
 * Z-API/dispatcher. Só LÊ o estado e materializa um dashboard de saúde:
 *   - lint de cron: comandos cujo minuto NÃO cai na grade /5 do timer systemd
 *     (OnCalendar *:00/5:10) — o footgun que travou a jrpauta:bridge-radar;
 *   - frescor por canal (feed/whatsapp/instagram) em jr_link_extracao;
 *   - estado dos serviços/timer (systemctl is-active, read-only);
 *   - taxa de erro 24h (news_source_runs) + último ciclo do juiz.
 *
 * Saída: public/health.html (clicável) + /home/jr/goals/WATCHDOG-estado.md.
 * Sinaliza nível (ok/warn/bad) por checagem; o CANAL de alerta fica pra decisão
 * do Lorran — este comando só pinta o quadro.
 */
class JrWatchdog extends Command
{
    protected $signature = 'jrlink:watchdog {--horas=24 : Janela de frescor pra marcar canal "morto"}';

    protected $description = 'Watchdog READ-ONLY: lint de cron + frescor de canais + saúde dos serviços. Gera public/health.html. Não muta nada, não notifica.';

    /** minutos em que o timer dispara o schedule:run (OnCalendar=*:00/5:10). */
    private const GRID_STEP = 5;

    public function handle(): int
    {
        $agora = Carbon::now();
        $checks = [];

        $checks['cron'] = $this->lintCron();
        $checks['canais'] = $this->frescorCanais((int) $this->option('horas'));
        $checks['whatsapp'] = $this->capturaWhatsapp();
        $checks['servicos'] = $this->servicos();
        $checks['pipeline'] = $this->pipeline();
        $checks['juiz'] = $this->juiz();
        $checks['civico'] = $this->civico();
        $checks['claude'] = $this->claudeCli();
        $checks['datas'] = $this->datasSuspeitas();
        $checks['alertazap'] = $this->alertaWhatsapp();

        $html = $this->renderHtml($checks, $agora);
        File::put(public_path('health.html'), $html);

        $md = $this->renderMd($checks, $agora);
        $mdPath = '/home/jr/goals/WATCHDOG-estado.md';
        @File::put($mdPath, $md);

        $this->info('Watchdog OK → public/health.html + '.$mdPath);
        foreach ($checks as $nome => $c) {
            $this->line(sprintf('  %-10s %s', $nome, $c['resumo'] ?? '-'));
        }

        return self::SUCCESS;
    }

    /** Lint: cada comando agendado cujo cron casa minuto fora da grade /5. */
    private function lintCron(): array
    {
        $rows = [];
        $offGrid = 0;
        try {
            $schedule = app(Schedule::class);
            foreach ($schedule->events() as $e) {
                $expr = $e->expression ?? '';
                $cmd = trim(str_replace([base_path(), '/home/jr/.local/php/php', "'", '"'], '', (string) ($e->command ?? $e->description ?? '?')));
                $minutos = $this->minutosDoCron($expr);
                $fora = array_values(array_filter($minutos, fn ($m) => $m % self::GRID_STEP !== 0));
                $ok = empty($fora);
                if (! $ok) {
                    $offGrid++;
                }
                $rows[] = [
                    'cmd' => preg_replace('/.*artisan\s*/', '', $cmd) ?: $cmd,
                    'cron' => $expr,
                    'ok' => $ok,
                    'fora' => $fora,
                ];
            }
        } catch (\Throwable $ex) {
            return ['nivel' => 'warn', 'resumo' => 'lint falhou: '.$ex->getMessage(), 'rows' => []];
        }

        return [
            'nivel' => $offGrid === 0 ? 'ok' : 'bad',
            'resumo' => $offGrid === 0
                ? count($rows).' comandos · todos na grade /5'
                : $offGrid.' comando(s) FORA da grade /5 (nunca rodam!)',
            'rows' => $rows,
        ];
    }

    /** Minutos (0-59) em que o cron dispara dentro de 1h. */
    private function minutosDoCron(string $expr): array
    {
        try {
            $c = new CronExpression($expr);
            $base = new \DateTime('2026-01-01 00:00:00');
            $fim = new \DateTime('2026-01-01 01:00:00');
            $mins = [];
            $next = $c->getNextRunDate($base, 0, true);
            $guard = 0;
            while ($next < $fim && $guard++ < 120) {
                $mins[(int) $next->format('i')] = true;
                $next = $c->getNextRunDate($next, 1, false);
            }

            return array_keys($mins);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function frescorCanais(int $horas): array
    {
        $limite = Carbon::now()->subHours($horas);
        $rows = [];
        $mortos = 0;
        try {
            $canais = DB::table('jr_link_extracao')
                ->select('origem', DB::raw('COUNT(*) as n'), DB::raw('MAX(created_at) as ultimo'))
                ->groupBy('origem')->get();
            foreach ($canais as $c) {
                $ult = $c->ultimo ? Carbon::parse($c->ultimo) : null;
                $fresco = $ult && $ult->greaterThanOrEqualTo($limite);
                // instagram está sabidamente fora (Apify) — marca warn, não bad.
                $nivel = $fresco ? 'ok' : ($c->origem === 'instagram' ? 'warn' : 'bad');
                if (! $fresco) {
                    $mortos++;
                }
                $rows[] = [
                    'canal' => $c->origem,
                    'total' => $c->n,
                    'ultimo' => $c->ultimo,
                    'idade_h' => $ult ? round($ult->diffInMinutes(Carbon::now()) / 60, 1) : null,
                    'nivel' => $nivel,
                ];
            }
        } catch (\Throwable $e) {
            return ['nivel' => 'warn', 'resumo' => 'falhou: '.$e->getMessage(), 'rows' => []];
        }

        return [
            'nivel' => $mortos === 0 ? 'ok' : 'warn',
            'resumo' => $mortos === 0 ? count($rows).' canais frescos' : $mortos.' canal(is) sem item em '.$horas.'h',
            'rows' => $rows,
        ];
    }

    private function servicos(): array
    {
        $units = ['newsradar-web', 'newsradar-worker', 'newsradar-scheduler.timer', 'caddy'];
        $rows = [];
        $down = 0;
        foreach ($units as $u) {
            $estado = 'desconhecido';
            try {
                $r = Process::timeout(8)->run(['systemctl', 'is-active', $u]);
                $estado = trim($r->output()) ?: trim($r->errorOutput());
            } catch (\Throwable $e) {
                $estado = 'erro';
            }
            $ok = $estado === 'active';
            if (! $ok) {
                $down++;
            }
            $rows[] = ['unit' => $u, 'estado' => $estado, 'nivel' => $ok ? 'ok' : 'bad'];
        }

        return [
            'nivel' => $down === 0 ? 'ok' : 'bad',
            'resumo' => $down === 0 ? 'todos active' : $down.' unit(s) fora',
            'rows' => $rows,
        ];
    }

    private function pipeline(): array
    {
        try {
            $desde = Carbon::now()->subHours(24);
            $runs = DB::table('news_source_runs')->where('created_at', '>=', $desde)
                ->selectRaw("SUM(status='success') ok, SUM(status='failed') fail, SUM(items_new) novos")
                ->first();
            $ok = (int) ($runs->ok ?? 0);
            $fail = (int) ($runs->fail ?? 0);
            $novos = (int) ($runs->novos ?? 0);
            $taxa = ($ok + $fail) > 0 ? round($fail * 100 / ($ok + $fail), 1) : 0;

            return [
                'nivel' => $taxa > 20 ? 'warn' : 'ok',
                'resumo' => "runs 24h: {$ok} ok · {$fail} fail ({$taxa}%) · {$novos} itens novos",
                'rows' => [['ok' => $ok, 'fail' => $fail, 'taxa' => $taxa, 'novos' => $novos]],
            ];
        } catch (\Throwable $e) {
            return ['nivel' => 'warn', 'resumo' => 'falhou: '.$e->getMessage(), 'rows' => []];
        }
    }

    private function juiz(): array
    {
        try {
            $u = DB::table('jr_juiz_log')->orderByDesc('id')->first();
            if (! $u) {
                return ['nivel' => 'warn', 'resumo' => 'sem ciclo registrado', 'rows' => []];
            }
            $idade = round(Carbon::parse($u->created_at)->diffInMinutes(Carbon::now()) / 60, 1);
            $hoje = DB::table('jr_juiz_log')->where('created_at', '>=', Carbon::now()->startOfDay())
                ->sum('custo_usd');

            return [
                'nivel' => $idade > 3 ? 'warn' : 'ok',
                'resumo' => sprintf('último %s (%sh atrás) · %s · US$ %.2f hoje', $u->operation, $idade, $u->model, (float) $hoje),
                'rows' => [(array) $u],
            ];
        } catch (\Throwable $e) {
            return ['nivel' => 'warn', 'resumo' => 'falhou: '.$e->getMessage(), 'rows' => []];
        }
    }

    /**
     * v1 — PONTO CEGO corrigido: mede a CAPTURA WhatsApp na fonte (mtime do último
     * raw do webhook + última linha de jr_pauta_capturas), não só pela
     * jr_link_extracao downstream (que some quando a ponte segura o release).
     */
    private function capturaWhatsapp(): array
    {
        $rows = [];
        try {
            $dir = storage_path('app/jr-pauta-capture');
            $rawIdadeMin = null;
            $r = Process::timeout(20)->run("ls -t " . escapeshellarg($dir) . " 2>/dev/null | head -1");
            $ultimo = trim($r->output());
            if ($ultimo !== '' && is_file($dir . '/' . $ultimo)) {
                $rawIdadeMin = (int) round((time() - filemtime($dir . '/' . $ultimo)) / 60);
            }

            $ultCaptura = DB::table('jr_pauta_capturas')->max('created_at');
            $capIdadeMin = $ultCaptura ? (int) Carbon::parse($ultCaptura)->diffInMinutes(Carbon::now()) : null;

            // raw parado = webhook/instância morta (bad); raw vivo mas tabela parada
            // = ingest travado (bad); os dois vivos = ok. Grupos têm horas quietas
            // de madrugada => tolerância de 4h no raw e 6h na tabela.
            $rawOk = $rawIdadeMin !== null && $rawIdadeMin <= 240;
            $capOk = $capIdadeMin !== null && $capIdadeMin <= 360;
            $nivel = ($rawOk && $capOk) ? 'ok' : 'bad';
            $resumo = sprintf(
                'raw webhook: %s · ingest (jr_pauta_capturas): %s',
                $rawIdadeMin === null ? 'NUNCA' : "há {$rawIdadeMin} min",
                $capIdadeMin === null ? 'NUNCA' : "há {$capIdadeMin} min"
            );
            if (! $rawOk) {
                $resumo .= ' — CAPTURA PARADA (webhook/instância)';
            } elseif (! $capOk) {
                $resumo .= ' — INGEST PARADO (raw chega, tabela não anda)';
            }
            $rows[] = ['raw_min' => $rawIdadeMin, 'tabela_min' => $capIdadeMin];

            return ['nivel' => $nivel, 'resumo' => $resumo, 'rows' => $rows];
        } catch (\Throwable $e) {
            return ['nivel' => 'warn', 'resumo' => 'falhou: ' . $e->getMessage(), 'rows' => []];
        }
    }

    /**
     * v1 — RADAR CÍVICO: frescor das 4 fontes + fila de scoring do DOM (atos sem
     * score na janela forward). Fim de semana/segunda cedo relaxa o limiar (as
     * fontes publicam em dia útil).
     */
    private function civico(): array
    {
        // [tabela, limiar_horas em dia útil]
        $fontes = [
            'dom' => ['jr_dom_atos', 8],
            'camara' => ['jr_camara_proposicoes', 48],
            'mpsc' => ['jr_mpsc_extratos', 30],
            'tce' => ['jr_tce_decisoes', 30],
            // BLOCO 3/6 (02/07): notícia institucional de prefeitura (4 ciclos/dia)
            'prefeitura' => ['jr_prefeitura_noticias', 30],
        ];
        $agora = Carbon::now();
        // sáb/dom/madrugada de segunda: nada publica => +48h de tolerância
        $folga = ($agora->isWeekend() || ($agora->isMonday() && $agora->hour < 12)) ? 48 : 0;

        $rows = [];
        $ruins = 0;
        try {
            foreach ($fontes as $nome => [$tabela, $limiar]) {
                $ult = DB::table($tabela)->max('created_at');
                $idadeH = $ult ? round(Carbon::parse($ult)->diffInMinutes($agora) / 60, 1) : null;
                $ok = $idadeH !== null && $idadeH <= ($limiar + $folga);
                if (! $ok) {
                    $ruins++;
                }
                $rows[] = ['fonte' => $nome, 'ultimo' => $ult, 'idade_h' => $idadeH, 'limiar_h' => $limiar + $folga, 'nivel' => $ok ? 'ok' : 'bad'];
            }

            // por-câmara (02/07): o max(created_at) global esconde câmara individual
            // parada — 12+ câmaras vivas agora (SAPL + Legislador + Itapema). Warn
            // informativo (>7d sem linha nova); não derruba o nível geral (câmara
            // pequena pode ficar dias sem projeto novo legitimamente).
            $porCamara = DB::table('jr_camara_proposicoes')
                ->selectRaw('municipio, max(created_at) as ult')
                ->groupBy('municipio')->orderBy('municipio')->get();
            foreach ($porCamara as $c) {
                $idadeH = $c->ult ? round(Carbon::parse($c->ult)->diffInMinutes($agora) / 60, 1) : null;
                $rows[] = [
                    'fonte' => "camara · {$c->municipio}",
                    'ultimo' => $c->ult, 'idade_h' => $idadeH, 'limiar_h' => 168,
                    'nivel' => ($idadeH !== null && $idadeH <= 168) ? 'ok' : 'warn',
                ];
            }

            // fila de scoring DOM (janela forward de 7d — excedente morre sem score)
            $filaHoje = DB::table('jr_dom_atos')->whereNull('score_pauta')
                ->whereDate('data_ato', $agora->toDateString())->count();
            $fila7d = DB::table('jr_dom_atos')->whereNull('score_pauta')
                ->where('created_at', '>=', $agora->copy()->subDays(7))->count();
            $nivelFila = $fila7d < 1000 ? 'ok' : ($fila7d < 2500 ? 'warn' : 'bad');
            if ($nivelFila === 'bad') {
                $ruins++;
            }
            $rows[] = ['fonte' => 'fila-scoring-dom', 'ultimo' => "hoje={$filaHoje} · 7d={$fila7d}", 'idade_h' => null, 'limiar_h' => null, 'nivel' => $nivelFila];

            $nivel = $ruins === 0 ? ($nivelFila === 'warn' ? 'warn' : 'ok') : 'bad';

            return [
                'nivel' => $nivel,
                'resumo' => $ruins === 0
                    ? "4 fontes frescas · fila scoring DOM: {$filaHoje} hoje / {$fila7d} na janela 7d"
                    : "{$ruins} problema(s) — ver tabela · fila DOM 7d={$fila7d}",
                'rows' => $rows,
            ];
        } catch (\Throwable $e) {
            return ['nivel' => 'warn', 'resumo' => 'falhou: ' . $e->getMessage(), 'rows' => []];
        }
    }

    /**
     * v1 — sessão claude-cli viva? O scoring cívico inteiro (Sonnet via claude-cli)
     * para em silêncio se o login Max expira. Ping barato de 1 turno.
     */
    private function claudeCli(): array
    {
        $modelo = (string) config('dom.scoring.modelo', 'claude-sonnet-4-6');
        if (! str_starts_with($modelo, 'claude')) {
            return ['nivel' => 'ok', 'resumo' => "scoring em OpenAI ({$modelo}) — ping claude-cli dispensado", 'rows' => []];
        }

        try {
            $t0 = microtime(true);
            $r = Process::timeout(90)->run([
                'claude', '-p', 'Responda somente: pong',
                '--model', (string) config('dom.scoring.modelo', 'claude-sonnet-4-6'),
                '--output-format', 'json',
                '--max-turns', '1',
            ]);
            $ms = (int) ((microtime(true) - $t0) * 1000);
            $json = json_decode(trim($r->output()), true);
            $vivo = $r->successful() && is_array($json) && ! ($json['is_error'] ?? false) && ($json['result'] ?? '') !== '';

            return [
                'nivel' => $vivo ? 'ok' : 'bad',
                'resumo' => $vivo
                    ? "sessão viva ({$ms} ms)"
                    : 'SESSÃO MORTA/EXPIRADA — scoring cívico parado (exit ' . $r->exitCode() . ': ' . mb_substr(trim($r->errorOutput() ?: $r->output()), 0, 160) . ')',
                'rows' => [],
            ];
        } catch (\Throwable $e) {
            return ['nivel' => 'bad', 'resumo' => 'ping falhou: ' . $e->getMessage(), 'rows' => []];
        }
    }

    /**
     * BLOCO 6 (02/07) — vigia de DATAS: se voltar a aparecer data futura/antiga
     * NÃO flagada (regressão de parser) ou uma leva grande de data_suspeita nas
     * últimas 24h (fonte publicando lixo), pinta warn/bad. READ-ONLY.
     */
    private function datasSuspeitas(): array
    {
        $tabelas = [
            'dom' => 'jr_dom_atos', 'camara' => 'jr_camara_proposicoes',
            'mpsc' => 'jr_mpsc_extratos', 'tce' => 'jr_tce_decisoes',
            'prefeitura' => 'jr_prefeitura_noticias',
        ];
        $rows = [];
        $furadas = 0;
        $suspeitas24h = 0;
        try {
            foreach ($tabelas as $nome => $t) {
                $semFlag = (int) DB::table($t)
                    ->where(fn ($q) => $q->where('data_pub', '>', Carbon::now()->addDays(2)->toDateString())
                        ->orWhere('data_pub', '<', '2015-01-01'))
                    ->where(fn ($q) => $q->whereNull('data_suspeita')->orWhere('data_suspeita', '!=', 1))
                    ->count();
                $novasSuspeitas = (int) DB::table($t)->where('data_suspeita', 1)
                    ->where('created_at', '>=', Carbon::now()->subDay())->count();
                $furadas += $semFlag;
                $suspeitas24h += $novasSuspeitas;
                $rows[] = ['fonte' => $nome, 'furadas_sem_flag' => $semFlag, 'suspeitas_24h' => $novasSuspeitas,
                    'nivel' => $semFlag > 0 ? 'bad' : ($novasSuspeitas > 20 ? 'warn' : 'ok')];
            }
        } catch (\Throwable $e) {
            return ['nivel' => 'warn', 'resumo' => 'falhou: '.$e->getMessage(), 'rows' => []];
        }

        return [
            'nivel' => $furadas > 0 ? 'bad' : ($suspeitas24h > 20 ? 'warn' : 'ok'),
            'resumo' => $furadas > 0
                ? "{$furadas} data(s) furada(s) SEM flag — regressão do BLOCO 1!"
                : "0 datas furadas sem flag · {$suspeitas24h} suspeitas novas 24h",
            'rows' => $rows,
        ];
    }

    /**
     * BLOCO 6 (02/07) — canal WhatsApp de ALERTA (instância JRLINK_ALERT_ZAPI_*,
     * grupo interno): configurado? Último alerta registrado? Falhas de envio no
     * log 24h? PASSIVO — só pinta o quadro, nunca envia nada.
     */
    private function alertaWhatsapp(): array
    {
        try {
            $zapOk = (new \App\Services\Jr\ZapRascunhos())->configurado();
            $grupo = (string) config('radar_civico.canais.sugestoes');
            $ultimo = DB::table('jr_civico_alertas')->max('alerted_at');
            $idadeH = $ultimo ? round(Carbon::parse($ultimo)->diffInMinutes(Carbon::now()) / 60, 1) : null;

            $falhas24h = 0;
            $log = storage_path('logs/laravel.log');
            if (is_file($log)) {
                $r = Process::timeout(15)->run(
                    "grep -c \"\$(date +%Y-%m-%d).*ZapRascunhos.*\\(falhou\\|erro\\)\" " . escapeshellarg($log) . ' 2>/dev/null || true'
                );
                $falhas24h = (int) trim($r->output());
            }

            $nivel = (! $zapOk || $grupo === '') ? 'bad' : ($falhas24h > 0 ? 'warn' : 'ok');
            $resumo = sprintf('instância+grupo: %s · último alerta registrado: %s · falhas Z-API hoje no log: %d',
                ($zapOk && $grupo !== '') ? 'configurados' : 'FALTANDO (fail-closed, nada sai)',
                $idadeH === null ? 'nunca' : "há {$idadeH}h",
                $falhas24h);

            return ['nivel' => $nivel, 'resumo' => $resumo,
                'rows' => [['configurado' => $zapOk, 'ultimo_alerta_h' => $idadeH, 'falhas_24h' => $falhas24h]]];
        } catch (\Throwable $e) {
            return ['nivel' => 'warn', 'resumo' => 'falhou: '.$e->getMessage(), 'rows' => []];
        }
    }

    private function cor(string $nivel): string
    {
        return ['ok' => '#3fb950', 'warn' => '#e3b341', 'bad' => '#f85149'][$nivel] ?? '#9aa3b2';
    }

    private function renderHtml(array $checks, Carbon $agora): string
    {
        $dot = fn ($n) => '<span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:'.$this->cor($n).'"></span>';
        $secoes = '';

        // cron
        $c = $checks['cron'];
        $linhas = '';
        foreach ($c['rows'] as $r) {
            $fora = $r['ok'] ? '<span style="color:#3fb950">na grade</span>' : '<span style="color:#f85149">FORA: '.implode(',', $r['fora']).'</span>';
            $linhas .= '<tr><td><code>'.htmlspecialchars($r['cmd']).'</code></td><td><code>'.htmlspecialchars($r['cron']).'</code></td><td>'.$fora.'</td></tr>';
        }
        $secoes .= $this->bloco($dot($c['nivel']).' Lint de cron (grade /5)', $c['resumo'], '<table><tr><th>comando</th><th>cron</th><th>status</th></tr>'.$linhas.'</table>');

        // canais
        $c = $checks['canais'];
        $linhas = '';
        foreach ($c['rows'] as $r) {
            $linhas .= '<tr><td>'.$dot($r['nivel']).' '.htmlspecialchars($r['canal']).'</td><td style="text-align:right">'.$r['total'].'</td><td>'.htmlspecialchars((string) $r['ultimo']).'</td><td style="text-align:right">'.($r['idade_h'] ?? '-').'h</td></tr>';
        }
        $secoes .= $this->bloco($dot($c['nivel']).' Frescor dos canais', $c['resumo'], '<table><tr><th>canal</th><th>total</th><th>último item</th><th>idade</th></tr>'.$linhas.'</table>');

        // serviços
        $c = $checks['servicos'];
        $linhas = '';
        foreach ($c['rows'] as $r) {
            $linhas .= '<tr><td>'.$dot($r['nivel']).' <code>'.htmlspecialchars($r['unit']).'</code></td><td>'.htmlspecialchars($r['estado']).'</td></tr>';
        }
        $secoes .= $this->bloco($dot($c['nivel']).' Serviços', $c['resumo'], '<table><tr><th>unit</th><th>estado</th></tr>'.$linhas.'</table>');

        // captura whatsapp (v1 — mede na fonte, não no downstream)
        $c = $checks['whatsapp'];
        $secoes .= $this->bloco($dot($c['nivel']).' Captura WhatsApp (raw + ingest)', $c['resumo'], '');

        // radar cívico (v1)
        $c = $checks['civico'];
        $linhas = '';
        foreach ($c['rows'] as $r) {
            $linhas .= '<tr><td>'.$dot($r['nivel']).' '.htmlspecialchars($r['fonte']).'</td><td>'.htmlspecialchars((string) $r['ultimo']).'</td><td style="text-align:right">'.($r['idade_h'] ?? '-').'h</td><td style="text-align:right">'.($r['limiar_h'] ?? '-').'h</td></tr>';
        }
        $secoes .= $this->bloco($dot($c['nivel']).' Radar Cívico (fontes + fila scoring)', $c['resumo'], '<table><tr><th>fonte</th><th>último item</th><th>idade</th><th>limiar</th></tr>'.$linhas.'</table>');

        // sessão claude-cli (v1)
        $c = $checks['claude'];
        $secoes .= $this->bloco($dot($c['nivel']).' Sessão claude-cli (scoring cívico)', $c['resumo'], '');

        // datas suspeitas (BLOCO 6)
        $c = $checks['datas'];
        $linhas = '';
        foreach ($c['rows'] as $r) {
            $linhas .= '<tr><td>'.$dot($r['nivel']).' '.htmlspecialchars($r['fonte']).'</td><td style="text-align:right">'.$r['furadas_sem_flag'].'</td><td style="text-align:right">'.$r['suspeitas_24h'].'</td></tr>';
        }
        $secoes .= $this->bloco($dot($c['nivel']).' Datas (sanidade BLOCO 1)', $c['resumo'], '<table><tr><th>fonte</th><th>furadas sem flag</th><th>suspeitas 24h</th></tr>'.$linhas.'</table>');

        // canal WhatsApp de alerta (BLOCO 6)
        $c = $checks['alertazap'];
        $secoes .= $this->bloco($dot($c['nivel']).' Canal WhatsApp de alerta (Z-API)', $c['resumo'], '');

        // pipeline + juiz (só resumo)
        $secoes .= $this->bloco($dot($checks['pipeline']['nivel']).' Pipeline 24h', $checks['pipeline']['resumo'], '');
        $secoes .= $this->bloco($dot($checks['juiz']['nivel']).' Juiz', $checks['juiz']['resumo'], '');

        return '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>NewsRadar — Health (watchdog v0)</title><style>'
            .'body{margin:0;background:#0f1115;color:#e8eaed;font:15px/1.6 -apple-system,Segoe UI,Roboto,sans-serif}'
            .'.wrap{max-width:900px;margin:0 auto;padding:26px 18px 70px}h1{font-size:22px;margin:0 0 4px}'
            .'.sub{color:#9aa3b2;font-size:13px}.card{background:#171a21;border:1px solid #2a2f3a;border-radius:10px;padding:14px 16px;margin:14px 0}'
            .'.card h2{font-size:16px;margin:0 0 2px}.card .r{color:#9aa3b2;font-size:13px;margin-bottom:8px}'
            .'table{border-collapse:collapse;width:100%;font-size:13px}th,td{padding:6px 9px;border-bottom:1px solid #2a2f3a;text-align:left}'
            .'th{color:#9aa3b2;font-size:11px;text-transform:uppercase}code{background:#11141a;border:1px solid #2a2f3a;border-radius:4px;padding:1px 5px;font-size:12px}'
            .'</style></head><body><div class="wrap"><h1>NewsRadar — Health</h1>'
            .'<p class="sub">watchdog v0 · READ-ONLY · gerado '.$agora->format('Y-m-d H:i').' (UTC) · não notifica, não muta</p>'
            .$secoes.'</div></body></html>';
    }

    private function bloco(string $titulo, string $resumo, string $tabela): string
    {
        return '<div class="card"><h2>'.$titulo.'</h2><div class="r">'.htmlspecialchars($resumo).'</div>'.$tabela.'</div>';
    }

    private function renderMd(array $checks, Carbon $agora): string
    {
        $m = "# WATCHDOG — estado (v0, read-only)\n\nGerado: ".$agora->format('Y-m-d H:i')." UTC\n\n";
        foreach (['cron' => 'Lint de cron', 'canais' => 'Frescor dos canais', 'whatsapp' => 'Captura WhatsApp', 'servicos' => 'Serviços', 'pipeline' => 'Pipeline 24h', 'juiz' => 'Juiz', 'civico' => 'Radar Cívico', 'claude' => 'Sessão claude-cli', 'datas' => 'Datas (sanidade)', 'alertazap' => 'Canal WhatsApp de alerta'] as $k => $nome) {
            $m .= "## {$nome}\n- **[".strtoupper($checks[$k]['nivel'])."]** ".$checks[$k]['resumo']."\n\n";
        }
        $m .= "_Não notifica e não muta nada. Dashboard: public/health.html_\n";

        return $m;
    }
}
