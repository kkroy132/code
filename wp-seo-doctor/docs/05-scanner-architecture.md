# WP SEO Doctor — Step 5: Scanner Architecture

## What landed

- `includes/checks/class-check.php` — abstract `Check` base class every
  Free (Step 6) and Pro check extends.
- `includes/checks/class-scan-context.php` — wraps the object under scan
  (a `WP_Post` for now), lazily fetches live HTML/DOM only when a Check
  asks for it, memoized so multiple Checks against the same object share
  one fetch. Uses a `seodoc_scan_context` filter so Pro can add
  term/archive/attachment context types (Step 12) without editing this
  file's factory method.
- `includes/checks/class-check-registry.php` — instantiates every class
  registered via `seodoc_register_check()` and runs them against a
  `Scan_Context`.
- `includes/scanner/class-queue.php` — builds the per-scan work queue via
  paginated `WP_Query` (never `numberposts => -1`), claims batches with a
  stale-claim sweep, tracks retry attempts, purges on completion/cancel.
- `includes/scanner/class-batch-processor.php` — the Action Scheduler
  hook target; claims one batch, runs checks per row inside a
  `try/catch (\Throwable)` so one bad post can't take down a scan,
  re-schedules itself until the queue is empty.
- `includes/scanner/class-scan-controller.php` — start/pause/resume/
  cancel/complete for `wp_seodoc_scans` rows; the only class allowed to
  write to that table.
- `includes/issues/class-issue.php` and `class-issue-engine.php` — the
  Issue value object and a **minimal** upsert/resolve engine. See below
  for why this exists ahead of Step 7.

`includes/class-plugin.php`'s `seodoc_core_modules` default list now
includes `Scanner\Batch_Processor` and `Checks\Check_Registry` alongside
Step 4's two entries.

## Why a minimal Issue_Engine landed in Step 5, not Step 7

The batch processor needs somewhere to persist what Checks find in order
to be end-to-end functional at all — a scanner that runs Checks and
discards the results isn't a real deliverable to test against. So
`Issue_Engine::record()` (upsert by `check_id` + `url_hash`, resolve
issues whose check no longer fires) landed now. Step 7 adds SEO Health
Score computation and the Fix First / Action Plan ranking on top of this
same class — the storage contract defined here doesn't change under it.

## Scan lifecycle

```
Scan_Controller::start()
  → insert wp_seodoc_scans row (status: queued)
  → Queue::build_for_scan()   — paginated WP_Query, one insert per row
  → status → running
  → Batch_Processor::schedule_next()   — as_schedule_single_action, now

Action Scheduler tick
  → Batch_Processor::process_batch( $scan_id )
      → re-check scan status; a paused/cancelled scan stops here
      → Queue::claim_batch()   — stale-claim sweep, then claim N pending rows
      → for each row: Scan_Context::for_object() → Check_Registry::run_all()
        → Issue_Engine::record()
      → Scan_Controller::increment_processed()  (per row)
      → if queue empty → Scan_Controller::complete()
        else → schedule_next() again
```

Pause sets `status = 'paused'`; the next-scheduled batch sees that on
entry and simply returns without claiming more work or rescheduling — no
in-flight job to kill, matching Step 1 §4's "stop scheduling more" design.
Resume flips status back and calls `schedule_next()` once to restart the
loop.

## Stale-claim recovery

`Queue::claim_batch()` first resets any row still `processing` after
`STALE_CLAIM_AFTER` (5 minutes) back to `pending`. This is what makes a
killed request (host timeout, memory limit, process killed mid-batch) safe:
the row isn't lost, and it isn't double-counted either, since claiming and
marking `processing` happen as separate `UPDATE` statements the next tick
would only touch once the staleness window has actually passed.

## Batch size and shared hosting

`DEFAULT_BATCH_SIZE` (20) is filterable via `seodoc_scan_batch_size` — the
brief calls for the scanner to "auto-tune down on shared hosting via a
timing self-check"; that self-check (measuring how long a batch actually
takes and adjusting the filter's returned value) is a Settings/Pro
refinement layered on top of this filter later, not a new mechanism.

## Open item carried to Step 6

`Check_Registry` and `Batch_Processor` are fully wired but there are no
concrete Check classes registered yet, so a scan right now would run,
process every queued post, and record zero issues. That's expected —
Step 6 is exactly the ~40-50 concrete checks that populate
`seodoc_register_check()`.
