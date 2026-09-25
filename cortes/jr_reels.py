# -*- coding: utf-8 -*-
"""
JR Reels: motor de cortes verticais do Jornal Razao (1080x1920).

Layout (das referencias do JR):
  foto de contexto no topo (Ken Burns) + logo JR branco + titulo em caixas azuis
  entrevistado embaixo, enquadrado pelo rosto, com punch-in alternado a cada frase
  legenda palavra a palavra (karaoke) em Montserrat, palavra ativa em caixa azul
  barra de progresso na costura entre as duas metades
  audio: corte de silencio, EQ de voz, compressao e loudnorm -14 LUFS

uso: python3 jr_reels.py corte.json
"""
import json, math, os, subprocess, sys, time, re
import numpy as np
import cv2
from PIL import Image, ImageDraw, ImageFont, ImageFilter

HERE = os.path.dirname(os.path.abspath(__file__))
ASSETS = os.path.join(HERE, "assets")
FONT_DIR = os.path.join(ASSETS, "fonts")

W, H = 1080, 1920
SR = 48000

# paleta JR (gerador de Stories + carrossel)
BLUE = (0, 97, 255)
BLUE_LIGHT = (24, 173, 254)
NAVY = (13, 36, 129)
ORANGE = (255, 159, 0)
WHITE = (255, 255, 255)

DEFAULT_LAYOUT = {
    "seam": 920,            # onde termina a foto e comeca o video
    "head_bottom": 962,     # base do bloco de titulo
    "head_size": 52,        # Fira Sans 700
    "head_line_h": 72,
    "head_pad_x": 22,
    "head_max_w": 1000,
    "logo_h": 66,
    "logo_gap": 22,
    "cap_y": 1500,          # centro da legenda
    "cap_size": 66,
    "cap_max_w": 820,
    "face_y": 0.36,         # altura do rosto dentro da metade de baixo
    "bar_h": 8,
}

# ------------------------------------------------------------------ util
def log(*a):
    print("[jr]", *a, file=sys.stderr, flush=True)

def ease_out_cubic(x):
    x = min(max(x, 0.0), 1.0); return 1 - (1 - x) ** 3

def ease_out_back(x, s=1.70158):
    x = min(max(x, 0.0), 1.0); x -= 1; return x * x * ((s + 1) * x + s) + 1

def ease_in_out(x):
    x = min(max(x, 0.0), 1.0); return x * x * (3 - 2 * x)

GOOGLE_FONTS = {"FiraSans": "Fira+Sans", "Montserrat": "Montserrat"}

def ensure_font(name):
    """Baixa a fonte do Google Fonts (OFL) na primeira execucao."""
    path = os.path.join(FONT_DIR, name + ".ttf")
    if os.path.exists(path):
        return path
    import urllib.request
    fam, wt = name.split("-")
    css = urllib.request.urlopen(urllib.request.Request(
        f"https://fonts.googleapis.com/css2?family={GOOGLE_FONTS[fam]}:wght@{wt}",
        headers={"User-Agent": "curl/8.0"}), timeout=30).read().decode()
    url = re.search(r"url\((https://[^)]+\.ttf)\)", css).group(1)
    os.makedirs(FONT_DIR, exist_ok=True)
    urllib.request.urlretrieve(url, path)
    return path

def ensure_face_model():
    path = os.path.join(ASSETS, "face_detection_yunet_2023mar.onnx")
    if not os.path.exists(path):
        from huggingface_hub import hf_hub_download
        import shutil
        shutil.copy(hf_hub_download("opencv/face_detection_yunet", "face_detection_yunet_2023mar.onnx"), path)
    return path

_fonts = {}
def font(name, size):
    k = (name, int(size))
    if k not in _fonts:
        _fonts[k] = ImageFont.truetype(ensure_font(name), int(size))
    return _fonts[k]

def probe(src):
    out = subprocess.run(["ffprobe", "-v", "error", "-show_entries",
                          "stream=codec_type,width,height,r_frame_rate,avg_frame_rate:stream_tags=rotate:format=duration",
                          "-of", "json", src], capture_output=True, text=True, check=True).stdout
    j = json.loads(out)
    v = next(s for s in j["streams"] if s["codec_type"] == "video")
    num, den = (v.get("avg_frame_rate") or v["r_frame_rate"]).split("/")
    fps = float(num) / float(den) if float(den) else 30.0
    if fps <= 0 or fps > 240:
        num, den = v["r_frame_rate"].split("/"); fps = float(num) / float(den)
    has_audio = any(s["codec_type"] == "audio" for s in j["streams"])
    return {"w": int(v["width"]), "h": int(v["height"]), "fps": fps,
            "dur": float(j["format"]["duration"]), "audio": has_audio}

def pick_fps(f):
    for std in (23.976, 24, 25, 29.97, 30):
        if abs(f - std) < 0.05:
            return f
    return f / 2 if f > 45 else 30.0

def rgba_to_premul(arr):
    """PIL RGBA -> (rgb premultiplicado float32 BGR, alpha float32)"""
    a = arr[..., 3:4].astype(np.float32) / 255.0
    rgb = arr[..., :3][..., ::-1].astype(np.float32) * a
    return rgb, a

class Sprite:
    """Imagem RGBA pronta pra compor sobre frame BGR uint8."""
    def __init__(self, pil_rgba):
        arr = np.asarray(pil_rgba.convert("RGBA"))
        self.h, self.w = arr.shape[:2]
        self.rgb, self.a = rgba_to_premul(arr)
        self.pil = pil_rgba

    def scaled(self, s):
        if abs(s - 1) < 1e-3:
            return self
        w, h = max(1, int(round(self.w * s))), max(1, int(round(self.h * s)))
        return Sprite(self.pil.resize((w, h), Image.BICUBIC))

def blit(frame, sp, x, y, alpha=1.0, clip=None):
    """Composicao premultiplicada, com recorte nas bordas (clip = (xa, xb) opcional)."""
    if sp is None or alpha <= 0.003:
        return
    x, y = int(round(x)), int(round(y))
    x0, y0 = max(x, 0), max(y, 0)
    x1, y1 = min(x + sp.w, frame.shape[1]), min(y + sp.h, frame.shape[0])
    if clip is not None:
        x0, x1 = max(x0, int(clip[0])), min(x1, int(clip[1]))
    if x1 <= x0 or y1 <= y0:
        return
    sx0, sy0 = x0 - x, y0 - y
    sx1, sy1 = sx0 + (x1 - x0), sy0 + (y1 - y0)
    a = sp.a[sy0:sy1, sx0:sx1] * alpha
    rgb = sp.rgb[sy0:sy1, sx0:sx1] * alpha
    reg = frame[y0:y1, x0:x1].astype(np.float32)
    frame[y0:y1, x0:x1] = np.clip(reg * (1 - a) + rgb, 0, 255).astype(np.uint8)

