import { runCollection } from '@/lib/collector';

export async function POST() {
  try {
    const result = await runCollection();
    return Response.json({
      message: 'Coleta concluída',
      ...result,
    });
  } catch (err) {
    return Response.json(
      { error: err instanceof Error ? err.message : 'Erro na coleta' },
      { status: 500 }
    );
  }
}
