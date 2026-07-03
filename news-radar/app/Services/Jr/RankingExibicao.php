<?php

namespace App\Services\Jr;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Score de EXIBIÇÃO (score_x) = score_pauta + bônus de tier − decay de recência,
 * com CAP pra objeto vago. Só ordenação/UX — score_pauta no banco fica INTACTO
 * (alertas do jrcivico:alertar, Segundo Olhar, Mesa de Pauta e watchdog seguem
 * no score cru). Função pura, usada pelos 3 renderizadores; NUNCA escreve.
 */
class RankingExibicao
{
    /** Padrões de objeto_limpo VAGO (regra dura, anti-genérico). */
    private const PADROES_VAGO = [
        '/\bn[ãa]o\s+(?:identificad|especificad|detalhad|informad|localizad)/iu',
        '/\btexto\s+(?:cortado|truncado|incompleto)\b/iu',
        '/\bextrato\s+truncado\b/iu',
        '/\bnatureza\s+exata\s+n[ãa]o\b/iu',
        '/\bsem\s+(?:objeto|descri[çc][ãa]o|detalhamento)\s/iu',
        '/\bobjeto\s+(?:original\s+)?n[ãa]o\b/iu',
    ];

    public static function vago(?string $objetoLimpo): bool
    {
        $o = trim((string) $objetoLimpo);
        if ($o === '' || mb_strlen($o) < (int) config('interesse.anti_generico.min_len', 25)) {
            return true;
        }
        foreach (self::PADROES_VAGO as $rx) {
            if (preg_match($rx, $o)) {
                return true;
            }
        }

        // título-fallback puro ("Dispensa Eletrônica Nº 383/2026", "Extrato do Termo Aditivo…")
        return (bool) preg_match('/^(?:extrato|dispensa|inexigibilidade|preg[ãa]o|termo\s+aditivo|homologa[çc][ãa]o|ata\s+de\s+registro)\b[\s\S]{0,40}$/iu', $o);
    }

    public static function decay(?string $dataPub): int
    {
        if (! $dataPub) {
            return (int) config('interesse.decay.max', 24); // sem data = arquivo
        }
        $dias = Carbon::parse($dataPub)->startOfDay()->diffInDays(now()->startOfDay());
        $car = (int) config('interesse.decay.carencia_dias', 1);
        if ($dias <= $car) {
            return 0;
        }

        return min((int) config('interesse.decay.max', 24),
            ($dias - $car) * (int) config('interesse.decay.por_dia', 4));
    }

    /** @return array{tier:int,vago:bool,fresco:bool,score_x:int} */
    public static function avaliar(int $score, ?string $municipio, ?string $dataPub, ?string $objetoLimpo): array
    {
        $tier = CidadesInteresse::tier($municipio);
        $vago = self::vago($objetoLimpo);
        $sx = $score + CidadesInteresse::bonus($tier);
        if ($vago) {
            $sx = min($sx, (int) config('interesse.anti_generico.cap', 55)); // cap DEPOIS do bônus
        }
        $sx = max(0, min(100, $sx) - self::decay($dataPub));
        // BLOCO 1 (02/07): mesma verdade de recência do alerta (Recencia) —
        // fresco = data_pub nos últimos frescos_dias (48h) e nunca futuro.
        $fresco = Recencia::fresco($dataPub, (int) config('interesse.frescos_dias', 2));

        return ['tier' => $tier, 'vago' => $vago, 'fresco' => $fresco, 'score_x' => $sx];
    }

    /**
     * União 3 pernas com dedup por id: FRESCOS (últimos N dias) ∪ INTERESSE
     * (cidades tier1/tier2) ∪ TOP-SCORE. Garante que item de ontem/hoje e de
     * cidade de interesse SEMPRE chega ao JSON, mesmo com backlog de alto score
     * (bug "Ontem = 0 do MPSC"). Prioridade de sobrevivência ao take():
     * frescos > interesse > top (ordem do concat).
     *
     * $base recebe o query builder da tabela e aplica os filtros comuns
     * (score>=40 etc.) — chamada 1× por perna (builder não é reutilizável).
     */
    public static function coletar(string $tabela, callable $base, int $limite): \Illuminate\Support\Collection
    {
        $corte = now()->subDays((int) config('interesse.frescos_dias', 2))->toDateString();
        $frescos = $base(DB::table($tabela))->where('data_pub', '>=', $corte)
            ->orderByDesc('score_pauta')->limit((int) config('interesse.frescos_limit', 150))->get();
        $interesse = $base(DB::table($tabela))
            ->whereIn('municipio', CidadesInteresse::nomesOficiais())
            ->orderByDesc('score_pauta')->limit((int) config('interesse.interesse_limit', 150))->get();
        $top = $base(DB::table($tabela))
            ->orderByDesc('score_pauta')->limit($limite)->get();

        return $frescos->concat($interesse)->concat($top)
            ->unique('id')->take($limite + 150)->values();
    }
}
