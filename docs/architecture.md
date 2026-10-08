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
                         │  Web ─ Actions ─ Domain ─ Outbox tables      │
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
  Outbox/           Outbox model base, drainer, sweeper, idempotency keys
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
2. **Core domains never touch adapters.** Reservations, FrontDesk and Billing call the `ChannelConnector`, `ZanReporter` and `FiscalReceipter` interfaces, or write outbox rows. They never reference an adapter class or an HTTP client (ADR 0010).
3. **Every tenant-owned model uses `BelongsToTenant`** (§4).
4. **Controllers stay thin.** They authorize through a Policy, validate through a Form Request, call one action, and return an Inertia response.
5. **No `env()` outside config files.** Secrets are read through config.

### 3.3 Actions

One class per use case, with a single `handle()` method. An action:

1. receives an already-authorized, validated input (a DTO or Form Request data);
2. opens a database transaction;
3. changes domain state;
4. writes outbox rows for every integration effect (§5) in the **same transaction**;
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

Before archiving, `ArchiveProperty` runs every **pre-archive check**: a class implementing `App\Domains\Tenancy\Contracts\PreArchiveCheck`, whose `blockingReason(Property)` returns null to allow archiving or a sentence telling the owner what stops it. Checks run inside the property's tenant context, and the owner sees every failing reason. M1 registers none. A later domain adds one by tagging it in its service provider:

```php
$this->app->tag([NoFutureBookings::class], PreArchiveCheck::TAG); // e.g. Reservations; also open balances (Billing), undelivered ZRA reports (ZanReporting)
```

## 5. Integration messaging: the outbox (ADR 0009)

### 5.1 Tables

Each outbound integration has its own outbox table:

| Table                       | Integration                                             |
| --------------------------- | ------------------------------------------------------- |
| `booking_reports`           | ZAN Gateway                                             |
| `fiscal_transactions`       | VFMS                                                    |
| `channel_outbound_messages` | Channel manager (availability, rates, acknowledgements) |

Common columns:

| Column                                                         | Meaning                                                                                                        |
| -------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------- |
| `id`                                                           | ULID                                                                                                           |
| `organization_id`, `property_id`                               | Tenant scope                                                                                                   |
| `subject_type`, `subject_id`                                   | Usually the reservation                                                                                        |
| `sequence`                                                     | Increases per subject. Defines delivery order                                                                  |
| `idempotency_key`                                              | Subject ULID + sequence (for example `01JA…:7`)                                                                |
| `event`                                                        | For example `checked_in`                                                                                       |
| `payload`                                                      | JSON, built at write time from the committed state                                                             |
| `status`                                                       | `pending` → `sending` → `delivered`, or `rejected` (permanent, needs a person) or `failed` (retries exhausted) |
| `attempts`, `next_attempt_at`, `last_response`, `delivered_at` | Delivery state                                                                                                 |

These rows are the **record of truth** for delivery. The delivery log (ZAN-08), compliance dashboard (RPT-04) and latency alerts all read them. The queue's `jobs` table is just transport.

### 5.2 Delivery

1. The action writes the outbox row inside its transaction (§3.3).
2. **After commit**, a drain job for that subject is dispatched. The Supervisor workers pick it up within about a second, so delivery is attempted immediately.
3. A **sweeper** runs every minute from the scheduler and dispatches drains for any row still `pending` with `next_attempt_at` in the past. That covers crashes, deploys and failed attempts.
4. **Ordering:** a row is sent only when every lower `sequence` for the same subject is `delivered` or terminal. The drain takes a cache lock per subject (database cache driver) so two workers never send the same subject in parallel.
5. **Responses:**
    - success → `delivered`;
    - validation error or other 4xx → `rejected`, with an alert to the compliance operator and property manager;
    - 429, 5xx or timeout → back to `pending` with exponential backoff.

### 5.3 Per-integration rules

**ZAN Gateway (ZanReporting)**

