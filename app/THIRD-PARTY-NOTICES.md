# Third-party notices

Buscador Documental is AGPL-3.0-or-later (see `LICENSE`). It uses, and does not modify, the following components. Each keeps its own licence; versions are the ones pinned in `compose.yaml`, `worker/Dockerfile`, `app/composer.lock` and `app/package-lock.json`.

| Component | Used for | Licence |
|---|---|---|
| Nextcloud server 34 | host application | AGPL-3.0-or-later |
| `@nextcloud/vue`, `@nextcloud/*` libraries | interface components | AGPL-3.0-or-later / GPL-3.0 (see each package) |
| Vue 3 | interface | MIT |
| PostgreSQL 18 | index database | PostgreSQL Licence |
| pgvector | vector index | PostgreSQL Licence |
| Tesseract OCR, `tesseract-ocr-spa`, `tesseract-ocr-eng` | reading scanned documents | Apache-2.0 |
| OCRmyPDF, Ghostscript, unpaper | PDF preparation for OCR | MPL-2.0, AGPL-3.0, GPL-2.0 (separate processes in the worker image) |
| pypdf, pikepdf, openpyxl, defusedxml, PyYAML, requests, psycopg | document parsing and worker | BSD-3-Clause, MPL-2.0, MIT, PSF |
| Ollama (administrator's own endpoint) | optional meaning search | MIT |
| `bge-m3` (BAAI) and `qwen3-embedding` (Alibaba) models | optional meaning search, downloaded by the administrator's Ollama, not shipped | MIT / Apache-2.0 |
| Symfony Console, PHPUnit, Playwright, axe-core, Vitest | development and tests only, not in the release tarball | MIT, BSD-3-Clause, Apache-2.0, MPL-2.0 |
| Chilean APS glossary (`buscador/data/aps-glosario-chile.md`, `app/lib/Search/glossary.json`) | query expansion | supplied by the project owner for this app |

Docker base images (Nextcloud, Postgres with pgvector, Python, Node, Playwright) carry their own licences and are pulled by the administrator or CI, not redistributed in the tarball.
