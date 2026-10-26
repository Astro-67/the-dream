# How Uhuru files with VFMS (observed)

Observed 26 October 2026 in the owner's Uhuru account (property `the-dream-of-sau-inn-beach-hot`), read-only. Replaces the 24 October attempt, which was blocked at login. The account is in **Test mode**: it files to ZRA's test VFMS, not production.

## Sources

- Uhuru screens: Settings → Fiscal, Settings → Tax & ZRA, Receipts, Reports → ZRA filings / Tax / Reconciliation, reservation `#/reservations/3` (checked-out walk-in with one extra service), its receipt page `/api/reservations/3/receipt`, and the JSON behind it (`/api/reservations/3`).
- ZRA's **test** guide, v1.6 ("Test Environment"). Not in git, because page 33 prints ZRA's shared test credentials; ask the project owner. The production guide is `sources/vfms-hotel-api-guide-v1.6.pdf`.
- One read-only call each to the test `currencyExchangeRate` and `accommodationReport` endpoints, both HTTP 200.

## ZRA test environment

- The test server's base URL ends in `:6060/vfms/api/` (plain HTTP; the full address is in the test guide). **Production is `https://gateway.zanrevenue.org/vfms/api/`**, not the `/api/` the production guide prints: probed without credentials on 26 October, every `/api/…` path returns 404 and every `/vfms/api/…` path answers with ZRA's own field validation.
- The test guide prints shared test credentials on page 33: one integration ID and token for an accommodation business unit, one for restaurant/other services. They are ZRA's public test values, not the pilot hotel's. The test unit is business unit `5940` and is shared: its accommodation report shows other vendors' guests.
- Both servers serve the same paths. The test guide's `changeRoom/price/` and `api/reservation/booking/` are typos: both return 404, while `changeRoomPrice/` and `reservation/booking/` answer. The test guide has no error-correction endpoints.
- The test guide adds `arrivalDate` per guest on accommodation, and `tourRegNo`, `costOptions` for `TOUROPERATOR` source. VFMS suffixes our `referenceNumber` with `-<businessUnitId>` and returns a `responseNumber`.
- ZRA's own report viewer on the test server (port 4242): `/checkout-report/guest/<reportNumber>` and `/receipt-form/<receiptNumber>`. Production's `https://portalvfms.zanrevenue.org/checkout-report/guest/<reportNumber>` answers HTTP 200. It failed to load data on 26 October ("Sorry!", backing call HTTP 503); the Uhuru banner also said ZRA's fiscal service was unavailable.

## Uhuru settings

**Fiscal**: "Room prices include City Tax" (levy inside the desk price, ZRA sent the room part); "File OTA bookings at" net of commission (recommended) or full price; "Auto-fire fiscal" on check-in/check-out; "Email fiscal receipt" after checkout; receipt template standard/compact/branded; **"Test mode: Use ZRA sandbox endpoints (not production)"**; read-only "Set by ZRA": ZRA TIN, ZRA VRN, Z-number.

**Tax & ZRA**: infrastructure tax currency (USD/TZS/EURO) and effective date; ZRA tax category (vat, hotel_levy, tour_operator, exempt); tax-inclusive prices; tax label and rate; show tax breakdown on bill / on receipt; optional 10% service charge. Read-only "Set by ZRA": City Tax amount, Hotel Levy (2%), Tour Operator charge (5%), VAT Mainland 18% / Zanzibar 15%, foreign withholding 15%.

Integration ID and token are not on any settings screen.

## What a stay files

Reservation 3 (walk-in, 1 night, USD 130, tax-inclusive, `RO` package, source `DIRECT`) produced three fiscal transactions, each with its own idempotency key, attempt count, last error and stored ZRA response:

| Uhuru endpoint   | VFMS result                                                      | Stored as                                                            |
| ---------------- | ---------------------------------------------------------------- | -------------------------------------------------------------------- |
| `hotel/checkin`  | `responseNumber` (reference = reservation number)                | document type `response`, no QR                                      |
| `hotel/service`  | `receiptNumber` XHE297326, tax 1.30 on 10.00, reference `SVC-…`  | document type `receipt`, QR                                          |
| `hotel/checkout` | `reportNumber` XHE297327, total 140, tax 18.26, infrastructure 5 | document type `report`, QR, Z-number, TIN, per-guest `guest_reports` |

Each call is also a reservation event (`fiscal_succeeded`, `service_charge_filed`). The reservation shows a "ZRA fiscal audit trail" (status, endpoint, attempt). Before filing it computes `fiscal_readiness`: adults, guests linked, guests with documents, missing.

Uhuru's bill shows "Tax (assessed by ZRA)" and "Infrastructure levy (assessed by ZRA)" from the ZRA response, plus "ZRA document XHE297327".

## The checkout report Uhuru prints

Header: business name and address from ZRA's response. Title "ZRA Checkout Report". Reference (reservation number), room, arrived, departed, nights. "Guests declared": name, lead badge, document type and number, country. Totals: accommodation tax inclusive, "of which VAT", infrastructure levy, total. Note that the levy is assessed by ZRA. Report number, Z-number, TIN, VRN. QR code and "Verify with ZRA" link. Footer: property phone and email, "Filed with ZRA via Zansync".

