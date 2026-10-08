# Issue tracker

GitHub Issues on `Astro-67/the-dream`, through the `gh` CLI.

- **Spec**: one issue holding the whole spec (problem, solution, user stories, decisions, testing, out of scope). Spec 1 is #1.
- **Tickets**: one issue each, with `## Parent` naming the spec and `## Blocked by` listing blocking tickets. Label `ready-for-agent` when buildable.
- **Closing work**: through a PR whose body says `Closes #N` for the spec and every ticket. A spec builds on one integration branch, `spec/<N>-<slug>`, opened as a draft PR after the first ticket merges.
- **Read**: `gh issue view <N>`. **List**: `gh issue list --state open`.
