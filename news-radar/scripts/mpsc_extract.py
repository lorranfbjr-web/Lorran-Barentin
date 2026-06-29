#!/usr/bin/env python3
"""
Radar Cívico FASE 4 — extrator do Diário Oficial Eletrônico do MPSC.

Parseia um PDF do DOE-MPSC e emite, em JSON (stdout), os EXTRATOS DE INSTAURAÇÃO
de procedimentos das Promotorias (notícia de fato / inquérito civil / procedimento
preparatório/administrativo) — o "MP abre investigação sobre X". Cada extrato é um
FATO público; o faro (Sonnet) decide noticiabilidade depois. READ-ONLY.

Uso: mpsc_extract.py <caminho.pdf> <url_fonte> <edicao_data>
Requer: PyMuPDF (fitz) no venv dedicado scripts/pdf-venv.
"""
import sys
import re
import json
import hashlib

import fitz  # PyMuPDF

# rótulo do bloco -> tipo normalizado
TIPOS = [
    (r"INQU[ÉE]RITO CIVIL", "inquerito_civil"),
    (r"PROCEDIMENTO PREPARAT[ÓO]RIO", "procedimento_preparatorio"),
    (r"PROCEDIMENTO ADMINISTRATIVO DE ACOMPANHAMENT", "pa_acompanhamento"),
    (r"PROCEDIMENTO ADMINISTRATIVO", "procedimento_administrativo"),
    (r"NOT[ÍI]CIA DE FATO", "noticia_de_fato"),
]

# linhas de rodapé/cabeçalho da página que se intrometem no meio do bloco
FOOTER = re.compile(
    r"(Divulga[çc][ãa]o:|Publica[çc][ãa]o:|Ano \d+\s*\|?\s*n\.|Di[áa]rio Oficial Eletr[ôo]nico|"
    r"Ato n\. 469|Assinado por meio eletr[ôo]nico|certifica[çc][ãa]o digital|P[áa]g\.\d+)",
    re.I,
)


def limpa(s: str) -> str:
    linhas = [l for l in s.split("\n") if not FOOTER.search(l)]
    return re.sub(r"\s+", " ", " ".join(linhas)).strip()


def campo(bloco: str, rotulo: str) -> str | None:
    # captura "Rótulo: valor" até o próximo rótulo conhecido ou fim do bloco
    m = re.search(
        rotulo
        + r"\s*:?\s*(.*?)(?=\n[A-ZÓÂÃÉÍ][^:\n]{2,40}:|Membro do Minist|Data da Instaura|Objeto:|Partes:|$)",
        bloco,
        re.I | re.S,
    )
    return limpa(m.group(1)) if m else None


def tipo_de(header: str):
    for pat, norm in TIPOS:
        if re.search(pat, header, re.I):
            return norm
    return "outro"


def parse(pdf_path: str, url_fonte: str, edicao: str):
    doc = fitz.open(pdf_path)
    txt = "\n".join(p.get_text() for p in doc)
    txt = re.sub(r"[ \t]+", " ", txt)

    out = []
    blocos = re.split(r"(?=EXTRATO DE INSTAURA[ÇC][ÃA]O)", txt)
    for b in blocos:
        if not re.match(r"EXTRATO DE INSTAURA[ÇC][ÃA]O", b):
            continue
        header = b.split("\n", 1)[0]
        tipo = tipo_de(header)
        num = None
        mnum = re.search(r"N\.?\s*([\d.]+\.\d{4}\.[\d.\-]+|\d[\d./-]{6,})", header)
        if mnum:
            num = mnum.group(1).strip(" .")

        comarca = campo(b, r"COMARCA")
        orgao = campo(b, r"[ÓO]RG[ÃA]O DO MINIST[ÉE]RIO P[ÚU]BLICO")
        partes = campo(b, r"Partes")
        objeto = campo(b, r"Objeto")
        membro = campo(b, r"Membro do Minist[ée]rio P[úu]blico")
        data_inst = campo(b, r"Data da Instaura[çc][ãa]o")
        data_norm = None
        if data_inst:
            md = re.search(r"(\d{1,2})/(\d{1,2})/(\d{4})", data_inst)
            if md:
                data_norm = f"{md.group(3)}-{int(md.group(2)):02d}-{int(md.group(1)):02d}"

        if not objeto and not partes:
            continue  # bloco sem miolo aproveitável

        chave = f"mpsc:{num or ''}:{edicao}:{(objeto or partes or '')[:40]}"
        out.append(
            {
                "hash": hashlib.sha1(chave.encode("utf-8")).hexdigest(),
                "tipo_proc": tipo,
                "numero": num,
                "comarca": comarca,
                "orgao_mp": orgao,
                "partes": partes,
                "objeto": objeto,
                "membro": membro,
                "data_pub": data_norm or edicao,
                "edicao": edicao,
                "url_fonte": url_fonte,
                "texto_bruto": limpa(b[:1200]),
            }
        )
    return out


if __name__ == "__main__":
    if len(sys.argv) < 4:
        print("uso: mpsc_extract.py <pdf> <url_fonte> <edicao_data>", file=sys.stderr)
        sys.exit(2)
    try:
        res = parse(sys.argv[1], sys.argv[2], sys.argv[3])
        print(json.dumps(res, ensure_ascii=False))
    except Exception as e:  # noqa
        print(f"erro: {e}", file=sys.stderr)
        sys.exit(1)
