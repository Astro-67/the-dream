# Thedream PMS: Product Requirements Document

- **Version:** 1.1 (draft for product and engineering review). Replaces the v1.0 draft (`PRD.pdf`), which is kept as input only.
- **Date:** 6 October 2026
- **Product:** multi-tenant hotel management SaaS, built new
- **Market:** Zanzibar and mainland Tanzania first; international expansion later
- **Customers:** independent hotels, resorts, lodges, serviced apartments, and hotel groups and chains

**Related documents**

- Terms: [`docs/GLOSSARY.md`](../GLOSSARY.md). Every bold term in this PRD is defined there.
- Decisions: [`docs/adr/`](../adr/), ADRs 0001–0011.
- Architecture: [`docs/architecture.md`](../architecture.md).
- Research and sources: [`docs/research/booking-com-integration.md`](../research/booking-com-integration.md), and the ZAN Gateway API spec at [`docs/research/sources/zan-gateway-openapi.yaml`](../research/sources/zan-gateway-openapi.yaml).
- ZRA documents: the ZAN Gateway PMS Integration Guide v1.3 ("ZAN GW guide") and the VFMS Hotel API Guide v1.6 ("VFMS guide").

> **Security:** The two ZRA PDFs in this folder print credentials issued to other parties. Do not share them further, and do not copy any credential into code, tickets or documents. Thedream obtains its own credentials through ZRA vendor registration.

---

## 1. Product vision

Hotel businesses register online, set up one or many properties, invite their staff, and run their hotels from one secure platform. Each organization controls its own data, permissions, subscription and property settings. Group owners oversee their portfolio, and hotel teams work within the properties they are assigned to.

For properties in Zanzibar, Thedream is also the hotel's route to legal compliance. Every booking from every channel is reported to the Zanzibar Revenue Authority (ZRA), and every stay is fiscally receipted, without staff re-keying anything.

The product should reduce manual work, keep room availability accurate across channels, make guest billing traceable, and give clear operational reporting. These are intended outcomes, not claims about an existing product.

## 2. Context

### 2.1 Product benchmark

Integrated hospitality suites such as Yanolja Cloud Solution combine PMS, channel distribution, direct booking, payments and point of sale. This informs scope only. It does not establish feature parity, partnerships or architecture.

Thedream's proposed differentiation:

- practical local onboarding;
- English-only interfaces for this version;
- built-in Zanzibar compliance (ZAN Gateway and VFMS);
- explicit multi-currency handling;
- a clear path from one hotel to a group.

### 2.2 Regulatory context: Zanzibar

ZRA runs two systems a Zanzibar PMS must integrate with. They do different jobs:

| System          | Purpose                                                                        | When Thedream calls it                                         |
| --------------- | ------------------------------------------------------------------------------ | -------------------------------------------------------------- |
| **ZAN Gateway** | Booking reporting: every reservation event from every channel                  | On every booking, change, cancellation, check-in and check-out |
| **VFMS**        | Fiscal receipting: tax per guest per night, and fiscal receipts with a QR code | From check-in to check-out, plus extra services                |

Obligations that shape this PRD:

- A PMS supplier "shall not onboard new properties in Zanzibar" unless all booking channels are integrated and transmitting (ZAN GW guide §3.5).
- Manual entry, delayed sync and spreadsheet imports of OTA bookings are non-compliant (§3.3, §12).
- The PMS supplier is responsible for completeness, accuracy and timeliness "regardless of booking source or system configuration", including when a channel manager is used (§3.4).
- New bookings, changes and cancellations must be reported within 5 minutes, and check-ins and check-outs "immediately" (§4 Step 4).

The compliance deadlines published by ZRA have passed, so these obligations are treated as in force today.

### 2.3 Distribution context: Booking.com

