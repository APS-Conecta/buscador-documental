# 03. UI guidelines

Status: draft v3. Visual reference: `mockup/index.html` (open in a browser; the top bar switches view and state). The mockup is the source for layout and microcopy; this file is the source for rules. UI strings are Spanish literals (org practice, no `t()` catalogue unless P2 ADR changes it), usted/impersonal, using `glosario.yml` terms.

## APS Conecta theme (mandatory)
Source: documentation `desarrollo/tema.md` and `gestion/themes/apsconecta/core/css/server.css`. The product never hard-codes colours: it uses Nextcloud variables, which the theme maps to the palette below. The mockup declares the same values only because it runs outside Nextcloud.
| Token | Hex | Use |
|---|---|---|
| Primary violet | `#7f21fe` (`--color-primary-element`) | primary button, focus ring, selected state |
| Dark violet | `#5315a8` | titles, side menu, selected text |
| Ink | `#101828` | body text |
| Muted | `#485363` | secondary text |
| Error pink | `#ea003e` | borders/UI only; error text uses `--color-text-error`, never the pink |
| Gold / dark gold | `#e06f00` / `#9a4c00` | gold for fills/icons/large text; dark gold for small text (e.g. "Lectura incierta" badge) |
Fonts: Fraunces for h1-h3 and the top bar, Nunito Sans for everything else (served by the server; no third-party requests). Light theme only (`enforce_theme=light`); the only contrast boost is `prefers-contrast: more`. Theme rules to respect: variables declared in `:root` lose inside `body`; measure on `document.body`; no manufacturer names in visible text.

## App layout (as in the sibling apps, e.g. farmacia `src/App.vue`)
`NcContent` > `NcAppNavigation` (scope list) > `NcAppContent` (search, filters, summary row with order and page size, results, pager) > `NcAppSidebar` (selected document). **No extra bars:** the top bar and menu are the server's own (Nextcloud + APS theme); the app draws none. Navigation items are scopes: Todos los documentos, Mis archivos, Compartidos conmigo, Con lectura incierta. Subpath imports only (`@nextcloud/vue/components/NcButton`, …). Below 720 px the navigation collapses to a horizontal list and the sidebar opens as an overlay.

## Components to reuse (already used by farmacia, territorio, estadistica, epidemiologia)
| Need | Reuse |
|---|---|
| Shell | `NcContent`, `NcAppNavigation`, `NcAppNavigationList`, `NcAppNavigationItem`, `NcAppContent`, `NcAppSidebar` |
| Search box | `NcTextField` (type search) |
| Order, page size, filters | `NcSelect` |
| Low-confidence toggle | `NcCheckboxRadioSwitch` |
| Result rows | `NcListItem` (+ `NcDateTime`, `NcChip` for the badge) |
| Banners (preparing, partial, error) | `NcNoteCard` |
| Empty and error states | `NcEmptyContent` |
| Confirmations | `NcDialog` |
| Admin sections | `NcSettingsSection` |
| Buttons | `NcButton` (primary / secondary / tertiary) |
| Text folding (accent-insensitive match) | `aps-common` JS `fold()` / `textMatches()` via `make aps-sync`; PHP `Text.php` |
| Errors and refusals | `aps-common` `Refusal` / `Refuses`; `api.js` for requests |
| Loading placeholder | the theme's `.aps-mount-loading` fragment (gestion `themes/apsconecta/MAPEO.md`) |
New custom components only for what none of these cover (the pager, the result snippet with highlights). Peer-compare against farmacia before adding one.

## Buttons (simple, one primary per area)
Use `NcButton` variants only. **Primary:** "Buscar" (search form), "Abrir en la página N" (sidebar). **Secondary:** "Copiar enlace", "Quitar filtros", page-number buttons. **Tertiary:** "Anterior", "Siguiente", "Abrir en la página N" on cards, "Excluir", "Reintentar". No icon-only buttons without an `aria-label`; no more than two buttons per result card.

