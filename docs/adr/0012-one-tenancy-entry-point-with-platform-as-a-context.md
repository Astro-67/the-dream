# One Tenancy entry point, with platform as a kind of context

ADR 0008 decided _that_ tenant data is scoped by a global scope and that jobs carry their context. It left open _how_ code enters a context, and architecture draft 1 sketched separate pieces: web middleware that sets a `TenantContext`, a job trait that restores it, `setPermissionsTeamId()` calls next to each, and `Tenancy::asPlatform()` as a bypass. Each entry point would then repeat the same four steps (set the organization, set the property, set the spatie team id, restore everything afterwards), and every repetition is a place to forget one.

The architecture review of 7 October 2026 (candidate 3) proposed collapsing them. We adopt it: **the Tenancy module has one entry point, `Tenancy::within(TenantContext $context, Closure $callback)`**, and web requests, queued jobs, console commands, actions and tests all enter a tenant through it. `within` sets the context, sets the spatie team id to the organization, checks that the property belongs to the organization, runs the callback, and restores the previous context and team id afterwards, even when the callback throws. Contexts nest.

**Platform is a kind of context, not a bypass.** `TenantContext::platform($reason)` is entered through the same `within` call. Inside it, tenant-owned models are not filtered by organization, the spatie team id is cleared, and the entry is written to the activity log with the actor and the reason. It replaces `Tenancy::asPlatform()`, and is still the only way to read across organizations, so it stays easy to find in review.

## Consequences

- `TenantContext` is a small serializable value object of IDs (organization, property, or platform plus reason), so a job can capture `Tenancy::current()` at dispatch and pass it back to `within` when it runs (ticket #10).
- Outside any context, querying or creating a tenant-owned model throws `MissingTenantContext`. Nothing runs unscoped by default.
- Membership checks for choosing a context (`Tenancy::allows`, `Tenancy::defaultContextFor`, and the switcher lists) live in the same module, so web middleware, switching and invitations ask the same question the same way.
- The scope class is used only inside `App\Support\Tenancy`. A Pest architecture test enforces this, so any other bypass fails CI.