def shadowed(img, blur=8, offset=(0, 4), opacity=0.55, pad=24):
    """Adiciona sombra suave sob um RGBA."""
    w, h = img.size
    out = Image.new("RGBA", (w + 2 * pad, h + 2 * pad), (0, 0, 0, 0))
    a = img.split()[3].point(lambda v: int(v * opacity))
    sh = Image.new("RGBA", img.size, (0, 0, 0, 255)); sh.putalpha(a)
    layer = Image.new("RGBA", out.size, (0, 0, 0, 0))
    layer.paste(sh, (pad + offset[0], pad + offset[1]))
    layer = layer.filter(ImageFilter.GaussianBlur(blur))
    out.alpha_composite(layer)
    out.alpha_composite(img, (pad, pad))
    return out, pad

# ------------------------------------------------------------------ logo
def logo_white(height):
    im = Image.open(os.path.join(ASSETS, "logo_jr_horizontal.png")).convert("RGBA")
    im = im.crop(im.getbbox())
    w = int(im.width * height / im.height)
    im = im.resize((w, height), Image.LANCZOS)
    white = Image.new("RGBA", im.size, (255, 255, 255, 255))
    white.putalpha(im.split()[3])
    return white

# ------------------------------------------------------------------ titulo
def balanced_wrap(text, fnt, max_w):
    words = text.split()
    n = len(words)
    width = lambda i, j: fnt.getlength(" ".join(words[i:j]))
    for lines in range(1, 7):
        best = None
        # programacao dinamica: minimizar soma dos quadrados das sobras
        INF = float("inf")
        dp = [[INF] * (n + 1) for _ in range(lines + 1)]
        back = [[0] * (n + 1) for _ in range(lines + 1)]
        dp[0][0] = 0
        for l in range(1, lines + 1):
            for j in range(1, n + 1):
                for i in range(l - 1, j):
                    if dp[l - 1][i] == INF:
                        continue
                    wd = width(i, j)
                    if wd > max_w:
                        continue
                    c = dp[l - 1][i] + (max_w - wd) ** 2
                    if c < dp[l][j]:
                        dp[l][j] = c; back[l][j] = i
        if dp[lines][n] < INF:
            out, j = [], n
            for l in range(lines, 0, -1):
                i = back[l][j]; out.append(" ".join(words[i:j])); j = i
            return out[::-1]
    return [text]

def headline_lines(cfg, L):
    h = cfg["headline"]
    if isinstance(h, list):
        return h
    f = font("FiraSans-700", L["head_size"])
    return balanced_wrap(h, f, L["head_max_w"] - 2 * L["head_pad_x"])

def render_headline_line(text, L):
    f = font("FiraSans-700", L["head_size"])
    tw = int(math.ceil(f.getlength(text)))
    bw, bh = tw + 2 * L["head_pad_x"], L["head_line_h"]
    box = Image.new("RGBA", (bw, bh), BLUE + (255,))
    txt = Image.new("RGBA", (bw, bh), (0, 0, 0, 0))
    d = ImageDraw.Draw(txt)
    asc, desc = f.getmetrics()
    ty = (bh - (asc + desc)) // 2 + 1
    d.text((L["head_pad_x"], ty), text, font=f, fill=WHITE)
    return box, txt

# ------------------------------------------------------------------ legendas
FUNC_WORDS = set("a o e é as os de do da dos das em no na nos nas um uma uns umas que se pra pro para por com sem ao à "
                 "mas ou nem então como quando onde porque tá eu tu ele ela nós vocês eles elas lhe me te".split())

def clean_word(w):
    w = w.strip()
    w = re.sub(r"^[\"'“”‘’(«]+|[\"'“”‘’)»]+$", "", w)
    w = re.sub(r"[.,;:…]+$", "", w)
    return w

def build_cards(words, max_words=3, max_chars=18, gap_break=0.32):
    cards, cur = [], []
    for i, w in enumerate(words):
        cur.append(w)
        raw = w["w"].strip()
        txt = " ".join(clean_word(x["w"]) for x in cur)
        nxt = words[i + 1] if i + 1 < len(words) else None
        punct = raw[-1:] in ".!?,;:…" if raw else False
        gap = (nxt["s"] - w["e"]) if nxt else 9
        nlen = len(txt) + 1 + len(clean_word(nxt["w"])) if nxt else 999
        if len(cur) >= max_words or punct or gap > gap_break or nlen > max_chars:
            cards.append(cur); cur = []
    if cur:
        cards.append(cur)
    # palavra funcional no fim do cartao passa pro proximo (le melhor)
    for k in range(len(cards) - 1):
        c, n = cards[k], cards[k + 1]
        last = c[-1]
        if len(c) >= 2 and clean_word(last["w"]).lower() in FUNC_WORDS and last["w"].strip()[-1:] not in ".!?,;:" \
                and n[0]["s"] - last["e"] < 0.25 and len(n) < max_words:
            n.insert(0, c.pop())
    cards = [c for c in cards if c]
    # palavra funcional sozinha ("da", "para", "que") nunca pisca isolada: junta com o bloco seguinte
    k = 0
    while k < len(cards) - 1:
        c = cards[k]
        if len(c) == 1 and clean_word(c[0]["w"]).lower() in FUNC_WORDS and c[0]["w"].strip()[-1:] not in ".!?,;:" \
                and cards[k + 1][0]["s"] - c[0]["e"] < 0.35:
            cards[k + 1].insert(0, c[0])
            cards.pop(k)
            continue
        k += 1
    return cards

class CaptionRenderer:
    """Legenda no padrao das referencias do JR: branca, Montserrat ExtraBold, caixa mista como falado,
    sem karaoke e sem palavra colorida; legibilidade por halo em camadas + sombra suave (sem contorno duro)."""
    def __init__(self, L, emphasis=()):
        self.L = L
        self.size = L["cap_size"]
        self.cache = {}

    def sprite(self, card_words, active=None):
        key = tuple(w["w"] for w in card_words)
        if key in self.cache:
            return self.cache[key]
        size = self.size
        text = " ".join(clean_word(w["w"]) for w in card_words)
        f = font("Montserrat-800", size)
        while f.getlength(text) > self.L["cap_max_w"] and size > 40:
            size -= 2; f = font("Montserrat-800", size)
        asc, desc = f.getmetrics()
        m = 44
        Wc = int(f.getlength(text)) + 2 * m; Hc = asc + desc + 2 * m
        mask = Image.new("L", (Wc, Hc), 0)
        ImageDraw.Draw(mask).text((m, m), text, font=f, fill=255)
        def layer(dilate, blur, opacity, dy=0):
            mk = mask.filter(ImageFilter.MaxFilter(dilate)) if dilate > 1 else mask
            if dy:
                mk = mk.transform(mk.size, Image.AFFINE, (1, 0, 0, 0, 1, -dy))
            mk = mk.filter(ImageFilter.GaussianBlur(blur)).point(lambda v: int(v * opacity))
            im = Image.new("RGBA", (Wc, Hc), (0, 0, 0, 255)); im.putalpha(mk)
            return im
        img = Image.new("RGBA", (Wc, Hc), (0, 0, 0, 0))
        img.alpha_composite(layer(21, 16, 0.30))          # halo ambiente: escurece de leve a area
        img.alpha_composite(layer(7, 5, 0.55))            # halo de contato: abraca o desenho da letra
        img.alpha_composite(layer(1, 4, 0.55, dy=4))      # sombra projetada (como na referencia)
        white = Image.new("RGBA", (Wc, Hc), (255, 255, 255, 255)); white.putalpha(mask)
        img.alpha_composite(white)
        sp = Sprite(img)
        self.cache[key] = sp
        return sp

