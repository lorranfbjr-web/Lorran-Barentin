<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Aditivo: origem do registro (whatsapp = captura do grupo · feed = ponte
     * news_items do NewsRadar). Default 'whatsapp' cobre os registros existentes.
     */
    public function up(): void
    {
        Schema::table('jr_link_extracao', function (Blueprint $table) {
            if (! Schema::hasColumn('jr_link_extracao', 'origem')) {
                $table->string('origem')->default('whatsapp')->after('fonte_tipo'); // whatsapp | feed
                $table->index('origem');
            }
        });
    }

    public function down(): void
    {
        Schema::table('jr_link_extracao', function (Blueprint $table) {
            if (Schema::hasColumn('jr_link_extracao', 'origem')) {
                $table->dropIndex(['origem']);
                $table->dropColumn('origem');
            }
        });
    }
};
