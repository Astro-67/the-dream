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
- Batch 1 was initially stacked on Batch 0's PR #256. That dependency merged
  into main as `39975870`; PR #257 now targets main.
- No project-root edits, stash, staging, commits or thread interruption were
  used to acquire the foundation. Only its finished committed branch was merged.

## Changed surfaces

All nine inventoried Batch 1 pages are implemented:

- Landing B: full brand, animated hotel-photo/headline hero, connected product
  workflow, keyboard-operated product-area radios, team, pricing, FAQ, closing
  action and the original copyright / Pricing / FAQ / Contact footer.
- Login and the shared authentication layout: focused form, approved wide-screen
  hotel photograph, responsive single-column mobile form and existing theme
  control. The form family also covers confirmation, forgotten/reset passwords,
  invitations, account registration, email verification and two-factor challenges.
- The mobile "Back to website" link is icon-only below 760px, retaining its
  accessible name and a 44px tap target. Desktop keeps the text.
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
  period. Only actual configured plans are advertised; there are no invented
  Growth or Group / Enterprise offers. Trial lengths come from each plan;
  zero-day plans say "No trial included". Only an empty plan list shows the
  contact-pricing fallback. Email links use configured `contactEmail`.
- All real Fortify forms, routes, token/email transforms, password policy props,
  remember-me input, password reset access, passkey capability checks, invitation
  refusal/acceptance states, verification resend/logout and two-factor/recovery
  modes remain intact.
- The hero pauses on user request, when offscreen, when the page is hidden and
  when reduced motion is preferred. Reduced motion displays one stationary photo,
  unanimated readable text and no pause control. Image frames reserve their size.
- Existing English UI conventions, General Sans / DM Sans, full light/dark logos,
  semantic tokens and Light/Dark/System infrastructure are reused. No dependency,
  newly generated image, dashboard screenshot or prototype DOM script is added.
  The user-supplied replacement imagery is recorded below.

## Supplied photography update

On 13 November 2026 the owner supplied the complete replacement set and explicitly
superseded the earlier existing-assets-only restriction. All four files were opened
and inspected before integration, then copied byte-for-byte into
`public/images/marketing/`. No additional images were generated or recompressed.
The supplied people are intentionally included for this approved Batch 1 set;
these scenes are illustrative, not actual customer/property evidence or
testimonials. A generation model or prompt was not supplied.

Source directory: `/home/astro/.t3/userdata/attachments/`. Each source basename
starts with `1027c2d0-6fdd-488c-a076-3bd0ef215759-`, followed by the suffix below.

- **Reception:** `coastal-reception-welcome.webp`, 1448 x 1086, 94,082 bytes.
  Source: `7bb846cc-0f81-497b-9851-4798718f5988.webp`.
  SHA-256: `724d428aa2e0c3c9868ad253537fb854ee1995859718efb5cda9bf67a056b5af`.
  Used for hero scene 1 and the existing Front desk / Billing supporting slots.
- **Prepared room:** `coastal-prepared-room.webp`, 1448 x 1086, 146,138 bytes.
  Source: `60d6d485-f623-4677-8024-172c3fd91c57.webp`.
  SHA-256: `bf35a88af99d13caf2623da7dba9bdb18e9d996f1ffe986d676813b42cc91fe8`.
  Used for hero scene 2 and the existing Room readiness supporting slot.
- **Coordinated team:** `coastal-team-veranda.webp`, 1448 x 1086, 105,940 bytes.
  Source: `ccc59f77-6245-42ba-8f6c-ca02f659a5a5.webp`.
  SHA-256: `e56309f98f5a27d7884e0941dc3711664ed73311d7d23b2a101859c80b550cf3`.
  Used for hero scene 3 and the existing team section.
- **Carved doorway:** `coastal-doorway-ocean-portrait.webp`, 1024 x 1536, 175,302 bytes.
  Source: `9c17d6d9-053a-4c80-a7eb-f81e61d21982.webp`.
  SHA-256: `13fcd5d490bd3867875cd1d313e7a72ffd6fb49cb1b6b0c53f23650f1b0c256d`.
  Used by the shared login/signup/authentication photo panel.

Every caller of the previous marketing assets was grepped first. Their files,
approved HTML archives, and historical captures remain unchanged; descriptive
new filenames isolate this update from later batches and archived references.
The asset test verifies each file's exact source hash, dimensions, WebP format
and size below 350 KB.

The landing keeps its headline beside the photograph on wide screens and the
sequence welcome -> prepared room -> coordinated team. Its photo frame scales
with its column, with a reception-specific focal point; fades retain their
existing timing, pause, offscreen, visibility and reduced-motion safeguards.
Zoom was removed so people are not trimmed during transitions. The auth panel
keeps the supplied portrait's proportions rather than stretching with a long
form; its existing overlay mechanism now applies localized shading over the
sunlit floor behind the copy. Alt descriptions match the actual new scenes.
The imagery itself does not add sections or change pricing, footer,
authentication behavior or global themes.

