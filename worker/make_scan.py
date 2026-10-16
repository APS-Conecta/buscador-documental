# SPDX-License-Identifier: AGPL-3.0-or-later
"""Makes an image-only (scanned-looking) one-page PDF with known Spanish text, for the S1 end-to-end test."""
import sys

from PIL import Image, ImageDraw, ImageFont

LINES = [
    "Informe de laboratorio",
    "",
    "Hemoglobina glicosilada 6,8 por ciento.",
    "La paciente fue derivada a nutricion y control en tres meses.",
]

img = Image.new("L", (1654, 2339), 255)          # A4 at 200 dpi
draw = ImageDraw.Draw(img)
font = ImageFont.truetype("/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf", 44)
y = 200
for line in LINES:
    draw.text((150, y), line, font=font, fill=0)
    y += 90
img.save(sys.argv[1], "PDF", resolution=200.0)
