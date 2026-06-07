<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Aditivo: eixo (primaria|concorrente|-), temperatura (quente|frio) e score
     * do classificador de pauta. Não altera/dropa nada existente.
     */
    public function up(): void
    {
        Schema::table('jr_link_extracao', function (Blueprint $table) {
            if (! Schema::hasColumn('jr_link_extracao', 'eixo')) {
                $table->string('eixo')->nullable()->after('categoria'); // primaria | concorrente | -
            }
            if (! Schema::hasColumn('jr_link_extracao', 'temperatura')) {
                $table->string('temperatura')->nullable()->after('eixo'); // quente | frio
            }
            if (! Schema::hasColumn('jr_link_extracao', 'score')) {
                $table->integer('score')->default(0)->after('temperatura');
            }
        });
    }

    public function down(): void
    {
        Schema::table('jr_link_extracao', function (Blueprint $table) {
            foreach (['eixo', 'temperatura', 'score'] as $col) {
                if (Schema::hasColumn('jr_link_extracao', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
