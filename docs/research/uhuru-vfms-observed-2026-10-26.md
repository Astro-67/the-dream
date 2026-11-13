# How Uhuru files with VFMS (observed)

Observed 26 October 2026 in the owner's Uhuru account (property `the-dream-of-sau-inn-beach-hot`), read-only. Replaces the 24 October attempt, which was blocked at login. The account is in **Test mode**: it files to ZRA's test VFMS, not production.

## Sources

- Uhuru screens: Settings → Fiscal, Settings → Tax & ZRA, Receipts, Reports → ZRA filings / Tax / Reconciliation, reservation `#/reservations/3` (checked-out walk-in with one extra service), its receipt page `/api/reservations/3/receipt`, and the JSON behind it (`/api/reservations/3`).
- ZRA's **test** guide, v1.6 ("Test Environment"). Not in git, because page 33 prints ZRA's shared test credentials; ask the project owner. The production guide is `sources/vfms-hotel-api-guide-v1.6.pdf`.
- One read-only call each to the test `currencyExchangeRate` and `accommodationReport` endpoints, both HTTP 200.

## Production credential check

On 26 October the pilot property's saved Live integration ID and token were used on the server for one read-only `CURRENCY_EXCHANGE` request to `https://gateway.zanrevenue.org/vfms/api/currencyExchangeRate/`. ZRA returned HTTP 200 with `rateUSD`, `rateEURO` and `rateUSDEURO`. This verifies those credentials for that endpoint only, not accommodation filing, portal visibility or report generation. No credentials were printed, no fiscal calls were replayed and no tax was filed.

## ZRA test environment

- The test server's base URL ends in `:6060/vfms/api/` (plain HTTP; the full address is in the test guide). **Production is `https://gateway.zanrevenue.org/vfms/api/`**, not the `/api/` the production guide prints: probed without credentials on 26 October, every `/api/…` path returns 404 and every `/vfms/api/…` path answers with ZRA's own field validation.
- The test guide prints shared test credentials on page 33: one integration ID and token for an accommodation business unit, one for restaurant/other services. They are ZRA's public test values, not the pilot hotel's. The test unit is business unit `5940` and is shared: its accommodation report shows other vendors' guests.
- Both servers serve the same paths. The test guide's `changeRoom/price/` and `api/reservation/booking/` are typos: both return 404, while `changeRoomPrice/` and `reservation/booking/` answer. The test guide has no error-correction endpoints, but the test server answers §2.12 `ERROR_SAVE` (see 5 November below).
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

One two-guest stay filed by Karafuu on 26 October with references `TDREAM1793012693…` (test business unit `5940`), then checked out:

- The request type for check-in is `ACCOMMODATION_REQUEST`. A wrong type returns `INVALID REQUEST TYPE PROVIDED`.
- **Errors come back as HTTP 200** with `{"statusCode": 5000, "statusMessage": …}`. Success is the echoed payload with a `responseNumber`.
- **A repeated reference is refused**: `Reference number <ref>-5940 already exists`, observed for accommodation and services on the test server only. This does not establish replay safety for Live, checkout or price changes. A duplicate response has no original receipt/report, so it is unresolved rather than delivered until the required fiscal evidence is recovered.
- **VFMS tracks room occupancy by `roomId`**: checking into a room ID already open in the business unit returns `The Room ID … Is not Empty`. VFMS stores the room as `roomId` followed by the unit ID.
- Package `BB` was accepted and echoed back as `paymentType`. Package `RO` was accepted but echoed back as `OTHER`, so VFMS has no room-only code of its own; Uhuru sends `RO` anyway.
- Document names `ZANID` and `NIDA` were accepted. Two Tanzanian guests were each charged the USD 5 levy.
- The accommodation report with `refno` returned HTTP 500; without it, the day's report (13 accommodations from all vendors) contained ours, including the service line (no receipt number in the report).
- **Check-out of two guests returns one report per guest**, each with its own report number (`XHE299346`, `XHE299349`). Each repeats the room line in full (USD 100, tax 13.04), carries only that guest's services, and charges that guest's infrastructure levy (USD 5). The business name, TIN, VRN, street and Z-number come from the business unit.
- The extra service returned receipt `XHE299345` with tax 2.61 on USD 20 (VAT taken out of the inclusive price).

## A full stay with Karafuu's field names

On 26 October one stay was filed on the test server with main's field names and paths (references `TDF…`): check-in (two guests, ISO timestamp), add guest (`NIDA`), change room, change room price (`changeRoomPrice/`, `rooms[].hotelRoomRoom`), change profile, extra services (two lines in one call), one guest's departure, and check-out of the rest. **Every call was accepted.** Check-out of a document already out returns `Guest Document … Not Found`. The change-room-price answer is a bare list with no response number.

## Not observed

Advance payment, room change, price change, add guest, change profile, guest departure, tour-operator source, a failed or unknown call, multi-guest rooms. No Uhuru form was submitted.

