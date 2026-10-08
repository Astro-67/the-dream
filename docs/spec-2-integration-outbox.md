# Spec 2: integration outbox foundation

- **Status:** design confirmed and published, 8 October 2026; not a completed feature.
- **Sources:** [PRD §14 M1](prd/PRD.md), [ADR 0009](adr/0009-transactional-outbox-for-integrations.md), [architecture §5](architecture.md#5-integration-messaging-the-outbox-adr-0009).
- **Tracker:** [GitHub issue #16](https://github.com/Astro-67/the-dream/issues/16) is canonical and labeled `ready-for-implementation`. “Spec 2” is a spec ordinal, not GitHub issue #2.

## Problem

Saving a business change and separately queuing its integration effect can lose that effect on a crash. Retries, partial responses, concurrent workers and database restores can also reorder operations or reuse identities. M1 needs a durable, isolated delivery foundation before M3 introduces real providers and business lifecycle wiring.

## Solution

One property-scoped `outbox_messages` table and one delivery module in `app/Support/Outbox`. The outbox, not the queue's jobs table, is the record of delivery truth. Integration modules own business meaning, payload construction and provider response classification; the shared module owns delivery mechanics.

## User stories

- As a property operator, a committed business change retains its integration effect across crashes and outages without blocking local operations on an HTTP call.
- As a property manager or organization owner, I can recover failed delivery within my authorized properties, with an attributable reason and history.
- As a compliance operator, I can recover ZAN/VFMS delivery without gaining channel-distribution recovery or implicit access to identity documents.
- As a recovery operator, I cannot resume delivery or integration-producing writes after a restore until delivery and identity safety have been established.

## Decisions

### Recording and identity

- Record in the same database transaction as the business change. Only integration modules call `record()`; enforce that boundary with an architecture test. Recording requires an active transaction, not a separate outbox-owned business transaction.
- An ordered stream is organization + property + integration + subject type + subject ID. ZAN orders by reservation; VFMS orders by stay. Credentials or adapter rotation do not reset a stream.
- The calling action locks the business subject before recording. Allocate sequences from that stream's highest existing sequence under this lock; enforce stream-plus-sequence uniqueness in the database. Scope idempotency keys to the stream and sequence.
- Restored sequence maxima are not sufficient evidence of safe identity allocation. Restore recovery must account for accepted operations absent from the restored database.

### Delivery contracts

- Adapters expose `send(messages)` and return exactly one result per message: accepted, rejected, retryable or unknown, with provider references and optional retry timing. Ambiguous or unmappable results are unknown; only explicit acceptance permits delivered state.
- Adapters declare policy as data: batch grouping, maximum batch size, quota identity and limit, ordered versus latest-only delivery. Grouping and quota scope are separate.
- Send at most one message per ordered stream at a time; batch only across independent streams. Same-stream batching is excluded until a provider guarantees ordered processing.
- Dispatch queued delivery after commit. A scheduled sweeper recovers missed dispatches, due retries and expired claims. Do not hold database transactions open during HTTP calls.
- Claim tokens prevent stale workers from replacing newer claim results. An expired in-flight attempt has an unknown outcome, not proof it was unsent.
- Unknown outcomes may retry the unchanged payload only under a verified safe idempotency contract; otherwise reconcile. Never infer acceptance from a generic duplicate error.
- Enforce quotas across workers sharing the adapter-declared quota identity. ZAN's allowance is global across properties. Respect provider retry timing; one throttled integration must not stop unrelated integrations.

### Ordered recovery

- Confirmed rejection and exhausted automatic retries block successors. Distinguish confirmed rejection from exhausted retries whose outcome remains uncertain. There is no generic skip.
- A retry preserves the payload. An authorized operator may restart unchanged retries; evidence of provider acceptance is required to resolve a message as delivered.
- A resubmit requires confirmed rejection. Rebuild only the rejected operation while preserving its original business meaning, not blindly replacing it with current subject state. Rebuild affected blocked snapshot successors consistently so they cannot regress the provider after the correction.
- Keep the sequence and idempotency key only when the provider's changed-payload key-reuse contract has been verified; otherwise leave the stream blocked pending provider-specific recovery.
- Correction is one transaction under the recording subject lock: authorize, verify confirmed rejection and no active attempt, rebuild the operation and affected successors, preserve payload revisions and audit the actor's reason, then release eligible delivery after commit. Any failure rolls back the whole correction.

### Latest-only recovery

- Integration modules define explicit coalesce keys scoped by organization, property and integration. Only unattempted pending updates may coalesce.
- Once an attempt starts, its payload is immutable. A newer update waits behind it; it does not replace it or send concurrently for the same key.
- An unknown outcome holds that key pending safe retry or reconciliation. A confirmed rejection does not block newer desired state. Unrelated keys continue.

### Authorization, privacy and archival

- Recovery commands require an explicit actor, property scope and reason. Policies authorize property managers and organization owners within their allowed properties; platform compliance operators may recover ZAN/VFMS, not channel distribution. Audit outcomes and refused sensitive actions. Routine system delivery does not supply manual-recovery authority.
- Encrypt stored request/response bodies; exclude credentials. Keep immutable attempt and payload-revision history. Ordinary logs, audit entries and recovery output contain metadata and hashes, not identity documents or decrypted bodies.
- Recovery permission does not imply raw-body read permission; raw access requires a separate policy.
- Block property archival while any unresolved message exists, including rejected, exhausted, blocked or uncertain messages, through the existing pre-archive extension point.
- No automatic history pruning in this foundation. Provider-specific retention and deletion tooling are prerequisites for production activation, not an indefinite-retention policy.

### Restore

- Include a persistent restore gate and reconciliation command. The gate blocks delivery and integration-producing writes until delivery reconciliation and identity recovery complete.
- Recover missing history or establish a verified safe identity boundary before allocating new identities. Checking only restored pending rows is insufficient.
- VFMS requires provider reconciliation. ZAN and channel replay require verified safe replay contracts; an optional reconciliation capability must not imply unconditional replay safety.
- Foundation tests use reconciliation-capable and replay-only fake adapters. Real provider recovery ships with its integration; the production restore exercise remains an M4 release gate.

## Testing and acceptance

The foundation is accepted when runnable tests demonstrate:

1. A committed business change retains its outbox effect; rollback retains neither. Delivery is dispatched only after commit, and the sweeper recovers missed dispatches.
2. Concurrent recording cannot collide sequences; concurrent delivery cannot send ordered successors early or let stale claim results overwrite current claims. Worker death produces an unknown outcome and retains history.
3. Independent-stream batching maps partial outcomes per message. Ambiguous results never become delivered. Shared quotas hold across workers and properties without starving unrelated integrations.
4. Rejected and exhausted ordered messages block successors. Retry preserves payload; authorized correction is atomic, records revisions and cannot release regressive successor snapshots. No generic skip is available.
5. Latest-only coalescing affects only unattempted updates. In-flight content is immutable, newer updates wait, unknown outcomes hold the key and confirmed rejection permits newer desired state.
6. Cross-tenant and cross-property attacks fail for recording, jobs and recovery. Unauthorized recovery fails and is audited; encrypted bodies and ordinary output do not expose credentials or identity documents. Raw access is separately authorized.
7. Unresolved delivery blocks property archival through the existing check mechanism.
8. Restore gates block delivery and integration-producing writes until reconciliation and identity recovery finish, including a backup older than already accepted messages. Both reconciliation-capable and replay-only fake contracts are exercised.

Run database-sensitive coverage on MySQL as well as the project's normal checks. Fake adapters prove engine contracts, not provider compatibility or real-provider duplicate prevention.

## Out of scope

- Real ZAN, VFMS or channel-manager HTTP clients, credentials and provider-specific response fixtures.
- Reservation/stay lifecycle wiring, payload builders for real providers and inbound channel processing.
- Recovery UI, compliance dashboards, provider-specific latency alerts and incidents.
- Same-stream batch delivery, generic skip, retention pruning or unverified replay/resubmit behavior.

## Provider activation prerequisites

Before enabling a real integration, verify its idempotency and changed-payload key-reuse semantics, partial-response mapping, replay/reconciliation behavior and retention/access requirements. VFMS duplicate `referenceNumber` behavior remains unverified. ZAN upsert identity alone does not prove that every historical replay order is safe. These dependencies must not be represented as guarantees established by fake-adapter tests.
