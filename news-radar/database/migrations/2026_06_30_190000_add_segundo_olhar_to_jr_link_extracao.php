<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEGUNDO OLHAR no juiz de notícias — colunas *_2 (veredito do gpt-4o-mini, cego)
 * em jr_link_extracao. ADITIVO e ISOLADO: NÃO toca o veredito do juiz (score_llm/
 * score_editorial/temperatura_juiz) nem o que vira post. Guarda a 2ª opinião e a
 * flag de divergência (juiz Sonnet × gpt) pra revisão humana na Mesa.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('jr_link_extracao') || Schema::hasColumn('jr_link_extracao', 'score2')) {
            return;
        }
        Schema::table('jr_link_extracao', function (Blueprint $table) {
            $table->unsignedTinyInteger('score2')->nullable()->index();  // 0-100 do gpt
            $table->boolean('eh_pauta2')->nullable();                    // gpt acha pauta?
            $table->string('motivo2')->nullable();
            $table->string('model2')->nullable();                        // gpt-4o-mini
            $table->boolean('divergente2')->nullable()->index();         // juiz × gpt discordam
            $table->dateTime('scored2_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('jr_link_extracao') || ! Schema::hasColumn('jr_link_extracao', 'score2')) {
            return;
        }
        Schema::table('jr_link_extracao', function (Blueprint $table) {
            $table->dropColumn(['score2', 'eh_pauta2', 'motivo2', 'model2', 'divergente2', 'scored2_at']);
        });
    }
};