# ------------------------------------------------------------------ audio
def load_audio(src, t0, t1, has_audio=True):
    n = int(round((t1 - t0) * SR))
    if not has_audio:
        return np.zeros((n, 2), np.float32)
    raw = subprocess.run(["ffmpeg", "-hide_banner", "-loglevel", "error", "-ss", f"{t0:.4f}", "-i", src,
                          "-t", f"{t1 - t0:.4f}", "-vn", "-ac", "2", "-ar", str(SR), "-f", "f32le", "-"],
                         capture_output=True, check=True).stdout
    a = np.frombuffer(raw, np.float32).reshape(-1, 2).copy()
    if len(a) < n:
        a = np.vstack([a, np.zeros((n - len(a), 2), np.float32)])
    return a[:n]

def rms_db(audio, hop=0.01):
    mono = audio.mean(axis=1)
    k = int(SR * hop)
    n = len(mono) // k
    r = np.sqrt((mono[:n * k].reshape(n, k) ** 2).mean(axis=1) + 1e-12)
    return 20 * np.log10(r + 1e-9)

def whoosh(dur=0.55, peak_db=-20.0, seed=7):
    """Whoosh sintetico: ruido rosa filtrado com varredura de frequencia."""
    rng = np.random.default_rng(seed)
    n = int(SR * dur)
    white = rng.standard_normal(n).astype(np.float32)
    # rosa aproximado
    b = [0.049922035, -0.095993537, 0.050612699, -0.004408786]
    a = [1, -2.494956002, 2.017265875, -0.522189400]
    pink = np.zeros(n, np.float32); x = [0.0] * 4; y = [0.0] * 4
    for i in range(n):
        x = [white[i]] + x[:3]
        yi = b[0] * x[0] + b[1] * x[1] + b[2] * x[2] + b[3] * x[3] - a[1] * y[0] - a[2] * y[1] - a[3] * y[2]
        y = [yi] + y[:3]; pink[i] = yi
    t = np.arange(n) / SR
    fc = 400 + 3600 * np.sin(np.pi * np.clip(t / dur, 0, 1)) ** 2
    out = np.zeros(n, np.float32); lp = 0.0; bp = 0.0; q = 0.9
    for i in range(n):  # state-variable filter
        f = 2 * math.sin(math.pi * fc[i] / SR)
        hp = pink[i] - lp - q * bp
        bp += f * hp; lp += f * bp
        out[i] = bp
    env = np.sin(np.pi * np.clip(t / dur, 0, 1)) ** 1.6 * np.exp(-1.2 * t / dur)
    out *= env
    out /= (np.abs(out).max() + 1e-9)
    out *= 10 ** (peak_db / 20)
    st = np.stack([out * 0.9, out], axis=1)
    return st

def process_voice(wav_in, wav_out, target=-14.0):
    chain = "highpass=f=75,lowpass=f=15000,equalizer=f=3200:t=q:w=1.2:g=2.0,acompressor=threshold=-21dB:ratio=2.6:attack=6:release=140:makeup=1.5"
    meas = subprocess.run(["ffmpeg", "-hide_banner", "-i", wav_in, "-af",
                           chain + f",loudnorm=I={target}:TP=-1.2:LRA=9:print_format=json", "-f", "null", "-"],
                          capture_output=True, text=True).stderr
    j = json.loads(meas[meas.rfind("{"):meas.rfind("}") + 1])
    ln = (f"loudnorm=I={target}:TP=-1.2:LRA=9:measured_I={j['input_i']}:measured_TP={j['input_tp']}:"
          f"measured_LRA={j['input_lra']}:measured_thresh={j['input_thresh']}:offset={j['target_offset']}:linear=true")
    subprocess.run(["ffmpeg", "-hide_banner", "-loglevel", "error", "-y", "-i", wav_in, "-af",
                    chain + "," + ln + ",aresample=48000", "-c:a", "pcm_f32le", wav_out], check=True)

def write_wav(path, audio):
    raw = audio.astype(np.float32).tobytes()
    subprocess.run(["ffmpeg", "-hide_banner", "-loglevel", "error", "-y", "-f", "f32le", "-ar", str(SR), "-ac", "2",
                    "-i", "-", "-c:a", "pcm_f32le", path], input=raw, check=True)

def read_wav(path):
    raw = subprocess.run(["ffmpeg", "-hide_banner", "-loglevel", "error", "-i", path, "-f", "f32le", "-ac", "2",
                          "-ar", str(SR), "-"], capture_output=True, check=True).stdout
    return np.frombuffer(raw, np.float32).reshape(-1, 2).copy()

# ------------------------------------------------------------------ edicao (EDL)
def load_words(cfg, t0, t1):
    j = json.load(open(cfg["words"]))
    words = []
    for s in j["segments"]:
        for w in s["words"]:
            if w["s"] >= t0 - 0.05 and w["e"] <= t1 + 0.05 and w["w"].strip():
                words.append(dict(w))
    fixes = cfg.get("fix", {})
    for w in words:
        k = w["w"].strip()
        core = re.sub(r"[.,;:!?…]+$", "", k)
        tail = k[len(core):]
        if core in fixes:
            w["w"] = fixes[core] + tail
        elif core.lower() in fixes:
            w["w"] = fixes[core.lower()] + tail
    # frases inteiras (chave com espaco): aplica sobre sequencia
    for k, v in fixes.items():
        if " " not in k:
            continue
        ks = k.split(); vs = v.split()
        i = 0
        while i <= len(words) - len(ks):
            seq = [re.sub(r"[.,;:!?…]+$", "", words[i + m]["w"].strip()) for m in range(len(ks))]
            if [x.lower() for x in seq] == [x.lower() for x in ks]:
                tail = words[i + len(ks) - 1]["w"].strip()[len(seq[-1]):]
                span_s, span_e = words[i]["s"], words[i + len(ks) - 1]["e"]
                new = []
                for m, t in enumerate(vs):
                    a = span_s + (span_e - span_s) * m / len(vs); b = span_s + (span_e - span_s) * (m + 1) / len(vs)
                    new.append({"w": t + (tail if m == len(vs) - 1 else ""), "s": round(a, 3), "e": round(b, 3), "p": 1})
                words[i:i + len(ks)] = new
                i += len(vs)
            else:
                i += 1
    # simbolo solto (ex.: "82" + "%") gruda na palavra anterior
    merged = []
    for w in words:
        t = w["w"].strip()
        if merged and (re.fullmatch(r"[%º°ª]+[.,;:!?…]*", t) or
                       (re.fullmatch(r"[.,]\d+[%.,;:!?…]*", t) and re.search(r"\d$", merged[-1]["w"].strip()))):
            merged[-1]["w"] = merged[-1]["w"].strip() + t
            merged[-1]["e"] = w["e"]
            continue
        merged.append(w)
    # remove palavras apagadas pela correcao
    return [w for w in merged if w["w"].strip() not in ("", "-")]

