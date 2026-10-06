# Connect to Booking.com through a channel manager first

Booking.com has paused onboarding of new connectivity partners with no reopening date ([connect.booking.com](https://connect.booking.com/)). Zanzibar properties cannot go live without a connection for every channel they sell on. Thedream therefore connects to Booking.com and other OTAs through a channel manager at launch. A direct connection to Booking.com stays an option if Booking.com reopens, and it would replace the channel manager for that OTA only.

## Consequences

- Choosing the channel manager is a launch dependency. The PRD sets the selection criteria: a PMS-facing API, reservation delivery fast enough for ZRA's 5-minute reporting window, Booking.com certification, card handling that keeps Thedream out of PCI scope where possible, and ZRA acceptance.
- Using a channel manager does not move the ZRA reporting duty off Thedream (ZAN GW guide §3.4). See [0003](0003-thedream-is-the-single-zra-pms-supplier.md).
