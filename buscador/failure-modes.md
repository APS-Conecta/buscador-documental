# Failure modes and the tests that prove them (architecture section 9, S7)

Each mode from `01-architecture.md` section 9, what the system does, and the test that runs in the regression. `make -C app test-e2e-failures` is the live suite added in S7; the others already existed from S1 to S6.

| Failure | Behaviour | Test |
|---|---|---|
| The index database refuses writes (full disk, read-only) | Search answers "unavailable" with no results invented; the worker stops with a short note (no traceback, no text); both recover when writes return and the document that waited is indexed. A read-only database also stops Nextcloud itself, so the live test makes the worker role read-only and removes the search's right to store its result | `e2e/failures.sh` (5 checks) |
| Worker dead | `occ buscador:doctor` warns that no worker has asked for work; the Prometheus metrics and alert rules (`app/ops/alerts.yml`) say so | `DoctorTest`, `S5ClaimTest::testEveryClaimRecordsThatTheWorkerIsAlivePausedOrNot`, `e2e/ops.sh` |
| Lease expires in the middle of reading | The old holder's results are refused; the job is claimed again; five expiries skip the file with a reason | `WorkerFunctionsTest::testZombieWorkerWithOldEpochCannotOverwrite`, `testExpiredLeaseAloneRejectsTheWriteEvenWithoutAReclaim`, `testAJobThatKeepsLosingItsLeaseIsSkippedInsteadOfReclaimedForever` |
| The file changes while it is read | The result for the old etag is refused and the new version is queued | `WorkerFunctionsTest::testFileChangedAfterClaimRejectsTheOldResult`, `WorkerControllerTest` (412 on `If-Match`) |
| The query embedder is down | The search falls back to words with a notice, never an empty list | `SearcherTest::testSemanticModesFallBackToKeywordWithANoticeWhenNotAvailable`, `EmbedClientTest` |
| The vector index is still being built | Searches use an exact scan (no index) or keep the previous generation; meaning search stays off until coverage allows it | `SearcherTest::testExactScanIsUsedBelowTheThresholdOrWithoutAnIndex`, `SnapshotAndStatePgTest` (validation and coverage), `HybridPgTest` |
| Permission changes not yet applied (`acl_dirty` backlog) | Search rechecks live, so it never shows a revoked file; the doctor warns after one hour and fails after six | `DoctorTest`, `RecheckTest`, `e2e/rbac.sh` |
| Zip bomb, hostile or encrypted file | Skipped with a reason; the parser container has no network or secrets | `e2e/formats.sh` (bomb, corrupt, locked), `worker/tests/test_extract.py`, `sandbox_selftest.py`, `e2e/egress.sh` |
| The embedding model changed under the profile | Refused (digest mismatch); nothing is written | `EmbedClientTest::testAChangedModelDigestIsRefusedAndTheCheckIsCached`, `worker/tests/test_embed.py` |
| Clock skew on the worker channel | The request is refused (120 s window); the worker stops instead of burning attempts | `WorkerAuthTest::testTimestampWindowBothDirections`, `worker/tests/test_main.py::test_rejected_credentials_stop_the_worker_instead_of_burning_attempts` |
| Restore from a backup of another schema version, a damaged or an altered backup | Refused before anything is created | `e2e/restore.sh` (18 checks: newer schema, altered file, forged keyed checksum, wrong key) |
| The exclusion tag is missing | Search and the worker's file access fail closed; enabling the app creates the tag | `e2e/failures.sh`, `e2e/install.sh`, `WorkerControllerTest::testExclusionUnavailableFailsClosedWith503` |
