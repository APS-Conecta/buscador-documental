# Brief for counsel (draft)

It covers two apps of the suite, reviewed together: Buscador Documental first, then «IA».

## What the system is
Buscador Documental is a Nextcloud app for the staff of a primary-care establishment. It reads the text of the documents in folders the administrator includes (including scanned documents, by text recognition) and lets each person search only what they can already open in Nextcloud. It is installed on the establishment's own server.

## What it does with personal data
- **Stores**: the text of included documents (page text, chunks, optionally numeric vectors made from the text), file names and paths, owner names, and for each file the list of users, groups and shares allowed to read it. An administrator audit log keeps pseudonymised identifiers for administrative actions.
- **Does not send text out of the server**, unless the administrator points the optional meaning-search service at another machine (a deliberate exception; the default test setup is a local service). The reading process runs on a network with no route to the internet; the sandbox that opens documents has no network at all.
- **Permissions**: every result is checked again against Nextcloud's live permissions before it is shown; a document shared without download permission appears by title only; the tag `rag-excluded` on a file or folder removes it from the index (not just hides it).
- **Erasure**: deleting a user account removes everything read from that person's files; trashing a file removes its text immediately; a periodic reconciliation repairs missed events.
- **Scope**: by default only folders the administrator includes ("opt-in"); a mode that reads everything readable exists but is off, and is only selectable after the decisions below.
- **Backups**: encrypted, contain the page text; restoring an old backup can bring back text erased since (a documented procedure re-applies erasures).

## Questions we need answered
1. Is indexing the text of staff documents lawful under Ley 21.719 and Ley 20.584 for this establishment, and on what legal basis? Does the answer depend on whether clinical records can be present in the included folders (the organisation's policy says they should not be)?
2. What retention applies to (a) the index and vectors, (b) the administrator audit log (we propose 90 days), (c) backups?
3. Is an opt-in folder list enough, or must each team or person be informed or consent before their folder is included?
4. May the "all readable" mode ever be enabled? Under which conditions?
5. May the optional meaning-search service run on a machine outside the establishment? If yes, under what contract and with what safeguards?
6. What must the staff notice say, and where must it be shown?
7. Who is the data protection owner for the DPIA, and who approves it?

## Options we can implement either way
Smaller scope only (some folders), per-folder notice, shorter audit retention, disabling meaning search entirely (word search still works), disabling the Files tab text view, a stricter pause procedure. All are settings or small changes.

## «IA»

### What it is
«IA» is a Nextcloud app of the same suite, not built yet (spec: APS-Conecta/gestion-aio#162). It uses a language model (the Model) that runs on the establishment's own server for two things. It reviews documents: it lists errors, gaps and unclear wording with suggested fixes, and never changes the document. And it writes recurring Bulletins that summarise documents and calendars chosen by a group of staff (Editors) for other groups (Readers).

### What it does with personal data
- **Reads** whatever the person it works for can open in Nextcloud, files with patient data included: the document a person asks it to review; files added, changed, moved or copied into a folder with an Automatic action, as the person who did it or, failing that, an Editor who can open the file; a Bulletin's Sources, as one of its Editors; a Reader's own calendars, to list their meetings.
- **Stores** Review reports, which quote the document, for 90 days. For each version of a Bulletin's documents, it stores a short summary, the expiry date and the job roles (Cargos) the document concerns, until the document changes or is deleted. Each published issue of a Bulletin (an Edition), with the Resumen (the AI-written summary) and the list of file versions read, is kept 12 months. The Model's input and output pass through Nextcloud's task tables and are deleted as soon as the result is stored.
- **Keeps no read or view log.** Reports and Editions name the file versions they used, but nothing records the Model's reads or who viewed a report or an Edition, so «IA» cannot answer "who looked at this data". Nextcloud's permissions, checked live on every read and every view, are the only limit.
- **Publishes without prior approval.** An Edition reaches its Readers as soon as it is complete, marked «Escrito por IA», and Editors can correct or withdraw it. Before release, a person checks the Model's expiry dates and job roles on about 20 real protocols; below 90 % right, the Resumen and the dates stay hidden.
- **Does not send text out of the server.** The Model runs in the suite, and «IA» reaches it only through Nextcloud's TaskProcessing and the standard connector. An administrator could point the connector at another server; the suite never does. The one exception is the quality test: about 20 real protocols are copied to the owner's own machine and read there by the same Model (APS-Conecta/ia#17).
- **Backups**: the suite's backup carries «IA»'s tables.

### Questions about «IA»
1. Is it lawful under Ley 21.719 and Ley 20.584 for a local Model to read documents with health data that the person can already open, in order to review them and to summarise them for groups of staff? On what legal basis? Should folders or apps with patient data (Farmacia, Epidemiología) be kept out?
2. Is it acceptable to keep no read or view log, or must reads and views be recorded?
3. May Editions be published without a person approving each one, given the marking, the correction and withdrawal, and the quality test?
4. Is the retention right: reports 90 days, Editions 12 months, document summaries while the version exists, backups?
5. May «IA» read each Reader's calendars to list their meetings, without storing them?
6. What must the staff notice say, and must staff be able to keep a document or folder out of «IA»?
7. Is the DPIA owner the same as for Buscador Documental?

### Options we can implement either way
Keep folders or apps with patient data out; record each read and view in the audit log; require an Editor's approval before an Edition is published; shorter retention; a tag that keeps a file or folder out of «IA»; Reviews only, without Bulletins; the Resumen hidden, keeping the parts built from data.
