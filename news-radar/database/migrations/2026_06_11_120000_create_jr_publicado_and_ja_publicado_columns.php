<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // v4.1: espelho dos posts JÁ PUBLICADOS no site (WPGraphQL, só leitura).
        // Upsert por slug — jrlink:publicados-sync mantém a janela recente.
        Schema::create('jr_publicado', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->text('titulo');
            $table->string('categoria')->nullable();
            $table->timestamp('publicado_em')->nullable();
            $table->timestamps();

            $table->index('publicado_em');
        });

        // Colunas ADITIVAS no estoque: evento casado com post do site (some do
        // Radar por default, nunca notifica) e com post do nosso Instagram.
        Schema::table('jr_link_extracao', function (Blueprint $table) {
            $table->timestamp('ja_publicado_em')->nullable();
            $table->string('ja_publicado_slug')->nullable();
            $table->timestamp('ja_ig_em')->nullable();
            $table->string('ja_ig_shortcode')->nullable();

            $table->index('ja_publicado_em');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jr_publicado');
        Schema::table('jr_link_extracao', function (Blueprint $table) {
            $table->dropIndex(['ja_publicado_em']);
            $table->dropColumn(['ja_publicado_em', 'ja_publicado_slug', 'ja_ig_em', 'ja_ig_shortcode']);
        });
    }
};
