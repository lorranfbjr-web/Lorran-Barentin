import React, { useEffect, useRef, useState } from 'react';
import { useInfiniteQuery, useQuery } from '@tanstack/react-query';
import { fetchRadar, RadarItem } from '../api';
import { timeAgo } from './NewsCard';

/**
 * Aba Radar — pautas do juiz LLM (jr_link_extracao). Mobile-first: lista de
 * cards de texto, filtros enxutos em cima, "Fila humana" em seção própria.
 */

const GANCHO_LABEL: Record<string, string> = {
    conquista_superacao: 'conquista',
    identidade_sc: 'identidade SC',
    feel_good: 'feel-good',
    indignacao: 'indignação',
    curiosidade: 'curiosidade',
    emocao: 'emoção',
    escala: 'escala',
    servico: 'serviço',
    solidariedade: 'solidariedade',
    vaquinha: 'vaquinha',
};

function scoreColor(s: number | null): string {
    if (s === null) return 'bg-white/10 text-white/60';
    if (s >= 80) return 'bg-emerald-500 text-white';
    if (s >= 60) return 'bg-[#0061FF] text-white';
    if (s >= 40) return 'bg-amber-500 text-black';
    return 'bg-white/15 text-white/70';
}

function RadarCard({ item }: { item: RadarItem }) {
    const primaria = item.eixo === 'primaria';
    const quando = item.publicado_em ?? null;

    return (
        <a
            href={item.url}
            target="_blank"
            rel="noopener noreferrer"
            className="block bg-[#16213e] rounded-lg p-3 hover:ring-2 hover:ring-[#0061FF]/50 transition-all"
        >
            <div className="flex items-center gap-2 flex-wrap">
                <span className={`px-1.5 py-0.5 rounded text-[10px] font-black tracking-wide ${
                    primaria ? 'bg-emerald-600 text-white' : 'bg-[#FF9F00] text-black'
                }`}>
                    {primaria ? 'PRIMÁRIA' : 'RADAR'}
                </span>
                <span className={`px-2 py-0.5 rounded-full text-sm font-black ${scoreColor(item.score_editorial)}`}>
                    {item.score_editorial ?? '–'}
                </span>
                {item.cidade_llm && (
                    <span className="text-[11px] text-[#18ADFE] font-semibold">{item.cidade_llm}</span>
                )}
                {item.tipo_gancho && item.tipo_gancho !== 'nenhum' && (
                    <span className="text-[11px] text-white/50">{GANCHO_LABEL[item.tipo_gancho] ?? item.tipo_gancho}</span>
                )}
                <span className="ml-auto text-[11px] text-white/40">
                    {quando ? timeAgo(quando.replace(' ', 'T') + 'Z') : `coleta ${timeAgo(item.created_at.replace(' ', 'T') + 'Z')}`}
                </span>
            </div>

            <h3 className="mt-2 text-sm font-bold leading-snug text-[#D9F8FF]">{item.titulo}</h3>

            {item.juiz_motivo && (
                <p className="mt-1 text-xs text-white/55 leading-snug">{item.juiz_motivo}</p>
            )}

            <div className="mt-2 flex items-center gap-2 text-[11px] text-white/40 flex-wrap">
                <span className="text-white/60">{item.fonte_tipo ?? item.host ?? '?'}</span>
                {item.cluster_n > 1 && (
                    <span className="px-1.5 py-0.5 rounded bg-white/10">1 de {item.cluster_n} portais</span>
                )}
                {item.notificado_em && <span title="já avisado no Raspador">🔔</span>}
            </div>
        </a>
    );
}

