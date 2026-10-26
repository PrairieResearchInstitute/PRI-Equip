# UI Elements: PaCELab Equip

The UI Elements spec for PaCELab Equip (Shared Laboratory Equipment System). Read it before adding or changing anything on screen. It builds on the estate baseline, the skill drez-ui-elements: the baseline sets the rules, and this file sets the values. Owner decisions Q-25 to Q-28 (2026-10-17) apply. Source audit: `reference\UI Audit 2026-10-17.docx` (findings PCE-01 to PCE-33). The owner reads the rendered copy, `reference\UI Elements.docx`.

## 1. Identity

| Item | Value |
|---|---|
| Palette family | Illinois: navy `#13294B` primary, Illinois Orange `#FF5F05` highlight (Q-26), warm paper ground and warm grey lines shared with PRI Facilities Request |
| Fonts | `--font` Georgia for prose and headings; `--font-ui` system sans for tables, forms, buttons, pills and the calendar; `--font-mono` for codes. The Illinois Adobe Fonts kit `etc1rtr` styles only the toolkit header and footer. Open decision: one font pairing (section 9). |
| Themes shipped | one light theme; no dark or System theme |
| Token sheet | `assets/style.css`, the `:root` block. Every colour, size and spacing value lives there; no PHP file carries a `style` attribute except where `assets/calendar.js` places a calendar block or the tooltip. |
| Chrome | Illinois Web Toolkit v3 `<ilw-header>` and `<ilw-footer>` from `cdn.toolkit.illinois.edu/3/` (approved dependency, AGENTS.md), loaded by `page_header()` in `includes/functions.php` |
| Scripts | `assets/app.js`, `assets/calendar.js`; plain JavaScript, no framework |
| Icons | no icon set; approved text glyphs only (section 3, Icons); favicon `assets/favicon.svg` and `assets/favicon.ico` |

### Shared PRI web look

Identical in the specs of PaCELab Equip, PRI Facilities Request and PRI District Tracker; change all three together.

The three PRI web apps share one look where they already agree. PaCELab named its tokens after Facilities on purpose, so those two share eight token names; District Tracker reaches the same navy and orange under other names.

| Role | Shared value | PaCELab Equip | PRI Facilities Request | PRI District Tracker |
|---|---|---|---|---|
| Primary | `#13294B` navy | `--accent` | `--accent` | `--blue` (public), `--navy` (Data Console) |
| Highlight | `#FF5F05` Illinois Orange (Q-26) | `--accent2`, today `#e84a27` | `--accent2`, today `#e84a27` | `--orange` |
| White-text orange, orange text on light grounds | `#C84113` (`--il-altgeld`) | new `--il-altgeld` | new `--il-altgeld` | `--il-altgeld` in the vendored toolkit |
| Body text | `#1a1a1a` | `--ink` | `--ink` | `--ink` (public pages) |
| Page ground | `#f5f4f1` warm paper | `--paper` | `--paper` | differs: toolkit white on public pages, `#f4f6f8` in the back office |
| Panel | `#ffffff` | `--panel` | `--panel` | white literals |
| Line | `#d8d5cf` warm grey | `--line` | `--line` | differs: `#e3e3e2` public, `#e3e6ea` back office |
| Success | `#1b6b2f` | `--good` | `--good` | differs: `#14532d` on `#e8f5ec` |
| Danger | `#a4210c` | `--bad` | `--bad` | differs: `#7f1d1d` on `#fdecea` |
| Focus ring | 2 px solid navy, 2 px offset; `#FF5F05` on navy bars | `--focus` | `--focus` | `--focus` |

Orange rule (Q-26). Illinois Orange `#FF5F05` replaces the retired Altgeld `#E84A27` everywhere, favicons included. White text never sits on `#FF5F05` (about 3.0 to 1). A fill that carries white text uses `#C84113` (`--il-altgeld`, about 4.97 to 1 with white); a fill that stays `#FF5F05` carries navy `#13294B` text (about 4.7 to 1). Orange text on white or paper uses `#C84113`. Pure `#FF5F05` stays for rules, borders, underlines, dots and fills with navy text. Navy `#13294B` stays the primary colour.

