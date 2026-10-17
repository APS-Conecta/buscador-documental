# Python for the head-term re-run: the S0 worker's base and word list (wspanish feeds the synthetic vocabulary, so it must be
# the same Debian bookworm package) with psycopg, numpy, pillow and reportlab (gen_corpus imports them); no OCR, no models.
FROM python:3.12-slim-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends wspanish && rm -rf /var/lib/apt/lists/*
RUN pip install --no-cache-dir "psycopg[binary]" numpy pillow reportlab
WORKDIR /work
