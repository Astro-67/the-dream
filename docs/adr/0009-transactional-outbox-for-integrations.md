# Integration messages go through a transactional outbox

A lost ZRA booking report is a compliance incident. If an action saved a reservation and then dispatched a queue job, a crash between the two would lose the report silently. Instead, the action writes an outbox row (`booking_reports`, `fiscal_transactions`, outbound channel messages) in the same database transaction as the domain change. An `afterCommit` hook tries delivery immediately, and queue workers drain any rows still pending. The outbox rows are the record of truth for delivery state, retries, the delivery log and the compliance dashboard. The queue's jobs table is not.

## Consequences

- Every outbox message has an idempotency key: the reservation ULID plus the event's sequence number. It is passed to each integration where the integration supports one. The VFMS `referenceNumber` carries it. ZAN Gateway has no idempotency field, but it upserts by hotel code plus reservation number, so re-sending the same state is harmless. Support on the channel manager side is a selection criterion.
- After a database restore, outbox rows are reconciled against each integration's delivery record before workers restart (see `docs/architecture.md`, runbook).
