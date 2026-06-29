<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Radar Cívico FASE 4 — extratos de instauração de procedimentos do MPSC.
 * ADITIVO e ISOLADO. Cada linha = "o MP abriu um inquérito civil / notícia de
 * fato / procedimento sobre X" (FATO público; o faro decide noticiabilidade).
 *
 * Colunas de proveniência+scoring espelham jr_dom_atos / jr_camara_proposicoes
 * (source, municipio, objeto_limpo, data_pub, score_pauta, tipo, gancho…) pro
 * RADAR CÍVICO UNIFICADO (Fase 7) unir as fontes por UNION.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jr_mpsc_extratos', function (Blueprint $table) {
            $table->id();

            $table->string('source', 16)->default('mpsc')->index();
            $table->string('hash', 40)->unique();            // sha1 estável do extrato
            $table->string('tipo_proc', 40)->nullable()->index(); // inquerito_civil|noticia_de_fato|procedimento_*
            $table->string('numero')->nullable();            // nº do procedimento (06.AAAA.NNNNNNNN-D)
            $table->string('comarca')->nullable()->index();
            $table->string('municipio')->nullable()->index(); // comarca→município (Capital→Florianópolis)
            $table->string('orgao')->nullable();             // órgão do MP (Nª Promotoria de Justiça)
            $table->text('partes')->nullable();              // partes envolvidas (Município/órgão/empresa)
            $table->text('objeto')->nullable();              // o que se apura (gold)
            $table->string('membro')->nullable();            // promotor responsável
            $table->date('data_pub')->nullable()->index();   // data da instauração (ou edição)
            $table->string('edicao')->nullable()->index();   // data da edição do DOE
            $table->string('url_fonte')->nullable();         // PDF da edição
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
        Schema::dropIfExists('jr_mpsc_extratos');
    }
};
