<?php

namespace App\Console\Commands;

use App\Services\Jr\PautaMidia;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * PEÇA 1 — reprocessa capturas e baixa/casa a mídia (fotos) com a captura de
 * TEXTO. NÃO mexe no fluxo vivo de recebimento da Z-API: lê o que já está em
 * jr_pauta_capturas + arquivos crus. Idempotente. Ligar isso no recebimento ao
 * vivo é decisão manual do Lorran (Trava #0).
 *
 * Sem --ids, processa as capturas que viraram draft (jr_pauta_publicacoes com
 * wp_post_id) — exatamente os 5 do teste.
 */
class JrPautaMidia extends Command
{
    protected $signature = 'jrpauta:midia '
        . '{--ids= : message_ids de captura de TEXTO (vírgula). Default: capturas com draft} '
        . '{--janela=600 : janela em segundos pra casar foto↔texto} '
        . '{--dry : só mostra os candidatos, não baixa}';

    protected $description = 'Casa fotos↔texto e baixa o binário das imagens das capturas de pauta (Peça 1). Não publica, não toca no recebimento ao vivo.';

    public function handle(): int
    {
        $svc = new PautaMidia((int) $this->option('janela'));
        $ids = $this->selecionar();

        $this->info(sprintf('Capturas a processar: %d  ·  janela=%ss  ·  modo=%s',
            count($ids), $this->option('janela'), $this->option('dry') ? 'DRY' : 'REAL'));

        $okFotos = 0;
        $semFoto = [];
        foreach ($ids as $capId) {
            $cap = DB::table('jr_pauta_capturas')->where('message_id', $capId)->first(['message_id', 'chat_name']);
            $this->newLine();
            $this->line('▸ ' . $capId . '  [' . ($cap->chat_name ?? '?') . ']');

            if ($this->option('dry')) {
                $this->mostrarCandidatos($capId);
                continue;
            }

            $rows = $svc->processarCaptura($capId);
            $oks = array_filter($rows, fn ($r) => $r->download_status === 'ok');
            $okFotos += count($oks);

            if (! $rows) {
                $this->warn('  nenhuma foto candidata — fallback og:image fica pra Peça 2b.');
                $semFoto[] = $capId;
                continue;
            }
            foreach ($rows as $r) {
                $ic = $r->download_status === 'ok' ? '✅' : '⚠️';
                $this->line(sprintf('  %s %s  dt=%ss  %s  %sx%s  %s bytes  cred=%s',
                    $ic, $r->midia_message_id, $r->dt_segundos, $r->origem,
                    $r->width, $r->height, $r->bytes ?? 0, $r->credito ?? '—'));
                if ($r->download_status !== 'ok') {
                    $this->warn('      ' . $r->download_status . ': ' . $r->download_error);
                }
            }
        }

        $this->newLine();
        $this->info('Fotos baixadas com sucesso: ' . $okFotos);
        if ($semFoto) {
            $this->warn('Capturas SEM foto candidata (og:image na Peça 2b): ' . implode(', ', $semFoto));
        }

        return self::SUCCESS;
    }

    private function mostrarCandidatos(string $capId): void
    {
        $cap = DB::table('jr_pauta_capturas')->where('message_id', $capId)->first();
        if (! $cap || $cap->momment === null) {
            $this->warn('  captura sem momment — não dá pra casar por tempo.');

            return;
        }
        $w = (int) $this->option('janela') * 1000;
        $imgs = DB::table('jr_pauta_capturas')->where('tipo_conteudo', 'imagem')
            ->where('chat_name', $cap->chat_name)->where('sender_name', $cap->sender_name)
            ->whereBetween('momment', [$cap->momment - $w, $cap->momment + $w])
            ->get(['message_id', 'momment']);
        $this->line('  candidatos na janela: ' . $imgs->count());
        foreach ($imgs as $i) {
            $this->line(sprintf('    %s  dt=%+ds', $i->message_id, (int) round(($i->momment - $cap->momment) / 1000)));
        }
    }

    /** @return string[] */
    private function selecionar(): array
    {
        if ($ids = $this->option('ids')) {
            return array_map('trim', explode(',', $ids));
        }

        return DB::table('jr_pauta_publicacoes')->whereNotNull('wp_post_id')
            ->orderBy('id')->pluck('captura_message_id')->all();
    }
}
