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

The commit step must re-enter the job's property context, recheck dates,
eligibility, rates, restrictions, availability and duplicates, create/link the
booker and guest, and call `CreateReservation` with the replay fields and resolved
booker ID. Only valid rows are eligible. Store per-row commit outcomes, use the
database uniqueness constraint for concurrent repeat safety, and add the
`imported` flag and go-live lifecycle behavior specified by Spec 8. Those writes
are intentionally deferred to #98. Update the results screen's no-write notice
when adding a committed state.