The QR encodes Uhuru's own redirect `https://zansync.com/r/<reportNumber>`, not ZRA's viewer URL; the QR image is drawn by `api.qrserver.com`, a third-party service.

The receipt list shows one row per fiscal document (checkout report and each service receipt) with tax, levy, total, filed time, "Open on ZRA" and "Stay".

## Reports

**ZRA filings**: filed, succeeded, failed, unknown, receipts issued, checkouts not filed, missing documents; then every call with reservation, type, amount, status and receipt number ("the trail an inspector asks for"). **Tax**: gross, accommodation tax, infrastructure tax filed, filed-vs-held variance. **Reconciliation**: departures compared with what ZRA holds (matched, variance, unfiled). The Today page flags a departure that was never checked in, because ZRA has no record of it.

## Uhuru's front-desk code

Read from Uhuru's served scripts (`/app/reservations.js`, `/app/rooms.js`, `/app/settings.js`), 26 October:

- **Package** on the booking form: `RO` Room only, `BB` Bed & Breakfast, `HB` Half Board, `FB` Full Board, `AI` All-inclusive.
- **Payer**: "The guest directly" (`DIRECT`) or "A tour operator" (`TOUR OPERATOR`) with operator name, ZRA registration (required) and what it pays for: `56` room and levy, `5` room only, `567` room, levy and other services, `57` room and other services.
- **Room ID**: each room has a numeric "ZRA Room ID (VFMS)" made of a property prefix and the room's sequence (`238080001` … `238080005`). A room without one cannot be moved into.
- **Country** is sent as an uppercase English name (`TANZANIA`, `ITALY`), "the format ZRA's samples use and what has been filing".
- **Check-in** needs a document for every adult. If filing fails, the guest is still checked in, the call retries automatically, and staff see "Checked in, but NOT filed with ZRA" when it needs attention. A stay with no successful check-in filing shows "Re-file check-in", rebuilt from current details.
- **Room move** files `change-room` first and moves the guest only if ZRA accepts. Each move gets its own numbered reference.
- **Charge to room**: staff draft free-text lines (VAT-inclusive price, whole-number quantity), then press "Send N items to ZRA". It cannot be undone: "a correction after filing goes through ZRA's credit procedure". Filed lines show their receipt number.
- **Check-out** is refused while a balance is due. If ZRA does not accept it, staff see "Checked out, but ZRA has not accepted it yet" and re-file from the reservation.
- **Filed guest details** (check-in date, room, rate, adults, document) are locked on a checked-in stay; document corrections go through the change-profile call.
- **A departed stay that was never checked in** is offered "Close without filing": nothing goes to ZRA and the property declares it in its VAT return.
- The checkout report is staff-only, "because it shows the accommodation price the hotel declared, which is not what an agent-booked guest paid".
- Fiscal transaction statuses: `success`, `pending`, `retrying`, `indeterminate`, `failed`, with "Retry now".

## ZRA test environment behaviour

One two-guest stay filed by Thedream on 26 October with references `TDREAM1793012693…` (test business unit `5940`), then checked out:

- The request type for check-in is `ACCOMMODATION_REQUEST`. A wrong type returns `INVALID REQUEST TYPE PROVIDED`.
- **Errors come back as HTTP 200** with `{"statusCode": 5000, "statusMessage": …}`. Success is the echoed payload with a `responseNumber`.
- **A repeated reference is refused**: `Reference number <ref>-5940 already exists`, for both accommodation and services. Resending after an unknown outcome is therefore safe.
- **VFMS tracks room occupancy by `roomId`**: checking into a room ID already open in the business unit returns `The Room ID … Is not Empty`. VFMS stores the room as `roomId` followed by the unit ID.
- Package `BB` was accepted and echoed back as `paymentType`. Package `RO` was accepted but echoed back as `OTHER`, so VFMS has no room-only code of its own; Uhuru sends `RO` anyway.
- Document names `ZANID` and `NIDA` were accepted. Two Tanzanian guests were each charged the USD 5 levy.
- The accommodation report with `refno` returned HTTP 500; without it, the day's report (13 accommodations from all vendors) contained ours, including the service line (no receipt number in the report).
- **Check-out of two guests returns one report per guest**, each with its own report number (`XHE299346`, `XHE299349`). Each repeats the room line in full (USD 100, tax 13.04), carries only that guest's services, and charges that guest's infrastructure levy (USD 5). The business name, TIN, VRN, street and Z-number come from the business unit.
- The extra service returned receipt `XHE299345` with tax 2.61 on USD 20 (VAT taken out of the inclusive price).

## A full stay with Thedream's field names

On 26 October one stay was filed on the test server with main's field names and paths (references `TDF…`): check-in (two guests, ISO timestamp), add guest (`NIDA`), change room, change room price (`changeRoomPrice/`, `rooms[].hotelRoomRoom`), change profile, extra services (two lines in one call), one guest's departure, and check-out of the rest. **Every call was accepted.** Check-out of a document already out returns `Guest Document … Not Found`. The change-room-price answer is a bare list with no response number.

## Not observed

Advance payment, room change, price change, add guest, change profile, guest departure, tour-operator source, a failed or unknown call, multi-guest rooms. No Uhuru form was submitted.
