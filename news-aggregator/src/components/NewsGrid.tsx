'use client';

import NewsCard from './NewsCard';

interface NoticiaData {
  id: number;
  titulo: string;
  url: string;
  imagem_url: string | null;
  fonte_nome: string;
  fonte_regiao: string;
  data_publicacao: string | null;
  data_coleta: string;
}

interface NewsGridProps {
  noticias: NoticiaData[];
  loading: boolean;
}

export default function NewsGrid({ noticias, loading }: NewsGridProps) {
  if (loading && noticias.length === 0) {
    return (
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
        {Array.from({ length: 12 }).map((_, i) => (
          <div key={i} className="bg-[#16213e] rounded-2xl overflow-hidden animate-pulse">
            <div className="aspect-video bg-[#1a1a2e]" />
            <div className="p-3 space-y-2">
              <div className="h-4 bg-[#1a1a2e] rounded w-full" />
              <div className="h-4 bg-[#1a1a2e] rounded w-3/4" />
              <div className="h-5 bg-[#1a1a2e] rounded w-24" />
            </div>
          </div>
        ))}
      </div>
    );
  }

  if (noticias.length === 0) {
    return (
      <div className="text-center py-16">
        <svg className="mx-auto w-16 h-16 text-[#2a2a4e] mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
          <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m5.231 13.481L15 17.25m-4.5-15H5.625c-.621 0-1.125.504-1.125 1.125v16.5c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9zm3.75 11.625a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
        </svg>
        <p className="text-[#8892b0] text-lg font-bold">Nenhuma notícia encontrada</p>
        <p className="text-[#5a5a8e] text-sm mt-1">Tente ajustar os filtros ou clique em Atualizar</p>
      </div>
    );
  }

  return (
    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
      {noticias.map((noticia) => (
        <NewsCard
          key={noticia.id}
          titulo={noticia.titulo}
          url={noticia.url}
          imagem_url={noticia.imagem_url}
          fonte_nome={noticia.fonte_nome}
          fonte_regiao={noticia.fonte_regiao}
          data_publicacao={noticia.data_publicacao}
          data_coleta={noticia.data_coleta}
        />
      ))}
    </div>
  );
}
