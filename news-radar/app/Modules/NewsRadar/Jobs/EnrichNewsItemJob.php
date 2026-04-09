<?php

namespace App\Modules\NewsRadar\Jobs;

use App\Modules\NewsRadar\Enums\EnrichmentStatus;
use App\Modules\NewsRadar\Models\NewsItem;
use App\Modules\NewsRadar\Models\NewsItemAiMetadata;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;

class EnrichNewsItemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 45;

    public function __construct(
        private int $newsItemId,
    ) {
        $this->queue = 'news-radar-ai';
    }

    public function handle(): void
    {
        $item = NewsItem::find($this->newsItemId);
        if (!$item || $item->enrichment_status !== EnrichmentStatus::EnrichedL1) return;

        $bodyExcerpt = mb_substr($item->body_text ?? '', 0, 5000);
        $input = "Título: {$item->title}\n";
        if ($item->subtitle) $input .= "Subtítulo: {$item->subtitle}\n";
        if ($item->author_raw) $input .= "Autor: {$item->author_raw}\n";
        $input .= "Texto: {$bodyExcerpt}";

        try {
            $response = OpenAI::chat()->create([
                'model' => config('news_radar.ai.enrichment_model', 'gpt-4o-mini'),
                'messages' => [
                    ['role' => 'system', 'content' => $this->getSystemPrompt()],
                    ['role' => 'user', 'content' => $input],
                ],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => 'news_enrichment',
                        'strict' => true,
                        'schema' => $this->getSchema(),
                    ],
                ],
            ]);

            $data = json_decode($response->choices[0]->message->content, true);
            if (!$data) throw new \RuntimeException('Invalid AI response');

            $metadata = NewsItemAiMetadata::where('news_item_id', $item->id)->first();
            if ($metadata) {
                $metadata->update([
                    'five_ws' => $data['five_ws'] ?? null,
                    'suggested_titles' => $data['suggested_titles'] ?? null,
                    'summary_bullets' => $data['summary_bullets'] ?? null,
                    'enrichment_level' => 'level_2',
                ]);
            }

            $item->update(['enrichment_status' => EnrichmentStatus::EnrichedL2]);

            Log::debug("[NewsRadar AI] Enriched L2: {$item->title}");

        } catch (\Throwable $e) {
            Log::error("[NewsRadar AI] Enrichment failed: {$e->getMessage()}");
        }
    }

    private function getSystemPrompt(): string
    {
        return <<<PROMPT
Você é um editor de notícias. Analise a matéria e retorne um JSON com:
- five_ws: objeto com {who, what, where, when, why, how} - cada campo uma frase curta
- suggested_titles: array com 3 títulos alternativos para a matéria
- summary_bullets: array com 3-5 bullet points resumindo os pontos-chave
PROMPT;
    }

    private function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'five_ws' => [
                    'type' => 'object',
                    'properties' => [
                        'who' => ['type' => 'string'],
                        'what' => ['type' => 'string'],
                        'where' => ['type' => 'string'],
                        'when' => ['type' => 'string'],
                        'why' => ['type' => 'string'],
                        'how' => ['type' => 'string'],
                    ],
                    'required' => ['who', 'what', 'where', 'when', 'why', 'how'],
                    'additionalProperties' => false,
                ],
                'suggested_titles' => ['type' => 'array', 'items' => ['type' => 'string']],
                'summary_bullets' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['five_ws', 'suggested_titles', 'summary_bullets'],
            'additionalProperties' => false,
        ];
    }
}
