import React from 'react';
import { useQuery } from '@tanstack/react-query';
import { fetchSources, fetchRegions, fetchThemes } from '../api';

interface Filters {
    q: string;
    source_id: string;
    region: string;
    theme_id: string;
    period: string;
    urgency: string;
}

interface Props {
    filters: Filters;
    onChange: (f: Filters) => void;
}

export default function FilterBar({ filters, onChange }: Props) {
    const { data: sourcesData } = useQuery({ queryKey: ['sources-list'], queryFn: () => fetchSources() });
    const { data: regionsData } = useQuery({ queryKey: ['regions'], queryFn: fetchRegions });
    const { data: themesData } = useQuery({ queryKey: ['themes'], queryFn: fetchThemes });

    const set = (key: keyof Filters, val: string) => onChange({ ...filters, [key]: val });

    const selectClass = 'px-3 py-2.5 bg-[#16213e] border border-white/10 rounded-lg text-white text-sm focus:outline-none focus:border-[#0061FF] transition-colors appearance-none cursor-pointer';

    return (
        <div className="flex flex-wrap gap-2">
            <select value={filters.source_id} onChange={(e) => set('source_id', e.target.value)} className={selectClass}>
                <option value="">Todas as fontes</option>
                {sourcesData?.data.map((s) => (
                    <option key={s.id} value={s.id}>{s.name}</option>
                ))}
            </select>

            <select value={filters.region} onChange={(e) => set('region', e.target.value)} className={selectClass}>
                <option value="">Todas as regiões</option>
                {regionsData?.data.map((r) => (
                    <option key={r} value={r}>{r}</option>
                ))}
            </select>

            <select value={filters.theme_id} onChange={(e) => set('theme_id', e.target.value)} className={selectClass}>
                <option value="">Todos os temas</option>
                {themesData?.data.map((t) => (
                    <option key={t.id} value={t.id}>{t.label} ({t.items_count})</option>
                ))}
            </select>

            <select value={filters.period} onChange={(e) => set('period', e.target.value)} className={selectClass}>
                <option value="6h">Últimas 6h</option>
                <option value="12h">Últimas 12h</option>
                <option value="24h">Últimas 24h</option>
                <option value="48h">Últimas 48h</option>
                <option value="7d">Últimos 7 dias</option>
                <option value="">Todos</option>
            </select>

            <select value={filters.urgency} onChange={(e) => set('urgency', e.target.value)} className={selectClass}>
                <option value="">Todas urgências</option>
                <option value="alta">Alta</option>
                <option value="media">Média</option>
                <option value="baixa">Baixa</option>
            </select>
        </div>
    );
}
