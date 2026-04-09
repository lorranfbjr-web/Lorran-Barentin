'use client';

interface LoadMoreProps {
  hasMore: boolean;
  loading: boolean;
  onClick: () => void;
  total: number;
  showing: number;
}

export default function LoadMore({ hasMore, loading, onClick, total, showing }: LoadMoreProps) {
  if (!hasMore) return null;

  return (
    <div className="text-center mt-8">
      <p className="text-[#5a5a8e] text-xs mb-3">
        Exibindo {showing} de {total} notícias
      </p>
      <button
        onClick={onClick}
        disabled={loading}
        className="px-6 py-3 rounded-xl bg-[#16213e] border border-[#2a2a4e] text-white text-sm font-bold hover:border-[#0061FF] hover:bg-[#1a1a3e] transition disabled:opacity-50"
      >
        {loading ? 'Carregando...' : 'Carregar mais notícias'}
      </button>
    </div>
  );
}