def build_edl(words, audio, t0, t1, fps, cfg):
    """Intervalos mantidos, em indices de frame relativos a t0."""
    tr = cfg.get("trim", {})
    head = tr.get("head", 0.10); tail = tr.get("tail", 0.40)
    max_gap = tr.get("max_gap", 0.50); keep_gap = tr.get("keep_gap", 0.24)
    enabled = tr.get("enabled", True)
    start = max(0.0, (words[0]["s"] - t0) - head) if words else 0.0
    end = min(t1 - t0, (words[-1]["e"] - t0) + tail) if words else t1 - t0
    if cfg.get("hard_in") is not None:
        start = cfg["hard_in"] - t0
    if cfg.get("hard_out") is not None:
        end = cfg["hard_out"] - t0
    db = rms_db(audio)
    speech_lvl = np.percentile(db[db > -60], 70) if np.any(db > -60) else -20
    thr = speech_lvl - tr.get("silence_db", 22)
    cuts = []
    if enabled:
        for a, b in zip(words[:-1], words[1:]):
            g0, g1 = a["e"] - t0, b["s"] - t0
            if g1 - g0 <= max_gap:
                continue
            # confirma silencio real no audio (whisper erra fronteira)
            i0, i1 = int(g0 / 0.01), int(g1 / 0.01)
            seg = db[i0:i1]
            quiet = seg < thr
            # maior trecho silencioso continuo
            best, cur, bs, cs = 0, 0, 0, 0
            for k, q in enumerate(quiet):
                if q:
                    if cur == 0: cs = k
                    cur += 1
                    if cur > best: best, bs = cur, cs
                else:
                    cur = 0
            if best * 0.01 < max_gap:
                continue
            q0 = g0 + bs * 0.01; q1 = q0 + best * 0.01
            # deixa keep_gap de respiro, dividido nas duas pontas
            c0 = q0 + keep_gap * 0.5; c1 = q1 - keep_gap * 0.5
            if c1 - c0 > 0.08:
                cuts.append((c0, c1))
    for c in cfg.get("remove", []):   # trechos removidos a mao (tempo de origem)
        cuts.append((c[0] - t0, c[1] - t0))
    cuts.sort()
    keep, cur = [], start
    for c0, c1 in cuts:
        if c1 <= start or c0 >= end:
            continue
        if c0 > cur:
            keep.append((cur, c0))
        cur = max(cur, c1)
    if end > cur:
        keep.append((cur, end))
    # quantiza em frames
    edl = []
    for a, b in keep:
        fa, fb = int(round(a * fps)), int(round(b * fps))
        if fb - fa >= 2:
            edl.append((fa, fb))
    return edl

class TimeMap:
    def __init__(self, edl, fps):
        self.edl = edl; self.fps = fps
        self.cum = [0]
        for a, b in edl:
            self.cum.append(self.cum[-1] + (b - a))
        self.n_out = self.cum[-1]

    def out_frame_to_src(self, n):
        for k, (a, b) in enumerate(self.edl):
            if n < self.cum[k + 1]:
                return a + (n - self.cum[k]), k
        a, b = self.edl[-1]; return b - 1, len(self.edl) - 1

    def src_time_to_out(self, t):  # t relativo a t0 (s)
        f = t * self.fps
        for k, (a, b) in enumerate(self.edl):
            if f < a:
                return self.cum[k] / self.fps
            if f < b:
                return (self.cum[k] + (f - a)) / self.fps
        return self.n_out / self.fps

# ------------------------------------------------------------------ tarjas pretas
def detect_bars(src, t0, t1, info):
    """Detecta letterbox/pillarbox. Devolve (w, h, x, y) ou None."""
    dur = min(30.0, t1 - t0)
    err = subprocess.run(["ffmpeg", "-hide_banner", "-ss", f"{t0 + (t1 - t0 - dur) / 2:.3f}", "-i", src, "-t", f"{dur:.3f}",
                          "-vf", "fps=2,cropdetect=limit=24:round=2:reset=0", "-f", "null", "-"],
                         capture_output=True, text=True).stderr
    m = re.findall(r"crop=(\d+):(\d+):(\d+):(\d+)", err)
    if not m:
        return None
    w, h, x, y = map(int, m[-1])
    w, h = min(w, info["w"] - x), min(h, info["h"] - y)
    w, h = w - w % 2, h - h % 2
    if w >= info["w"] - 8 and h >= info["h"] - 8:
        return None
    if w < info["w"] * 0.5 or h < info["h"] * 0.5:
        return None
    return (w, h, x, y)

# ------------------------------------------------------------------ rosto
def analyze_faces(src, t0, t1, fps, step=5, aw=960, crop=None):
    """Decodifica em baixa, mede corte de camera (todo frame) e rosto (1 a cada `step`)."""
    info = probe(src)
    if crop:
        info["w"], info["h"] = crop[0], crop[1]
    ah = int(round(info["h"] * aw / info["w"] / 2) * 2)
    det = cv2.FaceDetectorYN.create(ensure_face_model(), "", (aw, ah),
                                    score_threshold=0.62, nms_threshold=0.3, top_k=20)
    p = subprocess.Popen(["ffmpeg", "-hide_banner", "-loglevel", "error", "-ss", f"{t0:.4f}", "-i", src,
                          "-t", f"{t1 - t0:.4f}", "-vf", (f"crop={crop[0]}:{crop[1]}:{crop[2]}:{crop[3]}," if crop else "") + f"fps={fps},scale={aw}:{ah}", "-f", "rawvideo",
                          "-pix_fmt", "bgr24", "-"], stdout=subprocess.PIPE)
    fsz = aw * ah * 3
    faces, diffs = {}, []
    prev = None; i = 0
    while True:
        buf = p.stdout.read(fsz)
        if len(buf) < fsz:
            break
        img = np.frombuffer(buf, np.uint8).reshape(ah, aw, 3)
        th = cv2.resize(cv2.cvtColor(img, cv2.COLOR_BGR2GRAY), (64, 36), interpolation=cv2.INTER_AREA).astype(np.float32)
        diffs.append(0.0 if prev is None else float(np.abs(th - prev).mean()))
        prev = th
        if i % step == 0:
            _, fc = det.detect(img)
            lst = []
            if fc is not None:
                for f in fc:
                    x, y, w, h = f[:4]; sc = f[-1]
                    s = info["w"] / aw
                    lst.append((float((x + w / 2) * s), float((y + h / 2) * s), float(h * s), float(sc)))
            faces[i] = lst
        i += 1
    p.wait()
    diffs = np.array(diffs)
    # corte de camera: salto grande e isolado
    cuts = []
    if len(diffs) > 3:
        med = np.median(diffs) + 1e-6
        for k in range(1, len(diffs)):
            if diffs[k] > max(18.0, med * 6):
                cuts.append(k)
    return {"faces": faces, "cuts": cuts, "n": i, "src_w": info["w"], "src_h": info["h"]}