## Karafuu on ZRA's test server, 27 October

On 27 October 2026 one Sandbox stay was driven through Karafuu's real routes and outbox against ZRA's shared test unit (`5940`), with the code on `spec/168-vfms-zra-conformance`. The driver is `tests/Feature/FiscalReceipting/VfmsTestServerStayTest.php` (skipped unless `VFMS_TEST_SERVER_RUN=1`; only the test gateway and test viewer may be reached). Guest documents were fake, unique per run, and are redacted here; no credentials or test hosts are recorded.

The stay: two guests with passports checked into room 7 (wire room ID `128370001`: property prefix plus the room's VFMS sequence), moved to room 8, a third guest added, night audit closing night one with a dearer night two (price change), two extras, the second guest leaving early, night audit closing night two, then check-out. Run `LP4WJR`, references `T12837-RES-000001-<sequence>`:

| #   | Event                        | Request type                | Outcome  | ZRA answer                                                  |
| --- | ---------------------------- | --------------------------- | -------- | ----------------------------------------------------------- |
| 1   | `accommodation`              | `ACCOMMODATION_REQUEST`     | accepted | `responseNumber` `…T12837-RES-000001-2-5940`                |
| 2   | `changeRoom`                 | `ACCOMMODATION_CHANGE_ROOM` | accepted | `responseNumber` `…T12837-RES-000001-3-5940`                |
| 3   | `addGuest`                   | `ACCOMMODATION_ADD_GUEST`   | accepted | `responseNumber` `…T12837-RES-000001-4-5940`                |
| 4   | `changeRoomPrice`            | `CHANGEROOM_PRICE`          | accepted | bare list `[{roomPrice: 125, hotelRoomRoom: <roomId>5940}]` |
| 5   | `services` (WATER 2 x 10.00) | `ACCOMMODATION_ADD_SERVICE` | accepted | receipt `XHE299595`, tax 2.6087 on 20.00                    |
| 6   | `services` (LUNCH 1 x 15.00) | `ACCOMMODATION_ADD_SERVICE` | accepted | receipt `XHE299596`, tax 1.9565 on 15.00                    |
| 7   | `guestDeparture`             | `ACCOMMODATION_CHECK_OUT`   | accepted | report `XHE299597` (departed guest)                         |
| 8   | `checkout`                   | `ACCOMMODATION_CHECK_OUT`   | accepted | reports `XHE299599` (lead), `XHE299602` (added guest)       |

Every message was delivered on its first attempt; no call was refused or unknown, so no code changed. An earlier run the same morning (`3OIK4X`, reports `XHE299587`, `XHE299589`, `XHE299592`, receipts `XHE299585`, `XHE299586`) was also accepted call for call.

- **One report per in-house guest.** Check-out stored `reportNumbers` `XHE299597`, `XHE299599`, `XHE299602`: the early leaver's report from step 7 plus one per guest still in house. Each report repeats the room line, carries only its own services (all extras on the lead guest's document) and charges that guest's USD 5 infrastructure levy.
- **Folio matches the report.** `CheckoutFigures` counted the shared room once: room 100.00, extras 35.00, tax 17.61 (13.04 room, 4.57 services), levy 15.00, total 150.00. The VFMS adjustment posting has reference `XHE299599`, VAT and levy lines of 17.61 and 15.00, and brings the folio's charges to exactly 150.00.
- **VFMS bills elapsed time, not business nights.** The run compressed two business nights into about 40 seconds. Every report shows one room line, room `7` (the check-in room, not the room moved to), `days` 1 at the check-in price 100.00. The room move and the price change were accepted but did not appear on the report, and the folio's two nights (225.00) were adjusted down by 110.00 to VFMS's figures. A real multi-night stay is needed to see how VFMS bills a move or a price change.
- **The report page** (`CheckoutReport`, rendered from the recorded props) is stamped "TEST, NOT FISCAL", shows the test unit's business header, both service receipts and three guest blocks (the early leaver marked "Departed before stay check-out"), each with its own QR code whose label and link are `<test viewer>/checkout-report/guest/<reportNumber>`. A screenshot was taken locally and not committed, because its QR codes encode the test host.
- **A4 software print check.** The recorded report HTML, with embedded CSS and logo, was opened through gstack and printed by Chromium to PDF. The final valid PDF is one A4 page (`595.92 x 842.88` points), including all three guest QR codes and the early-departure marker; the screenshot was visually inspected and the browser reported no console errors. This verifies software pagination only: physical paper printing and scanner readability remain unchecked. The PDF and screenshot remain local because their QR codes encode the test host; the initial blank PDF from a failed navigation was overwritten and is not evidence. This does not resolve the viewer's HTTP 503 or complete #175.
- **Viewer checks.** A GET on each QR target (`XHE299597`, `XHE299599`, `XHE299602`) returned HTTP 200. The viewer is a single-page app: a made-up number also returns the same 200 page. The data it loads, `:6060/hotel-service/api/v2/checkout/report/<n>?currency=USD`, returned HTTP 503 for all three, for the 26 October report `XHE299346` and for a made-up number, and the page shows "Sorry!" with empty fields. The QR targets resolve, but ZRA's test viewer could not display any report's content on 27 October, as with the production portal on 26 October.
- **Extras are one line per call.** Karafuu files each posted extra as its own `services` call with one line. The guide and the 26 October run accept several lines in one call; Karafuu cannot yet send a two-line extra as one receipt.

