# Group reports qualification (#107)

Target (PRD §9): core server requests p95 under 800 ms at the initial qualification workload of 100 organizations, 500 properties, 50,000 rooms and 1,000 concurrent staff sessions.

**Result: full concurrent qualification remains open.** With the group folio balances added in the final fix-up, the full-dataset HTTP p95 passes serially (304 / 320 ms for 1 / 31 nights). At concurrency 8 the 1-night range passes (743 ms) and the 31-night range misses (841 ms). Concurrency 32 misses (2,498 / 2,910 ms). Before folio balances, concurrency 8 passed (618 / 704 ms); see "Final fix-up" below. This is not evidence of 1,000 concurrent sessions meeting the target. No stored aggregates or report caches were added.

## How to reproduce

```bash
scripts/qualify-group-reports.sh smoke
# Only after final history code is merged and the host is idle:
QUALIFY_FULL=1 scripts/qualify-group-reports.sh run
```

### Final-stage raw-query diagnosis

The first fully populated recorded-history seed at `65dbc90` verifies
912,000 reservations, 2,615,000 price lines, 1,635,500 postings and
1,861,000 lifecycle events. All 500 properties pass the 31-date coverage
and boundary-integrity checks. Serial five-property profiles cost
344/359 ms for 1/31 dates, with 326/347 ms in SQL; history alone costs
112 ms. EXPLAIN identifies non-covering event/posting reads and expensive
booked-value membership reads.

The separate 32-request bursts return 200 but have p95 39,846/22,502 ms.
The overloaded baseline is interrupted during the first steady range,
exit 143, to fix those measured access paths; it is **not** a completed
1,000-session qualification and supplies no steady-range pass or percentile.
Its artifacts remain under `.scratch/qualification-evidence/full-baseline/`.
An earlier invocation stopped during seed, before any timing; evidence is
under `aborted-seed/`, with old copied smoke files explicitly labelled stale.

The additive `2026_10_30_000101_reports_add_covering_indexes` migration
provides tenant/property covering access paths for lifecycle boundaries,
reservation statuses/dates, booked value, folio balances and payments.
These remain raw on-request reads; no report data is cached or materialized.
Non-unique indexes change no figures or tenant/security behavior. They add
index storage/write overhead and require a normal deployment migration;
no actual production database or deployment is involved in this local run.
Five schema-contract regressions fail before the migration; the same
regressions and all property/group report tests pass after it.
Final full measurements and functional gates remain pending.

The old positional interface used PHP's built-in server. It is replaced by the
disposable Nginx + PHP-FPM + OPcache + MySQL stack in `scripts/qualification/`.
All measurements below remain historical built-in-server evidence, not results
from the new stack. The full workload has **not** been rerun during infrastructure
preparation.

### Worktree prerequisites

Use a copied (not symlinked) `vendor/`, and build the frontend in this worktree.
Never copy the root `.env`. Follow `docs/agents/dev-workflow.md`, explicitly
clearing `DB_URL` for every local Artisan/build command:

```bash
cp .env.example .env
mkdir -p .scratch
touch .scratch/build.sqlite
DB_URL= DB_CONNECTION=sqlite DB_DATABASE="$PWD/.scratch/build.sqlite" php artisan key:generate
DB_URL= DB_CONNECTION=sqlite DB_DATABASE="$PWD/.scratch/build.sqlite" php artisan migrate --force
DB_URL= DB_CONNECTION=sqlite DB_DATABASE="$PWD/.scratch/build.sqlite" bun run build
```

Docker Engine/Compose, `curl`, `openssl`, `flock`, `sha256sum`, and PHP are required.
The wrapper does not use host database settings or expose MySQL/FPM ports.
It creates random throwaway credentials and an application key in a mode-0600
gitignored runtime file, and supplies them to containers. No real credentials
are read. The private backend Docker network has no external routing; Nginx
also joins an edge network and is published on an automatically allocated
loopback port. PHP and MySQL join only the private backend. Application source is
read-only; only this worktree's storage, bootstrap cache and `.scratch` are writable.

