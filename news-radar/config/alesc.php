<?php

/**
 * Conector ALESC (proposições) — Radar Cívico, SONDA D2 (02/07/2026).
 * ⚠️ PARQUEADO / DOCUMENTADO — SEM ingestão em produção. ADITIVO e ISOLADO.
 *
 * A FONTE É LIMPA: o e-Legis (portalelegis.alesc.sc.gov.br) é server-rendered
 * (htmx, zero SPA), com duas listagens públicas e estáveis:
 *   /proposicoes/processo-legislativo   (PLs/PECs/PLCs — 3.230 itens na sonda)
 *   /proposicoes/atividade-parlamentar  (RQS/moções/indicações — 26.739 itens)
 * Paginação ?page=N (10/pág), filtro incremental ?inicio=YYYY-MM-DD&fim=… (provado:
 * 25/06→02/07 = 7 itens), ID estável duplo (número/ano + slug /proposicoes/XxXxX),
 * sub-rotas /documentos e /tramitacoes. Conector forward-first seria trivial.
 *
 * O BLOQUEIO É DE REDE: toda a faixa da ALESC (200.192.66.0/24) dá DROP de TCP
 * SYN nas portas 80/443 contra ESTE VPS (Contabo/US) — www, rss, www2,
 * transparencia, portalelegis: HTTP 000/timeout, enquanto diariomunicipal/
 * tce.sc.gov.br respondem 200 da mesma máquina (bloqueio geo/ASN anti-datacenter;
 * frontend deles = Nginx Proxy Manager). Até o fetch de infra US falha; leitor
 * proxy passa só intermitente (~50%). QUALQUER conector nasce morto nesta máquina.
 *
 * CAMINHO FUTURO (quando houver egress BR): (1) e-Legis com filtro de data —
 * melhor opção; (2) RSS rss.alesc.sc.gov.br/diario-alesc (não provado — host
 * ainda mais fechado); (3) Diário da Assembleia PDF (ID sequencial, pior opção).
 * Requer: proxy/VPS no Brasil ou relay leve num IP BR.
 */
return [
    'parqueado' => true,
    'motivo' => 'ALESC bloqueia IP do VPS (DROP 80/443 na faixa 200.192.66.0/24); fonte e-Legis limpa, falta egress BR',
    'sonda_em' => '2026-07-02',

    'elegis' => [
        'base' => 'https://portalelegis.alesc.sc.gov.br',
        'listagens' => [
            '/proposicoes/processo-legislativo',
            '/proposicoes/atividade-parlamentar',
        ],
        // filtro incremental provado na sonda: ?inicio=YYYY-MM-DD&fim=YYYY-MM-DD&page=N
    ],
];
