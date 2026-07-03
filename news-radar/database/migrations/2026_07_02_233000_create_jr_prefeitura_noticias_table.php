<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BLOCO 3 (Goal 02/07): notícia INSTITUCIONAL das prefeituras de interesse
 * (release oficial — "versão" de uma parte, sempre sinalizado). Schema espelha
 * as outras fontes do Radar Cívico (jr_dom_atos & cia) pra reusar scorer,
 * RankingExibicao, Mesa de Pauta, RascunhoCivico e o alerta sem gambiarra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jr_prefeitura_noticias', function (Blueprint $table) {
            $table->increments('id');
            $table->string('source')->default('prefeitura');
            $table->string('hash')->unique();            // sha1(url canônica)
            $table->string('municipio')->index();        // grafia oficial (filtro interesse)
            $table->string('orgao')->nullable();         // "Prefeitura de X"
            $table->string('titulo');
            $table->text('objeto')->nullable();          // resumo bruto da listagem
            $table->string('objeto_limpo')->nullable();  // preenchido pelo scorer
            $table->date('data_pub')->nullable()->index();
            $table->tinyInteger('data_suspeita')->nullable();
            $table->string('url_fonte');
            $table->string('url_imagem')->nullable();
            $table->text('texto_bruto')->nullable();

            // faro (mesmo contrato dos outros scorers)
            $table->integer('score_pauta')->nullable()->index();
            $table->string('tipo')->nullable()->index(); // fiscalizacao|servico|neutro
            $table->string('gancho_curto')->nullable();
            $table->string('gancho')->nullable();
            $table->string('tipo_de_gancho')->nullable();
            $table->text('o_que_apurar')->nullable();
            $table->text('angulo_sugerido')->nullable();
            $table->text('flags')->nullable();
            $table->string('scored_model')->nullable();
            $table->dateTime('scored_at')->nullable()->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jr_prefeitura_noticias');
    }
};
