# Group reports qualification (#107)

Target (PRD §9): core server requests p95 under 800 ms at the initial qualification workload of 100 organizations, 500 properties, 50,000 rooms and 1,000 concurrent staff sessions.

**Result: full concurrent qualification remains open.** At the full dataset, optimized HTTP passes p95 < 800 ms serially (248 / 265 ms for 1 / 31 nights) and at concurrency 8 (618 / 704 ms), using the normal persistent permission cache. Concurrency 32 still misses (2,167 / 2,305 ms). This is not evidence of 1,000 concurrent sessions meeting the target. No stored aggregates or report caches were added.

## How to reproduce

```
scripts/qualify-group-reports.sh 200 8 1 8 32
```

The arguments are requests per level, built-in server workers, then concurrency levels. The script starts a throwaway MySQL 8.0 container on tmpfs and seeds it. It runs `ANALYZE TABLE`, measures in-process, then measures over HTTP, and removes the container on exit. Sessions and the normal framework/permission cache use this disposable database; report figures are not cached. It refuses any database but `ticket107_qualification`. `QUALIFY_ORGANIZATIONS=2` makes a smoke run; any count below 100 is not the qualification workload.

## Workload (seeded, 15 October 2026 run)

| Rows                    | Count                                                                                                 |
| ----------------------- | ----------------------------------------------------------------------------------------------------- |
| Organizations           | 100                                                                                                   |
| Properties              | 500 (5 per organization, alternating USD and TZS)                                                     |
| Rooms                   | 50,000 (100 per property, two room types)                                                             |
| Staff users / sessions  | 1,000 / 1,000 (10 per organization: owner, group operations manager, property staff)                  |
| Inventory records       | 42,000 (42 nights per room type)                                                                      |
| Reservations / folios   | 640,000 / 640,000 (stays of 1 to 5 nights, about two thirds occupied, 21 nights either side of today) |
| Reservation price lines | 1,862,000                                                                                             |
| Postings                | 1,139,000 (room charges, payments, open balances after departure)                                     |

Each in-process report covers all 5 real properties of one organization: KPIs, daily operations, housekeeping and finance. HTTP uses the actual staff authorization mix: owner/group manager reports cover 5 properties, while property manager/finance reports cover their one assigned property. At 200 requests per cell, 58 cover 5 properties and 142 cover 1; the first 200 eligible sessions span 29 organizations. The entire dataset remains present.

## Original baseline (3710212)

Machine: Intel Core i7-10610U (4 cores, 8 threads), 15 GB RAM, laptop. MySQL 8.0 in Docker on the same host with a 1 GB buffer pool. PHP 8.5 with `APP_ENV=production`, config and route caches, OPcache, and database sessions. The HTTP server is PHP's built-in server with 8 workers (`PHP_CLI_SERVER_WORKERS`); it is not PHP-FPM behind nginx.

| Measurement                            | Range     | Samples | p50 ms | p95 ms | max ms |
| -------------------------------------- | --------- | ------- | ------ | ------ | ------ |
| In-process query, serial (no HTTP)     | 1 night   | 300     | 714    | 868    | 1,086  |
| In-process query, serial (no HTTP)     | 31 nights | 300     | 923    | 1,016  | 1,238  |
| HTTP full page, concurrency 1 (serial) | 1 night   | 200     | 947    | 1,480  | 1,522  |
| HTTP full page, concurrency 1 (serial) | 31 nights | 200     | 1,008  | 1,757  | 1,947  |
| HTTP full page, concurrency 8          | 1 night   | 200     | 2,968  | 4,597  | 8,510  |
| HTTP full page, concurrency 8          | 31 nights | 200     | 3,182  | 5,420  | 9,512  |
| HTTP full page, concurrency 32         | 1 night   | 200     | 13,118 | 17,260 | 23,683 |
| HTTP full page, concurrency 32         | 31 nights | 200     | 14,315 | 19,397 | 37,078 |

All 1,200 HTTP responses were 200 and rendered `reports/group`. The harness had 700 eligible staff sessions, but actually used the first 200 distinct sessions: its session cursor restarts for each range and concurrency level. The original `distinctSessions: 700` metadata incorrectly counted eligible sessions; the harness now reports both counts correctly. Throughput levelled at about 2.2 requests per second. The raw run log is `.scratch/qualify-run1-*.log` (not committed).

What these numbers are not:

- Not 1,000 concurrent sessions. 1,000 sessions exist, but at most 32 requests were in flight. A staff session is mostly idle, and the PRD does not give a request rate, so the run does not show how many reports per second 1,000 sessions would ask for.
- Not production hardware or server software. The concurrent numbers mostly measure 8 built-in-server workers and MySQL sharing 4 cores. Requests queue behind each other.

## Original profile (full workload, warm)

