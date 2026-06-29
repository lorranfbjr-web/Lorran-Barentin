#!/usr/bin/env python3
"""
Radar Cívico FASE 6 (EXPERIMENTAL) — extrator do DJe-TJSC.

Parseia um caderno PDF do Diário da Justiça eletrônico e emite, em JSON (stdout),
APENAS os blocos com (a) ente PÚBLICO como parte E (b) marcador de SUBSTÂNCIA
jornalística (improbidade, ação civil pública/popular, liminar/tutela, mandado de
segurança, condenação, desapropriação, reintegração de posse).

⚠️ LIMITAÇÃO ESTRUTURAL (documentada): o DJe-TJSC publica INTIMAÇÕES e RELAÇÕES de
processos (referência + nº do processo), NÃO o TEXTO das decisões — a substância
mora no eproc, atrás do número. Por isso o filtro agressivo costuma render ~0:
é esperado. READ-ONLY.

Uso: tjsc_extract.py <caminho.pdf> <url_fonte> <edicao> <cdCaderno>
Requer: PyMuPDF (fitz) no venv dedicado scripts/pdf-venv.
"""
import sys
import re
import json
import hashlib

import fitz  # PyMuPDF

PUB = re.compile(
    r"Munic[íi]pio de|Prefeitura|Fazenda P[úu]blica|C[âa]mara Municipal|Instituto Municipal|"
    r"autarquia|Estado de Santa Catarina",
    re.I,
)
# substância jornalística (decisão de impacto envolvendo poder público)
SUB = re.compile(
    r"improbidade|a[çc][ãa]o civil p[úu]blica|a[çc][ãa]o popular|liminar|tutela (de urg|antecip)|"
    r"mandado de seguran|desapropria|reintegra[çc][ãa]o de posse|conden(a|ou|ado)|"
    r"bloqueio de|indisponibilidade de bens",
    re.I,
)
FOOTER = re.compile(r"DI[ÁA]RIO DA JUSTI[ÇC]A|Poder Judici[áa]rio de|Santa Catarina|n\. \d{3,5}", re.I)


def limpa(s: str) -> str:
    linhas = [l for l in s.split("\n") if not FOOTER.search(l)]
    return re.sub(r"\s+", " ", " ".join(linhas)).strip()


def ente_publico(b: str):
    m = PUB.search(b)
    if not m:
        return None
    # tenta um nome mais completo (Município de X / Prefeitura de X)
    mm = re.search(r"(Munic[íi]pio de [A-ZÀ-Ú][\wÀ-ú' ]+|Prefeitura[\wÀ-ú' ]*de [A-ZÀ-Ú][\wÀ-ú' ]+|Fazenda P[úu]blica[\wÀ-ú' ]*)", b)
    return limpa(mm.group(1))[:80] if mm else m.group(0)


def municipio_de(ente: str | None) -> str | None:
    if not ente:
        return None
    m = re.search(r"de\s+([A-ZÀ-Ú][\wÀ-ú'’\- ]+)$", ente.strip())
    return m.group(1).strip() if m else None


def parse(pdf_path: str, url_fonte: str, edicao: str, caderno: str):
    doc = fitz.open(pdf_path)
    txt = "\n".join(p.get_text() for p in doc)
    txt = re.sub(r"[ \t]+", " ", txt)

    out = []
    blocos = re.split(r"(?=Processo \d{7})", txt)
    for b in blocos:
        if not re.match(r"Processo \d{7}", b):
            continue
        if not (PUB.search(b) and SUB.search(b)):
            continue  # filtro agressivo: só ente público + substância
        mproc = re.match(r"Processo (\d[\d.\-]+\d)", b)
        proc = mproc.group(1) if mproc else None
        ente = ente_publico(b)
        mclasse = re.search(r"-\s*([A-ZÀ-Ú][^-\n]{4,50}?)\s*-", b)
        classe = limpa(mclasse.group(1)) if mclasse else None

        chave = f"tjsc:{edicao}:{proc}"
        out.append(
            {
                "hash": hashlib.sha1(chave.encode("utf-8")).hexdigest(),
                "processo": proc,
                "classe": classe,
                "ente_publico": ente,
                "municipio": municipio_de(ente),
                "edicao": edicao,
                "caderno": caderno,
                "data_pub": None,
                "url_fonte": url_fonte,
                "texto_bruto": limpa(b[:1400]),
            }
        )
    return out


if __name__ == "__main__":
    if len(sys.argv) < 5:
        print("uso: tjsc_extract.py <pdf> <url_fonte> <edicao> <cdCaderno>", file=sys.stderr)
        sys.exit(2)
    try:
        res = parse(sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4])
        print(json.dumps(res, ensure_ascii=False))
    except Exception as e:  # noqa
        print(f"erro: {e}", file=sys.stderr)
        sys.exit(1)