### Declared load model

Full mode retains 100 organizations, 500 real properties, 50,000 rooms and now
populates 60 inventory dates, including every measured date from business
date minus 30 through business date. It contains 912,000 reservations/folios,
2,615,000 price lines, 1,635,500 postings and 1,861,000 lifecycle events. All 1,000
distinct users/sessions now have report authorization: one owner, one group
operations manager, four property managers and four finance staff per organization.
The previous seed included 300 front-desk sessions without `reports.view`; those
were excluded from HTTP. They are replaced with authorized property staff,
not bypassed or counted as successful users.

For **each** range (1 and 31 nights), full mode schedules five complete minutes:
each session issues one report per minute, 5,000 requests per range, 16.67
offered requests/second. Deterministic bounded jitter staggers arrivals inside
individual slots; arrivals do not wait for previous responses. Every session
must issue requests. This is 1,000 active sessions, **not** 1,000 in-flight requests.
The client has at most 256 active curl handles; any waiting arrivals remain in
the schedule and their queue delay is included. No arrival is silently dropped.

Primary latency runs from the monotonic **scheduled arrival** to response/error
completion, including client scheduling queue, FPM/MySQL queue and transport
errors/timeouts. JSON also records wire-only and scheduling-queue percentiles,
statuses, offered rate, actual drain duration, distinct sessions and peak
in-flight requests. Only HTTP 200 bodies rendering `reports/group` succeed.
Redirects, authorization failures and curl errors fail the run. Full steady
p95 must be strictly below 800 ms for both ranges; failure is a nonzero exit,
with JSON retained. The wrapper runs a separate simultaneous 32-request burst
per range first; burst latency is diagnostic, not the 1,000-session steady model.
It also warms the ordinary persistent database permission cache and OPcache;
there is no report cache.

Smoke mode seeds two organizations (10 properties, 1,000 rooms, 18,240
reservations) and exercises the same HTTP path: 32-request bursts per range,
then 20 distinct authorized sessions over two seconds per range. Smoke checks
responses and session coverage, **not** performance acceptance. Neither smoke
nor the schedule self-check establishes full-capacity qualification.

### Recorded-history seed verification

`verify` runs before HTTP measurements and refuses incomplete measured-date
coverage or unexpected row counts. Each property has 1,824 reservations:
1,823 rolling stays plus a December 2025 delayed-checkout fixture. Event
counts per property are 1,823 created, 894 checked in, 814 checked out,
54 extended, 32 departed early and 105 cancelled. Snapshots contain identity,
scheduled dates, status and price lines; event business dates are explicit,
not derived from recording timestamps. Real properties use two timezones and
the same explicitly stored business date. Confirmed stays have no actual
boundaries; open stays include overdue scheduled departures.

Exactly two completed stays per property deliberately model missing/partial
legacy history (one with no events, one with only creation/check-in).
The missing fraction is 1,000 / 912,000 reservations, approximately 0.110%.
All other checked-in/out stays must have their required boundaries.
The verifier checks event identities, snapshots, test flags, future-date
exclusion, sequences and future planned/actual separation.

Public property/group query fixtures assert literal nonzero recorded
arrivals/departures/in-house totals: 41/38/79 for one business date and
893/813/2,491 across 31 dates per property; five-property groups are five
times those values. Only the 31-date fixture has incomplete-history warnings
(two per property). The separate December 10 check-in, scheduled December 12
departure, recorded December 13 checkout fixture asserts in-house on
December 12 and departure on December 13, including a range beginning
December 13.

### Resources, artifacts and cleanup

Fixed stack limits: MySQL 2 CPUs / 3 GiB, 1 GiB InnoDB buffer pool, 100 connections;
PHP 2 CPUs / 2 GiB, 16 static FPM children, 256 MiB per-request PHP limit,
192 MiB shared OPcache; Nginx 0.5 CPU / 128 MiB. The PHP curl client currently
shares the PHP container's CPU/memory limit with FPM, so results include that
local generator overhead. Containers share the host with Docker, the OS and
other work; this is local production-like infrastructure, not production sizing.
MySQL uses its normal durability settings on a disposable named volume, not
the earlier relaxed-durability tmpfs database. Compare runs only with these
differences explicitly recorded.

