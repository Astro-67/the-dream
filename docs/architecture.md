# Thedream PMS: Architecture

- **Status:** draft 1, 6 October 2026
- **Implements:** [`docs/prd/PRD.md`](prd/PRD.md) v1.1
- **Vocabulary:** [`docs/GLOSSARY.md`](GLOSSARY.md)
- **Decisions:** [`docs/adr/`](adr/), ADRs 0001–0012

This document describes how Thedream is built: the domains, their boundaries, how integration messages move, and how the system is deployed and recovered. The ADRs record _why_ each decision was made. This document records _what_ the shape is. When the two disagree, the ADR wins and this document gets fixed.

---

## 1. System context

```
                         ┌──────────────────────────────────────────────┐
  Staff browser ────────▶│  Thedream (Laravel 12 modular monolith)      │
  (Inertia + React)      │                                              │
                         │  Web ─ Actions ─ Domain ─ Outbox table       │
  Channel manager ──────▶│  Inbound webhooks ─ Inbox table              │
  (webhooks / pull)      │                                              │
                         │  Queue workers (Supervisor) ─ Adapters ──────┼──▶ Channel manager ──▶ Booking.com, other OTAs
                         │  Scheduler (daemon)                          ├──▶ ZAN Gateway (ZRA booking reports)
                         └───────────────┬──────────────────────────────┘──▶ VFMS (ZRA fiscal receipts)
                                         │
                                 RDS MySQL 8 (PITR)       S3 (private files)
```

External systems:

| System          | Direction                  | Purpose                                                   | Reference                                                                                                         |
| --------------- | -------------------------- | --------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------- |
| Channel manager | Both ways                  | Availability, rates and restrictions out; reservations in | ADR 0001, PRD §7.8                                                                                                |
| ZAN Gateway     | Out                        | Booking reports, and property profile (hotel info)        | ADR 0003, PRD §7.9, [`docs/research/sources/zan-gateway-openapi.yaml`](research/sources/zan-gateway-openapi.yaml) |
| VFMS            | Out (request and response) | Fiscal receipting from check-in to check-out              | ADR 0005, PRD §7.10, VFMS guide                                                                                   |

## 2. Technology stack

| Layer           | Choice                                                                                                                                     |
| --------------- | ------------------------------------------------------------------------------------------------------------------------------------------ |
| Backend         | Laravel 13, PHP 8.3, a modular monolith with an Actions layer                                                                              |
| Database        | MySQL 8 (RDS), ULID primary keys                                                                                                           |
| Auth            | Session auth from the Laravel React starter kit (Fortify)                                                                                  |
| Authorization   | spatie/laravel-permission in teams mode (team = organization, ADR 0007), plus Laravel Policies                                             |
| Audit           | spatie/laravel-activitylog                                                                                                                 |
| Queue and cache | Database drivers. Redis only when load requires it (ADR 0006)                                                                              |
| Frontend        | Inertia.js 3, React 19, TypeScript, Tailwind CSS 4, shadcn/ui, Wayfinder, built with Bun                                                   |
| Quality         | Pest 4 (including architecture tests), Pint, Larastan                                                                                      |
| Hosting         | AWS `af-south-1`: EC2 provisioned through Forge, RDS MySQL with PITR, S3. Workers under Supervisor, scheduler as a daemon (ADR 0006, 0011) |

The project was scaffolded on Laravel 13 and Inertia 3, the versions the current React starter kit ships. Composer is pinned to PHP 8.3 (`config.platform.php`), so Pest is held at v4 because Pest 5 needs PHP 8.4.

## 3. Domains

The application is one Laravel app divided into domains under `app/Domains/<Domain>/`. Each domain owns its models, actions, events, policies, data objects and enums. Shared building blocks live in `app/Support/`.

| Domain               | Owns                                                                                                                                               | PRD              |
| -------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------- |
| **Tenancy**          | Organization, Property, memberships, jurisdiction profile                                                                                          | §4, §6, §7.2     |
| **Platform**         | Platform roles (platform administrator, compliance operator) and the platform pages' read models. Later also support sessions and platform secrets | §5, §7.3         |
| **Inventory**        | Room types, rooms, out-of-service periods, availability                                                                                            | §7.4 (PMS-01)    |
| **Rates**            | Rate plans, prices, restrictions (minimum stay, closed to arrival, stop-sell)                                                                      | §7.4 (PMS-01)    |
| **Reservations**     | Reservations, stays, reservation numbers, channel references, no-shows                                                                             | §7.4             |
| **Guests**           | Guests, bookers, identity documents                                                                                                                | §7.5             |
| **FrontDesk**        | Check-in, check-out, room moves, night audit                                                                                                       | §7.4 (PMS-04–06) |
| **Billing**          | Folios, charges, payments, adjustments, receipts, channel-collected amounts, fiscal exceptions                                                     | §7.6             |
| **Housekeeping**     | Room status and tasks                                                                                                                              | §7.7             |
| **Distribution**     | Channel connections, mappings, inbound and outbound channel messages, reconciliation. Interface: `ChannelConnector`                                | §7.8             |
| **ZanReporting**     | Booking reports and hotel-info sync. Interface: `ZanReporter`                                                                                      | §7.9             |
| **FiscalReceipting** | VFMS transactions. Interface: `FiscalReceipter`                                                                                                    | §7.10            |
| **Reports**          | Occupancy, ADR, RevPAR, channel mix, group reports, and the compliance dashboard                                                                   | §7.11            |

