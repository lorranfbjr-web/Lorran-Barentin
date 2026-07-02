<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tabela ADITIVA: "perfil de demanda" — matérias mais lidas (GA4), read-only.
        // Não altera o pipeline de "mais lidas" nem nenhuma tabela existente.
        Schema::create('jr_sinal_interesse', function (Blueprint $table) {
            $table->id();
            $table->text('page_title');
            $table->string('page_path');
            $table->unsignedBigInteger('views')->default(0);
            $table->string('periodo'); // 2025 | 2026 | total
            $table->timestamp('created_at')->nullable();

            $table->unique(['page_path', 'periodo']); // idempotência
            $table->index('periodo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jr_sinal_interesse');
    }
};
