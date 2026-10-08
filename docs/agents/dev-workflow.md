# Dev workflow

Commands live in `composer.json` and `package.json` scripts; this file records what they don't say.

## Checks

Run all of these before reporting work done:

| Check                          | Command                                        |
| ------------------------------ | ---------------------------------------------- |
| Pest (SQLite, in memory)       | `php artisan test --compact`                   |
| Pest on MySQL 8, as CI runs it | `composer test:mysql`                          |
| Pint                           | `vendor/bin/pint --test`                       |
| Larastan                       | `vendor/bin/phpstan analyse --memory-limit=1G` |
| Build                          | `bun run build`                                |
| Types                          | `bun run types:check` (after the build)        |
| Lint and format                | `bun run check`                                |

Local Pest runs on SQLite; CI runs it on MySQL. They differ, for example in JSON key order and column types. Run `composer test:mysql` before merging anything that touches migrations, JSON columns, or queries. It starts a throwaway container and never touches the dev database.

## The dev database

`.env` points at the local MySQL database `the_dream`. Leave its data alone: never run `migrate:fresh`, `db:wipe` or seeders against it unless the user asks.

Run `php artisan migrate` after pulling. Wayfinder reads the **live** schema when it generates route types, so a stale database makes `bun run build` emit wrong parameter types and `types:check` fails.

## Worktrees

A fresh worktree has no `vendor/`, `node_modules/`, `.env` or build output.

1. `cp .env.example .env && php artisan key:generate`. The main checkout's `.env` holds real credentials; leave it there.
2. `composer install` and `bun install --frozen-lockfile`. Copy `vendor/` rather than symlinking it: a symlinked `vendor/` autoloads the main checkout's `app/`, so tests run the wrong code.
3. Build against a disposable SQLite file, never the shared dev database:

    ```
    touch .scratch/build.sqlite
    DB_CONNECTION=sqlite DB_DATABASE=$PWD/.scratch/build.sqlite php artisan migrate --force
    DB_CONNECTION=sqlite DB_DATABASE=$PWD/.scratch/build.sqlite bun run build
    ```

`.scratch/` is gitignored; keep shared agent notes there too, so they survive a session restart.

Build before running the full Pest suite: without the Vite manifest every page test fails with `ViteManifestNotFoundException`.

## Gotchas

- **New Inertia page**: rebuild before running its feature test; the test fails while the page is missing from the Vite manifest.
- **Dispatching inside `Tenancy::within`**: use a block closure (`function () { Job::dispatch(); }`). An arrow function returns the `PendingDispatch`, which dispatches after the context has ended.
- **Users across a team switch**: spatie caches roles on each user object. `within` clears them only for the signed-in user, so call `->fresh()` on any other user you carry across.
- **Commit as you go**: commit after each logical change, so an interrupted run leaves its work on the branch.
