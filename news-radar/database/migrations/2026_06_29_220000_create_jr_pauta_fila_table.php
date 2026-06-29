<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MESA DE PAUTA — Fase 1. Fila de produção server-side (cross-device): o que o
 * Lorran SELECIONA no Radar Cívico cai aqui e ele acompanha do PC ou do celular,
 * pois vive no servidor (não em localStorage). ADITIVO e ISOLADO: não toca
 * juiz/radar editorial/captura/dispatcher. Cada linha = uma pauta triada.
 *
 * `ato_ref` = "<source>:<id da tabela-fonte>" (ex.: "dom:1234") — chave estável e
 * única do ato, é o que o botão ★ do radar manda. Os campos de exibição são
 * SNAPSHOT do momento da seleção: a Mesa renderiza sem rejuntar as fontes e a
 * pauta sobrevive mesmo que a linha-fonte envelheça/saia do teto do radar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jr_pauta_fila', function (Blueprint $table) {
            $table->id();

            $table->string('ato_ref')->unique();        // "dom:1234" — ato triado (estável)
            $table->string('source', 16)->index();      // dom|camara|mpsc|tce|tjsc

            // snapshot de exibição (do instante da seleção)
            $table->string('municipio')->nullable()->index();
            $table->string('regiao')->nullable();
            $table->text('objeto')->nullable();
            $table->string('gancho_curto')->nullable();
            $table->unsignedTinyInteger('score')->nullable()->index();
            $table->string('url_fonte')->nullable();

            // produção
            $table->string('status', 24)->default('nova')->index(); // nova|em-apuracao|feita|rascunho-gerado
            $table->text('nota')->nullable();           // anotação livre editável

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jr_pauta_fila');
    }
};
