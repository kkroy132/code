=== Personal Project Tracker ===
Contributors: personalprojecttracker
Tags: project management, tasks, time tracking, finance, productivity
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A private, all-in-one project management and personal work tracking system for WordPress.

== Description ==

Personal Project Tracker turns your WordPress admin into a private project-management workspace. Nothing it stores is ever exposed on the public site — every screen lives behind wp-admin and a plugin-specific capability check.

Included modules:

* **Projects** — status, priority, budget, currency, progress, and a full detail page pulling together everything else below.
* **Tasks & Subtasks** — status, priority, due dates, estimated/actual time, tags, and a subtask checklist with completion tracking.
* **Milestones** — project checkpoints with their own status/priority/progress and overdue tracking.
* **Calendar** — month/week/day/agenda views of custom events plus task and milestone due dates, with optional reminder lead times.
* **Time Tracking** — start/stop timers or manual entries, rolled up per task and per project.
* **Notes, Files & Links** — free-form notes (rich text), Media Library-backed file attachments, and external links, all attachable to a project, task, or milestone.
* **Finance** — expenses and revenue by currency, with automatic profit/margin and budget-usage calculations per project.
* **Reports & Analytics** — project, task, milestone, time, finance, productivity, and activity reports, exportable as CSV or JSON.
* **AI Prompt Studio** — generates structured prompts (with reusable templates) from your live project data for use with an external AI tool of your choice; the plugin never calls any AI API itself.
* **Notifications, Reminders & Smart Alerts** — due-date and calendar reminders delivered on a 5-minute cron sweep, plus automatic alerts for overdue tasks, stalled projects, and long-running timers on an hourly sweep.
* **Global Search** — a single search across every module with type/project/status/priority/date filters.
* **Activity Log** — a running history of create/update/status-change events across the plugin.
* **Backup & Restore, Export & Import** — full-site JSON backups with merge/replace restore, plus per-module CSV/JSON export and JSON import with a review-before-confirm preview step.

The plugin is built for personal, single-user use. A capability layer (see Security, below) exists so multi-user roles could be introduced later without changing how any feature checks permissions.

== Requirements ==

* WordPress 6.0 or later
* PHP 8.1 or later
* No external services, API keys, or paid add-ons are required for any feature. The AI Prompt Studio generates prompt text only — you copy it into whichever AI tool you use.

== Installation ==

1. Upload the `personal-project-tracker` folder to `/wp-content/plugins/`, or install the plugin ZIP through Plugins → Add New → Upload Plugin.
2. Activate the plugin through the "Plugins" screen in WordPress. Activation creates the plugin's database tables and grants its capabilities to the Administrator role automatically — no manual setup is required.
3. Go to the "Project Tracker" menu in your admin sidebar to open the Dashboard.

= Activation details =

On activation the plugin: creates/updates its database tables, grants its five capabilities (see Security) to the Administrator role, and seeds default settings without overwriting any settings from a previous install. Reminder and Smart Alert cron events are scheduled separately, the first time an admin page loads after activation (see Cron, below) — not during activation itself.

== Usage ==

Start from **Project Tracker → Projects** and create a project. From a project's detail page you can add tasks, milestones, notes, files, links, and finance entries directly, or use the dedicated top-level menu for each module. The Dashboard summarizes overdue items, upcoming deadlines, and recent activity across all of your projects. Use **Search** to find anything by keyword across every module at once.

== Settings ==

Project Tracker → Settings has six tabs:

* **General** — default status/priority values for new projects and tasks, currency, date/time format, and week-start day.
* **Appearance** — Light / Dark / System color mode for the plugin's own admin screens.
* **Finance** — budget-warning threshold (requires the Finance capability).
* **AI** — which external AI tool a generated prompt is labeled for by default (a label only; no API calls are made).
* **Privacy** — whether uninstalling the plugin also deletes all of its data (off by default).
* **Advanced** — Smart Alerts/Reminders tuning: which alert types are enabled, upcoming-deadline and project-inactivity windows, long-running-timer threshold, and the overdue-task alert threshold.

Per-user notification preferences (which categories to receive, quiet hours) live under Project Tracker → Notifications → Preferences.

== Backup, Restore, Export & Import ==

**Backup** (Settings → Backup tab) creates a single JSON snapshot of every plugin table and stores it as a file, independent of your regular WordPress/database backups. Download or delete existing backups from the same tab.

