# Karafuu PMS

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

**Booked occupancy**:
Rooms sold divided by sellable rooms for a date, counting reservations whether or not guests have arrived. Valid for future dates.
_Avoid_: Occupancy (unqualified, for future dates)

**Actual occupancy**:
Rooms occupied overnight divided by sellable rooms for a past date.
_Avoid_: Occupancy (unqualified, for past dates)

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

**Out of order**:
A specific room removed from sellable inventory for a range of nights, with a reason. The back-in-service date is the first night it can be sold again.
_Avoid_: Out of service

**Overbooking conflict**:
A channel reservation that arrived when its room type had no inventory left on some night, beyond the property's overbooking allowance. It is accepted and flagged for staff, never refused.
_Avoid_: Overbooking (as a feature)

**Overbooking allowance**:
The percentage of a room type's rooms a property lets staff sell beyond its inventory for a night. Zero unless the property sets it.

**Overstay**:
An in-house reservation whose departure date has passed without check-out. A marker on the stay, not a separate status.

**Automatic check-out**:
The property's daily run, at a time it sets, that checks out departing guests and emails the property a summary. The property chooses whether it includes guests with unpaid folios and overstays.
_Avoid_: Night audit (which never checks guests out)

**Folio**:
The guest account for one reservation in one currency, open from booking until its balance is settled, which may be after departure. Charges move between folios only by a linked transfer.
_Avoid_: Bill, invoice, guest ledger, guest account

**Deposit**:
A payment posted to a folio before the guest arrives.
_Avoid_: Prepayment (when a channel collected it, that is a channel-collected amount)

**Cashier shift**:
One user's period of handling cash at one property, closed by counting the cash in each currency against what Karafuu expects. Optional: a property uses shifts only if it turns them on.
_Avoid_: Till, session

**Cancellation policy**:
The rule on a rate plan that proposes a fee when a reservation is cancelled or becomes a no-show. Staff post or waive the proposed fee with a reason.

**Open balance after departure**:
A balance left on a folio when a manager, or automatic check-out set to include unpaid folios, let the guest check out without settling it. It stays on the folio until paid or written off.
_Avoid_: Bad debt (until written off), city ledger

**Guest**:
A person staying at the property, held per property. Repeat guests are linked by staff choice, never matched automatically.
_Avoid_: Customer, client

**Lead guest**:
The guest whose details are taken with the reservation. Other guests are added to the reservation later.
_Avoid_: Main guest, primary guest

**Guest departure**:
One guest other than the lead guest leaving an in-house stay before it checks out ("Guest leaves"). The stay and room stay in-house; at a Zanzibar property VFMS checks out that guest's document, and the stay's check-out lists only the guests still in-house.
_Avoid_: Partial check-out

**Tour operator**:
A ZRA-registered company that books a reservation and pays some or all of it: the room, the infrastructure levy, other services, or a combination. A reservation either has one or is paid by the guest directly.
_Avoid_: Booker, agent, company

**Meal plan**:
What a reservation's price includes besides the room: room only, bed and breakfast, half board, full board or all-inclusive.
_Avoid_: Package (that is a subscription plan), board

**Imported reservation**:
A future reservation brought in from a property's previous system before go-live.
_Avoid_: Migrated booking

**Demo property**:
A property flagged for demonstration, holding sample data. Excluded from reports and plan limits, and never reported to channels or authorities.
_Avoid_: Sandbox, test property

**Write-off**:
Clearing an unpaid folio balance as uncollectable. Needs a permission and a reason, and a second approver above the property's threshold.

**Receipt**:
A Karafuu-numbered record of payment, always labelled non-fiscal. Distinct from a fiscal receipt, which VFMS issues.

**Restriction**:
A selling rule on a rate plan and date: minimum stay, closed to arrival or stop-sell. Binding for channels; staff may override with a reason. Never overrides inventory.

**Go-live**:
A property moving from setup to live. The owner sends a go-live request once its go-live gates pass; a platform administrator approves it after Karafuu is paid. Before go-live, a property can be configured and take test reservations but cannot check guests in, and nothing is reported to authorities. A Zanzibar property also needs VFMS credentials accepted by ZRA and set to Live.
_Avoid_: Activation, launch

**Go-live request**:
A property owner's request, recorded with when and by whom, for Karafuu to take a property live. Refused while any go-live gate fails, and always for a demo property. The property stays in setup while the request waits; requesting again changes nothing.
_Avoid_: Activation request

