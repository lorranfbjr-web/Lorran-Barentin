<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FASE 2 do JR Pauta — colunas do colapso por evento (cluster) e do juiz LLM
 * em jr_link_extracao, mais a tabela de log de chamadas jr_juiz_log (espelha o
 * padrão de news_item_ai_logs, com custo). Tudo aditivo e com rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jr_link_extracao', function (Blueprint $table) {
            // Colapso por evento (news_clusters).
            $table->unsignedBigInteger('cluster_id')->nullable()->index();
            $table->boolean('cluster_rep')->default(false);

            // Veredito do juiz LLM (null = ainda não julgado).
            $table->string('escopo')->nullable();          // local|regional|nacional_localizado|nacional
            $table->boolean('eh_pauta')->nullable();
            $table->string('tipo_gancho')->nullable();
            $table->string('cidade_llm')->nullable();
            $table->integer('score_llm')->nullable();       // 0-100 bruto do LLM
            $table->integer('score_editorial')->nullable(); // 0-100 final (LLM + âncora GA4)
            $table->string('tema_ga4')->nullable();         // tema TituloFeatures usado na âncora
            $table->string('temperatura_juiz')->nullable(); // quente|frio|fila_humana
            $table->text('juiz_motivo')->nullable();
            $table->string('juiz_modelo')->nullable();
            $table->string('juiz_prompt_versao')->nullable();
            $table->dateTime('juiz_julgado_em')->nullable();
        });

        Schema::create('jr_juiz_log', function (Blueprint $table) {
            $table->id();
            $table->string('operation');                    // juiz_lote
            $table->string('model')->nullable();
            $table->string('status');                       // success|error
            $table->integer('attempt')->default(1);
            $table->integer('itens')->default(0);           // itens julgados na chamada
            $table->integer('input_tokens')->nullable();
            $table->integer('output_tokens')->nullable();
            $table->decimal('custo_usd', 10, 6)->nullable();
            $table->text('error_message')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jr_juiz_log');

        Schema::table('jr_link_extracao', function (Blueprint $table) {
            $table->dropColumn([
                'cluster_id', 'cluster_rep', 'escopo', 'eh_pauta', 'tipo_gancho',
                'cidade_llm', 'score_llm', 'score_editorial', 'tema_ga4',
                'temperatura_juiz', 'juiz_motivo', 'juiz_modelo',
                'juiz_prompt_versao', 'juiz_julgado_em',
            ]);
        });
    }
};
