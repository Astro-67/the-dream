# Batch 1 - Public site and authentication

Implemented and reviewed on 13 November 2026. This is the production
implementation of the approved references, not another concept round.

## Base and dependency

- Work stays in the separate `feat/ui-redesign-batch-1` worktree.
- The initial base includes the approved handoff at `2fe909f4`.
- Isolated implementation: `f97b6db6`.
- Finished Batch 0 foundation: `d9cf9ef94408dbdf67e4f9953d8a0a6b711ce878`,
  integrated by merge commit `fc6a4fdd`. Both Batch 0's connection check and
  Batch 1's public/auth check are retained in `test:frontend`.
- Batch 0 is PR #256. Batch 1 is stacked on `feat/ui-redesign-batch-0` while
  that PR remains unmerged. Retarget to main after the foundation merges.
- No project-root edits, stash, staging, commits or thread interruption were
  used to acquire the foundation. Only its finished committed branch was merged.

## Changed surfaces

All nine inventoried Batch 1 pages are implemented:

- Landing B: full brand, animated hotel-photo/headline hero, connected guest
  journey, keyboard-operated product-area radios, team, pricing, FAQ, closing
  action and the original copyright / Pricing / FAQ / Contact footer.
- Login and the shared authentication layout: focused form, approved wide-screen
  hotel photograph, responsive single-column mobile form and existing theme
  control. The form family also covers confirmation, forgotten/reset passwords,
  invitations, account registration, email verification and two-factor challenges.
- Registration and verification retain the account/email progress presentation.
  Business and Property are non-interactive future-step labels, not implemented
  setup screens or verification shortcuts.
- Authentication-only password controls preserve input attributes and server
  rules, with keyboard-operable visibility buttons. Error messages are associated
  with their fields; loading forms are inert and submit controls retain spinners.
  The existing shared password component outside this family is untouched.

Styling is confined to public/auth layouts, dedicated components and scoped CSS.
No operational shell, shared primitive, global theme CSS, foundation test,
authentication endpoint, authorization rule, organization/property write or
database schema is changed by Batch 1.

## Real behavior

- Prices still come from `WelcomeController`'s plan props and `formatMoney`.
  Each price identifies its currency, subscribed-room basis and monthly/yearly
  period. Trial lengths are plan-driven; zero-day plans do not advertise a
  zero-day trial. Unpublished offers say "Talk to us"; no plans shows an honest
  contact state. Email links use configured `contactEmail`.
- All real Fortify forms, routes, token/email transforms, password policy props,
  remember-me input, password reset access, passkey capability checks, invitation
  refusal/acceptance states, verification resend/logout and two-factor/recovery
  modes remain intact.
- The hero pauses on user request, when offscreen, when the page is hidden and
  when reduced motion is preferred. Reduced motion displays one stationary photo,
  unanimated readable text and no pause control. Image frames reserve their size.
- Existing English UI conventions, General Sans / DM Sans, full light/dark logos,
  semantic tokens and Light/Dark/System infrastructure are reused. No dependency,
  generated marketing image, dashboard screenshot or prototype DOM script is added.

## Automated validation

After integrating Batch 0:

- `composer check:fast` passed: build; **2,837 Pest tests passed, 26 skipped,
  49,106 assertions**; Pint; PHPStan; TypeScript; repository formatting/lint.
- `bun run test:frontend` passed, including the inherited foundation checks and
  new `PublicAuthCheck.tsx`.
- Focused pre-integration landing/auth/invitation tests passed:
  **62 tests, 555 assertions**.
  The complete integrated Pest run includes those same tests.
- Final targeted build, TypeScript, public/auth frontend assertions and formatting
  passed after preserving caller-supplied password input classes and removing
  references to absent password-error elements.
- `git diff --check` passed. The 81-page inventory remains intact; exactly nine
  Batch 1 pages are checked. No other batch's checkboxes change.
- The scoped mechanical design scan returned no findings. Visual review was
  performed in-thread; no independent subagent review was requested or performed.

Builds/tests use disposable SQLite. Browser fixtures live in a separate
`.scratch/browser.sqlite`. The development database was not migrated, wiped or
reseeded. The preview server uses `--no-reload` so Laravel retains the explicitly
supplied SQLite environment; the worktree's ignored `.env` also points at SQLite.
PHPStan's optional local Turbo-extension startup warning is non-blocking.

## Browser validation and evidence

Actual production routes, not standalone mockups, were reviewed in installed
Chrome through Playwright after integration:

- All nine pages at **1280 x 900** and **390 x 844**, in Light, Dark and System.
  System responds to both device schemes; explicit Light/Dark remain explicit.
  Font, image loading and horizontal overflow assertions passed.
- Real registration password-policy errors, required email-verification redirect,
  resend status, logout, login errors, password-confirmation errors, invitations
  with long organization names, invalid recovery code and successful recovery
  sign-in were exercised. No organization/property setup submission was made.
- Password visibility works with the keyboard; login tab order is natural.
  Product-area radio arrow keys, FAQ disclosure, hero pause/play and offscreen
  pausing passed. Native reduced-motion media emulation passed.
- Loading was tested with a held real login request: disabled submit, spinner and
  inert form, followed by the real server validation response.
- Unsupported passkeys were tested with the browser capability absent.
  Credential cancellation was simulated and its existing error UI was verified;
  no physical authenticator was available.
- Real browser offline login preserves entered values, stays on the form, shows
  no false success and re-enables retry after the failed request. Existing
  operational offline-write protection remains in Batch 0, unchanged.
- Additional checks cover password-reset email status, reset validation and an
  invalid authenticator code. The validation fixtures use a valid-length test MFA
  secret; the factory's short rendering-only secret is not a usable authenticator.

The main browser run records **66 surface/state results**, with five additional
state checks. Page-visibility handling was verified by dispatching visibility
events with a simulated `document.hidden` value; native background-tab lifecycle
behavior was not independently verified.

T3 Preview was tried first. Both freeform and device-preset resizing returned a
390px setting but a 1280 x 800 viewport; local browser automation was used for
reliable responsive/state verification. Temporary automation and complete captures
remain in the worktree's ignored `.scratch/`.

Representative captures use disposable test data, not customer data:

- [Landing, desktop Light](evidence/batch-1/landing-desktop-light.png)
- [Landing, mobile System/Dark](evidence/batch-1/landing-mobile-system.png)
- [Login, desktop Dark](evidence/batch-1/login-desktop-dark.png)
- [Login, mobile loading](evidence/batch-1/login-mobile-loading.png)
- [Login, mobile validation](evidence/batch-1/login-mobile-validation.png)
- [Account, mobile Light](evidence/batch-1/register-mobile-light.png)
- [Verification, desktop Dark](evidence/batch-1/verify-email-desktop-dark.png)
- [Two-factor challenge, mobile Light](evidence/batch-1/two-factor-mobile-light.png)
- [Long invitation, mobile System/Dark](evidence/batch-1/invitation-mobile-system.png)
- [Main browser results](evidence/batch-1/browser-results.json)
- [Additional state results](evidence/batch-1/state-results.json)
- Before: [pre-redesign production login](evidence/batch-0/after-login-desktop-light.png).

## Remaining limits and next batch

- No merge or production deployment is performed. Batch 0's PR must land before
  this stacked PR can merge independently.
- Physical-device review, physical passkey success and native background-tab
  lifecycle review remain manual checks. No new MySQL run is needed for this
  presentation-only change; no schemas or queries change.
- Batch 2 is next: organization/first-property setup and onboarding. Preserve the
  existing atomic creation action, required fields, verification and go-live gates.
