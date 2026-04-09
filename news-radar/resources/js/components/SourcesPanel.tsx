import React, { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { fetchSources, toggleSource, fetchNow, type NewsSource } from '../api';

export default function SourcesPanel() {
    const queryClient = useQueryClient();
    const [regionFilter, setRegionFilter] = useState('');

    const { data, isLoading } = useQuery({
        queryKey: ['sources', regionFilter],
        queryFn: () => fetchSources(regionFilter ? { region: regionFilter } : undefined),
    });

    const toggleMut = useMutation({
        mutationFn: toggleSource,
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['sources'] }),
    });

    const fetchMut = useMutation({
        mutationFn: fetchNow,
    });

    const sources = data?.data ?? [];
    const regions = [...new Set(sources.map((s) => s.region).filter(Boolean))].sort();

    if (isLoading) {
        return <div className="text-center py-12 text-white/50">Carregando fontes...</div>;
    }

    return (
        <div>
            <div className="flex items-center justify-between mb-6">
                <h2 className="text-lg font-bold">Fontes ({sources.length})</h2>
                <select
                    value={regionFilter}
                    onChange={(e) => setRegionFilter(e.target.value)}
                    className="px-3 py-2 bg-[#16213e] border border-white/10 rounded-lg text-sm text-white"
                >
                    <option value="">Todas as regiões</option>
                    {regions.map((r) => (
                        <option key={r} value={r!}>{r}</option>
                    ))}
                </select>
            </div>

            <div className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead>
                        <tr className="text-left text-white/50 border-b border-white/10">
                            <th className="pb-3 pr-4">Nome</th>
                            <th className="pb-3 pr-4 hidden md:table-cell">Região</th>
                            <th className="pb-3 pr-4 hidden lg:table-cell">Modo</th>
                            <th className="pb-3 pr-4 text-right">Notícias</th>
                            <th className="pb-3 pr-4 text-center">Status</th>
                            <th className="pb-3 text-right">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        {sources.map((s) => (
                            <SourceRow
                                key={s.id}
                                source={s}
                                onToggle={() => toggleMut.mutate(s.id)}
                                onFetch={() => fetchMut.mutate(s.id)}
                            />
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

function SourceRow({ source, onToggle, onFetch }: { source: NewsSource; onToggle: () => void; onFetch: () => void }) {
    return (
        <tr className="border-b border-white/5 hover:bg-white/5 transition-colors">
            <td className="py-3 pr-4">
                <div>
                    <a href={source.homepage_url} target="_blank" rel="noopener noreferrer" className="font-semibold hover:text-[#18ADFE] transition-colors">
                        {source.name}
                    </a>
                    {source.consecutive_failures > 0 && (
                        <span className="ml-2 text-xs text-red-400">({source.consecutive_failures} falhas)</span>
                    )}
                </div>
            </td>
            <td className="py-3 pr-4 hidden md:table-cell text-white/60">{source.region}</td>
            <td className="py-3 pr-4 hidden lg:table-cell text-white/60 capitalize">{source.discovery_mode}</td>
            <td className="py-3 pr-4 text-right text-white/60">{source.items_count ?? 0}</td>
            <td className="py-3 pr-4 text-center">
                <span className={`inline-block w-2 h-2 rounded-full ${source.active ? 'bg-emerald-400' : 'bg-red-400'}`} />
            </td>
            <td className="py-3 text-right">
                <div className="flex items-center justify-end gap-2">
                    <button
                        onClick={onToggle}
                        className={`px-2 py-1 rounded text-xs font-semibold transition-colors ${
                            source.active
                                ? 'bg-red-500/20 text-red-400 hover:bg-red-500/30'
                                : 'bg-emerald-500/20 text-emerald-400 hover:bg-emerald-500/30'
                        }`}
                    >
                        {source.active ? 'Desativar' : 'Ativar'}
                    </button>
                    <button
                        onClick={onFetch}
                        className="px-2 py-1 rounded text-xs font-semibold bg-[#0061FF]/20 text-[#18ADFE] hover:bg-[#0061FF]/30 transition-colors"
                    >
                        Coletar
                    </button>
                </div>
            </td>
        </tr>
    );
}
