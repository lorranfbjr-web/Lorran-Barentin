import { getDb } from '@/lib/db';

export async function GET() {
  const db = getDb();
  const fontes = db.prepare(`
    SELECT id, nome, url_base, regiao, prioridade, ativo, ultima_coleta, falhas_consecutivas
    FROM fontes
    ORDER BY nome
  `).all();

  const regioes = db.prepare(`
    SELECT DISTINCT regiao FROM fontes ORDER BY regiao
  `).all() as { regiao: string }[];

  return Response.json({
    fontes,
    regioes: regioes.map(r => r.regiao),
  });
}
