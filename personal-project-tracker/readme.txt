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

Personal Project Tracker turns your WordPress admin into a private project-management workspace: projects, tasks, subtasks, milestones, a unified calendar, time tracking, notes, files, links, finance (expenses/revenue/profit), reports, analytics, an AI Prompt Studio for generating structured prompts from your project data, notifications, reminders, smart alerts, and global search.

The plugin is built for personal, single-user use and keeps all data private to authorized users — nothing is exposed on the public site. The architecture is deliberately prepared so multi-user support can be added later without a rewrite.

= Current status =

This build ships the plugin foundation: bootstrap, database schema and migration system, activation/deactivation, capabilities, security helpers, REST API scaffold, and the admin menu with a system-status Dashboard. Feature modules (Projects, Tasks, Finance, Reports, AI Prompt Studio, etc.) are added in subsequent development phases.

== Installation ==

1. Upload the `personal-project-tracker` folder to `/wp-content/plugins/`.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Go to the "Project Tracker" menu in your admin sidebar.

== Frequently Asked Questions ==

= Is my data visible to visitors of my site? =

No. The plugin does not create any public-facing pages and all admin pages require an authorized capability.

= Does uninstalling the plugin delete my data? =

Not by default. Data is only removed on uninstall if you explicitly enable "Delete all plugin data when uninstalling" in Settings.

== Changelog ==

= 1.0.0 =
* Phase 1: Plugin foundation — bootstrap, database schema/migrations, activation/deactivation, capabilities, security helpers, REST API scaffold, admin menu and dashboard status page.
