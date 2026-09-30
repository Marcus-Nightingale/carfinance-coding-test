# Plan — Resilient CSV Import (Scenario 3)

> **Status: ACCEPTED — locked for implementation.** Approved after review rounds
> covering failure-mode correction, race-safe dedupe, retry/idempotency,
> counter durability, narrow DB-error handling, `min:8` fixture impact, BOM
> headers, password redaction, stale-lock recovery, blank-password defaulting,
> index-specific duplicate proof, and orphan-file cleanup. No further scope
> changes without a new review. Implementation follows §6 in order; acceptance
> is the test list in §4 (`php artisan test --filter=Import` green + full suite
> green).

## 1. Problem statement (corrected)

`app/Jobs/ProcessImportJob.php:44-54` wraps all rows in a single `DB::transaction()`
with **no per-row validation**. Two distinct cases must not be conflated:

- **Case A — malformed-but-insertable row (e.g. `not-an-email`): does NOT throw
  today.** There is no `Validator` in the job, and
  `database/migrations/0001_01_01_000000_create_users_table.php:18` declares
  `email` as a plain `string()->unique()` — no format constraint. `User::create()`
  therefore inserts `not-an-email` successfully and the batch *commits*. The bug
  here is silent bad data, not a rollback. The provided acceptance test
  (`tests/Feature/ProcessImportJobTest.php:15-56`) exposes this: it expects the
  bad-format row to be *rejected* (`failed_rows=1`, absent from `users`), which
  the current code cannot do.
- **Case B — duplicate email: DOES abort the whole batch.** The second insert
  violates the unique index → `QueryException` (SQLSTATE 23000) → the outer
  transaction rolls back *all* rows, `failed()` marks the job `failed`, valid
  rows are lost. Same outcome for any DB-level throw inside the loop
  (e.g. `array_combine()` `ValueError` on column-count mismatch in PHP 8,
  thrown *before* the transaction at `ProcessImportJob.php:37`).

So the plan needs different tests for the two cases (see §4): one proving
bad-format rows are now rejected instead of inserted, one proving a
constraint-violating row no longer rolls back its siblings.

Secondary issues:

- `app/Http/Controllers/ImportController.php:18-20` stores upload under the
  client-provided filename (`storeAs('imports', $filename)`). Collisions/overwrites
  and path-traversal-prone; no header check; no dedupe.
- `app/Models/ImportJob.php:22-28` / `database/migrations/2024_01_01_300000_create_import_jobs_table.php:11-18`
  only track `total_rows`, `processed_rows`. No `successful_rows` / `failed_rows`,
  no row-level error store, statuses limited to `pending|processing|completed|failed`.
- `app/Jobs/ProcessImportJob.php:32-40` loads the whole CSV into `$rows` array —
  unbounded memory on large files.
- No rejected-rows artifact for operators to fix/retry.

Failing acceptance test: `tests/Feature/ProcessImportJobTest.php:15-56`
expects `completed_with_errors` + `total/processed/successful/failed` counts.
It fails today (missing columns + abort-on-error behaviour).

## 2. Changes to be made

### 2.1 `ImportController@store` — validation + safe storage + dedupe
File: `app/Http/Controllers/ImportController.php:12-33`

- Keep `required|file|mimes:csv,txt|max:10240`, add explicit empty-file guard
  and a header check. Normalise the header before comparing: strip a leading
  UTF-8 BOM (`\xEF\xBB\xBF`, as emitted by Excel/Sheets exports) from the first
  cell, trim whitespace/`\r` per cell, then require exactly
  `name,email,password` (case-sensitive after normalisation). Return `422` with
  a `header` error key on mismatch — fail fast before a job row is created.
  Shared `normaliseHeader()` helper used by both controller and worker so a
  BOM-prefixed file is not rejected in one place and accepted in the other.
- Store with unique internal name, never client name:
  `storeAs('imports', Str::uuid().'.csv')`. Persist both:
  `original_filename` (display) + `stored_path` (internal, e.g. `imports/<uuid>.csv`).
  Keep legacy `filename` column populated for BC, or rename/migrate (see §3).
  Ordering / orphan rule: hash and header-check the **temporary upload**
  (`$file->getRealPath()`) *before* storing. Only a file that passes the header
  check and the dedupe pre-check is stored. If the request ends in `422` or
  `409`, nothing is left on disk. Residual race case (pre-check passed, but the
  `UNIQUE(dedupe_key)` insert then collides): delete the just-stored copy
  before returning `409`, so duplicate uploads never accumulate unreferenced
  files. Tests assert the 409 path leaves no orphan in `imports/`.