## Results: paging, order and filters
- **Page size:** "Resultados por página" 25 (default), 50, 100. Remembered per user and kept in the URL with page and order.
- **Pager:** "Anterior", numbered pages (first, last, current ±1, ellipsis), "Siguiente". Summary line "Mostrando 26–50 de 87 documentos" (`aria-live="polite"`). Changing size, order or any filter returns to page 1.
- **Order ("Ordenar por"):** Relevancia (default with a query), Más recientes, Más antiguos, Nombre (A-Z), Nombre (Z-A). Without a query the default is Más recientes.
- **Filters:** Tipo (PDF, Documentos, Planillas, Imágenes), Carpeta / carpeta de equipo, Modificado (cualquier fecha, última semana, último mes, último año, rango), Propiedad (Todos, Mis archivos, Compartidos conmigo), "Solo con lectura incierta". The navigation scopes set Propiedad and the low-confidence filter; "Quitar filtros" resets everything.
- **Depth cap:** at most the first 100 readable matches (`LISTED`, ADR-0010 amendment) are paged. When the list is incomplete (the `LISTED` bound, or, counted before the recheck, the 1 000 `CAP` or a full keyword candidate window in Exacta) the page says "Puede haber más documentos de los que se muestran; afine su búsqueda" and the total is shown as a lower bound ("más de …"); otherwise it is exact.
- **Selection:** clicking a card (or its title) selects it and opens the sidebar; "Abrir en la página N" opens the file.

## Principles
1. One search box, one obvious primary action. Modes and filters are secondary and collapsible on narrow screens.
2. Honest state: always say what the index can and cannot do now (preparing, partial, service down) and what the user can do next.
3. Never expose internals: no "embedding", "OCR", "índice vectorial", vendor or model names. Say "leer documentos escaneados", "búsqueda por significado", "lectura incierta".
4. Nextcloud-native: `@nextcloud/vue` 9 with subpath imports only, Nextcloud CSS variables only, no hard-coded colours. The org forces the light theme; high contrast only through `prefers-contrast: more`.
5. Fast first paint: initial JS <= 250 KB gzip, results stream in as one response, no layout shift.

## Surfaces
| Surface | Content | Rules |
|---|---|---|
| Unified search bar | top 5 hits: title, `p. N`, one-line snippet, link "Ver todos en Buscador Documental" | keyword only, no model call, debounced 250 ms |
| Sidebar app page | search box, mode toggle (Exacta / Por significado / Mixta, default Mixta), order, filters, result cards, pager with page size 25/50/100, status banner; details in `NcAppSidebar` | see state matrix and "Results" above |
| Result card | title link, path, page, date, owner, highlighted snippet, badge "Lectura incierta" when low OCR confidence, one tertiary action "Abrir en la página N" ("Copiar enlace" lives in the sidebar) | highlights use `<mark>` with underline (not colour alone); snippets escaped server-side |
| Files tab "Texto reconocido" | status, confidence, text, "Volver a leer este documento" (owner/admin, rate-limited) | minimal in R1 |
| Admin page | health, progress with estimate, included folders (exclude with preview), skipped files (reason, retry; low-confidence filter), profile panel (recommended vs active, edit), pause/resume | plain-language first-run notice with one-click "Pausar lectura" |

## State matrix (each has a mockup state)
Results · loading (skeleton, `role=status` text) · no results (hint to loosen query) · permission-filtered empty (no leak of existence) · preparing N % (keyword live, meaning pending, estimate) · meaning search unavailable (fallback message) · service down (`role=alert`, files safe) · rate limited (`Retry-After` shown) · OCR pending per file · partial index.

## Interaction rules
- Enter submits; arrows move through results; Esc clears the unified bar; mode and filters keep their state in the URL (shareable, back button works).
- Disabled mode buttons use `aria-disabled` plus explanatory title/text, never silent.
- Result count announced via `aria-live="polite"`; errors via `role="alert"`.
- "Abrir en la página N" uses the Files viewer; PDF.js `#page=N` deep link is unverified (S6 spike) with fallback to open the file and show the page number.

## Accessibility and responsive
WCAG 2.2 AA: visible focus (3 px), target >= 44 px, contrast >= 4.5:1, keyboard-only path for search → open result, no information by colour alone, `prefers-reduced-motion` stops skeleton animation, 360 px layout without horizontal scroll, axe clean in CI.

## Microcopy rules
Short sentences; verbs first ("Pruebe con menos palabras"); say what happened, then what to do; no blame; numbers with Chilean format (1 214, 38 %); dates "12 sept 2026". New terms go to `glosario.yml` with an l10n source.

## Mockup workflow (gate before UI code)
1. Update `mockup/index.html` for the slice (states, copy, new surfaces).
2. `/bd-mockup` runs `bd-ux-auditor` (a11y, states, copy vs glossary, 360 px) and writes a review artifact.
3. Human design-review gate: the user accepts or adjusts the mockup. Only then may S4 (mode UI) and S6 (UI) start implementation.
4. After accepting, the mockup is frozen as `mockup/v1/` and linked from the site docs; later changes require a new review.
