<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BLOCO 1 (Goal 02/07): flag ADITIVA de data suspeita. Ingest que encontra
 * data futura (> hoje+2d) ou implausível (< 2015) grava data_pub=NULL +
 * data_suspeita=1 em vez de lixo. Alerta e exibição filtram a flag.
 */
return new class extends Migration
{
    private const TABELAS = ['jr_dom_atos', 'jr_camara_proposicoes', 'jr_mpsc_extratos', 'jr_tce_decisoes'];

    public function up(): void
    {
        foreach (self::TABELAS as $t) {
            if (! Schema::hasColumn($t, 'data_suspeita')) {
                Schema::table($t, function (Blueprint $table) {
                    $table->tinyInteger('data_suspeita')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABELAS as $t) {
            if (Schema::hasColumn($t, 'data_suspeita')) {
                Schema::table($t, function (Blueprint $table) {
                    $table->dropColumn('data_suspeita');
                });
            }
        }
    }
};
