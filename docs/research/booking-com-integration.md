# Booking.com integration: research for Karafuu PRD v1.1

Prepared 6 October 2026 for the Karafuu product team. Web sources were retrieved on 6 October 2026.

**How to read this document**

- Each claim is cited inline to a URL, or to a local PDF with its section and page.
- "**Inference:**" marks the author's reasoning. It is not a sourced fact.
- "**Unverified:**" marks something no primary source could confirm. These gaps are left open, not filled with assumptions.

**Local PDFs, short names used below**

- `PRD.pdf`: Karafuu PRD v1.0.
- `ZAN GW guide`: "Vendor-Level PMS Integration Guide (Onboarding) ZAN GATEWAY - Novacom.pdf", ZRA, Version 1.3.
- `VFMS API`: "VFMS HOTEL API V1.6 (6060).pdf", ZRA, Version 1.6, Test Environment. Page numbers are the document's printed page numbers.

> **Security note:** Two local PDFs contain live-looking credentials. The ZAN GW guide prints an API key labelled "Novacom key" with an `sk_live` prefix (§5, p.5). The VFMS API prints test Integration IDs and tokens ("Note brief", p.33). This document does not reproduce them. Both PDFs should be treated as secrets, and the ZAN key should be reported to ZRA/Novacom for rotation. The guide was issued to another vendor (Novacom). Karafuu needs its own key through vendor registration (ZAN GW guide §4 Step 1, p.3).

---

## Summary

The findings below matter most for PRD decisions.

