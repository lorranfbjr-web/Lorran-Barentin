import React, { useEffect, useRef, useState } from 'react';
import { useInfiniteQuery, useQuery } from '@tanstack/react-query';
import { fetchRadar, votarFeedback, RadarItem } from '../api';
import { timeAgo } from './NewsCard';

/**
 * Aba Radar v3 — EVENTO como unidade (1 card por cluster, badge "N portais",
 * expande pras coberturas). Desktop = dashboard de editor: faixa EM ALTA no
 * topo, grid 2-3 colunas dos quentes, radar geral compacto colapsável e Fila
 * humana ao final. Mobile segue 1 coluna.
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

function quandoEvento(e: RadarItem): string {
    const d = e.ultima_cobertura ?? e.publicado_em;
    return d ? timeAgo(d.replace(' ', 'T') + 'Z') : `coleta ${timeAgo(e.created_at.replace(' ', 'T') + 'Z')}`;
}

const FAIXAS = [
    ['baixa', '❄️', '10-30'],
    ['media', '😐', '30-60'],
    ['alta', '🔥', '60-100'],
] as const;

function VotoBotoes({ evento }: { evento: RadarItem }) {
    const [voto, setVoto] = useState<string | null>(evento.faixa_voto ?? null);

    const votar = (faixa: 'baixa' | 'media' | 'alta') => {
        const anterior = voto;
        setVoto(faixa);
        votarFeedback(evento.lider_id, faixa).catch(() => setVoto(anterior));
    };

    return (
        <div className="mt-2 flex gap-1.5">
            {FAIXAS.map(([faixa, emoji, rotulo]) => (
                <button
                    key={faixa}
                    onClick={(e) => { e.preventDefault(); e.stopPropagation(); votar(faixa); }}
                    className={`flex-1 py-1.5 rounded-md text-[11px] font-bold transition-colors ${
                        voto === faixa
                            ? 'bg-[#0061FF] text-white ring-2 ring-[#18ADFE]/60'
                            : 'bg-white/5 text-white/50 hover:bg-white/10 active:bg-white/15'
                    }`}
                    title={`nota humana ${rotulo}`}
                >
                    {emoji} {rotulo}
                </button>
            ))}
        </div>
    );
}

function Coberturas({ evento }: { evento: RadarItem }) {
    const [aberto, setAberto] = useState(false);
    if (evento.n_portais <= 1) return null;

    return (
        <div className="mt-1.5">
            <button
                onClick={(e) => { e.preventDefault(); e.stopPropagation(); setAberto((v) => !v); }}
                className="px-1.5 py-0.5 rounded bg-white/10 text-[11px] text-white/70 hover:bg-white/20"
            >
                {evento.n_portais} portais {aberto ? '▾' : '▸'}
            </button>
            {aberto && (
                <ul className="mt-1.5 space-y-1">
                    {evento.fontes.map((f) => (
                        <li key={f.url}>
                            <a href={f.url} target="_blank" rel="noopener noreferrer"
                                onClick={(e) => e.stopPropagation()}
                                className="text-[11px] text-[#18ADFE] hover:underline">
                                {f.nome} ↗
                            </a>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function EventoCard({ evento, compacto = false }: { evento: RadarItem; compacto?: boolean }) {
    const primaria = evento.eixo === 'primaria';

    if (compacto) {
        return (
            <a href={evento.url} target="_blank" rel="noopener noreferrer"
                className="flex items-center gap-2 px-2 py-1.5 rounded bg-[#16213e]/60 hover:bg-[#16213e] text-xs">
                <span className={`px-1.5 py-0.5 rounded-full text-[10px] font-black shrink-0 ${scoreColor(evento.score_evento)}`}>
                    {evento.score_evento ?? '–'}
                </span>
                <span className="truncate text-white/75">{evento.titulo}</span>
                {evento.n_portais > 1 && <span className="text-[10px] text-white/40 shrink-0">{evento.n_portais}p</span>}
                <span className="ml-auto text-[10px] text-white/35 shrink-0">{quandoEvento(evento)}</span>
            </a>
        );
    }

    return (
        <a href={evento.url} target="_blank" rel="noopener noreferrer"
            className="block bg-[#16213e] rounded-lg p-3 hover:ring-2 hover:ring-[#0061FF]/50 transition-all h-fit">
            <div className="flex items-center gap-2 flex-wrap">
                <span className={`px-1.5 py-0.5 rounded text-[10px] font-black tracking-wide ${
                    primaria ? 'bg-emerald-600 text-white' : 'bg-[#FF9F00] text-black'
                }`}>
                    {primaria ? 'PRIMÁRIA' : 'RADAR'}
                </span>
                <span className={`px-2 py-0.5 rounded-full text-sm font-black ${scoreColor(evento.score_evento)}`}>
                    {evento.score_evento ?? '–'}
                </span>
                {evento.cidade_llm && <span className="text-[11px] text-[#18ADFE] font-semibold">{evento.cidade_llm}</span>}
                {evento.tipo_gancho && evento.tipo_gancho !== 'nenhum' && (
                    <span className="text-[11px] text-white/50">{GANCHO_LABEL[evento.tipo_gancho] ?? evento.tipo_gancho}</span>
                )}
                <span className="ml-auto text-[11px] text-white/40">{quandoEvento(evento)}</span>
            </div>

            <h3 className="mt-2 text-sm font-bold leading-snug text-[#D9F8FF]">{evento.titulo}</h3>
            {evento.juiz_motivo && <p className="mt-1 text-xs text-white/55 leading-snug">{evento.juiz_motivo}</p>}

            <div className="mt-2 flex items-center gap-2 text-[11px] text-white/40 flex-wrap">
                <span className="text-white/60">{evento.fontes[0]?.nome ?? '?'}</span>
                {evento.notificado_em && <span title="já avisado no Raspador">🔔</span>}
            </div>
            <Coberturas evento={evento} />
            <VotoBotoes evento={evento} />
        </a>
    );
}

function EmAlta({ janela }: { janela: string }) {
    const { data } = useQuery({
        queryKey: ['radar-alta', janela],
        queryFn: () => fetchRadar({ secao: 'alta', janela }),
        retry: false,
        refetchInterval: 5 * 60 * 1000,
    });
    const eventos = (data?.data ?? []) as RadarItem[];
    if (!eventos.length) return null;

    return (
        <section className="mb-5">
            <h2 className="text-sm font-black text-[#FF9F00] mb-2">🔥 EM ALTA — todo mundo cobrindo</h2>
            <div className="flex gap-2 overflow-x-auto pb-2 lg:grid lg:grid-cols-3 xl:grid-cols-4 lg:overflow-visible">
                {eventos.slice(0, 8).map((e) => (
                    <a key={String(e.evento_id)} href={e.url} target="_blank" rel="noopener noreferrer"
                        className="shrink-0 w-64 lg:w-auto bg-gradient-to-br from-[#16213e] to-[#1d2b50] border border-[#FF9F00]/30 rounded-lg p-2.5 hover:border-[#FF9F00]/70">
                        <div className="flex items-center gap-2">
                            <span className="px-1.5 py-0.5 rounded bg-[#FF9F00] text-black text-[10px] font-black">{e.n_portais} portais</span>
                            <span className="text-[10px] text-white/40">{quandoEvento(e)}</span>
                        </div>
                        <p className="mt-1.5 text-xs font-bold text-[#D9F8FF] leading-snug line-clamp-3">{e.titulo}</p>
                    </a>
                ))}
            </div>
        </section>
    );
}

function ListaRadar({ filtros, compacto = false, grid = false }: { filtros: Record<string, string>; compacto?: boolean; grid?: boolean }) {
    const loaderRef = useRef<HTMLDivElement | null>(null);
    const { data, fetchNextPage, hasNextPage, isFetchingNextPage, isLoading, isError, error } = useInfiniteQuery({
        queryKey: ['radar', filtros, compacto],
        queryFn: ({ pageParam = 1 }) => fetchRadar({ ...filtros, page: String(pageParam), limit: compacto ? '30' : '20' }),
        initialPageParam: 1,
        getNextPageParam: (last: any) => {
            const m = last?.meta;
            if (!m?.last_page) return undefined;
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

    const eventos = data?.pages.flatMap((p: any) => p.data) ?? [];
    if (!eventos.length) return <p className="text-white/40 text-sm py-8 text-center">Nada por aqui com esses filtros.</p>;

    return (
        <div className={grid ? 'grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-2 items-start' : 'space-y-2'}>
            {eventos.map((e: RadarItem) => <EventoCard key={String(e.evento_id)} evento={e} compacto={compacto} />)}
            <div ref={loaderRef} className="h-8" />
            {isFetchingNextPage && <p className="text-white/30 text-xs text-center col-span-full">carregando…</p>}
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
    const [mostraGeral, setMostraGeral] = useState(false);
    const [mostraFila, setMostraFila] = useState(false);

    const filtros = { eixo, score_min: scoreMin, cidade, q: busca, janela, sort, secao: 'quentes' };
    const sel = 'bg-[#16213e] border border-white/10 rounded-lg px-2 py-2 text-sm text-white/80 focus:outline-none focus:border-[#0061FF]';

    return (
        <div className="max-w-3xl lg:max-w-none mx-auto">
            <EmAlta janela={janela} />

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

            <ListaRadar filtros={filtros} grid />

            <div className="mt-8">
                <button onClick={() => setMostraGeral((v) => !v)}
                    className="w-full text-left px-3 py-2 bg-[#16213e] rounded-lg text-sm font-bold text-white/80">
                    📋 Radar geral — tudo da janela, compacto {mostraGeral ? '▾' : '▸'}
                </button>
                {mostraGeral && (
                    <div className="mt-2">
                        <ListaRadar filtros={{ janela, sort: 'recente', secao: 'geral' }} compacto />
                    </div>
                )}
            </div>

            <div className="mt-4">
                <button onClick={() => setMostraFila((v) => !v)}
                    className="w-full text-left px-3 py-2 bg-[#16213e] rounded-lg text-sm font-bold text-white/80">
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
