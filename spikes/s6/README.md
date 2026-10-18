# S6 spike: open a PDF on a given page from a search result

Question (blueprint phase 8, ADR-0016): can a link from the result list make the Files PDF viewer show page N?
Method: `pdf_page.mjs` (Playwright 1.56.1 in the pinned image, against the `bd-s1` stack, Nextcloud 34.0.4, files_pdfviewer 7.0.0)
opens a three-page PDF through several URL forms and reads the page number pdf.js reports (`#pageNumber`).

Result (`results-s6/pdf_page.json`): `/f/<id>#page=3` and `/f/<id>?page=3` both open the viewer on page 1. The outer URL's hash and
query are not forwarded to the viewer iframe, whose `src` is `/apps/files_pdfviewer/?file=<dav url>` in every case. Calling the
viewer URL directly with `#page=3` is not an option: it is not a stable entry point (it redirected to the dashboard).

Decision: fallback of ADR-0016. The result opens the document (`/f/<id>`) and the page is stated in words: the card shows "p. N",
the details panel says "La coincidencia está en la página N", and the button reads "Abrir documento (página N)". The page deep link
is revisited when Nextcloud forwards the fragment; nothing else in the app depends on it.

Re-run: `FILE_ID=<fileid> docker run --rm --network host -e FILE_ID -v "$PWD":/work mcr.microsoft.com/playwright:v1.56.1-noble@sha256:f1e7e01021efd65dd1a2c56064be399f3e4de00fd021ac561325f2bfbb2b837a sh -c 'cd /tmp && npm i playwright@1.56.1 >/dev/null && cp /work/pdf_page.mjs . && node pdf_page.mjs'`
(the stack needs `occ app:disable firstrunwizard` and the file in `admin/files/Docs/`; `tres-paginas.pdf` is the fixture).
