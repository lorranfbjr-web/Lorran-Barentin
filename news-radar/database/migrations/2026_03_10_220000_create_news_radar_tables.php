<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. news_themes — Temas editoriais
        Schema::create('news_themes', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('label');
            $table->timestamps();
        });

        // 2. news_sources — Configuração de cada fonte
        Schema::create('news_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('homepage_url')->unique();
            $table->boolean('active')->default(true);
            $table->string('source_type')->default('portal'); // portal, prefeitura, blog, agencia, whatsapp
            $table->string('discovery_mode')->default('auto'); // auto, feed, sitemap, html_listing
            $table->string('feed_quality_profile')->nullable(); // full, partial, teaser_only
            $table->string('fetch_detail_mode')->default('when_incomplete'); // never, when_incomplete, always
            $table->json('crawling_config')->nullable();
            $table->json('throttle_config')->nullable();
            $table->string('timezone_default')->default('America/Sao_Paulo');
            $table->json('date_formats')->nullable();
            $table->boolean('render_js_required')->default(false);
            $table->string('region')->nullable();
            $table->timestamp('next_sync_at')->nullable();
            $table->timestamp('sync_locked_until')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->float('success_rate')->default(100);
            $table->timestamps();

            $table->index('active');
            $table->index('next_sync_at');
        });

        // 3. news_source_runs — Log de cada execução
        Schema::create('news_source_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('news_source_id')->constrained('news_sources')->cascadeOnDelete();
            $table->string('status')->default('running'); // running, success, partial, failed
            $table->string('discovery_mode_used')->nullable();
            $table->unsignedInteger('items_found')->default(0);
            $table->unsignedInteger('items_new')->default(0);
            $table->unsignedInteger('items_updated')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['news_source_id', 'created_at']);
        });

        // 4. source_discovery_runs — Wizard assíncrono de cadastro
        Schema::create('source_discovery_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('url');
            $table->string('status')->default('pending'); // pending, running, completed, failed
            $table->json('result')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        // 5. news_raw_items — Staging bruto (pré-promoção)
        Schema::create('news_raw_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('news_source_id')->constrained('news_sources')->cascadeOnDelete();
            $table->foreignId('news_source_run_id')->nullable()->constrained('news_source_runs')->nullOnDelete();
            $table->string('raw_url', 2048);
            $table->string('normalized_url', 2048);
            $table->string('url_hash', 64);
            $table->string('guid')->nullable();
            $table->json('raw_payload');
            $table->string('processing_status')->default('pending'); // pending, processing, promoted, skipped, failed
            $table->unsignedInteger('fetch_attempts')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->unsignedInteger('seen_count')->default(1);
            $table->timestamps();

            $table->unique(['news_source_id', 'url_hash']);
            $table->index('processing_status');
        });

        // 6. news_items — Notícia processada final
        Schema::create('news_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('news_source_id')->constrained('news_sources')->cascadeOnDelete();
            $table->foreignId('news_raw_item_id')->nullable()->constrained('news_raw_items')->nullOnDelete();
            $table->string('title', 1000);
            $table->string('subtitle', 1000)->nullable();
            $table->string('author_raw')->nullable();
            $table->string('author_normalized')->nullable();
            $table->longText('body_html')->nullable();
            $table->longText('body_text')->nullable();
            $table->string('hero_image_url', 2048)->nullable();
            $table->string('url', 2048);
            $table->string('url_hash', 64)->unique();
            $table->string('published_at_raw')->nullable();
            $table->timestamp('published_at_parsed')->nullable();
            $table->timestamp('published_at_utc')->nullable();
            $table->string('published_at_timezone')->nullable();
            $table->string('published_at_source')->nullable(); // rss, jsonld, og_tag, time_tag, text_pattern, manual
            $table->unsignedInteger('extraction_completeness')->default(0);
            $table->string('content_source')->default('feed_only'); // feed_only, feed_plus_html, html_only
            $table->string('extraction_status')->default('pending'); // pending, extracted, extraction_failed
            $table->string('enrichment_status')->default('none'); // none, enriched_l1, enriched_l2, enrichment_failed
            $table->json('field_sources')->nullable(); // audit trail: {field: source}
            $table->json('categories')->nullable();
            $table->foreignId('duplicate_of_id')->nullable()->constrained('news_items')->nullOnDelete();
            $table->timestamps();

            $table->index('published_at_utc');
            $table->index('extraction_status');
            $table->index('enrichment_status');
            $table->index(['news_source_id', 'created_at']);
            $table->index('title');
        });

        // 7. news_item_ai_metadata — Metadados de IA (1:1 com news_items)
        Schema::create('news_item_ai_metadata', function (Blueprint $table) {
            $table->id();
            $table->foreignId('news_item_id')->unique()->constrained('news_items')->cascadeOnDelete();
            $table->string('city')->nullable();
            $table->string('state_abbr', 2)->nullable();
            $table->foreignId('news_theme_id')->nullable()->constrained('news_themes')->nullOnDelete();
            $table->string('urgency')->nullable(); // baixa, media, alta
            $table->float('relevance_score')->nullable();
            $table->json('entities')->nullable();
            $table->json('five_ws')->nullable();
            $table->json('suggested_titles')->nullable();
            $table->json('summary_bullets')->nullable();
            $table->string('enrichment_level')->default('none'); // none, level_1, level_2
            $table->timestamps();
        });

        // 8. news_item_media — Mídia associada
        Schema::create('news_item_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('news_item_id')->constrained('news_items')->cascadeOnDelete();
            $table->string('type')->default('hero'); // hero, gallery, video, embed
            $table->string('url', 2048);
            $table->string('alt_text')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('news_item_id');
        });

        // 9. news_clusters — Agrupamento de notícias similares
        Schema::create('news_clusters', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->foreignId('representative_item_id')->nullable()->constrained('news_items')->nullOnDelete();
            $table->unsignedInteger('items_count')->default(0);
            $table->timestamps();
        });

        // 10. news_cluster_items — Pivot cluster <-> items
        Schema::create('news_cluster_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('news_cluster_id')->constrained('news_clusters')->cascadeOnDelete();
            $table->foreignId('news_item_id')->constrained('news_items')->cascadeOnDelete();
            $table->float('similarity_score')->nullable();
            $table->timestamps();

            $table->unique(['news_cluster_id', 'news_item_id']);
        });

        // 11. news_item_ai_logs — Log de cada chamada IA
        Schema::create('news_item_ai_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('news_item_id')->constrained('news_items')->cascadeOnDelete();
            $table->string('operation'); // classify_basic, enrich_editorial
            $table->string('model')->nullable();
            $table->string('status'); // success, failed
            $table->unsignedInteger('attempt')->default(1);
            $table->string('strategy')->nullable(); // structured_outputs, prompt_json
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->string('error_category')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['news_item_id', 'operation']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_item_ai_logs');
        Schema::dropIfExists('news_cluster_items');
        Schema::dropIfExists('news_clusters');
        Schema::dropIfExists('news_item_media');
        Schema::dropIfExists('news_item_ai_metadata');
        Schema::dropIfExists('news_items');
        Schema::dropIfExists('news_raw_items');
        Schema::dropIfExists('source_discovery_runs');
        Schema::dropIfExists('news_source_runs');
        Schema::dropIfExists('news_sources');
        Schema::dropIfExists('news_themes');
    }
};
