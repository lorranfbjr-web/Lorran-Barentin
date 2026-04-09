import { NextRequest } from 'next/server';
import { getDb } from '@/lib/db';

export async function GET(request: NextRequest) {
  const { searchParams } = request.nextUrl;
  const q = searchParams.get('q') || '';
  const fonteId = searchParams.get('fonte_id') || '';
  const regiao = searchParams.get('regiao') || '';
  const periodo = searchParams.get('periodo') || '';
  const page = parseInt(searchParams.get('page') || '1', 10);
  const limit = Math.min(parseInt(searchParams.get('limit') || '30', 10), 100);
  const offset = (page - 1) * limit;

  const db = getDb();

  const conditions: string[] = [];
  const params: (string | number)[] = [];

  if (q) {
    conditions.push('n.titulo LIKE ?');
    params.push(`%${q}%`);
  }

  if (fonteId) {
    const ids = fonteId.split(',').map(Number).filter(Boolean);
    if (ids.length > 0) {
      conditions.push(`n.fonte_id IN (${ids.map(() => '?').join(',')})`);
      params.push(...ids);
    }
  }

  if (regiao) {
    conditions.push('f.regiao = ?');
    params.push(regiao);
  }

  if (periodo) {
    const hoursMap: Record<string, number> = {
      '6h': 6, '12h': 12, '24h': 24, '48h': 48, '7d': 168,
    };
    const hours = hoursMap[periodo];
    if (hours) {
      conditions.push(`n.data_coleta >= datetime('now', '-${hours} hours')`);
    }
  }

  const whereClause = conditions.length > 0
    ? 'WHERE ' + conditions.join(' AND ')
    : '';

  const countRow = db.prepare(`
    SELECT COUNT(*) as total
    FROM noticias n
    JOIN fontes f ON n.fonte_id = f.id
    ${whereClause}
  `).get(...params) as { total: number };

  const noticias = db.prepare(`
    SELECT n.*, f.nome as fonte_nome, f.regiao as fonte_regiao
    FROM noticias n
    JOIN fontes f ON n.fonte_id = f.id
    ${whereClause}
    ORDER BY COALESCE(n.data_publicacao, n.data_coleta) DESC
    LIMIT ? OFFSET ?
  `).all(...params, limit, offset);

  return Response.json({
    noticias,
    total: countRow.total,
    page,
    limit,
    totalPages: Math.ceil(countRow.total / limit),
  });
}