Booking.com has paused onboarding of new connectivity partners "until further notice" ([connect.booking.com](https://connect.booking.com/)). Thedream therefore reaches Booking.com and other OTAs through a **channel manager** (ADR 0001).

## 3. Goals, scope and priorities

| Priority | Meaning                                                        |
| -------- | -------------------------------------------------------------- |
| **P0**   | Required for the first paid multi-tenant release and the pilot |
| **P1**   | Commercial expansion after the P0 pilot                        |
| **P2**   | Advanced enterprise and international capabilities             |

**P0 includes:**

- self-registration and onboarding;
- organizations with multiple properties, staff permissions and tenant isolation;
- rooms, rates, reservations, check-in and check-out, room moves, and night audit;
- folios, payments, receipts and corrections;
- housekeeping;
- basic property and group reporting;
- subscription lifecycle, auditability, backups and support administration;
- **for Zanzibar properties only:** the **Zanzibar compliance pack** (ADR 0002):
    - ZAN Gateway booking reporting;
    - VFMS fiscal receipting;
    - channel-manager connectivity for every channel the property sells on, including Booking.com.

**P1 includes:**

- direct booking page;
- OTA connectivity for properties outside Zanzibar;
- local payment provider integration;
- restaurant, point-of-sale and outlet operations;
- conferences and events;
- maintenance;
- group blocks and advanced rate controls;
- channel content and promotions management from inside Thedream.

**P2 includes:**

- a direct Booking.com connection, if Booking.com reopens partner onboarding;
- revenue recommendations, loyalty, enterprise SSO and custom domains;
- deep accounting and hardware integrations;
- additional jurisdictions.

**Out of scope for this PRD:**

- **Mainland Tanzania fiscal receipting (TRA).** Mainland properties receive non-fiscal receipts in P0, and onboarding tells them so.
- Full accounting or ERP, payroll, a consumer travel marketplace, native mobile apps, and offline reservation writes.
- PCI certification. Thedream does not handle raw card data (ADR 0004).

## 4. Tenancy and multi-property model

An **organization** is the tenant: the customer business and subscription owner. A **property** is one hotel operated by that organization. A user is a person with explicit organization and property memberships.

- Different organizations must never see each other's operational data, guest identities, reports, files, notifications, credentials or channel connections.
- Organization membership alone does not grant access to every property. Permissions set property scope and access to consolidated reporting.
- A person may belong to several organizations through separate invitations. The interface always shows the current organization and property.
- Switching context revalidates membership and discards previous-property filters and cached results. A saved form stays bound to its original property.
- **Reservation numbers**, room numbers and receipt numbering are scoped to the property, not assumed globally unique.
- Guest records are property-scoped. An organization-wide guest directory requires an explicit privacy policy, and no guest directory is shared across organizations.
- Platform support has no standing access to guest data. Each support session needs a reason, customer authorization where applicable, an expiry and an audit trail.
- **Platform-level exception:** the ZRA supplier key and the central reporting service belong to the platform, not to any organization (ADR 0003). They must still never mix one organization's data into another's reports.

**Proposed implementation direction:** a modular monolith with a shared database and explicit organization and property boundaries. Tenant context covers requests, background jobs, scheduled tasks, imports, exports, storage, caching, notifications and integrations.

## 5. User roles

| Role                           | Access                                                                                                                                                          |
| ------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Platform administrator         | Tenant provisioning, plans, service health, ZRA supplier registration and key management, controlled suspension and support. No routine access to guest records |
| Compliance operator (platform) | ZAN Gateway and VFMS delivery monitoring, incidents and reconciliation across properties. Sees delivery status and errors, not full guest identity documents    |
| Organization owner             | Organization settings, properties, membership, subscription, consolidated reporting                                                                             |
| Group operations manager       | Assigned portfolio operations and reports. Financial and security settings only when granted                                                                    |
| Property manager               | Property setup, staff, channel connections, approvals, fiscal exception review, property reports                                                                |
| Front desk / reservations      | Reservations, guest registration including identity documents, room assignment, permitted charges and payments                                                  |
| Finance / cashier              | Financial reports, reconciliation, fiscal exceptions report, approved refunds and corrections                                                                   |
| Housekeeping                   | Assigned room status and tasks. No guest identity documents or financial records                                                                                |
| Auditor / read-only            | Explicit reports and records, without the right to change them                                                                                                  |
| Guest                          | Only their own verified booking or guest-portal session                                                                                                         |

Permissions are checked on the server for every action. Department and job title never grant permissions. P0 uses defined roles plus sensitive-action permissions. Custom roles may follow in P1.

## 6. Jurisdiction profiles

Every property has exactly one **jurisdiction profile**, set at onboarding from its location. The profile decides required guest registration fields, tax rules, allowed currencies and authority reporting. A property's profile cannot be changed while it has future reservations or open folios.

| Setting                              | Zanzibar                                                                                                              | Mainland Tanzania                 |
| ------------------------------------ | --------------------------------------------------------------------------------------------------------------------- | --------------------------------- |
| Guest registration at check-in       | Document type, document number and nationality for **every guest**. Any document type is accepted, not only passports | Configured by the property        |
| Allowed selling and folio currencies | TZS, USD, EUR only                                                                                                    | TZS, USD, EUR (configurable)      |
| Booking reporting                    | ZAN Gateway (P0)                                                                                                      | None                              |
| Fiscal receipting                    | VFMS (P0)                                                                                                             | Out of scope: non-fiscal receipts |
| Go-live gate                         | Every declared channel connected, ZRA property registration complete, VFMS business unit registered                   | Standard onboarding               |
| OTA connectivity                     | P0                                                                                                                    | P1                                |

## 7. Functional requirements

Each requirement has an ID and a priority. "Zanzibar" means a property under the Zanzibar jurisdiction profile.

### 7.1 Registration and onboarding (P0)

- **REG-01:** Account registration with name, email, password, business name, country and acceptance of terms. Verify email before live operation. Existing users accept invitations without duplicate identities.
- **REG-02:** A resumable onboarding flow creates the organization, owner membership, trial subscription and first property. Repeated submissions never create duplicates.
- **REG-03:** Collect property name, address, contact details, type, timezone, operational currency, room inventory, tax configuration, branding and **jurisdiction profile**. Never force Zanzibar fields on other properties.
- **REG-04:** An onboarding checklist covers:
    - verify email;
    - complete the property profile;
    - configure rooms and rates;
    - review taxes;
    - invite staff;
    - import future reservations;
    - make a sample reservation.

    Demonstration data is visibly separate from live records.

- **REG-05 (Zanzibar):** The property declares **every channel it sells on**. It cannot go live until:
    - each declared channel is connected and has passed a test booking;
    - the property is registered with ZAN Gateway (hotel info accepted);
    - its VFMS business unit is registered and has passed a test check-in.

    Thedream records who confirmed the channel list and when.

- **REG-06:** Importing from a previous system brings in **future reservations only**. On go-live, Thedream reports each imported reservation to ZAN Gateway as new. Completed historical stays remain the previous supplier's responsibility.
- **REG-07 (mainland):** Onboarding states plainly that Thedream issues non-fiscal receipts for mainland properties.

**Acceptance criteria:**

- A new operator independently reaches a usable first property.
- Interrupting and resuming onboarding keeps progress.
- Invalid imports show errors for each row.
- A Zanzibar property with one declared channel still unconnected cannot take live check-ins or sell inventory.

### 7.2 Organizations, properties and staff (P0)

- **ORG-01:** Owners add properties within plan limits, assign managers and switch among authorized properties. Each property has its own timezone, currency, branding, numbering, taxes and settings.
- **ORG-02:** Invitations specify organization, permitted properties, role, expiry and inviter. Revoking access immediately blocks new operations and downloads.
- **ORG-03:** Archive properties without destroying reservation, financial or reporting history. Block closure while future bookings, open balances or undelivered ZRA reports remain.

**Acceptance criteria:** a front-desk user assigned to one property cannot discover or change another property's records, including by changing IDs or opening exports.

### 7.3 Subscriptions and platform administration (P0)

- **SUB-01:** SaaS subscription invoices are separate from guest folios. Subscription states are trial, active, past due, suspended and cancelled, with an audited transition policy.
- **SUB-02:** Configurable plans, property and room limits, trial length, module entitlements and billing periods.
- **SUB-03:** Entitlements are enforced on the server. A downgrade never silently deletes rooms or properties.
- **SUB-04:** Non-payment: reminders and a grace period, then new sales are blocked. Existing guests can still check out, and data can still be exported.
    - **Zanzibar:** booking reports and VFMS calls for existing stays continue during suspension. A suspended account must never cause a compliance gap.
- **SUB-05:** The Zanzibar compliance pack is included in every plan for Zanzibar properties (§13).

### 7.4 Rooms, reservations and front desk (P0)

- **PMS-01:** Manage room types, rooms, occupancy limits, rate plans, date availability, restrictions (minimum stay, closed to arrival, stop-sell) and out-of-service periods, with a reservation calendar.
- **PMS-02:** Support direct, walk-in, phone and **channel** reservations, with amendments, cancellations, **no-shows**, arrivals, departures, room assignment and room moves.
    - Keep the channel, **channel reference**, actor, timestamps and amendment history.
    - Concurrent requests must never sell the same last room twice.
- **PMS-03:** Each reservation has a **reservation number**: unique within the property, never reused, unchanged for the whole stay. A channel's own identifier is stored as the **channel reference**.
- **PMS-04:** Check-in requires an eligible reservation, a ready room, and the registration fields set by the jurisdiction profile.
    - **Zanzibar:** document type, document number and nationality are required for every guest before check-in completes.
- **PMS-05:** Checkout shows open balances and uses a manager-controlled exception policy. Balances are never silently ignored.
- **PMS-06:** Night audit closes the business date with exception review and repeat-safe posting. Running the same close twice never duplicates charges.
    - At close, reservations not arrived by end of arrival date are proposed as no-shows for confirmation.
    - **Zanzibar:** the night audit sends VFMS price changes for the next night (FSC-04).
- **PMS-07:** A **booking received outside integration** is entered with a required reason. The reservation is marked as such and appears in the channel reconciliation report (DST-07).

**Acceptance criteria:**

- Two agents competing for the last room produce at most one confirmed booking.
- Checkout updates housekeeping.
- A Zanzibar check-in cannot complete with a guest missing an identity document.

### 7.5 Guest records (P0)

- **GST-01:** Maintain contact details, stay history, preferences, identity documents and notes, with restricted visibility.
    - Identity documents are visible only to front desk, property manager and roles explicitly granted access.
    - Mask sensitive fields for other roles.
- **GST-02:** A reservation is made for its lead guest, taken inline when booking. When a tour operator books, record its name, ZRA registration number and what it pays for (ADR 0015). Booker records from earlier versions are kept but no longer created by staff.
- **GST-03 (P1):** Booking-management links, pre-arrival registration (including identity documents for Zanzibar), and templated messages through an approved provider.

**Acceptance criteria:** guests from different organizations are never merged because they share an email address.

### 7.6 Folios, payments and financial controls (P0)

- **FIN-01:** One folio per reservation and currency. Show accommodation, taxes, extras, deposits, **channel-collected amounts** and balances clearly. Never add unlike currencies or convert silently.
- **FIN-02:** Posted entries stay traceable. Mistakes are corrected with linked reversals and adjustments with approval reasons.
- **FIN-03:** Cashier reconciliation, receipts, balance reporting and controlled refunds, with configurable permissions and thresholds. Retries never duplicate postings.
- **FIN-04:** Every reservation records its **payment collector**: the property (hotel-collect) or the channel (for example Payments by Booking.com).
    - When the channel collected, the reservation also records the **channel-collected amount**, including any per-person fees the channel took.
- **FIN-05:** The channel-collected amount is posted as a payment from the channel against the folio.
    - **Zanzibar:** the folio always shows the taxes VFMS calculated, including infrastructure tax. Any difference between what the channel collected and what is owed shows as an open balance owed by the guest, or as a reconciliation item. Nothing is netted off silently.
- **FIN-06:** Thedream never receives, stores or displays raw card numbers or security codes from any channel (ADR 0004).
    - Staff see masked card details only.
    - Virtual cards are charged outside Thedream, and the charge is recorded as a payment with a reference.
- **FIN-07 (P1):** A locally supported payment provider for cards and mobile money, using hosted or tokenized collection.

**Acceptance criteria:**

- A repeated provider or channel event posts once.
- A USD folio and a TZS folio stay independently balanced.
- A Payments-by-Booking.com stay shows the amount Booking.com collected and the VFMS taxes on the same folio, and they reconcile.

### 7.7 Housekeeping (P0)

- **OPS-01:** Room states are clean, dirty, occupied, inspection required and unavailable, with permitted transitions, assignment and status history.
- **OPS-02 (P1):** Maintenance tickets and inventory blocks.

**Acceptance criteria:** housekeeping users update only their authorized property and cannot see guest documents or financial reports.

### 7.8 Channel distribution and Booking.com (P0 for Zanzibar, P1 elsewhere)

- **DST-01:** Thedream connects to one selected **channel manager** (selection criteria in §9). Booking.com is the named launch channel. Any other OTA the channel manager supports may be connected.
- **DST-02:** Thedream controls availability, rates and restrictions, and sends every change to the channel manager.
    - Room descriptions, photos and promotions are managed on each OTA's own partner site (for Booking.com, the Extranet) in P0.
- **DST-03:** Map property, room type and rate plan identifiers explicitly for each channel. An unmapped room type or rate plan blocks that channel's go-live.
- **DST-04:** Receive new, modified and cancelled reservations from the channel manager.
    - Duplicate messages create one reservation.
    - An out-of-order change never overwrites newer state.
    - Every message is acknowledged only after it is stored.
- **DST-05:** Reject a channel reservation in a currency the property's jurisdiction profile does not allow. Raise an alert so the mapping can be fixed.
- **DST-06:** Show each channel connection's health, last successful message, delivery failures and retries. Saved credentials are never shown as proof of a working connection.
- **DST-07:** A daily reconciliation compares Thedream's reservations with the channel manager's for each property. It lists:
    - reservations missing on either side;
    - mismatched dates, guests or amounts;
    - every booking received outside integration.
- **DST-08:** A channel outage is logged as an incident with start and end times, the channels affected, and the reservations entered outside the integration during it.

**Acceptance criteria:**

- A Booking.com test reservation appears in Thedream with its channel reference, payment collector and amounts.
- A change to availability in Thedream reaches the channel manager.
- The product does not promise that overbooking across channels is impossible. Conflicts surface as reconciliation items. Staff may sell up to the property's overbooking allowance, zero by default (ADR 0015).

### 7.9 ZAN Gateway booking reporting (P0, Zanzibar)

- **ZAN-01:** Thedream reports as the single **PMS supplier** using one platform-held key (ADR 0003). Properties never enter ZRA credentials.
- **ZAN-02:** Each property's profile is sent to the hotel-info endpoint at go-live and whenever it changes. Required fields:
    - `Hotel_Code`, `Hotel_Name`, `City`, `State`, `Zipcode`;
    - `Country`, `Property_Type`, `Address`, `CurrencyCode`;
    - optional `tax_region` and `star_rating`.
- **ZAN-03:** Every reservation event produces a **booking report** keyed by the property's ZRA hotel code and the **reservation number**:

| Thedream event                                                   | `Status`             | `CurrentStatus`         | Notes                                                                                            |
| ---------------------------------------------------------------- | -------------------- | ----------------------- | ------------------------------------------------------------------------------------------------ |
| Reservation created (any channel, including outside integration) | `New`                | `Confirmed Reservation` |                                                                                                  |
| Reservation modified                                             | `Modify`             | `Confirmed Reservation` | Send the full current state                                                                      |
| Guest checked in                                                 | `Modify`             | `Checked In`            | Send immediately                                                                                 |
| Guest checked out                                                | `Modify`             | `Checked Out`           | Send immediately                                                                                 |
| Reservation cancelled                                            | `Cancel`             | `Cancel`                | Include `CancelDate`                                                                             |
| **No-show** confirmed                                            | `Cancel`             | `Cancel`                | Guest count 0, with `CancelDate` set to the no-show business date. Kept as a no-show in Thedream |
| Reservation created in error                                     | `Modify` or `Cancel` | `Void`                  | Requires manager permission and a reason                                                         |

- **ZAN-04:** Each booking report includes:
    - the reservation number;
    - guest first and last name;
    - arrival and departure dates;
    - guest count, with adults and children where known;
    - room number once assigned;
    - reservation date;
    - currency and tax-inclusive total, with tax and exclusive totals where known;
    - `guest_country`, as shown in the ZAN GW guide's example.

    Thedream does not send fields that neither ZRA document defines. In particular, no channel field is sent. The channel stays on Thedream's own records.

- **ZAN-05:** Reports go through a central queue that batches by property, one property per request, and respects a **100 requests per minute** limit for the whole platform.
    - Targets, measured from the event in Thedream: delivered within **1 minute**, an alert at **3 minutes**, and a **compliance incident** at **5 minutes**.
- **ZAN-06:** Handle partial acceptance. A 200 response can still contain rejected bookings, so check `rejected_booking_count` and `validation_errors` for each booking.
    - Retry 429 and 502 responses with backoff.
    - Send 400 and validation errors to the compliance operator and the property manager with the reason.
- **ZAN-07:** Corrections are made by re-sending the reservation's full current state through the API. Reports are never deleted.
- **ZAN-08:** Keep a delivery log for each booking report: payload hash, time sent, response, retries and final state. Show it to the property manager.

**Acceptance criteria:**

- For a Booking.com reservation that is created, modified, checked in and checked out, ZAN Gateway accepts four reports in order, each within target.
- A no-show is reported as a cancellation with zero guests.
- Under a simulated 429, no report is lost and none is duplicated.

### 7.10 VFMS fiscal receipting (P0, Zanzibar)

- **FSC-01:** Each property's VFMS business unit credentials are stored per property, separate from the ZAN Gateway supplier key, and are never shown after saving.
- **FSC-02:** At check-in, send an accommodation request containing:
    - every guest's full name, document type, document number and country;
    - room ID and name;
    - check-in date;
    - currency;
    - the **first night's tax-inclusive room price** and any discount;
    - the meal or package code;
    - a unique reference number for the transaction.
- **FSC-03:** Use the accommodation source `DIRECT` for direct, walk-in and OTA stays, with the guest as payer and the full tax-inclusive price (ADR 0005). Use `TOUR OPERATOR`, with the operator's ZRA registration number and cost options, only for stays paid by a registered tour operator.
    - The channel's commission never appears on a fiscal receipt.
- **FSC-04:** During the stay:
    - a room move sends a change-room call;
    - an added guest sends an add-guest call;
    - corrected guest details send a change-profile call;
    - at night audit, if the next night's rate differs, a change-room-price call is sent.
- **FSC-05:** Extras charged to the room folio (free-text items such as restaurant, minibar or laundry) are sent through the extra-services call, and the fiscal receipt number is stored against the folio charge.
- **FSC-06:** At checkout, send the check-out call and issue the **fiscal check-out report** with the VFMS QR code. Store the report number and totals.
- **FSC-07:** When VFMS is unavailable:
    - check-in, room moves and charges still go ahead in Thedream, and VFMS calls retry from a queue in order;
    - the final fiscal check-out report cannot be issued until every VFMS call for the stay is accepted;
    - a departure blocked this way goes to the manager's exception list.
- **FSC-08:** **Fiscal exceptions** are charges with no VFMS path: cancellation fees, no-show fees, and any charge to a guest who never checked in.
    - They are posted to the folio and get a receipt clearly labelled **non-fiscal**.
    - They are listed in a fiscal exceptions report for the property's accountant.
- **FSC-09:** Fetch and store the VFMS exchange rate when VFMS calculates in a currency different from the folio, and show the rate and date wherever it is used.
- **FSC-10:** Store every VFMS request and response, including response numbers and receipt numbers, linked to the stay, for audit.

**Acceptance criteria:**

- A Payments-by-Booking.com stay with a different rate on each night produces:
    - one accommodation request at check-in;
    - one price change on each night the rate changes;
    - a check-out report whose totals match the folio's VFMS taxes.
- A no-show fee produces a non-fiscal receipt and a fiscal exceptions entry.

### 7.11 Reporting and group oversight (P0)

- **RPT-01:** Property and authorized group views of occupancy, arrivals and departures, booked room value, balances, payments and housekeeping. Every KPI is defined, with the time it was last refreshed.
- **RPT-02:** Definitions:
    - **Occupancy:** sold room-nights divided by sellable room-nights.
    - **Booked ADR:** allocated booked room value divided by sold room-nights.
    - **Booked RevPAR:** allocated booked room value divided by sellable room-nights.

    Keep booked revenue separate from recognized revenue and cash collected, and handle zero denominators explicitly.

- **RPT-03:** Group reports show separate totals per currency unless the viewer selects an approved reporting-currency policy.
- **RPT-04 (Zanzibar):** A compliance dashboard for each property and for the platform:
    - booking reports pending, late and failed;
    - VFMS calls pending and failed;
    - fiscal exceptions;
    - bookings received outside integration;
    - channel reconciliation items;
    - open compliance incidents.
- **RPT-05:** Channel mix: reservations, room-nights and booked value by channel.
- **RPT-06 (P1):** Scheduled exports, budget comparisons, channel profitability and accounting exports.

### 7.12 Localization (P0)

- **LOC-01:** English-only staff workflows for this version, configurable business dates and timezones, and explicit TZS, USD and EUR handling. VFMS's "EURO" is mapped to and from EUR. Kiswahili, a translation layer and translation enforcement are deferred to a future version.
- **LOC-02:** Configurable tax and service-charge rules with effective dates, exemptions, inclusive or exclusive display, and rounding. A historical receipt keeps the tax settings it was issued with.
- **LOC-03:** Authority reporting runs only through the integrations in §7.9 and §7.10. There is **no manual export workflow** for Zanzibar authority reporting.

### 7.13 Outlets and events (P1)

Restaurant and outlet sales, menus, tables and kitchen workflow, conference venues and event bookings move to P1. Walk-in outlet sales can use the VFMS extra-services call with type `WS` (services for general customers); confirm with ZRA before P1 scoping.

## 8. Booking.com reservation lifecycle

This is how one Booking.com reservation moves through Thedream and both ZRA systems at a Zanzibar property.

| Step                          | Thedream                                                                                                                                            | ZAN Gateway                                      | VFMS                                                        |
| ----------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------ | ----------------------------------------------------------- |
| 1. Guest books on Booking.com | Channel manager delivers the reservation. Thedream creates it with the channel reference, payment collector and amounts, and sends availability out | `New` / `Confirmed Reservation` within 1 minute  | none                                                        |
| 2. Guest changes the booking  | Reservation updated, with amendment history                                                                                                         | `Modify` / `Confirmed Reservation`               | none                                                        |
| 3a. Guest cancels             | Reservation cancelled. Any fee is a fiscal exception                                                                                                | `Cancel` / `Cancel` with `CancelDate`            | none                                                        |
| 3b. Guest does not arrive     | Night audit proposes a no-show; the manager confirms it. Any fee is a fiscal exception                                                              | `Cancel` / `Cancel`, guest count 0, `CancelDate` | none                                                        |
| 4. Guest arrives              | Identity documents captured for every guest, room assigned, checked in                                                                              | `Modify` / `Checked In`, immediately             | Accommodation request (`DIRECT`, first night's rate)        |
| 5. During the stay            | Room moves, extras, rate changes at night audit                                                                                                     | `Modify` on changes to dates, guests or room     | Change room, add guest, services, change room price         |
| 6. Guest departs              | Folio settled against the channel-collected amount and VFMS taxes                                                                                   | `Modify` / `Checked Out`, immediately            | Check-out call; fiscal check-out report with QR code issued |

**If the channel connection fails**, Booking.com may email the reservation to the property after 30 minutes without acknowledgement ([Reservations overview](https://developers.booking.com/connectivity/docs/reservations-api/reservations-overview)).

- Staff enter it as a **booking received outside integration** (PMS-07). Thedream reports it from that moment.
- The outage is logged (DST-08), and the daily reconciliation confirms nothing was missed (DST-07).

## 9. Channel manager selection criteria

Choosing the channel manager is a launch dependency for Zanzibar (ADR 0001). A candidate must meet all of these:

1. **A PMS-facing API.** Thedream sends availability, rates and restrictions, and receives new, modified and cancelled reservations.
2. **Fast reservation delivery.** Push or near-real-time pull, so Thedream can report within ZRA's 5-minute window with room to spare.
3. **Certified Booking.com connection**, plus the other OTAs Zanzibar hotels sell on.
4. **Card handling without Thedream receiving card data** (ADR 0004): secure card storage, plus a way for the property to view or charge cards.
5. **Payment details on each reservation:** who collected payment, the amounts collected, and any fees the channel took.
6. **An API to list a property's reservations**, so the daily reconciliation (DST-07) can work.
7. **Commercial terms that work per property**, for pricing Zanzibar plans (§13).
8. **Not itself in conflict with ZRA.** The channel manager also falls under ZRA's integration rules (ZAN GW guide §2). Its reporting must not duplicate or contradict Thedream's.

Booking.com's direct connectivity programme should be monitored, so a direct connection can be considered if it reopens (P2).

## 10. Nonfunctional requirements

These are proposed release targets that need engineering validation.

| Area                   | Requirement                                                                                                                                                                                               |
| ---------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Isolation              | Automated adversarial tests across at least two organizations with two properties each, covering reads, writes, search, jobs, exports, files, notifications, reports and integration queues               |
| Identity               | MFA for platform admins, compliance operators and owners. Secure password reset, invitation expiry, session revocation and rate limits                                                                    |
| Audit                  | Record actor, organization, property, action, timestamp, result and before/after metadata for sensitive changes, excluding secrets and unnecessary identity data                                          |
| Performance            | Core server requests p95 under 800 ms. Staff pages usable within 3 seconds under the pilot workload, excluding external providers                                                                         |
| ZRA reporting latency  | Booking reports p95 within 1 minute of the event. Zero reports later than 5 minutes outside a logged ZRA outage                                                                                           |
| Integration durability | Booking reports, VFMS calls and channel messages are stored before acknowledgement, delivered at least once, and handled idempotently. Nothing is lost on restart                                         |
| Capacity               | Initial qualification workload: 100 organizations, 500 properties, 50,000 rooms and 1,000 concurrent staff sessions, plus booking-report throughput under the 100-per-minute platform limit at pilot peak |
| Availability           | 99.9% monthly for core PMS. Integrations measured separately                                                                                                                                              |
| Recovery               | Recovery point within 15 minutes and restoration within 4 hours, proven by a restore exercise. Delivery queues survive a restore without loss or duplicate reports                                        |
| Secrets                | The ZRA supplier key and VFMS credentials are kept in a secrets store, rotatable without downtime, and never logged                                                                                       |
| Privacy                | Minimum necessary guest data. Identity documents visible only to permitted roles. Retention set per jurisdiction before onboarding paying customers                                                       |
| Usability              | Responsive desktop and tablet operation, mobile housekeeping, keyboard-accessible critical workflows                                                                                                      |
| Observability          | Tenant-aware monitoring of errors, queues, channel health, ZAN Gateway and VFMS delivery, with redacted logs and fair-use limits per organization                                                         |

## 11. Conceptual data model

| Entity                                                | Purpose and boundary                                                                                                                                                |
| ----------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Organization                                          | Tenant, business owner, lifecycle, subscription                                                                                                                     |
| Property                                              | Belongs to exactly one organization. Holds its jurisdiction profile, ZRA hotel code and VFMS business unit                                                          |
| User; OrganizationMembership; PropertyMembership      | Login identity; role, scope and invitation history                                                                                                                  |
| Plan; Subscription; SaaSInvoice                       | Platform entitlements and billing, separate from guest accounts                                                                                                     |
| RoomType; Room; RatePlan; Restriction; InventoryBlock | Property inventory, pricing and restrictions                                                                                                                        |
| Guest; Booker; Reservation; Stay                      | The guest and booking lifecycle. A reservation carries its reservation number, channel, channel reference and payment collector. Identity documents belong to Guest |
| Folio; Charge; Payment; Adjustment                    | Hotel transactions per currency, including channel-collected payments                                                                                               |
| ChannelConnection; ChannelMapping; ChannelMessage     | Channel-manager connection per property, identifier mappings, and the inbound and outbound message log                                                              |
| BookingReport; ReportDelivery                         | ZAN Gateway reports and delivery attempts per reservation event                                                                                                     |
| FiscalTransaction                                     | VFMS calls and responses: reference, response and receipt numbers                                                                                                   |
| FiscalException                                       | Charges with no VFMS path                                                                                                                                           |
| ComplianceIncident                                    | Late reports, outages and bookings received outside integration                                                                                                     |
| AuditEvent; ImportJob; ExportJob                      | Traceability and data movement                                                                                                                                      |
| PlatformSecret                                        | The ZRA supplier key and other platform-level credentials. Not owned by any organization                                                                            |

All relationships must prevent links across organizations, including a reservation pointing at another tenant's guest, room or channel mapping.

## 12. Property go-live (replaces migration)

Thedream is built new, so there is no data migration from a previous Thedream system. A property goes live as follows:

1. Complete onboarding (§7.1) and choose the jurisdiction profile.
2. Configure rooms, rate plans, taxes and currencies.
3. **Zanzibar only:**
    1. Declare every channel.
    2. Connect each channel through the channel manager and map room types and rate plans.
    3. Register the property with ZAN Gateway (hotel info) and VFMS.
    4. Run a test reservation through every declared channel.
    5. Run a test VFMS check-in and check-out.
4. Import future reservations from the previous system, with a preview first and errors shown per row. **Zanzibar:** imported reservations are reported to ZAN Gateway as new on go-live.
5. Stop selling in the previous system, then switch channels to Thedream. Run the first daily reconciliation on the following day.

Imports require templates, a dry run, duplicate detection, errors per row, and processing that is safe to repeat.

## 13. Commercial packaging

| Plan               | Customer                               | Includes                                                                                                                                                   |
| ------------------ | -------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Essential          | Independent property                   | Core PMS, staff roles, housekeeping, basic reports, onboarding. **Zanzibar properties:** the full Zanzibar compliance pack, including channel connectivity |
| Growth             | Properties needing more sales channels | Essential, plus a direct booking page, OTA connectivity for mainland properties, payment options and richer reports                                        |
| Group / Enterprise | Multi-property operators and chains    | Multiple properties, scoped group roles, consolidated reporting, onboarding support, negotiated capacity and service terms                                 |

The channel manager's per-property cost is either built into Zanzibar property price bands or shown as a disclosed add-on. Legal compliance is never sold as an upgrade. Exact prices, trial length and discounts need commercial validation. No uncertified integration may be advertised as available.

## 14. Delivery sequence and release gates

| Milestone                    | Scope                                                                                                                     | Exit criteria                                                                                                                                                            |
| ---------------------------- | ------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| M0: Validate                 | Pilot hotel interviews, workflow mapping, **channel manager selection**, **ZRA vendor registration** and VFMS test access | Pilot cohort agreed, channel manager contracted, ZRA staging key and VFMS test credentials obtained                                                                      |
| M1: Tenant foundation        | Organizations, memberships, property context, authorization, audit, integration queue foundation                          | Isolation suite passes                                                                                                                                                   |
| M2: Core PMS                 | Rooms, rates, reservations, check-in and check-out, folios, housekeeping, night audit, onboarding, subscriptions          | P0 PMS and finance acceptance criteria pass                                                                                                                              |
| M3: Zanzibar compliance pack | Channel connectivity, ZAN Gateway reporting, VFMS receipting, compliance dashboard, reconciliation                        | Booking.com end-to-end lifecycle (§8) passes in staging. Latency, retry and outage tests pass                                                                            |
| M4: Pilot                    | Live pilot properties                                                                                                     | All P0 acceptance criteria pass. No unresolved critical security or data defects. Restore exercise passes. A Zanzibar pilot property completes full cycles in production |
| M5: Expansion                | P1 scope                                                                                                                  | Each provider passes contract and failure-recovery tests                                                                                                                 |

**Pilot cohort:** two independent hotels, one small multi-property group, and selected properties from one larger chain. At least one pilot property must be **in Zanzibar and selling on Booking.com through the channel manager**.

**Planning assumption:** a dedicated product, design and QA function and 3–5 engineers. Building new, plus the Zanzibar pack, needs a fresh estimate after M0. No delivery date is committed by this PRD.

## 15. Success measures

| Metric                         | Proposed pilot target                                                                                                  |
| ------------------------------ | ---------------------------------------------------------------------------------------------------------------------- |
| Activation                     | At least 70% of verified pilot organizations configure inventory, invite staff and create a real booking within 7 days |
| Onboarding time                | Median under 60 minutes to a configured first property, excluding channel and ZRA approval waits                       |
| Tenant isolation               | Zero exposure across tenants                                                                                           |
| Booking integrity              | Zero duplicate confirmations in concurrency tests. Channel conflicts tracked separately                                |
| Financial integrity            | Zero duplicate postings in retry and replay tests                                                                      |
| **ZRA reporting timeliness**   | ≥ 99% of booking reports delivered within 1 minute. Zero later than 5 minutes outside logged ZRA outages               |
| **ZRA reporting completeness** | 100% of reservations at Zanzibar pilot properties have an accepted booking report for each event                       |
| **Fiscal completeness**        | 100% of Zanzibar checkouts have a fiscal check-out report. Every fiscal exception is reviewed within 7 days            |
| **Channel reconciliation**     | Zero unresolved reconciliation items older than 2 business days                                                        |
| Operational adoption           | At least 80% of pilot properties use core operations on 5 or more days a week                                          |

Analytics events never contain identity documents, card data or full reservation payloads.

## 16. Risks

| Risk                                                                                                                | Mitigation                                                                                                                          | Owner                               |
| ------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------- |
| No suitable channel manager meets §9, especially fast delivery and card handling                                    | Shortlist and test in M0. Zanzibar go-live depends on it                                                                            | Partnerships and engineering        |
| ZRA reads its documents differently from §17 (for example the `DIRECT` treatment of OTA stays or no-show reporting) | Keep every interpretation in one place (§17), so payload rules can change by configuration. Monitor ZRA publications                | Product and compliance              |
| The shared ZRA rate limit is exhausted at scale                                                                     | Batch per property, monitor headroom, request a higher limit before it's reached                                                    | Engineering                         |
| Channel or ZRA outages cause late or missing reports                                                                | Durable queues, outage incidents, outside-integration entry with reasons, daily reconciliation                                      | Engineering and compliance operator |
| Credentials in the ZRA PDFs are misused or copied                                                                   | Treat the PDFs as confidential and never reuse their credentials. Report the exposed key to its owner                               | Platform administrator              |
| Guest identity data increases privacy exposure                                                                      | Restricted roles, masking, retention policy, audit                                                                                  | Product and advisers                |
| Hosting guest identity data outside Tanzania breaches the Personal Data Protection Act 2022                         | Legal review of the cross-border transfer rules. **Go-live gate:** resolved before the first real guest record is stored (ADR 0011) | Product and legal adviser           |
| Mainland hotels expect fiscal receipts                                                                              | Explicit onboarding message. A separate TRA PRD                                                                                     | Product                             |
| Building new plus the Zanzibar pack stretches P0                                                                    | Outlets and events moved to P1. Re-estimate after M0                                                                                | Product lead                        |

## 17. Interpretations of ZRA documents

Thedream relies on its reading of the ZRA documents and has not asked ZRA to confirm. Each interpretation below is implemented as configuration where practical.

| #    | Topic                         | Interpretation                                                                   | Basis                                                                      |
| ---- | ----------------------------- | -------------------------------------------------------------------------------- | -------------------------------------------------------------------------- |
| I-1  | VFMS source for OTA stays     | `DIRECT`: the guest is payer, full tax-inclusive price, no commission (ADR 0005) | VFMS guide §2.1. Booking.com guest-invoice guidance                        |
| I-2  | No-show                       | ZAN `Cancel`/`Cancel`, guest count 0, `CancelDate` = no-show date                | ZAN OpenAPI: "explicit 0 allowed for cancel/no-show"                       |
| I-3  | Channel field                 | Not sent. Neither ZRA document defines one                                       | ZAN OpenAPI schema                                                         |
| I-4  | `guest_country`               | Sent, as an ISO code                                                             | ZAN GW guide §7 example                                                    |
| I-5  | Rate limit                    | Design for 100 per minute for the whole platform                                 | ZAN GW guide §9 (stricter than the spec's 500)                             |
| I-6  | 5-minute window               | Measured from the event in Thedream, with a 1-minute target                      | ZAN GW guide §4 Step 4                                                     |
| I-7  | Varying nightly rates         | First night at check-in, then a change-room-price call when the rate changes     | VFMS guide §2.1, §2.3                                                      |
| I-8  | Cancellation and no-show fees | Non-fiscal receipt, plus the fiscal exceptions report                            | VFMS guide: no endpoint for a guest who never checked in                   |
| I-9  | Historical data               | Future reservations only. Completed stays stay with the previous supplier        | ZAN GW guide §4 Step 3, read as applying to the supplier's own bookings    |
| I-10 | Corrections                   | Re-send the full current state for the same reservation number                   | ZAN OpenAPI: identity is hotel code + reservation number; ZAN GW guide §11 |

## 18. Definition of done for the first paid release

- The Personal Data Protection Act 2022 cross-border question is resolved for the hosting region, before the first real guest record is stored.
- An independent hotel and a multi-property organization can register, configure properties, invite staff, run stays and manage their subscription.
- Role and tenant isolation tests cover every delivered surface. No critical or high-severity access-control defects remain.
- A Zanzibar pilot property has run real Booking.com reservations through the full lifecycle (§8), with:
    - every booking report accepted within target;
    - every checkout fiscally receipted;
    - every fiscal exception reviewed.
- Imports reconcile, financial corrections are traceable, and portfolio reports reconcile by property and currency.
- Recovery, monitoring, incident response, onboarding guidance and support ownership are demonstrated, including for ZRA delivery queues.
- Disabled or unapproved integrations stay visibly unavailable. No marketing claim goes beyond tested functionality.
- The product owner, engineering, QA and pilot hotel representatives approve the release evidence.

This document specifies a proposed product. It does not enable integrations, purchase services or claim regulatory certification.
