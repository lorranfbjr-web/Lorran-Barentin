import React, { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { fetchItems } from '../api';
import NewsCard from './NewsCard';

interface Props {
    filters: Record<string, string>;
}

export default function NewsFeed({ filters }: Props) {
    const [page, setPage] = useState(1);

    const params = { ...filters, page: String(page), limit: '30' };
    const { data, isLoading, isError } = useQuery({
        queryKey: ['items', params],
        queryFn: () => fetchItems(params),
    });

    if (isLoading) {
        return (
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                {Array.from({ length: 12 }).map((_, i) => (
                    <div key={i} className="bg-[#16213e] rounded-xl overflow-hidden animate-pulse">
                        <div className="aspect-video bg-[#0f1629]" />
                        <div className="p-4 space-y-2">
                            <div className="h-3 bg-white/5 rounded w-1/3" />
                            <div className="h-4 bg-white/5 rounded w-full" />
                            <div className="h-4 bg-white/5 rounded w-2/3" />
                        </div>
                    </div>
                ))}
            </div>
        );
    }

    if (isError) {
        return (
            <div className="text-center py-12 text-white/50">
                Erro ao carregar notícias. Tente novamente.
            </div>
        );
    }

    const items = data?.data ?? [];
    const meta = data?.meta;

    if (items.length === 0) {
        return (
            <div className="text-center py-12 text-white/50">
                Nenhuma notícia encontrada com os filtros selecionados.
            </div>
        );
    }

    return (
        <>
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                {items.map((item) => (
                    <NewsCard key={item.id} item={item} />
                ))}
            </div>

            {meta && meta.current_page < meta.last_page && (
                <div className="text-center mt-8">
                    <button
                        onClick={() => setPage((p) => p + 1)}
                        className="px-6 py-3 bg-[#16213e] hover:bg-[#16213e]/80 border border-white/10 rounded-lg text-sm font-semibold transition-colors"
                    >
                        Carregar mais notícias ({meta.total - meta.current_page * meta.per_page} restantes)
                    </button>
                </div>
            )}

            {meta && (
                <p className="text-center text-xs text-white/30 mt-4">
                    Mostrando {Math.min(meta.current_page * meta.per_page, meta.total)} de {meta.total} notícias
                </p>
            )}
        </>
    );
}
