# Batch 2 - Organization setup and onboarding

Implemented, browser-reviewed and validated on 18 November 2026. **Batch 2 is
complete.** The two page entries and four scope items are checked off; the
shared checklist does not complete the rest of the Batch 3 dashboard.

## Base and cleanup

- The checkout began clean at `7843cbc8` on `feat/mixed-room-type-booking`.
  Its PR #259 is merged; the local `origin/main` merge is `cfb14d43`.
  The preceding Batch 1 and desktop sidebar/page-spacing changes are included.
  GitHub's current main is also `cfb14d43`; its CI and Deploy runs succeeded.
- No tracked or untracked local changes needed discarding. No cleanup command,
  stash or deletion was performed.
- Delivery branch: `feat/ui-redesign-batch-2`, created from the verified
  `origin/main` base `cfb14d43` after implementation and browser validation.
- Ignored environment files, dependencies, scratch work, uploads and database
  files are retained. The local migration status was checked and was current;
  no development database migration, wipe or seeder was run.
- The page inventory remains 81 files. No routes, server props, policies,
  queries, creation actions or shared property-field definitions changed.

## Changed surfaces

- `organizations/create.tsx` reuses the existing authentication layout, full
  brand, appearance control and four-step progress indicator. Business and
  Property are draft stages of the same existing POST, not separate writes.
  Both fieldsets stay mounted so going back retains entered values. Native
  validation reveals the invalid stage before reporting the field; server
  errors return to Business for business fields and Property otherwise.
  Capture handlers cancel both native submission and propagation to Inertia
  when advancing a draft step or refusing an invalid submission.
- Setup follows the approved single-column, bounded form without the auth
  photo panel. Required property fields, optional tax registration fields,
  jurisdiction selection, timezone and currency still come from the existing
  `PropertyFields` and authorized server choices. Existing verification,
  atomic creation and go-live requirements are unchanged.
- The shared onboarding checklist uses flat separators, shell-owned gutters
  and a straight progress track. Empty item arrays produce a finite zero-width
  track. Existing next-action selection, permission-limited items, room
  substeps, tax confirmation, receipt notices, go-live gates and request/
  decline states are retained.
- `onboarding/import.tsx` separates upload and results, bounds the upload form,
  keeps results full-width, uses existing upload/download icons and freezes
  forms while processing. The CSV contract, property intent, invalid-row
  refusal, resumable commits, stored outcomes, errors and pagination remain
  unchanged.
- Public/auth CSS additions are scoped to setup. Other authentication pages
  retain their photo, dimensions, progress states and existing controls.

## Browser findings fixed

- A real offline setup submission originally raised an unhandled network
  error. Setup now reuses `useOnline` and the existing `preventOfflineWrite`
  listener, announces the offline state, and disables only the final creation
  button while disconnected. Draft navigation still works locally. Browser
  confirmation also attempts `requestSubmit()` directly: no request is sent,
  entered values survive, and reconnecting restores normal creation.
- Setup select triggers/native selects now match the 44px auth controls and
  16px input text. Coarse-pointer portal options and the logout action also
  meet the 44px target. Real touch emulation and keyboard focus restoration
  pass; no shared property-field or unrelated auth primitive changed.
- Checklist receipt/decline notices use straight edges, matching the approved
  operational treatment rather than retaining the shared Alert's auth radius.

## Automated verification

- `bun run test:frontend`: passed, including the new `OnboardingCheck.tsx`.
  It covers draft/final submission guards, business/property error recovery,
  mounted fields, theme-control presence, import empty/dry-run/partial/
  committed states, invalid-row commit refusal, property intent and pagination.
- Existing property checks also cover the shared property fields, checklist
  permissions/substeps and the empty progress track. Public/auth checks pass
  for the unchanged authentication family.
- Before browser review, `composer check:fast` passed with exit zero.
- After the browser fixes, `composer check:fast` passed build, 2,846 Pest tests
  (26 skipped; 49,192 assertions), Pint, PHPStan and TypeScript. Its final
  formatting stage caught newly generated browser evidence JSON; the evidence
  was formatted and the failed `bun run check` stage rerun successfully
  (300 files formatted; no lint warnings/errors in 224 files). Pest used in-memory
  SQLite. PHPStan emitted the existing non-blocking optional Turbo-extension
  warning.
- The 81-page inventory and `git diff --check` also pass.

## Browser verification

