<?php

namespace App\Modules\NewsRadar\Services;

class FeedItemDto
{
    public function __construct(
        public readonly string $url,
        public readonly ?string $title = null,
        public readonly ?string $author = null,
        public readonly ?string $contentHtml = null,
        public readonly ?string $excerpt = null,
        public readonly ?string $imageUrl = null,
        public readonly ?string $publishedAt = null,
        public readonly ?string $guid = null,
        public readonly array $categories = [],
    ) {}

    public function toRawPayload(): array
    {
        return array_filter([
            'title' => $this->title,
            'author' => $this->author,
            'content_html' => $this->contentHtml,
            'excerpt' => $this->excerpt,
            'image_url' => $this->imageUrl,
            'published_at' => $this->publishedAt,
            'guid' => $this->guid,
            'categories' => $this->categories ?: null,
        ], fn ($v) => $v !== null);
    }

    public function hasFullContent(): bool
    {
        return $this->contentHtml !== null && mb_strlen(strip_tags($this->contentHtml)) > 600;
    }
}
