# ADR-0014 — Embeddings come from an admin-configured Ollama endpoint

Status: accepted (2026-10-18)
Affects: ADR-0004, `profiles.yaml`, `embedding_profile`, vec lane, `EmbedClient`, `occ buscador:profile`, 01-architecture sections 6 and 7
Supersedes: the part of ADR-0004 and architecture section 7 that ships pinned ONNX model bundles

## Context
ADR-0004 assumed shipped, checksummed ONNX bundles run inside our containers. The product owner decided (2026-10-17) that release 1 ships no model and targets an Ollama endpoint. multilingual-e5, the family measured in S0, is not in the Ollama library, so the S0 vector figures are indicative only. Ollama has no authentication and its `/api/embed` silently truncates over-long input unless told not to.

## Decision
- A profile names an Ollama model tag, its dimension, query and passage prefixes and `max_tokens`. The admin pulls the model; the app never downloads one.
- On `occ buscador:profile set` the app reads the model digest and dimension from the endpoint and stores them in `embedding_profile`. The worker refuses to write vectors if the digest later differs (`model_mismatch`).
- Requests use `truncate:false` and an explicit `num_ctx`; chunks that do not fit fail loudly.
- The endpoint URL and token are app configuration (token sensitive) and worker environment, not database rows. A non-loopback endpoint should be HTTPS behind an authenticating proxy that exposes only `/api/embed`, `/api/tags` and `/api/show`.
- The Nextcloud local-address block is lifted per request, only for the configured host.
- First catalogue entries: `bge-m3` (1024-d, no prefixes, 8K context) and `qwen3-embedding:0.6b`. The ONNX `small-cpu` profile becomes a cut, brought back if a clinic cannot run Ollama.
- A profile change builds generation N+1 and is activated only by an admin (ADR-0004 unchanged on this point).

## Consequences
- Text chunks leave the Nextcloud host for the endpoint; for a remote endpoint this is a disclosure the DPIA must cover (S7 legal gate).
- Search quality depends on the model the admin chose; the eval report states the model and digest it measured.
- Reproducibility relies on the recorded digest, not on a checksummed bundle.
