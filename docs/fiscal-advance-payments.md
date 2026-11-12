# VFMS advance payments

## Supported workflow

The `ACCOMMODATION_BOOKING_REQUEST` check-in endpoint is shared by **all guests,
payers and reservation sources** once a valid ZRA `bookingNumber` and `receiptno`
exist. It is not restricted to tour operators.

### Existing ZRA advance: any guest or payer

1. Record the actual payment normally, then assign the room.
2. Open its non-fiscal payment receipt and choose **Link existing ZRA advance
   (any guest or payer)**. Supply the booking number and advance receipt number
   already issued by ZRA for this payment, property and VFMS mode, plus an
   auditable reason/source. Do not use a PMS receipt number or bank reference.
3. Karafuu records externally supplied references, not a fabricated accepted
   fiscal response. This sends no advance-booking request and creates no new
   ZRA receipt. The receipt page labels their provenance and links to the
   existing provider receipt for verification.
4. Complete guest registration and check in. The shared endpoint receives the
   actual guests and the supplied booking/receipt pair; ZRA validates that pair.
   Karafuu never also sends ordinary accommodation for this check-in.
5. Incorrect references can be corrected with a new audit entry before check-in
   or after a **confirmed rejection** of its advance check-in. A manager then
   uses the existing refused-filing recovery. Original check-in evidence is not
   rewritten. Accepted, pending or uncertain filings cannot be overwritten or
   bypassed with manually supplied references.

### Creating a new ZRA advance: any guest or payer

1. Create the reservation and assign its room. Any source works: direct guests,
   tour operators and every other channel.
2. Record the payment normally. Karafuu still issues its immutable **non-fiscal
   receipt**; recording money never depends on ZRA being reachable.
3. Open that receipt and expand **File an advance receipt with ZRA**. The payer
   name and TIN are prefilled (tour operator, else the lead guest); email,
   phone and TIN are optional. Choose the payer's ZRA registration and submit.
4. The VFMS outbox files `RESERVATION_BOOKING`. ZRA answers with
   `receiptNumber` and a nested `booking.bookingNumber`; both are stored and the
   receipt page shows them with the ZRA receipt viewer/QR.
5. Refresh the receipt page or open the reservation's ZRA filings to see delivery
   status. A queued, refused or unknown advance must be resolved before check-in.
6. Check-in sends `ACCOMMODATION_BOOKING_REQUEST` with ZRA's booking number and
   `receiptno` and the registered guests, **not** an ordinary accommodation
   request. ZRA accepts it when it echoes the same booking number.

## API contract and storage

Sources: the supplied `VFMS_Advance_Payment_Implementation_Notes.pdf` and
`VFMS_LIVE_HOTEL_API_GUIDE_V1.6 (1).pdf`, sections 2.9-2.10, read on
3 November 2026. The guide documents only `customerType: 2`; ZRA's test server accepted it for a
direct guest with an empty `regNumber`, email and phone, so every payer uses it.

Observed on ZRA's test server (3 November 2026), not printed in the Live guide:

- `reservation/booking/` returns `receiptNumber`, `referenceNumber` and
  `booking: {bookingId, bookingNumber, amountLeft}`. The receipt is room price
  times nights; `amountToBePaid` comes back as `amountLeft`.
- ZRA counts whole 24-hour days between `startDate` and `endDate`, so both are
  sent as property-local midnight. Check-in/out times made a one-night stay fail
  with "EndDate must be greater than StartDate".
- `accommodation/booking/` returns the booking, guests and reference, but no
  `responseNumber`. It refuses an unknown booking or a room not on the booking.
- Live gateway `vfms/api/reservation/booking/` and `vfms/api/accommodation/booking/`
  both answer; the guide's `api/api/` path returns 404.

Wire paths are relative to the existing, configured VFMS gateway:

- `reservation/booking/`, header `vfms-request-type: RESERVATION_BOOKING`.
- `accommodation/booking/`, header
  `vfms-request-type: ACCOMMODATION_BOOKING_REQUEST`.

The guide prints an extra `api/` in the first path. The corrected relative path
and deployed gateway base are recorded in
`docs/research/uhuru-vfms-observed-2026-10-26.md`; this feature changes neither
the configured base URL nor any existing endpoint.

The advance contains the recorded payment's actual amount and currency, the
assigned room's existing VFMS ID, its accommodation rate, booked adult count,
expected property-local check-in/check-out times, and the operator's existing
payment responsibilities. Children remain unregistered, as in the existing
VFMS flow. USD/EUR use major units, TZS has no decimals, and EUR is sent as
`EURO`, through the existing conversion helpers.

The advance check-in preserves the guide's spellings `accomodationPrice`,
`accomodationDiscount`, `salesCurrency` and `receiptno`.

Payment amount, currency and date already belong to the immutable posting and
payment receipt. The encrypted outbox stores the request and posting ID; its
accepted provider references store `bookingNumber`, normalized `receiptNumber`,
the returned reference when supplied, mode and the accepted receipt viewer URL.
The original payment receipt is not rewritten. No new database columns or
parallel fiscal ledger are needed.

Externally issued references instead belong to audited `advance_booking_linked`
lifecycle snapshots with their source, payment ID and VFMS mode. They are never
represented as a delivered PMS advance-booking message. Their exact references
are copied into the advance check-in evidence, and only a confirmed rejection
permits a corrected, audited retry.

Acceptance requires a non-empty receipt number and nested booking number. An
advance check-in must echo the booking number it sent, return its own reference,
and pass a read-only accommodation-report lookup with the same credentials.
That reference must contain exactly the expected active guest documents and
the active assigned room, in the filing's currency. The report may be an object
or a list; missing, duplicate, incomplete or unavailable results remain unknown.
Only the verification verdict is stored alongside the original response, not
the report's guest details. Incomplete successful
responses and duplicate/uncertain responses remain **unknown**, never accepted
or retried blindly. Repeated submission for the same payment queues nothing new.

## Deliberate limits

- Opt-in, one advance per reservation/payment, including individual members of a
  booking group. A group payment is not silently assigned to every member.
- Both creating and linking require an assigned room, a real recorded deposit
  and the reservation's currency.
- On 4 November 2026, ZRA's test server echoed an advance check-in but its
  accommodation report had no guest or room relationships. Check-out then
  failed with "Guest Document Not Found", despite matching document numbers.
  New advance check-ins now remain unknown in this situation. This safeguard
  does not create missing ZRA registrations or prove successful Live check-out.
  There is no blind retry or ordinary-check-in fallback; incomplete registration
  requires reconciliation. Already delivered historical filings are unchanged.
- Once an advance is queued, reservation amendments, room reassignment,
  cancellation/no-show/void and corrections of the filed payment require
  reconciliation. The guide supplies no advance refund/cancellation workflow;
  Karafuu refuses these unsupported changes instead of leaving a stale fiscal
  receipt. An unlinked refund cannot bypass this protection. Other payments not
  included in the advance remain independently correctable.
- Existing refused-filing recovery can resend a confirmed rejection, keeping
  its audit trail and generating a new wire reference. Changed financial
  history cannot be silently rebuilt. Unknown outcomes retain the existing
  manual reconciliation protections.
- Sandbox booking/receipt references cannot be used for a Live check-in.
- After issuing or linking a Live advance, do not roll back to code that uses ordinary
  check-in for that reservation. Stop new advance filings separately while
  retaining receipt linkage and safeguards until open advances are reconciled.
- Tests use synthetic VFMS responses and prevent stray HTTP requests. No Live
  advance was filed or claimed to be verified by these tests.
