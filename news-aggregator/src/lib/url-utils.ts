/**
 * Normalizes a URL for deduplication:
 * - Removes trailing slashes
 * - Removes common tracking query params (utm_*, fbclid, etc.)
 * - Lowercases the hostname
 */
export function normalizeUrl(rawUrl: string): string {
  try {
    const url = new URL(rawUrl);
    url.hostname = url.hostname.toLowerCase();

    // Remove tracking params
    const trackingParams = [
      'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
      'fbclid', 'gclid', 'ref', 'source', 'mc_cid', 'mc_eid',
    ];
    for (const param of trackingParams) {
      url.searchParams.delete(param);
    }

    // Remove trailing slash
    let normalized = url.toString();
    if (normalized.endsWith('/')) {
      normalized = normalized.slice(0, -1);
    }
    return normalized;
  } catch {
    return rawUrl;
  }
}

/**
 * Resolves a potentially relative URL against a base URL.
 */
export function resolveUrl(href: string, baseUrl: string): string {
  try {
    return new URL(href, baseUrl).toString();
  } catch {
    return href;
  }
}

/**
 * Extracts the domain name from a URL for display purposes.
 */
export function getDomain(url: string): string {
  try {
    return new URL(url).hostname.replace('www.', '');
  } catch {
    return url;
  }
}