- 73 queries per 5-property report. SQL totals about 530 ms; PHP and Eloquent hydration add about 370 ms. Finance makes 7 queries per property, 3 of them with a balance subquery over postings.
- Per property: KPIs about 25 ms, daily operations about 80 ms (about 40 ms of that is casting about 630 reservation dates), finance about 75 ms (SQL-bound).
- The group report calls the three property queries serially, once per property, so time grows with the property count.
- Without `ANALYZE TABLE` after the bulk seed, MySQL chose the `property_id` index for the folio balance subquery and a report took about 70 s. Production tables keep statistics current as they grow; a bulk import or restore should run `ANALYZE TABLE`.

## Measured fixes (15 October 2026)

- Aggregate identical reservation date/status tuples in SQL and use date strings, not Eloquent date casts and a Carbon period per stay. The same property-report calculation serves property and group views.
- Read KPI and operations figures across the explicitly included property IDs, grouped by property. Group reads enter the authorized organization's context through `Tenancy::within`; tenant scopes remain enabled and every query retains property filters. `Property::real()` still excludes demo properties.
- Aggregate nonzero folio balances once for group summaries, including credits and settled-folio exclusion. Do not load or sort the unused paginated folio detail. Payment/refund/reversal arithmetic remains shared with property finance reports.
- No date restriction, currency conversion, reporting cache, new dependency, migration or stored aggregate.

### Same-dataset before/after

A new throwaway MySQL container was seeded with exactly the row counts above and analyzed. The four report classes from 3710212 were copied before editing and loaded with PHP's `auto_prepend_file` for the baseline. Both versions used the same database, date ranges and configuration. Seeding and targeted tests finished before these measurements; `composer check:fast` was not running during qualification.

| Measurement                  | Range     | Samples | p50 ms | p95 ms | max ms | Queries |
| ---------------------------- | --------- | ------- | ------ | ------ | ------ | ------- |
| Baseline in-process, serial  | 1 night   | 100     | 676.6  | 714.8  | 747.9  | 71      |
| Baseline in-process, serial  | 31 nights | 100     | 1034.0 | 1320.6 | 1527.9 | 71      |
| Optimized in-process, serial | 1 night   | 300     | 212.1  | 238.5  | 265.0  | 11      |
| Optimized in-process, serial | 31 nights | 300     | 291.5  | 349.2  | 406.5  | 11      |

The baseline and optimized full report payloads for all five properties of the first organization were byte-identical (31-night range, including all sections). A warm single-report probe measured SQL at 504 ms before and 178 ms after; total wall time fell from 860–997 ms to 184–205 ms. These five-sample probes isolate the improvement; the larger runs above are the percentile evidence. Query counts exclude the initial property selection outside `forProperties`; the original 73-query profile included additional context reads.

Regression coverage checks identical/long stays and departure boundaries, mixed price-line currencies and their denominators, full unsettled counts beyond a page, credits, zero balances, refund reversals on their own dates, and public HTTP property authorization.

Raw local evidence (gitignored): `.scratch/perf-seed.log`, `.scratch/perf-before-query.json`, `.scratch/perf-after-query.json`, `.scratch/perf-full-before-probe.json`, `.scratch/perf-full-after-probe.json`, and `.scratch/perf-full-{before,after}-report.json`.

### HTTP with the original array-cache configuration

The same hardware and 8 configured server workers (plus the built-in server parent) were used. The fresh baseline rerun uses 30 requests per range, concurrency 1; the optimized run uses 200 per range/level, concurrency 1, 8 and 32. Every response was 200 and rendered `reports/group`.

| Version   | Range     | Concurrency | Samples | p50 ms  | p95 ms  | max ms  | Requests/s |
| --------- | --------- | ----------- | ------- | ------- | ------- | ------- | ---------- |
| Baseline  | 1 night   | 1           | 30      | 1076.4  | 1779.1  | 1781.3  | 0.8        |
| Baseline  | 31 nights | 1           | 30      | 995.1   | 1951.6  | 2021.1  | 0.8        |
| Optimized | 1 night   | 1           | 200     | 860.3   | 1026.1  | 1413.5  | 1.1        |
| Optimized | 31 nights | 1           | 200     | 955.7   | 1182.8  | 1359.2  | 1.0        |
| Optimized | 1 night   | 8           | 200     | 2514.9  | 2904.1  | 10242.6 | 3.0        |
| Optimized | 31 nights | 8           | 200     | 2657.5  | 3332.1  | 4029.9  | 2.8        |
| Optimized | 1 night   | 32          | 200     | 10505.7 | 14293.1 | 28234.3 | 2.9        |
| Optimized | 31 nights | 32          | 200     | 11055.7 | 13579.8 | 24780.8 | 2.8        |

Raw evidence: `.scratch/perf-before-http.json`, `.scratch/perf-after-http.json`. Baseline metadata predates the session-count correction: its `distinctSessions: 700` means eligible sessions, not the 30 actually used. The optimized metadata correctly records 700 eligible / 200 used.

### Isolating permission-cache overhead

