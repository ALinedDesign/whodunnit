=== Dobsie ===
Contributors: tracksies
Tags: performance, profiler, debug, queries, database
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find out which plugin is slowing your site down, in a panel small enough to actually read. Queries, memory, autoloaded data and OPcache.

== Description ==

Your site got slow. Something is running too many database queries, and you would like to know what.

Tools exist that answer this. The trouble is that reading them turns out to be its own skill, and plenty of people open one, meet a wall of tabs and numbers, and quietly close it again assuming they are the problem. They are not. The information was there. It was not readable.

Dobsie shows you less, and shows it where you already are.

A small panel sits in the corner of the page you were already looking at. How long the page took, how many queries it ran and how long those took, peak memory, and a bar chart of which plugin is responsible for what. Queries slower than 20ms are called out on their own. When the answer is one badly behaved plugin, you can see it without going anywhere or learning anything first.

When you want the detail, Tools > Dobsie has it, including several things that are genuinely hard to find out on your own. Whether OPcache is switched on and actually working. How much autoloaded data every single page request is dragging along, and which options are the worst of it. Which plugin owns which rewrite rules. Whether your cron is healthy.

Only administrators see any of it, on the front end and in the admin alike.

= Real-time Toast Overlay =

A floating dark-themed panel appears on every page, showing:

* Total page load time
* Database query count and total query time
* Peak memory usage
* Top sources by query time (visual bar chart)
* Slow queries (>20ms)

The toast is only visible to administrators and can be minimized, closed, or permanently hidden. Add `?perf=0` to any URL to temporarily hide it.

= Admin Profiler Page (Tools > Dobsie) =

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
* Cron health (scheduled event count, overdue jobs, and which hooks schedule the most)
* Active hooks analysis (callback counts on eight common hooks, so you can see what is crowded)
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

Dobsie is a Tracksies plugin made by A Lined Design, a WordPress studio in Hobart, Tasmania. Every one of them started as something a client site needed, and they all still run on the sites we build. The full range lives at tracksies.com. For more about the studio, or custom site work, see alineddesign.com.

== Installation ==

1. Upload the `dobsie` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins menu.
3. Visit Tools > Dobsie for the full profiler.
4. The toast overlay appears automatically on all pages (admin-only).

== Frequently Asked Questions ==

= Does this affect site performance for visitors? =

No. The toast overlay and SAVEQUERIES are only enabled for administrators. Regular visitors see no impact.

= How do I hide the toast overlay? =

Click the X button to hide it (sets a cookie). Or go to Tools > Dobsie and uncheck "Enable performance toast overlay" to disable it completely.

= What does Deep Scan do differently? =

Deep Scan enables the SAVEQUERIES constant, which records every database query with its execution time and stack trace. This lets Dobsie identify exactly which plugin generated each query. It adds overhead, so use it for diagnosis rather than leaving it on permanently.

= Can I use this on a production site? =

Yes, with caveats. The Overview tab is safe for production use. Deep Scan and Server Health add overhead and should be used for diagnosis sessions, not left on permanently.

== Screenshots ==

1. Toast overlay showing real-time performance metrics.
2. Overview tab with memory, queries, and autoload analysis.
3. Deep Scan showing query breakdown by plugin source.
4. Server Health tab with filesystem, PHP, cron and hook diagnostics.

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
* Cron health and active hooks analysis.
* SAVEQUERIES enabled only for logged-in administrators, and only on the tabs that need it.
