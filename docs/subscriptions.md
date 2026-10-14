# Subscription billing

`subscriptions:advance` runs hourly, entering each organization's tenant context
independently. One organization's failure does not stop the others. A locked
subscription records its successful run, so repeats in the same hour do nothing.

Invoices are due at the start of their billing period. The task issues the next
invoice three days beforehand so owners receive a pre-due reminder, then one
overdue reminder at or after the due date. A missed reminder window is not
backfilled with a misleading pre-due message. Reminders use queued mail; workers
must run. Persistent invoice markers prevent repeat scheduling.

An unpaid invoice moves a trial or active subscription to past due at its due
time, then to suspended when the plan's grace days have elapsed. Payment
reactivation belongs to `RecordSaasPayment`. Cancellation scheduled with
`cancel_at_period_end` takes effect at the paid period end (or trial end), without
issuing a renewal. Cancelled subscriptions are not advanced.
