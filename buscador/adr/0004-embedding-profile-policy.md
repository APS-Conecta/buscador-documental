# ADR-0004 — Hardware-agnostic embedding profile: auto-recommend, admin-editable

Status: accepted (2026-10-15)
Affects: preflight, `profiles.yaml`, `embedding_profile`, `occ buscador_documental:profile`

## Context
The product runs on varied hardware (this dev box is CPU-only, others may have GPUs). Automatic switching after install could trigger a multi-day re-embed (flapping).

## Decision
A shipped, versioned `profiles.yaml` lists profiles (model, dim, quantization, prefixes, max tokens, threads, batch). At first install the preflight measures cores, RAM, AVX2/AVX512, optional GPU and real embedding throughput, and recommends one. Each profile declares eligibility rules (minimum measured tokens/s and RAM); the preflight never recommends an ineligible one. The choice is stored in `embedding_profile` and editable by an admin (an override below eligibility shows a warning) (settings or `occ`). Later preflight runs only warn. A profile change creates generation N+1 (`chunk_vec_<N+1>`); keyword search is unaffected; the switch happens when coverage reaches the threshold. Preflight does not depend on Ollama.

## Consequences
GPU support is a profile entry, not a code path. Model bundles are pinned and checksummed; no runtime downloads.
