<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Radar Cívico FASE 5 — processos/decisões do TCE-SC (DOTC-e). ADITIVO e
 * ISOLADO. Cada linha = uma deliberação do Tribunal (representação, inspeção,
 * denúncia, prestação de contas, decisão). FATO público; o faro decide pauta.
 *
 * Colunas espelham as demais fontes (source, municipio, objeto_limpo, data_pub,
 * score_pauta, tipo, gancho…) pro RADAR CÍVICO UNIFICADO (Fase 7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jr_tce_decisoes', function (Blueprint $table) {
            $table->id();

            $table->string('source', 16)->default('tce')->index();
            $table->string('hash', 40)->unique();            // sha1(tce:processo)
            $table->string('processo')->nullable()->index(); // REP 26/00007363
            $table->string('tipo_proc')->nullable()->index(); // Representação|Relatório de Inspeção|Denúncia…
            $table->text('assunto')->nullable();             // do que trata (gold)
            $table->string('interessado')->nullable();
            $table->string('responsavel')->nullable();       // gestor responsável
            $table->string('unidade_gestora')->nullable();   // Prefeitura/Secretaria/autarquia
            $table->string('municipio')->nullable()->index(); // extraído da UG quando municipal
            $table->string('relator')->nullable();
            $table->string('decisao')->nullable();           // nº da decisão/acórdão
            $table->text('desfecho')->nullable();            // trecho do "decide:" (multa/irregular/arquiva)
            $table->date('data_pub')->nullable()->index();
            $table->string('edicao')->nullable()->index();
            $table->string('url_fonte')->nullable();
            $table->text('texto_bruto')->nullable();

            // ── faro / radar cívico (Sonnet, dual-lens, swappable) ──
            $table->unsignedTinyInteger('score_pauta')->nullable()->index();
            $table->string('tipo')->nullable()->index();     // dual-lens: fiscalizacao|servico|neutro
            $table->string('objeto_limpo')->nullable();
            $table->string('gancho_curto')->nullable();
            $table->string('gancho')->nullable();
            $table->string('tipo_de_gancho')->nullable();
            $table->json('o_que_apurar')->nullable();
            $table->text('angulo_sugerido')->nullable();
            $table->json('flags')->nullable();
            $table->string('scored_model')->nullable();
            $table->dateTime('scored_at')->nullable()->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jr_tce_decisoes');
    }
};
