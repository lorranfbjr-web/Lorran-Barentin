<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ADITIVO: fotos (og:image por portal) + estado de entrega no WhatsApp.
        // Não quebra nada do schema existente de jr_pauta_reescrita.
        Schema::table('jr_pauta_reescrita', function (Blueprint $table) {
            $table->json('fotos')->nullable();              // [{host,url,og_image|null}]
            $table->string('entrega_status')->nullable();   // pendente | enviado | erro
            $table->text('entrega_erro')->nullable();
            $table->json('zap_ids')->nullable();            // messageIds Z-API enviados
            $table->timestamp('entregue_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('jr_pauta_reescrita', function (Blueprint $table) {
            $table->dropColumn(['fotos', 'entrega_status', 'entrega_erro', 'zap_ids', 'entregue_em']);
        });
    }
};
