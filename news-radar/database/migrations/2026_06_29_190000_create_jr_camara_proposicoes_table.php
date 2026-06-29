<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Radar Cívico FASE 2 — proposições das câmaras (SAPL). ADITIVO e ISOLADO.
 *
 * Cada linha = uma matéria legislativa (projeto de lei/decreto legislativo/
 * resolução/emenda à LO) de uma câmara com SAPL vivo. Camada de DESCOBERTA de
 * pauta: a proposição é FATO público; o faro só SINALIZA lead, NUNCA acusa.
 *
 * Colunas de proveniência+scoring espelham jr_dom_atos (source, municipio,
 * objeto_limpo, data_pub, score_pauta, tipo dual-lens, gancho…) pra o RADAR
 * CÍVICO UNIFICADO (Fase 7) unir as fontes por UNION sem retrabalho.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jr_camara_proposicoes', function (Blueprint $table) {
            $table->id();

            // ── identidade / proveniência ──
            $table->string('source', 16)->default('camara')->index(); // fonte (radar unificado)
            $table->string('host')->index();                 // instância SAPL
            $table->unsignedBigInteger('materia_id');        // id da matéria no SAPL
            $table->string('hash', 40)->unique();            // sha1(host:materia_id) — dedup idempotente
            $table->string('municipio')->nullable()->index();
            $table->string('orgao')->nullable();             // "Câmara Municipal de X"
            $table->string('tipo_sigla', 16)->nullable();    // PLO/PLC/PDL/PRE… (varia por instância)
            $table->string('tipo_descricao')->nullable();    // "Projeto de Lei Ordinária"…
            $table->boolean('complementar')->default(false); // PL complementar
            $table->unsignedInteger('numero')->nullable();
            $table->unsignedSmallInteger('ano')->nullable()->index();
            $table->text('ementa')->nullable();              // texto da proposição
            $table->string('autores')->nullable();           // nomes resolvidos, juntos
            $table->boolean('em_tramitacao')->default(false);
            $table->date('data_pub')->nullable()->index();   // data_apresentacao (uniforme c/ DOM)
            $table->string('titulo')->nullable();
            $table->string('url_fonte')->nullable();
            $table->string('url_pdf')->nullable();           // texto_original (PDF), se houver
            $table->text('texto_bruto')->nullable();         // ementa + indexação (pro faro)

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

            $table->index(['host', 'ano']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jr_camara_proposicoes');
    }
};