- Hash file for dedupe: `hash_file('sha256', $file->getRealPath())` on the temp
  upload → `file_hash`. Before storing, query for an existing row with the same
  `file_hash` and `status IN ('pending','processing')` **that is not stale**
  (see stale rule below). If found, return `409` with `{ existing_job_id }`
  without storing anything.
- Race safety (revised): a lookup-then-insert inside a transaction is **not**
  race-safe — two concurrent uploads can both read "no active job" and both
  insert. And a plain `UNIQUE(file_hash)` is wrong: it would permanently forbid
  re-importing a file after the first import *completes*. DB-enforced rule:
  add `dedupe_key CHAR(64) NULL UNIQUE` alongside the non-unique `file_hash`
  history column, plus `processing_started_at TIMESTAMP NULL` as the lock
  heartbeat. Set `dedupe_key = file_hash` on create; clear it to `NULL` in
  the same write that moves the job to a terminal state (`completed`,
  `completed_with_errors`, `failed`). Both MySQL and SQLite permit multiple
  `NULL`s in a unique index, so only one *active* job per hash can exist while
  completed jobs remain re-importable. Create path catches the duplicate-key
  `QueryException` (SQLSTATE 23000) and, on collision, re-queries the live row
  and returns `409` (deleting any just-stored copy first) — the catch, not the
  pre-check, is the correctness mechanism. (A Redis/cache lock was considered
  and rejected: adds infra, not transactional with the row insert.)
- Stale-lock recovery: if a worker dies (SIGKILL, OOM, deploy) between the
  `processing` write and any terminal write, `failed()` never runs and
  `dedupe_key` stays occupied, blocking that file forever. Fix with an expiring
  lock: `processing_started_at` is set when the job enters `processing`
  (refreshed on each periodic counter flush as a heartbeat). A job is **stale**
  when `status = 'processing'` and `processing_started_at < now() - threshold`
  (`config('imports.stale_after_minutes', 30)`). Controller ignores stale rows
  in the pre-check; on a duplicate-key collision against a stale row it reaps
  it (marks `failed` with `error_message = 'Stale worker; lock reclaimed.'`,
  nulls `dedupe_key`) and retries the insert once. A scheduled
  `imports:reap-stale` command (folded into the prune command or standalone,
  run every 10 min) reaps stale jobs with no new upload in flight. Worker
  heartbeat via the periodic flush keeps long-but-healthy imports from looking
  stale.
- Return `202 { id, status }` unchanged on success.

### 2.2 Migration: extend `import_jobs`, add `import_job_errors`
New migration(s), e.g. `2026_09_30_000001_extend_import_jobs_table.php`:

`import_jobs` additions:

- `original_filename string nullable`
- `stored_path string nullable` (internal disk path)
- `file_hash char(64) nullable, index` (history / audit; NOT unique)
- `dedupe_key char(64) nullable, unique` (active-job lock; set on create,
  nulled on terminal state — see §2.1)
- `processing_started_at timestamp nullable` (lock heartbeat / stale detection;
  set on entering `processing`, refreshed on each counter flush)
- `successful_rows integer default 0`
- `failed_rows integer default 0`
- `rejected_path string nullable` (path to generated rejects CSV)
- `rejected_expires_at timestamp nullable` (TTL marker)
- extend `status` comment/enum to include `completed_with_errors`
  (keep string column, validate in code, not DB enum for portability).

New table `import_job_errors`:

- `id`, `import_job_id FK -> import_jobs.id cascadeOnDelete, index`
- `row_number unsignedInteger` (1-based data-row number, header = row 1 excluded)
- `row_data json` — **redacted**: stores only non-sensitive fields
  (`{ "name": ..., "email": ... }`). The CSV `password` value is **never**
  persisted here (or anywhere except the salted `users.password` hash). A
  password validation failure is recorded as a reason
  (e.g. `{"password": ["The password must be at least 8 characters."]}`)
  without retaining its value. If raw-row forensics are ever needed, store
  `password_present: bool` only.
