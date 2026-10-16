FROM python:3.12-slim-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends \
      tesseract-ocr tesseract-ocr-spa ocrmypdf ghostscript unpaper \
      wspanish fonts-dejavu-core \
      curl ca-certificates \
    && rm -rf /var/lib/apt/lists/*
RUN pip install --no-cache-dir psycopg[binary] numpy pillow reportlab onnxruntime tokenizers rapidfuzz pypdf
ENV OMP_THREAD_LIMIT=1
WORKDIR /work
CMD ["sleep", "infinity"]