def choose_track(an, pick="largest"):
    """Escolhe um rosto por amostra mantendo continuidade."""
    track = {}
    prev = None
    for i in sorted(an["faces"]):
        lst = an["faces"][i]
        if not lst:
            continue
        if pick == "left":
            cand = min(lst, key=lambda f: f[0])
        elif pick == "right":
            cand = max(lst, key=lambda f: f[0])
        else:
            cand = max(lst, key=lambda f: f[2])
        if prev is not None and len(lst) > 1:
            near = min(lst, key=lambda f: (f[0] - prev[0]) ** 2 + (f[1] - prev[1]) ** 2)
            if abs(near[2] - cand[2]) < 0.25 * cand[2]:
                cand = near
        track[i] = cand
        prev = cand
    return track

# ------------------------------------------------------------------ foto de topo
class TopPhoto:
    def __init__(self, path, seam, focus=(0.5, 0.45), dur=60.0, kb=(1.0, 1.09), darken=0.0, grade=None):
        from PIL import ImageEnhance
        im = Image.open(path).convert("RGB")
        if grade:
            im = ImageEnhance.Contrast(im).enhance(grade[0])
            im = ImageEnhance.Color(im).enhance(grade[1])
        self.seam = seam
        zw, zh = W, seam
        s = max(zw / im.width, zh / im.height) * kb[1] * 1.02
        self.img = cv2.cvtColor(np.asarray(im.resize((int(im.width * s), int(im.height * s)), Image.LANCZOS)),
                                cv2.COLOR_RGB2BGR)
        if darken:
            self.img = (self.img.astype(np.float32) * (1 - darken)).astype(np.uint8)
        self.focus = focus; self.dur = max(dur, 1); self.kb = kb

    def frame(self, t):
        ih, iw = self.img.shape[:2]
        zw, zh = W, self.seam
        p = ease_in_out(t / self.dur)
        z = self.kb[0] + (self.kb[1] - self.kb[0]) * p
        base = max(zw / iw, zh / ih)
        sc = base * z
        # centro visivel caminha levemente em direcao ao foco
        fx, fy = self.focus
        cx = iw * (0.5 + (fx - 0.5) * (0.6 + 0.4 * p))
        cy = ih * (0.5 + (fy - 0.5) * (0.6 + 0.4 * p))
        vw, vh = zw / sc, zh / sc
        cx = min(max(cx, vw / 2), iw - vw / 2); cy = min(max(cy, vh / 2), ih - vh / 2)
        M = np.array([[sc, 0, zw / 2 - cx * sc], [0, sc, zh / 2 - cy * sc]], np.float32)
        return cv2.warpAffine(self.img, M, (zw, zh), flags=cv2.INTER_LINEAR, borderMode=cv2.BORDER_REFLECT)

class TopPanel:
    """Sem foto: painel azul JR com marca d'agua e brilho animado."""
    def __init__(self, seam, dur=60.0):
        self.seam = seam; self.dur = dur
        yy, xx = np.mgrid[0:seam, 0:W].astype(np.float32)
        g = (xx / W * 0.45 + yy / seam * 0.55)
        c0 = np.array(NAVY[::-1], np.float32); c1 = np.array(BLUE[::-1], np.float32)
        self.base = (c0 * (1 - g[..., None]) + c1 * g[..., None])
        wm = Image.open(os.path.join(ASSETS, "logo_jr_monograma.png")).convert("RGBA")
        wm = wm.crop(wm.getbbox()); s = 760 / wm.width
        wm = wm.resize((760, int(wm.height * s)), Image.LANCZOS)
        white = Image.new("RGBA", wm.size, (255, 255, 255, 255)); white.putalpha(wm.split()[3].point(lambda v: int(v * 0.07)))
        self.wm = Sprite(white)

    def frame(self, t):
        f = self.base.copy()
        # brilho diagonal lento
        ph = (t / 6.0) % 1.0
        f = np.clip(f, 0, 255).astype(np.uint8)
        blit(f, self.wm, W - self.wm.w + 120 - 40 * ph, 40)
        return f

