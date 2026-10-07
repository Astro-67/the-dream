# Coding standards

Judgement calls for review. Mechanical rules are enforced by tests in `tests/Unit` (`CodingRulesTest`, `TenancyArchTest`, `PlatformArchTest`) and `tests/Feature/Tenancy/PropertyIntentCoverageTest.php`; those failures need no review comment.

Each rule cites its source. A rule here never overrides the source it cites.

## Layering

- **Actions own the work** (`docs/architecture.md` §3.3). An action receives already-authorized, validated input, opens the transaction, and writes its audit entry inside it. Authorization sits in a Policy; validation in a Form Request.
- **Controllers stay thin** (§3.2 rule 4). A controller authorizes, calls one action, and shapes the response. Eligibility rules, landing-context choices and multi-step logic belong in an action.
- **One owner per concept.** A rule, label or default lives in one place: grant rules in `ValidatesMembershipGrant`, jurisdiction labels on `JurisdictionProfile`, the default timezone on `Property`. Reuse the owner.

## Tenancy

- **Enter tenants through `Tenancy::within`** (ADR 0012). Cross-organization reads use `TenantContext::platform($reason)` with a reason a reviewer can read in the log.
- **Every new route has isolation coverage** (`tests/Feature/Isolation/Coverage/README.md`), with an `attempt` that tries organization B's records, not only a `visit`.
- **Refused sensitive actions are audited** with their result, like successful ones (spec #1, "Audit").

## Language

- **Use glossary terms** (`docs/GLOSSARY.md`) in code and UI copy, and avoid each entry's _Avoid_ words; the property type `hotel` and ZRA field names are domain identifiers and stay.
- **Interface text is translatable** (`docs/design.md`): server strings through `__()`.

## Tests

- **Assert observable outcomes** (spec #1, "Testing Decisions"): HTTP status, Inertia props, visible records. Tests survive refactors of internals.
- **Compare JSON-backed values by content.** MySQL returns JSON object keys in its own order: compare such arrays with `toEqual`, not `toBe`.