Official base images are digest-pinned in the Dockerfile/Compose file:
`php:8.3-fpm-bookworm`, `nginx:1.28-alpine`, and `mysql:8.0`.
Only PDO MySQL is compiled from the pinned image's bundled PHP source; curl,
mbstring and OPcache are already included. No package repository download is
needed. Retain the built image ID and actual runtime versions from
`.scratch/qualification/stack.txt` when comparing runs.

Evidence is overwritten per run in `.scratch/qualification/`:
`seed.json`, `verification.json`, `profile.json`, `before.txt`, `after.txt`,
`burst.json`, `steady.json`, and `stack.txt`. Query plans precede measurement;
host and container CPU/memory snapshots bracket the load. Capture wrapper
stdout/stderr separately if retaining successive runs. No cookies or keys are
included in result JSON. Session material and config/route caches are removed
on exit.

The worktree-path-derived Compose project (`dream107-` plus 12 hash characters)
and `flock` prevent concurrent runs in this worktree. Startup refuses existing
containers, networks or volumes belonging to that project. EXIT/INT/TERM
cleanup removes only that project's containers, network and database volume,
preserving failure exit status. No global `pkill`, Docker prune or dev database
cleanup is used. On SIGKILL/host crash, inspect project labels first, then
remove only its resources; the retained runtime file supports targeted recovery:

```bash
export QUALIFY_ROOT="$PWD" HOST_UID="$(id -u)" HOST_GID="$(id -g)"
export QUALIFY_ENV_FILE="$PWD/.scratch/qualification/runtime.env"
project="dream107-$(printf '%s' "$PWD" | sha256sum | cut -c1-12)"
docker ps -a --filter "label=com.docker.compose.project=$project"
docker compose --project-name "$project" --env-file "$QUALIFY_ENV_FILE" \
  -f scripts/qualification/compose.yaml down --volumes --remove-orphans
```

Runnable scheduling invariant check (no application/database bootstrap):

```bash
php scripts/qualification/check-schedule.php
```

### Infrastructure preparation validation (15 October 2026)

Application/report code remains at `34013f0`; no history implementation or report
query was changed here. `scripts/qualify-group-reports.sh smoke` exited 0:
two organizations, 10 properties, 1,000 rooms, 20 users/sessions, 12,800
reservations/folios, 37,240 price lines and 22,780 postings. All 104 report
requests returned 200 and rendered `reports/group`: 32 burst requests plus 20
open-loop requests for each of the two date ranges. Each range used all
20 distinct sessions. This establishes infrastructure/harness wiring only.
The initial internal-network-only publication failed readiness; adding an
Nginx-only edge network resolved it, and the failed run cleaned up safely.

Runtime versions: PHP 8.3.35 with Zend OPcache 8.3.35, Nginx 1.28.3,
MySQL Community Server 8.0.46, Linux/amd64. The successful built PHP image
ID is recorded in `stack.txt`. Debian package transfer was initially slow;
the final build needs no Debian downloads and official image pulls succeeded.
No outstanding network blocker remains.

Validation also covers full-run opt-in refusal (exit 2), concurrent-worktree
lock refusal (exit 2), existing-project-volume refusal (exit 2), refusal of
the worktree SQLite database (exit 1), and SIGTERM during actual stack startup
(`timeout` exit 124). Both normal exit and interruption removed all owned
containers, networks, MySQL volumes and runtime secret files. Images remain
cached intentionally. PHP lint, Bash syntax, Pint and the 5,000-arrival
scheduler self-check pass. No full benchmark, fast suite, MySQL test suite
or end-to-end suite ran during this bounded preparation.

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

## Final fix-up: group folio balances (15 October 2026)