# ------------------------------------------------------------------ reel
class Reel:
    def __init__(self, cfg):
        self.t_start = time.time()
        self.cfg = cfg
        L = self.L = dict(DEFAULT_LAYOUT); L.update(cfg.get("layout", {}))
        src = self.src = cfg["source"]; info = self.info = probe(src)
        fps = self.fps = cfg.get("fps") or pick_fps(info["fps"])
        t0 = self.t0 = float(cfg.get("t_in", 0.0)); t1 = self.t1 = float(cfg.get("t_out", info["dur"]))
        words = self.words = load_words(cfg, t0, t1)
        log(f"{len(words)} palavras, fps {fps:.3f}, fonte {info['w']}x{info['h']}")
        self.audio = load_audio(src, t0, t1, info["audio"])
        edl = self.edl = build_edl(words, self.audio, t0, t1, fps, cfg)
        tm = self.tm = TimeMap(edl, fps)
        dur = self.dur = tm.n_out / fps
        log(f"EDL: {len(edl)} trechos, {dur:.2f}s (de {t1 - t0:.2f}s)")

        # palavras em tempo de saida
        ow = self.ow = []
        for w in words:
            s_ = tm.src_time_to_out(w["s"] - t0); e_ = tm.src_time_to_out(w["e"] - t0)
            if e_ - s_ < 0.02:
                e_ = s_ + 0.08
            ow.append({"w": w["w"], "s": s_, "e": e_})
        self.cards = build_cards(ow, cfg.get("cap_words", 3), cfg.get("cap_chars", 18))
        self.caprender = CaptionRenderer(L, cfg.get("emphasis", []))
        emph = set(e.lower() for e in cfg.get("emphasis", []))
        self.emph_times = [w["s"] for w in ow if clean_word(w["w"]).lower() in emph]

        # rosto e cortes de camera
        crop = self.crop = cfg.get("crop") or detect_bars(src, t0, t1, info)
        if crop:
            log(f"tarja preta detectada, recorte {crop}")
        an = analyze_faces(src, t0, t1, fps, crop=crop)
        track = choose_track(an, cfg.get("speaker", {}).get("pick", "largest"))
        cam_cuts = an["cuts"]
        log(f"rostos em {len(track)} amostras, {len(cam_cuts)} cortes de camera na fonte")
        sw, sh = self.sw, self.sh = (crop[0], crop[1]) if crop else (info["w"], info["h"])
        ZW, ZH = self.ZW, self.ZH = W, H - L["seam"]

        tkeys = np.array(sorted(track)) if track else np.array([], int)
        cuts_arr = np.array(sorted(cam_cuts), int)
        seg_of = lambda i: int(np.searchsorted(cuts_arr, i, side="right"))
        def face_at(fa, fb, prev=None):
            sg = seg_of(fa)
            for margin in (0, int(fps * 0.6), int(fps * 1.5), int(fps * 4)):
                sel = [i for i in tkeys if fa - margin <= i <= fb + margin and seg_of(i) == sg] if len(tkeys) else []
                if sel:
                    a = np.array([track[i] for i in sel])
                    return (float(np.median(a[:, 0])), float(np.median(a[:, 1])), float(np.median(a[:, 2])))
            if prev is not None:
                return prev
            if len(tkeys):
                a = np.array(list(track.values()))
                return (float(np.median(a[:, 0])), float(np.median(a[:, 1])), float(np.median(a[:, 2])))
            return (sw / 2, sh * 0.4, sh * 0.25)

        # ---- planos: quebram em jump cut, corte de camera e frases longas
        zc = self.zc = cfg.get("zoom", {})
        z_wide = zc.get("wide", 1.0); z_tight = zc.get("tight", 1.30); max_shot = zc.get("max_shot", 4.8)
        bounds = set()
        for k in range(1, len(edl)):
            bounds.add(("jump", tm.cum[k]))
        def same_camera(c):
            """Corte na fonte com rosto do mesmo tamanho dos dois lados = emenda na mesma camera."""
            before = [track[i][2] for i in tkeys if c - fps * 2 <= i < c]
            after = [track[i][2] for i in tkeys if c <= i < c + fps * 2]
            if not before or not after:
                return False
            r = np.median(after) / np.median(before)
            return 0.8 < r < 1.25
        for c in cam_cuts:
            for k, (a, b) in enumerate(edl):
                if a < c < b:
                    bounds.add(("jump" if same_camera(c) else "cam", tm.cum[k] + (c - a)))
        for tt in cfg.get("force_cuts", []):      # quebras manuais (tempo de saida, s)
            bounds.add(("jump", int(round(tt * fps))))
        sent_ends = [int(round(w["e"] * fps)) for w in ow if w["w"].strip()[-1:] in ".!?"]
        comma_ends = [int(round(w["e"] * fps)) for w in ow if w["w"].strip()[-1:] in ",;:"]
        b_sorted = sorted(set(b for _, b in bounds if 0 < b < tm.n_out) | {0, tm.n_out})
        shots = []
        for a, b in zip(b_sorted[:-1], b_sorted[1:]):
            segs = [(a, b)]
            changed = True
            while changed:
                changed = False
                new = []
                for x, y in segs:
                    if (y - x) / fps > max_shot:
                        mid = (x + y) / 2
                        cands = [e for e in sent_ends if x + fps * 1.2 < e < y - fps * 1.2] or \
                                [e for e in comma_ends if x + fps * 1.2 < e < y - fps * 1.2] or \
                                [int(round(w["s"] * fps)) for w in ow if x + fps * 1.5 < w["s"] * fps < y - fps * 1.5]
                        if cands:
                            c = min(cands, key=lambda e: abs(e - mid))
                            new += [(x, c), (c, y)]; changed = True; continue
                    new.append((x, y))
                segs = new
            shots += segs
        kinds = {b: k for k, b in bounds}
        zoom_seq = []
        level = 1 if zc.get("start", "tight") == "tight" else 0
        for i, (a, b) in enumerate(shots):
            if i > 0 and kinds.get(a) != "cam":
                level = 1 - level
            zoom_seq.append(level)
        self.plan = []
        cw0 = min(sw, sh * ZW / ZH); s0 = ZW / cw0
        max_up = zc.get("max_upscale", 1.75)
        face_wide = zc.get("face_wide", 250); face_tight = zc.get("face_tight", 330)
        prev = None
        for (a, b), lv in zip(shots, zoom_seq):
            fa = tm.out_frame_to_src(a)[0]; fb = tm.out_frame_to_src(max(a, b - 1))[0]
            fx, fy, fh = face_at(fa, fb, prev)
            prev = (fx, fy, fh)
            zw_ = min(max(z_wide, face_wide / max(fh * s0, 1)), max_up / s0)
            zt_ = min(max(z_tight * zw_ / max(z_wide, 1e-6), face_tight / max(fh * s0, 1)), max_up / s0)
            self.plan.append({"a": a, "b": b, "z": max(zt_ if lv else zw_, 1.0), "face": (fx, fy, fh), "lv": lv})
        log(f"{len(self.plan)} planos")
        self._build_layers()

    # ---------------------------------------------------------- camadas fixas
    def _build_layers(self):
        cfg, L = self.cfg, self.L
        seam = L["seam"]
        photo = cfg.get("photo")
        self.top = TopPhoto(photo, seam, tuple(cfg.get("photo_focus", (0.5, 0.45))), self.dur,
                            darken=cfg.get("photo_darken", 0.0), grade=cfg.get("photo_grade")) if photo else TopPanel(seam, self.dur)
        ga = np.zeros((seam, W), np.float32)
        yy = np.arange(seam, dtype=np.float32)
        ga[:] = (np.clip((yy - seam * 0.38) / (seam * 0.62), 0, 1) ** 1.3 * cfg.get("grad", 0.62))[:, None]
        ga[:] += (np.clip(1 - yy / 260, 0, 1) ** 2 * 0.35)[:, None]
        g_img = np.zeros((seam, W, 4), np.uint8); g_img[..., 3] = (np.clip(ga, 0, 1) * 255).astype(np.uint8)
        self.grad_sp = Sprite(Image.fromarray(g_img, "RGBA"))
        sh_img = np.zeros((70, W, 4), np.uint8)
        sh_img[..., 3] = (np.linspace(0.45, 0, 70) ** 1.5 * 255)[:, None].astype(np.uint8)
        self.seam_shadow = Sprite(Image.fromarray(sh_img, "RGBA"))

        lines = headline_lines(cfg, L)
        self.head = []
        y = L["head_bottom"] - len(lines) * L["head_line_h"]
        head_top = y
        for ln in lines:
            box, txt = render_headline_line(ln, L)
            self.head.append({"txt": Sprite(txt), "x": (W - box.width) // 2, "y": y, "w": box.width})
            y += L["head_line_h"]
        logo = logo_white(L["logo_h"])
        logo_s, lpad = shadowed(logo, blur=10, offset=(0, 3), opacity=0.45)
        self.logo_sp = Sprite(logo_s)
        self.logo_xy = ((W - logo.width) // 2 - lpad, head_top - L["logo_gap"] - L["logo_h"] - lpad)

        self.credit_sp = None
        if cfg.get("photo_credit"):
            f = font("Montserrat-700", 21)
            tw = int(f.getlength(cfg["photo_credit"])) + 4
            ci = Image.new("RGBA", (tw, 30), (0, 0, 0, 0))
            ImageDraw.Draw(ci).text((2, 2), cfg["photo_credit"], font=f, fill=(255, 255, 255, 220))
            cs, cpad = shadowed(ci, blur=3, offset=(0, 1), opacity=0.75, pad=8)
            self.credit_sp = Sprite(cs)
            self.credit_xy = (W - 34 - ci.width - cpad, self.logo_xy[1] + lpad - 46 - cpad)

        self.tag = None
        if cfg.get("name"):
            fn = font("Montserrat-800", 40); fr = font("Montserrat-700", 29)
            name = cfg["name"].upper(); role = cfg.get("role", "")
            nw = int(fn.getlength(name)) + 44
            rw = int(fr.getlength(role)) + 32 if role else 0
            ti = Image.new("RGBA", (max(nw, rw) + 4, 66 + (50 if role else 0)), (0, 0, 0, 0))
            dd = ImageDraw.Draw(ti)
            dd.rectangle([0, 0, nw, 66], fill=WHITE + (255,))
            dd.rectangle([0, 0, 9, 66], fill=BLUE + (255,))
            dd.text((26, 10), name, font=fn, fill=NAVY)
            if role:
                dd.rectangle([0, 66, rw, 116], fill=BLUE + (255,))
                dd.text((16, 74), role, font=fr, fill=WHITE)
            ts, tpad = shadowed(ti, blur=10, offset=(0, 4), opacity=0.35)
            self.tag = {"sp": Sprite(ts), "x": 36 - tpad, "y": L["head_bottom"] + 36 - tpad,
                        "t0": cfg.get("name_t0", 1.0), "t1": cfg.get("name_t1", 5.0)}

    # ---------------------------------------------------------- video de origem
    def vf(self):
        crop, grade = self.crop, self.cfg.get("grade", {"contrast": 1.04, "saturation": 1.08, "sharpen": 0.35})
        vf = ([f"crop={crop[0]}:{crop[1]}:{crop[2]}:{crop[3]}"] if crop else []) + [f"fps={self.fps}"]
        if grade:
            vf.append(f"eq=contrast={grade.get('contrast', 1)}:saturation={grade.get('saturation', 1)}:gamma={grade.get('gamma', 1)}")
            if grade.get("sharpen"):
                vf.append(f"unsharp=5:5:{grade['sharpen']}:5:5:0")
        return ",".join(vf)

    def grab(self, src_frame):
        """Um frame de origem (indice relativo a t_in)."""
        t = self.t0 + src_frame / self.fps
        raw = subprocess.run(["ffmpeg", "-hide_banner", "-loglevel", "error", "-ss", f"{max(0, t - 2):.4f}", "-i", self.src,
                              "-ss", f"{min(2, t):.4f}", "-vf", self.vf(), "-frames:v", "1", "-f", "rawvideo",
                              "-pix_fmt", "bgr24", "-"], capture_output=True, check=True).stdout
        return np.frombuffer(raw[:self.sw * self.sh * 3], np.uint8).reshape(self.sh, self.sw, 3)

    # ---------------------------------------------------------- composicao
    def plan_at(self, n):
        for P in self.plan:
            if P["a"] <= n < P["b"]:
                return P
        return self.plan[-1]

    def zone(self, frame, n, P, intro=True):
        t = n / self.fps
        zc, L = self.zc, self.L
        prog = (n - P["a"]) / max(1, P["b"] - P["a"])
        z = P["z"] * (1 + zc.get("push", 0.035) * ease_in_out(prog))
        if intro and zc.get("intro_punch", 0.10) and t < 0.55:          # entrada: zoom que assenta
            z *= 1 + zc.get("intro_punch", 0.10) * (1 - ease_out_cubic(t / 0.55))
        if self.cfg.get("punch", True) and self.emph_times:              # punch nas palavras de enfase
            bump = 0.0
            for te in self.emph_times:
                d = t - te
                if -0.1 < d < 0.9:
                    bump = max(bump, ease_out_cubic((d + 0.1) / 0.14) * (1 - ease_in_out((d - 0.25) / 0.6)))
            z *= 1 + zc.get("punch_amt", 0.06) * bump
        sw, sh, ZW, ZH = self.sw, self.sh, self.ZW, self.ZH
        cw0 = min(sw, sh * ZW / ZH); ch0 = cw0 * ZH / ZW
        cw, ch = cw0 / z, ch0 / z
        fx, fy, fh = P["face"]
        fyr = L.get("face_y_tight", L["face_y"] + 0.04) if P.get("lv") else L["face_y"]
        x0 = min(max(fx - cw / 2, 0), sw - cw); y0 = min(max(fy - fyr * ch, 0), sh - ch)
        s = ZW / cw
        M = np.array([[s, 0, -x0 * s], [0, s, -y0 * s]], np.float32)
        return cv2.warpAffine(frame, M, (ZW, ZH), flags=cv2.INTER_AREA if s < 1 else cv2.INTER_CUBIC,
                              borderMode=cv2.BORDER_REPLICATE)

    def compose(self, n, frame, canvas, captions=True, intro=True, bar=True):
        L, fps = self.L, self.fps
        t = n / fps
        seam = L["seam"]
        P = self.plan_at(n)
        canvas[seam:] = self.zone(frame, n, P, intro)
        canvas[:seam] = self.top.frame(t)
        blit(canvas, self.grad_sp, 0, 0)
        blit(canvas, self.seam_shadow, 0, seam)
        if bar:
            bh = L["bar_h"]; by = seam - bh // 2
            pw = int(round(W * (n + 1) / self.tm.n_out))
            canvas[by:by + bh] = (canvas[by:by + bh].astype(np.float32) * 0.55 + 255 * 0.45).astype(np.uint8)
            canvas[by:by + bh, :pw] = BLUE_LIGHT[::-1]
        ti = t if intro else 99.0
        la = ease_out_cubic(ti / 0.35)
        blit(canvas, self.logo_sp, self.logo_xy[0], self.logo_xy[1] - 18 * (1 - la), la)
        if self.credit_sp is not None:
            blit(canvas, self.credit_sp, self.credit_xy[0], self.credit_xy[1], ease_out_cubic((ti - 0.3) / 0.4))
        for i, hl in enumerate(self.head):
            st = 0.06 + 0.07 * i
            p = ease_out_cubic((ti - st) / 0.30)
            if p <= 0:
                continue
            bw = max(2, int(hl["w"] * p))
            bx = hl["x"] + (hl["w"] - bw) // 2
            canvas[hl["y"]:hl["y"] + L["head_line_h"], bx:bx + bw] = BLUE[::-1]
            tp = ease_out_cubic((ti - st - 0.10) / 0.24)
            if tp > 0:
                blit(canvas, hl["txt"], hl["x"], hl["y"] + 8 * (1 - tp), tp, clip=(bx, bx + bw))
        tag = self.tag
        if captions and tag and tag["t0"] <= t <= tag["t1"] + 0.3:
            pin = ease_out_cubic((t - tag["t0"]) / 0.35); pout = ease_out_cubic((t - tag["t1"]) / 0.3)
            blit(canvas, tag["sp"], tag["x"] - tag["sp"].w * (1 - pin) - tag["sp"].w * pout, tag["y"])
        if captions and self.cards:
            ci = 0
            while ci < len(self.cards) - 1 and t >= self.cards[ci + 1][0]["s"]:
                ci += 1
            c = self.cards[ci]
            if t >= c[0]["s"] - 0.02:
                nxt = self.cards[ci + 1][0]["s"] if ci + 1 < len(self.cards) else self.dur + 1
                if t < min(nxt, c[-1]["e"] + 0.6):
                    sp = self.caprender.sprite(c)
                    pp = (t - c[0]["s"]) / 0.10
                    sc_ = 0.92 + 0.08 * ease_out_cubic(pp) if pp < 1 else 1.0
                    sp2 = sp.scaled(sc_) if sc_ != 1.0 else sp
                    blit(canvas, sp2, (W - sp2.w) / 2, L["cap_y"] - sp2.h / 2, min(1.0, max(0.0, pp * 1.6)))
        return canvas

    # ---------------------------------------------------------- saidas
    def render(self):
        cfg, fps, tm = self.cfg, self.fps, self.tm
        out = cfg["out"]
        os.makedirs(os.path.dirname(os.path.abspath(out)), exist_ok=True)
        dec = subprocess.Popen(["ffmpeg", "-hide_banner", "-loglevel", "error", "-ss", f"{self.t0:.4f}", "-i", self.src,
                                "-t", f"{self.t1 - self.t0:.4f}", "-vf", self.vf(), "-f", "rawvideo", "-pix_fmt", "bgr24", "-"],
                               stdout=subprocess.PIPE)
        tmp_v = out + ".v.mp4"
        enc = subprocess.Popen(["ffmpeg", "-hide_banner", "-loglevel", "error", "-y", "-f", "rawvideo", "-pix_fmt", "bgr24",
                                "-s", f"{W}x{H}", "-r", f"{fps}", "-i", "-", "-c:v", "libx264", "-preset",
                                cfg.get("preset", "medium"), "-crf", str(cfg.get("crf", 18)), "-profile:v", "high",
                                "-pix_fmt", "yuv420p", "-g", str(int(round(fps * 2))), "-bf", "2",
                                "-color_primaries", "bt709", "-color_trc", "bt709", "-colorspace", "bt709", tmp_v],
                               stdin=subprocess.PIPE)
        fsz = self.sw * self.sh * 3
        src_idx, frame = -1, None
        canvas = np.zeros((H, W, 3), np.uint8)
        for n in range(tm.n_out):
            need, _ = tm.out_frame_to_src(n)
            while src_idx < need:
                buf = dec.stdout.read(fsz)
                if len(buf) < fsz:
                    break
                frame = np.frombuffer(buf, np.uint8).reshape(self.sh, self.sw, 3)
                src_idx += 1
            self.compose(n, frame, canvas)
            enc.stdin.write(canvas.tobytes())
            if n % int(fps * 10) == 0:
                log(f"  frame {n}/{tm.n_out} ({n / max(1, time.time() - self.t_start):.1f} fps)")
        enc.stdin.close(); enc.wait()
        dec.stdout.close(); dec.kill()
        self._audio_mux(tmp_v, out)
        log(f"OK {out}  {self.dur:.2f}s  em {time.time() - self.t_start:.1f}s")
        return out

    def _audio_mux(self, tmp_v, out):
        cfg, fps, edl = self.cfg, self.fps, self.edl
        parts = []
        fade = int(SR * 0.012)
        for a, b in edl:
            seg = self.audio[int(round(a / fps * SR)):int(round(b / fps * SR))].copy()
            if len(seg) > 2 * fade:
                ramp = np.linspace(0, 1, fade, dtype=np.float32)[:, None]
                seg[:fade] *= ramp; seg[-fade:] *= ramp[::-1]
            parts.append(seg)
        voice = np.concatenate(parts) if parts else np.zeros((1, 2), np.float32)
        need_len = int(round(self.tm.n_out / fps * SR))
        if len(voice) < need_len:
            voice = np.vstack([voice, np.zeros((need_len - len(voice), 2), np.float32)])
        voice = voice[:need_len]
        wa, wb = out + ".a.wav", out + ".b.wav"
        write_wav(wa, voice)
        process_voice(wa, wb, cfg.get("lufs", -14.0))
        mix = read_wav(wb)[:need_len]
        if len(mix) < need_len:
            mix = np.vstack([mix, np.zeros((need_len - len(mix), 2), np.float32)])
        if cfg.get("sfx", True):
            wh = whoosh(peak_db=cfg.get("sfx_db", -19.0))
            mix[:len(wh)] += wh[:len(mix)]
        mix = np.clip(mix, -0.98, 0.98)
        write_wav(wa, mix)
        subprocess.run(["ffmpeg", "-hide_banner", "-loglevel", "error", "-y", "-i", tmp_v, "-i", wa, "-map", "0:v",
                        "-map", "1:a", "-c:v", "copy", "-c:a", "aac", "-b:a", "192k", "-ar", "48000", "-shortest",
                        "-movflags", "+faststart", out], check=True)
        for f in (tmp_v, wa, wb):
            try: os.remove(f)
            except OSError: pass

    def cover(self, path, t=None):
        """Capa 1080x1920: titulo completo, sem legenda. t = segundo do corte (padrao: melhor plano fechado)."""
        fps = self.fps
        if t is None:
            cands = [P for P in self.plan if P["lv"] == 1 and P["a"] < self.tm.n_out * 0.5] or self.plan
            P = max(cands, key=lambda P: P["face"][2] * min(1.0, (P["b"] - P["a"]) / fps / 2))
            n = (P["a"] + P["b"]) // 2
        else:
            n = int(round(t * fps))
        src_n, _ = self.tm.out_frame_to_src(n)
        frame = self.grab(src_n)
        canvas = np.zeros((H, W, 3), np.uint8)
        self.compose(n, frame, canvas, captions=False, intro=False, bar=False)
        Image.fromarray(canvas[..., ::-1]).save(path)
        return path

def render(cfg):
    r = Reel(cfg)
    out = r.render()
    if cfg.get("cover", True):
        cp = os.path.splitext(out)[0] + "_capa.png"
        r.cover(cp, cfg.get("cover_t"))
        log(f"capa {cp}")
    return {"out": out, "dur": r.dur, "edl": r.edl, "plan": r.plan, "cards": len(r.cards)}

if __name__ == "__main__":
    cfg = json.load(open(sys.argv[1]))
    base = os.path.dirname(os.path.abspath(sys.argv[1]))
    for k in ("source", "words", "photo", "out"):
        if cfg.get(k) and not os.path.isabs(cfg[k]):
            cfg[k] = os.path.join(base, cfg[k])
    r = render(cfg)
    print(json.dumps({"out": r["out"], "dur": round(r["dur"], 2), "shots": len(r["plan"]), "cards": r["cards"]}))
