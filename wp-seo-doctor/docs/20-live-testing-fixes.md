# WP SEO Doctor — Step 20: Fixes From Live Testing

Two real problems reported from actually running the plugin on a live
WordPress site — the first genuine live-environment feedback this build
has had, and both real gaps, not misunderstandings.

## Action Scheduler was never actually vendored

Every step since Step 4 correctly treated Action Scheduler as "a real,
well-known dependency with a clearly-marked integration point, not
fabricated" — but that reasoning was really only valid for dependencies
that require an *account* (Freemius, Google OAuth credentials). Action
Scheduler needs neither: it's a plain MIT-licensed (GPLv3 in its actual
license file), publicly downloadable open-source library with no
credentials involved. Treating it the same as the account-gated
dependencies was overly conservative — there was no reason not to
actually fetch and bundle it.

**Fixed**: downloaded the real, official Action Scheduler 3.9.2 release
directly from its GitHub releases and vendored it at
`vendor/action-scheduler/` (884 KB, 104 PHP files, all lint-clean).
`.gitignore` no longer excludes `vendor/action-scheduler/vendor/` — that
was excluding the library's own required Composer autoloader, which
isn't a placeholder to ignore, it's part of the real distributed
package. `wp-seo-doctor.php`'s existing loader code needed no changes —
it was already written correctly to `require` this exact path once
present.

## "This section is coming soon" — for real this time

Four of the five placeholder screens had complete, working REST APIs
underneath and just no React UI. Built all four:

- **SEO Audit** (`audit`) — filterable (severity/category) paginated
  issue list, with an Ignore action per row.
- **Links** (`links`) — pending internal-link suggestions with
  Approve & Insert / Dismiss actions.
- **404 Monitor** (`404-monitor`) — tracked 404 hits with hit counts and
  a "Create 301 Redirect" button wherever a suggested destination
  exists, which calls the same `/redirects` endpoint the Redirects
  screen uses.
- **Redirects** (`redirects`) — a create form plus the existing list,
  with delete.

All four verified with a real `npm run build` (webpack+Babel actually
compiling the JSX), not just written and assumed correct — bundle grew
from 4.2 KB to 13.4 KB, consistent with four new screens' worth of code
actually being compiled in.

**Still "coming soon," deliberately**: Content, Search Console, and AI
Assistant. These need more than a list-plus-action screen — an OAuth
connect flow, a chat-style Q&A interface, and (for Content) surfacing
GSC-derived Issues that already appear correctly in the SEO Audit screen
above, just not in a dedicated view yet. Left as real follow-up work
rather than shipped as thin, half-functional wrappers around REST
endpoints that need a genuinely different UI shape.