Not yet a domain folder, and added when their first ticket needs them:

- **Subscriptions:** plans, SaaS invoices, entitlements (§7.3, §13).
- **Compliance incidents and latency monitoring:** a Compliance domain if they outgrow Reports and the outbox (§7.9, §7.11).
- **Platform administration** beyond roles and the organization list: support sessions and platform secrets (§5, §10), in the Platform domain.

Users, login, registration and MFA stay where the starter kit puts them (`app/Models/User.php`, Fortify actions).

### 3.1 Layout

```
app/Domains/<Domain>/
  Actions/ Models/ Events/ Listeners/ Policies/ Data/ Enums/ Exceptions/
app/Domains/{Distribution,ZanReporting,FiscalReceipting}/
  Contracts/        ChannelConnector.php, ZanReporter.php, FiscalReceipter.php
  Adapters/         Vendor adapters and Fake* adapters
app/Support/
  Tenancy/          Tenancy::within(), TenantContext, BelongsToTenant, BelongsToProperty
  Outbox/           Shared record, delivery engine, sweeper, recovery and restore gate
  Money/            Money value object, currency
  Jobs/             TenantAware marker and the queue handler that runs queued work in Tenancy::within()
  Console/          TenantCommand base class (--organization, --property; runs handle() in Tenancy::within())
app/Http/Controllers/Web/      Inertia controllers, grouped by domain
app/Http/Controllers/Api/V1/   Inbound webhooks and any future API
routes/web.php                 Loads every file in routes/web/ (one file per domain)
resources/js/pages/            Inertia pages (starter kit structure kept)
resources/js/features/<domain>/  Domain-specific React components and hooks
resources/js/components/shared/  Cross-domain components; components/ui stays shadcn
tests/Feature/{Reservations,Isolation,Integrations}/
tests/Fakes/                   Fake transports shared across tests
database/migrations/           One folder, migration names prefixed by domain
```

### 3.2 Boundary rules

These are enforced by Pest architecture tests (`arch()`), so a violation fails CI:

1. **Writes go through the owning domain's actions.** Another domain may read a model, but it changes data only by calling that domain's action.
2. **Core domains never touch adapters or write outbox rows directly.** Reservations, FrontDesk and Billing call integration-owned recording operations through `ChannelConnector`, `ZanReporter` and `FiscalReceipter`. Only integration domains call the shared outbox `record`; an architecture test enforces this boundary. Core domains never reference an adapter class or an HTTP client (ADR 0010).
3. **Every tenant-owned model uses `BelongsToTenant`** (§4).
4. **Controllers stay thin.** They authorize through a Policy, validate through a Form Request, call one action, and return an Inertia response.
5. **No `env()` outside config files.** Secrets are read through config.

### 3.3 Actions

One class per use case, with a single `handle()` method. An action:

1. receives an already-authorized, validated input (a DTO or Form Request data);
2. opens a database transaction;
3. changes domain state;
4. calls integration recording operations for every integration effect (§5) in the **same transaction**, holding the subject lock used to allocate its stream sequence; recording requires the caller's transaction and never opens an independent one;
5. records activity through activitylog;
6. dispatches domain events after commit.

Actions are what the HTTP layer, jobs, imports and tests all call. No business logic lives in controllers, jobs or models' observers.

## 4. Tenancy and authorization

### 4.1 Tenant context (ADR 0008, ADR 0012)

Everything about "which tenant am I acting for" goes through one module, `App\Support\Tenancy`, and one entry point:

```php
Tenancy::within(TenantContext::forProperty($property), fn () => ...);       // organization + property
Tenancy::within(TenantContext::forOrganization($organization), fn () => ...); // organization only
Tenancy::within(TenantContext::platform('ZAN Gateway drain'), fn () => ...);  // across organizations, logged
```

