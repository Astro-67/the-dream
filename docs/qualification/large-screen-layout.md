# Workspace layout qualification (#145)

Measured on 22 October 2026, against `6c1b578`, using an authenticated owner
and disposable SQLite data. No routes, grants or operational data changed.

## Geometry

The matrix covers Dashboard, Reservations (empty and populated arrivals),
reservation/folio, Calendar, Payments (empty), Reports, Property settings and
Account settings. Each runs at actual CSS viewport widths 1280, 1440, 1920,
2560 and 960 with expanded/collapsed navigation, plus 390 with sheet navigation.
The 960 case exercises the effective CSS width of a 1920 screen at 200% zoom;
it is not a native browser-zoom test.

All 99 measured cases pass these assertions: main starts at the visible
navigation edge, main fills the remaining document client width, forms do not
exceed 720px, and the document has no horizontal overflow. Measurements use
`innerWidth`, `documentElement.clientWidth/scrollWidth` and element bounding
boxes, not the requested resize alone. Frames provide the initial matrix;
top-level 390, 1920 and 2560 viewports confirm the captures below.

Dashboard measurements (CSS pixels, no vertical scrollbar):

| Viewport | Navigation | Sidebar edge / main x | Main width | Content x | Usable content width |
| -------- | ---------- | --------------------- | ---------- | --------- | -------------------- |
| 1280     | Expanded   | 256                   | 1024       | 280       | 976                  |
| 1280     | Collapsed  | 48                    | 1232       | 72        | 1184                 |
| 1440     | Expanded   | 256                   | 1184       | 280       | 1136                 |
| 1440     | Collapsed  | 48                    | 1392       | 72        | 1344                 |
| 1920     | Expanded   | 256                   | 1664       | 280       | 1616                 |
| 1920     | Collapsed  | 48                    | 1872       | 72        | 1824                 |
| 2560     | Expanded   | 256                   | 2304       | 280       | 2256                 |
| 2560     | Collapsed  | 48                    | 2512       | 72        | 2464                 |
| 390      | Sheet      | 0                     | 390        | 16        | 358                  |

Long pages reserve the native 15px vertical scrollbar where present; the main
ends at the document client edge rather than extending underneath it.
At 2560 expanded, the account form starts at x=504 and stays 720px wide.
The property form's existing 720px outer bound retains 672px for its fields.

The populated 14-day calendar owns its horizontal scrolling: at 1920 expanded,
its client width is 1614px and scroll width 2210px. At 390 they are 356px and
2210px. The populated arrivals table similarly scrolls 901px inside a 356px
container on mobile. No workspace clipping is needed to conceal either.

The long property name retains 106px in the 390px header (91px on pages with a
vertical scrollbar), alongside jurisdiction and business date. The mobile
navigation opens as a sheet and dismisses on an Escape key event.

## Captures and boundaries

Local captures are retained under `.scratch/layout-evidence/`: mobile Dashboard,
1920 light expanded/collapsed Dashboard, 2560 dark expanded/collapsed Dashboard,
and the bounded 2560 account form. Images may be downsampled; geometry above
comes from the live document, not image dimensions.

Header and sidebar rules use the same existing border token: light
`rgb(232, 232, 236)`, dark `rgb(39, 39, 42)`. These remain decorative separators,
not control outlines. Existing table/card boundaries and sticky headings remain
owned by their components.

## Remaining manual checks

- Native 200% browser zoom, complete keyboard traversal/focus restoration, and
  loading/error-state interaction were not qualified.
- The supplied GitLab PNGs are 1px placeholders. No pixel match is claimed.
  Readable replacements are needed to compare exact divider placement,
  navigation proportions and header density; Dream's tokens/fonts remain its own.
