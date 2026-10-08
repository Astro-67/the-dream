# Core actions record reservation lifecycle events; integrations translate them

ADR 0010 and architecture §7 had each core action (CreateReservation, CheckIn, MoveRoom, CloseBusinessDate and so on) call `ZanReporter::record()` and `FiscalReceipter::record()` itself. With about nine lifecycle actions, each would have to know which ZAN Gateway reports and VFMS calls it implies, and one forgotten call is a silent compliance gap.

We refine ADR 0010: **every lifecycle action records exactly one reservation lifecycle event in its own transaction, under the reservation's lock. One translator per integration turns that event into outbox messages within the same transaction.** Core domains never call `ZanReporter` or `FiscalReceipter` directly. Whether an event produces ZAN or VFMS work stays a jurisdiction decision inside the integration domain.

M2 builds the actions and the event record with no translators bound. M3 adds the ZanReporting and FiscalReceipting translators without changing core code.

## Considered options

- **Core actions call the reporter interfaces (architecture §7 as drafted).** Rejected: compliance knowledge is spread across every action, and nothing checks completeness.
- **Retrofit in M3.** Rejected: every M2 action would be reopened, which is the likeliest way to miss an effect.

## Consequences

- An architecture test asserts that every lifecycle action records an event and that core domains reference no integration interface.
- Events are ordered per reservation by the same lock that allocates outbox stream sequences, so translation keeps the ordering guarantees of ADR 0009.
- The event carries what translators need (or they read the reservation within the same transaction). Payload building still lives in the integration domain.
