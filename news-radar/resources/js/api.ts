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

// ── Aba Radar (JR Pauta / juiz) — chave leve via ?key= (cookie 90d depois) ──
export interface RadarFonte { nome: string; url: string }

export interface RadarItem {
    evento_id: number | string;
    lider_id: number;
    titulo: string;
    url: string;
    score_evento: number | null;
    score_atual: number | null;
    idade_horas: number | null;
    score_coarse: number;
    eixo: 'primaria' | 'concorrente';
    escopo: string | null;
    tipo_gancho: string | null;
    cidade_llm: string | null;
    juiz_motivo: string | null;
    temperatura_final: string | null;
    n_portais: number;
    fontes: RadarFonte[];
    primeira_cobertura: string | null;
    ultima_cobertura: string | null;
    publicado_em: string | null;
    created_at: string;
    notificado_em: string | null;
    ja_publicado_em: string | null;
    ja_publicado_slug: string | null;
    ja_ig_em: string | null;
    ja_ig_shortcode: string | null;
    faixa_voto: 'baixa' | 'media' | 'alta' | null;
    score_trending?: number;
}

export interface RadarMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    votados?: number;
    total_triagem?: number;
}

export const radarKeyFromUrl = (): string =>
    new URLSearchParams(window.location.search).get('key') ?? '';

export const fetchRadar = (params: Record<string, string>) => {
    const key = radarKeyFromUrl();
    const qs = new URLSearchParams(
        Object.entries({ ...params, ...(key ? { key } : {}) }).filter(([, v]) => v),
    ).toString();
    return fetch(`/api/v1/jrlink/radar?${qs}`, { headers: { Accept: 'application/json' } }).then((res) => {
        if (res.status === 403 || res.status === 401) throw new Error('403');
        if (!res.ok) throw new Error(`API ${res.status}`);
        return res.json() as Promise<PaginatedResponse<RadarItem>>;
    });
};

export const votarFeedback = (itemId: number, faixa: 'baixa' | 'media' | 'alta') => {
    const key = radarKeyFromUrl();
    const qs = key ? `?key=${encodeURIComponent(key)}` : '';
    return fetch(`/api/v1/jrlink/feedback${qs}`, {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify({ item_id: itemId, faixa }),
    }).then((res) => {
        if (!res.ok) throw new Error(`API ${res.status}`);
        return res.json() as Promise<{ ok: boolean; faixa: string }>;
    });
};