The spec review found that group reports lacked balances by folio. The group page now lists every unsettled folio of the included properties, 50 to a page, with each folio's property. That is one more page query on every group request. The first version reused the property report's balance subquery and measured worse: in-process p95 413 / 530 ms (14 queries). HTTP p95 was 445 / 536 ms serially, 1,134 / 1,362 ms at concurrency 8 and 3,748 / 4,284 ms at concurrency 32 (raw: `.scratch/perf-final-fixup.json`). The page query then switched to one grouped balance join over the included properties. It also reuses the unsettled-folio count the summaries already compute, so it needs no count query. Property reports keep their own query. A fresh throwaway container was seeded with the same row counts and the same command was rerun; all 1,200 HTTP responses were 200.

| Measurement        | Range     | Concurrency | Samples | p50 ms | p95 ms | max ms | Requests/s |
| ------------------ | --------- | ----------- | ------- | ------ | ------ | ------ | ---------- |
| In-process, serial | 1 night   | 1           | 300     | 244.1  | 290.4  | 331.9  |            |
| In-process, serial | 31 nights | 1           | 300     | 318.2  | 389.4  | 417.5  |            |
| HTTP               | 1 night   | 1           | 200     | 121.8  | 303.5  | 1252.2 | 5.9        |
| HTTP               | 31 nights | 1           | 200     | 127.5  | 319.9  | 351.9  | 5.6        |
| HTTP               | 1 night   | 8           | 200     | 290.8  | 743.0  | 1339.1 | 17.9       |
| HTTP               | 31 nights | 8           | 200     | 315.1  | 840.6  | 1245.3 | 16.1       |
| HTTP               | 1 night   | 32          | 200     | 1524.4 | 2497.7 | 3479.3 | 18.0       |
| HTTP               | 31 nights | 32          | 200     | 1703.6 | 2910.0 | 3888.8 | 16.1       |

The report makes 13 queries, against 11 before folio balances. In-process p95 rose by about 40–50 ms (from 239 / 349 ms). The concurrency-8, 31-night cell now misses by 41 ms, so concurrency 8 no longer passes for every range. Same laptop and built-in server as above; runs on this host vary by tens of milliseconds, and this is one run per version. Raw: `.scratch/perf-final-fixup2.json`.

## Remaining qualification

The serial query/HTTP gap is fixed without stored aggregates. With folio balances, concurrency 8 passes for 1 night and misses for 31 nights. Concurrency 32 still fails on this host. The full target remains unproven: this laptop and built-in server do not represent production capacity, and 1,000 stored sessions are not 1,000 simultaneously active users. Do not mark the performance acceptance complete from serial or concurrency-8 timings.

1. Re-measure the optimized code on production-like infrastructure (PHP-FPM or Octane, managed MySQL) with 1,000 staff sessions and an explicit report request rate/think time.
2. If application/database execution still misses p95 < 800 ms there, use that profile to decide whether stored daily aggregates are warranted. They are not a fix for requests queued behind too few web workers.

## Validation

- Code/harness tip tested: `1402ffd`; integration tip `f453e25` is already included (`git merge spec/33-m2-reports` reports up to date).
- Targeted report tests: 54 passed, 245 assertions. Report isolation coverage: 2 passed, 634 assertions.
- `composer check:fast` completed with exit 0 on 15 October 2026, 14:27:53–14:37:56 Africa/Dar_es_Salaam: build, 1,560 Pest tests passed / 15 skipped / 26,224 assertions, Pint, PHPStan (0 errors), TypeScript and frontend formatting/lint.
- PHPStan emitted an optional turbo-extension startup warning, then completed successfully using the fallback. No unrelated environment changes were made.
- Evidence: `.scratch/check-fast-107-perf.log`, `.scratch/perf-final-targeted.log`, `.scratch/perf-final-isolation.log`. The throwaway MySQL container and HTTP workers were stopped after qualification.
- Full MySQL and end-to-end suites were deliberately not run; they are reserved for final integration fix-up. Performance acceptance remains open for the explicitly recorded concurrency gap.
