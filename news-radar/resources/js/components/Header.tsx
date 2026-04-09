import React from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { fetchStats, collectAll } from '../api';

interface Props {
    activeTab: 'feed' | 'sources';
    onTabChange: (tab: 'feed' | 'sources') => void;
}

export default function Header({ activeTab, onTabChange }: Props) {
    const queryClient = useQueryClient();
    const { data } = useQuery({ queryKey: ['stats'], queryFn: fetchStats });
    const stats = data?.data;

    const handleCollect = async () => {
        await collectAll();
        queryClient.invalidateQueries({ queryKey: ['stats'] });
    };

    const formatTime = (iso: string | null | undefined) => {
        if (!iso) return '—';
        const d = new Date(iso);
        const now = Date.now();
        const diff = Math.floor((now - d.getTime()) / 60000);
        if (diff < 1) return 'agora';
        if (diff < 60) return `há ${diff}min`;
        if (diff < 1440) return `há ${Math.floor(diff / 60)}h`;
        return d.toLocaleDateString('pt-BR');
    };

    return (
        <header className="bg-[#16213e] border-b border-white/10">
            <div className="max-w-7xl mx-auto px-4 py-4">
                <div className="flex items-center justify-between flex-wrap gap-4">
                    <div className="flex items-center gap-4">
                        <h1 className="text-2xl font-black tracking-tight">
                            <span className="text-[#0061FF]">Radar</span>{' '}
                            <span className="text-[#18ADFE]">Editorial</span>
                        </h1>
                        <span className="text-xs text-white/40 hidden sm:inline">Jornal Razão</span>
                    </div>

                    <div className="flex items-center gap-6 text-sm">
                        {stats && (
                            <div className="hidden md:flex items-center gap-4 text-white/60">
                                <span>{stats.items.last_24h} notícias (24h)</span>
                                <span>{stats.sources.active} fontes ativas</span>
                                <span>Última coleta: {formatTime(stats.last_collection)}</span>
                            </div>
                        )}
                        <button
                            onClick={handleCollect}
                            className="px-4 py-2 bg-[#0061FF] hover:bg-[#0061FF]/80 rounded-lg text-sm font-semibold transition-colors"
                        >
                            Atualizar
                        </button>
                    </div>
                </div>

                <nav className="flex gap-1 mt-4">
                    {([['feed', 'Feed'], ['sources', 'Fontes']] as const).map(([key, label]) => (
                        <button
                            key={key}
                            onClick={() => onTabChange(key)}
                            className={`px-4 py-2 rounded-t-lg text-sm font-semibold transition-colors ${
                                activeTab === key
                                    ? 'bg-[#1a1a2e] text-white'
                                    : 'text-white/50 hover:text-white/80'
                            }`}
                        >
                            {label}
                        </button>
                    ))}
                </nav>
            </div>
        </header>
    );
}
