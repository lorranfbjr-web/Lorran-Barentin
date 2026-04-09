import React, { useRef, useEffect } from 'react';

interface Props {
    value: string;
    onChange: (val: string) => void;
}

export default function SearchBar({ value, onChange }: Props) {
    const timer = useRef<ReturnType<typeof setTimeout>>(undefined);

    const handleInput = (e: React.ChangeEvent<HTMLInputElement>) => {
        const val = e.target.value;
        clearTimeout(timer.current);
        timer.current = setTimeout(() => onChange(val), 300);
    };

    useEffect(() => () => clearTimeout(timer.current), []);

    return (
        <div className="flex-1 min-w-[200px]">
            <input
                type="text"
                placeholder="Buscar notícias..."
                defaultValue={value}
                onChange={handleInput}
                className="w-full px-4 py-2.5 bg-[#16213e] border border-white/10 rounded-lg text-white placeholder-white/30 focus:outline-none focus:border-[#0061FF] transition-colors"
            />
        </div>
    );
}
