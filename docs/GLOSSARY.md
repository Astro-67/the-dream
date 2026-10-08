# Thedream PMS

Multi-tenant hotel management SaaS for Tanzania and Zanzibar first. This glossary fixes the language used in the PRD and its decisions.

## Tenancy

**Organization**:
The tenant: the customer business that owns the subscription and one or more properties.
_Avoid_: Account, customer, client

**Property**:
One hotel operated by an organization, with its own rooms, settings and jurisdiction.
_Avoid_: Hotel (in formal requirements), site, unit

## Jurisdiction and compliance

**Jurisdiction profile**:
The set of legal rules a property operates under, set by where it is: required guest registration fields, tax rules and authority reporting. Zanzibar and mainland Tanzania are separate profiles.
_Avoid_: Country settings, region

**Zanzibar compliance pack**:
The capabilities a property under the Zanzibar jurisdiction profile needs before it may go live: ZAN Gateway reporting, VFMS fiscal receipts, and a live connection for every channel the property sells on.
_Avoid_: ZRA integration, fiscal module

**PMS supplier**:
The company registered with the Zanzibar Revenue Authority as the provider of the system a property uses. Thedream is the PMS supplier for all of its properties.
_Avoid_: Vendor, integrator

**Booking report**:
A message to ZAN Gateway recording a reservation event: new, modified, cancelled, checked in or checked out.
_Avoid_: Sync, push, posting

**Booking report correction**:
A revision of a booking report confirmed rejected by ZAN Gateway, preserving the reservation event it reports. It is not a new reservation event or a replacement with an unrelated later state.
_Avoid_: Retry (for a correction), latest-state sync

**Reservation number**:
Thedream's own identifier for a reservation: unique within its property, never reused, and kept for the whole stay. It is the identifier sent to ZAN Gateway.
_Avoid_: Booking ID, confirmation number

**Channel reference**:
The identifier a channel gives a reservation, such as the Booking.com reservation ID. Stored alongside the reservation number, never in place of it.
_Avoid_: External ID, OTA ID

**Fiscal receipt**:
A receipt or check-out report issued through VFMS, carrying a VFMS QR code.
_Avoid_: Invoice, bill, folio

**Fiscal exception**:
A charge that has no path into VFMS, such as a cancellation or no-show fee, so it gets a non-fiscal receipt and is listed for the property's accountant.
_Avoid_: Unreceipted charge, tax gap

**No-show**:
A reservation whose guest never arrived by the end of the arrival business date. Reported to ZAN Gateway as a cancellation with zero guests, but kept as a no-show in Thedream.
_Avoid_: Cancellation (for a no-show)

## Distribution

**Channel**:
A source of reservations other than the property's own staff, such as Booking.com or the direct booking page.
_Avoid_: Source, OTA (when the channel is not an OTA)

**OTA**:
An online travel agency channel, such as Booking.com.

**Channel manager**:
A third-party service Thedream connects to once, which relays rates, availability and reservations between Thedream and many OTAs.
_Avoid_: Connectivity provider, aggregator

**Payment collector**:
Who takes the guest's money for a reservation: the property (hotel-collect) or the channel (for example Payments by Booking.com).
_Avoid_: Payment type, payment model

**Channel-collected amount**:
What a channel has already collected from the guest for a reservation, including any per-person fees, before the guest arrives.
_Avoid_: Prepayment, deposit (when the channel collected it)

**Booking received outside integration**:
A channel reservation that reached the property by a route other than the channel connection, such as an email fallback during an outage, and was entered by staff.
_Avoid_: Manual booking, offline booking

**Direct connection**:
A connection between Thedream and an OTA with no channel manager in between.
_Avoid_: Native integration
