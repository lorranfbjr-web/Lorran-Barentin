<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feedback humano do Radar (botões ❄️/😐/🔥 no card): rótulo de faixa por
 * pauta, com snapshot do veredito do juiz NA HORA do voto (score/gancho podem
 * ser re-julgados depois — o rótulo guarda o contexto). Re-voto atualiza a
 * MESMA linha (unique por jr_link_extracao_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jr_pauta_feedback', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('jr_link_extracao_id')->unique();
            $table->foreign('jr_link_extracao_id')->references('id')->on('jr_link_extracao')->cascadeOnDelete();
            $table->unsignedBigInteger('cluster_id')->nullable()->index();
            $table->string('faixa');                          // baixa(10-30) | media(30-60) | alta(60-100)
            $table->integer('ponto_medio');                   // 20 | 45 | 80
            $table->integer('score_juiz_na_hora')->nullable();
            $table->string('gancho_na_hora')->nullable()->index();
            $table->dateTime('votado_em')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jr_pauta_feedback');
    }
};
