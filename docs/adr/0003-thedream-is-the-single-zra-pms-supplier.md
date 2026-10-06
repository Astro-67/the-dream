# Thedream is the single ZRA PMS supplier for all properties

ZRA issues one ZAN Gateway API key per PMS supplier and identifies the supplier from the key, not from the request (ZAN OpenAPI, `/hotel-info`). The supplier is responsible for the completeness, accuracy and timeliness of every property's booking data (ZAN GW guide §3.4). Thedream registers as the PMS supplier, holds the one key as a platform secret, and sends booking reports for all organizations through one central delivery service. Properties do not enter their own ZRA credentials.

## Consequences

- One rate limit is shared by every tenant, so booking reports are queued centrally and batched per property.
- Thedream carries the compliance risk for every Zanzibar property and needs a named owner for ZRA registration and compliance.