Header and footer pattern. Each app opens with navy or the Illinois toolkit header, one orange rule as the only accent line, then white panels on a light ground. PaCELab: toolkit `<ilw-header>` with the lab bar below it and the toolkit `<ilw-footer>`. District Tracker: vendored toolkit `<ilw-header>` and the PRI `<ilw-footer>` on public pages; a navy bar with a 6 px orange top rule and no footer in the back office. Facilities: its own navy `header.site` with a 4 px orange bottom rule, the PRIOps lockup and a right-aligned text footer `footer.foot`.

Dialog helper. All three apps use one plain JavaScript helper with one API, `PriDialog.ask`, `PriDialog.confirm` and `PriDialog.notify`, plus the server-rendered confirm page for PHP forms (Q-27). Each app's section 4 names its file.

Open shared decisions, each listed in every app's `reference\open-questions.md`:

- Fonts. Answered (Q-20): the campus faces, from the Illinois kit online. PaCELab uses Source Sans Pro as the kit serves it and vendors nothing. Recommendation was Source Sans 3 for body and controls and Montserrat for headings in all three, vendored offline by the District Tracker method (drez-illinois-brand-offline; drez-inhs-document-branding rule 3 names Source Sans 3 for screens), with Georgia retired from screens.
- Header. PaCELab and District Tracker use the toolkit `<ilw-header>`; Facilities draws its own navy bar. Recommendation: the toolkit header in all three, vendored offline for Facilities (which loads nothing from a CDN), with the PRIOps lockup in the site-name slot.
- Neutral greys. PaCELab and Facilities use the warm paper set (`#f5f4f1`, `#d8d5cf`); District Tracker uses cool greys. Recommendation: the warm set for app screens and back offices; the toolkit neutrals stay on District Tracker's public pages.

## 2. Tokens

All tokens sit in `:root` in `assets/style.css`. The file carries two names for most colours, a short house name (`--ink`) and a `--color-*` alias used by the rules; both stay, and new rules use the `--color-*` name. Every token below exists in the stylesheet.

### Colour

