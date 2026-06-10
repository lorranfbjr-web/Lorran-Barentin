import React, { useEffect, useRef } from 'react';
import { useInfiniteQuery } from '@tanstack/react-query';
import { fetchItems } from '../api';
import NewsCard from './NewsCard';

interface Props {
    filters: Record<string, string>;
}

export default function NewsFeed({ filters }: Props) {
    const loaderRef = useRef<HTMLDivElement | null>(null);

    const {
        data,
        fetchNextPage,
        hasNextPage,
        isFetchingNextPage,
        isLoading,
        isError,
    } = useInfiniteQuery({
        queryKey: ['items', filters],
        queryFn: ({ pageParam = 1 }) =>
            fetchItems({ ...filters, page: String(pageParam), limit: '9' }),
        initialPageParam: 1,
        getNextPageParam: (last: any) => {
            const m = last?.meta;
            if (!m) return undefined;
            return m.current_page < m.last_page ? m.current_page + 1 : undefined;
        },
    });

    // Intersection observer pro scroll infinito
    useEffect(() => {
        if (!loaderRef.current) return;
        const obs = new IntersectionObserver(
            (entries) => {
                if (entries[0].isIntersecting && hasNextPage && !isFetchingNextPage) {
                    fetchNextPage();
                }
            },
            { rootMargin: '400px' }
        );
        obs.observe(loaderRef.current);
        return () => obs.disconnect();
    }, [hasNextPage, isFetchingNextPage, fetchNextPage]);

    if (isLoading) {
        return (
            <div className="grid grid-cols-3 gap-2">
                {Array.from({ length: 9 }).map((_, i) => (
                    <div key={i} className="bg-[#16213e] rounded-lg overflow-hidden animate-pulse">
                        <div className="aspect-square bg-[#0f1629]" />
                        <div className="p-2 space-y-1">
                            <div className="h-2 bg-white/5 rounded w-1/2" />
                            <div className="h-3 bg-white/5 rounded w-full" />
                        </div>
                    </div>
                ))}
            </div>
        );
    }

    if (isError) {
        return <div className="text-center py-12 text-white/50">Erro ao carregar notícias.</div>;
    }

    const items = data?.pages.flatMap((p: any) => p.data ?? []) ?? [];
    const total = data?.pages?.[0]?.meta?.total ?? items.length;

    if (items.length === 0) {
        return <div className="text-center py-12 text-white/50">Nenhuma notícia encontrada.</div>;
    }

    return (
        <>
            <div className="grid grid-cols-3 gap-2">
                {items.map((item) => (
                    <NewsCard key={item.id} item={item} />
                ))}
            </div>

            <div ref={loaderRef} className="h-16 flex items-center justify-center text-xs text-white/40">
                {isFetchingNextPage ? 'Carregando...' : hasNextPage ? 'Role para carregar mais' : `${items.length} de ${total} notícias`}
            </div>
        </>
    );
}
