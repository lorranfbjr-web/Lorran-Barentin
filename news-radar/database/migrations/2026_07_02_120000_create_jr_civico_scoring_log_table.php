<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B5 (02/07/2026) — observabilidade do scoring cívico: o faro (Sonnet via
 * claude-cli) não logava NADA em tabela. Uma linha por ciclo de cada comando
 * de score (dom/camara/mpsc/tce); o watchdog lê daqui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jr_civico_scoring_log', function (Blueprint $table) {
            $table->id();
            $table->string('fonte', 16);                    // dom|camara|mpsc|tce
            $table->unsignedInteger('chamadas')->default(0); // chamadas LLM no ciclo
            $table->unsignedInteger('scorados')->default(0); // atos pontuados OK
            $table->unsignedInteger('falhas')->default(0);   // lotes com erro
            $table->unsignedInteger('pendentes_apos')->default(0); // fila restante
            $table->string('modelo', 64)->nullable();
            $table->unsignedInteger('duracao_ms')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['fonte', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jr_civico_scoring_log');
    }
};
