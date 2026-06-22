<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tabela ADITIVA: pauta reescrita unificada (Radar JR, Goal 3). Guarda o
        // resultado de juntar TODOS os portais de um assunto numa matéria padrão
        // JR. NÃO altera jr_link_extracao (linkada por assunto_id, só leitura).
        // Upsert por assunto_id: re-rodar sobrescreve.
        Schema::create('jr_pauta_reescrita', function (Blueprint $table) {
            $table->id();
            $table->string('assunto_id')->unique();   // chave do assunto no radar
            $table->json('cluster_ids');               // clusters cobertos
            $table->json('fontes');                    // [{host,url}] dos portais unificados
            $table->unsignedSmallInteger('n_portais')->default(0);
            $table->string('cidade')->nullable();
            $table->string('editoria')->nullable();
            $table->json('titulos');                   // ~12 títulos na voz JR
            $table->text('titulo_principal')->nullable();
            $table->text('linha_fina')->nullable();
            $table->longText('materia');               // a matéria unificada (prosa)
            $table->json('tags');                      // 8 tags
            $table->json('lacunas');                   // o que falta confirmar / só na versão
            $table->string('modelo')->nullable();      // LLM usado (claude-opus-4-8)
            $table->decimal('custo_usd', 8, 4)->default(0);
            $table->string('gate')->default('ok');     // ok | fila_humana_solidariedade
            $table->timestamp('gerado_em')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jr_pauta_reescrita');
    }
};