1. **Booking.com is not accepting new connectivity partners.** connect.booking.com states: "we are pausing integrations with new connectivity providers until further notice" ([connect.booking.com](https://connect.booking.com/)). A direct Booking.com connection therefore has no available start date. For launch, the practical route is a certified channel manager, or a "PMS with direct OTA integrations" bought in. The ZAN GW guide accepts both (§3.3, p.2).
2. **Under ZRA rules, OTA connectivity is a launch prerequisite in Zanzibar, not a P1 feature.** A PMS supplier "shall not onboard new properties in Zanzibar" unless all booking channels are integrated and transmitted (ZAN GW guide §3.5, p.3). Manual entry, delayed sync and spreadsheet imports of OTA bookings "do not meet compliance requirements" (§3.3, p.2). PRD v1.0 places distribution in P1 and allows "approved manual export workflows" (PRD §4 p.2, §7.11 LOC-03 p.8). That conflicts with the guide for Zanzibar properties.
3. **Using a channel manager does not move the ZRA obligation.** The guide lists channel managers and "systems providing connectivity to OTAs" as systems that must integrate (§2, p.1). It still makes the PMS supplier responsible for the completeness, accuracy and timeliness of booking data, "regardless of booking source or system configuration" (§3.4, p.3).
4. **ZRA timing is tight, and Booking.com's failure mode breaks it.** ZAN GATEWAY wants creations, modifications and cancellations within 5 minutes, and check-ins and check-outs "immediately" (§4 Step 4, p.4). If Booking.com reservations are not pulled or acknowledged within 30 minutes, Booking.com emails them to the property ([Reservations overview](https://developers.booking.com/connectivity/docs/reservations-api/reservations-overview)). Under ZRA rules, an emailed booking would then need manual entry, which is non-compliant (**inference**).
5. **The two ZRA systems do different jobs.** ZAN GATEWAY is booking-event reporting: a JSON POST of bookings (ZAN GW guide §5–7, pp.5–6). VFMS is fiscal receipting that starts at check-in and ends with a check-out report (VFMS API §2.1, §2.9). VFMS has **no cancellation endpoint and no OTA source value**: `accommodationSource` is only `DIRECT` or `TOUR OPERATOR` (VFMS API §2.1, p.4).
6. **Booking.com reservations lack the guest identity data that VFMS keys on.** VFMS identifies each guest by `documentName` and `documentNo`. Check-out, services, room change and profile change are all keyed on the document number (VFMS API §2.1–2.9). Booking.com reservation messages carry names, the booker's country of residence and an email address, but no ID document fields ([Retrieving new reservations](https://developers.booking.com/connectivity/docs/reservations-api/retrieving-new-reservations-ota)). Documents must be captured at check-in. That collides with PRD PMS-03, which says not to hard-code a passport requirement (PRD §7.4, p.5).
7. **Booking.com puts the receipt obligation on the hotel.** The hotel must invoice the guest, in the guest's name, for the total reservation amount. This applies even when Payments by Booking.com collects the money, and even when the virtual card shows "Booking.com (Agent)" ([Sending guest invoices](https://partner.booking.com/en-us/help/payments-payouts-invoices/guest-payments/sending-guest-invoices)). Commission is a separate Booking.com-to-hotel invoice: monthly, or deducted from bank-transfer payouts ([Understanding our commission](https://partner.booking.com/en-us/help/commission-invoices-tax/commission/understanding-our-commission)). **Unverified:** whether ZRA accepts this treatment, gross amount with the guest as payer under `DIRECT`, for OTA prepaid bookings.
8. **Payments by Booking.com will collect per-person fees in Tanzania.** From 15 June 2026 it collects fees such as city tax online in Tanzania ([Payments API overview](https://developers.booking.com/connectivity/docs/payments-api/understanding-the-payments-api)). VFMS computes an "infrastructure tax" per guest per night (VFMS API §2.1, p.3). **Inference:** the PMS must know what Booking.com already collected, so that fees are neither double-charged nor mis-receipted.
9. **PCI scope applies.** Booking.com requires "PCI & PII Compliance" plus certification for the Reservations API ([Going Live](https://developers.booking.com/connectivity/docs/going_live)). Hotel-collect reservations can carry full card numbers and CVCs ([Retrieving new reservations](https://developers.booking.com/connectivity/docs/reservations-api/retrieving-new-reservations-ota)). PRD FIN-04 says not to store raw card details (PRD §7.6, p.6). Booking.com lets a provider choose "display all information on the extranet" instead of sending card data by XML ([Payment data FAQ](https://developers.booking.com/connectivity/docs/con-faq-reservations-handling-payment-related-data)).
10. **One ZAN GATEWAY key is shared across all tenants.** ZRA issues one API key per PMS supplier (ZAN GW guide §5 p.5). The supplier is derived from the key, not the request (ZAN OpenAPI, `/hotel-info`). The rate limit is stated two ways: 100 requests per minute with higher limits "upon request" (ZAN GW guide §9 p.7), and "default 500/minute per API key" (ZAN OpenAPI, 429 response). Each request carries one `hotel_code` and a `bookings[]` array, and may be gzip or brotli compressed (ZAN OpenAPI). **Inference:** Karafuu needs a central delivery queue that batches by property and respects the rate limit. Separate calls per tenant would not fit the limit.
11. **The ZAN GATEWAY spec closes the event-field gap but not the channel gap.** Events use `Status` (`New`/`Modify`/`Cancel`) together with `CurrentStatus` (`Confirmed Reservation`/`Arrived`/`Checked In`/`Checked Out`/`Cancel`/`Void`). A booking's identity is `hotel_code` plus the reservation number, so each push upserts that record. The spec has **no booking-source or channel field** and no no-show status (ZAN OpenAPI, `PmsZanzibarPushBooking`). See "ZAN GATEWAY OpenAPI spec" in §5.

---

## 1. Connectivity Partner Programme

### What PRD v1.0 already says

- The current code has "OTA, ZAN Gateway, VFMS, credentials, and delivery scaffolding". External integrations are disabled by default, and the code "is not proof of live connectivity or certification" (PRD §3, p.1).
- P1 includes "a certified distribution connection" and "local reporting integrations where approved" (PRD §4, p.2).
- DST-02: "Integrate one selected channel manager or approved OTA connection first". It requires explicit hotel/room/rate mapping, modifications and cancellations, delivery status, retries and reconciliation. DST-03 makes certification and provider agreements release dependencies (PRD §7.9, p.7).
- M4 Expansion covers "selected certified integrations" (PRD §12, p.11). The risk "OTA/payment/fiscal access is unavailable" is assigned to Partnerships and Engineering (PRD §14, p.12).

### Is the programme open?

**No.** The Booking.com Connectivity Portal shows:

> "Thank you for your interest in a connectivity partnership with Booking.com. In an effort to ensure our teams are able to provide the strong partnership experience, we are pausing integrations with new connectivity providers until further notice."

Source: [connect.booking.com](https://connect.booking.com/).

The same page says Booking.com does not accept direct connections from individual properties. They must connect through a channel manager ([connect.booking.com](https://connect.booking.com/)). **Unverified:** any reopening date. No primary source gives one.

### Requirements

- **Onboarding requirements.** "To become Booking.com Connectivity Partners, companies must meet specific onboarding requirements" ([About the Connectivity APIs](https://developers.booking.com/connectivity/docs)). These link to the Connectivity Hub at `portal.connectivity.booking.com`, which could not be read: its TLS certificate chain failed verification from this environment. **Unverified:** the content of those requirements.
- **Compliance.** connect.booking.com says PCI and PII compliance is reviewed "based on regulations from the European Union and the PCI Security Standards Council" ([connect.booking.com](https://connect.booking.com/)). The Going Live page lists the compliance needed for each API. The Reservations API needs PCI and PII compliance. The Content, Contacts, Messaging, Property Details, Guest Review, Opportunities and Reporting APIs need PII compliance. The Payments API needs PCI compliance ([Going Live](https://developers.booking.com/connectivity/docs/going_live)).
- **Ongoing expectations.** Partners are expected to:
    - support all available API functions;
    - load at least a full year of rates and availability;
    - provide an easy room and rate mapping interface;
    - minimise reservations falling back to email;
    - keep up with continuous API changes.

    Source: [About the Connectivity APIs](https://developers.booking.com/connectivity/docs).

- **Minimum property counts or volume gates.** **Unverified.** None of the pages reviewed states a minimum. The Booking.com programme criteria page on `connectivity.booking.com` could not be read (same certificate failure).
- **Tiers.** connect.booking.com describes a points-based "Partner Programme" ([connect.booking.com](https://connect.booking.com/)). **Unverified:** the tier names and criteria. Search results cite "Standard / Advanced / Premier" with new names for 2026, but the partner.booking.com articles behind them now redirect to an unrelated page ([Setting up and working with a Connectivity provider](https://partner.booking.com/en-gb/help/registration-getting-started/tools/setting-and-working-connectivity-provider)).

### Application and certification process, as documented for existing partners

1. **Portal access.** Booking.com "typically shares access to the Connectivity Portal during the onboarding process". Machine accounts are created there ([Authentication](https://developers.booking.com/connectivity/docs/authentication)).
2. **Test environment.** For the Content API: "you first need to go through a certification process to learn and test how to update rates, availability and reservations… you'll get access to a test account… you can start another certification process to activate your production account" ([Content API overview](https://developers.booking.com/connectivity/docs/content)).
3. **Certification versus self-assessment.**
    - Certification means "running several test requests on certain API endpoint(s)". Connectivity Support shares the steps and evaluates the requests and responses on test properties.
    - Self-assessment follows a published tutorial. Support may ask for RUIDs (request IDs) and screenshots.

    Source: [Going Live](https://developers.booking.com/connectivity/docs/going_live).

4. **Beta rollout.** Booking.com advises offering a newly live API "to a small beta group for the first few weeks" ([Going Live](https://developers.booking.com/connectivity/docs/going_live)).
5. **Property connection.** A property selects the provider in the Extranet and accepts terms. The provider then maps rooms and rates and completes the connection ([Setting up and working with a Connectivity provider](https://partner.booking.com/en-us/help/first-steps/online-bookable/setting-and-working-connectivity-provider)).

**Timelines:** **Unverified.** Booking.com publishes no duration for onboarding or certification.

### What certification requires, by API

All from [Going Live](https://developers.booking.com/connectivity/docs/going_live).

| API                                                                              | Technical gate                                                                               | Compliance gate                    |
| -------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------- | ---------------------------------- |
| Reservations                                                                     | Certification                                                                                | PCI and PII                        |
| Rates & Availability                                                             | Certification, tailored to pricing model                                                     | —                                  |
| Content API                                                                      | Certification. Full Content API also requires Contracting, Licences and Photo APIs           | PII                                |
| Room type and rate plan management                                               | Certification                                                                                | —                                  |
| Rooms, Property Details, Property Settings, Property Status, Facilities, Charges | Certification, or self-assessment if already Content-certified                               | PII for Property Details           |
| Photo, Promotions, Reporting, Guest Review, Request to Book                      | Self-assessment                                                                              | PII for Reporting and Guest Review |
| Payments API                                                                     | Self-assessment. Only for Payments by Booking.com properties already on the Reservations API | PCI                                |
| Payments by Booking.com Onboarding, Connections, Licences, Credit Card Status    | Self-assessment, no tutorial                                                                 | —                                  |

Pricing-model rules:

- **OBP and LOS pricing** need certification. Derived pricing needs self-assessment ([Going Live](https://developers.booking.com/connectivity/docs/going_live)).
- **Conflicting statement:** the Rates & Availability overview says "All pricing types need certification" in its text, but marks Standard and Derived as "No" in its table ([Rates & Availability API overview](https://developers.booking.com/connectivity/docs/ari)).
- The "mandatory functionalities and restrictions" for Rates & Availability and Reservations are kept in a Connectivity Hub article ([ARI FAQ](https://developers.booking.com/connectivity/docs/con-faq-rates-availability)). **Unverified:** that article's contents, for the same certificate reason.

---

## 2. APIs a PMS needs

### Cross-cutting facts

- **Base URLs.** `https://supply-xml.booking.com` serves non-PCI calls. `https://secure-supply-xml.booking.com` is the PCI host for reservation retrieval and confirmation. HTTPS with TLS 1.2 is required, and bodies must be UTF-8 ([About the Connectivity APIs](https://developers.booking.com/connectivity/docs)).
- **Formats.** OTA XML (OTA 2003B), B.XML (Booking.com's own XML) and JSON. "In most cases… both", but "do not mix OTA and B.XML requests" within one process ([About the Connectivity APIs](https://developers.booking.com/connectivity/docs)).
- **Authentication.**
    - Machine accounts exchange credentials for a JWT at `POST https://connectivity-authentication.booking.com/token-based-authentication/exchange`.
    - "The token expires after one hour. You can create up to 30 tokens per hour" ([Token-based authentication](https://developers.booking.com/connectivity/docs/token-based-authentication)).
    - Credential-based (basic) authentication was sunset on 31 December 2025 ([Authentication](https://developers.booking.com/connectivity/docs/authentication)).
    - Access requires all of the following: the property is linked to the machine account; the property has the right connection type; the endpoint is enabled; and any certification is passed ([Managing endpoint access](https://developers.booking.com/connectivity/docs/managing-endpoint-access)).
- **Rate limits.** Exceeding a limit returns HTTP 429. The published limits are:
    - all endpoints: 10,000 calls per minute;
    - `/xml/reservationssummary`: 700 per minute;
    - `OTA_HotelDescriptiveContentNotif`, `OTA_HotelInvNotif`, `OTA_HotelProductNotif` and `OTA_HotelSummaryNotif`: 75 per minute each;
    - `OTA_HotelDescriptiveInfo`: 100 per minute;
    - Photos `POST pending_photos`: 100 per minute.

    Booking.com "reserves the right to… change these limits at any time… and… impose other limits on specific accounts" ([About the Connectivity APIs, Rate limiting](https://developers.booking.com/connectivity/docs)).

- **Connection types a provider can offer** to properties: Reservations; Rates & Availability; Guest reviews; Reporting; Content; Photos; Guest messages; Performance data; Payments by Booking.com onboarding; Promotions; Facilities; Property settings ([About the Connectivity APIs](https://developers.booking.com/connectivity/docs)).

### Reservations API: OTA XML versus B.XML

All endpoints are **pull** (the PMS polls). There is no push for reservation content. The Notification Service sends alerts only, not reservation data (see the table below).

|                                 | OTA XML                                                                                               | B.XML                                                                                                                      |
| ------------------------------- | ----------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------- |
| New bookings                    | `GET https://secure-supply-xml.booking.com/hotels/ota/OTA_HotelResNotif`                              | `POST https://secure-supply-xml.booking.com/hotels/xml/reservations` (all types)                                           |
| Modifications and cancellations | `GET …/OTA_HotelResModifyNotif`                                                                       | Same endpoint, with `status` = `new` / `modified` / `cancelled`                                                            |
| Acknowledge                     | `POST OTA_HotelResNotif` and `POST OTA_HotelResModifyNotif`, with a Success element or error code 193 | **Not supported**: "Once the endpoint call completes, Booking.com assumes that the partner will be prepared for the guest" |
| Polling guidance                | Once per 20 seconds, and 5 seconds after an acknowledgement before the next GET                       | Once per 20 seconds                                                                                                        |

Sources: [Reservations overview](https://developers.booking.com/connectivity/docs/reservations-api/reservations-overview), [Retrieving new reservations](https://developers.booking.com/connectivity/docs/reservations-api/retrieving-new-reservations-ota), [Retrieving modified/cancelled](https://developers.booking.com/connectivity/docs/reservations-api/retrieving-modifcancel-reservations-ota), [Acknowledging modified/cancelled](https://developers.booking.com/connectivity/docs/reservations-api/acknowleding-modif-cancel-reservations-ota), [Managing reservations (B.XML)](https://developers.booking.com/connectivity/docs/reservations-api/managing-reservations-bxml).

**Delivery and failure rules**

- **Re-delivery until acknowledged.** "`GET OTA_HotelResNotif` will repetitively return bookings until they are acknowledged… avoid creating duplicate bookings" ([Reservations overview](https://developers.booking.com/connectivity/docs/reservations-api/reservations-overview)). PRD DST acceptance already requires idempotency (PRD §7.9, p.7).
- **Fallback to email.**
    - If a reservation is not acknowledged (OTA) or retrieved (B.XML) within 30 minutes, it is emailed to the property.
    - Support can extend the window to 24 hours, at the cost of more overbookings ([Reservations overview](https://developers.booking.com/connectivity/docs/reservations-api/reservations-overview)).
    - The Reservation Recovery API (off by default) also moves fallback to 24 hours, except for check-ins within 48 hours ([Reservation Recovery overview](https://developers.booking.com/connectivity/docs/reservations-flow-control-api/reservations-recovery-overview)).
    - Planned outage holds can last at most 8 hours ([General information FAQ](https://developers.booking.com/connectivity/docs/con-faq-reservations-general-information)).
- **Client settings.** Use a 5-minute HTTP timeout, parallel workers and about 40% capacity headroom ([Reservations overview](https://developers.booking.com/connectivity/docs/reservations-api/reservations-overview)).
- **Modification raced with cancellation.** With `ota_res_response_token` enabled, acknowledging may return 409. Two `ResID_Value`s must then be acknowledged ([OTA reservations process](https://developers.booking.com/connectivity/docs/reservations-api/reservations-process-ota)).
- **Modification after check-in.** Modifications can arrive after check-in, from the hotelier or Booking.com Customer Service ([General information FAQ](https://developers.booking.com/connectivity/docs/con-faq-reservations-general-information)).
- **Cancellations.**
    - The cancellation message includes any applicable cancellation fee, "although Booking.com won't charge the guest".
    - For Payments by Booking.com, refunds are automatic within 10 business days.
    - Cancelled rooms are added back to inventory automatically ([General information FAQ](https://developers.booking.com/connectivity/docs/con-faq-reservations-general-information)).
    - B.XML adds `total_cancellation_fee`, and `total_price` becomes 0 ([Managing reservations (B.XML)](https://developers.booking.com/connectivity/docs/reservations-api/managing-reservations-bxml)).
    - **Unverified:** the exact OTA element that marks a full cancellation. `HotelResModify/RoomStays` allows 0..* `RoomStay` ([RoomStays reference](https://developers.booking.com/connectivity/docs/api-reference/roomstays)). **Inference:** an empty `RoomStays` may signal a full cancellation. Confirm in the test environment.
- **No-shows** are reported by the PMS through the **Reporting API**, `POST https://supply-xml.booking.com/hotels/xml/reporting` with `report=is_no_show`. Fees can be waived, and room-level reporting is supported.
    - The window runs from 00:00 on the check-in date to 48 hours after check-out.
    - A no-show cancels the reservation, or modifies it for a partial no-show. It then "is no longer modifiable".
    - "We do not automatically update the room's availability" after a no-show.

    Source: [Reporting API](https://developers.booking.com/connectivity/docs/reporting-api/b_xml-reporting).

    Booking.com charges full commission if changes are not marked within 48 hours of check-out ([Understanding our commission](https://partner.booking.com/en-us/help/commission-invoices-tax/commission/understanding-our-commission)).

- **Backfill.** `/reservationssummary` returns basic details of reservations made before connection. `last_change` recovery reaches back only 2 weeks ([Reservations overview](https://developers.booking.com/connectivity/docs/reservations-api/reservations-overview), [Retrieving new reservations](https://developers.booking.com/connectivity/docs/reservations-api/retrieving-new-reservations-ota)).
- **Card data.** Full card details are sent "in the first pull only". A provider can choose XML with CVC, XML without CVC, or "display all information on the extranet" ([Payment data FAQ](https://developers.booking.com/connectivity/docs/con-faq-reservations-handling-payment-related-data)).
- **Telephone.** The booker's telephone field "is omitted… now is unavailable for all Property IDs" ([Retrieving new reservations](https://developers.booking.com/connectivity/docs/reservations-api/retrieving-new-reservations-ota)).

### Other APIs

| API                                    | Protocol and endpoint                                                                                                                                                                                 | Direction                   | Notes                                                                                                                                                                                                                                                                                                                                                                                        |
| -------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Rates & Availability (ARI)**         | OTA: `OTA_HotelAvailNotif` (inventory and restrictions), `OTA_HotelRateAmountNotif` (rates). B.XML: `/xml/availability` (inventory, restrictions and rates)                                           | Push (PMS → Booking.com)    | Pricing types: Standard, Derived (RLO), OBP, LOS. Prices outside €5–€50,000 are rejected ([Rates & Availability API overview](https://developers.booking.com/connectivity/docs/ari), [ARI FAQ](https://developers.booking.com/connectivity/docs/con-faq-rates-availability), [Foundational solutions](https://developers.booking.com/connectivity/docs/solutions/foundational/foundational)) |
| **Room type and rate plan management** | OTA `OTA_HotelProductNotif` and B.XML `/xml/roomrates`                                                                                                                                                | Push and read               | Lighter alternative to the full Content API, but needs certification ([Going Live](https://developers.booking.com/connectivity/docs/going_live))                                                                                                                                                                                                                                             |
| **Content / Property**                 | Legacy `OTA_HotelDescriptiveContentNotif` (HDCN) is deprecated and **sunsets 31 December 2026**. Replaced by modular JSON APIs: Property, Rooms, Facilities, Policies, Licences, Contracting, Charges | Push                        | "Full Content API" requires the Contracting, Licences and Photo APIs ([Implementing or disabling CVC collection](https://developers.booking.com/connectivity/docs/collecting-cvc), [Going Live](https://developers.booking.com/connectivity/docs/going_live)). **Inference:** do not build on HDCN                                                                                           |
| **Photos**                             | REST/JSON Photo API                                                                                                                                                                                   | Push                        | Up to 299 photos per property. Rate limit 100 per minute on `pending_photos` ([Using the Photo API](https://developers.booking.com/connectivity/docs/photo-api/understanding-the-photo-api), [Rate limiting](https://developers.booking.com/connectivity/docs))                                                                                                                              |
| **Promotions**                         | B.XML `/promotions`, `/getpromotions`                                                                                                                                                                 | Push and read               | Percentage discounts only. Needs machine-account permissions from Support ([Understanding promotions](https://developers.booking.com/connectivity/docs/promotions))                                                                                                                                                                                                                          |
| **Reporting**                          | B.XML `/xml/reporting`                                                                                                                                                                                | Push                        | Invalid card, stay change, no-show, cancellation for invalid card, guest misconduct ([Reporting API](https://developers.booking.com/connectivity/docs/reporting-api/b_xml-reporting))                                                                                                                                                                                                        |
| **Payments API**                       | `https://payments-api.booking.com`                                                                                                                                                                    | Pull, plus notifications    | Payments by Booking.com reservations only. Payout status, VCC balances, price breakdown, 18 months of history ([Understanding the Payments API](https://developers.booking.com/connectivity/docs/payments-api/understanding-the-payments-api))                                                                                                                                               |
| **Payments Clarity Package v2**        | Feature flag on the Reservations API (`payment_clarity_package_v2`)                                                                                                                                   | Within the reservation pull | Guest-view and hotel-view price breakdown, withheld tax, VCC activation, balance and expiry ([Retrieving new reservations](https://developers.booking.com/connectivity/docs/reservations-api/retrieving-new-reservations-ota))                                                                                                                                                               |
| **Payments by Booking.com Onboarding** | JSON                                                                                                                                                                                                  | Push and read               | Fresh enrolment only ([Onboarding API](https://developers.booking.com/connectivity/docs/payments-by-booking-onboarding-api/understanding-the-pbb-onboarding-api))                                                                                                                                                                                                                            |
| **Notification Service**               | Provider-hosted endpoints                                                                                                                                                                             | Push (Booking.com → PMS)    | "Simple alerts and not… the detailed changes/data". The PMS must call the relevant API after each alert ([Notification Service](https://developers.booking.com/connectivity/docs/notification-service/notification-service-overview))                                                                                                                                                        |
| **Reservation Recovery**               | `/reservations-flow-control/missed-reservations`                                                                                                                                                      | Pull                        | Off by default ([Reservation Recovery overview](https://developers.booking.com/connectivity/docs/reservations-flow-control-api/reservations-recovery-overview))                                                                                                                                                                                                                              |
| **Others**                             | Messaging, Guest Review, Licences, Market Insights, Property Scores, Opportunities, Request to Book                                                                                                   | Various                     | Listed on [Going Live](https://developers.booking.com/connectivity/docs/going_live)                                                                                                                                                                                                                                                                                                          |

**Inference:** the minimum certifiable scope for a PMS is Reservations plus Rates & Availability, with Room/Rate management or the Content API for mapping. Booking.com's "Essentials" foundational solution is exactly Rates and Availability plus Reservations ([Foundational solutions](https://developers.booking.com/connectivity/docs/solutions/foundational/foundational)).

---

## 3. Channel-manager alternative

### What ZRA says about who must integrate

- **Systems required to integrate:**
    - PMS;
    - CRS;
    - booking engines;
    - **channel managers**;
    - reservation platforms;
    - "Systems providing connectivity to Online Travel Agents (OTAs)".

    Source: ZAN GW guide §2, p.1.

- **Acceptable OTA connectivity:** a "live, integrated system, such as: Channel manager, CRS, Booking engine, PMS with direct OTA integrations". OTA bookings must be "automatically synchronized into the PMS or connected system" (ZAN GW guide §3.3, p.2).
- **Responsibility stays with the PMS supplier.** PMS suppliers are responsible for completeness, accuracy and timeliness "regardless of booking source or system configuration" (ZAN GW guide §3.4, p.3).
- **Compliance portal requirements.** ZRA's compliance portal states that a PMS vendor must:
    1. register as a ZRA taxpayer (overseas vendors as a Non-Resident Business Entity);
    2. integrate with ZAN Gateway;
    3. obtain VFMS integration certification.

    A hotel is compliant only if its PMS vendor is compliant. The registry tracks "Does the property use a Channel Manager connected to a ZRA-approved PMS?", and the guidance tells hotels to "Connect your channel manager through your PMS".

    Source: [compliance.zanrevenue.org](https://compliance.zanrevenue.org/), FAQ and registry text as rendered by the site's JavaScript. The page states two deadlines that have both passed: "24 April 2026" in the FAQ and "Compliance Deadline: 18 May 2026" in the banner.

- **Conclusion (inference, from the text above):** choosing a channel manager does **not** move Karafuu's ZRA obligation. The channel manager may carry its own parallel obligation, but Karafuu still owns reporting for its properties.
- **Unverified:**
    - whether ZRA expects both systems to report the same booking, and how duplicates are reconciled;
    - whether any specific channel manager is currently registered with ZRA. The registry data could not be read.

### Options compared

|                                     | Direct Booking.com                                                                 | Channex                                                                                                                                                                                                                    | SiteMinder (pmsXchange)                                                                                                                                                                                                                                                                    | RateGain (RezGain, RG Bridge)                                                                                                                                                                                                                               | Cloudbeds                                                                                                                                                                                                                                          |
| ----------------------------------- | ---------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Availability now**                | Paused for new providers ([connect.booking.com](https://connect.booking.com/))     | Self-serve staging, "no sales call" ([Channex pricing](https://channex.io/pricing.md))                                                                                                                                     | Application, then a partnership agreement before integration starts ([Integration process](https://developer.siteminder.com/get-started/partner-lifecycle/integration-process.md))                                                                                                         | For "RateGain partner organizations" ([RG Bridge spec](https://developer.rategain.com/our-products/channel-manager/rg-bridge-supply-push/interface-specifications-reservation-notification-service.md))                                                     | Public developer portal lists PMS, Data Insights, Accounting and Distribution (OTA-side) APIs ([Cloudbeds developers](https://developers.cloudbeds.com/page/home)). **Unverified:** whether any channel-manager API is offered to third-party PMSs |
| **Published cost**                  | **Unverified** (none published)                                                    | $130 per month platform fee plus $7 per hotel per month (falling to $4 at 2,000+). Only properties with an active channel are billed. No minimums, no certification fee ([Channex pricing](https://channex.io/pricing.md)) | **Unverified** (not published on pages reviewed)                                                                                                                                                                                                                                           | **Unverified**                                                                                                                                                                                                                                              | **Unverified**                                                                                                                                                                                                                                     |
| **Time to market**                  | **Unverified**                                                                     | Integration "in hours" (vendor claim), plus Channex certification with a live screen-share, plus OTA account authorisation ([Channex llms.txt](https://channex.io/llms.txt))                                               | "Most integrations are completed within 60 days": 1–3 days initiation, 4–6 weeks development, 1 week certification ([Integration process](https://developer.siteminder.com/get-started/partner-lifecycle/integration-process.md))                                                          | **Unverified**                                                                                                                                                                                                                                              | n/a                                                                                                                                                                                                                                                |
| **Protocol**                        | OTA XML, B.XML, JSON                                                               | REST/JSON with API key ([Channex llms.txt](https://channex.io/llms.txt))                                                                                                                                                   | SOAP/XML (OTA) for reservations. REST for rooms and rates ([pmsXchange FAQ](https://developer.siteminder.com/pmsxchange-api/additional-resources/faq.md))                                                                                                                                  | PMS pushes ARI. The channel manager pushes reservations to an interface the PMS provides ([RG Bridge spec](https://developer.rategain.com/our-products/channel-manager/rg-bridge-supply-push/interface-specifications-reservation-notification-service.md)) | —                                                                                                                                                                                                                                                  |
| **Reservation delivery**            | Pull only, with a 30-minute email fallback                                         | Webhook push plus Booking Revisions feed pull and acknowledge. Also poll every 15–20 minutes as backup ([Channex llms.txt](https://channex.io/llms.txt))                                                                   | PUSH in real time (recommended) or PULL every 2–5 minutes. Must certify Rooms & Rates, Availability, and Reservations PUSH or PULL. Modifications and cancellations are only "Recommended" ([pmsXchange FAQ](https://developer.siteminder.com/pmsxchange-api/additional-resources/faq.md)) | Push to PMS                                                                                                                                                                                                                                                 | —                                                                                                                                                                                                                                                  |
| **Control**                         | Full access to Booking.com features (Reporting, Promotions, Payments API, Content) | Mediated by Channex. White-label, wholesale only ([Channex llms.txt](https://channex.io/llms.txt))                                                                                                                         | Mediated by SiteMinder                                                                                                                                                                                                                                                                     | Mediated by RateGain                                                                                                                                                                                                                                        | —                                                                                                                                                                                                                                                  |
| **PCI**                             | Karafuu needs PCI and PII compliance                                               | Channex "itself is not certified to PCI DSS". Card handling uses PCI-certified processors ([Channex llms.txt](https://channex.io/llms.txt))                                                                                | **Unverified**                                                                                                                                                                                                                                                                             | **Unverified**                                                                                                                                                                                                                                              | —                                                                                                                                                                                                                                                  |
| **Tanzania / East Africa presence** | n/a                                                                                | **Unverified** (no primary evidence found)                                                                                                                                                                                 | **Unverified** (no primary page found)                                                                                                                                                                                                                                                     | Not found                                                                                                                                                                                                                                                   | Not found                                                                                                                                                                                                                                          |

**Other notes**

- **Card data through SiteMinder.** SiteMinder's reservation schema can carry guest ID documents (`Document DocID`, `DocType`, `DocHolderNationality`) ([pmsXchange push](https://developer.siteminder.com/pmsxchange-api/reference/reservations/push.md)). **Inference:** a channel manager cannot supply ID data that Booking.com does not send. The VFMS document gap in §5 remains whichever route is chosen.
- **Commercial model.** **Inference:** with a channel manager, Booking.com's commission and payment models are unchanged. The channel manager is a pipe, and its fee adds to Booking.com's commission.
- **ZRA timing.**
    - **Inference:** SiteMinder PULL at a 5-minute interval could use the whole ZRA 5-minute budget on its own. PUSH or webhooks fit the ZRA timing better.
    - **Unverified:** each channel manager's end-to-end latency from Booking.com.

---

## 4. Payment models and fiscal receipts

### Booking.com facts

**Two models exist.**

- **Payments by Booking.com (PbB).** Guests pay through Booking.com. Payout is by bank transfer or virtual credit card (VCC), plus Stripe for US and Canada Homes only ([Understanding payouts](https://developers.booking.com/connectivity/docs/payments-by-booking-onboarding-api/understanding-payouts)).
- **Hotel-collect** (pay at property, or "agency"). The hotel collects from the guest and pays commission by invoice ([Payments by Booking.com guide](https://partner.booking.com/en-gb/help/policies-payments/payment-products/how-much-payments-bookingcom-costs)).

**VCC payouts**

- A per-booking temporary Mastercard holding "the exact amount that the guest paid" ([Understanding payouts](https://developers.booking.com/connectivity/docs/payments-by-booking-onboarding-api/understanding-payouts)).
- VCC payouts are "almost always gross". Commission is then invoiced, and the hotel pays its own card-acquiring fee ([Understanding payouts](https://developers.booking.com/connectivity/docs/payments-by-booking-onboarding-api/understanding-payouts), [PbB costs](https://partner.booking.com/en-gb/help/policies-payments/payment-products/how-much-payments-bookingcom-costs)).
- When the card can be charged:
    - non-refundable bookings: from the booking date;
    - other bookings: once at least 90% non-refundable;
    - new properties: from 1 day after check-in.
- The card can be charged for 12 months after check-out ([VCC guide](https://partner.booking.com/en-gb/help/policies-payments/payment-products/everything-you-need-know-about-virtual-credit-cards)).

**Bank-transfer payouts**

- Paid after check-out, monthly, weekly or daily. Can be net or gross ([Understanding payouts](https://developers.booking.com/connectivity/docs/payments-by-booking-onboarding-api/understanding-payouts)).
- A payment service fee applies, as a percentage that depends on location ([PbB costs](https://partner.booking.com/en-gb/help/policies-payments/payment-products/how-much-payments-bookingcom-costs)).
- Commission and the fee are deducted from payouts "if we manage all your guest payments". Otherwise they come in one monthly invoice ([PbB costs](https://partner.booking.com/en-gb/help/policies-payments/payment-products/how-much-payments-bookingcom-costs)).
- Switching from VCC to bank transfer is one-way ([Understanding payouts](https://developers.booking.com/connectivity/docs/payments-by-booking-onboarding-api/understanding-payouts)).

**Commission invoicing**

- Commission is a percentage of the total booking amount, including room rate and extra fees. Booking.com does not charge commission on local taxes such as city tax, but in most countries it does charge commission on VAT.
- One invoice per month covers guests who checked out in the previous month.
- Changes must be marked within 48 hours of check-out, or full commission is charged.

Source: [Understanding our commission](https://partner.booking.com/en-us/help/commission-invoices-tax/commission/understanding-our-commission).

- Invoices must be paid within 14 days. Unpaid invoices can close the listing ([Paying your invoices](https://partner.booking.com/en-us/help/payments-payouts-invoices/invoices/paying-your-invoices-bookingcom)).
- The reservation message carries commission per room stay (`CommissionPayableAmount`, "Is NULL if the business model… is `net_rate`") and optionally a reservation total (`include_total_commission`) ([Retrieving new reservations](https://developers.booking.com/connectivity/docs/reservations-api/retrieving-new-reservations-ota)).

**Currency**

- Reservation prices use a currency that "is always the same for a property and is set by Booking.com" ([Retrieving new reservations](https://developers.booking.com/connectivity/docs/reservations-api/retrieving-new-reservations-ota)).
- There are three settings: load currency, payout currency and invoice currency. The VCC payout currency is usually the load currency. The bank-transfer currency is usually the bank account's currency ([Currency settings](https://partner.booking.com/en-us/help/commission-invoices-tax/guest-payments/everything-you-need-know-about-currency-settings-and)).

**Tanzania specifically**

- From 15 June 2026, PbB "will facilitate the collection of per-person fees (such as city tax and tourism fees)" in Tanzania, among other countries. Properties "should not collect per-person fees again at check-in" ([Understanding the Payments API](https://developers.booking.com/connectivity/docs/payments-api/understanding-the-payments-api)).
- **Unverified:** whether PbB itself (VCC or bank transfer) is generally available to Tanzanian and Zanzibari properties. **Inference:** the per-person fee rollout implies at least some PbB coverage.
- The tax remittance markets, where Booking.com withholds and remits taxes, are listed as Canada, France, Malaysia and the US. Tanzania is not listed ([Understanding the Payments API](https://developers.booking.com/connectivity/docs/payments-api/understanding-the-payments-api)).

### Who issues the guest receipt, and for what

Booking.com's position is set out in [Sending guest invoices](https://partner.booking.com/en-us/help/payments-payouts-invoices/guest-payments/sending-guest-invoices):

- "you're responsible for issuing invoices to your guests – even when we facilitate the payment on your behalf".
- "your invoice should always reflect the total reservation amount… even if we facilitate the payment or deduct commission before paying you".
- With VCC payouts, "the cardholder will appear as 'Booking.com (Agent)' but you remain the supplier".
- "You must issue invoices in the guest's name". Booking.com should not be named as seller.

### Mapping to VFMS

| Question                          | Hotel-collect                                                                       | Payments by Booking.com (VCC or bank transfer)                                                       | Source / status                                                                                                                                                                                                             |
| --------------------------------- | ----------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Payer on the receipt**          | Guest                                                                               | Guest (Booking.com acts as agent)                                                                    | Booking.com guidance above                                                                                                                                                                                                  |
| **VFMS source**                   | `DIRECT`: "All charges will be paid by the guests themselves"                       | No OTA value exists. `TOUR OPERATOR` needs a ZRA tour-operator registration number (`TourRegNo`)     | VFMS API §2.1, p.4. **Unverified:** which value ZRA expects for an OTA booking, and whether Booking.com holds a ZRA tour-operator number                                                                                    |
| **Amount receipted**              | Total stay value at the tax-inclusive `accommodationPrice` per night, plus services | Booking.com says total reservation amount (gross), not the net payout                                | **Inference.** **Unverified:** ZRA's position on gross versus net for agent-collected bookings                                                                                                                              |
| **Commission**                    | Booking.com's invoice to the hotel. Not a guest receipt                             | Same, or deducted from bank-transfer payout                                                          | Booking.com guidance. **Unverified:** how ZRA treats it (for example, VAT on imported services)                                                                                                                             |
| **Currency**                      | Booking.com property currency                                                       | Payout may differ from load currency                                                                 | VFMS accepts only `USD`, `TZS`, `EURO`. Note `EURO`, not ISO `EUR` (VFMS API §2.1 p.3, §2.2 p.9). VFMS provides an exchange-rate endpoint (USD→TZS, EUR→TZS, USD→EUR) (§2.4, p.12). **Gap:** any other Booking.com currency |
| **Timing**                        | VFMS accommodation record at check-in. Fiscal check-out report at check-out         | Money may arrive before check-in (VCC on non-refundable bookings) or after check-out (bank transfer) | VFMS API §2.1 p.3, §2.9 p.21. The Advance Payment Booking endpoint (§2.10, p.24) is built for B2B tour-operator prepayments (`customerType` "constant 2… B2B"). **Unverified:** whether it applies to OTA prepayments       |
| **Cancellation and no-show fees** | Hotel charges the card                                                              | Booking.com collects on the hotel's behalf                                                           | VFMS has no cancellation or no-show endpoint. **Inference:** a fee with no stay may only fit the general-customer services type (`WS`) (§2.7, p.17). **Unverified** with ZRA                                                |

PRD v1.0 relevance:

- FIN-01: one folio per reservation and currency, with no silent conversion (PRD §7.6, p.6).
- FIN-02: separate recorded payments from "independently confirmed payment-provider settlement" (PRD §7.6, p.6). This maps naturally onto VCC charges and bank-transfer payouts.

---

## 5. ZRA reporting of OTA bookings

### ZAN GATEWAY: booking-event reporting

**Scope**

- All bookings regardless of source, including OTA bookings, named as "Booking.com, Expedia, Airbnb" (§3.2, p.2).
- Applies to every Zanzibar property on the system (§3.1, p.2).

**Onboarding steps**

1. **Vendor registration.** ZRA then issues the API base URL, key and documentation (§4 Step 1, p.3).
2. **Property registration.** Each property gives its name, "PMS hotel code" and location, and receives a ZRA property identifier (§4 Step 2, p.4).
3. **Historical import** of all bookings, modifications, cancellations, check-ins and check-outs "from 1 January 2025 onward", by API bulk push or CSV/JSON upload (§4 Step 3, p.4).
4. **Ongoing reporting**, on this timetable:

| Event             | Deadline         |
| ----------------- | ---------------- |
| Booking created   | Within 5 minutes |
| Booking modified  | Within 5 minutes |
| Booking cancelled | Within 5 minutes |
| Guest check-in    | "Immediately"    |
| Guest check-out   | "Immediately"    |

These timings apply "to all booking sources, including OTAs" (§4 Step 4, p.4).

**API**

- `POST https://zangw-staging.zanrevenue.org/api/v1/pms/zanzibar/bookings` (testing). Swagger documentation is listed at `zangw-staging.zanrevenue.org/api-docs` (§5, p.5).
- Authentication is a per-supplier API key in a header (§5, p.5).
- Responses: 200, 400, 401, 429, 502 (§8, p.6).
- Rate limit: 100 requests per minute per key (§9, p.7).

**Schema**

- "Fields marked 'Required' must be provided where available. (Full schema remains unchanged from technical specification)". The full schema is **not** in the guide (§6, p.6).
- The example payload has `hotel_code` plus a `bookings[]` array of `reservation_no`, `guest_first_name`, `guest_last_name`, `guest_country`, `checkin`, `checkout`, `guest_count`, `currency_code`, `amount_total` (§7, p.6).
- The guide does not define the event field, a source or channel field, or the required flags. The OpenAPI spec below covers them.

### ZAN GATEWAY OpenAPI spec

**Source:** `docs/research/sources/zan-gateway-openapi.yaml`. "Zan Gateway PMS Push API" v1.0.0, OpenAPI 3.1.0, downloaded from `https://zangw-staging.zanrevenue.org/api/openapi.yaml` (the file the Swagger page at `/api-docs` loads) on 6 October 2026. The spec "intentionally includes only the two push endpoints used by PMS suppliers". Below it is cited as "ZAN OpenAPI".

**Servers**

- Staging: `https://zangw-staging.zanrevenue.org`
- Production: `https://zangateway.zanrevenue.org`

**Endpoint 1: `POST /api/v1/pms/zanzibar/bookings`**

- **Authentication:** `X-API-Key` header.
- **Body:** `{ hotel_code, bookings[] }`, with at least one booking. One request covers one hotel and can carry many bookings.
- **Compression:** optional `Content-Encoding` of `gzip` or `br`.
- **Field names:** eZee-style PascalCase (`ReservationNo`, `FirstName`) and snake_case (`reservation_no`, `guest_first_name`) are both accepted. Send one style, not both.
- **Required for a 200:**
    - a reservation number;
    - a first or last name;
    - arrival and departure dates;
    - a guest count: `NoOfGuest`, `guest_count`, or `Adult`/`Child`. "Explicit 0 allowed for cancel/no-show."
- **Not required, but should be sent "so tax reporting is complete":** amounts and currency (`CurrencyCode`, `TotalInclusiveTax`/`amount_total`).
- **Optional fields:**
    - `ReservationDate`, `FolioNo`, `RoomNo`. `RoomNo` is "used for property-specific rules (e.g. underwater-room infra tax)".
    - `TotalTax`, `TotalExclusivTax` (vendor spelling), `OtherRevenue*`, `DueAmount`, `Deposit`.
    - `CancelDate` (also accepted as `CancellationDate`).
    - `ArrivalTime`, `DepartureTime`, `NoOfNights`.
- **Extra fields:** `additionalProperties: true`, so fields outside the schema are allowed.

**Event and status model**

| Situation                             | `Status`             | `CurrentStatus`           |
| ------------------------------------- | -------------------- | ------------------------- |
| New booking                           | `New`                | `Confirmed Reservation`   |
| Arrival or check-in                   | `Modify`             | `Arrived` or `Checked In` |
| Check-out                             | `Modify`             | `Checked Out`             |
| Cancellation (also send `CancelDate`) | `Cancel`             | `Cancel`                  |
| Void                                  | `Modify` or `Cancel` | `Void`                    |

- `CurrentStatus` wins when both fields are sent.
- "`Status` is not an OTA create/update flag: identity is hotel_code + reservation number." **Inference:** every push upserts the record for that reservation number. A correction is a re-push of the same reservation number. That matches the guide's "Corrections must be submitted through the API" (§11).
- `BookingStatus` is also accepted as a lifecycle field.

**Responses**

| Code | Meaning                                                                                                                     |
| ---- | --------------------------------------------------------------------------------------------------------------------------- |
| 200  | Accepted. Returns `id`, `booking_count`, `rejected_booking_count`, `skipped_non_actionable_count` and `validation_errors[]` |
| 400  | Missing `hotel_code` or `bookings`, or every booking failed validation                                                      |
| 401  | Bad or missing API key                                                                                                      |
| 415  | Unsupported content encoding                                                                                                |
| 429  | Rate limited ("default 500/minute per API key")                                                                             |
| 502  | "Failed to forward to sync service after retries"                                                                           |

- The 200 `id` is a submission (batch) id, "not a stable booking key".
- **Inference:** a 200 can still contain rejected bookings. Karafuu must read `rejected_booking_count` and `validation_errors` on each booking, not treat the 200 as success for the whole batch.

**Endpoint 2: `POST /api/v1/pms/zanzibar/hotel-info`**

- Upserts the property profile.
- **Required:** `Hotel_Code`, `Hotel_Name`, `City`, `State`, `Zipcode`, `Country`, `Property_Type`, `Address`, `CurrencyCode`.
- **Optional:**
    - `tax_region` (several aliases).
    - `star_rating`: 1–5, or the grades `A`, `AA`, `AP`, `APP`, `HOS`, `SI`.
- **Response:** includes `ztn_number`, "null until ZRA registers the taxpayer". **Inference:** a property can push hotel info before its ZRA taxpayer registration is linked.

**Still not in the spec**

- **No booking-source or channel field.** No field marks a booking as Booking.com versus direct. `additionalProperties: true` means such a field would be accepted, but ZRA has not defined one. **Unverified:** whether ZRA wants one, and its name.
- **No no-show status.** The only hint is that `guest_count: 0` is allowed "for cancel/no-show". **Unverified:** whether a no-show should be sent as `Cancel`, as `Void`, or with `guest_count: 0`.
- **No `guest_country`.** The guide's example payload uses `guest_country` (§7), but the spec does not list it. It would pass under `additionalProperties`.
- **Rate limit conflict.** The guide says 100 per minute (§9). The spec says a default of 500 per minute.
- **5-minute clock.** Whether the 5-minute window starts at OTA booking time or at receipt in the PMS is still not stated.

**Corrections and integrity**

- "Corrections must be submitted through the API". No unauthorised deletion or alteration (§11, p.7).

**Enforcement**

Non-compliance includes:

- OTA connectivity not integrated;
- booking data manually entered or delayed;
- properties operating outside the system.

Source: §12, p.7.

### VFMS: fiscal receipting

**Transport and authentication**

- All endpoints are `POST` and JSON (§1.1, p.1; §2, p.3).
- Headers: `Content-type`, `vfms-request-type`, `vfms-intergration-id` (sic), `vfms-token-id` (§1.4, pp.1–2).
- The test base URL is plain HTTP on an IP address and port (`http://102.223.7.131:6060/vfms/api/…`) (§2.1, p.3). **Unverified:** the production URL and whether it uses TLS.

**Endpoints**

| #    | Endpoint                      | `vfms-request-type`             | When it is used                                                                                                                         |
| ---- | ----------------------------- | ------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------- |
| 2.1  | Accommodation                 | `ACCOMMODATION_REQUEST`         | At check-in. "VFMS will determine the infrastructure tax and the total tax amount per guest per night until the guest checks out" (p.3) |
| 2.2  | Change room                   | `ACCOMMODATION_CHANGE_ROOM`     | Room move (p.9)                                                                                                                         |
| 2.3  | Change room price             | `CHANGEROOM_PRICE`              | Rate change (p.11)                                                                                                                      |
| 2.4  | Exchange rate                 | `CURRENCY_EXCHANGE`             | Rate lookup (p.12)                                                                                                                      |
| 2.5  | Accommodation report          | `ACCOMMODATION_REPORT`          | Fetch records by date range. Response includes `source`, `isCanceled`, `isClosed` (pp.13–14)                                            |
| 2.6  | Add guest                     | `ACCOMMODATION_ADD_GUEST`       | Extra guest in an occupied room (p.15)                                                                                                  |
| 2.7  | Services                      | `ACCOMMODATION_ADD_SERVICE`     | Extras. Returns `receiptNumber` (pp.16–18)                                                                                              |
| 2.8  | Change profile                | `GUEST_PROFILE`                 | Correct guest details (p.19)                                                                                                            |
| 2.9  | Check-out                     | `ACCOMMODATION_CHECK_OUT`       | Returns the check-out report with `reportNumber`, totals, tax and `totalInfrastructure` (pp.21–23)                                      |
| 2.10 | Advance payment booking       | `RESERVATION_BOOKING`           | B2B tour-operator prepayment. Returns `receiptNumber` and a `bookingNumber` (pp.24–27)                                                  |
| 2.11 | Advance payment accommodation | `ACCOMMODATION_BOOKING_REQUEST` | Check-in against an advance booking (pp.28–31)                                                                                          |

**Receipt requirements**

- Receipts and check-out reports "must include the VFMS QR code" ("Note brief", p.33).
- Section 4 shows sample receipt and check-out report layouts (pp.34–37).

**Gaps and inconsistencies in the VFMS document**

- No cancellation, void or refund endpoint for accommodation or advance payments.
- No OTA source value.
- The §2.6 parameter table does not match its example payload: the example is a services payload.
- §2.7 documents `type` as `CS`/`WS`, but its response example returns `CR`.
- The VFMS document calls itself a "Test Environment" guide (cover).

**Inference from the example figures:**

- 900.0 with tax 117.39 is consistent with extracting 15% from a tax-inclusive amount.
- `totalInfrastructure` 45.0 for 9 nights and 1 guest is consistent with 5 per guest per night (§2.9, p.22).

The document does not state either rate. **Unverified.**

### How a Booking.com booking moves through both ZRA systems

**Inference**, assembled from the sources above:

1. **Booking.com booking received**, either pulled (direct) or pushed or pulled from a channel manager. Karafuu creates its reservation and reports a ZAN GATEWAY creation within 5 minutes. No VFMS call yet: VFMS starts at check-in.
2. **Modification or cancellation.** ZAN GATEWAY report within 5 minutes. No VFMS call if the guest has not checked in. If already checked in, the change may need a VFMS room change (§2.2), price change (§2.3) or added guest (§2.6).
3. **No-show.** Karafuu reports it to Booking.com through the Reporting API (direct only). **Unverified:** how ZRA expects a no-show to be reported. It is not one of the five ZAN events.
4. **Check-in.** ZAN GATEWAY check-in "immediately", plus VFMS Accommodation (§2.1) with the guest's identity documents captured at the desk.
5. **Check-out.** ZAN GATEWAY check-out "immediately", plus VFMS Check-out (§2.9). The fiscal check-out report with QR code is issued to the guest.

### Field mapping: Booking.com OTA reservation to ZAN GATEWAY and VFMS

Booking.com element paths come from [Retrieving new reservations](https://developers.booking.com/connectivity/docs/reservations-api/retrieving-new-reservations-ota). B.XML equivalents come from [Managing reservations (B.XML)](https://developers.booking.com/connectivity/docs/reservations-api/managing-reservations-bxml).

| Booking.com source                                                                                                                             | ZAN GATEWAY field (§7)                                                                           | VFMS field                                                                                       | Notes and gaps                                                                                                                                                                                                                     |
| ---------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `BasicPropertyInfo@HotelCode` (Booking.com property ID)                                                                                        | `hotel_code`                                                                                     | none (the business unit is set by credentials)                                                   | **Inference:** map to Karafuu's property, then to the "PMS hotel code" registered with ZRA (§4 Step 2). Not the Booking.com ID itself                                                                                              |
| `HotelReservationID@ResID_Value`                                                                                                               | `reservation_no`                                                                                 | `referenceNumber` ("A unique transaction no.")                                                   | Decision needed: Booking.com ID or Karafuu ID. VFMS `referenceNumber` is per transaction, so each VFMS call needs its own unique value (**inference** from §2.1–2.9 examples)                                                      |
| `ResGuest/…/PersonName` `GivenName` and `Surname`. Split names need the `ota_res_split_names` feature, otherwise `Surname` holds the full name | `guest_first_name`, `guest_last_name`                                                            | `guest[].fullName`                                                                               | The guest can differ from the booker (`ResGlobalInfo/Profiles`). Multi-room guest assignment is approximate ([General information FAQ](https://developers.booking.com/connectivity/docs/con-faq-reservations-general-information)) |
| `ResGlobalInfo/…/Address/CountryName@Code` (booker's residence, ISO 3166 alpha-2). B.XML `countrycode`                                         | `guest_country` (example "DE")                                                                   | `guest[].country` (examples use names: "KENYA", "INDIA", "TANZANIA"; report response shows "IT") | **Gap:** booker's residence is not each guest's nationality. **Gap:** VFMS uses country names in requests, ZAN uses ISO codes                                                                                                      |
| none                                                                                                                                           | none                                                                                             | `guest[].documentName`, `guest[].documentNo`                                                     | **Gap:** not supplied by Booking.com. Must be captured at check-in. VFMS keys check-out, services and profile on `documentNo`                                                                                                      |
| `RoomRate@EffectiveDate` per night (OTA). `arrival_date` / `departure_date` (B.XML)                                                            | `checkin`, `checkout`                                                                            | `checkInDate` (actual check-in)                                                                  | **Inference:** for OTA, check-out = last `EffectiveDate` + 1 day. VFMS uses the actual check-in time, not the booked date                                                                                                          |
| `RoomStay/GuestCounts/GuestCount@Count` (room-level counts and ages need feature flags)                                                        | `guest_count`                                                                                    | number of `guest[]` entries                                                                      | Booked occupancy can differ from arrivals. Children under the property's free age limit may not be counted ([General information FAQ](https://developers.booking.com/connectivity/docs/con-faq-reservations-general-information))  |
| `Total@CurrencyCode`                                                                                                                           | `currency_code`                                                                                  | `currency` (`USD`/`TZS`/`EURO`)                                                                  | Map `EUR` to `EURO`. **Gap:** other currencies are unsupported by VFMS                                                                                                                                                             |
| `ResGlobalInfo/Total@AmountAfterTax` or `@AmountBeforeTax`, divided by 10^`DecimalPlaces`                                                      | `amount_total`                                                                                   | none at booking. VFMS totals are computed                                                        | Which attribute appears depends on the property's Extranet VAT settings. Some charges may be excluded. Payments Clarity Package v2 gives the breakdown                                                                             |
| `RoomRate/Rates/Rate/Total` per night                                                                                                          | none                                                                                             | `accommodationPrice` ("A tax-inclusive amount")                                                  | **Gap:** VFMS takes one price per check-in, and changes go through §2.3. Varying nightly rates need a rule. If Booking.com returns `AmountBeforeTax`, a tax-inclusive figure must be derived                                       |
| Discount not itemised (price already discounted)                                                                                               | none                                                                                             | `accommodationDiscount`                                                                          | **Unverified:** how to populate it for Genius or promotion pricing                                                                                                                                                                 |
| `RoomType@RoomTypeCode`, `RoomDescription@Name`                                                                                                | none                                                                                             | `roomId`, `roomName`                                                                             | VFMS wants the physical room. Booking.com gives the room type only. Assigned at check-in                                                                                                                                           |
| `RoomDescription/MealPlan` (free text)                                                                                                         | none                                                                                             | `paymentPackage` (example "AI")                                                                  | **Gap:** the VFMS code list is not documented                                                                                                                                                                                      |
| Endpoint (`OTA_HotelResNotif` versus `OTA_HotelResModifyNotif`). B.XML `status`                                                                | `Status` (`New`/`Modify`/`Cancel`) + `CurrentStatus`; `CancelDate` on cancellation (ZAN OpenAPI) | none (no cancellation endpoint)                                                                  | Cancellation reporting exists only on the ZAN side. Check-in and check-out are PMS events (`Modify` + `Checked In`/`Checked Out`), not Booking.com events                                                                          |
| none                                                                                                                                           | none (no channel field in ZAN OpenAPI)                                                           | `accommodationSource`                                                                            | **Gap:** neither ZRA system has a field marking the booking as coming from Booking.com                                                                                                                                             |
| `RatePlan/Commission/CommissionPayableAmount`, `DepositPayments`, `PriceDetails` (PCP v2)                                                      | none in example                                                                                  | none                                                                                             | Useful for reconciliation. Not needed by either ZRA example                                                                                                                                                                        |
| none                                                                                                                                           | none                                                                                             | `accommodationSource`, `tourRegNo`, `costOptions`                                                | **Gap:** no OTA value (see §4)                                                                                                                                                                                                     |

---

## Decisions the PRD must make

1. **Connectivity route for launch.** Options:
    - a channel manager, such as Channex, SiteMinder or RateGain;
    - waiting for Booking.com to reopen partner onboarding;
    - both, in sequence.
2. **Release stage for ZRA and OTA connectivity.** PRD v1.0 places distribution and local reporting in P1. ZRA bars onboarding Zanzibar properties without full channel integration and transmission. Options:
    - move ZAN GATEWAY, VFMS and OTA connectivity into P0 for Zanzibar;
    - exclude Zanzibar properties from P0;
    - something else.
3. **Manual export fallback.** Whether LOC-03's "approved manual export workflows" remains, and for which jurisdictions. ZAN GW guide §3.3 and §12 classify manual or delayed OTA data as non-compliant.
4. **Which channels at launch.** Booking.com only, or all OTAs the pilot hotels use. ZRA requires all channels.
5. **Booking.com API scope.** Reservations and ARI only, or also Content, Photos, Promotions, Reporting (no-shows), Payments API and Payments Clarity Package v2.
6. **OTA XML or B.XML.** If going direct: OTA XML (acknowledgements) or B.XML (no acknowledgements).
7. **Card data handling.** Whether Karafuu accepts card data by XML, which needs PCI compliance, or uses the Extranet-only display option. How this fits FIN-04.
8. **Payments by Booking.com support.** Support VCC and bank-transfer reconciliation in P1, or only hotel-collect at first.
9. **Receipt treatment for OTA bookings.** Payer, gross versus net amount, VFMS `accommodationSource` value, and treatment of commission. All pending ZRA confirmation.
10. **Guest identity capture.** Make identity documents mandatory at check-in for Zanzibar properties because of VFMS, versus PMS-03's instruction not to hard-code passports. Also whether to collect nationality per guest.
11. **Currency policy.** For Booking.com load or payout currencies that VFMS does not accept.
12. **Per-person fees.** How to handle infrastructure tax and per-person fees that PbB may already have collected online from 15 June 2026.
13. **Reference numbers.** Whether ZRA `reservation_no` and VFMS `referenceNumber` use Booking.com IDs or Karafuu IDs, and their uniqueness scope across organisations and properties.
14. **Historical import.** The from-1-January-2025 import for properties migrating from other systems: data source, and who is responsible.
15. **ZAN GATEWAY delivery.** A shared multi-tenant queue under one supplier key at 100 requests per minute, and whether to request a higher limit. What reporting latency to target.
16. **Booking.com outage behaviour.** Reservation Recovery API, outage holds, and what happens to fallback-email bookings in ZRA terms.
17. **Responsibility split** between Karafuu and the channel manager for ZRA reporting, and how duplicate reporting is avoided.
18. **Who handles the regulatory work.** Who registers Karafuu with ZRA (vendor and taxpayer), who obtains VFMS certification, and who owns ongoing enforcement risk.

---

## Unverified / open questions

**Booking.com programme**

- When, or whether, Booking.com will reopen onboarding for new connectivity partners.
- Booking.com's minimum property counts, certification durations, partner fees, tier criteria and the Hub-only "mandatory functionalities" article. The `portal.connectivity.booking.com` and `connectivity.booking.com` pages failed TLS verification from this environment.

**Booking.com API behaviour**

- The exact OTA XML marker for a fully cancelled reservation, which may be an empty `RoomStays`.
- Whether PbB (VCC or bank transfer) is available to Zanzibar properties today, and the bank-transfer fee percentage for Tanzania.

**Channel managers**

- Channel-manager pricing for SiteMinder, RateGain and Cloudbeds, and whether Cloudbeds offers a channel-manager API to third-party PMSs.
- Any channel manager with a verifiable office or support presence in Tanzania or East Africa. None was found on primary sources.
- Which channel managers are registered or compliant with ZRA. The registry data on compliance.zanrevenue.org is loaded dynamically and was not read.
- Whether ZRA expects both the channel manager and the PMS to report the same booking, and how ZRA de-duplicates.

**ZAN GATEWAY**

- ~~The full ZAN GATEWAY schema~~: resolved by the OpenAPI spec (see §5). Still open: a booking-source or channel field, which the spec does not define.
- How ZRA wants no-shows reported. The spec has no no-show status, only "explicit 0 allowed for cancel/no-show" on guest count.
- Which rate limit applies: 100 per minute (guide §9) or the default of 500 per minute (spec).
- Whether the 5-minute window is measured from OTA booking time or from receipt in the PMS.
- The authoritative compliance deadline: the portal shows both 24 April 2026 and 18 May 2026.

**VFMS**

- The VFMS production endpoint, transport security, certification steps and `paymentPackage` codes.
- The VFMS VAT and infrastructure-tax rates. The figures in this document are inferred from examples only.
- The correct VFMS `accommodationSource` for OTA bookings, how to receipt OTA prepayments, cancellation and no-show fees and refunds, and whether the Advance Payment Booking endpoint applies to OTAs.
- ZRA's treatment of Booking.com commission, and of net bank-transfer payouts.

**Local PDFs**

- Whether the credentials printed in the local ZRA PDFs are still live.

---

## Sources

### ZRA API spec

- `docs/research/sources/zan-gateway-openapi.yaml`: "Zan Gateway PMS Push API" v1.0.0 (OpenAPI 3.1.0), from https://zangw-staging.zanrevenue.org/api/openapi.yaml, retrieved 6 October 2026.

### Local PDFs

- `PRD.pdf`: Karafuu PMS PRD v1.0, 6 October 2026. Sections cited: §3 p.1; §4 p.2; §7.4 p.5; §7.6 p.6; §7.9 p.7; §7.11 p.8; §12 p.11; §14 p.12.
- `Vendor-Level PMS Integration Guide (Onboarding) ZAN GATEWAY - Novacom.pdf`: ZRA, ZAN GATEWAY PMS Integration Guide v1.3. Sections cited: §1–§14, pp.1–8.
- `VFMS HOTEL API V1.6 (6060).pdf`: ZRA, Hotel API Guide for VFMS v1.6, Test Environment. Sections cited: §1–§4, pp.1–37.

### Zanzibar Revenue Authority (web)

- ZRA Hospitality Compliance Portal: https://compliance.zanrevenue.org/

### Booking.com developer documentation (developers.booking.com)

- About the Connectivity APIs: https://developers.booking.com/connectivity/docs
- Overview: https://developers.booking.com/connectivity/docs/index-content
- Going Live: https://developers.booking.com/connectivity/docs/going_live
- Foundational solutions: https://developers.booking.com/connectivity/docs/solutions/foundational/foundational
- Authentication: https://developers.booking.com/connectivity/docs/authentication
- Token-based authentication: https://developers.booking.com/connectivity/docs/token-based-authentication
- Managing endpoint access: https://developers.booking.com/connectivity/docs/managing-endpoint-access
- Reservations overview: https://developers.booking.com/connectivity/docs/reservations-api/reservations-overview
- OTA reservations process: https://developers.booking.com/connectivity/docs/reservations-api/reservations-process-ota
- Retrieving new reservations (OTA): https://developers.booking.com/connectivity/docs/reservations-api/retrieving-new-reservations-ota
- Retrieving modified/cancelled reservations (OTA): https://developers.booking.com/connectivity/docs/reservations-api/retrieving-modifcancel-reservations-ota
- Acknowledging modified/cancelled reservations: https://developers.booking.com/connectivity/docs/reservations-api/acknowleding-modif-cancel-reservations-ota
- Managing reservations (B.XML): https://developers.booking.com/connectivity/docs/reservations-api/managing-reservations-bxml
- RoomStays reference: https://developers.booking.com/connectivity/docs/api-reference/roomstays
- Reservation Recovery API: https://developers.booking.com/connectivity/docs/reservations-flow-control-api/reservations-recovery-overview
- Reservations general information FAQ: https://developers.booking.com/connectivity/docs/con-faq-reservations-general-information
- Handling payment-related data FAQ: https://developers.booking.com/connectivity/docs/con-faq-reservations-handling-payment-related-data
- Rates & Availability API overview: https://developers.booking.com/connectivity/docs/ari
- Rates & Availability FAQ: https://developers.booking.com/connectivity/docs/con-faq-rates-availability
- Content API overview: https://developers.booking.com/connectivity/docs/content
- Implementing or disabling CVC collection (HDCN deprecation): https://developers.booking.com/connectivity/docs/collecting-cvc
- Photo API: https://developers.booking.com/connectivity/docs/photo-api/understanding-the-photo-api
- Promotions: https://developers.booking.com/connectivity/docs/promotions
- Reporting API: https://developers.booking.com/connectivity/docs/reporting-api/b_xml-reporting
- Payments API: https://developers.booking.com/connectivity/docs/payments-api/understanding-the-payments-api
- Payments by Booking.com Onboarding API: https://developers.booking.com/connectivity/docs/payments-by-booking-onboarding-api/understanding-the-pbb-onboarding-api
- Understanding payouts: https://developers.booking.com/connectivity/docs/payments-by-booking-onboarding-api/understanding-payouts
- Notification Service: https://developers.booking.com/connectivity/docs/notification-service/notification-service-overview

### Booking.com partner pages

- Connectivity Portal (partnership pause notice): https://connect.booking.com/
- Payments by Booking.com guide and costs: https://partner.booking.com/en-gb/help/policies-payments/payment-products/how-much-payments-bookingcom-costs
- Payments by Booking.com FAQs: https://partner.booking.com/en-us/help/policies-payments/payment-products/payments-bookingcom-faqs
- Virtual credit cards: https://partner.booking.com/en-gb/help/policies-payments/payment-products/everything-you-need-know-about-virtual-credit-cards
- Understanding our commission: https://partner.booking.com/en-us/help/commission-invoices-tax/commission/understanding-our-commission
- Paying your invoices: https://partner.booking.com/en-us/help/payments-payouts-invoices/invoices/paying-your-invoices-bookingcom
- Sending guest invoices: https://partner.booking.com/en-us/help/payments-payouts-invoices/guest-payments/sending-guest-invoices
- Currency settings: https://partner.booking.com/en-us/help/commission-invoices-tax/guest-payments/everything-you-need-know-about-currency-settings-and
- Setting up and working with a Connectivity provider: https://partner.booking.com/en-us/help/first-steps/online-bookable/setting-and-working-connectivity-provider and https://partner.booking.com/en-gb/help/registration-getting-started/tools/setting-and-working-connectivity-provider

### Channel managers

- Channex pricing: https://channex.io/pricing.md
- Channex product summary: https://channex.io/llms.txt
- SiteMinder integration process: https://developer.siteminder.com/get-started/partner-lifecycle/integration-process.md
- SiteMinder pmsXchange FAQ: https://developer.siteminder.com/pmsxchange-api/additional-resources/faq.md
- SiteMinder pmsXchange Reservations Push: https://developer.siteminder.com/pmsxchange-api/reference/reservations/push.md
- RateGain RG Bridge reservation notification spec: https://developer.rategain.com/our-products/channel-manager/rg-bridge-supply-push/interface-specifications-reservation-notification-service.md
- Cloudbeds developer portal: https://developers.cloudbeds.com/page/home