function ListaRadar({ filtros }: { filtros: Record<string, string> }) {
    const loaderRef = useRef<HTMLDivElement | null>(null);
    const { data, fetchNextPage, hasNextPage, isFetchingNextPage, isLoading, isError, error } = useInfiniteQuery({
        queryKey: ['radar', filtros],
        queryFn: ({ pageParam = 1 }) => fetchRadar({ ...filtros, page: String(pageParam), limit: '20' }),
        initialPageParam: 1,
        getNextPageParam: (last: any) => {
            const m = last?.meta;
            if (!m) return undefined;
            return m.current_page < m.last_page ? m.current_page + 1 : undefined;
        },
        retry: false,
    });

    useEffect(() => {
        if (!loaderRef.current) return;
        const obs = new IntersectionObserver((entries) => {
            if (entries[0].isIntersecting && hasNextPage && !isFetchingNextPage) fetchNextPage();
        });
        obs.observe(loaderRef.current);
        return () => obs.disconnect();
    }, [hasNextPage, isFetchingNextPage, fetchNextPage]);

    if (isLoading) return <p className="text-white/40 text-sm py-8 text-center">Carregando radar…</p>;
    if (isError)

        return (
            <div className="text-center py-10 space-y-2">
                <p className="text-3xl">🔒</p>
                <p className="text-white/70 text-sm font-semibold">
                    {(error as Error)?.message === '403'
                        ? 'Aba Radar é trancada: abra o painel com ?key=SUA_CHAVE (uma vez a cada 90 dias).'
                        : 'Erro ao carregar o radar.'}
                </p>
            </div>
        );

    const itens = data?.pages.flatMap((p: any) => p.data) ?? [];
    if (!itens.length) return <p className="text-white/40 text-sm py-8 text-center">Nada por aqui com esses filtros.</p>;

    return (
        <div className="space-y-2">
            {itens.map((it: RadarItem) => <RadarCard key={it.id} item={it} />)}
            <div ref={loaderRef} className="h-8" />
            {isFetchingNextPage && <p className="text-white/30 text-xs text-center">carregando…</p>}
        </div>
    );
}

export default function RadarPanel() {
    const [eixo, setEixo] = useState('');
    const [scoreMin, setScoreMin] = useState('');
    const [cidade, setCidade] = useState('');
    const [busca, setBusca] = useState('');
    const [janela, setJanela] = useState('48');
    const [sort, setSort] = useState('score');
    const [mostraFila, setMostraFila] = useState(false);

    const filtros = { eixo, score_min: scoreMin, cidade, q: busca, janela, sort, secao: 'quentes' };

    const sel = 'bg-[#16213e] border border-white/10 rounded-lg px-2 py-2 text-sm text-white/80 focus:outline-none focus:border-[#0061FF]';

    return (
        <div className="max-w-3xl mx-auto">
            <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-2 mb-4">
                <input className={`${sel} col-span-2`} placeholder="Buscar título/motivo…"
                    value={busca} onChange={(e) => setBusca(e.target.value)} />
                <select className={sel} value={eixo} onChange={(e) => setEixo(e.target.value)}>
                    <option value="">Eixo: todos</option>
                    <option value="primaria">Primária</option>
                    <option value="concorrente">Radar</option>
                </select>
                <select className={sel} value={scoreMin} onChange={(e) => setScoreMin(e.target.value)}>
                    <option value="">Score: todos</option>
                    <option value="60">≥ 60</option>
                    <option value="70">≥ 70</option>
                    <option value="80">≥ 80</option>
                    <option value="90">≥ 90</option>
                </select>
                <select className={sel} value={janela} onChange={(e) => setJanela(e.target.value)}>
                    <option value="24">24h</option>
                    <option value="48">48h</option>
                </select>
                <select className={sel} value={sort} onChange={(e) => setSort(e.target.value)}>
                    <option value="score">Por score</option>
                    <option value="recente">Mais recente</option>
                </select>
                <input className={`${sel} col-span-2 sm:col-span-1`} placeholder="Cidade…"
                    value={cidade} onChange={(e) => setCidade(e.target.value)} />
            </div>

            <ListaRadar filtros={filtros} />

            <div className="mt-8">
                <button
                    onClick={() => setMostraFila((v) => !v)}
                    className="w-full text-left px-3 py-2 bg-[#16213e] rounded-lg text-sm font-bold text-white/80"
                >
                    🙋 Fila humana — solidariedade/vaquinha {mostraFila ? '▾' : '▸'}
                </button>
                {mostraFila && (
                    <div className="mt-2">
                        <ListaRadar filtros={{ janela, sort, secao: 'fila' }} />
                    </div>
                )}
            </div>
        </div>
    );
}
