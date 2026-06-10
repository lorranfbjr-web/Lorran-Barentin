<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corpus REAL do Instagram @jornalrazao (via Apify instagram-post-scraper).
 * likesCount vem oculto (0/1 falso) e NÃO é gravado — engajamento principal =
 * comments_count; video_view_count secundário quando reel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jr_ig_corpus', function (Blueprint $table) {
            $table->id();
            $table->string('shortcode')->unique();
            $table->string('url')->nullable();
            $table->string('tipo_midia')->nullable();        // Image | Video | Sidecar
            $table->text('legenda')->nullable();
            $table->integer('comments_count')->default(0)->index();
            $table->integer('video_view_count')->nullable();
            $table->dateTime('postado_em')->nullable()->index();
            $table->string('tipo_pauta')->nullable()->index(); // preenchido pelo jrlink:ig-dna
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jr_ig_corpus');
    }
};
