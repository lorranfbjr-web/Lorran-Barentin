<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BLOCO 2 (03/07): payload estruturado do rascunho (JSON titulo/lead/corpo/
 * checklist/municipio) gravado na entrega — é dele que o ✅ do WhatsApp monta
 * o DRAFT no WP sem regenerar LLM. Entregas antigas (payload NULL) caem no
 * fallback: texto formatado guardado em jr_pauta_fila.rascunho.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jr_rascunho_entregas', function (Blueprint $table) {
            $table->json('payload')->nullable()->after('gate_motivo');
        });
    }

    public function down(): void
    {
        Schema::table('jr_rascunho_entregas', function (Blueprint $table) {
            $table->dropColumn('payload');
        });
    }
};
