# Future reservation imports

Managers open **Import future reservations** from the reservations list. Download
the CSV template, keep its columns in order, and upload a UTF-8 CSV (up to 2 MB
and 1,000 rows). Blank lines are skipped; an optional UTF-8 BOM is accepted.
The original CSV is retained on the private `local` disk.

## Columns

| Column                        | Meaning                                                                                                            |
| ----------------------------- | ------------------------------------------------------------------------------------------------------------------ |
| `source_reference`            | Required previous-system reference, unique per property.                                                           |
| `room_type_code`              | Existing room type code at the selected property.                                                                  |
| `rate_plan_code`              | Active rate plan code for that room type and property.                                                             |
| `arrival`, `departure`        | `YYYY-MM-DD`; arrival on or after the property's business date; departure after arrival, at most 365 nights later. |
| `adults`, `children`          | Integer counts within the room type's occupancy limits. At least one adult.                                        |
| `booker_name`, `booker_email` | Required booker name; optional valid email.                                                                        |
| `guest_name`, `guest_email`   | Required guest name; optional valid email. The guest need not be the booker.                                       |
| `status`                      | `confirmed` only. In-house and past stays are not supported.                                                       |
| `restriction_override_reason` | Optional, required when the configured rate restrictions need an override.                                         |

Prices come from the property's configured rate plan, including derived rates
and guest supplements. Every occupied night must have a price. This is not a
vendor-specific mapping or a way to override nightly prices from the CSV.

## Replay contract for ticket #98

`App\Domains\Onboarding\Actions\DryRunImport::handle($actor, $file)` returns an
`ImportJob`. Jobs and rows are property-scoped through `BelongsToProperty`.

- The job retains `uploaded_by`, `file_path`, `original_name`, `status` (`dry_run`)
  and nullable `commit_results`.
- Its ordered `rows()` retain `row_number` (header is row 1), `source_reference`,
  `raw_data`, `status` (`valid`, `invalid`, `duplicate`), field-keyed `errors`,
  nullable `validated_data` and nullable `commit_result`.
- Validated data contains `reservation` (resolved room type/rate plan IDs, dates,
  integer guest counts, source reference, source `import`, property payment
  collector and restriction override), `booker` and `guest` (name/email), and
  `quote` (currency and nightly price snapshot).
- No reservation, booker, guest, folio, inventory or lifecycle event is created
  by the dry run. The quote is a preview, not locked inventory or a fixed price.
- `reservations.source_reference` has a nullable unique constraint on
  `(property_id, source_reference)`. Dry runs flag existing references and
  repeated references within the upload; another property's reference is not
  a duplicate.

The commit step re-enters the job's property context and rechecks dates,
eligibility, rates, restrictions, availability and duplicates, create/link the
booker and guest, and call `CreateReservation` with the replay fields and resolved
booker ID. A dry run with invalid rows must be corrected before committing.
Duplicate rows are skipped; only valid rows are eligible.

## Commit outcomes and retries

Commit saves each row's `commit_result` atomically with its reservation, booker,
guest attachment, folio and inventory. It contains `status` (`created`,
`duplicate` or `refused`), a `reservation_number` when available, and field-keyed
`errors` for refusals. Each row uses current rates and restrictions, not the
preview quote. Refused rows roll back without creating people or taking inventory.
Guests are created for each new reservation, never matched automatically.

The database unique constraint on `(property_id, source_reference)` is the
idempotency key across uploads, including concurrent commits. The import job
lock serializes replay of the same job. Completed rows survive an interrupted
commit; resubmission processes only rows without an outcome. Once all rows have
outcomes, the job becomes `committed` and its `commit_results` stores counts of
`created`, `duplicate` and `refused`. Resubmission preserves those results.
To retry refused rows, correct the CSV and run a new dry run.

Created reservations carry `imported = true` and `source_reference`. At Zanzibar
properties in setup, their `created` lifecycle event is deferred until go-live,
with the go-live actor, time and business date and `test = false`. Go-live emits
it once, using the reservation's current snapshot. Mainland imports and imports
at already-live properties retain the ordinary lifecycle behavior. Reporting
translation remains M3 work.
