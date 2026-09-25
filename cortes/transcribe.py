# -*- coding: utf-8 -*-
"""Transcricao com timestamps por palavra (faster-whisper).
uso: python3 transcribe.py VIDEO SAIDA.json [--model large-v3] [--start S --end E]
"""
import argparse, json, os, subprocess, sys, time, tempfile

def extract_wav(src, start=None, end=None):
    fd, wav = tempfile.mkstemp(suffix=".wav"); os.close(fd)
    cmd = ["ffmpeg", "-hide_banner", "-loglevel", "error", "-y"]
    if start is not None: cmd += ["-ss", f"{start:.3f}"]
    cmd += ["-i", src]
    if end is not None: cmd += ["-t", f"{(end - (start or 0)):.3f}"]
    cmd += ["-vn", "-ac", "1", "-ar", "16000", "-c:a", "pcm_s16le", wav]
    subprocess.run(cmd, check=True)
    return wav

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("src"); ap.add_argument("out")
    ap.add_argument("--model", default="large-v3")
    ap.add_argument("--lang", default="pt")
    ap.add_argument("--start", type=float); ap.add_argument("--end", type=float)
    ap.add_argument("--prompt", default="Entrevista em português do Brasil ao Jornal Razão, de Santa Catarina.")
    a = ap.parse_args()
    from faster_whisper import WhisperModel
    t0 = time.time()
    wav = extract_wav(a.src, a.start, a.end)
    m = WhisperModel(a.model, device="cpu", compute_type="int8", cpu_threads=os.cpu_count())
    segs, info = m.transcribe(wav, language=a.lang, word_timestamps=True, beam_size=5,
                              vad_filter=True, vad_parameters={"min_silence_duration_ms": 300},
                              initial_prompt=a.prompt, condition_on_previous_text=False)
    off = a.start or 0.0
    out = {"src": os.path.abspath(a.src), "offset": off, "model": a.model, "segments": []}
    for s in segs:
        out["segments"].append({
            "start": round(s.start + off, 3), "end": round(s.end + off, 3), "text": s.text.strip(),
            "words": [{"w": w.word.strip(), "s": round(w.start + off, 3), "e": round(w.end + off, 3),
                       "p": round(w.probability, 3)} for w in (s.words or [])]})
        print(f"[{s.start + off:7.2f} -> {s.end + off:7.2f}] {s.text.strip()}", flush=True)
    os.remove(wav)
    json.dump(out, open(a.out, "w"), ensure_ascii=False, indent=1)
    print(f"# {a.model}: {time.time() - t0:.1f}s", file=sys.stderr)

if __name__ == "__main__":
    main()
