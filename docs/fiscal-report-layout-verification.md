# Fiscal check-out report layout verification (#222)

Verified on 2 November 2026 against the merged #220 letterhead and #221
verification blocks, after fetching and merging
`origin/spec/219-property-branding` (already up to date).

## Scope and environment

- Production changes stay in the existing report component. No fiscal
  calculations, routes, stored destinations, permissions or ordinary receipts
  change.
- Ran `scripts/worktree-setup.sh` with explicit scratch SQLite overrides.
  Replaced the worktree's copied MySQL settings with
  `.scratch/build.sqlite` before subsequent Artisan commands. Pest uses its
  configured SQLite `:memory:` database. No shared development database writes.
- Browser fixtures render the actual report component through the existing
  Inertia rendering seam, with compiled application CSS and locally served
  project fonts. These are representative synthetic report props, not live
  customer data or an authenticated end-to-end browser session.

## Screen and print evidence

Evidence lives in the main checkout's ignored
`.scratch/spec-219/evidence/` directory.

- `browser-checks.json`: all 30 combinations of five fixtures, 320px / 390px /
  1280px widths and light / dark themes passed computed-layout assertions.
  Checks cover document overflow, centered headings, left-aligned stay details,
  right-aligned tabular amounts, the total divider and 600 weight, proportional
  logos, QR clear space, unchanged links and break avoidance.
- `desktop-*.png` and `mobile-*.png`: representative screenshots of no branding,
  a wide logo, a tall logo, long property/address/contact/identifier content,
  financial hierarchy and distinct verification blocks.
- `no-logo.pdf`, `wide-logo.pdf`, `tall-long.pdf`, `multipage.pdf` and
  `fallback.pdf`: Chrome Save as PDF output, A4 with 12mm margins. The
  multipage fixture contains 20 guest reports and one service receipt across
  four pages. Other fixtures exercise absent branding, proportionally wide
  and tall logos, long references, early departure and the fallback QR.
- `print-*.png`: representative PDF rasterizations inspected for black-on-white
  text, unclipped content and code/caption/reference grouping.
- `pdf-qr-checks.json` and `pdf-qr-checks.log`: libzbar decoded all 31 QR
  occurrences from 150dpi PDF rasterizations to the expected fixture
  destinations. Every decoded code has its caption and reference on the same
  page. PDF text bounding boxes stay inside the print margins.
- `render-fixtures.tsx` and `check-pdfs.py` record fixture generation and the
  assert-based PDF checks. Screen checks used T3's headless Chrome 154;
  PDF export used locally installed headless Chrome 156.

## Regression checks

- Existing report-rendering checks first failed for the new accessible summary,
  semantic total emphasis and named stay details, then passed after each slice.
  They do not assert exact utility-class strings.
- After the base merge, `bun tests/Frontend/CheckoutReportCheck.tsx` passed;
  `php artisan test --compact tests/Feature/FiscalReceipting/CheckOutVfmsTest.php`
  passed all 28 tests and 676 assertions.
- Ran `composer check:fast` once after that merge: build, Pest (2,421 passed,
  19 skipped; 40,810 assertions), Pint, Larastan, TypeScript and formatting/lint
  passed. Full output is in `check-fast.log`. Optional Fontaine and PHPStan
  turbo-extension warnings did not prevent their checks from passing.
- The file-scoped Impeccable `broken-image` exception covers regex assertions
  in the rendering test, not production images. No production design findings
  were suppressed.

## Not verified

No physical printer, paper scan, phone camera scan, live ZRA viewer or
authenticated browser workflow was exercised. Software decoding of exported
PDFs is not a claim about physical print quality. MySQL checks were not run:
this ticket changes neither persistence nor queries. The full fast suite's
19 skipped tests remain skipped.
