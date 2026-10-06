# Thedream never receives raw card data from channels

Booking.com can send full card numbers and security codes for hotel-collect reservations, but only to systems with PCI and PII compliance. Thedream does not accept raw card data from any channel. Cards stay in the channel manager's secure card storage or on the Booking.com Extranet, and staff see only masked details. This keeps Thedream out of PCI scope and keeps the rule that raw card details are never stored.

## Consequences

- Card handling is a hard requirement when choosing the channel manager: it must store cards securely and give the property a way to charge or view them without Thedream holding the data.
- Virtual cards from Payments by Booking.com are charged outside Thedream, on a card terminal or the Extranet.
