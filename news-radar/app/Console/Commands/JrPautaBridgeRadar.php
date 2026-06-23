<?php

namespace App\Console\Commands;

use App\Services\Jr\CapturaFiltro;
use App\Services\Jr\PautaClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * PONTE captura→Radar (Parte C) — promove RELEASE DE TEXTO de grupo de WhatsApp
 * pra jr_link_extracao com origem=whatsapp, pra cair na aba WhatsApp do /radar.
 *
 * Espelha EXATAMENTE o molde do canal Instagram (JrInstagramPoll::ingerir):
 * categoria='concorrente' (radar, nunca reescreve), gate de corpo + régua
 * concorrente_feed, dedup permanente por url_hash. A diferença é a fonte: aqui
 * vem de jr_pauta_capturas (texto de grupo), não do Apify.
 *
 * Recorte:
 *  - SÓ mensagem de grupo permitida (CapturaFiltro — defesa em profundidade);
 *  - SÓ tipo_conteudo='texto' SEM link http (link já é tratado por jrlink:extract,
 *    que também grava origem=whatsapp — esta ponte cobre o release sem URL);
 *  - janela pela HORA DA CAPTURA (momment), não pelo created_at, pra o backlog
 *    de dias não entrar todo de uma vez como se fosse "fresco" e estourar o juiz;
 *  - cap por run.
 *
 * O juiz (jrlink:juiz) roteia origem=whatsapp DIRETO, pulando o gate coarse
 * (calibrado pra manchete de portal) — igual ao IG. Aqui o eixo/temperatura
 * coarse são gravados só por consistência de schema; quem decide é o juiz.
 */
class JrPautaBridgeRadar extends Command
{
    protected $signature = 'jrpauta:bridge-radar '
        . '{--dry : Mostra o que promoveria sem gravar} '
        . '{--horas= : Override da janela (default config bridge_whatsapp.janela_horas)}';

    protected $description = 'Promove release de texto de grupo (jr_pauta_capturas) -> jr_link_extracao origem=whatsapp, na régua do Radar.';

    public function handle(): int
    {
        $cfg     = config('jrlink.bridge_whatsapp', []);
        $cap     = (array) config('jrlink.captura', []);
        $horas   = (int) ($this->option('horas') ?: ($cfg['janela_horas'] ?? 72));
        $minChar = (int) ($cfg['min_chars'] ?? 60);
        $limite  = (int) ($cfg['cap_por_run'] ?? 200);

        // janela pela hora da captura: momment é epoch em MILISSEGUNDOS no Z-API.
        $cutoffMs = (Carbon::now()->subHours($horas)->timestamp) * 1000;

        $q = DB::table('jr_pauta_capturas')
            ->where('is_group', true)
            ->where('tipo_conteudo', 'texto')
            ->whereNotNull('texto')
            ->where('texto', 'not like', '%http%')   // link -> caminho do jrlink:extract
            ->whereNotNull('momment')
            ->where('momment', '>=', $cutoffMs);

        // anti-loop / privacidade: from_me + denylist de chat (defesa redundante;
        // o ingest já barra, mas a ponte não confia só nisso).
        if ($cap['ignorar_from_me'] ?? true) {
            $q->where('from_me', false);
        }

        $caps = $q->orderByDesc('momment')->limit($limite * 3)->get();

        $clf = new PautaClassifier();
        $rows = [];
        $jaExiste = 0;
        $cortadas = 0;

        foreach ($caps as $c) {
            if (count($rows) >= $limite) {
                break;
            }
            // defesa em profundidade pela MESMA regra da captura.
            if (! CapturaFiltro::aceita($c->chat_name, (bool) $c->is_group)) {
                $cortadas++;
                continue;
            }

            $texto = trim((string) $c->texto);
            if (mb_strlen($texto) < $minChar) {
                continue;
            }

            // url canônica sintética (release não tem link): dedup permanente por
            // message_id. scheme 'wa' garante que NUNCA cai no caminho de extract.
            $url = 'wa://capture/' . md5((string) $c->message_id);
            $hash = $clf->urlHashNorm($url);

            $titulo = mb_substr(trim(preg_split('/\r?\n/', $texto)[0] ?? ''), 0, 120);
            if ($titulo === '') {
                $titulo = mb_substr($texto, 0, 120);
            }

            // MESMA régua do IG/feed: gate de corpo + temperatura concorrente_feed.
            $categoria = 'concorrente';
            $status = $clf->gateStatus($categoria, $titulo, $texto);
            [$eixo, $temperatura, $score] = $clf->temperatura($categoria, $titulo, $texto, [], $url, 'concorrente_feed');

            $dataPub = is_numeric($c->momment)
                ? Carbon::createFromTimestampMs((int) $c->momment)->format('Y-m-d H:i:s')
                : null;

            $rows[$hash] = [
                'url'         => $url,
                'url_hash'    => $hash,
                'url_norm'    => $clf->normalizeUrl($url),
                'host'        => 'whatsapp',
                'fonte_tipo'  => $c->fonte_tipo ?: 'imprensa_oficial',
                'origem'      => 'whatsapp',
                'categoria'   => $categoria,
                'eixo'        => $eixo,
                'temperatura' => $temperatura,
                'score'       => $score,
                'metodo'      => 'whatsapp',
                'titulo'      => $titulo,
                'data_pub'    => $dataPub,
                'autor'       => $c->sender_name ?: $c->chat_name,
                'char_len'    => mb_strlen($texto),
                'status'      => $status,
                'markdown'    => $texto,
                'created_at'  => Carbon::now(),
            ];
        }

        if ($cortadas > 0) {
            $this->warn("Cortadas pela denylist/privacidade na ponte: {$cortadas}.");
        }

        if (! $rows) {
            $this->info("Nada novo a promover (janela {$horas}h, min {$minChar} chars).");
            return self::SUCCESS;
        }

        if ($this->option('dry')) {
            foreach ($rows as $r) {
                $this->line(sprintf('  [%s/%d] %-22s %s', $r['temperatura'] ?? '-', $r['score'],
                    mb_strimwidth((string) $r['autor'], 0, 22), mb_strimwidth($r['titulo'], 0, 70)));
            }
            $this->warn(sprintf('DRY-RUN — promoveria %d release(s), nada gravado.', count($rows)));
            return self::SUCCESS;
        }

        $antes = (int) DB::table('jr_link_extracao')->where('origem', 'whatsapp')->count();
        foreach (array_chunk(array_values($rows), 100) as $chunk) {
            // upsert NÃO mexe nas colunas de juiz/cluster — re-bridge não re-zera veredito.
            DB::table('jr_link_extracao')->upsert($chunk, ['url_hash'], [
                'titulo', 'data_pub', 'char_len', 'status', 'markdown',
            ]);
        }
        $depois = (int) DB::table('jr_link_extracao')->where('origem', 'whatsapp')->count();
        $novos = $depois - $antes;
        unset($jaExiste);

        $this->info(sprintf('Ponte WhatsApp: %d release(s) avaliados · %d novos em jr_link_extracao (origem=whatsapp).',
            count($rows), $novos));

        return self::SUCCESS;
    }
}
