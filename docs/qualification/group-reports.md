# Group reports qualification (#107)

Target (PRD §9): core server requests p95 under 800 ms at the initial qualification workload of 100 organizations, 500 properties, 50,000 rooms and 1,000 concurrent staff sessions.

**Final workload result: PASS, 16 October 2026.** Both full 1,000-session ranges
complete 5,000 requests at 16.6667 offered requests/s for 300 seconds each:
scheduled-arrival p95 **524.3 ms / 795.5 ms** for 1 / 31 dates.
All 10,000 responses are valid HTTP 200 group reports. The long-range margin
is only **4.5 ms**: this establishes one local production-like workload pass,
not robust production headroom or a production deployment. The separate
32-request burst still exceeds 800 ms. Prior failures remain below.
No stored aggregate, report cache, reduced load or relaxed gate is used.

## Final Measurement

Measured source `be19d36b8ffd1a8e84928f9e509d87d404ae2585`, tree
`f038a978e6bd51c05153e61e1bc18a8729de87d8`. The wrapper's stack.txt pins
this source after the explicit pre-load diagnosis pause; launch evidence
predates the candidate commit. Seed source is `509bb12`, restored from the
owned consistent disposable checkpoint and fully reverified before load.
Measurement is bracketed by telemetry at 16 October 2026,
01:51:10–02:01:22 Africa/Dar_es_Salaam; foreground wrapper exits **0**
at 02:01:29. No seed, functional test, migration or checkpoint copy overlaps
the timed workload.

| Range (inclusive)  | Requests / distinct sessions | p50 ms |    p95 ms | Max ms | Wire p95 ms | Queue p95 / max ms | Elapsed including drain s | Peak in-flight |
| ------------------ | ---------------------------: | -----: | --------: | -----: | ----------: | -----------------: | ------------------------: | -------------: |
| 16 Oct 2026        |                5,000 / 1,000 |  136.1 | **524.3** |  719.7 |       523.4 |          1.1 / 3.0 |                   300.100 |              7 |
| 16 Sep–16 Oct 2026 |                5,000 / 1,000 |  196.1 | **795.5** |  969.6 |       794.4 |          1.1 / 3.6 |                   300.226 |              9 |

Each range independently schedules 300 seconds and 16.6667 requests/s:
five requests per authenticated, authorized session, one/session/minute.
Every arrival completes; no HTTP, transport, redirect or wrong-body error
occurs. Primary latency begins at scheduled arrival, not curl dispatch;
all generator/scheduler/FPM/MySQL queue time remains included.
The model is 1,000 active distinct sessions, **not 1,000 requests in flight**.
Max latency above 800 ms is retained: acceptance is strict p95 <800, not max.

The separate simultaneous 32-request stress diagnostics return all 64
responses as valid HTTP 200 reports but have p95 **5,313.7 / 1,762.9 ms**
(max 5,315.1 / 1,767.7). They do not meet 800 ms and are not substituted for
the steady workload. No stronger burst/in-flight capacity claim is made.

### Final Verified Dataset

| Table / capacity                                              |                        Actual count |
| ------------------------------------------------------------- | ----------------------------------: |
| Organizations / real properties / rooms                       |                  100 / 500 / 50,000 |
| Authenticated authorized users / distinct sessions            |                       1,000 / 1,000 |
| Inventory records                                             |                              60,000 |
| Reservations / folios                                         |                   912,000 / 912,000 |
| Reservation price lines / postings                            |               2,615,000 / 1,635,500 |
| Lifecycle events                                              |                           1,861,000 |
| Created / checked-in / checked-out events                     |         911,500 / 447,000 / 407,000 |
| Extended / departed-early / cancelled events                  |            27,000 / 16,000 / 52,500 |
| Confirmed / checked-in / checked-out / cancelled reservations | 412,000 / 39,500 / 408,000 / 52,500 |
| Intentional incomplete-history stays                          |    1,000 (0.10965% of reservations) |

