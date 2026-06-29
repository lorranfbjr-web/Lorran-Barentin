<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado/cursor do crawler DOM/SC (OBJ1) — KV simples, ADITIVO e ISOLADO.
 *
 * Guarda o cursor do RETROATIVO (até que data pra trás já foi varrida) pra
 * resume entre execuções, sem competir com o forward. Ex.:
 *   retro_cursor = '2026-06-10'  (já varremos daqui pra frente; próximo chunk é antes)
 *   retro_concluido = '1'        (alcançou o alvo de profundidade)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jr_dom_estado', function (Blueprint $table) {
            $table->string('chave')->primary();
            $table->text('valor')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jr_dom_estado');
    }
};
