import RssParser from 'rss-parser';
import type { Fonte } from './db';

const USER_AGENT = 'JornalRazaoBot/1.0 (+https://jornalrazao.com/bot)';
const TIMEOUT_MS = 15000;

const parser = new RssParser({
  timeout: TIMEOUT_MS,
  headers: { 'User-Agent': USER_AGENT },
  customFields: {
    item: [
      ['media:content', 'mediaContent', { keepArray: false }],
      ['media:thumbnail', 'mediaThumbnail', { keepArray: false }],
      ['enclosure', 'enclosure', { keepArray: false }],
    ],
  },
});

export interface CollectedItem {
  titulo: string;
  url: string;
  imagem_url: string | null;
  data_publicacao: string | null;
}

/**
 * Attempts to collect news from a source via RSS feed.
 * Tries multiple common feed URLs if the source doesn't have a specific feed URL.
 */
export async function collectFromRss(fonte: Fonte): Promise<CollectedItem[]> {
  const feedUrls = fonte.url_feed
    ? [fonte.url_feed]
    : [
        `${fonte.url_base}/feed/`,
        `${fonte.url_base}/rss/`,
        `${fonte.url_base}/feed/rss2/`,
      ];

  for (const feedUrl of feedUrls) {
    try {
      const feed = await parser.parseURL(feedUrl);
      const items: CollectedItem[] = [];

      for (const item of feed.items) {
        if (!item.title || !item.link) continue;

        const imagem = extractImage(item);
        const dataPub = item.isoDate || item.pubDate || null;

        items.push({
          titulo: item.title.trim(),
          url: item.link.trim(),
          imagem_url: imagem,
          data_publicacao: dataPub ? new Date(dataPub).toISOString() : null,
        });
      }

      return items;
    } catch {
      // Try next feed URL
      continue;
    }
  }

  throw new Error(`No RSS feed found for ${fonte.nome}`);
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
function extractImage(item: any): string | null {
  // Try media:content
  if (item.mediaContent?.$ ?.url) {
    return item.mediaContent.$.url;
  }
  // Try media:thumbnail
  if (item.mediaThumbnail?.$ ?.url) {
    return item.mediaThumbnail.$.url;
  }
  // Try enclosure
  if (item.enclosure?.url && item.enclosure?.type?.startsWith('image')) {
    return item.enclosure.url;
  }
  // Try content:encoded for first img tag
  if (item['content:encoded']) {
    const match = item['content:encoded'].match(/<img[^>]+src=["']([^"']+)["']/i);
    if (match) return match[1];
  }
  // Try content snippet
  if (item.content) {
    const match = item.content.match(/<img[^>]+src=["']([^"']+)["']/i);
    if (match) return match[1];
  }
  return null;
}
