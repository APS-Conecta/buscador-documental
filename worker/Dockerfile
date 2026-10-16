# S1 worker: OCR (ocrmypdf + tesseract spa/eng), HTTP client for the app's worker channel, psycopg as the bd_worker role.
# Base image as in the S0 harness; tool versions are recorded in every write_result (ocr_ver).
FROM python:3.12-slim-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends \
      tesseract-ocr tesseract-ocr-spa tesseract-ocr-eng ocrmypdf ghostscript unpaper fonts-dejavu-core \
      curl ca-certificates \
    && rm -rf /var/lib/apt/lists/*
RUN pip install --no-cache-dir "psycopg[binary]==3.2.*" requests PyYAML pypdf pytest pillow reportlab
ENV OMP_THREAD_LIMIT=1 PYTHONUNBUFFERED=1
WORKDIR /worker
COPY . /worker
CMD ["python3", "main.py"]
