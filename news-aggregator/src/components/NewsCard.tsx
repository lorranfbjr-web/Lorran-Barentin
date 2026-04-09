'use client';

import { useState } from 'react';

interface NewsCardProps {
  titulo: string;
  url: string;
  imagem_url: string | null;
  fonte_nome: string;
  fonte_regiao: string;
  data_publicacao: string | null;
  data_coleta: string;
}

function formatRelativeTime(dateStr: string | null, fallback: string): string {
  const date = new Date(dateStr || fallback);
  const now = new Date();
  const diffMs = now.getTime() - date.getTime();
  const diffMin = Math.floor(diffMs / 60000);
  const diffHours = Math.floor(diffMs / 3600000);
  const diffDays = Math.floor(diffMs / 86400000);

  if (diffMin < 1) return 'agora';
  if (diffMin < 60) return `há ${diffMin} min`;
  if (diffHours < 24) return `há ${diffHours}h`;
  if (diffDays < 7) return `há ${diffDays}d`;

  return date.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' });
}

// Color mapping for source badges
const sourceColors: Record<string, string> = {
  'Estadual': 'bg-[#0061FF]',
  'Grande Florianópolis': 'bg-[#18ADFE]',
  'Norte': 'bg-emerald-600',
  'Vale do Itajaí': 'bg-purple-600',
  'Litoral Norte': 'bg-[#FF9F00]',
  'Sul': 'bg-rose-600',
  'Oeste': 'bg-teal-600',
  'Serra': 'bg-amber-700',
};

export default function NewsCard({
  titulo,
  url,
  imagem_url,
  fonte_nome,
  fonte_regiao,
  data_publicacao,
  data_coleta,
}: NewsCardProps) {
  const [imgError, setImgError] = useState(false);
  const badgeColor = sourceColors[fonte_regiao] || 'bg-[#0061FF]';
  const initials = fonte_nome.slice(0, 2).toUpperCase();

  return (
    <a
      href={url}
      target="_blank"
      rel="noopener noreferrer"
      className="group block bg-[#16213e] rounded-2xl overflow-hidden shadow-lg hover:shadow-2xl hover:ring-1 hover:ring-[#0061FF]/50 transition-all duration-200 hover:-translate-y-0.5"
    >
      {/* Thumbnail */}
      <div className="relative aspect-video bg-[#1a1a2e] overflow-hidden">
        {imagem_url && !imgError ? (
          <img
            src={imagem_url}
            alt=""
            loading="lazy"
            className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
            onError={() => setImgError(true)}
          />
        ) : (
          <div className="w-full h-full flex items-center justify-center bg-gradient-to-br from-[#0061FF]/20 to-[#18ADFE]/20">
            <span className="text-3xl font-black text-[#0061FF]/40">{initials}</span>
          </div>
        )}
        {/* Time badge */}
        <span className="absolute top-2 right-2 px-2 py-0.5 rounded-md bg-black/60 text-white text-xs font-bold">
          {formatRelativeTime(data_publicacao, data_coleta)}
        </span>
      </div>

      {/* Content */}
      <div className="p-3">
        <h3 className="text-white text-sm font-bold leading-snug line-clamp-2 group-hover:text-[#18ADFE] transition-colors">
          {titulo}
        </h3>
        <div className="mt-2 flex items-center gap-2">
          <span className={`inline-block px-2 py-0.5 rounded-md text-white text-[10px] font-bold uppercase tracking-wider ${badgeColor}`}>
            {fonte_nome}
          </span>
        </div>
      </div>
    </a>
  );
}
