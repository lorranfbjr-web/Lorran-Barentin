<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Camada ADITIVA: extração de links (markdown + metadados) das capturas.
        // Não altera nenhuma tabela existente.
        Schema::create('jr_link_extracao', function (Blueprint $table) {
            $table->id();
            $table->text('url');
            $table->string('url_hash', 64)->unique(); // dedup idempotente (sha256 da url)
            $table->string('fonte_tipo')->nullable();
            $table->string('categoria')->nullable();   // concorrente | primaria | social | outro
            $table->string('metodo')->nullable();      // trafilatura | jina
            $table->text('titulo')->nullable();
            $table->string('data_pub')->nullable();
            $table->string('autor')->nullable();
            $table->unsignedInteger('char_len')->default(0);
            $table->string('status')->default('erro'); // ok | vazio | erro
            $table->longText('markdown')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('status');
            $table->index('categoria');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jr_link_extracao');
    }
};
