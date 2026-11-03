# VFMS advance payments

## Supported workflow

1. Create a reservation booked through a tour operator, including its name,
   registration number and payment responsibilities. Assign its room.
2. Record the payment normally. Karafuu still issues its immutable **non-fiscal
   receipt**; recording money never depends on ZRA being reachable.
3. Open that receipt and expand **File a tour-operator advance receipt**. Enter the
   operator's email, phone and actual ZRA registration status, then submit.
4. The existing VFMS outbox files `RESERVATION_BOOKING`. Once ZRA accepts it and
   returns both a booking number and receipt number, the receipt page displays
   those references and the ZRA receipt viewer/QR. Sandbox receipts remain
   explicitly non-fiscal.
5. Refresh the receipt page or open its reservation's ZRA filings to see delivery
   status. A queued, refused or unknown advance must be resolved before check-in.
6. Check-in still requires the assigned, ready room and every registered adult.
   It submits `ACCOMMODATION_BOOKING_REQUEST` with the accepted booking number
   and `receiptno`, **not** an additional ordinary accommodation request.
   Later room moves, guest changes, services and check-out keep their existing
   lifecycle operations.

## API contract and storage

Sources: the supplied `VFMS_Advance_Payment_Implementation_Notes.pdf` and
`VFMS_LIVE_HOTEL_API_GUIDE_V1.6 (1).pdf`, sections 2.9-2.10, read on
3 November 2026. The implementation follows the guide's explicit B2B
`customerType: 2`; it does not invent a consumer customer type.

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

The notes name `receiptNo`, while the existing research names `receiptNumber`;
the adapter also recognizes the guide's `receiptno` spelling. Acceptance requires
non-empty booking and receipt numbers. Incomplete successful responses and
duplicate/uncertain responses remain **unknown**, never accepted or retried
blindly. Repeated submission for the same payment queues nothing new.

## Deliberate limits

- Opt-in, one advance per reservation/payment, including individual members of a
  booking group. A group payment is not silently assigned to every member.
- Only documented tour-operator/B2B payments with an assigned room and the
  reservation's currency. Direct-guest, channel-collected and unsupported
  payments continue to receive the existing non-fiscal receipt.
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
- After issuing a Live advance, do not roll back to code that uses ordinary
  check-in for that reservation. Stop new advance filings separately while
  retaining receipt linkage and safeguards until open advances are reconciled.
- Tests use synthetic VFMS responses and prevent stray HTTP requests. No Live
  advance was filed or claimed to be verified by these tests.
