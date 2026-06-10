<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notificação do Radar JR (grupo Raspador via Z-API): dedup permanente —
 * item/cluster notificado nunca repete. Aditiva, com rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jr_link_extracao', function (Blueprint $table) {
            $table->dateTime('notificado_em')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('jr_link_extracao', function (Blueprint $table) {
            $table->dropColumn('notificado_em');
        });
    }
};
