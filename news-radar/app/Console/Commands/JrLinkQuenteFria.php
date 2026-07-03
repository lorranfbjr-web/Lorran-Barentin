<?php

namespace App\Console\Commands;

use App\Services\Jr\CidadesInteresse;
use App\Services\Jr\JanelaSilencio;
use App\Services\Jr\ZapRascunhos;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * BLOCO 4 (03/07) — TRIAGEM QUENTE/FRIA (parte SEM decisão da meta "Pipeline
 * JR Pauta quente/fria"): prioriza o que a redação humana apura primeiro.
 *
 * NÃO inventa modelo novo — COMPÕE sinais que já existem no fluxo julgado
 * (jr_link_extracao):
 *   · temperatura_juiz (o juiz já pesa eh_pauta, escopo, gancho e a régua
 *     viral do prompt v5 — sinal viral/aceleração mora AQUI, não em coluna);
 *   · score_editorial (score_llm + ajustes GA4 de tema/gancho);
 *   · recência (data_pub, decay igual ao do RankingExibicao: -4/dia após 1d
 *     de carência, teto -24; >7d nunca é quente);
 *   · cidade de interesse (CidadesInteresse::tier + bônus do interesse.php).
 *
 * QUENTE → digest no canal SUGESTÕES (fallback RASCUNHOS), respeitando a
 * janela de silêncio e o cap de mensagens do Bloco 0b. FRIA → só painel/Mesa
 * (fica no log jr_quente_fria, nenhuma mensagem). SEM publicação ao vivo,
 * SEM reescrita em lote — decisões pendentes do Lorran.
 *
 * Dedup: 1 linha por extração em jr_quente_fria — classifica/entrega 1×.
 * Só representantes de cluster (cluster_rep=1 ou sem cluster) — o evento
 * inteiro é uma pauta, não N.
 *   --dry     : classifica e imprime, não envia nem grava
 *   --janela= : horas de juiz_julgado_em pra trás (default 24)
 *   --seed    : marca o backlog SEM enviar (anti-flood na primeira rodada)
 */
class JrLinkQuenteFria extends Command
{
    protected $signature = 'jrlink:quente-fria '
        .'{--dry : classifica e imprime, não envia nem grava} '
        .'{--janela=24 : horas de julgamento pra trás} '
        .'{--seed : grava o backlog sem enviar nada (anti-flood)}';

    protected $description = 'Triagem quente/fria compondo sinais existentes (juiz + score + recência + cidade). Quente → digest no canal sugestões; fria → só painel. Nada muda score no banco.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');
        $seed = (bool) $this->option('seed');
        $janelaH = max(1, (int) $this->option('janela'));

        $corte = (int) config('radar_civico.quente_fria.score_min');
        $maxRun = (int) config('radar_civico.quente_fria.max_itens_run');
        $frescorDias = (int) config('radar_civico.quente_fria.frescor_dias');

        $linhas = $this->classificar($janelaH, $corte, $frescorDias);
        if ($linhas->isEmpty()) {
            $this->info('Nada novo julgado na janela — nada a classificar.');

            return self::SUCCESS;
        }

        $quentes = $linhas->where('classe', 'quente')->sortByDesc('score_composto')->values();
        $frias = $linhas->where('classe', 'fria');
        $this->info(sprintf('%d classificadas: %d quentes · %d frias (corte composto %d).',
            $linhas->count(), $quentes->count(), $frias->count(), $corte));

        if ($dry) {
            foreach ($quentes->take(10) as $q) {
                $this->line("[dry] 🔥 {$q['score_composto']} {$q['cidade']} — ".mb_substr($q['titulo'], 0, 70)." · {$q['motivo']}");
            }

            return self::SUCCESS;
        }

        // silêncio: não grava dedup — o backlog alerta no 1º ciclo acordado
        if (! $seed && JanelaSilencio::ativa(config('radar_civico.alertas'))) {
            $this->info('Silêncio: quente/fria espera o dia acordar (nada gravado).');

            return self::SUCCESS;
        }

        $messageId = null;
        $envioFalhou = false;
        if (! $seed && $quentes->isNotEmpty()) {
            $messageId = $this->entregar($quentes, $maxRun);
            // Z-API caiu no meio? NÃO grava dedup dos quentes — eles voltam no
            // próximo ciclo (mesmo padrão do kit social). Canal não configurado
            // é fail-closed deliberado: aí grava (senão acumula pra sempre).
            $envioFalhou = $messageId === null && (new ZapRascunhos)->configurado()
                && (string) config('radar_civico.canais.sugestoes') !== '';
        }

        $agora = Carbon::now();
        foreach ($linhas as $l) {
            if ($envioFalhou && $l['classe'] === 'quente') {
                continue; // re-tenta no próximo ciclo
            }
            DB::table('jr_quente_fria')->insertOrIgnore([
                'extracao_id' => $l['id'],
                'classe' => $l['classe'],
                'score_composto' => $l['score_composto'],
                'motivo' => mb_substr($l['motivo'], 0, 250),
                'message_id' => $l['classe'] === 'quente' ? $messageId : null,
                'created_at' => $agora,
                'updated_at' => $agora,
            ]);
        }
        $this->info($seed
            ? 'Seed: backlog gravado sem envio.'
            : ($messageId
                ? "Digest quente entregue (messageId {$messageId})."
                : ($envioFalhou
                    ? 'Z-API FALHOU — quentes NÃO marcados, voltam no próximo ciclo.'
                    : 'Nenhum quente pra entregar neste ciclo.')));

        return self::SUCCESS;
    }

    /**
     * Compõe a classe por item julgado ainda não triado. Regra:
     * quente = temperatura_juiz 'quente' E score_composto >= corte E fresco
     * (idade <= frescor_dias); qualquer falha → fria (painel). fila_humana e
     * frio do juiz nunca viram quente aqui — a triagem não desautoriza o juiz.
     *
     * @return Collection<int, array>
     */
    private function classificar(int $janelaH, int $corte, int $frescorDias): Collection
    {
        // mesmos pesos do score de exibição (interesse.php) — nada novo
        $bonusT1 = (int) config('interesse.pesos.tier1', 12);
        $bonusT2 = (int) config('interesse.pesos.tier2', 6);
        $decayDia = (int) config('interesse.decay.por_dia', 4);
        $decayMax = (int) config('interesse.decay.max', 24);

        // dedup por NOT EXISTS correlacionado — whereNotIn(pluck) materializa a
        // tabela inteira em placeholders e estoura SQLITE_MAX_VARIABLE_NUMBER
        // (32766 no php estático) quando jr_quente_fria crescer.
        $rows = DB::table('jr_link_extracao')
            ->whereNotNull('juiz_julgado_em')
            ->where('juiz_julgado_em', '>=', Carbon::now()->subHours($janelaH))
            ->where(fn ($q) => $q->where('cluster_rep', 1)->orWhereNull('cluster_id'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('jr_quente_fria')
                ->whereColumn('jr_quente_fria.extracao_id', 'jr_link_extracao.id'))
            ->get(['id', 'titulo', 'url', 'host', 'origem', 'data_pub', 'created_at',
                'cidade_llm', 'score_editorial', 'temperatura_juiz', 'tipo_gancho']);

        return $rows->map(function ($r) use ($corte, $frescorDias, $bonusT1, $bonusT2, $decayDia, $decayMax) {
            $cidade = trim((string) ($r->cidade_llm ?? ''));
            $tier = $cidade !== '' ? CidadesInteresse::tier($cidade) : 0;
            $bonus = $tier === 1 ? $bonusT1 : ($tier === 2 ? $bonusT2 : 0);

            $idadeDias = $this->idadeDias((string) ($r->data_pub ?: $r->created_at));
            $decay = min($decayMax, max(0, ($idadeDias - 1)) * $decayDia);

            $score = (int) ($r->score_editorial ?? 0) + $bonus - $decay;

            $quente = ($r->temperatura_juiz === 'quente')
                && $score >= $corte
                && $idadeDias <= $frescorDias;

            return [
                'id' => (int) $r->id,
                'titulo' => (string) $r->titulo,
                'url' => (string) $r->url,
                'host' => (string) ($r->host ?? ''),
                'cidade' => $cidade !== '' ? $cidade : '—',
                'classe' => $quente ? 'quente' : 'fria',
                'score_composto' => $score,
                'motivo' => sprintf('juiz=%s · editorial=%d · tier%d%+d · idade=%dd(-%d)',
                    $r->temperatura_juiz, (int) $r->score_editorial, $tier, $bonus, $idadeDias, $decay),
            ];
        });
    }

    /** Idade em dias (0 = hoje). Data ilegível = velha (99) — nunca quente. */
    private function idadeDias(string $data): int
    {
        try {
            $d = Carbon::parse($data);
        } catch (\Throwable) {
            return 99;
        }
        if ($d->isFuture()) {
            return 99; // data suspeita nunca é quente
        }

        return (int) $d->diffInDays(Carbon::now());
    }

    /**
     * Digest dos quentes no canal SUGESTÕES (fallback RASCUNHOS): top N
     * detalhado agrupado por cidade, excedente em 1 linha de contagem.
     * Cap de chars = max_msg_run × 4000 (fatiamento do ZapRascunhos).
     */
    private function entregar(Collection $quentes, int $maxRun): ?string
    {
        $zap = new ZapRascunhos;
        $canal = (string) config('radar_civico.canais.sugestoes');
        if (! $zap->configurado() || $canal === '') {
            $this->warn('[SEM CREDENCIAL Z-API/canal] Digest quente NÃO enviado (fica pro painel).');

            return null;
        }

        $top = $quentes->take($maxRun);
        $resto = $quentes->count() - $top->count();

        $linhas = ['🔥 *PAUTAS QUENTES — apurar primeiro* ('.$top->count().')'];
        foreach ($top->groupBy('cidade') as $cidade => $itens) {
            $linhas[] = '';
            $linhas[] = "📍 *{$cidade}*";
            foreach ($itens as $q) {
                $linhas[] = '• ['.$q['score_composto'].'] '.mb_substr($q['titulo'], 0, 110)
                    ."\n  ".$q['url'];
            }
        }
        if ($resto > 0) {
            $linhas[] = '';
            $linhas[] = "… e mais {$resto} quentes no painel.";
        }
        $linhas[] = '_triagem automática (juiz+score+recência+cidade) · fria fica no painel_';

        $maxChars = max(1, (int) config('radar_civico.alertas.max_msg_run', 3)) * 4000;
        $msg = implode("\n", $linhas);
        if (mb_strlen($msg) > $maxChars) {
            $msg = mb_substr($msg, 0, $maxChars - 25)."\n… (cortado pelo cap)";
        }

        return $zap->texto($msg, $canal);
    }
}
