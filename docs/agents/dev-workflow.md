# Dev workflow

Commands live in `composer.json` and `package.json` scripts; this file records what they don't say.

## Checks

`composer check:fast` runs the fast checks in the order they need: build, then Pest on SQLite, Pint, Larastan, TypeScript, and lint. Pest needs the build first; without the Vite manifest every page test fails. Run it before reporting work done. Pest runs in parallel, one process per core; while building, run only the affected tests with `php artisan test --filter`.

| Check                          | Command               |
| ------------------------------ | --------------------- |
| All fast checks, in order      | `composer check:fast` |
| Pest on MySQL 8, as CI runs it | `composer test:mysql` |

`check:fast` leaves migrations to you: run `php artisan migrate` first against the database the build reads (see below).

Local Pest runs on SQLite; CI runs it on MySQL. They differ, for example in JSON key order and column types. Run `composer test:mysql` before merging anything that touches migrations, JSON columns, or queries. It starts a throwaway container and never touches the dev database.

## The dev database

`.env` points at the local MySQL database `the_dream`. Leave its data alone: never run `migrate:fresh`, `db:wipe` or seeders against it unless the user asks.

Run `php artisan migrate` after pulling. Wayfinder reads the **live** schema when it generates route types, so a stale database makes `bun run build` emit wrong parameter types and `types:check` fails.

## Worktrees

A fresh worktree has no `vendor/`, `node_modules/`, `.env` or build output. Run `scripts/worktree-setup.sh` from its root once: it creates its own `.env`, copies dependencies from the main checkout, and builds against a disposable `.scratch/build.sqlite`, never the shared dev database.

`.scratch/` is gitignored; keep shared agent notes there too, so they survive a session restart.

## Payment slip uploads

PHP must allow uploads of at least 5 MB (`upload_max_filesize=5M` or higher). Set
`post_max_size` and the reverse proxy's request-body limit high enough for the
combined batch plus multipart overhead; application validation still limits each
file to 5 MB. Configure the PHP runtime that serves HTTP, not only the CLI.
Slips live on the private `local` disk (`storage/app/private/payment-slips`), which
must persist across deployments and be included in backups.

## Gotchas

- **New Inertia page**: rebuild before running its feature test; the test fails while the page is missing from the Vite manifest.
- **Dispatching inside `Tenancy::within`**: use a block closure (`function () { Job::dispatch(); }`). An arrow function returns the `PendingDispatch`, which dispatches after the context has ended.
- **Users across a team switch**: spatie caches roles on each user object. `within` clears them only for the signed-in user, so call `->fresh()` on any other user you carry across.
- **Test helpers are global**: Pest loads every test file into one namespace, so two files declaring `function inHouse()` crash the whole suite. `CodingRulesTest` fails on a duplicate; name helpers after their file's subject.
- **Commit as you go**: commit after each logical change, so an interrupted run leaves its work on the branch.