| Token | Value | Baseline role | Notes |
|---|---|---|---|
| `--paper` / `--color-bg` | `#f5f4f1` | `--bg` | page ground |
| `--panel` / `--color-surface` | `#ffffff` | `--surface` | cards, tables, inputs, modal |
| `--color-surface-alt` | `#faf9f7` | `--surface-2` | table heads, calendar gutters, subtotals, hover rows; replaces `#f8f9fa`, `#fbfcfd`, `#fbfbfc` (PCE-15) |
| `--line` / `--color-line` | `#d8d5cf` | `--line` | outer borders |
| `--color-line-soft` | `#e8e5df` | `--line` (inner) | rules inside tables and lists; readonly fill |
| `--ink` / `--color-ink` | `#1a1a1a` | `--text` | body text |
| `--color-ink-soft` | `#5b5750` | `--text-muted` | hints, crumbs, captions |
| `--accent` / `--color-accent` | `#13294b` | `--accent` | primary buttons, links, focus, selection |
| `--color-accent-strong` | `#0d1d36` | `--accent-hover` | primary button hover (PCE-16) |
| `--color-accent-soft` | `#e7eaf1` | `--accent` tint | hover rows, today column, locked pill, lab bar |
| `--on-accent` | `#ffffff` | `--on-accent` | text on navy and on `--il-altgeld` |
| `--accent2` / `--color-orange` | `#FF5F05` | highlight | Q-26; rules, dots and borders only, never under white text |
| `--il-altgeld` | `#C84113` | highlight, text-safe | white-text orange fills (`--cal-mine`), orange text on light grounds (`a:hover`, today's date number) |
| `--good` / `--color-ok` | `#1b6b2f` | `--ok-text` | |
| `--color-ok-bg` | `#e8f2ea` | `--ok-bg` | |
| `--color-ok-line` | `#9dd3b2` | `--ok` border | `.flash-success` border |
| `--color-ok-ink` | `#12351f` | `--ok-text` (flash) | `.flash-success` text |
| `--color-warn` | `#7a5600` | `--warn-text` | |
| `--color-warn-bg` | `#f8f1de` | `--warn-bg` | |
| `--color-warn-line` | `#e6cf8b` | `--warn` border | |
| `--color-warn-ink` | `#4a3a05` | `--warn-text` (flash) | |
| `--bad` / `--color-error` | `#a4210c` | `--danger` | danger button, error pill |
| `--color-error-strong` | `#8f1d17` | `--danger` hover | |
| `--color-error-bg` | `#f8e9e6` | `--danger-bg` | |
| `--color-error-line` | `#efab9f` | `--danger` border | |
| `--color-error-ink` | `#6d1811` | `--danger-text` (flash) | |
| `--cal-free` | `#faf9f7` | `--surface-2` | free calendar cell |
| `--cal-free-alt` | `#f2f0ec` | `--surface-2` | weekend columns |
| `--cal-booked` | `#5d6f8c` | calendar | other people's bookings; white text measures 5.1 to 1 |
| `--cal-booked-ink` | `#ffffff` | calendar | |
| `--cal-mine` | `var(--il-altgeld)` | calendar | own bookings; white text (Q-26) |
| `--cal-mine-ink` | `#ffffff` | calendar | |
| `--cal-ghost` | `rgba(19, 41, 75, .35)` | calendar | drag preview |

No info status colour exists; notices use the warn set.

### Type

| Token | Value | Baseline role | Use |
|---|---|---|---|
| `--font` | "source-sans-pro", "Source Sans 3", "Source Sans Pro", system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif | body face | everything: prose, headings, tables, calendar, controls |
| `--font-ui` | `var(--font)` | control face | the same family, kept as a name because the rules use it |
| `--font-mono` | ui-monospace, SFMono-Regular, "SF Mono", Consolas, "Liberation Mono", monospace | code face | `code`, CFOPA numbers, email body, Studio |
| `--fs-xs` | .72rem | `--fs-xs` | uppercase labels, pills, table heads |
| `--fs-sm` | .82rem | `--fs-sm` | labels, small buttons, legends |
| `--fs-hint` | .85rem | `--fs-sm` (hint) | hints, crumbs, quiet links |
| `--fs-md` | .9rem | `--fs-md` | tables, small body, alerts |
| `--fs-h3` | 1rem | `--fs-md` (heading) | h3 |
| `--fs-lg` | 1.15rem | `--fs-lg` | h2 |
| `--fs-xl` | 1.5rem | `--fs-xl` | h1 |

Body base: `15px/1.55 var(--font)` on `body`. The 2rem charge total on the use entry form is the one size outside the scale. Weights: 400 body, 600 headings, labels, buttons and emphasis, 700 reservation names and the grand total. Those are the weights the kit serves (400, 600, 700, 900, each with an italic) and the only ones to ask for. A rule asking for 500 gets no 500 face: the browser falls back to 400, which is how every button in the application came to render at body weight (Q-20). Check a new weight against the kit before using it.

### Shape, space, elevation, layers, motion

| Token | Value | Baseline role | Use |
|---|---|---|---|
| `--radius-sm` | 4px | `--radius-sm` | inputs, buttons, pills, code, tooltip |
| `--radius` | 6px | `--radius-md` | cards, accordions, flash, modal, calendar grid, tiles |
| `--radius-pill` | 999px | `--radius-pill` | `.alert-count` |
| `--space-1` to `--space-7` | .25, .5, .75, 1, 1.25, 1.5, 2rem | `--space-1` to `--space-6` | padding, gaps, margins; 1rem and 1.25rem carry most panels |
| `--shadow` | 0 1px 2px rgba(16,24,40,.06), 0 1px 3px rgba(16,24,40,.1) | `--shadow-1` | `.card`, `.accordion`, `.lab-card`, and tiles (PCE-23) |
| `--shadow-overlay` | 0 12px 40px rgba(16,24,40,.28) | `--shadow-2` | `.modal`, `.cal-tip` |
| `--z-tip` | 40 | `--z-menu` | `.cal-tip` |
| `--z-dialog` | 50 | `--z-dialog` | `.modal-backdrop` |
| `--dur-fast` | .12s | `--dur-fast` | accordion marker turn |

The calendar grid alone may use 3px corners on booking blocks (`.cal-block`, `.cal-ghost`).

### Active state and focus

- Active-state class: `.is-current`, as `.lab-card.is-current` uses today. The header menu item for the page in view, the Studio file in view and any future tab, segment or chip take `.is-current`; a pressed toggle also carries `aria-pressed="true"`. Other `is-` classes describe row state, not selection: `.is-voided`, `.is-locked`, `.is-today`, `.is-short`, `.is-ahead`.
- Focus ring: `--focus: 2px solid var(--color-accent)` with `outline-offset: 2px` on `:focus-visible` for every button, link, input, select, summary, calendar cell and menu item. Inputs keep the inset form (`outline-offset: -1px`) because they sit in tight rows. Inside a navy surface (`.charge-bar`) the ring uses `--accent2`.

## 3. Components

One component per family. Copy the markup; change the text, never the classes.

### Buttons

Class `.button`. Variants: `.button-secondary` (white, navy text and border), `.button-danger` (red fill), `.button-small` (compact, for tables and toolbars). One primary per form. Quiet cancel link: `a.link-quiet`; text danger link: `a.link-danger`. States: hover (`--color-accent-strong`, `--color-error-strong` or `--color-accent-soft`), `:focus-visible` ring, active, `:disabled` and `[aria-disabled=true]` at opacity .5 with `cursor: not-allowed`.

Use danger for removing, revoking, voiding and cancelling a booking; use secondary for reversible retire, restore, deactivate and reactivate (PCE-12). Variants must win over the base rule: write them as `.button.button-secondary`, `.button.button-danger`, `.button.button-small` (PCE-01).

```html
<div class="form-actions">
  <a class="link-quiet" href="equipment.php">Cancel</a>
  <button type="submit" class="button">Save Booking</button>
</div>
<button type="submit" class="button button-danger button-small">Remove</button>
```

Group buttons with `.button-row` (toolbar) or `.form-actions` (foot of a form).

### Inputs and selects

Every `input` type that takes text, numbers, dates or times, `select` and `textarea` share one rule: full width, `.5rem .6rem` padding, `--color-line` border, `--radius-sm`, white fill. Readonly and disabled fields take `--color-line-soft` with `--color-ink-soft` text. `input[type=datetime-local]` joins the shared rule (PCE-14). Selects keep the browser arrow (`appearance: auto`).

### Checkboxes

```html
<div class="checkline"><input type="checkbox" id="active" name="active" checked><label for="active">Active</label></div>
```

### Forms, labels and validation

Form shell `form.card.form`, fields in `.field`, two or three across in `.field-row` or `.field-row-3`, grouped in `fieldset` with `legend`. Label above the field; optional fields add `<span class="muted">(optional)</span>`; help goes in `p.hint` under the field. Server validation errors arrive as `.flash-error` above the form; a field that failed adds `.field-error` (new: `--color-error` border on the field and a `p.hint` in `--color-error` text) (PCE-30).

```html
<form method="post" class="card form">
  <div class="field">
    <label for="grant">Grant</label>
    <select id="grant" name="grant_id">...</select>
    <p class="hint">Charges post to the grant's receiving account.</p>
  </div>
  <div class="form-actions"><button type="submit" class="button">Add Grant</button></div>
</form>
```

### Dialogs and confirm pages

See section 4. The one dialog shell is `.modal-backdrop > .modal`, with `.modal-head` holding the `h2` and the `.modal-close` control; the server-rendered confirm page uses `form.card.form` inside `main.page.narrow`. Inside a dialog `.form-actions` puts Cancel left and the one primary action right.

### Menus

The site menu is the toolkit `<ilw-header-menu slot="navigation">` fed by `site_pages()`; it lists every page except the one in view, and the home page shows no menu (its cards lead everywhere). Administrative screens list in `admin_pages()` and reach each other through the overview cards and the breadcrumb.

### Tabs and filters

No tab strip: the identity gate on `index.php` uses one heading per choice. Filter bars use one pattern: `form.report-filters` inside the accordion or card that holds the list, fields in `.field`, one `.button.button-small` submit labelled with one verb (Filter); the billing report keeps Run Report (PCE-18).

### Tables

`.card.card-tight > .table-wrap > table.data`, or `.accordion-body > .table-wrap > table.data` edge to edge with no inner card (PCE-05, PCE-17). Numbers in `td.num`; row states `tr.is-voided`, `tr.is-locked`, `tr.subtotal`, `tr.grand`; empty list `<td colspan="n" class="empty">` with one sentence.

```html
<div class="card card-tight"><div class="table-wrap"><table class="data">
  <thead><tr><th>Instrument</th><th class="num">Hours</th></tr></thead>
  <tbody><tr><td>Confocal</td><td class="num">3.5</td></tr></tbody>
</table></div></div>
```

### Panels and cards

`.card` (1.25rem padding, `--shadow`); `.card-tight` only around a table; `details.accordion` through `accordion_open($id, $title, ['tone' => ...])` with tones `critical`, `warning`, `notice`, `opportunity`; `.grid-cards` of `a.card` for link cards; `.totals-strip > .total-tile` for figures; `.notify-card` for message previews; `.lab-cards > .lab-card` for laboratories.

### Page header and footer

Every templated page calls `page_header($title, $options)` or `admin_header($key, $title)` and `page_footer()`. Order: toolkit header (primary unit INHS from `unit_name`, site name from `app_name`, utility links for the person and administrator state, the menu), the lab bar (`.lab-bar`, laboratory picker and dashboard link), breadcrumbs on admin pages (`nav.crumbs`), `main.page`, queued flash messages, then the toolkit footer (PRI primary unit, INHS address, Find Collaborators column, the app's own links), and below it, outside the toolkit markup, the credit line `p.app-credit` "Developed by M. J. Dreslik, with Claude Opus 5" in hint text (`--fs-hint`, `--color-ink-soft`, centred). Interface Text repeats the credit in an About card. The gate fails when the footer line or the NOTICE file is missing (drez-ui-elements rule 27, D-021). The page title is an `<h1>` followed by `p.lede`, or `.page-head` when a control sits beside the title. Every page has exactly one `<h1>` (PCE-09). Crumbs and lab bar follow the width class of `main` (PCE-24).

### Navigation

Breadcrumbs `nav.crumbs > ol > li` with the `›` separator drawn by CSS. The admin overview shows one card per `admin_pages()` entry in the same order; Studio shows only to holders of the code-editing grant (PCE-07).

### Flash messages and alerts

`flash($message, $type)` queues a message across a redirect; `flash_render()` prints it as `.flash.flash-success`, `.flash.flash-notice` or `.flash.flash-error`. `calendar.js` `say(text, kind)` writes the same classes in place. Lists of issues use `.alert-list` with `.alert-tag` and tones `.alert-critical`, `.alert-warning`, `.alert-notice`, `.alert-opportunity`. Never an inline style for a warning box (PCE-03).

### Status badges and pills

`.pill` with `.pill-ok`, `.pill-warn`, `.pill-error`, `.pill-locked`; uppercase `--fs-xs`, 600 weight. One label and one tone per state (PCE-11; the vocabulary waits on the owner, section 9). Count chip: `.alert-count`.

### Tooltips

`.cal-tip` on calendar blocks only: navy fill, white text, `--shadow-overlay`, `--z-tip`. Elsewhere use `p.hint` text, never a `title` attribute as the only explanation.

### Empty, loading and error pages

Empty list: `.empty` (centred, `--color-ink-soft`, one sentence with a period). Loading: pages render on the server; the calendar draws its grid when the data arrives and reports a failed load through `say()` as `.flash-error`. No spinner exists and none is needed. Error pages: the exception page, the headers-sent fallback and `auth_stop()` move to one minimal shell carrying the house tokens and the favicon (PCE-25); `check.php` keeps its own inline palette because it runs before `style.css` can load, and `install.php` stays chromeless by design.

### Icons

No icon set. Approved glyphs: `&larr;` and `&rarr;` for week steps and back links, `›` in crumbs, the CSS triangle on accordions. No emoji. Favicon: navy tile, white instrument, white check on an orange dot that moves from `#E84A27` to `#C84113` (Q-26).

### Calendar

`schedule.php` with `assets/calendar.js`: `.cal-toolbar` (instrument select, week steps, Today), `.cal-legend` swatches, `.cal-grid` of `.cal-corner`, `.cal-dayname` (with `.is-today`), `.cal-hour` and `.cal-cell` (`.weekend`, `.hour-line`), blocks `.cal-block` (`.mine`, `.selected`, `.dragging`, label sizes `.label-full`, `.label-compact`, `.label-tiny`, `.label-none`), `.cal-ghost`, `.cal-tip`, `.cal-actionbar`, `.cal-hintbar`. Grid placement uses the `.c1` to `.c8` and `.r1` to `.r49` classes plus the inline positions `calendar.js` writes. Own bookings fill `--cal-mine` (`#C84113`) with white text.

## 4. Dialogs and feedback

In-app dialog helper (Q-27): `assets/dialog.js`, plain JavaScript, loaded by `page_footer()` on every page. The calendar's `openModal()` in `calendar.js` builds the same shell around its own form.

```js
PriDialog.ask({ title, message, label, value = '', placeholder = '', okLabel = 'OK', cancelLabel = 'Cancel', required = false })
  // Promise<string|null>: the text, or null on Cancel, Escape or a backdrop click
PriDialog.confirm({ title, message, okLabel = 'OK', cancelLabel = 'Cancel', danger = false })
  // Promise<boolean>
PriDialog.notify({ title, message, okLabel = 'OK' })
  // Promise<void>
```

Shell, built from the existing classes: `.modal-backdrop > .modal[role=dialog][aria-modal=true][aria-labelledby]`, an `h2` title with a close button (`aria-label="Close"`, `&times;`) in the title row, the body, then `.form-actions` with Cancel (`.button.button-secondary`) on the left and the main action on the right as the only primary button (`.button`, or `.button.button-danger` when `danger` is true). Escape and a backdrop click cancel; focus moves to the first field or the main action and returns to the trigger on close.

Forms that change or remove data: replace `onsubmit="return confirm(...)"` with `data-confirm`; `dialog.js` intercepts the submit, asks through `PriDialog.confirm`, adds `confirmed=1` and submits on yes.

```html
<form method="post" data-confirm="Cancel this booking?" data-confirm-ok="Cancel Booking" data-confirm-danger>
```

Server-rendered confirm page, for a request without `confirmed=1` (JavaScript off or blocked): `confirm_page($title, $question, $okLabel, $fields, $cancelHref, $danger = false)` in `includes/functions.php` renders `page_header($title)`, a `form.card.form` in `main.page.narrow` holding the question, the posted fields as hidden inputs, `confirmed=1`, Cancel as `a.link-quiet` to `$cancelHref` on the left and the main action on the right. The handler calls it, guarded by `needs_confirmation()`, and stops before it writes anything.

No `prompt()`, `confirm()` or `alert()` is left in the application: the 15 `confirm()` calls became `data-confirm` on 14 forms and `PriDialog.confirm` in `calendar.js`, each keeping its words. The Units hide and show toggle, which asked nothing before, asks now.

Feedback places: inline errors beside the field (`.field-error`) and `.flash-error` above the form; `flash()` after a redirect for completed actions; the calendar's `say()` for actions on the grid. No toast and no banner exist; app-wide state shows in the lab bar.

## 5. Layout

| Item | Value |
|---|---|
| Content width | `main.page` 1180px; `.narrow` 640px; `.wide` 1400px; `body.schedule-page main.schedule-main` full width |
| Page padding | 1.75rem top, 1.25rem sides, 3rem bottom |
| Crumbs and lab bar | follow the width of `main` on the page (today fixed at 1180px, PCE-24) |
| Card padding | 1.25rem; card gap 1.5rem below; grid gap 1rem |
| Grids | `.grid-cards` auto-fill, minimum 260px; `.field-row` two columns, `.field-row-3` auto-fit minimum 150px |
| Stacking | `.stack`: list above the form that edits it, never side by side |
| Breakpoints | one at 60rem (Studio); stack fixed pairs below about 36rem (PCE-31) |
| Print | toolkit chrome, crumbs, filters and buttons hidden; cards without shadow |

## 6. Copy

A page title names the page. It is never a sentence, a question or an instruction: "No Laboratory Assigned", not "You are not in a laboratory yet"; "Error", not "Something went wrong"; "Installation", not "Install the equipment system". Whatever the sentence said belongs in the lede beneath it (estate rule 20, Q-33). The same holds for a card title.

Casing (Q-25): Title Case for buttons, menu items, tabs, dialog titles, page titles (`<h1>`, `<title>`), card titles and breadcrumbs: Save Booking, Add Grant, Run Report, Use Entry, Billing Report, Usage Records, Export History. Sentence case (Q-29 for form field labels) for body text, hints, form field labels, flash messages, table cells, placeholders and confirm questions: "Cancel this booking?". Small words (a, an, and, for, in, of, on, the, to) stay lowercase inside a Title Case label. Writing rules: no em dashes, no contractions, no filler; separators in option labels use a middle dot `·` (PCE-27).

Glossary (settled by the owner, Q-15 to Q-17, D-020; one name per thing):

| Thing | Name on screen |
|---|---|
| Application | PRI Shared Equipment, from the `app_name` setting |
| Use entry screen | Use Entry |
| Calendar screen | Calendar |
| Billing report | Billing Report |
| Units | Units |
| Administrators | Administrators, in every identity mode |
| The thing being used | equipment (a mass noun: "a piece of equipment") |
| Time held on the calendar | reservation |
| A closed billing period | export |
| The account a charge lands in | receiving subaccount |
| The person who did the run | operator last name |
| A charge waiting to be billed | Pending (grey pill) |
| A charge inside an export | Exported (navy pill, the export number beside it) |
| A grant past its end date | Expired (red pill) |
| Anything taken out of service | Retired (grey pill) |
| Laboratory | Laboratory (never "lab" in titles) |
| Studio | Studio: code editing, separate grant |

The laboratory's own batch or run identifier on a charge keeps that name. It is the laboratory's label for a run, not an export.

Formats: `pretty_date()` gives `M j, Y` (Oct 17, 2026); `pretty_datetime()` gives `M j, Y g:i a` (Oct 17, 2026 3:04 pm); times on today's lists `g:i a`; money through `money()` (`$1,234.50`); hours to one or two decimals in `td.num`. Store times as ISO text. Empty value: the em dash (Q-30), `&mdash;` alone in a table cell or read-only field; prose, labels, messages and help text never use it (PCE-27).

## 7. Adding a feature

1. Read this file; find the component in section 3.
2. If none fits, add the component or variant here first, with its tokens and states.
3. Use tokens only; no literal colour, size or radius, no `style=""`.
4. Put the page through `page_header()` or `admin_header()` and `page_footer()`; give it one `<h1>` and add it to `site_pages()` or `admin_pages()` and to the overview cards.
5. Ask through `PriDialog` or the confirm page, never `confirm()`.
6. Format dates with `pretty_date()` and `pretty_datetime()`, money with `money()`.
7. Add the new page or include to the ship list in `tools/build-release.ps1`.
8. Run the baseline checks (drez-ui-elements, Checks) and `node tools/check.js`.
9. Remove the section 8 row the change fixes; rebuild `reference\UI Elements.docx`.

## 8. Known deviations

None. The audit of 2026-10-17 found 38, the spec added one more while the work
was going on, and the owner answered the eight questions the last six waited
on, all on the same day (D-020).

Three things are true of the application rather than wrong with it, and the next
pass should know them.

- Six pieces of wording are rows in the settings table rather than in a file:
  `app_name`, `entry_title`, `entry_instructions`, `schedule_title`,
  `schedule_instructions` and `report_title`. `includes/schema.php` holds what
  a fresh install gets; an existing installation keeps its own until somebody
  edits them on the Interface Text screen. No agent writes the live database;
  A-07 in `reference\open-questions.md` lists the six with their new text.
- The type comes from the Illinois kit at `use.typekit.net` and stays there
  (Q-20, the owner, 2026-10-18): Source Sans Pro as campus serves it, no
  vendored copy, and a system sans in the stack for a machine with no
  network. Ask only for the weights the kit serves.
- `lab_name` is a setting nothing reads since the application took its name
  from `app_name` (Q-15). It is seeded and editable, and it can be retired
  whenever the owner says so.

## 9. Decisions

Estate decisions (2026-10-17):

- Q-25: Title Case for buttons, tabs, menu items, dialog titles and page titles; sentence case for body, help, messages and placeholders. Here the menu, admin page names, buttons and `<h1>` titles move to Title Case (PCE-34); the footer heading "Find Collaborators" already complies.
- Q-26: Illinois Orange `#FF5F05` replaces `#E84A27`; white text sits on `#C84113`, never on `#FF5F05`. Here: `--accent2`, `--cal-mine`, link hover, today's date and the favicon dot (PCE-19, PCE-37). Navy stays the primary.
- Q-27: native dialogs give way to the in-app helper, `prompt()` first. Here `assets/dialog.js` (`PriDialog`) and `confirm_page()` replace the 15 `confirm()` calls (PCE-35).
- Q-28: this file in the nine baseline sections is the spec; the owner reads `reference\UI Elements.docx`; a check script joins the gate.
- Q-29: form field labels take sentence case, as the labels here do today; buttons, tabs, menu items and titles take Title Case.
- Q-30: the em dash stays the mark for an empty value in table cells and read-only fields, as `&mdash;` in a cell does today. PCE-27 still covers em dashes in visible prose.

App decision taken while carrying the audit out: D-019, the Illinois toolkit is pinned to 3.14.0 instead of the floating `/3/` path, so an upstream release cannot change the chrome of every screen unannounced (PCE-33). 3.14.0 was byte-identical to what `/3/` served on 2026-10-17.

App decisions that shape the UI: D-015 (three identity modes, so copy must hold in `password`, `local` and `proxy`, PCE-28); D-016 (owner as the one code editor, so Studio shows to one NetID at first); D-017 (favicon in place of launchers); D-018 (adoption of this spec). AGENTS.md records the Illinois toolkit as the one approved outside dependency.

Answered by the owner on 2026-10-17 and built (D-020): Q-15 the application name, Q-16 one name per screen, Q-17 the glossary, Q-18 the Studio wording, Q-19 the status vocabulary, Q-20 the type, Q-21 the chrome, Q-22 the greys. Nothing about the interface is open except the one question inside Q-20, whether Source Sans 3 may stand in for Source Sans Pro when the faces are vendored offline, and A-07, the six rows of wording only the owner can change.