Every property has inventory, price lines and postings on all 31 measured
dates, normal completed/open/confirmed history distribution, and two explicit
missing/partial legacy stays. Every event has matching tenant/property IDs,
test=false, nonempty representative snapshots and valid per-reservation
sequences. Confirmed stays have no actual boundaries, future plans stay
separate, and no actual event is future-dated relative to stored business
date. Literal public property/group fixtures and the December delayed-checkout
fixture pass before load; none is an empty-history proxy.

### Runtime And Limitations

Digest-pinned Nginx 1.28.3, PHP-FPM/OPcache 8.3.35 and MySQL 8.0.46,
Linux/amd64. Built PHP image:
`sha256:3dd2da6fbf8f53de6ce20c6904ca98d0a442400d7adae73897998d08ab607f7e`.
The host is an Intel i7-10610U laptop (4 physical / 8 logical CPUs,
approximately 15.4 GiB RAM, non-rotational SSD), not a production server.
Limits are MySQL **6 CPU equivalents / 4 GiB / 2 GiB buffer pool**;
PHP **3 CPU equivalents / 2 GiB / 16 FPM workers**; Nginx 0.5 / 128 MiB.
CPU quotas share the host; they are not dedicated physical cores.
Generator CPU/memory remains inside PHP's budget. Normal database permission
cache, OPcache and native InnoDB buffer warming are allowed; report figures
remain uncached raw reads.

The host retains ordinary desktop/OS/T3 background activity, not another
test/benchmark process. Load average is 0.98/1.25/1.49 before and
6.63/5.09/3.35 after. Bracketed CPU use is approximately PHP 713.42 CPU-s,
MySQL 1,654.25 CPU-s; cgroup throttled-time deltas 17.315 s / 0.327 s.
IO pressure remains high, CPU pressure low and memory pressure negligible.
InnoDB physical reads increase 565,960 over 65,686,086 logical buffer requests.
The 4.5 ms long-range margin warrants repeat qualification on the intended
deployment hardware before relying on operational headroom.

Final evidence is `.scratch/qualification-evidence/full-kpi/`:
`steady.json`, `burst.json`, `verification.json`, `seed.json`, `seed-source.txt`,
`stack.txt`, `before.txt`, `after.txt`, profiles and foreground `run.log`.
All prior failed/aborted runs are separately retained and labelled.
The wrapper removes its own containers/networks/live database volume and
generated runtime/session secrets. The label-verified owned checkpoint,
its private credential/session copies and executable throwaway probes are
removed at 02:02:52; unrelated Docker resources, root `.env`, development
data and root server are untouched. `.env` remains byte-identical.

