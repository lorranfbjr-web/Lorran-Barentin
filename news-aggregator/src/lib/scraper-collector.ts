import * as cheerio from 'cheerio';
import type { Fonte } from './db';
import { resolveUrl } from './url-utils';
import type { CollectedItem } from './rss-collector';

const USER_AGENT = 'JornalRazaoBot/1.0 (+https://jornalrazao.com/bot)';
const TIMEOUT_MS = 15000;

/**
 * Collects news from a source by scraping its homepage HTML.
 * Uses CSS selectors stored in the fontes table.
 */
export async function collectFromScraping(fonte: Fonte): Promise<CollectedItem[]> {
  const response = await fetch(fonte.url_base, {
    headers: { 'User-Agent': USER_AGENT },
    signal: AbortSignal.timeout(TIMEOUT_MS),
  });

  if (!response.ok) {
    throw new Error(`HTTP ${response.status} fetching ${fonte.url_base}`);
  }

  const html = await response.text();
  const $ = cheerio.load(html);
  const items: CollectedItem[] = [];

  const containerSelector = fonte.seletor_container || 'article, .post, .entry';
  const titleSelector = fonte.seletor_titulo || 'h2 a, h3 a, .entry-title a';
  const imageSelector = fonte.seletor_imagem || 'img';
  const linkSelector = fonte.seletor_link || 'h2 a, h3 a, a';

  $(containerSelector).each((_, el) => {
    const $el = $(el);

    // Extract title
    const $titleEl = $el.find(titleSelector).first();
    const titulo = $titleEl.text().trim();
    if (!titulo) return;

    // Extract link
    const $linkEl = $el.find(linkSelector).first();
    let url = $linkEl.attr('href') || $titleEl.attr('href');
    if (!url) return;
    url = resolveUrl(url, fonte.url_base);

    // Skip if it's just a # or javascript link
    if (url === '#' || url.startsWith('javascript:')) return;

    // Extract image
    const $img = $el.find(imageSelector).first();
    let imagem_url: string | null = null;
    const imgSrc = $img.attr('data-src') || $img.attr('data-lazy-src') || $img.attr('src');
    if (imgSrc) {
      imagem_url = resolveUrl(imgSrc, fonte.url_base);
    }

    items.push({
      titulo,
      url,
      imagem_url,
      data_publicacao: new Date().toISOString(),
    });
  });

  if (items.length === 0) {
    throw new Error(`No articles found scraping ${fonte.url_base}`);
  }

  return items;
}

/**
 * Attempts to fetch og:image from a news article URL.
 * Used as fallback when RSS doesn't include images.
 */
export async function fetchOgImage(articleUrl: string): Promise<string | null> {
  try {
    const response = await fetch(articleUrl, {
      headers: { 'User-Agent': USER_AGENT },
      signal: AbortSignal.timeout(10000),
    });
    if (!response.ok) return null;

    const html = await response.text();
    const $ = cheerio.load(html);

    const ogImage = $('meta[property="og:image"]').attr('content')
      || $('meta[name="twitter:image"]').attr('content');

    return ogImage || null;
  } catch {
    return null;
  }
}
