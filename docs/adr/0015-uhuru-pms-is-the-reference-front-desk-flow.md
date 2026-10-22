# Uhuru PMS is the reference front-desk flow

The project owner runs hotels in Zanzibar and asked for changes (October 2026) that match UhuruPMS, the only PMS ZRA has accepted for VFMS filing so far. Where Thedream's earlier design differs from Uhuru on the front-desk flow, settings or VFMS behaviour, we follow Uhuru, because ZRA has already accepted it and staff already know it.

This replaces three earlier positions:

- **No booker.** A reservation takes the lead guest inline. The only other party is an optional tour operator (name, ZRA registration number, what it pays for), which is what VFMS needs. Existing booker records are kept, not deleted.
- **Staff may overbook** up to a per-property percentage, zero by default.
- **Automatic check-out** runs daily at the property's time, with property switches for unpaid folios and overstays, and emails a summary. Night audit still never checks guests out.

For Zanzibar properties, the tax and infrastructure levy VFMS returns are what the folio shows. Thedream's tax rules only estimate them before check-in.

## Considered options

- **Keep the PRD's design and explain the differences to the owner.** Rejected: the owner is the product owner, and Uhuru's flow is the one ZRA has accepted.

## Consequences

- When a front-desk or VFMS question is open, check how Uhuru does it before designing.
- Where Uhuru is wrong, we do not copy it: its bill left out a filed extra charge that VFMS still taxed.
- Property managers take payments, as Uhuru's manager does (#144): they hold `charges.post` in their own properties, which also lets them post charges, open folios and transfer charges. Refunds, corrections, write-offs, guest identity and staff administration stay with the roles that already hold them.