- `errors json` (`['email'=>[...], ...]` from validator)
- `timestamps`
- `unique(['import_job_id','row_number'])` — makes error writes idempotent
  across at-least-once redelivery (worker uses `updateOrCreate`, see §2.3).

Update `app/Models/ImportJob.php:20-29`: `$fillable` + casts + `errors()` HasMany
relation; new `app/Models/ImportJobError.php` model.

### 2.3 `ProcessImportJob` — row-by-row streaming, per-row validation
File: `app/Jobs/ProcessImportJob.php:18-60` (rewrite `handle()`):

- Resolve path via `stored_path` (fall back to legacy `imports/{filename}`).
  Missing file → `failed` + `error_message`, as today.
- Validate header again in worker (defence in depth) with the same
  BOM-stripping `normaliseHeader()`; mismatch → `failed`.
- Retry / double-processing rule (resolved): rows commit individually (no outer
  transaction), so a queue retry that restarts from row 1 would re-insert
  already-created users and mislabel them as row failures on the second pass.
  Decision: set `public $tries = 1` on the job (no automatic retry). There is
  **no supported same-job rerun path**: `handle()` starts with `refresh()` and
  exits as a no-op if the job is already terminal (`completed`,
  `completed_with_errors`, `failed`), so re-dispatching a completed job never
  modifies users, counters, or error rows. To reprocess, upload again (new
  `ImportJob`; allowed because the terminal write nulled `dedupe_key`). The
  `updateOrCreate(['import_job_id','row_number'])` + unique constraint on error
  rows is **not** a rerun feature — it only guards the at-least-once redelivery
  edge (duplicate dispatch / `queue:retry` of a still-`processing` job after a
  crash) against double-inserting error rows. Enabling `$tries > 1` later
  requires offset-resume (skip `processed_rows`) — out of scope here.
- Stream: `fopen` + `fgetcsv` loop (or `LazyCollection`), never accumulate `$rows`.
  Use `SplFileObject`/`fgetcsv` with `array_combine` guarded for column-count
  mismatch (short/long rows → record error, continue).
- Per row (inside loop, no outer transaction):
  1. Normalise first, then validate the **defaulted** value: trim `name`/`email`;
     if `password` is missing/`null`/blank (`''` or whitespace-only), replace it
     with the default `'password'` **before** `Validator::make()`. Validate
     `['name'=>'required|string|max:255','email'=>'required|email|unique:users,email','password'=>'required|string|min:8']`
     (password `required` post-defaulting, not `nullable` — otherwise `''`
     fails `min:8` and contradicts the stated default). This makes "blank
     password → default → passes" deterministic rather than dependent on how
     `Validator` treats empty strings vs `null`.
     NOTE — fixture impact: `tests/Feature/ImportTest.php:23` uploads
     `...john@example.com,secret` (`secret` = 6 chars). Under `min:8` that row
     would now be *rejected* if ever processed. Update that fixture to
     `password123` when implementation lands (or consciously relax the rule to
     `min:6`); the upload-only test passes either way today because it fakes
     the bus, but the inconsistency must not be left latent.
  2. On failure → upsert redacted `import_job_errors` row
     (`row_data = {name, email}` only — never the password value) + `$failed++`.
  3. On pass → `try { User::create(['name','email','password' => Hash::make($row['password'])]) }`
     with **narrow** catch: only a proven unique-violation on `users.email` is
     a row error. Helper `isEmailDuplicate()` requires ALL of: SQLSTATE
     `23000`, plus driver-specific proof — MySQL `errorInfo[1] === 1062` with
     the message naming the email unique index (`users_email_unique`), or
     SQLite `errorInfo[1] === 19` with message matching
     `UNIQUE constraint failed: users.email`. Bare `23000` / bare code `19`
     also cover NOT-NULL, CHECK, and other indexes, so they alone are
     insufficient — any non-matching `QueryException` (connection loss, missing
     table, other constraint) is rethrown so an outage/schema error is never
     mislabelled as "1 bad CSV row". Duplicate-email → redacted error row +
     `$failed++`, else `$success++`.
  4. `$processed++`; every N rows (e.g. `50`/`100`) flush
     `processed/successful/failed/total` for live `GET /imports/{id}` progress.
