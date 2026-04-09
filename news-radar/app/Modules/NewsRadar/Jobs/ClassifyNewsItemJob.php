<?php

namespace App\Modules\NewsRadar\Jobs;

use App\Modules\NewsRadar\Enums\EnrichmentStatus;
use App\Modules\NewsRadar\Models\NewsItem;
use App\Modules\NewsRadar\Models\NewsItemAiMetadata;
use App\Modules\NewsRadar\Models\NewsTheme;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;

class ClassifyNewsItemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 30;

    public function __construct(
        private int $newsItemId,
    ) {
        $this->queue = 'news-radar-ai';
    }

    public function handle(): void
    {
        $item = NewsItem::find($this->newsItemId);
        if (!$item || $item->enrichment_status !== EnrichmentStatus::None) return;

        $excerpt = mb_substr($item->body_text ?? '', 0, 2000);
        $input = "Título: {$item->title}\n";
        if ($item->subtitle) $input .= "Subtítulo: {$item->subtitle}\n";
        $input .= "Texto: {$excerpt}";

        try {
            $response = OpenAI::chat()->create([
                'model' => config('news_radar.ai.classification_model', 'gpt-4o-mini'),
                'messages' => [
                    ['role' => 'system', 'content' => $this->getSystemPrompt()],
                    ['role' => 'user', 'content' => $input],
                ],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => 'news_classification',
                        'strict' => true,
                        'schema' => $this->getSchema(),
                    ],
                ],
            ]);

            $data = json_decode($response->choices[0]->message->content, true);
            if (!$data) throw new \RuntimeException('Invalid AI response');

            // Find or create theme
            $themeSlug = $this->normalizeTheme($data['theme'] ?? 'outro');
            $theme = NewsTheme::firstOrCreate(
                ['slug' => $themeSlug],
                ['label' => ucfirst(str_replace('_', ' ', $themeSlug))]
            );

            NewsItemAiMetadata::updateOrCreate(
                ['news_item_id' => $item->id],
                [
                    'city' => $data['city'] ?? null,
                    'state_abbr' => $data['state_abbr'] ?? 'SC',
                    'news_theme_id' => $theme->id,
                    'urgency' => $this->normalizeUrgency($data['urgency'] ?? 'baixa'),
                    'relevance_score' => min(1.0, max(0.0, (float)($data['relevance_score'] ?? 0.5))),
                    'entities' => $data['entities'] ?? [],
                    'enrichment_level' => 'level_1',
                ]
            );

            $item->update(['enrichment_status' => EnrichmentStatus::EnrichedL1]);

            // Dispatch enrichment if relevance >= 0.7
            $relevance = (float)($data['relevance_score'] ?? 0);
            if ($relevance >= 0.7) {
                EnrichNewsItemJob::dispatch($item->id)->onQueue('news-radar-ai');
            }

            Log::debug("[NewsRadar AI] Classified: {$item->title} (relevance: {$relevance})");

        } catch (\Throwable $e) {
            $item->update(['enrichment_status' => EnrichmentStatus::EnrichmentFailed]);
            Log::error("[NewsRadar AI] Classification failed: {$e->getMessage()}");
        }
    }

    private function getSystemPrompt(): string
    {
        return <<<PROMPT
Você é um classificador de notícias de Santa Catarina.
Analise a notícia e retorne um JSON com:
- city: cidade mencionada na notícia
- state_abbr: sigla do estado (geralmente SC)
- theme: tema editorial (politica, policia, esporte, economia, saude, educacao, cultura, tecnologia, meio_ambiente, transporte, sociedade, outro)
- urgency: baixa, media ou alta
- relevance_score: 0.0 a 1.0 (relevância para um jornal regional de SC)
- entities: array de {type: "pessoa"|"organizacao"|"local"|"evento", name: "..."}
PROMPT;
    }

    private function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'city' => ['type' => 'string'],
                'state_abbr' => ['type' => 'string'],
                'theme' => ['type' => 'string'],
                'urgency' => ['type' => 'string', 'enum' => ['baixa', 'media', 'alta']],
                'relevance_score' => ['type' => 'number'],
                'entities' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => ['type' => 'string', 'enum' => ['pessoa', 'organizacao', 'local', 'evento']],
                            'name' => ['type' => 'string'],
                        ],
                        'required' => ['type', 'name'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['city', 'state_abbr', 'theme', 'urgency', 'relevance_score', 'entities'],
            'additionalProperties' => false,
        ];
    }

    private function normalizeTheme(string $theme): string
    {
        $valid = ['politica', 'policia', 'esporte', 'economia', 'saude', 'educacao', 'cultura', 'tecnologia', 'meio_ambiente', 'transporte', 'sociedade', 'internacional', 'outro'];
        $theme = strtolower(trim($theme));
        return in_array($theme, $valid) ? $theme : 'outro';
    }

    private function normalizeUrgency(string $urgency): string
    {
        $urgency = strtolower(trim($urgency));
        return in_array($urgency, ['baixa', 'media', 'alta']) ? $urgency : 'baixa';
    }
}