- One request per property: `{ hotel_code, bookings[] }`, holding **at most one row per reservation** (the oldest pending), so events for one reservation never race inside a batch.
- A platform-wide rate limiter allows **100 requests per minute** (PRD I-5), shared across all properties and keyed globally, not per tenant.
- A 200 response is checked **for each booking**: `rejected_booking_count` and `validation_errors` decide each row's status (ZAN-06).
- **Idempotency:** ZAN Gateway has no idempotency field. It upserts by hotel code plus reservation number, so re-sending a row's state is harmless. The idempotency key is kept on our side for the log.
- The drainer runs under `Tenancy::within(TenantContext::platform(...))` because the key and rate limit belong to the platform (ADR 0003).

**VFMS (FiscalReceipting)**

- One call per row, strictly in order per stay. A stay's check-out call is sent only after every earlier call has been delivered (FSC-07).
- **Idempotency:** the VFMS `referenceNumber` ("a unique transaction no.") carries the idempotency key. **Unverified:** how VFMS responds to a duplicate `referenceNumber`. Until it's tested against the VFMS test server, a duplicate-reference error is treated as `delivered` only after confirming the record through the accommodation report call.
- Credentials are per property (FSC-01).

**Channel manager (Distribution)**

- Availability and rate changes for the same room type and date range are **coalesced**: only the latest state is sent.
- The idempotency key is passed as the channel manager's idempotency header if it has one. Support for this is a selection criterion (PRD §9).

### 5.4 Latency monitoring

A scheduled check runs every minute over undelivered `booking_reports`, measuring age from the domain event's time:

- **3 minutes:** alert the compliance operator.
- **5 minutes:** open a `ComplianceIncident` (PRD ZAN-05).

Delivery latency (event time to `delivered_at`) is recorded for the p95 target.

## 6. Inbound channel messages

1. The channel manager webhook controller (or the pull job, depending on the vendor) **stores the raw message** in `channel_inbound_messages` and acknowledges only after the insert commits (DST-04).
2. A job processes the message through Distribution actions, which call Reservations actions.
3. **Duplicates:** messages are unique on (channel connection, channel message ID or channel reference + version). A repeat is marked processed with no effect.
4. **Out-of-order changes:** each reservation stores the channel's last applied version or modification timestamp. An older message is recorded but not applied.
5. Mapping failures (unknown room type or rate plan) and disallowed currencies (DST-05) leave the message `rejected`, with an alert, and the reservation isn't created.

## 7. The Booking.com lifecycle in code

How PRD §8 maps onto actions and outbox rows at a Zanzibar property:

