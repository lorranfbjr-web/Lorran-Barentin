# compara duas transcricoes (turbo x v3) palavra a palavra e lista divergencias
import json, sys, difflib, re
def words(p):
    j = json.load(open(p)); out = []
    for s in j["segments"]:
        for w in s["words"]:
            out.append((w["w"].strip(), w["s"]))
    return out
a, b = words(sys.argv[1]), words(sys.argv[2])
na = [re.sub(r"[^\wÀ-ú]", "", x[0].lower()) for x in a]
nb = [re.sub(r"[^\wÀ-ú]", "", x[0].lower()) for x in b]
sm = difflib.SequenceMatcher(a=na, b=nb, autojunk=False)
for op, i1, i2, j1, j2 in sm.get_opcodes():
    if op == "equal": continue
    ta = " ".join(x[0] for x in a[i1:i2]); tb = " ".join(x[0] for x in b[j1:j2])
    t = a[i1][1] if i1 < len(a) else b[j1][1]
    print(f"{t:7.2f}  TURBO: {ta!r:45}  V3: {tb!r}")
