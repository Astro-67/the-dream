# Approved visual references

These are the selected design snapshots, not production screens. Open the HTML
from a checkout; Laravel, a database, dependencies and build output are not
required. Fonts are embedded and photographs/logos use relative paths to the
repository's tracked `public/` assets.

| Reference                                   | Approved content                                                                       |
| ------------------------------------------- | -------------------------------------------------------------------------------------- |
| [Landing B](landing.html)                   | Animated headline/hotel photographs, B's sections, real-price section, original footer |
| [Login](login.html)                         | Form/photo composition and the existing sign-in options                                |
| [Signup/setup](signup.html)                 | Clickable account, email, business, property and checklist states                      |
| [Front desk - light](front-desk-light.html) | Direct-access cards, distinct arrival/departure icons, flat operational workspace      |
| [Front desk - dark](front-desk-dark.html)   | The same layout and behaviour in the approved dark palette                             |

Both front-desk files include Light/Dark/System switching. The other references
follow the device's colour scheme using standalone fallbacks from the same app
palette. Layout and demo behaviour are preserved from the approved snapshots.
The rejected C landing and earlier front-desk sketches are not design targets.

Read only the reference for the current batch. View/render it rather than
dumping embedded base64 fonts into the agent context. Inline HTML tools may
require a temporary copy with image `src` paths resolved against the reference
file's directory; keep the tracked files checkout-independent.

Static contrast-detector exceptions are scoped only to the archived landing
and login HTML in [the detector config](../../../.impeccable/config.json).
This preserves the approved reference snapshots; it is not production contrast
approval. Verify rendered text and photo-overlay contrast during implementation.

## Reference data, not live behaviour

- Names, credentials, rooms, balances and business dates are synthetic.
- Steps may be explored freely here. Production email verification, permissions,
  atomic setup and go-live requirements must remain enforced.
- Preview actions do not create accounts, send verification emails, assign rooms,
  check guests in/out or save data.
- [Pricing snapshot](pricing-snapshot.json) is the public plan response captured
  during the design session: integer minor units, per subscribed room and billing
  period. The live page must load current plans; do not hardcode this snapshot.
- `hello@example.com` is the captured development contact placeholder, not an
  approved customer-support address. Production uses the configured contact.
- These files contain prototype-only inline DOM scripts and self-checks.
  Implement the visual target using the existing React/Inertia components.
