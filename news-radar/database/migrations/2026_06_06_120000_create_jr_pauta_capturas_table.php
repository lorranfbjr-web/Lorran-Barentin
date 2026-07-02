<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tabela ADITIVA: captura crua de pautas (WhatsApp via Z-API) parseada.
        // Não substitui nem altera nenhuma tabela existente. Somente leitura
        // dos arquivos crus em storage/app/jr-pauta-capture é feita pelo comando.
        Schema::create('jr_pauta_capturas', function (Blueprint $table) {
            $table->id();
            $table->string('message_id')->unique();        // dedup / idempotência
            $table->unsignedBigInteger('momment')->nullable(); // epoch ms do Z-API
            $table->string('chat_name')->nullable();
            $table->string('sender_name')->nullable();
            $table->boolean('is_group')->default(false);
            $table->boolean('from_me')->default(false);
            $table->boolean('is_status_reply')->default(false);
            $table->boolean('is_newsletter')->default(false);
            $table->string('fonte_tipo')->index();         // distribuicao_jr | imprensa_oficial | individual
            $table->string('tipo_conteudo');               // texto | audio | imagem | sticker | outro
            $table->string('fonte_cidade')->nullable();    // best-effort; NULL quando desconhecido
            $table->longText('texto')->nullable();
            $table->string('raw_path');                    // caminho relativo do arquivo cru
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jr_pauta_capturas');
    }
};
