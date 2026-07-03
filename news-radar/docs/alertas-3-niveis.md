# Alertas em 3 níveis (BLOCO 5 · radar-total 03/07/2026)

1. **🔥🔥🔥 N3 LARGA-TUDO** — mensagem IMEDIATA própria, fora do cap do digest. Gatilhos: (a) viral v0 do juiz (`temperatura_juiz=quente`) em cidade **tier 1** → `jrlink:quente-fria`; (b) fiscalização (`dom,tce,mpsc,camara`) com `score_pauta ≥ RADAR_N3_SCORE` (90) → `jrcivico:alertar`. Release de prefeitura nunca vira N3.
2. **🔥 N2 QUENTE** — fluxo atual intocado: gate ≥80/60 do `jrcivico:alertar` (digest) e digest do quente-fria.
3. **❄️ N1 RESTO** — só painel/Mesa (`jr_quente_fria` classe fria; não-gatilho do alertar). Nenhuma mensagem.
4. **Guard-rails**: teto diário `RADAR_N3_MAX_DIA` (3) por fluxo — excedente desce pro digest N2, nunca some; janela de silêncio 22–06 vale igual (N3 de madrugada acumula e sai no 1º ciclo pós-6h); falha Z-API → item volta no próximo ciclo; formato limpo (fato + link, metadado em 1 linha após separador).
5. **Config-driven** em `config/radar_civico.php` bloco `niveis` — nenhum detector/score alterado; dedup marca N3 com `motivo=n3-fiscalizacao` (`jr_civico_alertas`) ou prefixo `n3 ·` (`jr_quente_fria`).
