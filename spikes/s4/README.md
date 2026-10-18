# S4 evaluation (spike, not product)

`make -C app eval-search [EVAL_PROFILE=bge-m3 EVAL_MODEL=bge-m3:567m]`

1. `compose.yaml` starts a throwaway Ollama (project `bd-s4-ollama`, no published port) and pulls the model on first use.
2. `topics.py` holds 27 Spanish APS topics with four documents each (full terms, acronyms, two paraphrases) and four queries each
   (lexical, acronym, paraphrase, accent-free); `corpus.py` adds 60 filler documents. Synthetic text, no real data.
3. `embed_corpus.py` embeds everything with the product's own worker client (`worker/embed.py`) and the profile's prefixes.
4. `app/tests/eval/EvalSearchTest.php` loads the vectors into a scratch database and runs the product's SQL, glossary expansion
   and fusion: recall@10, MRR and nDCG@10 per mode and query kind, the filtered ANN-vs-exact check, and the SQL-stage latency.

Results: `results-s4/eval_search_<profile>.json`. Stop the model server with `docker compose -f spikes/s4/compose.yaml down`
(the model volume `bd-s4-ollama_bd_s4_models` stays; remove it with `down -v`).

Limits: 168 documents and 108 queries written by the same hand as the product's glossary rules; quality numbers are indicative
and say nothing about the head-term pre-limit, 1M-page latency or filtered recall at scale (S7, with real documents).
