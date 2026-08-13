# WP SEO Doctor test harness

Two suites that run the plugin's real code without a WordPress install.

| Suite | What it covers | Needs a database |
|---|---|---|
| `smoke.php` | Pure logic: URL normalisation, HTML/DOM parsing, word counting, similarity, check verdicts, redirect pattern compilation, CSV round-trips, PDF structure | No |
| `integration.php` | Every SQL statement the plugin issues, executed against a real MySQL-compatible server: schema install, issue store, link graph, broken links, 404 monitor, redirect manager, Search Console aggregates, scoring, reports | Yes |

`wp-stubs.php` and `wpdb-stub.php` provide the WordPress functions the plugin
touches. The `$wpdb` stub is a thin wrapper over `mysqli` that mirrors
`wpdb::prepare()` semantics and **fails the run** on any SQL error or on a
placeholder/argument count mismatch — the two mistakes that are otherwise
invisible until the plugin runs on a live site.

## Running

```bash
# Logic only — no setup required.
php tests/smoke.php
```

For the integration suite, point it at any MySQL or MariaDB server:

```bash
# Example: a throwaway MariaDB instance.
mariadb-install-db --user=root --datadir=/tmp/wpsd-data
mkdir -p /tmp/wpsd-run
mariadbd --user=root --datadir=/tmp/wpsd-data \
         --socket=/tmp/wpsd-run/m.sock --skip-networking &
mariadb --socket=/tmp/wpsd-run/m.sock -e "CREATE DATABASE wpsd_test"

php tests/integration.php
```

Override the connection with `WPSD_TEST_SOCKET` and `WPSD_TEST_DB`.

Keep the socket path short — Unix domain sockets are capped near 107
characters and a long temp path will fail to connect.

The suite drops and recreates its own tables plus a minimal `wp_posts` /
`wp_postmeta`, so point it at a scratch database, never a real site's.

## Bugs this harness has caught

- `WPSD_GSC::declining_pages()` — MariaDB rejects an aggregate alias used
  inside an `ORDER BY` expression. Rewritten with a derived table.
- Regex redirects — `normalize_source()` was rewriting patterns as paths
  (`^/blog/(.+)$` → `/^/blog/(.+)$`), after which `compile_regex()` misread
  the leading slash as a delimiter and silently dropped every capture group.
- `wp_update_post()` was called with unslashed content in the three paths that
  rewrite posts, so any backslash in the content was stripped on save.
