# Loader and query generator for the S7 load test (python, numpy, psycopg, the Spanish word list the S0 text model needs).
FROM python:3.12-slim-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends wspanish fonts-dejavu-core && rm -rf /var/lib/apt/lists/*
RUN pip install --no-cache-dir "psycopg[binary]==3.2.*" numpy==2.5.4 pillow==13.0.0
