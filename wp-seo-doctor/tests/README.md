# WP SEO Doctor test harness

```bash
php tests/run.php     # every suite, one verdict
```

| Suite | What it covers | Needs a database |
|---|---|---|
| `fidelity.php` | Audits the harness itself — see below | No |
| `smoke.php` | Pure logic: URL normalisation, DOM parsing, word counting, similarity, redirect pattern compilation, CSV round-trips, PDF structure | No |
| `integration.php` | Every SQL statement the plugin issues, against a real MySQL-compatible server | Yes |
| `content-filters.php` | Links injected by `the_content` filters, and restoration of the globals that staging a singular view disturbs | Yes |
| `navigation-links.php` | Site chrome detection, orphan and crawl-depth agreement | Yes |
| `scale.php` | Duplicate detection cost and correctness on a larger corpus | Yes |

`bootstrap.php` loads the plugin by including its real main file, so the suites
cannot drift from the actual module list. `wp-stubs.php` and `wpdb-stub.php`
provide the WordPress functions the plugin touches; `$wpdb` is a thin wrapper
over `mysqli` that **fails the run** on any SQL error or on a
placeholder/argument count mismatch.

## Running

```bash
mariadb-install-db --user=root --datadir=/tmp/wpsd-data
mkdir -p /tmp/wpsd-run
mariadbd --user=root --datadir=/tmp/wpsd-data \
         --socket=/tmp/wpsd-run/m.sock --skip-networking &
mariadb --socket=/tmp/wpsd-run/m.sock -e "CREATE DATABASE wpsd_test"

php tests/run.php
```

Override the connection with `WPSD_TEST_SOCKET` and `WPSD_TEST_DB`. Keep the
socket path short — Unix domain sockets are capped near 107 characters. The
suites drop and recreate their own tables plus a minimal `wp_posts` /
`wp_postmeta`, so point them at a scratch database, never a real site's.

## Why there is a fidelity audit

These suites run against a *model* of WordPress. A model is only useful while
its gaps are known, and the dangerous failure is not a missing stub — it is a
stub that quietly does nothing, because then every test passes and nothing is
exercised.

That is not hypothetical. `apply_filters()` was `return $value;`, so no test
could ever run a filter. A bug that made the plugin miss every filter-injected
link — on a real site, 6 internal links found instead of thousands, and 511 of
516 pages reported as orphans — sailed through 288 passing assertions.

`fidelity.php` fails the build when:

1. **The plugin calls a WordPress function the harness does not define.** That
   code path cannot be under test; it would fatal if reached.
2. **A stub is inert but unacknowledged.** Inert means it ignores its arguments
   and returns a constant, or hands an argument straight back. Every such stub
   must be listed in `ACKNOWLEDGED_INERT` with a reason.
3. **An acknowledgement is stale** — the stub now does real work, or no longer
   exists.

It also asserts positively that the load-bearing pieces really work: filters
run callbacks, actions fire, transients round-trip, and HTTP can be served
through `pre_http_request`.

Adding to `ACKNOWLEDGED_INERT` is meant to be a deliberate act. The list is the
honest record of what these suites do **not** verify — currently the admin UI,
nonce and capability checks, the AJAX envelope, cron scheduling, and shortcode
expansion.

## What this harness cannot do

It is not WordPress. Hooks, capabilities, nonces, admin rendering and the block
editor are modelled or absent. A real `wp-env` / WordPress test-suite run on a
staging site remains the only way to verify those, and it is the first thing to
do before trusting a release.

## Bugs this harness has caught

- **Filter-injected links were invisible.** `rendered_content()` skipped the
  `the_content` chain, so related-posts blocks and automatic internal linking —
  where most sites' internal links come from — were never seen.
- **`WPSD_GSC::declining_pages()` never ran.** MariaDB rejects an aggregate
  alias inside an `ORDER BY` expression, so content decay silently returned
  nothing.
- **Regex redirects dropped every capture group.** `normalize_source()`
  rewrote patterns as paths, after which `compile_regex()` read the leading
  slash as a delimiter. `/blog/(.+)` still redirected, just always to the same
  place.
- **`wp_update_post()` was called unslashed** in all three paths that rewrite
  post content, stripping backslashes on every save.
- **Pages linked only from the menu were reported as orphans**, while crawl
  depth counted those same links — the two features disagreed.
- **Duplicate detection did not scale**: 16,001 queries and a 1.7 MB transient
  on 2,000 posts, crossing `max_allowed_packet` at roughly 20,000.
- **Pair-finding took 113 seconds** on a templated corpus until shingles shared
  site-wide were excluded as boilerplate.
