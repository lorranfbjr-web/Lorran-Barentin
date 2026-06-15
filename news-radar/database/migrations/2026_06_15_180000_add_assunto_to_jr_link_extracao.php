<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // v5: AGRUPAMENTO POR ASSUNTO (vitrine Radar). O Opus agrupa, no CICLO,
        // clusters diferentes do MESMO assunto numa janela de 7 dias (ex.: tainha:
        // encerramento + reabertura + cota + Lula = 1 assunto). assunto_id é
        // estável (cacheável); assunto_label é o rótulo legível. Aditivo e
        // reversível — não toca o pipeline de juiz/cluster.
        Schema::table('jr_link_extracao', function (Blueprint $table) {
            $table->string('assunto_id')->nullable()->index();
            $table->string('assunto_label')->nullable();
            $table->timestamp('assunto_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('jr_link_extracao', function (Blueprint $table) {
            $table->dropIndex(['assunto_id']);
            $table->dropColumn(['assunto_id', 'assunto_label', 'assunto_em']);
        });
    }
};
