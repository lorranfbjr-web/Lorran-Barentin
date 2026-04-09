const BASE = '/api/v1/news-radar';

async function apiFetch<T>(path: string, init?: RequestInit): Promise<T> {
    const res = await fetch(`${BASE}${path}`, {
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
        ...init,
    });
    if (!res.ok) throw new Error(`API ${res.status}: ${res.statusText}`);
    return res.json();
}

// Types
export interface NewsItem {
    id: number;
    title: string;
    subtitle: string | null;
    url: string;
    hero_image_url: string | null;
    author_raw: string | null;
    published_at_utc: string | null;
    created_at: string;
    enrichment_status: string;
    source?: { id: number; name: string; homepage_url: string; region: string };
    ai_metadata?: {
        city: string | null;
        urgency: string | null;
        relevance_score: number | null;
        news_theme_id: number | null;
    };
}

export interface NewsSource {
    id: number;
    name: string;
    homepage_url: string;
    active: boolean;
    region: string | null;
    source_type: string;
    discovery_mode: string;
    consecutive_failures: number;
    items_count?: number;
    next_sync_at: string | null;
}

export interface DashboardStats {
    sources: { total: number; active: number; failing: number };
    items: { total: number; last_24h: number; last_1h: number };
    runs: { last_24h: number; failed_last_24h: number };
    last_collection: string | null;
}

export interface PaginatedResponse<T> {
    data: T[];
    meta: { current_page: number; last_page: number; per_page: number; total: number };
}

// API calls
export const fetchItems = (params: Record<string, string>) => {
    const qs = new URLSearchParams(Object.entries(params).filter(([, v]) => v)).toString();
    return apiFetch<PaginatedResponse<NewsItem>>(`/items?${qs}`);
};

export const fetchSources = (params?: Record<string, string>) => {
    const qs = params ? new URLSearchParams(Object.entries(params).filter(([, v]) => v)).toString() : '';
    return apiFetch<{ data: NewsSource[] }>(`/sources?${qs}`);
};

export const fetchStats = () => apiFetch<{ data: DashboardStats }>('/dashboard/stats');

export const fetchThemes = () => apiFetch<{ data: { id: number; slug: string; label: string; items_count: number }[] }>('/themes');

export const fetchRegions = () => apiFetch<{ data: string[] }>('/regions');

export const toggleSource = (id: number) =>
    apiFetch<{ data: NewsSource }>(`/sources/${id}/toggle`, { method: 'PATCH' });

export const fetchNow = (id: number) =>
    apiFetch<{ message: string }>(`/sources/${id}/fetch-now`, { method: 'POST' });

export const collectAll = () =>
    apiFetch<{ message: string; dispatched: number }>('/collect-all', { method: 'POST' });
