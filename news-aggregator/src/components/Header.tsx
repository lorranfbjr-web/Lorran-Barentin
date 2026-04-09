'use client';

interface HeaderProps {
  lastUpdate: string | null;
  onRefresh: () => void;
  loading: boolean;
}

export default function Header({ lastUpdate, onRefresh, loading }: HeaderProps) {
  return (
    <header className="bg-[#16213e] border-b border-[#2a2a4e] sticky top-0 z-50">
      <div className="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between gap-4">
        <div className="flex items-center gap-3">
          <div className="flex items-center justify-center w-10 h-10 rounded-xl bg-gradient-to-br from-[#0061FF] to-[#18ADFE] shrink-0">
            <span className="text-white font-black text-sm">JR</span>
          </div>
          <div>
            <h1 className="text-white font-black text-lg tracking-wide leading-tight">
              RADAR EDITORIAL
            </h1>
            <p className="text-[#8892b0] text-xs hidden sm:block">
              Monitoramento de Notícias SC
            </p>
          </div>
        </div>

        <div className="flex items-center gap-3">
          {lastUpdate && (
            <span className="text-[#8892b0] text-xs hidden md:block">
              Atualizado: {lastUpdate}
            </span>
          )}
          <button
            onClick={onRefresh}
            disabled={loading}
            className="flex items-center gap-2 px-4 py-2 rounded-xl bg-[#0061FF] hover:bg-[#0050d4] text-white text-sm font-bold transition disabled:opacity-50"
          >
            <svg
              className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`}
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              strokeWidth={2}
            >
              <path strokeLinecap="round" strokeLinejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
            <span className="hidden sm:inline">Atualizar</span>
          </button>
        </div>
      </div>
    </header>
  );
}
