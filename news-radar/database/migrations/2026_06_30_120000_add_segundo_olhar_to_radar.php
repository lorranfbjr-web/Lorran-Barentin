<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEGUNDO OLHAR — colunas *_2 (veredito do GPT) nas 4 tabelas do radar cívico.
 * ADITIVO e ISOLADO: não toca score_pauta (Sonnet) nem nada do faro existente.
 * Guarda o 2º olhar (cego) e a flag de divergência pra revisão humana na Mesa.
 */
return new class extends Migration
{
    private array $tabelas = [
        'jr_dom_atos',
        'jr_camara_proposicoes',
        'jr_mpsc_extratos',
        'jr_tce_decisoes',
    ];

    public function up(): void
    {
        foreach ($this->tabelas as $t) {
            if (! Schema::hasTable($t) || Schema::hasColumn($t, 'score2')) {
                continue;
            }
            Schema::table($t, function (Blueprint $table) {
                $table->unsignedTinyInteger('score2')->nullable()->index();  // 0-100 do GPT
                $table->boolean('eh_pauta2')->nullable();                    // GPT acha pauta?
                $table->string('motivo2')->nullable();                       // 1 linha do porquê
                $table->string('model2')->nullable();                        // ex.: gpt-4o-mini
                $table->boolean('divergente')->nullable()->index();          // Sonnet × GPT discordam
                $table->dateTime('scored2_at')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tabelas as $t) {
            if (! Schema::hasTable($t) || ! Schema::hasColumn($t, 'score2')) {
                continue;
            }
            Schema::table($t, function (Blueprint $table) {
                $table->dropColumn(['score2', 'eh_pauta2', 'motivo2', 'model2', 'divergente', 'scored2_at']);
            });
        }
    }
};
