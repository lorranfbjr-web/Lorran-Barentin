import { getDb, type Fonte } from './db';
import { collectFromRss, type CollectedItem } from './rss-collector';
import { collectFromScraping, fetchOgImage } from './scraper-collector';
import { normalizeUrl } from './url-utils';

const MAX_CONSECUTIVE_FAILURES = 5;
const DELAY_BETWEEN_SOURCES_MS = 1500;

function sleep(ms: number): Promise<void> {
  return new Promise(resolve => setTimeout(resolve, ms));
}

/**
 * Collects news from a single source.
 */
async function collectFromSource(fonte: Fonte): Promise<{ items: CollectedItem[]; error?: string }> {
  try {
    let items: CollectedItem[];

    if (fonte.tipo_coleta === 'rss' || fonte.url_feed) {
      items = await collectFromRss(fonte);
    } else {
      items = await collectFromScraping(fonte);
    }

    // For RSS items without images, try og:image for the first few
    const itemsWithoutImages = items.filter(i => !i.imagem_url).slice(0, 3);
    for (const item of itemsWithoutImages) {
      const ogImage = await fetchOgImage(item.url);
      if (ogImage) {
        item.imagem_url = ogImage;
      }
    }

    return { items };
  } catch (err) {
    // If RSS failed, try scraping as fallback
    if (fonte.tipo_coleta === 'rss') {
      try {
        const items = await collectFromScraping(fonte);
        return { items };
      } catch {
        // Both failed
      }
    }
    return { items: [], error: err instanceof Error ? err.message : String(err) };
  }
}

/**
 * Saves collected items to the database, deduplicating by URL.
 */
function saveItems(fonteId: number, items: CollectedItem[]): number {
  const db = getDb();
  const insertStmt = db.prepare(`
    INSERT OR IGNORE INTO noticias (fonte_id, titulo, url, imagem_url, data_publicacao)
    VALUES (?, ?, ?, ?, ?)
  `);

  let newCount = 0;
  for (const item of items) {
    const normalizedUrl = normalizeUrl(item.url);
    const result = insertStmt.run(
      fonteId,
      item.titulo,
      normalizedUrl,
      item.imagem_url,
      item.data_publicacao
    );
    if (result.changes > 0) newCount++;
  }

  return newCount;
}

/**
 * Logs a collection attempt.
 */
function logCollection(fonteId: number, status: 'sucesso' | 'erro' | 'timeout', qtdNovas: number, errorMsg?: string) {
  const db = getDb();
  db.prepare(`
    INSERT INTO coletas_log (fonte_id, status, qtd_novas, mensagem_erro)
    VALUES (?, ?, ?, ?)
  `).run(fonteId, status, qtdNovas, errorMsg || null);

  // Update fonte
  if (status === 'sucesso') {
    db.prepare(`
      UPDATE fontes SET ultima_coleta = datetime('now'), falhas_consecutivas = 0 WHERE id = ?
    `).run(fonteId);
  } else {
    db.prepare(`
      UPDATE fontes SET falhas_consecutivas = falhas_consecutivas + 1 WHERE id = ?
    `).run(fonteId);

    // Auto-disable after too many consecutive failures
    db.prepare(`
      UPDATE fontes SET ativo = 0 WHERE id = ? AND falhas_consecutivas >= ?
    `).run(fonteId, MAX_CONSECUTIVE_FAILURES);
  }
}

/**
 * Runs collection for all active sources of a given priority.
 */
export async function runCollection(prioridade?: 'alta' | 'normal'): Promise<{
  totalSources: number;
  totalNew: number;
  errors: number;
}> {
  const db = getDb();

  let fontes: Fonte[];
  if (prioridade) {
    fontes = db.prepare('SELECT * FROM fontes WHERE ativo = 1 AND prioridade = ?').all(prioridade) as Fonte[];
  } else {
    fontes = db.prepare('SELECT * FROM fontes WHERE ativo = 1').all() as Fonte[];
  }

  let totalNew = 0;
  let errors = 0;

  for (const fonte of fontes) {
    const { items, error } = await collectFromSource(fonte);

    if (error) {
      logCollection(fonte.id, 'erro', 0, error);
      errors++;
    } else {
      const newCount = saveItems(fonte.id, items);
      logCollection(fonte.id, 'sucesso', newCount);
      totalNew += newCount;
    }

    // Delay between sources to be respectful
    await sleep(DELAY_BETWEEN_SOURCES_MS);
  }

  return { totalSources: fontes.length, totalNew, errors };
}

/**
 * Cleans up news older than 30 days.
 */
export function cleanupOldNews(): number {
  const db = getDb();
  const result = db.prepare(`
    DELETE FROM noticias WHERE data_coleta < datetime('now', '-30 days')
  `).run();
  return result.changes;
}