### Administrator-driven pricing correction

The owner's urgent pricing feedback exposed a client-only fallback: the page
appended invented Growth and Group / Enterprise offers to the real plan list.
Those cards are removed, along with unconditional free-trial/full-access/no-card
claims in the hero, pricing intro and FAQ. The original footer is unchanged.

`WelcomeController` already queries current Plan records on every request and
exposes only name, integer price, currency, billing period and trial days. That
allowlisted DTO is unchanged. There is no separate publication flag in the
current plan model/admin form: configured rows are the public offers. No new
publication state, billing calculation, entitlement or private configuration is
introduced. Prices still use `formatMoney`, including whole-unit TZS, and state
the subscribed-room/billing-period basis. Account CTAs are neutral because the
registration route does not select the plan displayed on an individual card.

The frontend regression was observed failing before the fix, then passed with
two configured currencies/periods, zero/positive trial terms, replacement props,
exact card counts and an empty-plan contact state. A backend regression submits
the authorized platform administrator update route, forgets the authenticated
guard, then makes a fresh anonymous landing request and checks the complete
public plan DTO. An additional regression verifies an empty catalogue stays
empty. Existing plan-admin validation, authorization and billing tests remain.

Actual browser verification used a separate disposable pricing database and real
admin login plus recovery-code authentication, not a bypass. Saving EUR 321.09
per room/year with zero trial days in the platform form appeared on fresh guest
requests at desktop/mobile in Light/Dark/System; no invented cards or trial
promises appeared. A subsequent five-day admin trial update was reflected on
reload. Removing the disposable plans showed only the configured email fallback,
without a stale price. These **nine pricing results** are recorded with the
refreshed 90-result imagery/header matrix below.

### Owner's landing refinements

The follow-up phrase "repetition of ticket" is provisionally interpreted as the
repeated decorative tick/checkmark treatment, not a request to remove product
information. General feature lists now use aligned text without checks; checks
remain on actual pricing inclusions. The repetitive Reservations / Front desk /
Housekeeping / Billing journey strip was removed because the same workflow is
explained in the retained hero and product sections. Product, security, legal and
pricing details remain. The owner can correct this interpretation if "ticket"
referred to different wording.

Only the landing masthead is revised: full logo aligned with the hero at left,
Product / Your team / Pricing in the desktop navigation, quiet login, one clear
get-started action and restrained theme control. Mobile uses the existing Radix
dropdown primitives for named menu controls, 44px targets, keyboard operation,
Escape and focus return. It is not a new floating header capsule or boxed toolbar.
Landing-specific selectors isolate all `.public-topbar` / `.public-brand`
overrides; approved auth headers and operational navigation do not change.

The hero now uses the already-installed **DM Sans 500**, not the previous heavy
General Sans treatment. Its 38-60px responsive scale, 1.14 leading and -0.02em
tracking are scoped to the hero. "Run the day. Welcome the guest." is unchanged;
two phrase-level spans wrap naturally instead of forcing three oversized word
lines. Auth and operational heading typography remains General Sans.

The pause/play control is an icon-only shared Button with decorative Lucide
Pause/Play icons, contextual accessible name, `aria-controls`, `aria-pressed`,
visible keyboard focus and a minimum 44px target. No visible Pause/Play word or
underlined text control remains. Animation timing, user pause, page visibility,
offscreen handling and reduced-motion behavior remain intact.

### Combined imagery and landing verification

The final combined browser matrix covers **90 surface/state results** at
1280 x 900, 1024 x 900, 900 x 900, 390 x 844 and 320 x 800 in Light, Dark and
System. A separate native reduced-motion check covers the new mobile menu too.

- The hero uses DM Sans 500 at 38-60px: two lines from 390px upward and a natural
  three-line wrap at 320px. The headline remains beside the photograph on desktop.
  Masthead logo/actions align with the hero, visible header targets are at least
  44px, and the auth heading remains General Sans 600.
- All three actual images were inspected at their production CSS animation
  phases. The reception and both colleagues stay in frame, with no scale/zoom.
  The crossfade, icon-only keyboard toggle, native reduced motion, supporting
  photo mappings and original footer passed.
- The mobile menu opens by keyboard, retains 44px items, closes on selection or
  Escape, restores focus and navigates to Pricing. Native reduced motion disables
  its opening animation as well as the hero animations. System responds to both
  device schemes; explicit theme selections remain explicit.
- Actual offscreen scrolling pauses the hero and returning resumes it.
  Simulated page-visibility events also pause/resume correctly; native
  background-tab lifecycle remains a separate manual check.
