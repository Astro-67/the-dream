# Outbox restore and reconciliation runbook

This runbook is for a post-restore outage. It does not certify a real provider contract. Keep the application in maintenance mode, stop all workers and the scheduler, and do not enable provider integrations until their activation prerequisites below are met.

## Prerequisites

- Identify one authorized recovery actor and the exact property to recover. Organization owners and assigned property managers need `outbox.recover`; platform context alone is not authorization.
- Record the restore point, affected time window, provider account, integration, and reason in the incident record. Do not put provider payloads, guest information, credentials, or evidence contents in command output or audit metadata.
- Ensure the deployed schema includes `outbox_restore_gates`, `outbox_restore_evidence`, `outbox_identity_floors`, and the restore identity evidence fields. A restore from an older snapshot can restore an older gate value; close the gate before application processes are started and verify it remains closed.
- Ensure every configured integration has an implemented provider-specific `RestoreReconciliationAdapter` or verified `VerifiedSafeReplayAdapter`, plus a `RestoreIdentityBoundaryAdapter` that attests complete property-wide coverage. A fake adapter does not establish a real provider guarantee.

## Pause and gate

1. Enable maintenance mode. Stop Supervisor workers and `schedule:work`.
2. Restore the database and point the application at it while all application processes remain stopped.
3. Close the property gate with an authorized actor, property ID and specific reason:

   ```sh
   php artisan outbox:gate --actor='<USER_ULID>' --property='<PROPERTY_ULID>' --reason='Post-restore recovery; restore point <UTC timestamp>'
   ```

4. Verify the gate row exists with `is_closed = 1`. Do not infer safety from maintenance mode, queue state, pending-row count, or restored sequence maxima.

The gate is persistent in the application database and is checked before recording integration effects, delivery claims, sweeping, retry, acceptance confirmation, and correction release. The property row lock serializes recording against gate closure/opening. A failed command leaves the gate closed.

## Reconcile delivery

Run reconciliation with the same explicit actor, property and reason:

```sh
php artisan outbox:reconcile-after-restore --actor='<USER_ULID>' --property='<PROPERTY_ULID>' --reason='Compare provider history for <UTC start> through <UTC end>'
```

The command queries each integration adapter outside a database transaction, then applies the complete result set transactionally only if the gate and message snapshot have not changed. Output is counts only. Evidence hashes, provider references, actor and reason are retained in `outbox_restore_evidence` and the audit log; raw evidence is not persisted. A missing, malformed, ambiguous, interrupted or failed response does not establish acceptance. The database changes roll back and the gate stays closed.

- Explicit `accepted` evidence can resolve eligible uncertain work as delivered and preserve provider references.
- `not_found` alone does not establish safe retry. A replay-only adapter may return it only under its verified safe-replay contract; the message remains pending until the gate opens.
- Rejected, exhausted, blocked and unresolved work is not silently cleared. Correct confirmed rejection using the existing correction workflow only when its adapter's changed-payload contract is verified.
- Reconcile all uncertain attempts in the affected window, not only rows still marked pending. A PITR restore can omit externally accepted operations entirely; provider-side history is required to discover them.

For example, VFMS requires authoritative reconciliation against the original operation and `referenceNumber`; absence is not proof of safe retry or identity reuse. ZAN Gateway and channel managers need verified replay contracts if they cannot query historical operations. An upsert identity alone is insufficient.

## Recover identities

After delivery reconciliation succeeds, run the provider-backed identity recovery command:

```sh
php artisan outbox:identity-safe --actor='<USER_ULID>' --property='<PROPERTY_ULID>' --reason='Recover external identity history through <UTC cutoff>'
```

Every registered integration must attest complete property-wide identity coverage and return per-stream sequence high-water marks with evidence. The command rejects omitted coverage, stale/lower sequence floors and unverified adapters. It stores only evidence hashes, actor, reason, and monotonic high-water floors. New sequence allocation uses the maximum of restored history and the verified floor. Do not use a restored maximum, pending-row scan, manually supplied number, or generic provider duplicate response as identity evidence.

If any provider cannot establish a complete safe boundary, leave the gate closed. Recover missing provider history/domain identities through an approved integration-specific procedure or wait for a verified provider contract; do not resume allocation for that property.

## Review and resume

Review the property-scoped audit trail, reconciliation counts, unresolved statuses, provider references, identity floor hashes and restore window. Resolve every rejected/exhausted/blocked/unknown state under its existing safety rules. Then reopen only with a fresh explicit actor, property and reason:

```sh
php artisan outbox:resume --actor='<USER_ULID>' --property='<PROPERTY_ULID>' --reason='Delivery reconciliation and identity review approved; incident <ID>'
```

Resume fails unless delivery reconciliation and identity safety are both recorded, unresolved/blocked states are absent, and replayable pending work has a matching identity floor. After success, restart workers and scheduler, verify delivery, then leave maintenance mode. Record a `ComplianceIncident` for the restore window.

## Fake restore exercise

Run the automated property-scoped fake exercise against SQLite and MySQL as part of release checks:

```sh
php artisan test --compact --filter=OutboxRestoreGateTest
TEST_MYSQL_PORT=33224 composer test:mysql -- tests/Feature/Integrations/OutboxRestoreGateTest.php
```

The tests cover persistent gating, reconciliation interruption, metadata-only evidence, reconciliation-capable and replay-only adapters, optional capability not implying safety, an older-backup operation absent from restored history, identity-floor allocation, and refusal to resume without both checks. These fakes prove only application behavior.

## Real-provider activation prerequisites

Before enabling any real integration, verify and document its idempotency semantics, changed-payload key reuse behavior, batch/partial-response mapping, replay and reconciliation/history behavior, complete identity coverage and high-water semantics, retention, authorization, and evidence access requirements. VFMS duplicate `referenceNumber` behavior remains unverified. ZAN upsert identity does not prove every replay order safe. Channel-manager latest-only coalescing is not a restore contract. Real provider clients and the M4 production restore exercise remain out of scope.
