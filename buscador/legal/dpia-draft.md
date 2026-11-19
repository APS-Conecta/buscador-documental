# DPIA draft (for the data protection owner to complete)

Owner: _to be named_. Status: draft built from the design and the tests; risks and acceptance are for the owner to assess.

Sections 1–4 cover Buscador Documental, sections 5–8 cover «IA», and section 9 records the decision for both.

## 1. Processing
Purpose: let staff find documents they can already open, faster. Data subjects: staff, and any person named in the included documents. Categories: free text of documents (possibly incidental personal data; health data should not be present by policy but cannot be excluded), file names, owner names, access lists. Recipients: none beyond people with existing access; optional remote meaning-search endpoint (off by default). Location: the establishment's server. Retention: until the file is deleted, excluded or the account removed; audit log 90 days (proposed); backups per the establishment's policy.

## 2. Necessity and proportionality
Opt-in folders only; no new access is created (results are rechecked live); `rag-excluded` and the pause give control; erasure follows deletion. Open point for the owner: whether the benefit justifies indexing folders that may hold clinical content.

## 3. Risks and controls (with the proof in the repository)
| Risk | Control | Proof |
|---|---|---|
| A person finds a document they may not read | stored access lists as a prefilter, live recheck as the authority; failures close | `RecheckTest`, `e2e/rbac.sh` (adversarial matrix), `SearcherTest` |
| A revoked share keeps showing | live recheck; permission changes queued; doctor alerts after 1 h | `e2e/events.sh`, `DoctorTest` |
| A download-disabled share leaks content through a snippet | title only; text view refused | `HitBuilder`, `UiServicesTest`, `e2e/ui.sh` |
| Excluded or deleted content stays in the index | exclusion deletes; erasure on deletion; reconciliation | `e2e/erasure.sh`, `e2e/reconcile.sh`, `e2e/scope.sh` |
| Document text in logs | log hygiene with sentinels in every sink | `e2e/logs.sh` |
| Hostile documents harm the server | parser container without network or secrets, size and ratio caps | `sandbox_selftest.py`, `e2e/egress.sh`, `e2e/formats.sh` |
| Text leaves the server | worker on an internal network; only the administrator's endpoint is an exception | `e2e/egress.sh`, `OutboundCallsTest` |
| Stolen backup | AES-256 with a key kept apart; keyed checksum; restore checks | `e2e/restore.sh` |
| A compromised worker or database role | limited role, bounded writes, security review | `buscador/stride-worker-channel.md` |
| Restored backups resurrect erased text | documented replay of erasures | restore runbook |

## 4. Residual risks to assess
Incidental health data in included folders; the optional remote endpoint; shared accounts; a compromised administrator (can read the index through the application's own tables); backups stored without encryption at rest on the establishment's side.

## 5. «IA»: processing
«IA» is not built yet. This part is drawn from its spec (APS-Conecta/gestion-aio#162), and its proofs are the tickets that build each control; update it as they land.

Purpose: review documents for errors, gaps and unclear wording, and write recurring Bulletins that summarise chosen Sources for groups of staff, with the suite's local Model. Data subjects: staff, and any person named in the documents read, patients included. Categories:
- the text of files that the person «IA» works for can open, health data included. Unlike Buscador, «IA» does not keep patient-data folders out (owner's decision, «IA» ADR 0001);
- text derived from it and stored: Review reports (findings that quote the document); Readings (for each version of a Bulletin's documents, a short summary, its expiry date and the Cargos it concerns); Editions (the Resumen, which is the AI-written summary; the list of file versions read; the Sources that could not be read);
- calendar events: of a Bulletin's calendar Sources, read as one of its Editors; and of the Reader's own calendars, which, with the Cargos in Readings, build the Personal list and «Para ti» when the Reader opens them, without a Model call, and are never stored;
- staff data: who asked for a Review, who a Run acts as, Editor, Reader and Cargo groups, who corrected an Edition;
- the Model's input and output, which pass through Nextcloud's TaskProcessing tables until «IA» stores the result.

Recipients: a manual report, only its requester; an automatic report, anyone who can open the file; an Edition, its Editor group and only the Readers who can open every file it read; the «Documentos» table, only Editors, for files they can open. No one outside the establishment. Location: the establishment's server. The Model runs in the suite's Ollama container and is reached only through TaskProcessing and the standard connector (gestion-aio ADR 0003). The one exception is the quality test: about 20 real protocols are copied to the owner's own machine and read there by the same Model (APS-Conecta/ia#17). Retention: reports 90 days; Editions 12 months; a Reading until its document changes or is deleted; TaskProcessing tasks deleted as soon as the result is stored, plus a sweep for leftovers; backups per the suite's backup and the establishment's policy. No read or view log: reports and Editions name the file versions they used, but nothing records who viewed a report or an Edition («IA» ADR 0001).

## 6. «IA»: necessity and proportionality
No new access: every read and every view is checked live against Nextcloud's permissions, and every Run acts as a real person. The Model is local. The Personal list is built without a Model call. Unattended work runs only in Off hours. Open points for the owner: whether reading files that hold patient data is proportionate to the benefit, and whether publishing AI-written Editions without prior approval («IA» ADR 0002, guarded by the quality bar) is acceptable.

## 7. «IA»: risks and controls
| Risk | Control | Proof (ticket) |
|---|---|---|
| A person sees, through «IA», content of a file they can't open | live check on every report, Reading, Edition, Personal list and «Documentos» view; a Reader sees an Edition only while they can open every file it read; a manual report is its requester's only | APS-Conecta/ia#2, #10, #12, #15 |
| A revoked share keeps a summary visible | checks run when a page is read, not only when it was written | APS-Conecta/ia#2, #10, #12, #15 |
| A Run reads as the wrong person | an automatic Review acts as whoever added or changed the file, else an active Editor who can open it, else it fails and notifies nobody; a Bulletin acts as an Editor who can open every Source, and Sources must be open to the whole Editor group | APS-Conecta/ia#7, #9, #11 |
| The Model's mistakes reach Readers | marked «Escrito por IA»; an expiry date kept only if it is in the text, Cargos only from the establishment's list; a finding whose quote isn't in the text is dropped; Editors correct or withdraw; below the quality bar (90 %) in the owner's test, «IA» ships with the Resumen and expiry dates hidden | APS-Conecta/ia#2, #10, #14, #17 |
| A half-written Edition shows after a crash | an Edition is published only when complete; work resumes from its last step | APS-Conecta/ia#6, #10 |
| Document text leaves the server | the Model runs in the suite; «IA» reaches it only through TaskProcessing and the standard connector | APS-Conecta/ia#2, APS-Conecta/gestion-aio#167 |
| Copies of document text pile up | each TaskProcessing task deleted once its result is stored; report and Edition retention; Readings replaced and deleted with their document | APS-Conecta/ia#2, #10, #16 |
| Document text in logs | no document text or Model output in any log, checked with a sentinel document | APS-Conecta/ia#2 |

## 8. «IA»: residual risks to assess
Health data appears in reports, Readings and Editions by design; nothing can tell who looked at what through «IA»; an administrator can point the standard connector at another server, which would send text out; the quality test copies about 20 real protocols to the owner's own machine; the small Model publishes mistakes until an Editor corrects them; a compromised administrator can read «IA»'s tables; backups carry «IA»'s tables.

## 9. Decision
Buscador Documental: approved / approved with conditions / not approved: _owner, date, reference_.

«IA»: approved / approved with conditions / not approved: _owner, date, reference_.
