import React, { useState } from 'react';
import type { NewsItem } from '../api';

interface Props { item: NewsItem; }

const sourceColors = [
    'bg-[#0061FF]', 'bg-[#18ADFE]', 'bg-[#FF9F00]', 'bg-emerald-500',
    'bg-purple-500', 'bg-pink-500', 'bg-teal-500', 'bg-indigo-500',
];

function getSourceColor(name: string): string {
    let hash = 0;
    for (let i = 0; i < name.length; i++) hash = name.charCodeAt(i) + ((hash << 5) - hash);
    return sourceColors[Math.abs(hash) % sourceColors.length];
}

export function timeAgo(date: string | null): string {
    if (!date) return '';
    const diff = Math.floor((Date.now() - new Date(date).getTime()) / 60000);
    if (diff < 1) return 'agora';
    if (diff < 60) return `${diff}m`;
    if (diff < 1440) return `${Math.floor(diff / 60)}h`;
    return new Date(date).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' });
}

function decodeHtml(s: string): string {
    return s.replace(/&#(\d+);/g, (_, n) => String.fromCharCode(parseInt(n, 10)))
            .replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>')
            .replace(/&quot;/g, '"').replace(/&#39;/g, "'");
}

export default function NewsCard({ item }: Props) {
    const [imgOk, setImgOk] = useState(true);
    const sourceName = item.source?.name ?? '?';
    const title = decodeHtml(item.title ?? '');

    return (
        <a
            href={item.url}
            target="_blank"
            rel="noopener noreferrer"
            className="group block bg-[#16213e] rounded-lg overflow-hidden hover:ring-2 hover:ring-[#0061FF]/50 transition-all"
        >
            <div className="aspect-square bg-[#0f1629] relative overflow-hidden">
                {item.hero_image_url && imgOk ? (
                    <img
                        src={item.hero_image_url}
                        alt=""
                        className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
                        loading="lazy"
                        referrerPolicy="no-referrer"
                        onError={() => setImgOk(false)}
                    />
                ) : (
                    <div className={`w-full h-full flex items-center justify-center ${getSourceColor(sourceName)}/30`}>
                        <span className="text-2xl font-black text-white/40">
                            {sourceName.slice(0, 2).toUpperCase()}
                        </span>
                    </div>
                )}
                <span className={`absolute top-1 left-1 px-1.5 py-0.5 rounded text-[10px] font-bold text-white ${getSourceColor(sourceName)}`}>
                    {sourceName.length > 10 ? sourceName.slice(0, 10) + '…' : sourceName}
                </span>
                <span className="absolute top-1 right-1 px-1.5 py-0.5 rounded text-[10px] font-bold text-white bg-black/60">
                    {/* horário de PUBLICAÇÃO; sem ele, mostra a coleta com marcação
                        (catch-up de fonte nova não pode se vestir de "agora") */}
                    {item.published_at_utc ? timeAgo(item.published_at_utc) : `coleta ${timeAgo(item.created_at)}`}
                </span>
            </div>
            <div className="p-2">
                <h3 className="text-xs font-bold leading-tight line-clamp-3 text-[#D9F8FF] group-hover:text-white transition-colors">
                    {title}
                </h3>
            </div>
        </a>
    );
}