## Live at the pilot hotel, 27–31 October

Read from pms.sauinndream.com's outbox on 31 October 2026 (code `99e34c9`); no guest data is recorded here.

- **First Live stay.** RES-000002's check-in was accepted on 27 October at 08:55 UTC and its check-out at 09:59 UTC, with ZRA report `TRV211`.
- **Hotel checks.** The project owner confirmed on 31 October that the stay appears in the hotel's VFMS portal, that the printed report shows ZRA's real report number, and that its QR code opens the report on `portalvfms.zanrevenue.org`. ZRA's viewer, which returned HTTP 503 on 26–27 October, works again.
- **Later stays.** Six further Live check-ins on 30 October were each accepted on their first attempt.

## Sandbox runs from developer machines

On 31 October the server still could not open TCP 6060 or 4242 to ZRA's test host (80 and 443 open), so a Sandbox call from the server ends `unknown`; one such check-in sits in its outbox. The owner decided not to have the ports opened: changes to VFMS filing are proved against ZRA's test server from a developer machine with `VFMS_TEST_SERVER_RUN=1` (see the 27 October run above). Sandbox properties on the server cannot file.

## Error-management routes, 4 November

Empty, unauthenticated POSTs to Live on 4 November 2026 (spec #228) reached ZRA's missing-header validation at `/vfms/error-management/new` (§2.12). The guide's root path and `/vfms/api/error-management/new` returned 404. Karafuu's §2.13 read uses the sibling `/vfms/error-management/errors` by analogy; no probe of it is recorded. This shows only where the submission route is: no credentials, correction or report request was sent, so the acknowledgement and report response formats remain unobserved.

## Error correction on the test server, 5 November

Raw HTTP from a developer machine to ZRA's test server (accommodation unit from page 33 of the test guide, business unit 5940), with a fake guest:

- Check-in `KRF082452C`, then two services receipts: `XHE2102440` (2 × WATER, 20 USD) and `XHE2102441` (1 × WATER, 10 USD). Both responses carried `businessUnitId` 5940.
- §2.12: `POST /vfms/error-management/new` with `vfms-request-type: ERROR_SAVE` and `{b_unit_name, email, new_receipt_number: XHE2102441, old_receipt_number: XHE2102440, phone_no, reasons, unitId: 5940, zrb_number}` returned HTTP 200 `{"status":200,"statusMessage":"success","message":"Your application has been received, you will be notified once it has been processed."}`. `status` is a JSON number. Dummy business name, email, phone and Z-number were not rejected. An empty body on the same route returned HTTP 500.
- §2.13: `POST /vfms/error-management/errors` with `{startDate, endDate, status: "0"}` (with and without `unitId`) returned HTTP 200 `{"statusCode":"5000","statusMessage":"INVALID REQUEST TYPE PROVIDED"}` for every request type tried (`ERRORS_MANAGEMENTS`, `ERROR_MANAGEMENTS`, `ERRORS_MANAGEMENT`, `ERROR_MANAGEMENT`, `ERROR_REPORT`, `ERRORS`, `ERROR_LIST`, `ERRORS_REPORT`, `ERROR_SAVE`). GET returned 405; `/errors/`, `/report` and `/list` returned 404. The decision report's response shape is still unobserved.
- `/vfms/api/error-management/new` returned 404, as on Live. The guest was checked out afterwards (report `XHE2102442`).

So the test server provides correction submission but not the status report. Karafuu's Sandbox submits `ERROR_SAVE` to the test origin under the `businessUnitId` its accepted filings returned, and refuses the status refresh before any call. No correction has been sent to Live.

Later the same day, Karafuu's own routes and outbox repeated this (`VfmsTestServerCorrectionTest`, `VFMS_TEST_SERVER_RUN=1`, run `WNYPFN`): Sandbox check-in, services receipts `XHE2102448` (2 × WATER) and `XHE2102449` (1 × WATER), both with `businessUnitId` 5940; the correction pair `XHE2102448` → `XHE2102449` was recorded and its `ERROR_SAVE` (`unitId` 5940) was delivered and classified accepted on the same acknowledgement body; check-out report `XHE2102450`. Karafuu files a corrected receipt for the same service only by replay, which spec #228 forbids, so the driver stands the second receipt in as the first service's replacement. An earlier attempt of the driver left one fake guest checked in on the shared test unit: Karafuu refused its check-out locally (night audit not yet run), so nothing was sent for it.

### Live correction unit on legacy filings (7 November 2026)

The pilot hotel's "Correct this document" on report `TRV242` was refused with "Accepted evidence with the same recorded business unit is required for both documents." A read-only look at production showed why:

- All eight Live check-out reports (`TRV211`–`TRV250`) were filed before #232 began snapshotting `unit_id` on filings, so their outbox references carry no `unitId`.
- Live `ACCOMMODATION_CHECK_OUT` answers carry no `businessUnitId` (business name, TIN, VRN, street and Z-number only).
- Every one of those stays' accepted Live `ACCOMMODATION_REQUEST` answers does carry `businessUnitId` `32519`, matching the `-32519` suffix on its `responseNumber`.
- The property's VFMS settings had no business-unit ID.

Karafuu now takes a legacy Live document's unit from the stay's accepted check-in answer, and names the unit and the missing or different setting when it refuses. ZRA's test server always answers under its own unit 5940, so this Live path is covered by a fixture shaped like the production evidence, not by the test server. On the test server the same build submitted `XHE2103325` → `XHE2103326` (run `6S4RMK`, unit 5940), delivered.

### Service-receipt correction through the real staff flow (7 November 2026)

Replacements were limited to a receipt for the same posting, which only a replay produces; staff instead reverse the wrong charge and post the right one, a new posting. Karafuu now offers any later accepted services receipt of the same stay. Test server, local run (`VfmsTestServerCorrectionTest`, run `V6PDFF`): receipts `XHE2103345` (2 × WATER) and `XHE2103346` (1 × WATER), the first reversed, correction `XHE2103345` → `XHE2103346` delivered under unit 5940, check-out `XHE2103347`. A browser run did the same through the folio and register screens: `XHE2103350` (3 × SODA) reversed, `XHE2103351` (2 × SODA) posted, correction delivered.

## Withholding and B2B check-ins on ZRA's test server, 10 November 2026

ZRA (WhatsApp, 10 November) added two optional fields to the existing `ACCOMMODATION_REQUEST` (same `accommodation/` path, no new endpoint): `accommodationType` (`WITHHOLD` or `BTOB`) and `zrbNumber`. Their samples pair `WITHHOLD` with a `WA…` number (a government institution) and `BTOB` with the tour operator's own registration number (`zrbNumber` equal to `tourRegNo`). Neither field is in guide v1.6.

Probed with curl from the dev machine against the shared test unit `5940`, run `67D17043`, fake passports, USD 100 BB, references `PROBE-67D17043-<letter>`. No credentials or test hosts are recorded.

| Body                                                                                                                                              | ZRA answer                                                  |
| ------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------- |
| No new fields (control), `DIRECT`                                                                                                                 | accepted; report `XHE2104169`                               |
| `BTOB` + tour operator's number, `TOUR OPERATOR` source with `tourRegNo`, `costOptions` `57`                                                      | accepted; report `XHE2104162`                               |
| `BTOB` + the same number, `DIRECT` source (two runs)                                                                                              | accepted; reports `XHE2104164`, `XHE2104166`                |
| `BTOB` with a made-up `Z…` number, a `WA…` number, or no `zrbNumber`                                                                              | HTTP 200, `5000` `PLEASE USE THE CORRECT ZRB NUMBER`        |
| `WITHHOLD` with ZRA's sample `WA2400117`, a made-up `WA…`, a tour operator's `Z…`, the test unit's own Z-number, or no `zrbNumber`; either source | HTTP 200, `5000` `This institution not Registered`          |
| `accommodationType` `NONSENSE` or lowercase `withhold`                                                                                            | HTTP 400, `Invalid JSON request or unsupported field value` |

- **`BTOB` works, and ZRA checks the number.** It is accepted only with a `zrbNumber` that ZRA knows.
- **`WITHHOLD` could not be accepted on the test server.** Every number, including none, gets the same refusal, so the check is not on the number's format. Either the test unit is not set up for withholding or no `WA…` number is registered on the test server. A refused `WITHHOLD` records nothing: check-out of its guest returned `Guest Document … Not Found`.
- **Nothing visible changes.** The accept response, the check-out report (`accommoDetail` holds only source, `tourRegNo`, `costOptions`, `tourName`) and `accommodationReport` do not echo `accommodationType` or `zrbNumber`. The `BTOB` reports have the same room tax (13.04) and levy (5.00) as the control. What ZRA does differently with either type is unknown.
- **`BTOB` does not merge a group's reports.** A two-room `BTOB` group (run `E363E02F`, two guests per room, `DIRECT`) was checked out room by room as Karafuu does. ZRA returned one report per guest, as for any stay: `XHE2104216`, `XHE2104219` for one room and `XHE2104221`, `XHE2104224` for the other, each USD 100, tax 13.04, levy 5.00.
- Every accepted probe stay was checked out; nothing is left open on unit `5940`.

### Browser walkthrough through Karafuu, 11 November 2026 (spec #242)

Ran the integration branch locally against a disposable SQLite database, fake guests, and ZRA's shared Sandbox unit `5940`. The T3 preview disconnected, so the walkthrough continued in gstack's headless browser with saved screenshots. No production filings were made.

- Two-room `BTOB`, source `DIRECT`: `T66853-RES-000001-2` and `T66853-RES-000002-3` accepted on their first attempts. Both checked out: reports `XHE2104522`, `XHE2104524`. Their combined group statement showed both rooms, payments, currency totals and both report numbers; exported to a two-page A4 PDF.
- `WITHHOLD` initially exposed a response-shape bug: ZRA returns `{headers: [], body: {statusCode: 5000, statusMessage: "This institution not Registered"}, statusCode: "OK", statusCodeValue: 200}`. Karafuu classified the outer envelope as unknown. Added regression tests and fixed refusal classification and display; the raw response remains stored unchanged. This first refused stay (`RES-000003`) was not accepted by ZRA and was not replayed.
- A fresh `WITHHOLD` (`RES-000004`) was correctly refused with the exact message. Through the real Edit paying institution dialog, cleared the type, saved, supplied a reason and re-filed. `T66853-RES-000004-2R1` was accepted without either new field. Checked out: report `XHE2104526`.
- Ordinary stay (`RES-000005`), paying institution None: `T66853-RES-000005-2` accepted with neither new field; checkout accepted, report `XHE2104529`.
- Every check-in accepted during this run received an accepted checkout. The earlier unknown local filing contains a definite provider refusal, not acceptance. No accepted stay from this run was left open.
- Timing limit: advanced Karafuu's business date for two nightly audits without advancing ZRA's real clock. ZRA returned one-night totals (USD 105 per guest, including USD 5 levy), and existing VFMS adjustments reconciled the local two-night folios. This does not verify real multi-day withholding calculations.
- All institution/guest names in screenshots are demo labels, not proof that the named institution owns the test ZRB number. `WITHHOLD` acceptance on Live remains unproven.

This was an interactive browser run, not the full repeatable #246 opt-in driver. That driver and the final release checks remain outstanding; keep the PR draft.

## Services receipt types `CS` and `WS` on ZRA's test server, 12 November 2026

Question: can the hotel API issue a receipt like the hotel's portal-issued government receipt `OGK283` (customer "OR-TAWALA ZA MIKOA", TZS, meals and conference items, 15% VAT, no levy, one receipt)? Probed `service/` (`ACCOMMODATION_ADD_SERVICE`) with `scripts/vfms-probe.sh`, using the two test logins printed in the test guide (Note brief, p.33): accommodation unit `5940` and restaurant/other-services unit `5941`. No checked-in guest was used.

| Body                                                                                | Unit | ZRA answer                                                                 |
| ----------------------------------------------------------------------------------- | ---- | -------------------------------------------------------------------------- |
| `WS`, `documentNumber` empty or null                                                | both | HTTP 400, `documentNo Field is Missing or Null` / `Can't be blank`         |
| `WS`, any text as `documentNumber` (no such guest)                                  | 5940 | accepted; receipts `XHE2104619`, `XHE2104620`                              |
| `WS`, same                                                                          | 5941 | accepted; receipts `US024729`, `US024730`                                  |
| `WS`, `documentNumber` `OR-TAWALA ZA MIKOA`, the two `OGK283` items and prices, TZS | 5940 | accepted; `XHE2104621`, TZS 11,109,000.25, tax 1,449,000.03                |
| `CS`, a document with no checked-in guest                                           | 5940 | HTTP 200, `5000` `This Business Unit do not have parent Accomodation unit` |
| `CS`, same                                                                          | 5941 | HTTP 200, `5000` `Guest Document … Not Found`                              |
| `WS`, a reference already used                                                      | 5940 | HTTP 200, `5000` `Reference number … already exists`                       |

- **`WS` is a stand-alone sale; it needs no guest or stay.** Whatever is sent as `documentNumber` becomes the receipt's customer: ZRA's viewer data (`sales-service/api/v2/sales_detail/receipt/<n>`) for `XHE2104621` shows `customer` "OR-TAWALA ZA MIKOA", both items, receipt amount 11,109,000.25 and tax 1,449,000.03. These are the same figures as `OGK283`, so the receipt matches the portal one apart from its number.
- Extra fields (`customerName`, `zrbNumber`) are accepted but ignored; the customer comes only from `documentNumber`.
- Price is tax-inclusive, VAT 15%, no infrastructure levy, as on `OGK283`. The response returns `type` `WS`; the guide's example returns `CR` for `CS`.
- **`WS` is an ordinary receipt, not a withholding one.** ZRA's viewer has a separate withholding layout (`withhold-form/<n>`, read from `sales-service/api/v2/withold/receipt/<n>`); for `XHE2104621` that call answers `Receipt Number XHE2104621 is not withhold`. `OGK283` also uses the ordinary layout, so it is a plain sale in the institution's name; any withholding is recorded on ZRA's side separately.
- Not yet shown: whether Live accepts `WS` under the hotel's production accommodation unit. Karafuu sends only `CS` today.

### `WS` beside an open stay, 12 November 2026 (run `WSG122943`)

A two-guest `DIRECT` stay was checked in (reference `WSG122943-IN`, room 2811, USD 100 BB). While it was open:

- `WS` in a made-up institution's name with two items (4 × 20 + 1 × 150, USD) was accepted as receipt `XHE2104623`: sales 230, tax 30.00. It is not attached to any guest.
- `CS` for guest A was accepted as `XHE2104624`, as today.
- **`WS` with guest A's document number was accepted (`XHE2104625`) but ZRA attached it to guest A's stay**: `accommodationReport` lists it under guest A's `service` with `type` `WS` beside the `CS` item. So the `documentNumber` of a `WS` must be the payer's name, never a checked-in guest's document, or the sale is counted in that guest's check-out.
- Check-out of both guests was accepted; the stay is closed (`isClosed` true, both guests `isChackedOut`). Nothing is left open on unit `5940`.

### Guest-type scenarios, 12 November 2026 (runs `SCN130316`, `SCN130340`)

Each stay was one guest, USD 100, checked in and checked out on unit `5940`; nothing is left open.

- **Normal guest with extras (`SCN130316`).** Check-in accepted. Three `CS` extras were accepted, each with its own receipt: laundry `XHE2104640` (hotel unit 5940), dinner `US024735` (**restaurant unit 5941**, same guest document), spa TZS 50,000 `XHE2104641`. The check-out report `XHE2104642` listed the room (100, tax 13.04) and **all three extras, including the restaurant unit's**, with the TZS spa converted to USD 19.23. Total 159.23, tax 20.76, levy 5.00. So a stay produces one receipt per extra plus one check-out report that summarises them.
- **`WS` with a checked-in guest's document (`SCN130340`).** Conference hall USD 40 was accepted as `XHE2104645` and then **appeared in that guest's check-out report** `XHE2104646` (total 140). So `WS` with a guest's document behaves like `CS`.
- **After check-out.** `CS` with the departed guest's document was refused (`This Business Unit do not have parent Accomodation unit`). `WS` with the same document was accepted as a stand-alone receipt (`XHE2104648`).
- **Day use (no room).** `WS` in the name "CASH CUSTOMER" with pool day pass 2 × 15 and lunch 2 × 12 was accepted as `XHE2104649`: sales 54.00, tax 7.04, no levy.
- **Discount.** `WS` spa 100 with `discount` 10 was accepted as `XHE2104650`: sales 90.00, tax 11.74. The discount is an amount per line, taken off before tax is extracted.

### General receipts through Karafuu, 12 November 2026 (ticket #250)

Opt-in driver `tests/Feature/FiscalReceipting/VfmsTestServerGeneralReceiptTest.php` (`VFMS_TEST_SERVER_RUN=1`), through Karafuu's real routes and outbox, against the shared test unit `5940`. Fake guests; no credentials or test hosts recorded.

- **Extra after check-out.** A one-guest USD stay: check-in accepted, `CS` water 2 × 10 accepted as `XHE2104828`, check-out accepted as report `XHE2104829`. Then a dinner 1 × 25 with discount 5 was posted on the departed stay: Karafuu sent `WS` in the guest's full name ("Test Guest 1", not the passport), accepted as `XHE2104831`, sales 20.00, tax 2.61. The stay was checked out before the `WS`; nothing is left open.
- **`BTOB` group not run.** `VFMS_TEST_BTOB_ZRB_NUMBER` is not set in any checkout, and every number tried for `BTOB` (the guide's sample `Z025608121` and two tour-operator numbers from earlier runs) was refused with `PLEASE USE THE CORRECT ZRB NUMBER`. `WITHHOLD` with `WA2400117` is still refused (`This institution not Registered`). Refused check-ins open nothing on ZRA. The group scenario is in the driver and skips until a test-server `BTOB` number is set.
- **Institution bodies, probed directly.** In place of the group run, `scripts/vfms-probe.sh` sent the two bodies Karafuu builds for an institution's extras (no stay; `documentNumber` "Test Institution …"). USD dinner 2 × 18 less 6 plus water 3 × 5: `XHE2104832`, sales 45.00, tax 5.87. TZS conference hall 150,000: `XHE2104833`, tax 19,565.22. These are probes, not runs through Karafuu's routes.

### Customer accounts through Karafuu, 12 November 2026 (ticket #251)

Opt-in driver `tests/Feature/FiscalReceipting/VfmsTestServerCustomerAccountTest.php`, run with
`VFMS_TEST_SERVER_RUN=1 php artisan test --filter=VfmsTestServerCustomerAccountTest`.
Both scenarios used the real account, extra, payment and close routes, and the existing
general-receipt outbox path. The driver requires ZRA's test-server host and Sandbox
credentials; it allows no other outbound HTTP. No Live filing or real customer data.

- **Day use, discounted:** customer "Test Day Use ACCU4EIMQ", no room or reservation.
  Pool and lunch, 2 x USD 25 less a USD 5 line discount; bank-transfer payment USD 45.
  Nothing filed before close. Closing accepted one `WS`, receipt **`XHE2104839`**,
  sales USD 45.00, tax USD 5.869565217391304; account closed with zero balance.
- **Event over two business dates:** customer "Test Event ACCJKGIAE", no room or
  reservation. Conference hall, 2 x USD 25 less USD 5 on the first business date;
  the real night-audit route advanced the business date, then catering 3 x USD 10
  was posted on the next date. Bank-transfer payment USD 75. Closing accepted one
  `WS` containing both dates' items, receipt **`XHE2104840`**, sales USD 75.00,
  tax USD 9.782608695652174; account closed with zero balance.
- Both filings were accepted on their first attempt. This proves items accumulated
  over Karafuu business dates, not two elapsed days on ZRA's clock. There are no
  accommodation stays to leave open.

### End-to-end acceptance evidence, 12 November 2026 (ticket #254)

Required base: `origin/spec/ws-fiscal-receipt` at
`4dad5e168697d1249719007c258b205b535717e0` (tickets #250-253), merged before
editing on `t3/vfms-test-server-proof`. Driver changes:
`2abeb64e` (`test: complete fiscal receipt route proofs and stay cleanup`).
All data below is synthetic. Only Sandbox and the authorized test-server host
`102.223.7.131` were allowed. No Live requests or credentials were used.
SQLite was disposable; shared local MySQL was not modified.

Command (real routes, real synchronous outbox delivery, no VFMS HTTP fake):

```sh
VFMS_TEST_SERVER_RUN=1 php artisan test \
  tests/Feature/FiscalReceipting/VfmsTestServerGeneralReceiptTest.php \
  tests/Feature/FiscalReceipting/VfmsTestServerCustomerAccountTest.php
```

Final run at approximately 22:33 EAT: **6 passed, 1 skipped, 62 assertions**.
Three passes are the real normal/day-use/event scenarios; the other three are
offline safety checks for cleanup success, cleanup refusal with continued
attempts for the other room, and rejection of a non-test-server host.
The skipped scenario is the real two-room BTOB group, not a successful proof.

| Real route/outbox scenario               | Accepted ZRA evidence | Stored figures and provenance                                                                                                                                                                                        |
| ---------------------------------------- | --------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Normal guest, first extra while in house | CS `XHE2104862`       | WATER, 2 x USD 10; sales USD 20. Outbox `01m78m13909ybtqp5hbb9axfy4`.                                                                                                                                                |
| Same guest, second extra while in house  | CS `XHE2104863`       | LAUNDRY, 1 x USD 15; sales USD 15. Outbox `01m78m13s0w9a74k4czywt5wgw`.                                                                                                                                              |
| Same stay, checkout                      | Report `XHE2104864`   | Room USD 100, extras USD 35, levy USD 5, total including levy USD 140, VAT USD 17.61. Outbox `01m78m149x437f1zah0cd5c719`.                                                                                           |
| Extra after that checkout                | WS `XHE2104866`       | LATE DINNER, USD 25 less USD 5; sales USD 20, tax USD 2.6086956521739135. Customer "Test Guest 1", not a passport. Outbox `01m78m15034jzjz4mx4nbrdzsb`.                                                              |
| Discounted day use, no stay              | WS `XHE2104867`       | POOL AND LUNCH, 2 x USD 25 less USD 5; sales USD 45, tax USD 5.869565217391304. Payment USD 45; closed. Outbox `01m78m178m4y9sg3vsrrpqmj67`.                                                                         |
| Event over two business dates, no stay   | WS `XHE2104868`       | CONFERENCE HALL, 2 x USD 25 less USD 5, then SECOND DAY CATERING, 3 x USD 10 after the real night-audit route. Sales USD 75, tax USD 9.782608695652174. Payment USD 75; closed. Outbox `01m78m186gamn4v5xx1cgad861`. |

Normal stay subject: `01m78m12hd8hrsmkbn3fhzasss`. Its accommodation outbox
`01m78m12kvt7m7cvv34d9rjynj` was accepted before either extra. Each of the five
normal-stay messages and the two account messages was delivered on its first
attempt. The driver always attempts checkout in `finally`, including when an
earlier assertion or route fails, tries the remaining rooms if one cleanup
fails, and fails explicitly if an accepted stay lacks an accepted checkout.
**No cleanup failed and no accepted stay was left open in these runs.**

An earlier run of the same scenarios also accepted CS `XHE2104853`,
CS `XHE2104854`, checkout `XHE2104855`, post-checkout WS `XHE2104857`,
day-use WS `XHE2104858`, and event WS `XHE2104859`. That stay was also checked
out. However, the stored response for report `XHE2104855` had an empty service
array and extras USD 0 despite both accepted CS responses. The final run's
`XHE2104864` includes both extras correctly. No payload-routing fix occurred
between those runs; do not erase the first observation or claim that receipt
linkage on the provider is invariably immediate. The stored response remains
the source of print figures, not a reconstructed total.

**Real BTOB acceptance remains blocked.** Checked `.env.example`,
`config/services.php`, the documented test-server observations and the necessary
test keys in the main checkout's `.env`; no
`VFMS_TEST_BTOB_ZRB_NUMBER` authorized for this test unit was available.
The driver explicitly skipped. No new numbers were tried, no institution was
invented for the real server, and no DIRECT stay or direct service probe is
claimed as BTOB route integration. Prior direct probes `XHE2104832` and
`XHE2104833` remain direct probes only.

#### Browser evidence and boundaries

The T3 shared-browser status was `available: false`, `visible: false`, with no
tab. Aside was also unavailable (`NEEDS_ASIDE: Linux`);
the mandated gstack browse skill used its local Chromium fallback. No Chrome
MCP or T3 preview browser was used. The local app ran only on
`http://127.0.0.1:8124`, with a separate `.scratch/browser.sqlite` and synthetic
tenant data. Its VFMS HTTP boundary was faked and stray HTTP was blocked.
**Every `BROWSER-*` document in these screenshots is a fixture, not ZRA
acceptance**, particularly the BTOB group. The real evidence is the table above.

- Normal: reservation ZRA summary card, separate guest/general/report sections,
  and print-all with four documents.
- Two-room BTOB fixture: held USD/TZS extras on the reservation and non-fiscal
  group statement; manual issue confirmation naming the institution; another
  extra; first checkout leaving it held; last checkout issuing it; two checkout
  reports and three general receipts. Both fixture stays ended checked out.
- Day use: new-sale form with first item and discount, charge/balance display,
  payment dialog, linked non-fiscal receipt, irreversible-close confirmation,
  closed account and ZRA receipt.
- Event: dated account form, first day's item, real night-audit UI, second
  business day's charge dialog, both dates in Items, payment and close
  confirmation, closed account and receipt.
- Receipts: all three tabs, number filter, source links to the stay, group,
  day-use account and event; account kind/status filters; light/dark captures
  and mobile/tablet captures of the new account and register surfaces.
- A4/80 mm screenshot and PDF artifacts for each scenario. A4 PDFs have
  4/5/1/1 pages for normal/group/day-use/event respectively, one per document;
  80 mm PDFs have the same page counts. This is a bounded verification of #252,
  not a repeated print/QR redesign.

No product regression was reproduced in these flows. The scratch database export
initially omitted separate indexes (causing an account-create 500); restoring
the indexes fixed the fixture, not application code. Initial navigation by
reservation ULID instead of its public `RES-...` number produced a harness-only 404. Successful flows were repeated after fixing those harness mistakes;
the final browser console had no errors.

One existing display limitation remains visible: the Receipts table shows
"Unavailable" as the customer of a CS receipt whose issue-time evidence contains
only a document number. `IssuedDocumentsTest` explicitly preserves this rule
instead of borrowing today's lead guest name. The source stay link works, and
the stay's fiscal page groups the CS rows under its guest, but the register is
not proof that every CS row has a named customer. No identity document was
substituted or exposed to hide the limitation.

Artifacts are local and untracked under `/tmp/spec249-254/`:
`real-normal-final-run.json`, `vfms-test-server-account-day_use.json`,
`vfms-test-server-account-event.json`, `real-normal-first-run.json`,
`route-proof-result.json`, plus the scenario-named screenshots and
`normal-a4.pdf`, `normal-80mm.pdf`, `group-a4.pdf`, `group-80mm.pdf`,
`dayuse-a4.pdf`, `dayuse-80mm.pdf`, `event-a4.pdf`, `event-80mm.pdf`.
The JSON logs contain outbox IDs, request types, paths, redacted bodies,
provider references and responses, never credential headers.
The screenshot manifest is `/tmp/spec249-254/browser-evidence.md`.

#### Validation gate

Affected billing/receipt tests: **40 passed, 4 opt-in skips, 362 assertions**
(44 tests) before the browser walkthrough. Display, register, account-isolation
and group-statement tests: **44 passed, 1,089 assertions**.

Final fetch/merge of `origin/spec/ws-fiscal-receipt` was already up to date at
`4dad5e168697d1249719007c258b205b535717e0`. Ran `composer check:fast`
**once after that merge**: build passed; SQLite Pest **2,822 passed,
26 skipped, 48,631 assertions** (2,848 tests); Pint, Larastan/PHPStan,
TypeScript, formatting and lint all passed. Full log:
`/tmp/spec249-254/check-fast.log`.

Full MySQL/slow E2E is deliberately
reserved for the parent's final post-review fix-up agent and CI, as instructed.
**Ticket #254's full MySQL acceptance is still pending that final gate**, and
real BTOB acceptance is still skipped. Neither is claimed complete here.
