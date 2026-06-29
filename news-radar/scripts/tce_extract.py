#!/usr/bin/env python3
"""
Radar Cívico FASE 5 — extrator do DOTC-e (Diário Oficial de Contas do TCE-SC).

Parseia um PDF do DOTC-e e emite, em JSON (stdout), os PROCESSOS/DECISÕES — as
deliberações do Tribunal: representações, relatórios, prestações de contas,
decisões singulares e editais de citação. Cada item é um FATO público; o faro
(Sonnet) decide noticiabilidade. READ-ONLY.

Uso: tce_extract.py <caminho.pdf> <url_fonte> <edicao_data>
Requer: PyMuPDF (fitz) no venv dedicado scripts/pdf-venv.
"""
import sys
import re
import json
import hashlib

import fitz  # PyMuPDF

# prefixo do nº do processo -> rótulo legível
SIGLAS = {
    "REP": "Representação",
    "RLI": "Relatório de Inspeção",
    "RLA": "Relatório de Auditoria",
    "DEN": "Denúncia",
    "PCP": "Prestação de Contas (Prefeito)",
    "PCA": "Prestação de Contas (Administrador)",
    "PCR": "Prestação de Contas (Recursos)",
    "TCE": "Tomada de Contas Especial",
    "ELC": "Edital de Licitação",
    " APE": "Aposentadoria",
    "PDI": "Pedido",
    "RPA": "Recurso",
    "RFI": "Reexame",
}

FOOTER = re.compile(
    r"(www\.tce\.sc\.gov\.br|Di[áa]rio Oficial Eletr[ôo]nico|Tribunal de Contas do Estado|"
    r"P[áa]gina \d+|Bulc[ãa]o Viana|diario@tcesc|Ano \d+ - n[º°])",
    re.I,
)


def limpa(s: str) -> str:
    linhas = [l for l in s.split("\n") if not FOOTER.search(l)]
    return re.sub(r"\s+", " ", " ".join(linhas)).strip()


def campo(bloco: str, rotulo: str) -> str | None:
    m = re.search(
        rotulo
        + r"\s*:?\s*(.*?)(?=\n[A-ZÓÂÃÉÍ][^:\n]{2,45}:|Decis[ãa]o n|Ac[óo]rd[ãa]o n|Assunto:|Interessad|Respons[áa]vel:|Unidade Gestora:|Unidade T[ée]cnica:|Relator|$)",
        bloco,
        re.I | re.S,
    )
    return limpa(m.group(1)) if m else None


def tipo_de(proc: str) -> str:
    m = re.match(r"\s*([A-Z]{2,4})\b", proc or "")
    if m:
        return SIGLAS.get(m.group(1), m.group(1))
    return "Processo"


def municipio_de(ug: str | None) -> str | None:
    if not ug:
        return None
    # "Prefeitura Municipal de X" / "Câmara Municipal de X" / "Fundo ... de X"
    m = re.search(r"\bde\s+([A-ZÀ-Ú][\wÀ-ú'’\- ]+)$", ug.strip())
    if m and re.search(r"munic|prefeitura|c[âa]mara|fundo|cons[óo]rcio|autarquia|servi[çc]o", ug, re.I):
        return m.group(1).strip()
    return None


def parse(pdf_path: str, url_fonte: str, edicao: str):
    doc = fitz.open(pdf_path)
    txt = "\n".join(p.get_text() for p in doc)
    txt = re.sub(r"[ \t]+", " ", txt)

    out = []
    seen = set()
    blocos = re.split(r"(?=Processo n[º°.: ])", txt)
    for b in blocos:
        if not re.match(r"Processo n", b):
            continue
        mproc = re.search(r"Processo n[º°.: ]+\s*([A-Z]{2,4}\s*\d{2}/\d{4,})", b)
        if not mproc:
            continue
        proc = re.sub(r"\s+", " ", mproc.group(1)).strip()
        if proc in seen:
            continue
        seen.add(proc)

        assunto = campo(b, r"Assunto")
        interessado = campo(b, r"Interessad[oa]")
        responsavel = campo(b, r"Respons[áa]vel")
        ug = campo(b, r"Unidade Gestora")
        relator = campo(b, r"Relator")
        mdec = re.search(r"(Decis[ãa]o n|Ac[óo]rd[ãa]o n)[º°.: ]+\s*([\d./-]+\s*-?\s*[^\n]{0,40})", b)
        decisao = limpa(mdec.group(2)) if mdec else None
        # trecho do "decide:" (desfecho) — onde mora multa/irregularidade/condenação
        mdesf = re.search(r"\bdecide\s*:?\s*(.{0,500})", b, re.I | re.S)
        desfecho = limpa(mdesf.group(1)) if mdesf else None

        if not assunto:
            continue

        chave = f"tce:{proc}"
        out.append(
            {
                "hash": hashlib.sha1(chave.encode("utf-8")).hexdigest(),
                "processo": proc,
                "tipo_proc": tipo_de(proc),
                "assunto": assunto,
                "interessado": interessado,
                "responsavel": responsavel,
                "unidade_gestora": ug,
                "municipio": municipio_de(ug),
                "relator": relator,
                "decisao": decisao,
                "desfecho": desfecho,
                "data_pub": edicao,
                "edicao": edicao,
                "url_fonte": url_fonte,
                "texto_bruto": limpa(b[:1400]),
            }
        )
    return out


if __name__ == "__main__":
    if len(sys.argv) < 4:
        print("uso: tce_extract.py <pdf> <url_fonte> <edicao_data>", file=sys.stderr)
        sys.exit(2)
    try:
        res = parse(sys.argv[1], sys.argv[2], sys.argv[3])
        print(json.dumps(res, ensure_ascii=False))
    except Exception as e:  # noqa
        print(f"erro: {e}", file=sys.stderr)
        sys.exit(1)