Final validation after timing additionally catches a teardown-only edge
case: the existing historical folio-backfill test recreates its old table,
removing the new folio index. Rollback now checks for that index before
dropping it. This changes no migration-up schema, report query or measured
runtime behavior. The regression fails before the guard and all 11 targeted
backfill/audit/index tests pass afterward. Final functional gate results
are recorded at the end of this document.

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
The initial index-only probe still chose a full folio-key scan for balances.
Grouping balances by property then folio permits the covering path, and the
join also matches property IDs. Lifecycle grouping/index order likewise
starts with property then reservation. Half-open raw DATE bounds replace
DATE-wrapped indexed inventory, price-line and payment columns, retaining
inclusive report endpoints (including SQLite's timestamp serialization).
EXPLAIN ANALYZE is retained alongside estimated plans. Targeted regression
checks pass on SQLite (72 tests/372 assertions) and MySQL (75/1,010), including
report isolation; no cache or stored aggregate is introduced.
At that intermediate stage full measurements and functional gates were pending;
the final workload result is recorded at the start of this document.

### Completed full-load failure at 219beac

Both ranges complete the declared 5,000 requests and use all 1,000 distinct
authorized sessions. All 10,000 responses are HTTP 200 group reports, but
latency fails; wrapper exit 1. No queue delay or failure is discarded.

| Range              | Scheduled-arrival p50 ms |    p95 ms | Maximum ms | Wire p95 ms | Queue p95 ms | Seconds including drain |
| ------------------ | -----------------------: | --------: | ---------: | ----------: | -----------: | ----------------------: |
| 15 Oct 2026        |                285,774.2 | 528,564.8 |  575,029.8 |    49,532.4 |    487,285.1 |                 874.079 |
| 15 Sep–15 Oct 2026 |                455,735.6 | 864,076.8 |  934,221.5 |    71,678.2 |    803,720.4 |               1,233.166 |

Source `219beac7f19bc20e5da2f29b6eea9c6666e3ff70`, tree
`761f427a62d1a23b001460ba552e36a2ddf4a5e9`; evidence
`.scratch/qualification-evidence/full-219beac/`. Steady load begins
15 October 2026 at approximately 23:13:33 Africa/Dar_es_Salaam;
wrapper finishes 23:48:46. Each range offers 16.6667 requests/s over
300 seconds; both reach the 256-handle client limit, retaining queued arrivals.

This run uses MySQL 2 CPUs/3 GiB/1 GiB buffer pool. During load MySQL uses
its full 2-CPU quota while PHP uses approximately 0.86–1.16 CPUs, with
high CPU and IO pressure but no memory pressure. No functional test runs
alongside it. Full-size plans show a correlated ownership-estimate error:
finance starts from 9,120 folios (estimated 183), rather than 535 nonzero
balances, and repeats that path for summary and page. The long-range
price query also chooses non-covering reservation/date lookups.

The next raw-query correction adds covering folio ownership/reservation
and reservation-first price indexes. These have red/green schema-contract
tests. A separate clock-boundary regression requires qualification ranges
to follow the stored property business date if setup crosses midnight;
application time and monotonic latency timers are not frozen.

Before the next measurement, MySQL resources are explicitly increased to
**3 CPUs / 4 GiB, with a 2 GiB InnoDB buffer pool**. The laptop has 8 logical
CPUs; PHP remains 2 CPUs/2 GiB/16 workers and Nginx 0.5 CPU/128 MiB.
This addresses measured CPU throttling and the larger covering-index
working set; comparisons are not presented as code-only speedups.
The load, data capacity, duration and strict 800 ms gate are unchanged.
This remains local production-like sizing, not a production deployment.
HTTP-kernel profiles include middleware/auth/session SQL timings with
bindings omitted and string literals redacted.
The revised smoke passes all integrity/HTTP checks with p95 262.2/490.0 ms
at its **smoke-only** rate; this is not full qualification. SQLite report
regressions pass 75 tests/375 assertions; MySQL report/isolation checks pass
78/1,013. Smoke also checks the new migration's MySQL down/up path before
seeding: MySQL had silently replaced implicit organization foreign-key
indexes with the covering indexes, so rollback restores those supporting
indexes before dropping the report indexes. The rollback check uses
`APP_ENV=testing` only for that CLI command against the empty disposable
database; FPM, seed and all measurements remain production-mode.
Full mode uses the canonical freshly migrated schema without this round-trip.

A same-dataset CPU-only diagnostic then compares the 3-CPU MySQL cap with
6 CPU equivalents (the host has 4 physical cores / 8 logical CPUs).
At 200 closed-loop requests per range/concurrency 8, all responses are 200:
throughput improves from 13.9/8.5 to 23.6/18.8 requests/s; p95 improves from
1,404.1/2,547.3 to 663.7/999.2 ms. These are **diagnostics**, not the
1,000-session qualification. MySQL consumes 82.75 CPU-seconds over the
approximately 20-second 6-CPU probe; quota throttling falls substantially.
This supports changing the current MySQL cap to **6 CPU equivalents**,
still 4 GiB/2 GiB buffer, before the full timed workload. PHP/Nginx limits
and all load/error/latency gates remain unchanged. This is a quota on a
shared laptop, not six dedicated physical cores.

### Second complete workload: a29bd5d

The 6-CPU MySQL run completes both 5,000-request ranges, all 1,000 sessions
per range, with 10,000 HTTP 200 valid reports and no transport/body errors.
Business date is 16 October 2026; 31 dates begin 16 September 2026.
Source `a29bd5dcfb42b89000b24a23963fbe72a22e89bb`, tree
`75c7523567060466a56d983c9e2cb147d9b407bf`. Seed provenance is `509bb12`.

| Range    | p50 ms | p95 ms |  Max ms | Scheduling queue p95 ms | Elapsed/drain seconds |
| -------- | -----: | -----: | ------: | ----------------------: | --------------------: |
| 1 date   |  130.4 |  464.2 |   697.8 |                     1.1 |               300.098 |
| 31 dates |  222.5 |  950.9 | 2,725.6 |                     1.1 |               300.247 |

The longer range **still fails**, so wrapper exit is 1; evidence is retained
under `.scratch/qualification-evidence/full-tuned/`. Peak in-flight is 6/30.
PHP accrued 39.6 seconds cgroup throttling at its 2-CPU quota; MySQL
accrued only 1.4 seconds at 6 CPUs. No test process ran during timing.

The next measured change aggregates in-range price lines per owning
property/reservation/currency before joining scoped reservation statuses,
rather than repeatedly looking up all reservation price lines. Full one-
and five-property payloads for both ranges remain byte-identical (SHA-256
`bf868249816bdedce5c3836690ed541ff374fef7d3bc1d92a9770c27edbe7383`).
Long-range price SQL improves from approximately 85–88 to 65 ms.
A public regression also rejects a price line incorrectly attached to a
different included property; baseline returns 22,000 instead of zero.
SQLite checks pass 76 tests/377 assertions, MySQL 79/1,015.
An indexed correlated lifecycle-boundary candidate is discarded:
it produces identical rows but takes 156 ms versus 97 ms for five properties.

Before the next full measurement, PHP's quota increases from 2 to
**3 CPU equivalents**, retaining 2 GiB/16 FPM workers; MySQL stays 6/4 GiB.
A CPU-only diagnostic on the same candidate dataset improves short-range
throughput from 21.5 to 26.7 req/s and long-range from 19.1 to 20.5;
diagnostic p95 is 687.9/922.0 ms. No workload/gate reduction or report cache.

For sequential same-dataset diagnosis, an owned native checkpoint was
copied only after cleanly stopping the disposable MySQL server. Optional
`QUALIFY_RESTORE_CHECKPOINT=1` in full mode restores only a volume bearing
the exact worktree-owned checkpoint label into an absent disposable volume;
it refuses an existing destination, never accesses development data, and
reruns full seed verification before any timing. The checkpoint preserves
seed provenance; measured source is pinned separately. Private generated
credentials/session material remain mode 0600 and are removed with the
owned checkpoint at final cleanup. Restored buffer/report outputs are
never treated as precomputed report caches.

The old positional interface used PHP's built-in server. It is replaced by the
disposable Nginx + PHP-FPM + OPcache + MySQL stack in `scripts/qualification/`.
The historical built-in-server section retains the earlier evidence, not
results from the new stack. Infrastructure preparation itself did not rerun
the full workload; the later full FPM measurements are recorded above.

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

`QUALIFY_PAUSE_BEFORE_LOAD=1` optionally pauses the foreground wrapper after
seed verification, before any timed load. It accepts only `continue`;
EOF/other input exits and cleans up. This permits sequential diagnosis
against the disposable full dataset without reseeding for each probe.
Continuing restarts FPM, rebuilds its configuration/routes and pins committed
source before normal profiling and the unchanged full workload.

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

Current stack limits: MySQL 6 CPU equivalents / 4 GiB, 2 GiB InnoDB buffer pool, 100 connections;
PHP 3 CPU equivalents / 2 GiB, 16 static FPM children, 256 MiB per-request PHP limit,
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
`seed.json`, `seed-source.txt`, `verification.json`, `profile.json`,
`profile-http-{owner,finance}-{1,31}.json`, `before.txt`, `after.txt`,
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

## Historical Built-In-Server Workload (15 October 2026)

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

## Historical Qualification Gap

At the built-in-server stage the serial query/HTTP gap was fixed without stored aggregates. With folio balances, concurrency 8 passed for 1 night and missed for 31 nights; concurrency 32 failed. The full target was then unproven. These historical timings alone never establish the final acceptance; see the subsequent full FPM workload above.

1. Re-measure the optimized code on production-like infrastructure (PHP-FPM or Octane, managed MySQL) with 1,000 staff sessions and an explicit report request rate/think time.
2. If application/database execution still misses p95 < 800 ms there, use that profile to decide whether stored daily aggregates are warranted. They are not a fix for requests queued behind too few web workers.

## Historical Validation

- Code/harness tip tested: `1402ffd`; integration tip `f453e25` is already included (`git merge spec/33-m2-reports` reports up to date).
- Targeted report tests: 54 passed, 245 assertions. Report isolation coverage: 2 passed, 634 assertions.
- `composer check:fast` completed with exit 0 on 15 October 2026, 14:27:53–14:37:56 Africa/Dar_es_Salaam: build, 1,560 Pest tests passed / 15 skipped / 26,224 assertions, Pint, PHPStan (0 errors), TypeScript and frontend formatting/lint.
- PHPStan emitted an optional turbo-extension startup warning, then completed successfully using the fallback. No unrelated environment changes were made.
- Evidence: `.scratch/check-fast-107-perf.log`, `.scratch/perf-final-targeted.log`, `.scratch/perf-final-isolation.log`. The throwaway MySQL container and HTTP workers were stopped after qualification.
- Full MySQL and end-to-end suites were deliberately not run; they are reserved for final integration fix-up. Performance acceptance remains open for the explicitly recorded concurrency gap.

## Final Functional Validation

All final gates run **after** the complete benchmark and owned-resource
cleanup, never alongside measurement. Final functional source is
`6e91fcf2911a449f6c219ffc06c635ba3d8af776`, tree
`330a6c4b031271902b150936204b0d94404b10d4`. Its only change after measured
`be19d36` is the guarded migration-down folio-index drop; migration-up
schema and measured report/harness/runtime behavior are unchanged.
Subsequent qualification-document edits do not change executable code.

- `composer check:fast`: exit **0**, 16 October 2026,
  02:17:05–02:28:24 Africa/Dar_es_Salaam; build, **1,595 passed /
  15 skipped / 26,524 assertions**, Pint, PHPStan 0 errors, TypeScript,
  formatting and lint pass. Existing optional PHPStan turbo startup warning
  falls back successfully.
- Full `composer test:mysql -- --display-all-issues --log-junit=...`:
  exit **0**, 02:29:28–02:38:05; **1,610 passed / zero skips /
  26,630 assertions**, JUnit zero failures/errors. No test filter.
  The wrapper starts/removes its own throwaway MySQL; no dev data touched.
- Disposable worktree SQLite migration, `bun run build:ssr`,
  `bun run types:check`, `bun run check`, and `bun run test:frontend`:
  all exit **0**, completed 02:38:50. All six frontend scripts pass,
  including populated/empty recorded operations, group KPIs, folio balances
  and platform sidebar behavior.
- PHP/Bash syntax, scheduler invariant check, seven-index/midnight and
  report/isolation regressions pass. MySQL smoke migration down/up passes.
  Recorded-history/nonzero seed fixtures are rechecked on the full dataset
  before every complete workload.

Evidence: `.scratch/qualification-evidence/final-gates/` contains exact
source/date/command/exit records, build/test/tool logs and MySQL JUnit.
The first fast invocation incorrectly exported the build SQLite path into
PHPUnit, overriding its isolated `:memory:` setting, and failed. Its logs
are preserved; the corrected final invocation unsets external DB overrides
for `composer check:fast`, using the safe worktree `.env` for the build and
PHPUnit's own isolated database for tests. The independently reproduced
backfill rollback defect is fixed, not ignored. Prior failed experiments
are not relabelled passes.

The PR remains draft. This evidence does not ready/merge the PR, close
issues, change deployment settings or establish production/burst headroom.
