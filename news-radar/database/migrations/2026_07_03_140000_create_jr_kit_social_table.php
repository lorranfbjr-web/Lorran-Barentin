<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BLOCO 5 (03/07): dedup do KIT de divulgação social (jrpauta:kit-social).
 * 1 kit por matéria publicada — slug UNIQUE espelha jr_publicado.slug.
 * message_id = id Z-API da entrega no grupo RASCUNHOS (null no --dry nunca
 * grava; falha de envio também não grava → tenta no próximo ciclo).
 * ADITIVA e isolada — nenhuma tabela existente muda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jr_kit_social', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();                   // jr_publicado.slug
            $table->string('message_id')->nullable()->index();  // id Z-API da msg no grupo
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jr_kit_social');
    }
};
