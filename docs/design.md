# Thedream PMS design system

## Overview

A precise, calm interface for people running hotels: front desk, reservations, housekeeping, finance and compliance staff, working long shifts on desktops and tablets, often on slow connections. The aesthetic is quietly confident: clear headings, generous but disciplined spacing, flat surfaces with crisp borders. Information density is high where staff scan and act (calendars, tables, folios) and relaxed where they read or decide (onboarding, settings, confirmations). Every status is readable at a glance and never depends on colour alone.

The interface is built on the Laravel React starter kit: Tailwind CSS 4, shadcn/ui and Radix. This document sets the values; the starter kit's components implement them. Interfaces ship in English only for this version; Kiswahili and translation infrastructure are deferred.

## Colors

All text colours below meet WCAG AA (4.5:1) on the surface they're used on, and border and focus colours used for controls meet 3:1. Ratios were checked for both themes.

### Light theme

- **Primary** (#4F46E5): primary button fill, active navigation, links, selected states. White text on it: 6.3:1
- **Primary Hover** (#4338CA): hover and pressed state for primary elements
- **Primary Tint** (#EEF2FF): selected rows, active filters, the current date in the calendar
- **Background** (#FAFAFA): page background
- **Surface** (#FFFFFF): cards, panels, tables, dialogs, sidebar
- **Text Primary** (#0A0A0A): headings and body text
- **Text Secondary** (#6B6B6B): descriptions, metadata, table secondary columns
- **Text Muted** (#737373): placeholders, timestamps, helper text (4.5:1 or better on both background and surface)
- **Border** (#E8E8EC): dividers, card and table borders. Decorative only, never the only edge of a control
- **Input Border** (#8A8A94): text inputs, selects, checkboxes, switches (3.4:1 on surface)
- **Focus Ring** (#4F46E5): a solid 2px ring with a 2px offset
- **Destructive** (#DC2626): fill for destructive buttons (white text 4.8:1); use #B91C1C for destructive text

### Dark theme

- **Primary** (#818CF8): primary fill with near-black text (#0A0A0B, 6.6:1); links and focus ring
- **Primary Hover** (#A5B4FC)
- **Primary Tint** (#818CF8 at 15% over surface)
- **Background** (#0A0A0B)
- **Surface** (#141416); raised surfaces such as popovers and dialogs (#1C1C1F)
- **Text Primary** (#F4F4F5)
- **Text Secondary** (#A1A1AA)
- **Text Muted** (#8B8B94)
- **Border** (#27272A)
- **Input Border** (#63636B)
- **Focus Ring** (#818CF8)
- **Destructive** (#F87171) with near-black text

### Status colours

Status is always shown as a **label (and, where useful, an icon) plus colour**, never colour alone. Light theme: dark text on a pale tint. Dark theme: the 400 shade on a 15% tint of the same hue.

| Role        | Light text / tint | Dark text | Used for                                             |
| ----------- | ----------------- | --------- | ---------------------------------------------------- |
| Success     | #047857 / #ECFDF5 | #34D399   | Delivered, paid, room clean, check-in complete       |
| Warning     | #B45309 / #FFFBEB | #FBBF24   | Pending, late, room dirty, balance due               |
| Error       | #B91C1C / #FEF2F2 | #F87171   | Failed, rejected, blocked, validation errors         |
| Info        | #4338CA / #EEF2FF | #818CF8   | Sending, in progress, informational notices          |
| Occupied    | #0369A1 / #F0F9FF | #38BDF8   | Room occupied                                        |
| Inspection  | #6D28D9 / #F5F3FF | #A78BFA   | Room needs inspection                                |
| Unavailable | #475569 / #F1F5F9 | #94A3B8   | Room out of service, disabled integrations, archived |

The bright shades (#10B981, #F59E0B, #EF4444) are allowed only for status dots and chart marks, never for text.

**Room status (OPS-01):** clean = Success, dirty = Warning, occupied = Occupied, inspection required = Inspection, unavailable = Unavailable.

**Compliance and delivery status (RPT-04, ZAN-08):** delivered = Success, pending = Info, late = Warning, failed, rejected and blocked = Error, not applicable (mainland) = Unavailable.

## Typography

- **Heading font:** General Sans, weight 600 only. Self-hosted (WOFF2, Latin subset) through the Laravel Vite font plugin, not loaded from the Fontshare CDN.
- **Body font:** DM Sans, weights 400 and 500, loaded through Bunny Fonts as the starter kit already does.
- **Mono font:** JetBrains Mono, weight 400. Used for identifiers: reservation numbers, channel references, receipt and VFMS reference numbers, document numbers, codes.

Headings use General Sans with letter spacing of -0.02em. Body and interface text use DM Sans. A screen uses at most these three weights: 400, 500 and 600.

**Type scale** (size / line height):

| Style                              | Font                            | Size      |
| ---------------------------------- | ------------------------------- | --------- |
| Display (auth and onboarding only) | General Sans 600                | 30 / 36px |
| Page title                         | General Sans 600                | 24 / 32px |
| Section heading                    | General Sans 600                | 18 / 28px |
| Subheading                         | DM Sans 500                     | 16 / 24px |
| Body and inputs                    | DM Sans 400                     | 14 / 20px |
| Small                              | DM Sans 400                     | 13 / 18px |
| Caption                            | DM Sans 400                     | 12 / 16px |
| Overline                           | DM Sans 500, uppercase, +0.06em | 11 / 16px |

**Numbers:** money, counts, dates and times use tabular figures (`font-variant-numeric: tabular-nums`) so columns line up.

## Elevation

Surfaces are flat, separated by 1px borders. Shadows mean "this floats" or "you can pick this up":

- Dropdowns, popovers, the command palette and toasts use shadow-lg.
- Dialogs and sheets use shadow-xl over a 40% black overlay.
- Clickable cards gain a hover shadow (0 8px 30px rgba(0,0,0,0.08)) and lift 2px. Static cards and table rows never lift.
- The top bar uses a backdrop blur, not a shadow.
- Focus is shown by the solid focus ring, never by a shadow.

Motion lasts 150–200ms with an ease-out curve. Respect `prefers-reduced-motion`: remove lifts and slides, keep fades.

## Components

- **Buttons:** primary (Primary fill, white text), secondary (surface fill, 1px Input Border), ghost (text only, tinted background on hover), destructive (Destructive fill). Radius 6px, DM Sans 500. Sizes: 32px (dense toolbars and table rows), 36px (default), 44px (touch and tablet primary actions). Buttons don't move on hover; they change colour only. Loading buttons keep their width and show a spinner.
- **Cards:** Surface, 1px Border, 12px radius. Only clickable cards (for example a property in the property picker) get the hover lift.
- **Inputs and selects:** Surface fill, 1px Input Border, 6px radius, 8px × 12px padding, 14px text. Focus: Focus Ring. Error: Error border, plus an error message below the field (Error text, 13px, with an icon). The label always sits above the field, and placeholders are never used as labels.
- **Checkboxes and switches:** square checkboxes (4px radius, 16px box inside a 24px hit area) for choosing items. Switches for settings that take effect immediately. Radios for one-of-many. Never round checkboxes.
- **Chips:**
    - **Status chips** are pill-shaped (9999px radius), 12px text, 2px × 8px padding, with a 6px dot and a label, in the status colours above.
    - **Tags and filters** use a 4px radius, Background fill and Text Secondary text. Active filters use Primary Tint with Primary text.
- **Tooltips:** the shadcn/Radix Tooltip, which works with a keyboard and on touch. Never put essential information only in a tooltip, and never use native `title` tooltips.
- **Dialogs and confirmations:** shadcn Dialog. Destructive confirmations name the object ("Cancel reservation R-1042?"), explain the consequence, and put the destructive button last. Any action that reports to ZRA says so ("This sends a cancellation to ZAN Gateway").
- **Toasts:** bottom-right on desktop, top on mobile. Success, info and default toasts disappear after 4 seconds; error and warning toasts after 8 seconds. Hovering or focusing the toaster pauses dismissal. Every toast has a close button and is announced to assistive technology.
- **Empty states:** a one-line explanation plus the next action ("No arrivals today. Create a reservation"). No illustrations.
- **Navigation:** the starter kit's collapsible sidebar on desktop and tablet, which becomes a sheet on mobile.
    - **Organization and property switcher** at the top of the sidebar, always showing the current organization and property (PRD §4).
    - **Top bar:** breadcrumb, the property's current **business date**, the connection status, and the user menu.
    - **Sidebar sections:** Front desk, Reservations, Calendar, Housekeeping, Billing, Reports, Compliance (Zanzibar properties only), Settings.
- **Command palette (⌘K / Ctrl+K):** searches reservations by number, guest name, channel reference and room number, within the current property only. Opened from a search bar showing the shortcut badge.
- **Connection status:** when the browser goes offline, a persistent amber banner below the top bar says "Offline: changes can't be saved", and actions that change data are disabled. No false success messages (PRD connectivity requirement).

## PMS patterns

- **Data tables:** dense rows (40px; 48px on touch), sticky header, Border dividers, row hover in Background. Text columns are left-aligned; numbers and money are right-aligned with tabular figures. Row actions go in the last column, with an overflow menu after two actions. Sorting is shown in the header. Selected rows use Primary Tint.
- **Reservation calendar (PMS-01):** rooms as rows, dates as columns. The current business date column uses Primary Tint. Each booking bar shows the guest name and channel, with a status chip on hover or focus. Out-of-service periods are hatched in Unavailable. The grid scrolls in both directions with sticky room labels and date header, and is fully usable with a keyboard.
- **Money:** always shown with its currency code ("TZS 251,550", "USD 120.00"). TZS shows no decimals, while USD and EUR show two. Different currencies are never added together or shown as one total; a folio with two currencies shows two balances (FIN-01). Negative amounts use a minus sign, not just red.
- **Dates and times:** shown in the property's timezone, with the business date labelled as such. Format: "7 Oct 2026" and "14:05" (24-hour).
- **Identifiers:** reservation numbers, channel references and receipt numbers are set in JetBrains Mono, with a copy button where staff need to read them out or paste them elsewhere.
- **Identity documents:** masked by default (••••4821) for roles without access (GST-01). Revealing one is a deliberate action and is logged.
- **Receipts and print:** print styles for A4 and 80mm thermal paper. A **fiscal receipt** shows the VFMS QR code and receipt number prominently (FSC-06). A **non-fiscal receipt** carries a clear "NON-FISCAL RECEIPT" heading (FSC-08). Print output uses black text on white only.
- **Text sizing:** buttons and labels must wrap or grow rather than truncate; never use fixed widths for text.
- **Touch:** housekeeping on mobile and the front desk on tablets need touch targets of at least 44 × 44px, with 8px between targets.

## Spacing

- Base unit: 4px
- Scale: 4, 8, 12, 16, 20, 24, 32, 40, 48, 64px
- Component padding: small 4 × 8, medium 8 × 12, large 12 × 20
- Page padding: 16px on mobile, 24px on tablet and desktop
- Section spacing: 24px on mobile, 32px on desktop
- Content width: full width for calendars and tables. Forms and settings stay at most 720px wide; a long form may set its sections side by side on wide screens, each at most 720px, with a summary column holding the submit button. Short create forms on a list page open in a dialog from a button beside the heading.
- The operational shell fills the viewport remaining after the visible sidebar (256px expanded, 48px rail); the sidebar owns that gap. No extra page offset or viewport-centred cap. The header and page share the 16px/24px horizontal gutter and a single token border marks each navigation boundary.
- Dashboard grids, report filters and tables use that full work area, including sparse states. Reading/print surfaces and forms keep their bounds inside it. Account settings use a 192px navigation column, a 32px gap and a form column capped at 720px; navigation stacks above the form below `lg`.
- Card grid gap: 16–24px

## Border Radius

Set the starter kit's `--radius` to 8px. Its derived sizes then give:

- 4px (`rounded-sm`): tags, badges, inline code, checkboxes
- 6px (`rounded-md`): buttons, inputs, selects
- 8px (`rounded-lg`): panels, dropdowns, popovers, dialogs
- 12px (`rounded-xl`): cards, the search bar
- 9999px (`rounded-full`): avatars, status chips, status dots

## Implementation: shadcn tokens

Map this document onto the variables in `resources/css/app.css` (`:root` for light, `.dark` for dark):

| Variable                             | Light          | Dark                      |
| ------------------------------------ | -------------- | ------------------------- |
| `--background`                       | #FAFAFA        | #0A0A0B                   |
| `--foreground`                       | #0A0A0A        | #F4F4F5                   |
| `--card`, `--popover`, `--sidebar`   | #FFFFFF        | #141416 (popover #1C1C1F) |
| `--primary`                          | #4F46E5        | #818CF8                   |
| `--primary-foreground`               | #FFFFFF        | #0A0A0B                   |
| `--secondary`, `--muted`, `--accent` | #F4F4F5        | #1C1C1F                   |
| `--muted-foreground`                 | #6B6B6B        | #A1A1AA                   |
| `--destructive`                      | #DC2626        | #F87171                   |
| `--border`                           | #E8E8EC        | #27272A                   |
| `--input`                            | #8A8A94        | #63636B                   |
| `--ring`                             | #4F46E5        | #818CF8                   |
| `--radius`                           | 0.5rem         | 0.5rem                    |
| `--font-sans`                        | DM Sans        | DM Sans                   |
| `--font-heading` (new)               | General Sans   | General Sans              |
| `--font-mono`                        | JetBrains Mono | JetBrains Mono            |

Status colours become additional tokens (`--status-success`, `--status-success-tint` and so on) so that chips, room status and compliance views share one definition.

## Building screens

Every new screen uses the shared building blocks; don't hand-roll their markup or reach for raw colours.

| Need                                | Use                                                                      |
| ----------------------------------- | ------------------------------------------------------------------------ |
| Data table                          | `Table`, `TableHeader`, `TableRow`, `TableHead`, `TableCell` (`numeric`) |
| Status                              | `StatusChip` with a `tone` from the status colours                       |
| Tag or filter                       | `Tag` (`active` for an active filter)                                    |
| Reservation number, reference, code | `Identifier` (`copyable` where staff read it out or paste it)            |
| Nothing to show                     | `EmptyState` with the next action                                        |
| Money                               | `formatMoney(minor, currency)` in a `numeric` cell                       |
| Date, time                          | `formatDate`, `formatTime(instant, property timezone)`                   |
| Page and section headings           | `Heading`, or `h1`–`h3` (General Sans is applied to them)                |
| Buttons, inputs, selects, dialogs   | `components/ui` (`Button` sizes: `sm` 32px, default 36px, `lg` 44px)     |

**Pagination:** any list that can grow without limit (reservations, guests, folios, audit logs) is paginated on the server with `->paginate(50)->withQueryString()`, so filters and sorting survive page changes, and rendered with `Pagination` directly below its `Table`. It shows "Showing 51–100 of 1,240", previous and next, and windowed page numbers; it hides itself when there is one page. Changing page resets the table to its first row. Bounded lists (room types, rate plans, members) are not paginated. Calendars page by date range, never by page number. An empty page uses `EmptyState`, not an empty table.

**Long pages and scrolling:** the top bar (breadcrumb, current property) is sticky and stays visible however far the page scrolls. A `Table` scrolls inside its own bordered card, capped to the viewport below the top bar, so its header row stays visible; the page itself only scrolls for content above and below it. Wide tables and the rate calendar scroll horizontally inside their card, never the whole page. Don't nest a second scroll area inside a table card.

Colours come only from theme tokens (`bg-primary`, `bg-primary-tint`, `text-muted-foreground`, `text-status-warning` and so on), never Tailwind palette colours such as `text-green-600`.

## Marketing surfaces

Public pages that sell Thedream (the landing page at `/`) follow every rule above, with these exceptions. PMS screens keep the strict rules.

- **Photography:** photorealistic images of warm East-African boutique hotels, each with one small indigo accent so they relate to the UI. They live in `public/images/marketing/` as WebP under 350 KB. No people's faces, no text or logos, and no image that implies a real customer.
- **Product screenshots:** real screens from the showcase data, in the visitor's active theme. Never a mock-up that shows a feature the product lacks.
- **Image overlays:** a dark scrim (`bg-black/50` or stronger) is allowed over a photo so white text passes contrast. This is the only gradient or overlay allowed.
- **Proof:** no testimonials, customer logos or usage numbers until they are real.
- **Pricing:** live plan prices come from the plans a platform administrator publishes. Plans without a published price say "Talk to us". Integrations awaiting certification are never advertised (PRD §13).

## Do's and Don'ts

- Do use Primary only for interactive and selected elements, never for decoration or static text.
- Do show every status as a label plus colour; never rely on colour alone.
- Do keep the 4px spacing grid for padding, margins and gaps.
- Do use General Sans for headings and DM Sans for everything else; never swap them.
- Do show the current organization, property and business date on every operational screen.
- Do test every screen in light and dark themes, in English, and with the keyboard only.
- Do keep the radius scale: cards 12px, controls 6px, chips as specified.
- Don't use the bright status shades (#10B981, #F59E0B, #EF4444) for text.
- Don't use shadows on static elements, or move buttons on hover.
- Don't use more than three font weights (400, 500, 600) on a screen.
- Don't place more than one primary button in the same section.
- Don't add gradients, illustrations or decorative patterns (marketing photography is the one exception; see Marketing surfaces).
- Don't add amounts in different currencies, or hide which currency an amount is in.
