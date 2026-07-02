<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tabela ADITIVA: rascunhos editoriais gerados a partir de jr_pauta_capturas.
        // Não altera nenhuma tabela existente. A tabela jr_pauta_capturas é só LIDA.
        Schema::create('jr_pauta_rascunhos', function (Blueprint $table) {
            $table->id();
            $table->string('captura_message_id')->unique(); // 1 rascunho por captura (idempotente)
            $table->string('cidade')->nullable();           // do grupo/texto; NULL se desconhecida
            $table->string('fonte');                        // grupo/órgão de origem
            $table->string('temperatura');                  // quente | fria
            $table->string('confianca');                    // alta | media | baixa
            $table->json('titulos');                        // 12 títulos
            $table->text('linha_fina');
            $table->longText('materia');                    // 5 blocos
            $table->json('tags');                           // 8 tags
            $table->json('lacunas');                        // dados ausentes / não informados
            $table->timestamp('gerado_em')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jr_pauta_rascunhos');
    }
};
