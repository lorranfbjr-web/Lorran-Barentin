<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabela ADITIVA: mídia (fotos) baixada das capturas de pauta e casada com
     * a captura de TEXTO correspondente. Não altera jr_pauta_capturas nem
     * nenhuma tabela existente. Uma linha por imagem (uma matéria pode ter N).
     * Reversível (down faz drop limpo). Não toca no fluxo vivo de recebimento.
     */
    public function up(): void
    {
        Schema::create('jr_pauta_midia', function (Blueprint $table) {
            $table->id();
            // messageId da PRÓPRIA imagem (idempotência do baixador).
            $table->string('midia_message_id')->unique();
            // messageId da captura de TEXTO à qual a foto foi casada (pode ser
            // a própria imagem quando a legenda É o release). NULL = foto solta.
            $table->string('captura_message_id')->nullable()->index();
            $table->string('chat_name')->nullable();
            $table->string('sender_name')->nullable();
            $table->unsignedBigInteger('momment')->nullable();   // epoch ms Z-API
            $table->integer('dt_segundos')->nullable();           // offset vs. texto casado
            // legenda_embutida | sequencia | avulsa
            $table->string('origem')->default('sequencia');
            $table->string('mime_type')->nullable();
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->text('caption')->nullable();                  // legenda embutida da imagem
            $table->text('credito')->nullable();                  // crédito extraído (texto/legenda)
            $table->string('arquivo_path')->nullable();           // caminho relativo em storage/app
            $table->string('source_url', 1024)->nullable();       // imageUrl Z-API (expira)
            $table->string('download_status')->default('pendente'); // pendente|ok|erro|expirado
            $table->text('download_error')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            // ligação com o juiz visual (Peça 2) e o draft (Peça 3) — aditivo.
            $table->boolean('escolhida')->default(false);         // escolhida pelo juiz p/ capa
            $table->text('juiz_justificativa')->nullable();
            $table->unsignedBigInteger('wp_media_id')->nullable(); // id na biblioteca do controle
            $table->timestamps();

            $table->index(['chat_name', 'sender_name', 'momment']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jr_pauta_midia');
    }
};
