<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DUAL-LENS (OBJ2) — classifica cada ato por ÓTICA editorial, ADITIVO e ISOLADO:
 *   🔴 fiscalizacao  — red flag a apurar (dispensa irônica, auto-benefício,
 *                      contrato zumbi, conflito de interesse, valor desproporcional)
 *   🟢 servico       — novidade positiva/neutra de 1ª-mão (edital de obra, concurso,
 *                      investimento, nova UBS/serviço que afeta o cidadão)
 *   neutro           — rotina/descartável
 *
 * Preenchido pelo DomScorer (Sonnet, SEPARADO do juiz). Não toca jrlink/juiz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jr_dom_atos', function (Blueprint $table) {
            // 'fiscalizacao' | 'servico' | 'neutro' — null = ainda não classificado
            $table->string('tipo', 20)->nullable()->index()->after('flags');
        });
    }

    public function down(): void
    {
        Schema::table('jr_dom_atos', function (Blueprint $table) {
            $table->dropColumn('tipo');
        });
    }
};
