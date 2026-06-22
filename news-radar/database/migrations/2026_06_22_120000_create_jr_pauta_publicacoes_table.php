<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Elo de PUBLICAÇÃO de pautas frias: captura primária → reescrita JR →
     * rascunho (draft) no WP de controle. ADITIVA — não toca jr_pauta_capturas
     * (captura no ar) nem jr_pauta_rascunhos/_v2 (showcases).
     *
     * Uma linha por captura processada pelo elo. captura_message_id UNIQUE =
     * idempotência (anti-duplicata: nunca cria 2 rascunhos da mesma captura).
     * gate registra o veredito da trava dura antes de qualquer reescrita.
     */
    public function up(): void
    {
        Schema::create('jr_pauta_publicacoes', function (Blueprint $table) {
            $table->id();
            $table->string('captura_message_id')->unique();

            // Trava dura (antes da reescrita):
            //   ok | fila_humana_solidariedade | fila_humana_sensivel | descartado_quente
            $table->string('gate');
            $table->text('gate_motivo')->nullable();

            // Classificação reusada do JuizLlm (fria/quente/fila_humana).
            $table->string('temperatura')->nullable();
            $table->string('gancho')->nullable();

            // Reescrita JR (preenchida só quando gate=ok e fria).
            $table->string('cidade')->nullable();
            $table->string('tema')->nullable();
            $table->text('titulo')->nullable();
            $table->text('linha_fina')->nullable();
            $table->longText('materia')->nullable();
            $table->json('tags')->nullable();
            $table->string('modelo')->nullable();   // modelo LLM usado na reescrita

            // Destino WP (controle.jornalrazao.com via REST). Sempre draft nesta fase.
            $table->unsignedBigInteger('wp_post_id')->nullable();
            $table->string('wp_status')->nullable();
            $table->text('wp_edit_url')->nullable();

            $table->timestamp('processado_em')->nullable();
            $table->timestamps();

            $table->index('gate');
            $table->index('wp_post_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jr_pauta_publicacoes');
    }
};