**Restore** replays a backup in one of two modes:
* *Merge* — only inserts records that don't already exist; never overwrites anything. No special confirmation required.
* *Replace* — truncates the plugin's tables and reloads them from the backup exactly. Because this is destructive and irreversible, the form requires typing the literal word REPLACE, checked on the server — a JavaScript confirmation dialog alone is never trusted for this action.

**Export** produces a CSV or JSON file of one module's data (or everything, for JSON) for use outside the plugin — spreadsheets, other tools, or your own archives.

**Import** uploads a JSON file, shows a preview of what will be added before anything is written, and only applies the import once you explicitly confirm it. Imported records are always inserted as new rows; import never overwrites or deletes existing data.

Both Backup/Restore and any Finance data in an Export/Import additionally require the Finance capability, on top of the Settings capability — the same rule Finance data follows everywhere else in the plugin.

== Security ==

* Every state-changing admin request (forms and REST writes alike) is nonce-verified and capability-gated. Read-only REST routes still require a capability via a `permission_callback`; the plugin registers no publicly-accessible route.
* Five capabilities gate access: `ptp_manage_projects`, `ptp_manage_tasks`, `ptp_manage_data`, `ptp_manage_finance`, `ptp_manage_settings`. Only the Administrator role receives them by default.
* All database access uses `$wpdb->prepare()` for user-supplied values; sortable/orderable columns are restricted to an allow-list rather than taking a raw column name from the request.
* All output is escaped (`esc_html`/`esc_attr`/`esc_url`) and all input is sanitized (`sanitize_text_field`, `wp_kses_post` for the limited rich text fields that need it, etc.) before it is stored or rendered.
* File attachments go through WordPress's own `media_handle_upload()` — the same validation and storage path as uploading through the Media Library — rather than a custom upload handler.
* Backup files are stored under random, plugin-generated filenames and are only ever looked up by their internal manifest ID, never by a path derived from user input.
* Nothing the plugin stores is exposed on the front end; there are no public-facing templates, shortcodes, or REST routes without a permission check.
* Uninstalling the plugin does **not** delete your data unless you have explicitly turned on "Delete all plugin data when uninstalling" under Settings → Privacy.

== Cron ==

The plugin schedules two WP-Cron events, both guarded by a `wp_next_scheduled()` check on `admin_init` so re-activating (or a stray reactivation) never double-schedules them:

* `ptp_reminder_check` — every 5 minutes, delivers due calendar/task reminders.
* `ptp_smart_alerts_check` — hourly, evaluates overdue-task/stalled-project/long-running-timer alerts.

== Upgrading ==

Upgrading is the same as any other plugin: replace the plugin files (or use Plugins → Update if installed from a ZIP you manage yourself) and let WordPress reactivate it. On the next admin page load, the plugin compares its stored database version against the current one and runs any pending table migrations automatically — no manual database steps are required. Always take a Backup (see above) before upgrading across a major version.

== Frequently Asked Questions ==

= Is my data visible to visitors of my site? =

No. The plugin does not create any public-facing pages, shortcodes, or unauthenticated REST routes. Every screen and API endpoint requires an authorized capability.

= Does uninstalling the plugin delete my data? =

Not by default. Data is only removed on uninstall if you explicitly enable "Delete all plugin data when uninstalling" in Settings → Privacy.

= Does the AI Prompt Studio send my data to an AI service? =

No. It only generates prompt text from your project data for you to copy and paste into whichever AI tool you choose. The plugin makes no external API calls.

= Can more than one person use this? =

The plugin is designed and tested for single-user, personal use. Its capability system is deliberately factored out so multi-user roles could be added in a future version, but that is not implemented today — all five plugin capabilities are granted only to the Administrator role.

= Is PDF export supported? =

Not in this version. Reports can be exported as CSV or JSON; PDF generation was intentionally left out to avoid adding an external library dependency.

== Changelog ==

= 1.0.0 =
* First production release: Projects, Tasks, Subtasks, Milestones, Calendar, Time Tracking, Notes, Files, Links, Finance (Expenses/Revenue/Profit), Reports, Analytics, AI Prompt Studio, Notifications, Reminders, Smart Alerts, Settings, Backup/Restore, Export/Import, Global Search, and Activity Log.
* Performance pass: batched N+1-prone queries across the dashboard, reports, and search into single grouped queries; added supporting database indexes.
* UI/UX and mobile polish: dark mode, a responsive card layout for list tables down to 360px-wide screens, and an accessibility pass (ARIA roles, keyboard focus states, semantic markup).
* Full QA pass covering functional, security, data-integrity, and edge-case scenarios; final production code review and cleanup.
