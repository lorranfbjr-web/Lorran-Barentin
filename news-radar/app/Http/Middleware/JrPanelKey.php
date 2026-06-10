<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chave leve da aba Radar do painel: `?key=<JRLINK_PANEL_KEY>` no 1º acesso
 * grava cookie de 90 dias; depois o cookie basta. Sem chave configurada no
 * .env, nega tudo (fail-closed). A aba Feed continua aberta como sempre.
 */
class JrPanelKey
{
    private const COOKIE = 'jrlink_panel_key';

    public function handle(Request $request, Closure $next): Response
    {
        $esperada = (string) config('jrlink.painel_key', '');
        if ($esperada === '') {
            return response()->json(['error' => 'painel sem chave configurada'], 401);
        }

        $fornecida = (string) ($request->query('key') ?: $request->cookie(self::COOKIE, ''));
        if (! hash_equals($esperada, $fornecida)) {
            return response()->json(['error' => 'chave inválida'], 401);
        }

        $response = $next($request);
        if ($request->query('key')) {
            $response->headers->setCookie(cookie(self::COOKIE, $esperada, 60 * 24 * 90, '/', null, true, true, false, 'Lax'));
        }

        return $response;
    }
}