**Reservation lifecycle event**:
A record that something happened to a reservation that authorities may need to know about: created, modified, cancelled, no-show confirmed, checked in, room moved, guest added, guest profile changed (name, country or identity document of an in-house guest), nightly price changed, extra posted, checked out.
_Avoid_: Booking report (that is ZAN Gateway's message, derived from the event)

**Maintenance ticket**:
A reported fault or planned job (repair, preventive or corrective) at a room or common area of one property, open until marked done. It never changes room status or availability; blocking a room is an out-of-service period.
_Avoid_: Work order (in UI copy), issue

## Subscriptions

**Plan**:
A priced offer Karafuu sells to organizations, setting the price per subscribed room for each billing period, the billing period, any property limit, and included modules.
_Avoid_: Package, tier, rate plan (that is a room price)

**Subscribed rooms**:
The number of rooms an organization chooses to pay for on its subscription. It is the most active rooms the organization may have, and each SaaS invoice charges the plan's price for each subscribed room.
_Avoid_: Room limit, seats, licences

**Subscription**:
An organization's agreement to one plan, with its trial, billing period and status.
_Avoid_: Account, licence

**SaaS invoice**:
A request for payment from Karafuu to an organization for one subscription billing period. Separate from guest folios, non-fiscal receipts and fiscal receipts.
_Avoid_: Folio, guest invoice, fiscal receipt

**Billing details**:
Karafuu's business name, address, contacts, TIN and VRN, and the bank and mobile-money accounts it accepts, kept by platform administrators. Each SaaS invoice keeps a copy of them from the moment it was issued.
_Avoid_: Company profile, bank details (when mobile money is meant too)

## Jurisdiction and compliance

**Jurisdiction profile**:
The set of legal rules a property operates under, set by where it is: required guest registration fields, tax rules and authority reporting. Zanzibar and mainland Tanzania are separate profiles.
_Avoid_: Country settings, region

**Zanzibar compliance pack**:
The capabilities a property under the Zanzibar jurisdiction profile needs before it may go live: ZAN Gateway reporting, VFMS fiscal receipts, and a live connection for every channel the property sells on.
_Avoid_: ZRA integration, fiscal module

**PMS supplier**:
The company registered with the Zanzibar Revenue Authority as the provider of the system a property uses. Karafuu is the PMS supplier for all of its properties.
_Avoid_: Vendor, integrator

**Booking report**:
A message to ZAN Gateway recording a reservation event: new, modified, cancelled, checked in or checked out.
_Avoid_: Sync, push, posting

**Booking report correction**:
A revision of a booking report confirmed rejected by ZAN Gateway, preserving the reservation event it reports. It is not a new reservation event or a replacement with an unrelated later state.
_Avoid_: Retry (for a correction), latest-state sync

**Reservation number**:
Karafuu's own identifier for a reservation: unique within its property, never reused, and kept for the whole stay. It is the identifier sent to ZAN Gateway.
_Avoid_: Booking ID, confirmation number

**Channel reference**:
The identifier a channel gives a reservation, such as the Booking.com reservation ID. Stored alongside the reservation number, never in place of it.
_Avoid_: External ID, OTA ID

**Star rating**:
A property's classification from the Zanzibar Commission for Tourism, or "not yet classified". ZRA sets the infrastructure levy from it.
_Avoid_: Category, grade

**Infrastructure levy**:
ZRA's flat charge per guest per night, set by star rating and assessed by VFMS. Shown on the folio as returned, never calculated by Karafuu.
_Avoid_: Infrastructure tax, tourism levy

**ZRA tax registration**:
A property's tax identifiers: TIN, VRN (when VAT-registered) and Z-number. The full Z-number is kept, including any suffix. A ZRA hotel code separately identifies the property with ZRA. Registration details may be completed during setup, before go-live in Zanzibar.
_Avoid_: ZTN, ZTN number location (the owner's labels for the full Z-number)

**ZRA hotel code**:
The authority's identifier for a property, separate from its TIN, VRN and full Z-number.

**Fiscal receipt**:
A receipt or check-out report issued through VFMS, carrying a VFMS QR code.
_Avoid_: Invoice, bill, folio

**Estimated tax**:
The VAT and infrastructure levy Karafuu's tax rules put on a Zanzibar folio before check-out. Replaced at check-out by the tax and levy VFMS returns, with any difference posted as a VFMS adjustment line (ADR 0015).
_Avoid_: Provisional tax, draft tax

**Fiscal check-out report**:
The guest's fiscal receipt for a whole stay: each guest's VFMS report number, room charges, extras, VFMS tax and levy, and a QR code for the VFMS viewer. Shown once VFMS has accepted every filing of the stay, including early guest departures. In Sandbox it is stamped "TEST, NOT FISCAL"; QR codes use only the configured ZRA test viewer, never the production portal. Identity documents appear only for staff with permission to view guest identity.
_Avoid_: Checkout invoice, bill, folio statement

**VFMS mode**:
Per property, **Sandbox** (the default: filings go to ZRA's shared test business unit, never the hotel's production unit) or **Live** (filed with ZRA's production VFMS under the hotel's own business unit). Only an Owner or Property manager switches it, with a reason. Sandbox reports are not fiscal.
_Avoid_: Test mode, demo mode

**Fiscal exception**:
A charge with no VFMS path, such as a cancellation or no-show fee, or a departed stay closed without filing. Listed with its reason for the property's accountant; an unfiled stay must be declared in the property's VAT return.
_Avoid_: Unreceipted charge, tax gap

**Close without filing**:
An audited, reason-required decision to close a departed stay whose VFMS filings never reached ZRA. It remains a fiscal exception for the property's VAT return, not a fiscal receipt.

**No-show**:
A reservation whose guest never arrived by the end of the arrival business date. Reported to ZAN Gateway as a cancellation with zero guests, but kept as a no-show in Karafuu.
_Avoid_: Cancellation (for a no-show)

## Distribution

**Channel**:
A source of reservations other than the property's own staff, such as Booking.com or the direct booking page.
_Avoid_: Source, OTA (when the channel is not an OTA)

**OTA**:
An online travel agency channel, such as Booking.com.

**Channel manager**:
A third-party service Karafuu connects to once, which relays rates, availability and reservations between Karafuu and many OTAs.
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
A connection between Karafuu and an OTA with no channel manager in between.
_Avoid_: Native integration