The shared T3 preview was tried first, in an isolated incognito tab. Two
snapshots failed with "The browser is not producing screenshot frames." The tab
was closed and testing switched to the already-installed Playwright/headless
Google Chrome tools. No browser package or application dependency was added.

Tests used `.scratch/batch2-browser.sqlite`, loopback port 8172, synthetic users,
and a private `.scratch/batch2-uploads` disk. No development database, real
guest, live email delivery, ZRA endpoint or fiscal service was used.

The [consolidated result](evidence/batch-2/browser-results.json) records **97
distinct passing checks with no remaining application JavaScript errors**:

- Eighty responsive/theme cases: Business, Property, checklist, empty import
  and populated results, each at 320/390/768/1440px in explicit Light against
  a dark device scheme, explicit Dark against a light device scheme, and
  System against both schemes. No page-level horizontal overflow or unlabelled
  visible text inputs was found. Long Zanzibar property names wrap on mobile.
- Native required/min validation, no early POST from Continue, preserved
  draft values and jurisdiction on Back/Continue, optional registration fields,
  and real returned business/property validation. A final real POST creates
  exactly one organization and one property; the unverified account stays
  behind the email-verification gate.
- Real deferred requests verify inert setup, upload and commit loading forms.
  Browser offline/recovery checks verify cancelled writes, retained draft
  fields/selected CSV, visible feedback and a usable reconnect path.
- Mainland readiness, real tax confirmation and go-live request; a synthetic
  declined request; Zanzibar registration/VFMS blockers; and a restricted
  reception member without tax-confirmation or go-live actions.
- Real invalid-file validation, invalid-row commit refusal, a 55-row dry run
  with server pagination, stored partial outcomes with Resume, and real
  committed outcomes without another commit button. Mobile results scroll
  horizontally with the keyboard to reveal the error column.
- Real property switching redirects to the dashboard, remounts the workspace
  and clears the unsaved CSV. The new import form carries the new property
  intent; visiting the other property's import returns HTTP 404.
- A real coarse-pointer context verifies 44px setup controls and portal
  options. Tab/Enter follow the field order, step changes focus the heading,
  and Escape returns focus to the select trigger.

This was one initial review/fix batch and one confirmation pass. The harness
needed targeted corrections for a reused synthetic source reference, the
intentional dashboard redirect on property switch, touch versus mouse input,
and asynchronous menu/focus timing. Those cases were rerun successfully; no
additional production UI changes followed the browser-fix batch. Raw runs and
diagnostics remain in `.scratch/batch2-browser-evidence`; the consolidated
record retains the corrected-case provenance.

## Representative evidence

- Business: [mobile Light](evidence/batch-2/business-mobile-light.png),
  [desktop Dark](evidence/batch-2/business-desktop-dark.png),
  [returned validation](evidence/batch-2/business-validation.png).
- Property: [desktop Light](evidence/batch-2/property-desktop-light.png),
  [mobile Dark](evidence/batch-2/property-mobile-dark.png),
  [offline](evidence/batch-2/property-offline.png),
  [loading](evidence/batch-2/property-loading.png).
- Checklist: [mobile Light](evidence/batch-2/checklist-mobile-light.png),
  [desktop Dark](evidence/batch-2/checklist-desktop-dark.png),
  [declined](evidence/batch-2/checklist-declined.png),
  [restricted member](evidence/batch-2/checklist-restricted-mobile.png),
  [Zanzibar mobile Dark](evidence/batch-2/checklist-zanzibar-mobile-dark.png).
- Import: [empty](evidence/batch-2/import-empty-desktop-light.png),
  [invalid rows](evidence/batch-2/import-invalid.png),
  [populated mobile Light](evidence/batch-2/import-results-mobile-light.png),
  [populated desktop Dark](evidence/batch-2/import-results-desktop-dark.png),
  [committed](evidence/batch-2/import-committed.png).

## Remaining limits and next batch

Testing used Chromium emulation, not physical devices, Safari, Firefox or a
screen reader. Browser fixtures start with explicitly verified test accounts;
real email delivery was not exercised. The decline/partial-import states were
synthetic stored states, not an external approval or interrupted production
job. Offline protection follows the browser's online signal; it is not a write
queue and does not guarantee server reachability while online.

No new MySQL run (no query/schema changes) or production deployment was
performed during validation. Delivery uses the Batch 2 branch and a PR against
`main`; CI, review, merge and deployment remain separate gates. Batch 3 -
Front desk, reservations and guest records - is the next bounded batch,
retaining real scoped data and the approved cards.
