<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tabela ADITIVA: sinal de TÍTULOS (GA4) + features por regra.
        // NÃO altera jr_sinal_interesse nem nenhuma tabela existente.
        Schema::create('jr_titulo_sinal', function (Blueprint $table) {
            $table->id();
            $table->string('page_path');
            $table->text('page_title');
            $table->string('periodo'); // 2025 | 2026

            // métricas GA4
            $table->unsignedBigInteger('views')->default(0);
            $table->double('engagement_seconds')->default(0);
            $table->double('engagement_rate')->default(0);
            $table->double('engaj_por_view')->default(0); // engagement_seconds / views

            // features do título (regra, PHP)
            $table->boolean('tem_aspas')->default(false);
            $table->boolean('aspas_inicio')->default(false);
            $table->boolean('tem_numero')->default(false);
            $table->boolean('tem_cidade')->default(false);
            $table->string('cidade')->nullable();
            $table->string('cidade_posicao')->nullable(); // inicio | meio | fim
            $table->boolean('cliffhanger')->default(false);
            $table->string('tema')->nullable();
            $table->string('gancho')->nullable();
            $table->unsignedInteger('comprimento_chars')->default(0);
            $table->unsignedInteger('comprimento_palavras')->default(0);
            $table->string('registro')->nullable(); // leve | pesado | neutro

            $table->timestamp('created_at')->nullable();

            $table->unique(['page_path', 'periodo']);
            $table->index('periodo');
            $table->index('tema');
            $table->index('gancho');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jr_titulo_sinal');
    }
};
