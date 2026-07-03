<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BLOCO 1/2 (03/07): registro de RASCUNHOS ENTREGUES no grupo RASCUNHOS via
 * Z-API (instância de alerta). Serve a três papéis:
 *   1. dedup do auto-rascunho (1 entrega por ato_ref/tipo, cap diário);
 *   2. elo messageId → ato_ref pro webhook de aprovação por ✅ (Bloco 2):
 *      o reply do aprovador cita o messageId da entrega, daqui sai o rascunho;
 *   3. trilha de auditoria (quem aprovou, draft WP criado, descartes).
 * ADITIVA e isolada — nenhuma tabela existente muda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jr_rascunho_entregas', function (Blueprint $table) {
            $table->id();
            $table->string('ato_ref');                       // "prefeitura:135"
            $table->string('tipo', 8)->default('auto');      // auto | mesa
            $table->string('message_id')->nullable()->index(); // id Z-API da msg no grupo
            $table->string('gate_motivo')->nullable();       // por que passou no gate (auto)
            $table->unsignedInteger('wp_post_id')->nullable(); // draft criado via ✅ (Bloco 2)
            $table->string('aprovado_por', 32)->nullable();  // phone mascarável de quem aprovou
            $table->dateTime('aprovado_em')->nullable();
            $table->dateTime('descartado_em')->nullable();   // ❌ = descartado (feedback)
            $table->timestamps();
            $table->unique(['ato_ref', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jr_rascunho_entregas');
    }
};
