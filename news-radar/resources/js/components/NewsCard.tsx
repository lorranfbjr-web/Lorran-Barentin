import React from 'react';
import type { NewsItem } from '../api';

interface Props {
    item: NewsItem;
}

const urgencyColors: Record<string, string> = {
    alta: 'bg-red-500/20 text-red-400',
    media: 'bg-yellow-500/20 text-yellow-400',
    baixa: 'bg-green-500/20 text-green-400',
};

const sourceColors = [
    'bg-[#0061FF]', 'bg-[#18ADFE]', 'bg-[#FF9F00]', 'bg-emerald-500',
    'bg-purple-500', 'bg-pink-500', 'bg-teal-500', 'bg-indigo-500',
];

function getSourceColor(name: string): string {
    let hash = 0;
    for (let i = 0; i < name.length; i++) hash = name.charCodeAt(i) + ((hash << 5) - hash);
    return sourceColors[Math.abs(hash) % sourceColors.length];
}

function timeAgo(date: string | null): string {
    if (!date) return '';
    const diff = Math.floor((Date.now() - new Date(date).getTime()) / 60000);
    if (diff < 1) return 'agora';
    if (diff < 60) return `há ${diff}min`;
    if (diff < 1440) return `há ${Math.floor(diff / 60)}h`;
    return new Date(date).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' });
}

export default function NewsCard({ item }: Props) {
    const sourceName = item.source?.name ?? 'Desconhecida';
    const urgency = item.ai_metadata?.urgency;

    return (
        <a
            href={item.url}
            target="_blank"
            rel="noopener noreferrer"
            className="group block bg-[#16213e] rounded-xl overflow-hidden hover:ring-2 hover:ring-[#0061FF]/50 transition-all"
        >
            {/* Thumbnail */}
            <div className="aspect-video bg-[#0f1629] relative overflow-hidden">
                {item.hero_image_url ? (
                    <img
                        src={item.hero_image_url}
                        alt=""
                        className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
                        loading="lazy"
                    />
                ) : (
                    <div className={`w-full h-full flex items-center justify-center ${getSourceColor(sourceName)}/20`}>
                        <span className="text-3xl font-black text-white/20">
                            {sourceName.slice(0, 2).toUpperCase()}
                        </span>
                    </div>
                )}
                {urgency && (
                    <span className={`absolute top-2 right-2 px-2 py-0.5 rounded text-xs font-bold ${urgencyColors[urgency] ?? ''}`}>
                        {urgency.toUpperCase()}
                    </span>
                )}
            </div>

            {/* Content */}
            <div className="p-4">
                <div className="flex items-center gap-2 mb-2">
                    <span className={`px-2 py-0.5 rounded text-xs font-bold text-white ${getSourceColor(sourceName)}`}>
                        {sourceName}
                    </span>
                    <span className="text-xs text-white/40">
                        {timeAgo(item.published_at_utc ?? item.created_at)}
                    </span>
                </div>
                <h3 className="text-sm font-bold leading-tight line-clamp-2 text-[#D9F8FF] group-hover:text-white transition-colors">
                    {item.title}
                </h3>
                {item.subtitle && (
                    <p className="text-xs text-white/40 mt-1 line-clamp-1">{item.subtitle}</p>
                )}
            </div>
        </a>
    );
}
