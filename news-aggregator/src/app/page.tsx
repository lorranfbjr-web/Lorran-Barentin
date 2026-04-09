'use client';

import { useCallback, useEffect, useState } from 'react';
import Header from '@/components/Header';
import SearchBar from '@/components/SearchBar';
import FilterBar from '@/components/FilterBar';
import NewsGrid from '@/components/NewsGrid';
import LoadMore from '@/components/LoadMore';

interface Noticia {
  id: number;
  titulo: string;
  url: string;
  imagem_url: string | null;
  fonte_nome: string;
  fonte_regiao: string;
  data_publicacao: string | null;
  data_coleta: string;
}

interface FonteOption {
  id: number;
  nome: string;
}

const AUTO_REFRESH_INTERVAL = 5 * 60 * 1000; // 5 minutes

export default function DashboardPage() {
  const [noticias, setNoticias] = useState<Noticia[]>([]);
  const [fontes, setFontes] = useState<FonteOption[]>([]);
  const [regioes, setRegioes] = useState<string[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [lastUpdate, setLastUpdate] = useState<string | null>(null);

  // Filters
  const [search, setSearch] = useState('');
  const [selectedFontes, setSelectedFontes] = useState<number[]>([]);
  const [selectedRegiao, setSelectedRegiao] = useState('');
  const [selectedPeriodo, setSelectedPeriodo] = useState('');

  // Load sources for filter dropdowns
  useEffect(() => {
    fetch('/api/fontes')
      .then(res => res.json())
      .then(data => {
        setFontes(data.fontes || []);
        setRegioes(data.regioes || []);
      })
      .catch(console.error);
  }, []);

  // Fetch news
  const fetchNews = useCallback(async (pageNum: number, append = false) => {
    const params = new URLSearchParams();
    params.set('page', String(pageNum));
    params.set('limit', '30');
    if (search) params.set('q', search);
    if (selectedFontes.length > 0) params.set('fonte_id', selectedFontes.join(','));
    if (selectedRegiao) params.set('regiao', selectedRegiao);
    if (selectedPeriodo) params.set('periodo', selectedPeriodo);

    const res = await fetch(`/api/noticias?${params.toString()}`);
    const data = await res.json();

    if (append) {
      setNoticias(prev => [...prev, ...(data.noticias || [])]);
    } else {
      setNoticias(data.noticias || []);
    }
    setTotal(data.total || 0);
    setLastUpdate(new Date().toLocaleString('pt-BR'));
  }, [search, selectedFontes, selectedRegiao, selectedPeriodo]);

  // Initial load + filter changes
  useEffect(() => {
    setPage(1);
    setLoading(true);
    fetchNews(1).finally(() => setLoading(false));
  }, [fetchNews]);

  // Auto-refresh
  useEffect(() => {
    const interval = setInterval(() => {
      fetchNews(1);
    }, AUTO_REFRESH_INTERVAL);
    return () => clearInterval(interval);
  }, [fetchNews]);

  // Manual refresh (trigger collection + reload)
  async function handleRefresh() {
    setRefreshing(true);
    try {
      await fetch('/api/coletar', { method: 'POST' });
      setPage(1);
      await fetchNews(1);
    } catch (err) {
      console.error('Refresh failed:', err);
    } finally {
      setRefreshing(false);
    }
  }

  // Load more
  async function handleLoadMore() {
    const nextPage = page + 1;
    setLoadingMore(true);
    try {
      await fetchNews(nextPage, true);
      setPage(nextPage);
    } finally {
      setLoadingMore(false);
    }
  }

  return (
    <div className="min-h-screen bg-[#1a1a2e]">
      <Header
        lastUpdate={lastUpdate}
        onRefresh={handleRefresh}
        loading={refreshing}
      />

      <main className="max-w-7xl mx-auto px-4 py-4 space-y-4">
        {/* Search + Filters */}
        <div className="flex flex-col sm:flex-row gap-3">
          <div className="flex-1">
            <SearchBar value={search} onChange={setSearch} />
          </div>
          <FilterBar
            fontes={fontes}
            regioes={regioes}
            selectedFontes={selectedFontes}
            selectedRegiao={selectedRegiao}
            selectedPeriodo={selectedPeriodo}
            onFontesChange={setSelectedFontes}
            onRegiaoChange={setSelectedRegiao}
            onPeriodoChange={setSelectedPeriodo}
          />
        </div>

        {/* Stats bar */}
        <div className="flex items-center gap-4 text-[#5a5a8e] text-xs">
          <span>{total} notícias encontradas</span>
          {(search || selectedFontes.length > 0 || selectedRegiao || selectedPeriodo) && (
            <button
              onClick={() => {
                setSearch('');
                setSelectedFontes([]);
                setSelectedRegiao('');
                setSelectedPeriodo('');
              }}
              className="text-[#FF9F00] hover:underline"
            >
              Limpar filtros
            </button>
          )}
        </div>

        {/* News Grid */}
        <NewsGrid noticias={noticias} loading={loading} />

        {/* Load More */}
        <LoadMore
          hasMore={noticias.length < total}
          loading={loadingMore}
          onClick={handleLoadMore}
          total={total}
          showing={noticias.length}
        />
      </main>
    </div>
  );
}
