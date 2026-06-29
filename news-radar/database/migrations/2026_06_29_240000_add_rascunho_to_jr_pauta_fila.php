<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MESA DE PAUTA — Fase 5. Guarda o rascunho gerado (padrão JR) pra cada pauta da
 * fila: persiste o texto pra reabrir no celular e marca quando foi gerado.
 * ADITIVO. NÃO publica nada — rascunho é só pro Lorran revisar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jr_pauta_fila', function (Blueprint $table) {
            $table->longText('rascunho')->nullable()->after('nota');   // draft JR gerado
            $table->dateTime('rascunho_at')->nullable()->after('rascunho');
        });
    }

    public function down(): void
    {
        Schema::table('jr_pauta_fila', function (Blueprint $table) {
            $table->dropColumn(['rascunho', 'rascunho_at']);
        });
    }
};
