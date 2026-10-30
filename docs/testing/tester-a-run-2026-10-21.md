# Tester A run: 21 October 2026

Status: IN PROGRESS. Owner email verification, organization creation, inventory and rate setup passed. A pre-live booking was saved, correctly refused check-in, then voided. The browser is at owner Security with the two-factor setup dialog open; the tester must finish authenticator setup before property settings and activation can continue.

Guide: `tester-a-full-flow.md`. Target: staging at https://pms.sauinndream.com. Deployed commit: `5f0f38c`.

## Approved reset and evidence

- The user explicitly approved keeping both Ibrahim and Khalfan.
- Database: `sauinndr_pms`. Reset committed at 12:12:24 EAT on 21 October 2026.
- Method: maintenance mode, private full SQL backup, then transactional deletion and restoration of the approved authentication records and Essential plan. The schema was not rebuilt, no demo-user seeder ran, and the app encryption key was not changed.
- Backup: `/home/sauinndr/.thedream-private-backups/20261021T091220Z-46545fe3/database.sql.gz`, outside the web root with restricted permissions.
- Backup SHA-256: `60f329764627646792778f594de3d4b9da63ad7c38027250b662b6b95f92d3a3`.
- Backup checks passed: dump command exit, nonempty output, gzip integrity, all 71 table definitions and completion marker. A restore rehearsal was not performed.
- Passwords, verification state, encrypted two-factor settings, recovery codes and retained passkeys were compared exactly before the reset committed. Secrets were not printed or copied into this report.
- No queued or outbox work was present. No background artisan worker/scheduler was active when the reset began. Maintenance mode was exited successfully.
- Uploaded files and backups were not deleted; the approved reset covered the database only.

## Verified baseline

| Record type                     | Count immediately after reset                           |
| ------------------------------- | ------------------------------------------------------- |
| Users                           | 2: ibrahimsa477@gmail.com and khalfanmakame96@gmail.com |
| Platform role assignments       | 2, both platform administrators                         |
| Passkeys                        | 1, preserved for a retained administrator               |
| Plans                           | 1, existing default Essential / USD 49.00               |
| Migration records               | 73, unchanged                                           |
| Organizations / properties      | 0 / 0                                                   |
| Guests / reservations           | 0 / 0                                                   |
| Folios / financial postings     | 0 / 0                                                   |
| Tenant subscriptions            | 0                                                       |
| Jobs / outbox messages          | 0 / 0                                                   |
| Other existing database records | 0 before application traffic resumed                    |

All 71 application tables were checked inside the reset transaction. A separate new database connection then confirmed the two surviving accounts, both confirmed two-factor timestamps and zero business records. Runtime sessions, cache entries or new scheduler audit entries may reappear normally after reopening the site.

## Observed test results

| Check               | Status  | Observed result                                                                                                                                                                                                                                                                           |
| ------------------- | ------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Baseline            | PASS    | Reset and independent post-reset verification completed as recorded above.                                                                                                                                                                                                                |
| PF-01               | PASS    | Anonymous navigation to /platform/organizations returned /login and the login form; no platform data was exposed. Browser DOM assertion passed.                                                                                                                                           |
| Login availability  | PASS    | Login page and its JavaScript/CSS loaded after maintenance ended.                                                                                                                                                                                                                         |
| 1.1                 | PASS    | Tester signed in as the preserved Ibrahim account; authenticated platform pages loaded. No password reset or server-side bypass.                                                                                                                                                          |
| 1.2                 | PASS    | Tester completed the two-factor challenge; both preserved admins show two-factor On on Platform staff.                                                                                                                                                                                    |
| 1.3                 | PASS    | Organizations shows No organizations yet. No hotel or guest data appears.                                                                                                                                                                                                                 |
| 1.4                 | PASS    | Essential remains Default at USD 49.00 monthly. TEST-A created through the UI with limits 2 properties / 10 rooms, trial 7 / grace 3 days, monthly USD 25.00, not default.                                                                                                                |
| 1.5                 | PASS    | TEST-A edited to USD 30.00. Duplicate TEST-A creation refused with The code has already been taken; exactly two plans remain.                                                                                                                                                             |
| 1.6                 | PASS    | Platform staff lists only Ibrahim and Khalfan as Platform administrators, both with two-factor On.                                                                                                                                                                                        |
| 2.1                 | PASS    | Tester submitted owner registration. Browser reached /email/verify and displays We sent a link to imdeveloper128@gmail.com. Password requirements were visible before submission. Email receipt itself is not yet verified.                                                               |
| 2.2                 | PASS    | Refreshing /dashboard after the tester verified email opened /organizations/create instead of the verification gate.                                                                                                                                                                      |
| 2.3                 | PASS    | Created Bwawani Bay Hotels / Bwawani Bay Hotel through the UI with Zanzibar / USD, 4 test stars and TEST-A-Z-NOT-REGISTERED. Dashboard shows profile/email complete and both registration gates Passed.                                                                                   |
| 2.4                 | BLOCKED | Sensitive property settings redirected through password confirmation to owner Security. The authenticator setup dialog is open; tester must finish 2FA. Logout/login persistence portion is not yet run.                                                                                  |
| 3.3                 | PASS    | Created STD, DLX and FAM with the specified occupancy limits. Duplicate STD refused with The code has already been taken.                                                                                                                                                                 |
| 3.4                 | PASS    | Created rooms 101/102/103, 201/202 and 301 with the intended types/floors. Duplicate 101 refused with The number has already been taken.                                                                                                                                                  |
| 3.5                 | PASS    | Saved BB / USD for all types, then saved cancellation-free days 3 and first-night cancellation/no-show policies without errors. Later fee behavior remains untested.                                                                                                                      |
| 3.6                 | PASS    | UI saved 61 nights per type, D0 2026-10-21 through 2026-12-20: STD 80, DLX 120, FAM 180, extra adult 20 and child 10. 183 rendered price rows verified.                                                                                                                                   |
| 3.7                 | PASS    | Created NR derived from BB at -10%. Availability UI verified USD 72/108/162 alongside USD 80/120/180.                                                                                                                                                                                     |
| 3.9                 | PASS    | Taxes UI shows only the seeded Infrastructure levy USD 5 per guest/night, Added / Room charges, and VAT 15% Included / Rooms and extras, effective 21 October 2026. No duplicate tax was created.                                                                                         |
| SM-01               | PASS    | Additional owner smoke test: RES-000001 saved for Hassan Ali (A), room 101, BB, 2 adults, Phone, 21-22 October, USD 80 nightly. Setup-mode check-in refused and status stayed confirmed; void succeeded and released its assignment. This does not count as U2 role coverage for 5.1-5.3. |
| Remaining scenarios | NOT RUN | No staff accounts, live check-in, completed hotel folio cycle, night audit, isolation or SaaS invoice flow is claimed as tested.                                                                                                                                                          |

