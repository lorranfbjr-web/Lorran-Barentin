<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MESA DE PAUTA — Fase 4. Anti-spam dos alertas do Telegram: registra cada ato
 * que JÁ foi alertado (uma vez só), pra nunca floodar a mesma pauta. ADITIVO e
 * ISOLADO. Uma linha = um ato_ref ("source:id") já disparado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jr_civico_alertas', function (Blueprint $table) {
            $table->id();
            $table->string('ato_ref')->unique();   // "mpsc:42" — já alertado
            $table->string('source', 16)->index();
            $table->unsignedTinyInteger('score')->nullable();
            $table->string('motivo', 48)->nullable(); // por que entrou (score|cidade|fonte-chave)
            $table->dateTime('alerted_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jr_civico_alertas');
    }
};
