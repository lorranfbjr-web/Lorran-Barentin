import cron from 'node-cron';
import { runCollection, cleanupOldNews } from './collector';

let initialized = false;

/**
 * Initializes the cron scheduler for news collection.
 * Should be called once on server startup.
 */
export function initScheduler() {
  if (initialized) return;
  initialized = true;

  console.log('[Scheduler] Initializing cron jobs...');

  // Every 15 minutes: collect from high-priority sources
  cron.schedule('*/15 * * * *', async () => {
    console.log('[Scheduler] Running high-priority collection...');
    try {
      const result = await runCollection('alta');
      console.log(`[Scheduler] High-priority done: ${result.totalNew} new articles from ${result.totalSources} sources (${result.errors} errors)`);
    } catch (err) {
      console.error('[Scheduler] High-priority collection failed:', err);
    }
  });

  // Every 30 minutes: collect from normal-priority sources
  cron.schedule('*/30 * * * *', async () => {
    console.log('[Scheduler] Running normal-priority collection...');
    try {
      const result = await runCollection('normal');
      console.log(`[Scheduler] Normal-priority done: ${result.totalNew} new articles from ${result.totalSources} sources (${result.errors} errors)`);
    } catch (err) {
      console.error('[Scheduler] Normal-priority collection failed:', err);
    }
  });

  // Daily at 3 AM: cleanup old news
  cron.schedule('0 3 * * *', () => {
    console.log('[Scheduler] Cleaning up old news...');
    try {
      const deleted = cleanupOldNews();
      console.log(`[Scheduler] Cleaned up ${deleted} old articles`);
    } catch (err) {
      console.error('[Scheduler] Cleanup failed:', err);
    }
  });

  console.log('[Scheduler] Cron jobs initialized:');
  console.log('  - High-priority collection: every 15 min');
  console.log('  - Normal-priority collection: every 30 min');
  console.log('  - Old news cleanup: daily at 3 AM');
}