Of the guide's 124 numbered scenarios: 15 PASS, 0 FAIL, 1 BLOCKED and 108 NOT RUN. Baseline/preflight and owner smoke checks are additional checks, not counted as role-specific business scenarios.

Created plan: TEST-A, ID `01m5eywdpk171a0bhx83086dtf`, now USD 30.00 monthly. Essential was not edited.

Created hotel property: `01m5f1m5kympvfjwnp3a1jx80w`. Room types: STD `01m5f20y7st1mq4zx2510f54tj`, DLX `01m5f20zxte3x86x8zbksz3sq7`, FAM `01m5f211kewxg6wra9zm56ygq0`. BB plan: `01m5f2drnpr61e59zpp9r0vqg0`; NR plan: `01m5f30kngcnnbmkpffcg0461v`. Sample `RES-000001` is void.

Platform walkthrough recording: `/home/astro/.t3/userdata/attachments/ee7a37a1-bf61-4a48-b8f9-2c5e60758a8d-b97b3ffd-c25b-4c91-a58d-b73d7b413b07-mp4.mp4`. It contains plan creation/edit/duplicate validation, not credential entry. Recording is off for owner registration.

## Resume point

1. Finish owner authenticator setup in the shared browser's Security dialog. Scan the QR privately, continue and confirm with the authenticator code. Do not post the setup key or recovery codes in chat.
2. Once 2FA is confirmed, resume sensitive property settings, subscription review and explicit tax review/activation. Owner credentials and 2FA were not bypassed.
3. Continue setup, staff invitations, housekeeping, bookings, billing, night audit, checkout and isolation in guide order. Batch ordinary form entry and keep narration brief as requested.
4. Narrate each section while the tester watches. Pause any recording for passwords, verification links, authenticator setup/codes and recovery codes.
5. Update this report with actual step results and evidence. Do not reset the database again merely to restart a blocked login step.

## Known gaps to verify, not passing claims

- The property-manager identity-view permission needs comparison with the agreed requirement at step 12.5.
- ZRA/VFMS production delivery, fiscal receipt issuance, live channels and production certification are outside the completed checks.
- Automatic checkout, maintenance tickets and calendar drag-and-drop are not covered by the reset or login preflight.
- Native browser screenshot export timed out twice at the login page. Navigation and DOM inspection worked; no screenshot or recording is claimed as saved for this checkpoint.
- A second preview tab shared the same authenticated session rather than creating an isolated role session. Roles are currently switched through normal logout/login; do not claim concurrency or independent-session coverage without verifying isolation.
- The PDF retains the earlier login checkpoint. This run report is the current execution record; regenerate the PDF after a substantive testing batch rather than after each form.

## Spec 11 live check (owner, 21 Oct 2026)

| Check                                | Result | Evidence                                                                                                                    |
| ------------------------------------ | ------ | --------------------------------------------------------------------------------------------------------------------------- |
| Grouped sidebar                      | PASS   | Front desk (Today, Calendar, Reservations, Housekeeping, My rooms), Money, Setup, More.                                     |
| New booking without Availability     | PASS   | /reservations/create opened from Reservations; picking room 101 filled its room type; guest names kept across room refresh. |
| Own rate typed as money              | PASS   | `95` submitted 9500 minor units; RES-000002 saved.                                                                          |
| Country dropdown                     | PASS   | Tanzania first; guest and document nationality and issuing country are dropdowns.                                           |
| Check in from reservation page       | PASS   | Refused without ID ("Registration incomplete"); succeeded after NIDA added and room 101 marked clean.                       |
| Extra charge `12.50`                 | PASS   | Folio USD 12.50, VAT USD 1.63 included.                                                                                     |
| Payment `12.5` mobile money          | PASS   | USD 12.50, receipt BBH000001.                                                                                               |
| Early departure, then check-out      | PASS   | Check-out refused until Depart early; then checked_out.                                                                     |
| Reservations tabs                    | PASS   | Upcoming, Today, In house, Past, All; Past lists RES-000001 (void) and RES-000002 (checked out).                            |
| Settings tabs                        | PASS   | Hotel, Front desk, Tax & ZRA, Payments; inactive panels hidden, not removed.                                                |
| Leave unassigned (from Availability) | PASS   | Room type and rate plan kept after choosing a room, then Leave unassigned.                                                  |
| Booker note on staying-guests page   | FIXED  | Outdated note removed in PR #131.                                                                                           |
