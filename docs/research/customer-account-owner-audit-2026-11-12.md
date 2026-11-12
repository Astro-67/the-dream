# Customer account folio-owner audit (#251)

Every folio belongs to exactly one reservation or customer account. The database
check enforces this on SQLite and MySQL; composite tenant foreign keys prevent an
account folio or issued document from naming another property's account.

| Consumer                                         | Account behaviour                                                                                                                                                                                                                     |
| ------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `PostExtraCharge`                                | Locks the owner before the folio; account items stay held, without a reservation lifecycle event. Closed accounts refuse new items.                                                                                                   |
| `RecordPayment`                                  | Same owner lock; payment, cashier shift and non-fiscal receipt paths are reused. No channel-collected payments or stay estimate. The account identity is stored in the payment-time snapshot.                                         |
| `ReceiptView`, receipt page                      | Nullable stay; account customer and account source link replace the reservation fields.                                                                                                                                               |
| `VfmsAdvance`, `FileAdvancePayment`              | Account payments are not deposits and have no advance-filing offer. An attempted stay-only advance route cannot bind an account to a reservation.                                                                                     |
| `IssueGeneralReceipt`, adapter, recovery         | The existing issuer accepts the account's held-posting query and records on the account's tenant-specific stream. Acceptance projects `customer_account_id`, never a fabricated reservation. Re-file uses the same stream and claims. |
| `OpenFolios`, pre-archive check                  | Property-specific balances include both owners. Open accounts block jurisdiction changes even when empty. Held fiscal extras block archiving after payment; settled demo and Mainland accounts need no ZRA receipt.                   |
| `FolioStatement`                                 | A reservation-specific entry point, unchanged. The account page itemises account folios instead; account receipts link there.                                                                                                         |
| Cashier shift                                    | Sums cash postings by shift, not reservation; account payments enter the existing totals.                                                                                                                                             |
| Receipts register                                | Includes account non-fiscal and general receipts, customer names and source links.                                                                                                                                                    |
| Reversal, refund, transfer, approvals            | No mandatory reservation lookup for account payments. Transfers compare both owner keys. Owner locking serializes corrections with account close; reversing a receipted account item remains a fiscal exception.                      |
| Open-balances and room/departure finance reports | Their reservation joins intentionally remain stay-only. Day-use and event balances are in their paginated account list and account pages. Payment totals already include all folios.                                                  |
| `PropertyPurge`                                  | Schema-derived deletion order includes accounts and their sequence rows, after dependent folios, postings and receipts. Covered by a demo-purge test.                                                                                 |

No restaurant menus, venue calendars, separate VFMS units, new roles or alternative
filing pipeline are added. Fiscal print and register-tab redesign belongs to #252/#253.
