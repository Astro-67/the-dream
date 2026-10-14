# Thedream PMS

Multi-tenant hotel management SaaS for Tanzania and Zanzibar first. This glossary fixes the language used in the PRD and its decisions.

## Tenancy

**Organization**:
The tenant: the customer business that owns the subscription and one or more properties.
_Avoid_: Account, customer, client

**Property**:
One hotel operated by an organization, with its own rooms, settings and jurisdiction.
_Avoid_: Hotel (in formal requirements), site, unit

## Reservations and front desk

**Reservation**:
A booking of one room type at one property for a range of nights. A booking for several rooms becomes one reservation per room.
_Avoid_: Booking (in formal requirements)

**Booking group**:
The reservations created from one booking for several rooms, such as one Booking.com reservation for two rooms. Each member keeps its own reservation number.
_Avoid_: Group block, multi-room reservation

**Stay**:
The in-house phase of a reservation, from check-in to check-out. Not a separate record from the reservation.
_Avoid_: Visit

**Room assignment**:
Choosing the specific room for a reservation. Optional when booking, required by check-in.

**Business date**:
The property's current operating day, which ends only when night audit closes it, not at midnight.
_Avoid_: Today, system date

**Night audit**:
Closing a property's business date: posting the night's room charges, proposing no-shows, and opening the next business date. Runs automatically at the property's close time; unresolved items carry forward rather than block.
_Avoid_: End of day

**Exception list**:
The items a night audit close left unresolved (no-shows awaiting a decision, departures not checked out, open cashier shifts), listed on the next business date for the manager. Each close lists only what is still unresolved then.

**Rate plan**:
A named way of selling a room type, such as "Bed and breakfast" or "Non-refundable", with one currency and a price per room type per date. A derived rate plan takes its prices from one base plan with a fixed percentage or amount difference.
_Avoid_: Tariff, price list

**Room charge**:
The charge for one night of a stay, posted to the folio when night audit closes that night's business date.
_Avoid_: Room revenue (a report figure)

**Ready room**:
A room clean enough to check a guest into. Only a clean room is ready, unless the property skips inspection or a manager overrides with a reason.

**Overbooking conflict**:
A channel reservation that arrived when its room type had no inventory left on some night. It is accepted and flagged for staff, never refused. Staff can never overbook deliberately.
_Avoid_: Overbooking (as a feature)

**Folio**:
The guest account for one reservation in one currency, open from booking until its balance is settled, which may be after departure. Charges move between folios only by a linked transfer.
_Avoid_: Bill, invoice, guest ledger

**Deposit**:
A payment posted to a folio before the guest arrives.
_Avoid_: Prepayment (when a channel collected it, that is a channel-collected amount)

**Cashier shift**:
One user's period of handling cash at one property, closed by counting the cash in each currency against what Thedream expects.
_Avoid_: Till, session

**Cancellation policy**:
The rule on a rate plan that proposes a fee when a reservation is cancelled or becomes a no-show. Staff post or waive the proposed fee with a reason.

**Open balance after departure**:
A balance left on a folio when a manager let the guest check out without settling it. It stays on the folio until paid or written off.
_Avoid_: Bad debt (until written off), city ledger

**Guest**:
A person staying at the property, held per property. Repeat guests are linked by staff choice, never matched automatically.
_Avoid_: Customer, client

**Booker**:
The person who made a reservation, who need not be a guest on it.
_Avoid_: Contact, lead guest (when not staying)

**Imported reservation**:
A future reservation brought in from a property's previous system before go-live.
_Avoid_: Migrated booking

**Demo property**:
A property flagged for demonstration, holding sample data. Excluded from reports and plan limits, and never reported to channels or authorities.
_Avoid_: Sandbox, test property

**Write-off**:
Clearing an unpaid folio balance as uncollectable. Needs a permission and a reason, and a second approver above the property's threshold.

**Receipt**:
A Thedream-numbered record of payment, always labelled non-fiscal. Distinct from a fiscal receipt, which VFMS issues.

**Restriction**:
A selling rule on a rate plan and date: minimum stay, closed to arrival or stop-sell. Binding for channels; staff may override with a reason. Never overrides inventory.

**Go-live**:
A property moving from setup to live once its go-live gates pass. Before go-live, a property can be configured and take test reservations but cannot check guests in, and nothing is reported to authorities.
_Avoid_: Activation, launch

**Reservation lifecycle event**:
A record that something happened to a reservation that authorities may need to know about: created, modified, cancelled, no-show confirmed, checked in, room moved, guest added, nightly price changed, checked out.
_Avoid_: Booking report (that is ZAN Gateway's message, derived from the event)

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
