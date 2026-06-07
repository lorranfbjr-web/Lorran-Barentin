<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Aditivo: colunas do classificador afinado (host resolvido, url normalizada,
     * flag de duplicada). Não altera/dropa nada existente.
     */
    public function up(): void
    {
        Schema::table('jr_link_extracao', function (Blueprint $table) {
            if (! Schema::hasColumn('jr_link_extracao', 'host')) {
                $table->string('host')->nullable()->after('categoria'); // host resolvido (final)
            }
            if (! Schema::hasColumn('jr_link_extracao', 'url_norm')) {
                $table->text('url_norm')->nullable()->after('host'); // url normalizada p/ dedup
            }
            if (! Schema::hasColumn('jr_link_extracao', 'duplicada')) {
                $table->boolean('duplicada')->default(false)->after('url_norm');
            }
        });
    }

    public function down(): void
    {
        Schema::table('jr_link_extracao', function (Blueprint $table) {
            foreach (['host', 'url_norm', 'duplicada'] as $col) {
                if (Schema::hasColumn('jr_link_extracao', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
