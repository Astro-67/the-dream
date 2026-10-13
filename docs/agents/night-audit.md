# Night audit extension points

`FrontDesk\Actions\CloseBusinessDate::handle(?User $actor, Property $property, string $date)`
requires that property's tenant context and an explicit `Y-m-d` business date.
Pass `null` for automation. It returns the property/date's `BusinessDateClose`.
A completed date returns its original record without advancing again; an
unclosed date must match the locked property's stored business date.

The property lock serializes closes. Room charges, the close record, audit
entries, and advancement of `properties.business_date` share one transaction.
Failure rolls back new work. Billing's `PostRoomCharge` also accepts retries:
postings are unique by reservation, business date and room charge category,
regardless of folio. Amounts and taxes are frozen; `price_line_id` records the
source. Unchecked-out departures repeat their last night's price, taxed for
the date being closed.

- **#88 scheduler:** capture the date when dispatching a tenant-aware job. Enter
  the property's context, then call the action with that date and a null actor.
- **#89 no-shows:** `Reservations\Actions\ProposeNoShows` runs before the date
  advances and creates one `NoShowProposal` per confirmed reservation arriving
  on or before the closing date (unique per reservation; `business_date` is the
  proposing close). Status `proposed` until a `night_audit.close` holder runs
  `ConfirmNoShow` (terminates as `no_show`, proposes the fee, records
  `no_show_confirmed`) or `RejectNoShow`. Open proposals:
  `NoShowProposal::where('status', 'proposed')`.
- **#91 stayovers and price events:** extend the locked reservation loop before
  advancing the date. Record `nightly_price_changed` with `RecordLifecycleEvent`
  under the reservation lock; the business date is still the closing date.
  Stayover service marking also belongs in this transaction, behind its setting.
  Done: `properties.stayover_service_on_close` marks every occupied room for the
  next date; `nightly_price_changed` compares the posted night with the next
  price line and records `date`, `previous_amount`, `amount`, `currency`.

The summary selects original room charges by property and closing date, so it
includes charges recovered from a partial attempt. History and charges are
paginated. Extend summary props for proposal and service results rather than
adding work to controllers. Deployments must run `organizations:sync-roles`
for the new `night_audit.view` and `night_audit.close` permissions.

**#90 exceptions:** before advancing, the close snapshots open no-show
proposals, checked-in departures due on or before the closing date, and open
cashier shifts as `FrontDesk\Models\ExceptionListItem` rows dated the next
business date. The index shows the current date's list; the summary shows
`exceptionsCarried`.
