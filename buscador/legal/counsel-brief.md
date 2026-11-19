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

## «IA», reviewed together with Buscador Documental

### What it is
«IA» is a Nextcloud app of the same suite, not built yet (spec: APS-Conecta/gestion-aio#162). It uses a language model that runs on the establishment's own server for two things. It reviews documents: it lists errors, gaps and unclear wording with suggested fixes, and never changes the document. And it writes recurring bulletins that summarise documents and calendars chosen by a group of staff (Editors) for other groups (Readers). It is not a chat; chat stays in Nextcloud's Assistant.

### What it does with personal data
- **Reads** whatever the person it works for can open in Nextcloud, files with patient data included: the document a person asks it to review; files that arrive in a folder with an automatic review rule, as the person who added them; a bulletin's sources, as one of its Editors; a Reader's own calendars, to list their meetings.
- **Stores** review reports, which quote the document, for 90 days. For each version of a bulletin's documents, it stores a short summary, the expiry date and the job roles (Cargos) the document concerns, until the document changes or is deleted. Each bulletin edition, with its summary and the list of file versions read, is kept 12 months. The model's input and output pass through Nextcloud's task tables and are deleted as soon as the result is stored.
- **Does not record** what the model read. There is no read log, so «IA» cannot answer "who looked at this data". Nextcloud's permissions, checked live on every read and every view, are the only limit.
- **Publishes without prior approval.** A bulletin edition reaches its Readers as soon as it is complete, marked «Escrito por IA», and Editors can correct or withdraw it. Before release, a person checks the model's expiry dates and job roles on about 20 real protocols; below 90 % right, the AI-written summary and the dates stay hidden.
- **Does not send text out of the server.** The model runs in the suite, and apps reach it only through Nextcloud's AI layer. An administrator could point that layer at another server; the suite never does.
- **Backups**: the suite's backup carries «IA»'s tables.

### Questions about «IA»
1. Is it lawful under Ley 21.719 and Ley 20.584 for a local model to read documents with health data that the person can already open, in order to review them and to summarise them for groups of staff? On what legal basis? Should folders or apps with patient data (Farmacia, Epidemiología) be kept out, as the suite's earlier rule said?
2. Is it acceptable to keep no record of what the model read, or must reads be recorded?
3. May AI-written bulletin editions be published without a person approving each one, given the marking, correction and withdrawal, and the quality test?
4. Is the retention right: reports 90 days, editions 12 months, document summaries while the version exists, backups?
5. May «IA» read each Reader's calendars to list their meetings, without storing them?
6. What must the staff notice say, and must staff be able to keep a document or folder out of «IA»?
7. Is the DPIA owner the same as for Buscador Documental?

### Options we can implement either way
Keep folders or apps with patient data out; record each read in the audit log; require an Editor's approval before an edition is published; shorter retention; a tag that keeps a file or folder out of «IA»; reviews only, without bulletins; the AI-written summary hidden, keeping the parts built from data.