- Counter durability (revised): periodic flush alone is lossy — `failed()` runs
  on a fresh model state and would otherwise preserve only the last flush,
  discarding rows handled since. So the loop body is wrapped in try/catch: the
  catch path flushes the in-memory `$processed/$success/$failed` counters to
  the DB *before* marking `failed` + `error_message`. `failed()` itself stays
  purely as a safety net (fatal/timeout where the catch never ran): it
  `refresh()`es, preserves existing counters, and only sets status/message —
  it never invents counts.
- Terminal status: `failed_rows === 0 ? 'completed' : 'completed_with_errors'`.
  The terminal write also nulls `dedupe_key` (releasing the §2.1 lock) in the
  same update.
- `total_rows`: increment as streamed, flushed with the periodic update, final
  sync at end. (Two-pass doubles I/O; document tradeoff.)

### 2.4 Progress + error visibility in API
File: `app/Http/Controllers/ImportController.php:35-48`, `routes/api.php:18-19`

- `GET /api/imports/{id}` gains `successful_rows`, `failed_rows`,
  `original_filename`, `errors_count`, `rejected_url`/`rejected_expires_at`.
  Keep existing keys for BC.
- New `GET /api/imports/{id}/errors` (or `/rejected`): streams the rejects CSV as
  `text/csv` download (`Content-Disposition: attachment`). `404` if no failures or
  artifact expired/deleted. Authorisation: none today — keep same posture, note as
  follow-up.

### 2.5 Rejected-rows CSV + TTL cleanup

- After processing, if `$failed > 0`, generate
  `imports/rejected/{import_job_id}.csv` with columns
  `row_number,name,email,errors` — **no password column** (`errors` = flattened
  `field: msg; …`; for password failures the reason is recorded without the
  value). The DB `import_job_errors.row_data` is likewise redacted (§2.2), so
  plaintext credentials exist nowhere outside the salted `users.password` hash.
  Store path in `import_jobs.rejected_path`, set
  `rejected_expires_at = now()+TTL`
  (TTL e.g. `7 days`, via `config('imports.rejected_ttl_days', 7)` or constant).
- Cleanup: `app/Console/Commands/PruneRejectedImportFiles.php`
  (`imports:prune-rejected`, also reaps stale `processing` jobs past
  `stale_after_minutes` — or a separate `imports:reap-stale` on a 10-min
  schedule) deletes expired files + nulls `rejected_path`,
  scheduled daily in `routes/console.php` (stale-reaper more frequently). Alternative: delayed queued job
  `DeleteRejectedFile::dispatch($job)->delay($ttl)` — prefer Artisan command for
  simplicity/idempotency; document choice.
- `Storage::disk('local')` throughout so `Storage::fake('local')` tests work.

### 2.6 Config / docs

- Small `config/imports.php`: `chunk_flush` (progress update interval),
  `rejected_ttl_days`, `expected_headers`, `stale_after_minutes`. Avoid
  enterprise abstraction; plain constants acceptable if config file deemed
  overkill.

## 3. Migration / BC notes

- Backfill: existing rows get `successful_rows=processed_rows`,
  `failed_rows=0`, `original_filename=filename`, `stored_path='imports/'.filename`,
  `file_hash=NULL`, `dedupe_key=NULL`, `processing_started_at=NULL`
  (no fake lock on history).
- Old `ImportTest.php:32` asserts `filename = users.csv` — keep writing client name
  into `filename` (or update test to assert `original_filename`). Decision at
  implementation: keep `filename` = original display name, `stored_path` = internal.
- Dedupe enforcement is the `UNIQUE(dedupe_key)` index + duplicate-key catch in
  the controller (see §2.1), not the pre-check transaction.
- `ImportTest.php:23` fixture uses password `secret` (6 chars): update to
  `password123` to satisfy the new `min:8` row rule.

## 4. Test plan (extend `ProcessImportJobTest`, `ImportTest`)

Keep the provided `test_invalid_row_does_not_abort_import` as the acceptance test.

Additions in `tests/Feature/ProcessImportJobTest.php` (split by failure mode —
see §1):

- bad-format row (`not-an-email`) → `completed_with_errors`, `2/1` split,
  absent from `users`, error row present. (Regression: today this row is
  silently *inserted*.)
- duplicate-email row (second occurrence, or pre-seeded user) → isolated via
  `unique` validator / duplicate-key catch; siblings commit; no batch rollback.
  (Regression: today this rolls back everything.)
