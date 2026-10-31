# Plans bill per subscribed room the organization chooses

A plan's price is per subscribed room per billing period. The organization chooses how many rooms it subscribes to, at setup and later on its Subscription page; a platform administrator can change it too. That number is both the most active rooms the organization may have and what each SaaS invoice charges for. The invoice keeps the room count and the price per room it was issued with.

## Considered options

- **Flat price per plan with room and property limits.** What we had. Replaced at the owner's request: the price should follow the size of the hotel.
- **Bill the active rooms counted when the invoice is issued.** Rejected: the amount would change with every room added or deactivated, and organizations could not predict their invoice.

## Consequences

- A change of subscribed rooms counts at once for the room cap and from the next invoice for the price. There is no proration.
- Subscribed rooms cannot be set below the rooms already active; rooms are deactivated first, as with the old room limit.
- Property limits are optional and unset for now, so properties are unlimited.
- Existing subscriptions started on their active room count (at least 1). Invoices issued before this change have no room count and keep their flat amount.
