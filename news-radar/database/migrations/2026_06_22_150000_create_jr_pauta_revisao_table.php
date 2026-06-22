<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabela ADITIVA: log do revisor pós-post (Peça 4). Uma linha por auditoria
     * de draft — guarda o veredito, as pendências e o custo, pra o Lorran medir
     * depois a taxa de acerto/erro do pipeline. Reversível.
     */
    public function up(): void
    {
        Schema::create('jr_pauta_revisao', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wp_post_id')->index();
            $table->string('captura_message_id')->nullable()->index();
            $table->string('veredito')->default('revisar');   // ok | revisar
            $table->json('pendencias')->nullable();            // lista de strings
            $table->json('achados')->nullable();               // checagens detalhadas (bools/notas)
            $table->boolean('auto_fix_aplicado')->default(false);
            $table->text('auto_fix_descricao')->nullable();
            $table->string('modelo')->nullable();
            $table->decimal('custo_usd', 10, 6)->nullable();
            $table->integer('duration_ms')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jr_pauta_revisao');
    }
};
