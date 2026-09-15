=== Whodunnit ===
Contributors: tracksies
Tags: performance, profiler, debug, queries, database
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight performance profiler. Shows which plugins are hogging your database via toast overlay and admin profiler.

== Description ==

Whodunnit is a lightweight performance profiler that helps WordPress administrators identify which plugins are consuming the most database resources.

= Real-time Toast Overlay =

A floating dark-themed panel appears on every page, showing:

* Total page load time
* Database query count and total query time
* Peak memory usage
* Top sources by query time (visual bar chart)
* Slow queries (>20ms)

The toast is only visible to administrators and can be minimized, closed, or permanently hidden. Add `?perf=0` to any URL to temporarily hide it.

= Admin Profiler Page (Tools > Whodunnit) =

Four tabs provide deep diagnostics:

**Overview**

* Memory usage, active plugin count, total queries, autoloaded data size
* Database cleanup stats (revisions, transients, expired transients)
* Quick page test (client-side timing for any URL)
* Autoloaded options table (top 15 by size)
* Rewrite rules analysis grouped by source plugin
* Actionable recommendations

**Deep Scan**

* Query breakdown by plugin/source with visual bar chart
* Slow queries (>10ms) with SQL preview and source identification
* Worst offenders analysis with optimization suggestions
* Slow query pattern detection (which tables are hit most)
* Requires SAVEQUERIES (enabled automatically for this tab)

**Server Health**

* Filesystem health (cache directories, uploads, log files)
* PHP configuration (memory, execution time, OPcache status)
* Object cache detection (Redis, Memcached, or none)
* Execution time breakdown
* Problem file detection (oversized logs, error files in web root)

**Debug**

* Full per-source query list with live filtering
* Requires SAVEQUERIES (enabled automatically for this tab)

= Key Features =

* Zero configuration required — activate and go
* Admin-only visibility — no impact on regular visitors
* SAVEQUERIES managed automatically — only enabled when needed
* Lightweight — no external dependencies, no database tables
* Plugin-aware — identifies query sources by plugin, theme, or mu-plugin
* Built-in suggestions for common performance issues

Whodunnit is made by A Lined Design, a WordPress studio in Hobart, Tasmania. Every Tracksies plugin started as something a client site needed, and they all still run on the sites we build.

== Installation ==

1. Upload the `whodunnit` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins menu.
3. Visit Tools > Whodunnit for the full profiler.
4. The toast overlay appears automatically on all pages (admin-only).

== Frequently Asked Questions ==

= Does this affect site performance for visitors? =

No. The toast overlay and SAVEQUERIES are only enabled for administrators. Regular visitors see no impact.

= How do I hide the toast overlay? =

Click the X button to hide it (sets a cookie). Or go to Tools > Whodunnit and uncheck "Enable performance toast overlay" to disable it completely.

= What does Deep Scan do differently? =

Deep Scan enables the SAVEQUERIES constant, which records every database query with its execution time and stack trace. This lets Whodunnit identify exactly which plugin generated each query. It adds overhead, so use it for diagnosis rather than leaving it on permanently.

= Can I use this on a production site? =

Yes, with caveats. The Overview tab is safe for production use. Deep Scan and Server Health add overhead and should be used for diagnosis sessions, not left on permanently.

== Screenshots ==

1. Toast overlay showing real-time performance metrics.
2. Overview tab with memory, queries, and autoload analysis.
3. Deep Scan showing query breakdown by plugin source.
4. Server Health tab with filesystem and PHP diagnostics.

== Changelog ==

= 1.0.0 =
* Initial release.
* Real-time toast overlay with page load time, query count, DB time, peak memory, top sources by query time, and slow queries.
* Admin profiler page under Tools with Overview, Deep Scan, Server Health, and Debug tabs.
* Query attribution by plugin, theme, or mu-plugin source.
* Slow query detection with SQL preview and worst-offender analysis.
* Autoloaded options analysis and database cleanup stats.
* Rewrite rules analysis grouped by source plugin.
* Filesystem health, PHP configuration, OPcache diagnostics, and object cache detection.
* SAVEQUERIES enabled only for logged-in administrators, and only on the tabs that need it.