- The doorway photo preserves its complete horizontal source extent, including
  both carved doors, on login and signup at all three desktop widths. Auth
  photography remains hidden on mobile as before.
- Overlay contrast was measured using the actual WebP, computed localized
  gradient and computed foreground colors, sampling each text bounding box on
  a conservative 2px grid. Across **63 text-region measurements**, the minimum
  ratio is **8.97:1 for headings** and **10.94:1 for small copy**, above 3:1 and
  4.5:1 respectively. The sunlit floor is not relied on without shading.
- No browser JavaScript errors or horizontal overflow were recorded. Physical
  devices, physical passkey success and native background-tab lifecycle remain
  outside this follow-up's verification.

T3 Preview was used first; two saved captures remained visibly blurred despite
loaded fonts and no CSS filter. Installed Chrome/Playwright supplied the sharp
captures and native media emulation used for the combined review. Initial
inspection found a stretched portrait trimming the doors on narrow signup
screens; retaining the portrait aspect ratio resolved that crop before the
combined pass. The complete temporary automation remains in `.scratch/`.

Current representative production captures, using the supplied illustrations:

- [Desktop landing and retained footer](evidence/batch-1/imagery-refinement/landing-desktop-light.png)
- [Welcome scene and landing masthead](evidence/batch-1/imagery-refinement/hero-1-desktop-light.png)
- [Prepared-room scene](evidence/batch-1/imagery-refinement/hero-2-desktop-light.png)
- [Coordinated-team scene, mobile System](evidence/batch-1/imagery-refinement/hero-3-mobile-system.png)
- [Mobile menu and clear get-started action](evidence/batch-1/imagery-refinement/mobile-menu-system.png)
- [Native reduced-motion hero](evidence/batch-1/imagery-refinement/hero-reduced-motion-mobile.png)
- [Login portrait and localized shading](evidence/batch-1/imagery-refinement/login-desktop-light.png)
- [Mobile auth header remains unchanged](evidence/batch-1/imagery-refinement/login-mobile-dark.png)
- [Signup portrait in Dark](evidence/batch-1/imagery-refinement/register-desktop-dark.png)
- [Narrow signup retains carved doors](evidence/batch-1/imagery-refinement/register-narrow-light.png)
- [Combined result summary and contrast measurements](evidence/batch-1/imagery-refinement/checks.json)
- [Actual admin-updated pricing, desktop Light](evidence/batch-1/imagery-refinement/pricing-1280-light.png)
- [Actual admin-updated pricing, mobile Dark](evidence/batch-1/imagery-refinement/pricing-390-dark.png)
- [Empty catalogue, mobile contact fallback](evidence/batch-1/imagery-refinement/pricing-empty-mobile.png)

### Follow-up automated validation

- Focused landing/auth/invitation suite: **66 passed, 579 assertions**, including
  all four source-byte/dimension/format/size checks for the supplied WebPs.
- After the pricing correction, focused landing/subscription-admin coverage:
  **24 passed, 233 assertions**. The public/auth frontend regression and full
  frontend command pass.
- Final serialized `composer check:fast` **exits zero**: build; **2,843 Pest
  tests passed, 26 skipped, 49,165 assertions**; Pint; PHPStan; TypeScript;
  repository formatting/lint. Its disposable database was prepared before the
  build, with no overlapping asset generation or evidence writes during tests.
  The frontend assertions cover source mapping, removal of general
  feature ticks, retained pricing checks, scoped DM Sans 500, icon-only control
  semantics, mobile menu naming and reduced-motion rules.
- An earlier `composer check:fast` combined run reached a
  formatting finding in the newly generated evidence JSON; after formatting that
  file, `bun run check` passed with all 297 files correctly formatted and no lint
  warnings/errors. The final clean pipeline above supersedes that failed command,
  rather than treating it as a pass. Remote CI/merge status is tracked on the PR.
- An earlier attempt overlapped a rebuild with Pest, invalidating font-manifest
  files in two requests. The 40 affected guest-access/night-audit tests then
  passed (356 assertions), and serialized full-suite verification passed.
  Builds and PHP tests must not run concurrently in the same output directory.
- The scoped mechanical design scan returned no findings. No approved HTML,
  original marketing asset, global theme, operational typography or foundation
  test was changed by this update.

## Original Batch 1 automated validation

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

## Original Batch 1 browser validation and evidence

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

- Track merge and deployment status through
  [PR #257](https://github.com/Astro-67/the-dream/pull/257) and its workflows.
  It now targets main after #256 merged, enabling the repository CI that did not
  run on the original stacked base. Local integrated checks pass.
- Physical-device review, physical passkey success and native background-tab
  lifecycle review remain manual checks. No new MySQL run is needed for this
  presentation-only change; no schemas or queries change.
- Batch 2 is next: organization/first-property setup and onboarding. Preserve the
  existing atomic creation action, required fields, verification and go-live gates.
