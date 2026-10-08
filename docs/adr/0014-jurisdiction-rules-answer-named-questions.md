# Jurisdiction rules answer named questions in one module

PRD §6 rules (required guest registration fields, allowed currencies, authority reporting, go-live gates) apply in at least six domains. Left to each domain, they become `if ($property->jurisdiction === Zanzibar)` checks scattered through the codebase, and adding a third profile means finding all of them.

We put **one `JurisdictionRules` module in the Tenancy domain**. It answers named questions about a property, such as `requiredRegistrationFields($property)` and `allowedCurrencies($property)`, with one implementation per profile (Zanzibar, mainland Tanzania). Domains ask it and never compare profiles themselves. Integration domains (ZanReporting, FiscalReceipting) still decide inside themselves whether an event produces work (ADR 0013), but they also ask the module rather than branching on the profile.

## Considered options

- **Profile checks in each domain, consolidated later.** Rejected: M2 is where the first questions appear, and consolidating later means a search through every domain.

## Consequences

- A Pest architecture test bans comparisons with the jurisdiction profile enum outside the module.
- New questions are added to the module when a domain first needs them, never speculatively.
- Property-configurable rules (for example mainland registration fields) are answered by the same module from property settings.