- `TenantContext` is a value object of IDs: an organization and optionally a property, or "platform" plus a reason. It is serializable, so jobs can carry it. `TenantContext::fromIds($organizationId, $propertyId)` rebuilds one from stored IDs.
- `within` sets the context and spatie's team ID (`setPermissionsTeamId($organization->id)`), checks that the property belongs to the organization, runs the callback, and restores the previous context and team ID afterwards, even on exceptions. Contexts nest.
- Reading the context: `Tenancy::current()`, `Tenancy::organization()`, `Tenancy::property()`, `Tenancy::isPlatform()`.
- Choosing a context for a user (membership rechecked on every call): `Tenancy::allows($user, $context)`, `Tenancy::defaultContextFor($user)`, and for the switcher `Tenancy::organizationsFor($user)` and `Tenancy::propertiesFor($user)` (in the current organization).
- **Web:** the `tenant` route middleware (`SetTenantContext`) reads the organization and property from the session, rechecks them with `Tenancy::allows`, falls back to `Tenancy::defaultContextFor`, and wraps the rest of the request in `within`. A user with no membership is sent to organization setup. It runs before `SubstituteBindings`, so route model binding is already scoped. `tenant:optional` sets the context when there is one but never redirects (used by user settings pages).
- **Jobs (#10):** anything queued inside `within` (jobs, mail, notifications, listeners) captures `Tenancy::current()` in its queue payload, and `CallQueuedTenantHandler` re-enters that context with `within` on the worker, before the job is unserialized, so its models are restored, its middleware and `handle()` run, and its chain continues inside the tenant. The worker's state is restored after each job. Tenant models are restored with the tenant scope, so a job handed another tenant's record fails with `ModelNotFoundException`. A job marked `implements TenantAware` must have a tenant: dispatched outside one (or from the platform context) it fails at once with `MissingTenantContext`, without retries. Dispatch inside a block closure: `fn () => Job::dispatch()` queues the job only after `within` has returned.
- **Console:** a command that acts for one tenant extends `App\Support\Console\TenantCommand`, which adds `--organization=<id>` (required) and `--property=<id>` and runs `handle()` inside `within`. Commands working across organizations enter `TenantContext::platform($reason)` themselves.
- **Tests:** call `within` directly. Pest helpers: `organizationWithOwner()` builds an organization, its first property and its owner through the real action; `inTenant($organizationOrProperty, fn)` wraps `within`.
- **Scoping:** `BelongsToTenant` (organization-owned models) adds a global scope on `organization_id`; `BelongsToProperty` (property-owned models) adds `property_id` too when a property is in context. `Organization` itself is scoped to the current organization. Creating a model fills both IDs from the context, and a mismatching ID or a change of `organization_id` throws `TenantMismatch`.
- **Fail closed:** querying or creating a tenant-owned model with no context throws `MissingTenantContext`.
- **Platform context:** the only way to read across organizations. Tenant scopes don't filter, the spatie team ID is cleared, created records must name their organization explicitly, and entering it is written to the activity log (`log_name` `platform`) with the actor (the signed-in user as causer, and the running queued job or console command as the `job` or `command` property) and the reason. Queued work dispatched inside the platform context runs in it too, and logs its own entry. The scope class is used only inside `App\Support\Tenancy`, enforced by an architecture test.

### 4.2 Authorization (ADR 0007)

- spatie teams mode: **team = organization**. Roles such as owner, group manager, property manager, front desk, finance, housekeeping and auditor are assigned within an organization.
- `property_memberships` lists the properties each organization member may act in.
- **Policies** check both: the role has the permission, **and** the record's property is in the user's property memberships. Organization-level roles (owner, group manager) may be granted all properties.
    - Every policy does this through one helper, `App\Support\Tenancy\Access::allows($user, $permission, $propertyOrRecord = null)`, judged in the current organization (null = an organization-wide action).
    - Permission names live in the `OrganizationPermission` enum; each role's set is `OrganizationRole::permissions()`, seeded per organization by `ProvisionOrganizationRoles`. After changing a set, run `php artisan organizations:sync-roles` to update existing organizations.
- **Platform roles** (platform administrator, compliance operator) are separate and never come from organization membership (#11). They live in the `platform_role_assignments` table, not in spatie's teams, so no organization role or invitation can carry one; an architecture test keeps the model inside the Platform domain. `AssignPlatformRole` / `RevokePlatformRole` are the only way in: called by a platform administrator (from the Platform staff page) or with no actor by a seed or `php artisan platform:assign-role {email} {role}` / `platform:revoke-role`. Each change is written to the activity log (`log_name` `platform`). Read with `$user->hasPlatformRole(PlatformRole::Administrator)`, checked against the database on every call.
- **Platform pages** (`routes/web/platform.php`, under `/platform`) use the route middleware `platform:<role>` (403 for anyone without that platform role, organization owners included) followed by `two-factor`. They sit outside the `tenant` middleware, and anything they read across organizations runs in `TenantContext::platform($reason)`, so each view is logged. The organization list shows name, owners, active property count and created date, and nothing from inside organizations. A platform administrator with no membership who opens an operational page is sent to the platform pages instead of organization setup.
- **`two-factor` route middleware** (`RequireTwoFactor`): the user must have confirmed two-factor authentication. Otherwise a page visit or form post is redirected to `security.edit` with an error toast, and a JSON request gets 403. Put it after the middleware that decides access, so people without access get 403 rather than a 2FA prompt. Platform pages use it.
- **Owner-only actions need 2FA (#13).** A route is owner-only when it takes `two-factor:owner`, placed after `tenant`: owners of the current organization (`Access::isOwner`) without confirmed 2FA are sent to set it up, and every other role passes untouched. It is a gate on owners as people, not a permission: what anyone may do is still decided by policies with `Access::allows`. Owner-only today: property management (`routes/web/properties.php`, everything but the switcher) and member management (`routes/web/invitations.php`, sending, listing and revoking; not accepting; and `routes/web/members.php`, the members list, changing access, removing members and transferring ownership). A new owner from a transfer must turn 2FA on before using them. Organization-settings routes join by adding the middleware to their group, e.g. `Route::middleware(['auth', 'verified', 'tenant', 'two-factor:owner'])->group(...)`, and a case in `ownerOnlyRequest()` in `tests/Feature/Tenancy/OwnerTwoFactorTest.php`, which sends an owner without 2FA to every route carrying the middleware. Turning 2FA off takes owner-only access away on the next request.
- **Returning after 2FA setup:** when either gate redirects, it keeps the reason and where to return (the page asked for, or for a form post the same-site page it came from) in the session. The security settings show the reason and, once 2FA is on, a Continue link back. Passing any two-factor gate clears it. Test fixtures (`organizationWithOwner()`, `twoTenants()`, `memberOfBoth()` with an owner role) give owners 2FA, so tests reach owner-only routes; pass your own owner to `organizationWithOwner(owner: ...)` to test without it.
- **Inertia shared props** carry the current organization, property, and the user's permissions for that property, so the UI can hide actions, plus `auth.platformRoles` for platform navigation. The server still checks every request.

### 4.3 Cross-tenant test suite

A Pest suite seeds two organizations with two properties each, and asserts that a user of one cannot reach the other's data through:

- every web route, including by guessing IDs;
- queued jobs dispatched with one tenant's context;
- exports and download links;
- files in storage;
- integration queues and the compliance dashboard.

New routes are checked automatically: the suite enumerates registered routes, so a new route without a cross-tenant test fails. Coverage is declared per route in `tests/Feature/Isolation/Coverage/` (one file per domain; how-to in the README there), and the fixture is the `twoTenants()` Pest helper. A separate test fails any model whose table has `organization_id` or `property_id` but lacks `BelongsToTenant` or `BelongsToProperty`.

### 4.4 Archiving a property and pre-archive checks

An owner archives a property with `ArchiveProperty` (and brings it back with `RestoreProperty`); both are audited. An archived property (`archived_at` set) is left out of `Tenancy::allows`, `defaultContextFor` and `propertiesFor`, so it drops out of the switcher and nobody can work in it, while its records and audit history stay and it is still listed on the properties page. Archiving the organization's last active property is refused.

Before archiving, `ArchiveProperty` runs every **pre-archive check**: a class implementing `App\Domains\Tenancy\Contracts\PreArchiveCheck`, whose `blockingReason(Property)` returns null to allow archiving or a sentence telling the owner what stops it. Checks run inside the property's tenant context, and the owner sees every failing reason. The M1 outbox foundation registers a check blocking archival while the property has unresolved messages (including unknown, rejected, exhausted or blocked work), so recovery remains possible. Other domains add checks by tagging them in their service provider:

```php
$this->app->tag([NoFutureBookings::class], PreArchiveCheck::TAG); // e.g. Reservations; also open balances (Billing), undelivered ZRA reports (ZanReporting)
```

## 5. Integration messaging: the outbox (ADR 0009)

The confirmed M1 scope and acceptance criteria are in [Spec 2: integration outbox foundation](spec-2-integration-outbox.md). Operational restore steps are in the [outbox restore runbook](outbox-restore-runbook.md).

### 5.1 Record and ownership

All outbound integrations use one tenant- and property-scoped `outbox_messages` table with an `integration` column (ZAN Gateway, VFMS or channel manager). These rows and their attempt/revision history are the **record of truth** for delivery, retries, the delivery log (ZAN-08), compliance dashboard (RPT-04) and latency monitoring. The queue's `jobs` table is just transport.

| Field                                                                          | Meaning                                                                                                                     |
| ------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------- |
| `id`, `organization_id`, `property_id`, `integration`                          | ULID, tenant/property scope and integration identity                                                                        |
| `subject_type`, `subject_id`, `sequence`                                       | Ordered stream: organization + property + integration + subject type + subject ID. ZAN uses a reservation; VFMS uses a stay |
| `idempotency_key`                                                              | Stable stream-scoped operation identity, not just subject ULID + sequence                                                   |
| `event`, `payload`                                                             | Original operation and encrypted snapshot built inside the domain transaction                                               |
| `status`                                                                       | `pending`, `sending`, `delivered`, `unknown`, `rejected`, `failed` (retries exhausted), or `blocked` successor              |
| Attempts, claim token/expiry, retry timing, provider references, delivery time | Durable delivery state; encrypted request/response and revision history retained separately                                 |

Database uniqueness protects both sequence and idempotency key within the stream. Only integration domains call shared `record`, enforced by an architecture test; they choose jurisdiction, subject, operation and payload. Recording requires the caller's existing transaction and subject lock; allocate from the stream's highest existing sequence under that lock. Credential or adapter changes do not reset the stream. Recording never commits independently.

`App\Support\Outbox` owns recording mechanics, claims, ordering, batching, quotas, retries, recovery and the restore gate. Integrations supply transport adapters and **delivery policy as data**: batch grouping, maximum size, quota identity/limit, and ordered versus latest-only mode (including its scoped coalescing key). They do not each implement a drainer.

### 5.2 Delivery

1. **After commit**, enqueue a drain. A **minute sweeper** rediscovers due pending work, missed dispatches and expired claims; the queue is not the durable delivery record.
2. In a short database transaction, claim eligible work with a fenced token and expiry. An ordered stream has **one in-flight message**; a batch may combine messages from independent streams only. Lower sequences must be delivered: rejected, exhausted and unknown messages do not count as completed and hold successors.
3. Call the adapter's `send(messages)` **outside any database transaction**. It returns exactly one result per input: `accepted`, `rejected`, `retryable` or `unknown`, with provider references and retry timing where available. HTTP status alone, including 200 or a duplicate error, is not acceptance.
4. Persist results only if the claim token is still current. Expired attempts become **unknown**, not blindly pending; a late worker cannot overwrite a newer claim or recovery decision. Only explicit acceptance marks a message delivered. A confirmed retryable result schedules an unchanged retry with policy backoff and provider timing. A timeout or crash that leaves acceptance uncertain is unknown.
5. Unknown work retains its payload, key and sequence. Retry it unchanged only under a **verified provider idempotency contract**; otherwise reconcile delivery first. Rejection or exhausted retries blocks an ordered stream; exhaustion never converts an uncertain outcome into confirmed rejection. Authorized operators may restart unchanged retries under the same safety rules; there is no generic skip.

Quota identities and limits are policy data, coordinated globally across workers. Respect provider retry timings and isolate integrations so one integration's quota or backoff does not stop the others.

**Latest-only work:** the integration defines a key scoped by organization, property, integration and the state being replaced (for example channel connection, room type and dates). Coalesce only **unattempted pending** snapshots. An in-flight snapshot is immutable and newer state waits as a successor. Unknown delivery holds that key until resolved; confirmed rejection does not block a newer state. This is not permission to skip ordered compliance operations.

### 5.3 Per-integration rules

**ZAN Gateway (ZanReporting)**

- Ordered per reservation. Batch by property as `{ hotel_code, bookings[] }`, at most one message per reservation; maximum batch size is policy data.
- The platform quota is **100 requests per minute** (PRD I-5), shared across properties and workers through one global quota identity.
- Classify each booking's response (`rejected_booking_count`, `validation_errors`), not just the HTTP response (ZAN-06).
- ZAN has no idempotency field. Upsert by hotel code + reservation number is **not proof** that replaying an older lifecycle snapshot is harmless. Replay unknown or restored work only under a verified safe contract; otherwise hold it for reconciliation.
- Cross-organization draining enters `Tenancy::within(TenantContext::platform(...))` with an audited reason; claiming and sending preserve each message's property scope (ADR 0003).

**VFMS (FiscalReceipting)**

- Ordered per stay, one message per call. Check-out waits for every earlier call's explicit acceptance (FSC-07).
- `referenceNumber` carries the idempotency key. Duplicate-reference behaviour and corrected-payload key reuse remain **unverified**; a duplicate error alone never means delivered. Reconcile against the accommodation report and verify acceptance before proceeding.
- Credentials are per property (FSC-01). After restore, VFMS delivery **must be reconciled**, not assumed safe to replay.

**Channel manager (Distribution)**

- Availability and rates use latest-only policies with integration-defined scoped keys; other operations declare their own ordering policy.
- Pass the key through a provider-supported idempotency header. Support is a selection criterion (PRD §9), not a guarantee. Unknown delivery and post-restore replay require a verified safe contract or reconciliation; coalescing alone is not proof of safety.

### 5.4 Recovery and privacy

Owners and property managers may recover work only within their authorized organization/properties. A platform **compliance operator** may recover **ZAN/VFMS only**, never channel-manager work. M1 exposes recovery through commands, not a UI: every invocation names an explicit actor, property and reason, checks the applicable policy, and audits the outcome. Entering platform context does not replace authorization.

An authorized correction is allowed only after **confirmed rejection**, not unknown delivery or generic exhaustion. Preserve the original operation; keep its key and sequence only when the provider contract verifies key reuse with corrected data, otherwise leave it blocked. Under the subject lock, with no active attempt, atomically revise the rejected snapshot and consistently rebuild affected blocked snapshots from their original operations. Preserve immutable attempt/revision history and audit the actor/reason; validation, rebuilding or audit failure rolls back **all** changes. Release eligible delivery only after commit. No recovery path silently skips an ordered operation.

Encrypt payloads, response bodies and revision/attempt history at rest. Credentials never enter those snapshots, logs or audits. Logs and ordinary delivery views expose metadata and hashes only; raw payload/history reads require a **separate policy** from recovery or metadata access. M1 includes no pruning. Provider-specific production retention policy and deletion tooling are prerequisites before real integration activation, not an indefinite-retention policy.

### 5.5 Latency monitoring and milestone scope

A later scheduled check runs every minute over undelivered `outbox_messages` filtered to ZAN, measuring age from the domain event's time:

- **3 minutes:** alert the compliance operator.
- **5 minutes:** open a `ComplianceIncident` (PRD ZAN-05).

Delivery latency (event time to `delivered_at`) is recorded for the p95 target.

**M1 foundation** includes the shared record/engine, policy and adapter contracts, scoped recovery commands, archival check, persistent restore gate and fake-contract tests. It includes **no real provider clients, lifecycle UI or provider alerts**. Fake tests prove the engine's safety rules, not provider compatibility, idempotency or replay safety. Real-provider restore/reconciliation contracts are M3 work; the full restore exercise remains the M4 release gate (§12).

## 6. Inbound channel messages

1. The channel manager webhook controller (or the pull job, depending on the vendor) **stores the raw message** in `channel_inbound_messages` and acknowledges only after the insert commits (DST-04).
2. A job processes the message through Distribution actions, which call Reservations actions.
3. **Duplicates:** messages are unique on (channel connection, channel message ID or channel reference + version). A repeat is marked processed with no effect.
4. **Out-of-order changes:** each reservation stores the channel's last applied version or modification timestamp. An older message is recorded but not applied.
5. Mapping failures (unknown room type or rate plan) and disallowed currencies (DST-05) leave the message `rejected`, with an alert, and the reservation isn't created.

## 7. The Booking.com lifecycle in code

How PRD §8 maps onto actions and outbox rows at a Zanzibar property:

All rows below are `outbox_messages`, distinguished by integration; integration recording operations write them inside the caller's transaction.

| Step                           | Action                                                                                       | Integration messages written in the same transaction                                                |
| ------------------------------ | -------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------- |
| Booking arrives                | `Distribution\ProcessInboundReservation` → `Reservations\CreateReservation`                  | ZAN (New / Confirmed Reservation); channel manager (availability)                                   |
| Guest changes the booking      | `Reservations\ModifyReservation`                                                             | ZAN (Modify); channel manager if dates or room type changed                                         |
| Guest cancels                  | `Reservations\CancelReservation`                                                             | ZAN (Cancel / Cancel + CancelDate); channel availability. Any fee → `Billing\PostFiscalException`   |
| No-show confirmed              | `FrontDesk\ConfirmNoShow` (proposed by night audit)                                          | ZAN (Cancel / Cancel, guest count 0)                                                                |
| Check-in                       | `FrontDesk\CheckIn` (requires identity documents for every guest under the Zanzibar profile) | ZAN (Modify / Checked In); VFMS (ACCOMMODATION_REQUEST, DIRECT, first night's rate)                 |
| Room move, added guest, extras | `FrontDesk\MoveRoom`, `Guests\AddGuest`, `Billing\PostCharge`                                | VFMS (change room, add guest, services); ZAN where reported fields change                           |
| Night audit                    | `FrontDesk\CloseBusinessDate`                                                                | VFMS (CHANGEROOM_PRICE) when the next night's rate differs                                          |
| Check-out                      | `FrontDesk\CheckOut`                                                                         | ZAN (Modify / Checked Out); VFMS (ACCOMMODATION_CHECK_OUT). The fiscal report prints once delivered |

Whether a property needs ZAN Gateway or VFMS rows is decided by its **jurisdiction profile**, inside the ZanReporting and FiscalReceipting domains. Core actions call `ZanReporter::record(...)` and `FiscalReceipter::record(...)`. For a mainland property these are no-ops.

## 8. Integration interfaces (ADR 0010)

Sketches, not final signatures:

```php
interface ChannelConnector
{
    public function recordAvailability(ChannelConnection $c, AvailabilityChange $change): void;
    public function recordRates(ChannelConnection $c, RateChange $change): void;
    public function recordAcknowledgement(ChannelConnection $c, InboundMessage $message): void;
    /** @return iterable<ChannelReservation> */
    public function listReservations(ChannelConnection $c, DateRange $range): iterable; // daily reconciliation
}

interface ZanReporter
{
    public function record(ReservationEvent $event): void;      // writes the outbox row (called inside the action's transaction)
    public function syncHotelInfo(Property $property): void;    // records an integration-owned snapshot, not an HTTP call
}

interface FiscalReceipter
{
    public function record(FiscalEvent $event): void;           // writes the outbox row
    public function exchangeRate(Currency $from): ExchangeRate;
}
```

Each integration domain owns recording semantics, transport and delivery policy data; the shared engine owns delivery mechanics:

- **Recording** writes through shared `record` inside the caller's transaction and subject lock; jurisdiction rules and payload builders stay in the integration domain.
- **Delivery adapters** expose `send(messages)`, returning one accepted/rejected/retryable/unknown result per input with provider references and retry timing. Wire format and provider-specific response classification stay here; ordering, claiming, batching, quotas and retries do not.
- **Policy data** declares batch group/size, quota identity/limit and ordering/latest-only keys (§5). Read/reconciliation operations such as `listReservations` and `exchangeRate` are separate from outbound recording.

**Fakes** (`FakeChannelConnector`, `FakeZanTransport`, `FakeVfmsTransport`) are bound in tests. They simulate partial results, retry timings, unknown acceptance, expired claims and duplicates. Their contracts validate the foundation only; real-provider compatibility requires staging contract tests.

## 9. Data conventions

| Concern            | Convention                                                                                                                                                                                                                                                                                                        |
| ------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Primary keys       | ULIDs (`HasUlids`) for application-owned models, including users, tenancy and platform tables. Framework/vendor storage tables retain their package-defined key types: passkeys, Spatie roles and permissions, activity log, and queue tables (`jobs`). Permission team and model morph keys are ULID-compatible. |
| Money              | Integer minor units plus an ISO currency code. A value object, never floats. VFMS "EURO" is mapped to and from EUR at the adapter                                                                                                                                                                                 |
| Reservation number | A `reservation_number_sequences` row per property, incremented under `SELECT … FOR UPDATE` in the creating transaction. Formatted per property, never reused (PRD PMS-03). Separate from the ULID                                                                                                                 |
| Channel reference  | Stored on the reservation, unique per channel connection                                                                                                                                                                                                                                                          |
| Business date      | Stored per property, as the property's local business date. Timestamps are UTC                                                                                                                                                                                                                                    |
| Secrets            | ZRA supplier key in the server environment (Forge), read through config. VFMS and channel-manager credentials use Laravel's `encrypted` cast. Key rotation uses `APP_PREVIOUS_KEYS`                                                                                                                               |
| Identity documents | Stored on Guest with the `encrypted` cast. Visible only through policy-checked resources. Never written to logs or analytics                                                                                                                                                                                      |
| Audit              | activitylog on sensitive models and actions, with organization and property IDs as properties. Secrets and document numbers excluded                                                                                                                                                                              |
| Files              | S3 private bucket, with tenant-prefixed paths and short-lived signed URLs checked against policy                                                                                                                                                                                                                  |

## 10. Scheduled work

The scheduler runs under Supervisor as `php artisan schedule:work`.

| Schedule                                            | Task                                                                       |
| --------------------------------------------------- | -------------------------------------------------------------------------- |
| Every minute                                        | Outbox sweeper (§5.2)                                                      |
| Every minute                                        | ZAN Gateway latency monitor (§5.5)                                         |
| Every minute                                        | Night audit trigger: properties whose local business date is due for close |
| Daily, per property, after the business date closes | Channel reconciliation (DST-07)                                            |
| Daily                                               | VFMS exchange rate fetch (FSC-09)                                          |
| Daily                                               | Fiscal exceptions digest to property managers                              |
| Hourly                                              | Subscription lifecycle transitions                                         |

## 11. Deployment

| Component | Production                                                                                                                                                                     |
| --------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Region    | AWS `af-south-1` (opt-in region, ADR 0011). The code stays region-agnostic                                                                                                     |
| App       | EC2 provisioned through Forge. Nginx and PHP-FPM 8.3                                                                                                                           |
| Workers   | Supervisor processes, named per queue so a slow integration can't starve the others: `zan`, `vfms`, `channels`, `default`                                                      |
| Scheduler | `schedule:work` under Supervisor                                                                                                                                               |
| Database  | RDS MySQL 8 with automated backups and PITR. Same region, private subnet                                                                                                       |
| Files     | S3 private bucket, same region                                                                                                                                                 |
| TLS       | Let's Encrypt through Forge                                                                                                                                                    |
| Deploys   | Forge zero-downtime deploy, then `php artisan queue:restart`. Mid-delivery crashes become unknown; fenced claims and verified retry/reconciliation (§5.2) prevent blind replay |

**Environments**

| Environment | ZRA endpoints                           | Channel manager |
| ----------- | --------------------------------------- | --------------- |
| Local       | Fakes                                   | Fake            |
| Staging     | ZAN Gateway staging, VFMS test          | Vendor sandbox  |
| Production  | ZAN Gateway production, VFMS production | Live            |

**Unverified:** the VFMS production URL, and whether it uses TLS. The test endpoint is plain HTTP on port 6060, so the security group's outbound rules must allow it.

## 12. Runbook: restore and post-restore reconciliation

PITR can restore pending/sending rows whose operations were already accepted externally, and erase newer domain changes, message identities, sequences and attempt history that providers have seen. **A persistent restore gate blocks both delivery and integration-producing writes**, across web, jobs and commands, until recovery is verified. Maintenance mode and stopped workers alone are not the gate.

1. Put the app in maintenance mode, close the restore gate and stop Supervisor workers and the scheduler.
2. Restore RDS and point the app at it with the gate still closed; verify the restored database has not reopened an older gate state before any app work resumes.
3. Run the existing command sketch `php artisan outbox:reconcile-after-restore`, with an explicit actor, property scope and reason under the recovery policy (§5.4). Treat uncertain restored attempts as unknown and reconcile the affected window, not just rows currently pending.

    | Integration     | Required evidence                                                                                                                                                                                             |
    | --------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
    | VFMS            | Reconcile accommodation reports against the original operation and `referenceNumber`; explicit acceptance records delivery and provider references. Absence is not proof that retry or identity reuse is safe |
    | ZAN Gateway     | No read endpoint in the public spec. Keep unresolved work gated unless reconciliation or a verified contract establishes safe replay; upsert semantics alone are insufficient                                 |
    | Channel manager | Use vendor delivery/history APIs where available. Unmatched operations stay unresolved unless a verified safe contract permits replay; latest-only coalescing is not sufficient                               |

4. Recover **missing identities and delivery/history records** from after the restore point, or establish a **verified safe identity boundary** that cannot collide with externally seen operations. The restored maximum sequence alone is insufficient. Reconcile lost domain changes too; channel re-pull (DST-07) covers incoming bookings, not all outbound or fiscal history.
5. Review scoped reconciliation evidence, unresolved work and identity safety. Keep the gate closed until both delivery reconciliation **and** missing identity/history recovery or a verified safe boundary are complete; audit the authorized decision to reopen it. Restart workers/scheduler and leave maintenance mode only then.
6. Open a `ComplianceIncident` covering the window, for the record.

M1 implements the persistent gate and tests this process with fake contracts, not real provider recovery. VFMS reconciliation and verified ZAN/channel restore contracts are **M3** work. A **restore exercise**, including these contracts and identity recovery, is a release gate for M4 (PRD §14).

## 13. Fallback: cPanel hosting

This applies only if the client rejects a VPS (ADR 0006).

**Changes**

- **Delivery after commit runs inside the web request** (`dispatch(...)->afterResponse()` handled synchronously), because no long-running worker is waiting. It uses the same fenced claims, restore gate, quota/retry policy and no-HTTP-in-transaction rule; ending a request is not proof of delivery.
- **Cron** runs `schedule:run` every minute. The schedule starts `queue:work --max-time=55 --stop-when-empty` with `withoutOverlapping()`.
- **Outbound port 6060** must be confirmed open with the host before choosing this option, or VFMS is unreachable.

**Latency examples for one booking report**

| Case                                  | Delay                                                                                                            |
| ------------------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| In-request attempt succeeds           | about 1–3 s                                                                                                      |
| Confirmed retryable in-request result | Next cron tick (up to 60 s) plus policy backoff/provider retry timing                                            |
| A second confirmed retryable result   | Next tick plus the applicable backoff/provider retry timing                                                      |
| The host kills the worker mid-batch   | Expired claims become unknown. No bounded delivery time: verified idempotent retry or reconciliation is required |

**Targets relaxed for this option**

| PRD target                     | VPS                   | cPanel fallback                                                                                                                                      |
| ------------------------------ | --------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------- |
| Booking report delivered (p95) | 1 minute              | 2 minutes                                                                                                                                            |
| Alert threshold                | 3 minutes             | 3 minutes (unchanged)                                                                                                                                |
| Compliance incident            | 5 minutes             | 5 minutes (unchanged; more likely to trigger)                                                                                                        |
| Availability                   | 99.9%                 | 99.5%                                                                                                                                                |
| Recovery point                 | 15 minutes (RDS PITR) | 15 minutes only if the database is still a managed external one. Shared-host MySQL backups are typically daily, which means a 24-hour recovery point |

## 14. Testing strategy

| Layer                                       | What                                                                                                                                                                                                                                                                                                   |
| ------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Unit (Pest)                                 | Money, reservation number sequences, payload builders for ZAN Gateway and VFMS (fixtures from the spec and guide), status mapping (PRD §7.9 table)                                                                                                                                                     |
| Feature (Pest)                              | Every action, with fake adapters. The **full Booking.com lifecycle** from PRD §8 as one scenario, asserting the exact outbox rows and their order                                                                                                                                                      |
| Failure                                     | Fake-contract tests cover missed dispatch, expired claims/fencing, per-input partial results, quotas/retry timing, stream order, latest-only races, unknown delivery, atomic correction/audit rollback, scoped recovery, archival and restore identity gates. They do not prove provider compatibility |
| Cross-tenant                                | The suite in §4.3                                                                                                                                                                                                                                                                                      |
| Architecture (`arch()`)                     | Boundary rules in §3.2                                                                                                                                                                                                                                                                                 |
| Contract (staging, manual or scheduled; M3) | Real-provider tests verify payloads, per-message acceptance, duplicate/corrected-key behaviour, and restore reconciliation/replay safety; fake contracts are not evidence                                                                                                                              |
| Static                                      | Larastan (level agreed with the team), Pint in CI                                                                                                                                                                                                                                                      |

## 15. Go-live gates (technical)

- The PDPA 2022 cross-border question is resolved for `af-south-1` before the first real guest record (ADR 0011, PRD §16).
- `af-south-1` is enabled on the AWS account.
- Thedream's own ZRA supplier key is issued, with the ZAN Gateway staging contract test passing.
- VFMS test credentials are issued, and the VFMS production URL and transport are confirmed.
- The channel manager is selected against PRD §9, with its sandbox contract test passing.
- A production retention policy for encrypted outbox bodies and history is approved before real guest data; M1 never prunes them.
- The restore exercise, including post-restore delivery reconciliation and missing identity/history recovery or a verified safe identity boundary, passes.
- The cross-tenant suite and the Booking.com lifecycle scenario pass in CI.

## 16. Decision index

| ADR                                                                    | Decision                                                         |
| ---------------------------------------------------------------------- | ---------------------------------------------------------------- |
| [0001](adr/0001-channel-manager-before-direct-booking-com.md)          | Connect to Booking.com through a channel manager first           |
| [0002](adr/0002-zanzibar-compliance-pack-is-p0.md)                     | The Zanzibar compliance pack is P0, only for Zanzibar properties |
| [0003](adr/0003-thedream-is-the-single-zra-pms-supplier.md)            | Thedream is the single ZRA PMS supplier                          |
| [0004](adr/0004-thedream-never-receives-raw-card-data.md)              | Thedream never receives raw card data                            |
| [0005](adr/0005-ota-stays-are-receipted-as-direct-in-vfms.md)          | OTA stays are receipted as DIRECT in VFMS                        |
| [0006](adr/0006-vps-hosting-with-long-running-workers.md)              | VPS hosting with long-running workers; cPanel as fallback        |
| [0007](adr/0007-permission-teams-are-organizations.md)                 | Permission teams are organizations                               |
| [0008](adr/0008-tenant-scoping-by-global-scope-and-job-context.md)     | Tenant isolation by global scope and job context                 |
| [0009](adr/0009-transactional-outbox-for-integrations.md)              | Transactional outbox for integrations                            |
| [0010](adr/0010-integrations-are-modules-behind-interfaces.md)         | Integrations are modules behind interfaces                       |
| [0011](adr/0011-aws-af-south-1-with-managed-mysql.md)                  | AWS af-south-1 with managed MySQL and PITR                       |
| [0012](adr/0012-one-tenancy-entry-point-with-platform-as-a-context.md) | One Tenancy entry point, with platform as a kind of context      |
