# DPIA draft (for the data protection owner to complete)

Owner: _to be named_. Status: draft built from the design and the tests; risks and acceptance are for the owner to assess.

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

## 5. Decision
Approved / approved with conditions / not approved: _owner, date, reference_.
