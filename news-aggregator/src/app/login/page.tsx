'use client';

import { signIn } from 'next-auth/react';
import { useState } from 'react';
import { useRouter } from 'next/navigation';

export default function LoginPage() {
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const router = useRouter();

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError('');
    setLoading(true);

    const result = await signIn('credentials', {
      password,
      redirect: false,
    });

    if (result?.error) {
      setError('Senha incorreta');
      setLoading(false);
    } else {
      router.push('/');
    }
  }

  return (
    <div className="min-h-screen flex items-center justify-center bg-[#1a1a2e] px-4">
      <div className="w-full max-w-sm">
        <div className="text-center mb-8">
          <div className="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-gradient-to-br from-[#0061FF] to-[#18ADFE] mb-4">
            <span className="text-white font-black text-2xl">JR</span>
          </div>
          <h1 className="text-2xl font-black text-white tracking-wide">
            RADAR EDITORIAL
          </h1>
          <p className="text-[#8892b0] text-sm mt-1">
            Painel de Monitoramento — Jornal Razão
          </p>
        </div>

        <form onSubmit={handleSubmit} className="bg-[#16213e] rounded-2xl p-6 shadow-xl">
          <label className="block text-sm font-bold text-[#D9F8FF] mb-2">
            Senha de acesso
          </label>
          <input
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            placeholder="Digite a senha da redação"
            className="w-full px-4 py-3 rounded-xl bg-[#1a1a2e] border border-[#2a2a4e] text-white placeholder-[#5a5a8e] focus:outline-none focus:border-[#0061FF] focus:ring-1 focus:ring-[#0061FF] transition"
            required
            autoFocus
          />

          {error && (
            <p className="text-red-400 text-sm mt-2">{error}</p>
          )}

          <button
            type="submit"
            disabled={loading}
            className="w-full mt-4 py-3 rounded-xl bg-gradient-to-r from-[#0061FF] to-[#18ADFE] text-white font-bold text-sm uppercase tracking-wider hover:opacity-90 disabled:opacity-50 transition"
          >
            {loading ? 'Entrando...' : 'Entrar'}
          </button>
        </form>
      </div>
    </div>
  );
}