- all-valid import → `completed`, counts `3/3/3/0`, zero `import_job_errors`.
- all-invalid import → `completed_with_errors`, zero users created.
- malformed row (wrong column count) → skipped, recorded with `row_number` +
  `row_data` + `errors`.
- `import_job_errors` assertions: `row_number`, redacted `row_data` JSON
  (`name`/`email` only — assert password value absent), `errors` JSON keys;
  redelivery of a still-`processing` job does not duplicate error rows
  (`unique(import_job_id,row_number)`); re-dispatch after terminal status is a
  no-op (counters/users unchanged).
- blank-password row → succeeds via default `'password'` (assert user created,
  no error row); short-password row (e.g. `secret`) → rejected with reason,
  value not stored.
- BOM-prefixed header (`\xEF\xBB\xBFname,email,password`) imports normally.
- progress columns persisted (`successful_rows`/`failed_rows`).

Additions in `tests/Feature/ImportTest.php`:

- header rejection (`422` on bad header, no job created) + BOM-prefixed header
  accepted.
- unique internal storage (assert `stored_path !== original name`, file exists).
- duplicate upload while `pending/processing` → `409` + same `existing_job_id`,
  no second `ProcessImportJob` dispatched, no orphan file stored; re-upload after
  completion → `202` (new job allowed — `dedupe_key` was nulled); upload matching
  a stale `processing` job (backdated `processing_started_at`) → old job reaped
  as `failed`, new upload accepted.
- `GET /imports/{id}` exposes new counters.
- rejects download: `GET /api/imports/{id}/errors` returns CSV with bad row +
  reason and **no password column/values**; `404` when no failures; pruning
  command deletes expired file.
- error-record redaction: assert stored `row_data` and rejected CSV contain no
  password values even when the rejected row failed on password.

Run: `php artisan test --filter=Import` (SQLite in-memory per `phpunit.xml`).

## 5. Out of scope / tradeoffs

- No chunked/batch insert — per-row inserts are slower but required for row
  isolation; revisit with batch+savepoints only if throughput demands it.
- Email `unique` validator has inherent TOCTOU race; the index-specific
  duplicate catch (§2.3) is the backstop — anything not provably a
  `users.email` unique violation still fails the job.
- Plaintext passwords are never written to `import_job_errors` or the rejected
  CSV; only the salted hash reaches `users`. Operators lose the ability to see
  the exact rejected password value — accepted, since the reason string is
  sufficient to fix and re-upload.
- Stale threshold (default 30 min) trades false-reaps of very slow healthy
  imports (mitigated by the flush heartbeat) against indefinite dedupe blocks.
- Very large files: single worker, single pass; horizontal sharding explicitly
  out of scope. Periodic counter flushes are progress signals only; crash
  durability comes from the exception-path flush (§2.3), and hard kills can
  still lose at most one flush interval.
- Retries disabled (`$tries = 1`); resume-from-offset is a future extension.
- TTL default 7 days; download is unauthenticated like existing endpoints.

## 6. Implementation order

1. Migration + models (`import_jobs` columns, `import_job_errors`).
2. Controller: header check, UUID storage, hash + `409` dedupe.
3. Job: streaming loop, per-row validator, counters, rejects CSV.
4. `show` + `errors` download endpoint + prune command + schedule.
5. Tests above; run full suite.

## 7. Implementation record (2026-09-30)

All items implemented as specified, with two fixes found via tests:

- Worker normalises the header once and uses it as the `array_combine` key map
  (a BOM-prefixed file otherwise validates but mis-keys `name`).
- `ImportTest` upload fixture updated `secret` → `password123` for the `min:8`
  rule; `RefreshDatabase` added to `ImportTest` for dedupe isolation.

Files added: `database/migrations/2026_09_30_000001_extend_import_jobs_table.php`,
`config/imports.php`, `app/Support/CsvImport.php`, `app/Models/ImportJobError.php`,
`app/Console/Commands/PruneRejectedImportFiles.php`.
Files changed: `app/Models/ImportJob.php`, `app/Http/Controllers/ImportController.php`,
`app/Jobs/ProcessImportJob.php`, `routes/api.php`, `routes/console.php`,
`tests/Feature/ImportTest.php`, `tests/Feature/ProcessImportJobTest.php`.

Verification: `php artisan test` — 41 passed (23 import-scoped, incl. the
original acceptance test); `vendor/bin/pint` clean on touched files.
