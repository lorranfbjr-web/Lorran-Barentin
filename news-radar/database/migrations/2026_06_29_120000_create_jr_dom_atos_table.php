<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conector DOM/SC — Radar de Oportunidades (PoC, ADITIVO e ISOLADO).
 *
 * Atos do Diário Oficial dos Municípios de SC (FECAM/CIGA,
 * diariomunicipal.sc.gov.br), focado em licitação/compras, minerados pela busca
 * pública (Solr GET, sem auth). A própria listagem já traz órgão + município +
 * texto + valor + link pro PDF assinado — sem parsear PDF.
 *
 * Camada de DESCOBERTA de pauta, separada do juiz/radar: o ato é FATO; o sistema
 * só SINALIZA lead pra apuração humana, NUNCA acusa irregularidade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jr_dom_atos', function (Blueprint $table) {
            $table->id();

            // ── identidade / proveniência ──
            $table->unsignedBigInteger('ato_id')->unique();   // id do ato no DOM/SC
            $table->string('hash', 40)->unique();             // sha1(ato_id) — dedup idempotente
            $table->string('titulo')->nullable();
            $table->string('municipio')->nullable()->index();
            $table->string('orgao')->nullable();              // Prefeitura/Câmara/Fundo/Autarquia (texto bruto)
            $table->string('categoria')->nullable()->index(); // Licitações | Contratos | Dispensas | Ata de registro de preços ...
            $table->string('modalidade')->nullable()->index(); // pregão|dispensa|inexigibilidade|concorrência|leilão|ata RP|aditivo (parseado)
            $table->text('objeto')->nullable();               // melhor extração do objeto (texto curto)
            $table->decimal('valor', 15, 2)->nullable()->index(); // R$ parseado do texto (maior valor encontrado)
            $table->string('fornecedor')->nullable();         // vencedor/contratado se aparecer
            $table->date('data_pub')->nullable()->index();
            $table->string('url_fonte')->nullable();          // página do ato no DOM
            $table->string('url_pdf')->nullable();            // PDF assinado original
            $table->text('texto_bruto')->nullable();          // snippet textual da listagem (objeto+vencedor+valores)

            // ── radar de oportunidades (Sonnet, swappable) ──
            $table->unsignedTinyInteger('score_pauta')->nullable()->index(); // 0-100 = NOTICIABILIDADE, não valor
            $table->string('gancho')->nullable();             // 1 linha: por que vira pauta
            $table->string('tipo_de_gancho')->nullable();     // TEXTO LIVRE — não preso a categoria
            $table->json('o_que_apurar')->nullable();         // bullets do que checar/perguntar/quem ouvir
            $table->text('angulo_sugerido')->nullable();
            $table->json('flags')->nullable();                // eixos de noticiabilidade que bateram
            $table->string('scored_model')->nullable();       // modelo usado no scoring (auditoria)
            $table->dateTime('scored_at')->nullable()->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jr_dom_atos');
    }
};
