'use client';

interface FonteOption {
  id: number;
  nome: string;
}

interface FilterBarProps {
  fontes: FonteOption[];
  regioes: string[];
  selectedFontes: number[];
  selectedRegiao: string;
  selectedPeriodo: string;
  onFontesChange: (ids: number[]) => void;
  onRegiaoChange: (regiao: string) => void;
  onPeriodoChange: (periodo: string) => void;
}

const periodos = [
  { value: '', label: 'Todos os períodos' },
  { value: '6h', label: 'Últimas 6h' },
  { value: '12h', label: 'Últimas 12h' },
  { value: '24h', label: 'Últimas 24h' },
  { value: '48h', label: 'Últimas 48h' },
  { value: '7d', label: 'Últimos 7 dias' },
];

export default function FilterBar({
  fontes,
  regioes,
  selectedFontes,
  selectedRegiao,
  selectedPeriodo,
  onFontesChange,
  onRegiaoChange,
  onPeriodoChange,
}: FilterBarProps) {
  function handleFonteToggle(id: number) {
    if (selectedFontes.includes(id)) {
      onFontesChange(selectedFontes.filter(f => f !== id));
    } else {
      onFontesChange([...selectedFontes, id]);
    }
  }

  return (
    <div className="flex flex-wrap gap-3">
      {/* Region filter */}
      <select
        value={selectedRegiao}
        onChange={(e) => onRegiaoChange(e.target.value)}
        className="px-3 py-2.5 rounded-xl bg-[#16213e] border border-[#2a2a4e] text-white text-sm focus:outline-none focus:border-[#0061FF] cursor-pointer"
      >
        <option value="">Todas as regiões</option>
        {regioes.map(r => (
          <option key={r} value={r}>{r}</option>
        ))}
      </select>

      {/* Period filter */}
      <select
        value={selectedPeriodo}
        onChange={(e) => onPeriodoChange(e.target.value)}
        className="px-3 py-2.5 rounded-xl bg-[#16213e] border border-[#2a2a4e] text-white text-sm focus:outline-none focus:border-[#0061FF] cursor-pointer"
      >
        {periodos.map(p => (
          <option key={p.value} value={p.value}>{p.label}</option>
        ))}
      </select>

      {/* Source filter (dropdown with checkboxes) */}
      <div className="relative group">
        <button className="px-3 py-2.5 rounded-xl bg-[#16213e] border border-[#2a2a4e] text-white text-sm flex items-center gap-2 hover:border-[#0061FF] transition">
          <span>
            Veículos{selectedFontes.length > 0 && ` (${selectedFontes.length})`}
          </span>
          <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" />
          </svg>
        </button>
        <div className="absolute top-full left-0 mt-1 w-64 max-h-72 overflow-y-auto bg-[#16213e] border border-[#2a2a4e] rounded-xl shadow-2xl z-50 hidden group-hover:block">
          {selectedFontes.length > 0 && (
            <button
              onClick={() => onFontesChange([])}
              className="w-full px-3 py-2 text-left text-xs text-[#FF9F00] hover:bg-[#1a1a2e] border-b border-[#2a2a4e]"
            >
              Limpar seleção
            </button>
          )}
          {fontes.map(f => (
            <label
              key={f.id}
              className="flex items-center gap-2 px-3 py-2 hover:bg-[#1a1a2e] cursor-pointer text-sm text-white"
            >
              <input
                type="checkbox"
                checked={selectedFontes.includes(f.id)}
                onChange={() => handleFonteToggle(f.id)}
                className="rounded border-[#2a2a4e] text-[#0061FF] focus:ring-[#0061FF] bg-[#1a1a2e]"
              />
              {f.nome}
            </label>
          ))}
        </div>
      </div>
    </div>
  );
}