| Step                           | Action                                                                                       | Outbox rows written in the same transaction                                                                                        |
| ------------------------------ | -------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| Booking arrives                | `Distribution\ProcessInboundReservation` → `Reservations\CreateReservation`                  | `booking_reports` (New / Confirmed Reservation); `channel_outbound_messages` (availability)                                        |
| Guest changes the booking      | `Reservations\ModifyReservation`                                                             | `booking_reports` (Modify); `channel_outbound_messages` if dates or room type changed                                              |
| Guest cancels                  | `Reservations\CancelReservation`                                                             | `booking_reports` (Cancel / Cancel + CancelDate); availability. Any fee → `Billing\PostFiscalException`                            |
| No-show confirmed              | `FrontDesk\ConfirmNoShow` (proposed by night audit)                                          | `booking_reports` (Cancel / Cancel, guest count 0)                                                                                 |
| Check-in                       | `FrontDesk\CheckIn` (requires identity documents for every guest under the Zanzibar profile) | `booking_reports` (Modify / Checked In); `fiscal_transactions` (ACCOMMODATION_REQUEST, DIRECT, first night's rate)                 |
| Room move, added guest, extras | `FrontDesk\MoveRoom`, `Guests\AddGuest`, `Billing\PostCharge`                                | `fiscal_transactions` (change room, add guest, services); `booking_reports` where reported fields change                           |
| Night audit                    | `FrontDesk\CloseBusinessDate`                                                                | `fiscal_transactions` (CHANGEROOM_PRICE) when the next night's rate differs                                                        |
| Check-out                      | `FrontDesk\CheckOut`                                                                         | `booking_reports` (Modify / Checked Out); `fiscal_transactions` (ACCOMMODATION_CHECK_OUT). The fiscal report prints once delivered |

Whether a property needs ZAN Gateway or VFMS rows is decided by its **jurisdiction profile**, inside the ZanReporting and FiscalReceipting domains. Core actions call `ZanReporter::record(...)` and `FiscalReceipter::record(...)`. For a mainland property these are no-ops.

## 8. Integration interfaces (ADR 0010)

Sketches, not final signatures:

```php
interface ChannelConnector
{
    public function pushAvailability(ChannelConnection $c, AvailabilityChange $change): DeliveryResult;
    public function pushRates(ChannelConnection $c, RateChange $change): DeliveryResult;
    public function acknowledge(ChannelConnection $c, InboundMessage $message): DeliveryResult;
    /** @return iterable<ChannelReservation> */
    public function listReservations(ChannelConnection $c, DateRange $range): iterable; // daily reconciliation
}

interface ZanReporter
{
    public function record(ReservationEvent $event): void;      // writes the outbox row (called inside the action's transaction)
    public function syncHotelInfo(Property $property): void;
}

interface FiscalReceipter
{
    public function record(FiscalEvent $event): void;           // writes the outbox row
    public function exchangeRate(Currency $from): ExchangeRate;
}
```

Each integration domain has two parts:

- **Recording** (`record`) writes outbox rows inside the caller's transaction.
- **Delivery** is done by the domain's drainer through a transport adapter. The ZAN Gateway HTTP, VFMS HTTP and channel-manager adapters live behind it.

**Fakes** (`FakeChannelConnector`, `FakeZanTransport`, `FakeVfmsTransport`) are bound in tests. They record what was sent and can simulate 429s, timeouts, partial rejections and duplicates.

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
| Every minute                                        | ZAN Gateway latency monitor (§5.4)                                         |
| Every minute                                        | Night audit trigger: properties whose local business date is due for close |
| Daily, per property, after the business date closes | Channel reconciliation (DST-07)                                            |
| Daily                                               | VFMS exchange rate fetch (FSC-09)                                          |
| Daily                                               | Fiscal exceptions digest to property managers                              |
| Hourly                                              | Subscription lifecycle transitions                                         |

## 11. Deployment

| Component | Production                                                                                                                |
| --------- | ------------------------------------------------------------------------------------------------------------------------- |
| Region    | AWS `af-south-1` (opt-in region, ADR 0011). The code stays region-agnostic                                                |
| App       | EC2 provisioned through Forge. Nginx and PHP-FPM 8.3                                                                      |
| Workers   | Supervisor processes, named per queue so a slow integration can't starve the others: `zan`, `vfms`, `channels`, `default` |
| Scheduler | `schedule:work` under Supervisor                                                                                          |
| Database  | RDS MySQL 8 with automated backups and PITR. Same region, private subnet                                                  |
| Files     | S3 private bucket, same region                                                                                            |
| TLS       | Let's Encrypt through Forge                                                                                               |
| Deploys   | Forge zero-downtime deploy, then `php artisan queue:restart`. Outbox rows make a restart mid-delivery safe                |

**Environments**

| Environment | ZRA endpoints                           | Channel manager |
| ----------- | --------------------------------------- | --------------- |
| Local       | Fakes                                   | Fake            |
| Staging     | ZAN Gateway staging, VFMS test          | Vendor sandbox  |
| Production  | ZAN Gateway production, VFMS production | Live            |

**Unverified:** the VFMS production URL, and whether it uses TLS. The test endpoint is plain HTTP on port 6060, so the security group's outbound rules must allow it.

## 12. Runbook: restore and post-restore reconciliation

PITR restores the database to a point in time. Outbox rows written before that point may show `pending` or `sending` even though the external system already received them. **Workers must not restart until these rows are reconciled.**

1. Put the app in maintenance mode and stop Supervisor workers and the scheduler.
2. Restore RDS to the chosen point in time, and point the app at the restored instance.
3. Run `php artisan outbox:reconcile-after-restore`. For every row in `pending` or `sending`:

    | Integration     | Check                                                                                              | Result                                                                                                       |
    | --------------- | -------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------ |
    | VFMS            | Query the accommodation report endpoint for the affected date range and match on `referenceNumber` | Found → mark `delivered` with the VFMS response number. Not found → leave `pending`                          |
    | ZAN Gateway     | The public spec has no read endpoint                                                               | Leave `pending`. Re-sending is safe because ZAN upserts by hotel code plus reservation number                |
    | Channel manager | List reservations and messages through the vendor API, if it offers one                            | Match → mark `delivered`. Otherwise leave `pending`. Coalescing makes re-sending availability and rates safe |

4. Review the reconciliation report: counts by integration and status, plus any rows that need a decision.
5. Domain changes made after the restore point are lost from Thedream, but may already exist outside it. Bookings that the channel manager delivered in that window are re-pulled by the next channel reconciliation (DST-07).
6. Restart workers and the scheduler, and leave maintenance mode.
7. Open a `ComplianceIncident` covering the window, for the record.

A **restore exercise**, including this reconciliation, is a release gate for M4 (PRD §14).

## 13. Fallback: cPanel hosting

This applies only if the client rejects a VPS (ADR 0006).

**Changes**

- **Delivery after commit runs inside the web request** (`dispatch(...)->afterResponse()` handled synchronously), because no long-running worker is waiting.
- **Cron** runs `schedule:run` every minute. The schedule starts `queue:work --max-time=55 --stop-when-empty` with `withoutOverlapping()`.
- **Outbound port 6060** must be confirmed open with the host before choosing this option, or VFMS is unreachable.

**Latency, worst case for one booking report**

| Case                                                                | Delay                                                                                                                 |
| ------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------- |
| In-request attempt succeeds                                         | about 1–3 s                                                                                                           |
| In-request attempt fails, and the next cron worker picks the row up | up to 60 s for the cron tick, plus the backoff (30 s first retry), so about 90 s                                      |
| A second failure                                                    | Next tick plus 2 minutes of backoff, so about 3 minutes                                                               |
| The host kills the worker mid-batch                                 | The row stays `sending` until the stale-lock timeout (2 minutes), then is retried on the next tick: about 3–4 minutes |

**Targets relaxed for this option**

| PRD target                     | VPS                   | cPanel fallback                                                                                                                                      |
| ------------------------------ | --------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------- |
| Booking report delivered (p95) | 1 minute              | 2 minutes                                                                                                                                            |
| Alert threshold                | 3 minutes             | 3 minutes (unchanged)                                                                                                                                |
| Compliance incident            | 5 minutes             | 5 minutes (unchanged; more likely to trigger)                                                                                                        |
| Availability                   | 99.9%                 | 99.5%                                                                                                                                                |
| Recovery point                 | 15 minutes (RDS PITR) | 15 minutes only if the database is still a managed external one. Shared-host MySQL backups are typically daily, which means a 24-hour recovery point |

## 14. Testing strategy

| Layer                                   | What                                                                                                                                                          |
| --------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Unit (Pest)                             | Money, reservation number sequences, payload builders for ZAN Gateway and VFMS (fixtures from the spec and guide), status mapping (PRD §7.9 table)            |
| Feature (Pest)                          | Every action, with fake adapters. The **full Booking.com lifecycle** from PRD §8 as one scenario, asserting the exact outbox rows and their order             |
| Failure                                 | Fakes simulate 429, 5xx, timeouts, partial rejection, duplicates and out-of-order inbound messages. Assert no loss, no duplicate effect, and correct ordering |
| Cross-tenant                            | The suite in §4.3                                                                                                                                             |
| Architecture (`arch()`)                 | Boundary rules in §3.2                                                                                                                                        |
| Contract (staging, manual or scheduled) | Real calls to ZAN Gateway staging and the VFMS test server, verifying payloads and the duplicate-`referenceNumber` behaviour                                  |
| Static                                  | Larastan (level agreed with the team), Pint in CI                                                                                                             |

## 15. Go-live gates (technical)

- The PDPA 2022 cross-border question is resolved for `af-south-1` before the first real guest record (ADR 0011, PRD §16).
- `af-south-1` is enabled on the AWS account.
- Thedream's own ZRA supplier key is issued, with the ZAN Gateway staging contract test passing.
- VFMS test credentials are issued, and the VFMS production URL and transport are confirmed.
- The channel manager is selected against PRD §9, with its sandbox contract test passing.
- The restore exercise, including post-restore reconciliation, passes.
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