The original harness explicitly forced `CACHE_STORE=array`, unlike the application's normal `database` default. That discards Spatie's existing permission cache between requests. A fresh-application HTTP-kernel probe (owner, all 5 properties, 31 nights) measured 1,403 ms with array cache, of which only 299 ms was SQL. With the normal database cache warmed, the same fresh-application probe took 382 ms (225 ms SQL). Subsequent requests within the same application instance were about 267 ms even with array cache. This isolates permission initialization, not stale report figures.

The harness now uses the normal database cache, exclusively inside the throwaway database. Both baseline and optimized HTTP are remeasured with that setting; results must not be compared across different cache settings as if only report code changed. The original array-cache measurements remain above. Raw probe evidence: `.scratch/perf-http-profile-array.json`, `.scratch/perf-http-profile-database.json`.

### HTTP with the normal persistent permission cache

Same container, hardware, 8 configured workers plus parent, OPcache, config/route caches and full HTML responses. Only the framework cache store changed from the preceding HTTP run; baseline and optimized code both use the database cache here. No report output is cached. These are closed-loop requests without think time: the next request is sent as soon as one completes, keeping up to 1, 8 or 32 requests in flight. No fast suite, seed or other qualification runner ran alongside them.

| Version   | Range     | Concurrency | Samples | p50 ms | p95 ms | max ms | Requests/s |
| --------- | --------- | ----------- | ------- | ------ | ------ | ------ | ---------- |
| Baseline  | 1 night   | 1           | 30      | 169.5  | 683.5  | 701.4  | 3.1        |
| Baseline  | 31 nights | 1           | 30      | 213.9  | 871.3  | 905.7  | 2.3        |
| Optimized | 1 night   | 1           | 200     | 100.3  | 248.4  | 295.4  | 7.4        |
| Optimized | 31 nights | 1           | 200     | 107.7  | 265.0  | 293.9  | 6.7        |
| Optimized | 1 night   | 8           | 200     | 250.0  | 617.9  | 1154.2 | 20.7       |
| Optimized | 31 nights | 8           | 200     | 254.3  | 704.2  | 1532.2 | 19.4       |
| Optimized | 1 night   | 32          | 200     | 1328.5 | 2166.9 | 3966.5 | 21.0       |
| Optimized | 31 nights | 32          | 200     | 1383.8 | 2304.9 | 2829.3 | 19.5       |

All 1,260 responses were 200 and rendered `reports/group`. Baseline uses 30 distinct sessions; optimized uses 200 (29 owners, 29 group managers, 85 property managers, 57 finance staff), from 700 eligible / 1,000 seeded staff sessions. The HTTP authorization mix differs from the uniformly five-property in-process measurements, and HTTP enables OPcache; do not treat their wall times as interchangeable.

The optimized 31-night p95 at concurrency 32 exceeds 800 ms by 1,505 ms. Throughput plateaus around 20 requests/s while latency rises when concurrency increases from 8 to 32, consistent with queuing/capacity limits on this shared laptop and server, not evidence that more report caching is the right fix.

Raw evidence: `.scratch/perf-before-persistent-http.json`, `.scratch/perf-after-persistent-http.json`; server logs are `.scratch/perf-{before,after}-persistent-server.log`. Reproduction uses the command at the top of this document with the now-corrected database cache setting.

## Remaining qualification

The serial query/HTTP gap is fixed without stored aggregates, and concurrency 8 passes. Concurrency 32 still fails on this host. The full target remains unproven: this laptop and built-in server do not represent production capacity, and 1,000 stored sessions are not 1,000 simultaneously active users. Do not mark the performance acceptance complete from serial or concurrency-8 timings.

1. Re-measure the optimized code on production-like infrastructure (PHP-FPM or Octane, managed MySQL) with 1,000 staff sessions and an explicit report request rate/think time.
2. If application/database execution still misses p95 < 800 ms there, use that profile to decide whether stored daily aggregates are warranted. They are not a fix for requests queued behind too few web workers.

## Validation

- Code/harness tip tested: `1402ffd`; integration tip `f453e25` is already included (`git merge spec/33-m2-reports` reports up to date).
- Targeted report tests: 54 passed, 245 assertions. Report isolation coverage: 2 passed, 634 assertions.
- `composer check:fast` completed with exit 0 on 15 October 2026, 14:27:53–14:37:56 Africa/Dar_es_Salaam: build, 1,560 Pest tests passed / 15 skipped / 26,224 assertions, Pint, PHPStan (0 errors), TypeScript and frontend formatting/lint.
- PHPStan emitted an optional turbo-extension startup warning, then completed successfully using the fallback. No unrelated environment changes were made.
- Evidence: `.scratch/check-fast-107-perf.log`, `.scratch/perf-final-targeted.log`, `.scratch/perf-final-isolation.log`. The throwaway MySQL container and HTTP workers were stopped after qualification.
- Full MySQL and end-to-end suites were deliberately not run; they are reserved for final integration fix-up. Performance acceptance remains open for the explicitly recorded concurrency gap.
