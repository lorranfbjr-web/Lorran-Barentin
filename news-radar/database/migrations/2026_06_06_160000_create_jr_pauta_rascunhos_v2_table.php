<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 2ª geração de rascunhos (v5.1 prosa + título data-driven + 2 eixos).
        // ADITIVA: NÃO toca jr_pauta_rascunhos (v1).
        Schema::create('jr_pauta_rascunhos_v2', function (Blueprint $table) {
            $table->id();
            $table->string('captura_message_id')->unique();
            $table->string('cidade')->nullable();
            $table->string('fonte');
            $table->string('tema');
            $table->string('gancho');
            $table->string('registro'); // leve | pesado | neutro
            $table->boolean('sobe_site')->default(true);
            $table->unsignedInteger('vai_feed')->default(0); // 0-100 pelo jr-titulo-regras.json
            $table->text('gancho_feed');
            $table->string('confianca'); // alta | media | baixa
            $table->text('titulo_escolhido');
            $table->json('titulos');      // 12 alternativos
            $table->text('linha_fina');
            $table->longText('materia');  // prosa corrida
            $table->json('lacunas');
            $table->timestamp('gerado_em')->nullable();
            $table->timestamps();

            $table->index('tema');
            $table->index('vai_feed');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jr_pauta_rascunhos_v2');
    }
};
