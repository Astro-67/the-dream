# OTA stays are receipted as DIRECT in VFMS

VFMS accepts only two accommodation sources: `DIRECT`, where the guest pays all charges, and `TOUR OPERATOR`, which requires the operator's ZRA registration number (VFMS API §2.1). Booking.com has no ZRA tour-operator registration and tells hotels to invoice the guest, in the guest's name, for the full reservation amount, even when Booking.com collected the payment. Karafuu therefore sends OTA stays to VFMS as `DIRECT`, with the guest as payer and the full tax-inclusive price. The channel's commission never appears on the fiscal receipt.

This is Karafuu's reading of the VFMS guide, not a ruling from ZRA. If ZRA later defines an OTA source value, this decision is superseded.
