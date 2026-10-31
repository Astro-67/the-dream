# Toast and dialog browser checks (#179)

Spec #178 assigns timing and dialog-state checks to the browser, not static
server-rendered markup. Use a disposable worktree database as described in
`docs/agents/dev-workflow.md`.

## Toasts

- Trigger each flash type. Success and info disappear after 4 seconds; error and
  warning remain at 4 seconds and disappear after 8 seconds.
- Hover an error or warning for more than 8 seconds: it remains visible. Move
  away: the remaining timeout resumes.
- Every toast has a "Close toast" button that dismisses it immediately.
- Press Alt+T to reach the notifications region, then Tab to the toast and its
  close button. The timer pauses while the region is expanded; Enter on the
  close button dismisses the toast. Notifications retain their polite live
  region.

## Submitting dialogs

For each action below, submit invalid input first. The dialog must stay open,
show the server's field errors, and keep entered values. Correct the input and
submit successfully: the dialog must close without another click. Reopen a
remaining action to confirm it still works.

- Out of order: change an existing period, including returning it early.
- Folio corrections: move and reverse a posting.
- Folio adjustments: request a refund and a write-off.
- Folio entries: record payment and post an extra.
- Front desk: check out, including an authorized open-balance override.
- Reservation termination: cancel and void.
- Stay length: extend and depart early.
- Reservation editing: assign a room, move room and amend the stay (including
  the restrictions-review step).
- Housekeeping: change status and override readiness.
- Taxes: add, change and end a rule (already close on success).
- Members: remove a member and transfer ownership.
- Properties: archive a property and delete a demo property.
- Platform staff: remove a role.
- Invitations: revoke a pending invitation.
- Rate plans: deactivate a plan.
- Fiscal recovery: re-file, retry and close without filing.

## Existing unmount/navigation behavior

- Guest merge: a successful merge navigates from `guests/merge` to `guests/show`;
  validation errors keep the merge confirmation open.
- Guest departure replaces its action with "Left".
- Removing a passkey removes its row, including its confirmation.
- Deleting the signed-in user's account navigates away.
- Rate-calendar bulk edits and maintenance reporting already close in their
  success callbacks.
- Two-factor setup, recovery-code displays and persistent `Alert` banners are
  unchanged.

## Implementation verification

The initial browser checks reproduced an error toast remaining after 8 seconds,
its missing close button, and front-desk, billing and administration dialogs
remaining after successful Inertia submissions.

After the fix, browser checks passed for the toast timing/hover/keyboard cases
and the submitting-dialog cases above using the real React components and
Inertia forms with fixture HTTP success/error responses. This isolates UI
state behavior; it does not replace an authenticated end-to-end acceptance
pass against the real application controllers. Navigation/unmount cases were
audited in their existing components and controller responses, not exercised
end to end.
