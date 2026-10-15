# Group reports qualification (#107)

Target (PRD §9): core server requests p95 under 800 ms at the initial qualification workload of 100 organizations, 500 properties, 50,000 rooms and 1,000 concurrent staff sessions.

**Result: not qualified.** At the full workload the group report misses p95 < 800 ms, even serially. No stored aggregates were added; see "Gap" below.

## How to reproduce

```
scripts/qualify-group-reports.sh 200 8 1 8 32
```

The arguments are requests per level, built-in server workers, then concurrency levels. The script starts a throwaway MySQL 8.0 container on tmpfs and seeds it. It runs `ANALYZE TABLE`, measures in-process, then measures over HTTP, and removes the container on exit. It refuses any database but `ticket107_qualification`. `QUALIFY_ORGANIZATIONS=2` makes a smoke run; any count below 100 is not the qualification workload.

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

Each report covers all 5 real properties of one organization: KPIs, daily operations, housekeeping and finance.

## Results

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

All 1,200 HTTP responses were 200 and rendered `reports/group`. They came from 700 distinct sessions of staff with reports access. Throughput levelled at about 2.2 requests per second. The raw run log is `.scratch/qualify-run1-*.log` (not committed).

What these numbers are not:

- Not 1,000 concurrent sessions. 1,000 sessions exist, but at most 32 requests were in flight. A staff session is mostly idle, and the PRD does not give a request rate, so the run does not show how many reports per second 1,000 sessions would ask for.
- Not production hardware or server software. The concurrent numbers mostly measure 8 built-in-server workers and MySQL sharing 4 cores. Requests queue behind each other.

## Where the time goes (profile of one report, full workload, warm)

- 73 queries per 5-property report. SQL totals about 530 ms; PHP and Eloquent hydration add about 370 ms. Finance makes 7 queries per property, 3 of them with a balance subquery over postings.
- Per property: KPIs about 25 ms, daily operations about 80 ms (about 40 ms of that is casting about 630 reservation dates), finance about 75 ms (SQL-bound).
- The group report calls the three property queries serially, once per property, so time grows with the property count.
- Without `ANALYZE TABLE` after the bulk seed, MySQL chose the `property_id` index for the folio balance subquery and a report took about 70 s. Production tables keep statistics current as they grow; a bulk import or restore should run `ANALYZE TABLE`.

## Gap

The target fails by about 70 to 220 ms serially in-process, and by more over HTTP and under concurrency. The spec (#33) allows stored aggregates only after the target fails at the qualification workload. It has now failed, but on a laptop with the built-in server, which is not enough to choose between fixes:

1. Re-measure on production-like infrastructure (PHP-FPM or Octane, managed MySQL) with a request rate taken from the PRD pilot workload.
2. Cheaper fixes first: one query per figure across all included properties (`whereIn property_id ... group by property_id`), in place of 73 serial queries, and selecting raw dates for daily operations.
3. Stored daily aggregates only if 1 and 2 still miss p95 < 800 ms.
