# folha de contato de um video renderizado: python3 qa_sheet.py VIDEO SAIDA.png t1 t2 ...
import os, subprocess, sys
import numpy as np
from PIL import Image, ImageDraw, ImageFont
vid, out = sys.argv[1], sys.argv[2]
ts = [float(x) for x in sys.argv[3:]] or [0.05, 0.2, 0.4, 1.5, 4, 8, 15, 25, 35, 50]
tw = 360; th = 640
cols = min(5, len(ts)); rows = (len(ts) + cols - 1) // cols
sheet = Image.new("RGB", (cols * tw, rows * (th + 26)), (30, 30, 30))
f = ImageFont.truetype(os.path.join(os.path.dirname(os.path.abspath(__file__)), "assets", "fonts", "Montserrat-700.ttf"), 18)
for i, t in enumerate(ts):
    raw = subprocess.run(["ffmpeg", "-hide_banner", "-loglevel", "error", "-ss", f"{t}", "-i", vid, "-frames:v", "1",
                          "-vf", f"scale={tw}:{th}", "-f", "rawvideo", "-pix_fmt", "rgb24", "-"], capture_output=True).stdout
    if len(raw) < tw * th * 3: continue
    im = Image.fromarray(np.frombuffer(raw, np.uint8).reshape(th, tw, 3))
    x, y = (i % cols) * tw, (i // cols) * (th + 26)
    sheet.paste(im, (x, y + 26))
    ImageDraw.Draw(sheet).text((x + 8, y + 3), f"{t:.2f}s", font=f, fill=(255, 255, 0))
sheet.save(out)
print(out)
