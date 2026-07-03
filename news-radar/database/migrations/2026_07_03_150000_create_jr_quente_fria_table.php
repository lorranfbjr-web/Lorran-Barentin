<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BLOCO 4 (03/07): triagem QUENTE/FRIA sobre o fluxo julgado (jr_link_extracao).
 * Log ADITIVO da classificação composta (sinais que já existem: temperatura do
 * juiz + score_editorial + recência + cidade de interesse) — score_pauta e o
 * veredito do juiz NUNCA são reescritos. Uma linha por extração = dedup
 * (classifica e entrega no máximo 1×).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jr_quente_fria', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('extracao_id')->unique(); // jr_link_extracao.id
            $table->string('classe', 8);                         // quente | fria
            $table->integer('score_composto');
            $table->string('motivo')->nullable();                // como compôs (1 linha)
            $table->string('message_id')->nullable();            // digest Z-API que levou (só quente)
            $table->timestamps();
            $table->index(['classe', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jr_quente_fria');
    }
};
