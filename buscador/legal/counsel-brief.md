# Brief for counsel (draft)

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
