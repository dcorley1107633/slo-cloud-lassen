# Dynamic Report Designer — Design and Functional Specification

## 1. Document purpose

This document is the normative product, interaction, data, security, and accessibility specification for the SLO Cloud Dynamic Report Designer, labeled **Custom Report** in navigation and **Report Manager** in the page header.

The Course Orbit AdminLTE 4 report builder is the reference design. SLO Cloud must present the same information architecture, visual hierarchy, control patterns, and report-running workflow while retaining SLO Cloud's own live database catalog, table prefixing, SQL Server/MySQL compatibility, authorization, audit logging, starter reports, and catalog-repair behavior.

The words **must**, **should**, and **may** are used intentionally:

- **Must** identifies a required product or security behavior.
- **Should** identifies the preferred behavior unless a documented constraint prevents it.
- **May** identifies an optional extension that must not change the core workflow.

## 2. Product goals

The designer must let an authorized user:

1. Find and run a saved report without writing SQL.
2. Supply optional or required runtime filters.
3. View a readable, scrollable result table.
4. Print the same result in a clean print layout.
5. Export the same displayed rows, including totals, to a spreadsheet-safe CSV file.
6. Create, edit, duplicate, raw-edit, and delete report definitions when the user has management permission.
7. Build reports from a curated live-database catalog rather than from arbitrary identifiers.
8. Join related SLO Cloud data through catalog-approved relationships.
9. Aggregate, sort, group, create control breaks, and calculate subtotal and grand-total rows.
10. Detect saved reports that no longer match the current database catalog and guide an administrator through repair.

The experience must feel like a native part of the shared AdminLTE 4 administration interface. It must not look like a separate application or a generic schema editor.

## 3. Non-goals

The Dynamic Report Designer is not:

- A general SQL console.
- A way to enter custom SQL expressions, table names, field names, join clauses, or functions.
- A replacement for purpose-built operational screens.
- A visualization or chart designer.
- A scheduled-report or email-delivery service.
- A mechanism for exposing fields excluded by the report catalog.
- A mechanism for bypassing SLO Cloud authorization, table prefixes, database portability, or audit requirements.

## 4. Terminology

| Term | Definition |
| --- | --- |
| Report Manager | The saved-report list at `administration/dynamic_reports.php`. |
| Report Designer | The visual create/edit mode of the same page. |
| Definition | The persisted JSON description of one report. |
| Catalog | The generated, curated description of reportable tables, fields, types, operators, aggregates, and relationships. |
| Base table | The fixed `terms` table from which every custom report relationship path begins. |
| Joined table | A table connected to the base through one or more selected catalog relationships. |
| Display column | A selected result field, optionally aggregated, sorted, grouped, or summarized. |
| Runtime filter | A saved prompt whose value is supplied when the report runs. |
| Control break | A non-aggregated column that defines ordered groups and produces group subtotal rows. |
| Summarize | A numeric display-column option that includes the column in subtotal and grand-total calculations. |
| Needs repair | A saved definition that fails validation against the current catalog. |

## 5. Users and permissions

### 5.1 Report viewer

An authenticated user allowed into the administration reporting area may:

- Open Report Manager.
- See saved reports.
- Open a valid saved report.
- Enter filter values.
- Run the report.
- Open Print View.
- Export CSV.

A viewer must not see or invoke definition-management actions.

### 5.2 Report manager

A global administrator, including an enabled emergency administrator, may additionally:

- Create a report.
- Edit a visual definition.
- Edit raw JSON.
- Duplicate a report.
- Delete a report.
- Refresh the report catalog.
- See generated SQL after a successful run.

Management authorization must be checked on the server for every management request. Hiding a control in the UI is not sufficient authorization.

## 6. Shared visual design

### 6.1 Design system

The feature must use the SLO Cloud AdminLTE 4 administration shell and its installed Bootstrap 5 and Font Awesome assets. It must not load another copy of Bootstrap, AdminLTE, jQuery, or Font Awesome.

Feature-specific styles live in `assets/css/custom-reports.css`. They may refine report cards, compact editor controls, report tables, multiselects, and responsive behavior, but they must not globally restyle unrelated administration pages.

### 6.2 Page shell

- Navigation label: **Custom Report**.
- Active navigation key: `admin_dynamic_reports`.
- List page title: **Report Manager**.
- Create page title: **New Report**.
- Edit page title: **Edit Report**.
- Raw page title: **Raw Report Definition**.
- Viewer page title: the saved report name.
- Breadcrumb trail: **Administration / Custom Report**.
- The page must not repeat the same H1/H2 immediately inside the content area.

### 6.3 Cards

- Cards use the shared AdminLTE/Bootstrap surface, border, radius, and color tokens.
- Card headers have transparent backgrounds, a bottom border, semibold text, and a leading Font Awesome icon where the reference design uses one.
- Tables inside cards use a zero-padding card body so table edges align with the card.
- Primary actions use `btn-primary`.
- Secondary navigation and non-destructive actions use outline buttons.
- Destructive actions use `btn-outline-danger`.
- Raw JSON save uses a warning-colored button to distinguish advanced editing.

### 6.4 Text and naming

- User-facing labels use title or sentence case consistently with Course Orbit.
- Internal table and field identifiers may appear only as secondary technical context, generally inside parentheses or code styling.
- Button text must use the Course Orbit action names: **New Report**, **Refresh Catalog**, **View**, **Save Report**, **Run Report**, **Print View**, **Export CSV**, **Edit Definition**, and **Report Manager**.

## 7. Information architecture and routes

The feature is implemented as modes of `administration/dynamic_reports.php`:

| Mode | Request | Purpose |
| --- | --- | --- |
| List | no `mode` | Report Manager and catalog summary. |
| Create | `mode=edit` | Blank visual designer. |
| Edit | `mode=edit&id={id}` | Visual designer for a saved report. |
| View/run | `mode=view&id={id}` | Filters and report results. |
| Raw edit | `mode=raw&id={id}` | Validated JSON editor. |
| Print | `mode=print&id={id}` | Bare printable document. |
| Export | `mode=export&id={id}` | CSV download. |

Searchable multiselect options are provided by:

```text
administration/report_options.php?id={reportId}&filter={filterIndex}&q={searchText}
```

The endpoint must return JSON containing `errors`, `values`, and `truncated`.

## 8. Report Manager specification

### 8.1 Catalog and action bar

The top content row contains:

- Left: `Configurable reports built from the master data catalog.`
- Second line: catalog table count, relationship count, refresh time, and source.
- Right, managers only: **Refresh Catalog** and **New Report**.

The catalog information must be concise inline metadata, not a large statistics card. On narrow screens the two sides may wrap vertically with a visible gap.

### 8.2 Refresh Catalog

Selecting **Refresh Catalog** must:

1. Display a confirmation explaining that removed tables or fields can make reports require repair.
2. Submit a CSRF-protected POST.
3. Rebuild the catalog from the connected live database.
4. Persist the new catalog.
5. Revalidate every stored report without rewriting any definition.
6. Audit the refresh with catalog statistics and the affected report names.
7. Redirect back to Report Manager with a success summary.

The build and validation sweep are two explicit ordered steps. A production catalog must never be generated from the checked-in DDL.

### 8.3 Saved reports table

The Reports card contains a striped, hoverable, horizontally responsive table with these columns in this order:

| Column | Content |
| --- | --- |
| Report | Linked report name and a **Needs repair** warning badge when validation fails. |
| Description | Muted description text. |
| Base Table | Friendly catalog table label in code styling. |
| Columns | Count of display columns. |
| Filters | Count of runtime filters. |
| Updated | Local formatted date/time or an em dash. |
| Actions | Right-aligned, non-wrapping action group. |

The report name and **View** action both open the viewer.

Manager actions are:

1. **View** — outlined primary button with play icon and text.
2. **Edit** — icon-only outlined secondary button.
3. **Edit raw JSON** — icon-only outlined warning button.
4. **Duplicate** — icon-only outlined secondary POST button.
5. **Delete** — icon-only outlined danger POST button.

Every icon-only action must have an accessible name containing the action and report name. Duplicate and delete must be CSRF protected. Delete must display a confirmation containing the report name.

### 8.4 Empty state

When no saved reports exist, the table has no placeholder row. A centered message below the empty table says:

- Manager: `No reports yet. Use "New Report" to create the first one.`
- Viewer-only user: `No reports yet.`

## 9. Visual Report Designer specification

### 9.1 Overall workflow

The designer is intentionally ordered:

1. Define the report identity; the base is already fixed to Terms.
2. Select relationships that crawl outward from Terms.
3. Add and order display columns.
4. Add runtime filters.
5. Set result options.
6. Save and validate.

The designer stores state in a client-side definition object and serializes that object into a hidden `definition` field on submit. The server must normalize and validate the submitted object before persistence.

### 9.2 Validation messages

- Server validation errors appear in a danger alert above the designer.
- The alert receives programmatic focus through the shared accessibility behavior.
- All submitted values that remain structurally usable should be preserved when the editor is rerendered.
- When opening an older report, unavailable joins, columns, or filters may be pruned in the browser only to make the editor usable. A warning alert must list the pruned items and tell the user to review before saving.

### 9.3 Report card

The **Report** card contains:

| Control | Requirement |
| --- | --- |
| Name | Required text input, maximum 160 characters. |
| Description | Optional text input, maximum 500 characters. |
| Base table | Disabled select showing `Terms (terms)` with help text explaining that all custom reports connect outward from Terms. |
| Joined tables | Bordered, muted panel listing relationships currently reachable from the selected query graph. |

Desktop layout:

- Name occupies four of twelve columns.
- Description occupies eight of twelve columns.
- Base table occupies four of twelve columns.
- Joined tables occupy eight of twelve columns.

On narrow screens these controls stack using the Bootstrap grid.

### 9.4 Terms-root behavior

Every custom report must use `terms` as its base table. The base is a fixed design invariant, not a user-selectable preference.

This produces one consistent exploration model:

```text
Terms
  -> Courses -> Sections / Enrollment / CSLOs / Programs / Assessments
  -> Sections -> Courses -> Disciplines
  -> Programs -> Program Outcomes -> CSLO mappings
  -> Disciplines -> Courses / Programs
  -> Institutional Outcomes -> Program Outcomes
  -> Cross-listed Sections
```

The actual paths available in an installation still come exclusively from the live catalog. The diagram describes the intended crawl direction, not permission to synthesize missing joins.

Requirements:

1. A new blank definition starts with `base_table: "terms"`.
2. The visual designer displays Terms in a disabled base-table select.
3. The server validator rejects every other base table, including through raw JSON.
4. The joined-table panel initially exposes relationships directly connected to Terms.
5. Selecting a relationship exposes the next reachable layer, allowing the user to crawl outward.
6. Every saved join list must be ordered as a connected traversal beginning at Terms.
7. When an older definition is opened visually, the client re-roots it at Terms, reorders still-connected joins, prunes disconnected fields, and displays a review warning.
8. Shipped legacy starter reports are migrated only when their old base and relationship graph match the known shipped signature; administrator-defined columns, filters, labels, and options are preserved.

### 9.5 Joined tables

- Relationships come exclusively from `catalog.relationships`.
- The panel initially lists all relationships directly reachable from Terms.
- When no relationship is available, it says `No related tables are available for this base table.`
- Each available relationship is a labeled checkbox.
- Selecting a relationship adds both relationship endpoints to the connected-table set and may reveal additional relationships.
- Clearing a relationship removes it and prunes downstream relationships that are no longer connected.
- The relationship order saved in JSON must be a valid traversal from the base table.
- SQL execution uses `LEFT JOIN` for each selected relationship.

### 9.6 Display columns card

The header contains:

- Left: table-columns icon and **Display columns**.
- Right: a grouped field picker and **Add column** button.

The picker initially contains Terms fields. It expands with fields from every table connected by the selected outward relationship path, grouped by friendly table label.

Selecting **Add column** appends the selected field to the end of the display list with these defaults:

```json
{
  "table": "selected_table",
  "field": "selected_field",
  "label": "",
  "aggregate": "",
  "sort": "",
  "break": false,
  "summarize": false
}
```

The compact table columns are:

| Column | Behavior |
| --- | --- |
| Order | Move-up and move-down icon buttons. |
| Field | Read-only friendly table and field name. |
| Label override | Optional compact text input; placeholder is the catalog field label. |
| Aggregate | Type-appropriate aggregate selector; blank means group/raw value. |
| Sort | None, Asc, or Desc. |
| Break | Checkbox defining a control break. |
| Summarize | Checkbox enabled only for numeric or aggregated output. |
| Remove | Icon-only danger action. |

Rules:

- At least one display column is required to save.
- Column order is result order.
- A column cannot be both aggregated and a control break.
- Selecting an aggregate clears its break flag.
- Selecting a break clears its aggregate.
- Sort order is evaluated in display-column order after control-break precedence.
- Move buttons must preserve every other column property.
- Removing the last column reveals the empty message `No display columns yet.`
- After add, move, remove, or rerender, keyboard focus should move to the most logical surviving control rather than resetting to the document start.

### 9.7 Filter parameters card

The header contains:

- Left: filter icon and **Filter parameters**.
- Right: a grouped field picker and **Add filter** button.

The picker uses the same Terms-rooted connected tables and groups as the column picker.

Selecting **Add filter** appends the selected field with the first allowed operator for its catalog type and these defaults:

```json
{
  "table": "selected_table",
  "field": "selected_field",
  "operator": "type_default_operator",
  "control": "textbox",
  "label": "",
  "required": false,
  "default": "",
  "default_to": ""
}
```

The compact table columns are:

| Column | Behavior |
| --- | --- |
| Order | Move-up and move-down icon buttons. |
| Field | Read-only friendly table and field name. |
| Prompt label | Optional label override shown to the report runner. |
| Input | Textbox or Dropdown multiselect when `in` is allowed. |
| Operator | Type-appropriate operator. Disabled and fixed to `in` for multiselect. |
| Default value(s) | One input, two inputs for `between`, `n/a` for blank/not-blank operators. |
| Required | Checkbox. |
| Remove | Icon-only danger action. |

Rules:

- Filters are optional as a collection.
- Filter order controls the viewer prompt order and the runtime `f0`, `f1`, and subsequent parameter sequence.
- Moving a filter must preserve its field, label, control, operator, defaults, and required state.
- A multiselect is available only when the field type allows the `in` operator.
- Switching to multiselect sets the operator to `in`.
- Multiselect defaults are stored as a string array and edited as comma-separated values in the designer.
- `between` shows lower and upper default inputs.
- `is_null` and `not_null` show no default input.
- Changing the operator must immediately update the default-value control shape.
- Removing the last filter reveals `No filters — the report will return everything up to the row limit.`
- Filters on a joined table may exclude base rows without a matching joined record because the filter is applied in the SQL `WHERE` clause.

### 9.8 Options card

| Option | Requirement |
| --- | --- |
| Row limit | Integer from 1 through 5,000; default 500. |
| Show grand total row | Bootstrap switch; default on. |

The server clamps an out-of-range row limit even when client validation is bypassed.

### 9.9 Save and cancel

- **Save Report** is the primary action and includes a save icon.
- **Cancel** returns an existing report to its viewer and a new report to Report Manager.
- Saving is a CSRF-protected POST.
- The client trims name and description and serializes the complete definition.
- The server validates catalog identifiers, connectivity, operators, aggregates, column requirements, row limit, and property types.
- A successful create or update is audited and redirects to the report viewer with a success message.
- The report ID is supplied by the route/server and cannot be reassigned by client JSON.

## 10. Raw JSON editor specification

The raw editor is a manager-only advanced interface.

It contains:

1. A warning alert stating that invalid JSON and invalid definitions will not be saved.
2. A **Raw report JSON** card.
3. A monospaced textarea showing pretty-printed UTF-8 JSON.
4. **Validate and Save JSON**, **Cancel**, and **Visual Editor** actions.

Requirements:

- The textarea is required and has spellcheck disabled.
- The submitted JSON must decode to an object/associative array.
- The embedded ID must exactly match the route ID.
- Raw editing uses the same normalizer and catalog validator as visual editing.
- Raw editing cannot add an unavailable table, field, relationship, operator, or aggregate.
- Validation errors rerender the submitted JSON rather than replacing it with the last saved copy.
- A successful raw update is audited and redirects to the viewer.

## 11. Report viewer and runner specification

### 11.1 Viewer header

The content begins with:

- Left: **Report Manager** outline button with back arrow.
- Right, managers only: **Edit Definition** outline primary button.
- Below: the report description in muted text when nonempty.

The report name appears once in the shared AdminLTE page header.

### 11.2 Repair state

Before rendering filters, the saved definition is validated against the active catalog.

If invalid:

- Do not run the report.
- Show a warning that the report needs repair.
- List every validation error.
- Give a manager a link to the visual editor.
- Tell a viewer-only user to contact an administrator.

### 11.3 Filters card

The **Filters** card contains one POST form. Filter controls use `col-md-3`; the action group follows the controls in the same responsive row.

Each filter label contains:

- Saved prompt label, or a fallback `Table: Field` label.
- Lowercase operator label in muted small text.
- A visible required asterisk with an accessible required label when applicable.

The action group contains, in this order:

1. **Run Report** — primary button.
2. **Print View** — outline secondary button, opens a new tab.
3. **Export CSV** — outline secondary button.

All three submit the same current filter values. Print and export use button-level form actions. A user must not have to run the on-screen report before printing or exporting.

### 11.4 Runtime control types

#### Text, number, date, and enum controls

- Catalog input hints determine the native control type.
- `between` renders a paired input group separated by `to`.
- `is_null` and `not_null` render a disabled `No value needed` field.
- Static enum/bool options render a select when catalog options are available.
- A textbox using `in` accepts comma-separated values.

#### Searchable multiselect

The multiselect must match the Course Orbit interaction pattern:

- Closed state is a full-width form-select-style button.
- Summary is `Any`, the single selected value, or `{n} selected`.
- The dropdown contains a search input, scrollable checkbox results, status text, and **Clear**.
- Opening an empty dropdown loads options automatically.
- A server-rendered selected value does not count as a completed option load; the first open must still request the full available option list.
- Typing searches after a 200 ms debounce.
- Pressing Enter in search does not submit the report.
- Up to 100 options are returned per request.
- When more than 100 values match, status says to keep typing to narrow the results.
- Current selections remain selected when the search result changes.
- **Clear** removes every selection.
- A required multiselect with no selection blocks submit, marks the toggle invalid, announces the problem in the status area, and receives focus.

### 11.5 Running state

Only selecting **Run Report** triggers the on-button busy state:

- Disable the Run button.
- Set `aria-busy=true`.
- Replace the idle label with a small spinner and `Generating...`.
- Restore the idle state on `pageshow` so browser back/forward navigation cannot leave a disabled button.

Print and export must not switch the Run button into its busy state.

### 11.6 Initial state

Before a report is run, show a bordered card with a centered table icon and:

`Set any filters above and choose Run Report.`

Do not show an empty result table, generated SQL, or a zero-row result header in this state.

### 11.7 Error state

Runtime validation or query errors appear in a danger alert. The page must not expose database exception text, credentials, parameters, or a stack trace. Database details go only to the server error log.

### 11.8 Results state

Successful results render in a **Results** card:

- Left header: table icon and `Results`.
- Right header: formatted data row count.
- If the row limit was reached, append `(row limit reached)`.
- Card body has no padding.
- Results scroll inside a container limited to `min(70vh, 48rem)`.
- Column headers stay sticky at the top of the internal scroll container.
- Wide results scroll horizontally.
- Long text may wrap and must not overlap or clip adjacent cells.
- Numeric cells are right aligned.
- Zero matching rows show `No rows matched the filters.`

Managers see a collapsed **Generated SQL** disclosure below the card. SQL is escaped and displayed in a bounded, wrapping code block. Query parameters are not displayed.

## 12. Result table and total rows

### 12.1 Data formatting

| Value | Display behavior |
| --- | --- |
| Null or empty | Em dash. |
| Money-like decimal | Dollar sign, thousands separators, two decimals. |
| Other numeric | Thousands separators and up to the report formatter's supported decimal precision. |
| Date | `M j, Y`. |
| Date/time | `M j, Y g:i A`. |
| Other | Escaped string value. |

All cell output must be HTML escaped.

### 12.2 Aggregation

When at least one display column is aggregated:

- Every non-aggregated display expression participates in `GROUP BY`.
- Aggregate functions are selected only from the catalog allowlist.
- `count_distinct` renders `COUNT(DISTINCT expression)`.
- An aggregate without a label override receives `{Aggregate label} of {Field label}`.

### 12.3 Ordering

Ordering is constructed in this priority:

1. Control-break columns, in display order, using selected sort or ascending by default.
2. Other explicitly sorted columns, in display order.

This guarantees contiguous control-break groups.

### 12.4 Subtotals

- A subtotal is emitted after each completed control-break group.
- Nested breaks close from deepest to shallowest.
- The subtotal label contains the break label, break value or `(blank)`, and data-row count.
- Only display columns marked **Summarize** receive subtotal values.
- Subtotals use a visually distinct secondary background, bold text, and a stronger top border.

### 12.5 Grand total

- The grand total is emitted only when the option is enabled, at least one data row exists, and the report has a break or summarized column.
- The label contains the grand data-row count.
- Only summarized columns receive calculated values.
- The row uses a dark/high-contrast total treatment on screen and a bold gray treatment in print.

## 13. Print View specification

Print View is a bare document without the AdminLTE navigation shell.

It must contain:

1. Screen-only **Print** and **Close** buttons.
2. Report name.
3. Optional description.
4. Generation date/time and data-row count.
5. Applied filter summary when any filter is active.
6. The same formatted data, subtotal, and grand-total rows used on screen.
7. A small footer with site name and report name.

Print behavior:

- Auto-open the browser print dialog shortly after load.
- Repeat table headings on printed pages.
- Hide Print/Close controls in print media.
- Use compact borders and typography suitable for paper.
- Do not use sticky positioning in print.
- Do not include the generated SQL.

## 14. CSV export specification

CSV export must:

- Execute the report with the submitted runtime values.
- Use UTF-8 with a byte-order mark for spreadsheet compatibility.
- Use the display-column labels as the header row.
- Export display rows in exact on-screen order, including subtotal and grand-total rows.
- Place a subtotal/total label in the first otherwise empty display column.
- Use a filename derived from the report name plus a UTC timestamp.
- Send `Content-Type: text/csv; charset=UTF-8`.
- Send `X-Content-Type-Options: nosniff`.
- Protect every heading and cell from spreadsheet formula injection by prefixing formula-like nonnumeric values beginning with `=`, `+`, `-`, or `@` after leading whitespace.
- Audit report ID and data-row count after a successful export.

CSV contains raw report values rather than the screen's formatted currency/date strings unless a future export contract explicitly changes this behavior.

## 15. Report definition contract

### 15.1 Canonical shape

```json
{
  "id": 1,
  "name": "Assessment outcomes by course",
  "description": "Compare assessment counts by course and achievement level across one or more terms.",
  "base_table": "terms",
  "joins": [
    "courses.term_fk->terms.terms_pk",
    "enrollment.course_fk->courses.courses_pk",
    "assessments.enrollment_fk->enrollment.enrollment_pk"
  ],
  "columns": [
    {
      "table": "assessments",
      "field": "achievement_level",
      "label": "Achievement level",
      "aggregate": "",
      "sort": "asc",
      "break": false,
      "summarize": false
    }
  ],
  "filters": [
    {
      "table": "terms",
      "field": "term_code",
      "operator": "in",
      "control": "multi_select",
      "label": "Terms",
      "required": false,
      "default": [],
      "default_to": ""
    }
  ],
  "options": {
    "row_limit": 500,
    "grand_total": true
  },
  "created_at": "2026-07-22T00:00:00+00:00",
  "updated_at": "2026-07-22T00:00:00+00:00"
}
```

### 15.2 Property requirements

| Property | Type | Rules |
| --- | --- | --- |
| `id` | integer | Zero for unsaved; positive and immutable after save. |
| `name` | string | Trimmed, required. |
| `description` | string | Trimmed, optional. |
| `base_table` | string | Required invariant: exactly `terms`; Terms must exist in the active catalog. |
| `joins` | string array | Each ID must exist and connect in traversal order. |
| `columns` | object array | At least one; every field must belong to a connected table. |
| `filters` | object array | Optional; every field must belong to a connected table. |
| `options.row_limit` | integer | Clamped to 1–5,000. |
| `options.grand_total` | boolean | Normalized from submitted value. |
| `created_at` | ISO timestamp | Set by store on first save. |
| `updated_at` | ISO timestamp | Set by store on every save. |

Unknown or malformed structures must not become query identifiers.

## 16. Catalog contract

The catalog is runtime state paired with report definitions. It contains:

- `generated_at`
- `source`
- `stats`
- `tables`
- `relationships`
- `types`
- `operators`
- `aggregates`

### 16.1 Tables and fields

Each table supplies:

- Internal name.
- Friendly label.
- Business category.
- Field map.

Each field supplies at least:

- Friendly label.
- Normalized report type.
- SQL type metadata.
- Optional static options.

Sensitive, secret-bearing, internal security, and unsuitable heavy fields must be removed when the catalog is built. A field excluded from the catalog cannot be selected, filtered, raw-added, queried, printed, or exported.

### 16.2 Normalized types

| Type | Inputs | Operators | Aggregates |
| --- | --- | --- | --- |
| `int` | Number | equals, not equals, comparisons, between, in, blank checks | count, distinct count, sum, average, min, max |
| `decimal` | Number | equals, not equals, comparisons, between, in, blank checks | count, distinct count, sum, average, min, max |
| `date` | Date | between, equals, at least, at most, in, blank checks | count, distinct count, min, max |
| `datetime` | Date | between, equals, at least, at most, in, blank checks | count, distinct count, min, max |
| `string` | Text | contains, does not contain, equals, not equals, starts with, in, blank checks | count, distinct count, min, max |
| `text` | Text | contains, does not contain, blank checks | count |
| `enum` | Select | equals, not equals, in | count, distinct count |
| `bool` | Select | equals, in | count, sum |

The UI must derive available operators and aggregates from the catalog rather than hard-coding a broader set.

### 16.3 Relationships

Each relationship supplies:

- Stable relationship ID.
- From table and field.
- To table and field.
- Friendly label.
- Relationship source metadata.

Only catalog relationships may generate joins.

## 17. Query execution rules

### 17.1 Identifier safety

- Every table, field, relationship, aggregate, and operator must be revalidated immediately before query construction.
- SQL identifiers come only from the active catalog and configured database prefix.
- MySQL identifiers use backticks.
- SQL Server identifiers use brackets.
- Runtime filter values are bound parameters.
- User input must never be interpolated as an identifier, SQL fragment, function, direction other than validated `ASC`/`DESC`, or limit.

### 17.2 Database portability

- SQL Server uses `SELECT TOP (limit)`.
- MySQL-compatible drivers use trailing `LIMIT limit`.
- Searchable distinct-value queries follow the same dialect rule.
- Text casts used by option search must be dialect appropriate.
- The configured table prefix is applied at query time to every physical table.

### 17.3 Filter semantics

- Blank optional filters add no predicate.
- Blank required filters return a validation error.
- `is_null` means SQL null and, for string-like fields, empty string.
- `not_null` excludes SQL null and, for string-like fields, empty string.
- `not_equals` retains null rows; string-like fields also retain empty strings.
- `contains`, `not_contains`, and `starts_with` escape `%`, `_`, the escape character, and SQL Server bracket syntax so user text cannot accidentally become a wildcard expression.
- `in` accepts an array or comma-separated value, removes blanks, and is capped at 100 values.
- A non-array operator rejects an array value.
- An optional `between` may use both bounds or only one bound.
- A required `between` requires both bounds.
- A date-only upper bound applied to a datetime uses an exclusive next-day boundary so the whole selected day is included.

### 17.4 Row limits

- Default: 500.
- Hard maximum: 5,000.
- The result reports `limit_reached` when returned data-row count is at least the configured limit.
- A reached limit indicates potentially truncated results; it is not an execution error.

## 18. Persistence and starter library

- Definitions are stored outside the deployable source tree under the protected SLO Cloud data root.
- The current store uses one JSON file per numeric report ID.
- Writes use pretty-printed UTF-8 JSON and an atomic temporary-file rename.
- The definitions directory must be private and writable by the application.
- ID allocation uses an exclusive sequence lock.
- Definitions are listed alphabetically by report name.
- Starter reports are installed by seed-library version.
- A seed upgrade must not overwrite an administrator-customized report.
- A deliberately deleted starter report must stay deleted for the same seed-library version.

## 19. Security requirements

1. Require an authenticated administration session for every page and option endpoint.
2. Require server-side manager authorization for create, update, raw update, duplicate, delete, and catalog refresh.
3. Require CSRF validation for every state-changing action, report run, print request, and export request.
4. Escape every definition-derived value rendered into HTML.
5. Use prepared statements for all runtime values.
6. Allow only catalog-derived identifiers and operations.
7. Enforce connected relationship paths.
8. Clamp row limits on the server.
9. Cap multiselect values and option results.
10. Return generic query errors to users and log technical details server-side.
11. Protect CSV consumers from formula injection.
12. Set no-store/private cache headers for authenticated report pages and no-store on option JSON.
13. Set `X-Content-Type-Options: nosniff` on JSON and CSV responses.
14. Keep generated SQL manager-only and never display credentials or raw database exceptions.

## 20. Audit requirements

Audit these events:

| Event | Required context |
| --- | --- |
| Catalog initialize | Catalog stats, reports checked, affected reports. |
| Catalog refresh | Catalog stats, reports checked, affected reports. |
| Create | New report ID and actor. |
| Update | Report ID and actor. |
| Raw update | Report ID and actor. |
| Duplicate | New report ID, source report ID, actor. |
| Delete | Deleted report ID and actor. |
| Export | Report ID, actor, and data-row count. |

Ordinary on-screen runs and print views are not currently audit events. Adding them later is permissible only if volume, retention, and privacy impacts are reviewed.

## 21. Accessibility requirements

The feature must conform to the shared SLO Cloud WCAG 2.2 AA interface requirements and should preserve the project's enhanced AAA-oriented focus and contrast rules.

### 21.1 Structure and names

- One page H1 is supplied by the AdminLTE shell.
- Cards and table captions identify their purpose.
- Every form control has a visible label or an explicit accessible label.
- Every icon-only button has a unique accessible name.
- Decorative icons use `aria-hidden=true`.
- Required status is conveyed programmatically and visually.
- New-tab behavior is included in the accessible name of Print View.

### 21.2 Keyboard and focus

- All operations are available by keyboard.
- Focus indicators must remain visible and unobscured.
- Dropdown search must not submit the report when Enter is pressed.
- Required multiselect errors move focus to the invalid toggle.
- Validation alerts are focusable through the shared `data-a11y-focus` behavior.
- Dynamic editor rerenders should restore focus to the changed row/action or nearest surviving control.
- Controls must meet the shared minimum target-size requirement.

### 21.3 Status and errors

- Loading and option-result text uses a polite live status region.
- The Run button exposes busy state.
- Repair, validation, and execution messages do not rely on color alone.
- Disabled/no-value states include text.

### 21.4 Reflow and zoom

- At 320 CSS pixels, action bars and picker groups wrap instead of clipping.
- Result and editor tables may scroll horizontally within their own responsive containers.
- Long result values wrap.
- No two-dimensional page-level scrolling should be required to reach primary actions.

### 21.5 Motion and print

- The feature relies on the shared reduced-motion rules.
- Spinners do not contain essential status without text.
- Print output preserves table headings and readable contrast.

## 22. Responsive behavior

### Desktop and wide tablet

- Report details use the 4/8 Bootstrap grid.
- Picker and Add controls remain aligned at the right of card headers.
- Viewer filters use up to four controls per row (`col-md-3`).
- Viewer actions stay in one non-wrapping group when space permits.

### Narrow tablet and mobile

- Top action bars wrap with consistent gaps.
- Report detail fields stack.
- Picker controls expand to full width and Add buttons follow beneath when necessary.
- Editor tables remain compact and scroll horizontally within the card.
- Filter controls stack.
- Run, Print, and Export may wrap but remain in their defined order.
- Results scroll within the card and do not push the application shell beyond the viewport width.

## 23. Error handling and recovery

| Condition | Expected behavior |
| --- | --- |
| Catalog missing on manager open | Build from live DB, persist, sweep reports, audit initialize. |
| Catalog missing on option endpoint | Return a generic JSON error; do not build from endpoint. |
| Report ID missing | Return 404/not-found handling. |
| Viewer opens invalid definition | Show Needs repair state; do not query. |
| Invalid visual JSON payload | Show definition-read error; do not persist. |
| Invalid raw JSON | Show JSON parser error and preserve submitted text. |
| Disconnected join | Reject in server validation. |
| Removed field | Flag report for repair and identify unavailable field in editor validation. |
| Missing required filter | Show runtime validation error. |
| Query exception | Log technical exception; show generic user error. |
| Option search exception | Return generic JSON error and update dropdown status. |
| Row limit reached | Show nonfatal limit indicator. |
| No matching rows | Show explicit empty-results message. |

## 24. Performance requirements

- Opening Report Manager must not execute every report query.
- Opening a viewer must not execute the report until Run, Print, or Export is selected.
- Multiselect options load on demand rather than being embedded in the page.
- Option search is debounced by 200 ms and capped at 100 displayed results.
- Report results are capped at 5,000 data rows.
- The catalog is loaded from persisted runtime state and rebuilt only on first use, setup, or explicit refresh.
- Results use an internal scroll container to avoid creating an unbounded page for large reports.

## 25. Implementation map

| File | Responsibility |
| --- | --- |
| `administration/dynamic_reports.php` | Manager, designer, viewer, raw editor, print, CSV response orchestration, and screen-specific JavaScript. |
| `administration/report_options.php` | Authenticated distinct-value search endpoint. |
| `assets/css/custom-reports.css` | Feature-scoped Course Orbit-aligned visual and responsive rules. |
| `system/reporting/bootstrap.php` | Runtime paths, catalog lifecycle helpers, authorization helpers, CSRF helpers, escaping, CSV safety, and filter-control rendering. |
| `system/reporting/ReportCatalogBuilder.php` | Live schema introspection, curation, exclusions, labels, type mapping, and relationships. |
| `system/reporting/ReportDefinitionValidator.php` | Definition normalization and catalog validation. |
| `system/reporting/ReportEngine.php` | Dialect-aware SQL construction, prepared execution, option search, and total-row post-processing. |
| `system/reporting/ReportStore.php` | Atomic JSON persistence, IDs, ordering, and starter-library installation. |
| `system/reporting/seeds/*.json` | Shipped starter definitions. |
| `system/scripts/build_report_catalog.php` | Development/operational catalog tooling. |

## 26. Acceptance criteria

### 26.1 Report Manager

- [ ] Matches the Course Orbit catalog/action bar hierarchy.
- [ ] Contains the seven specified columns in order.
- [ ] Report name and View open the same viewer.
- [ ] Management actions are permission gated and server protected.
- [ ] Repair badges accurately reflect current catalog validation.
- [ ] Empty state is correct for manager and viewer roles.

### 26.2 Visual designer

- [ ] Every new and saved definition uses Terms as the base table.
- [ ] The fixed Terms control cannot be changed in the visual designer.
- [ ] Raw JSON with another base table is rejected.
- [ ] Relationship choices begin at Terms and expand only through connected tables.
- [ ] Legacy shipped graphs re-root safely without overwriting administrator-defined columns, filters, labels, or options.
- [ ] Field pickers append the selected field rather than a generic placeholder row.
- [ ] Column move, label, aggregate, sort, break, summarize, and remove controls work.
- [ ] Filter move, control, operator, range/default, required, and remove controls work.
- [ ] Aggregate/break exclusivity is enforced in UI and server validation.
- [ ] Row limit and grand-total options persist.
- [ ] Invalid definitions are not saved.
- [ ] Keyboard focus remains sensible after dynamic changes.

### 26.3 Viewer

- [ ] Initial state does not query and displays the Run Report instruction.
- [ ] Runtime values persist after an on-screen run.
- [ ] Run, Print, and Export use the same current filter values.
- [ ] Multiselect auto-load, debounce, selection persistence, clear, truncation status, and required validation work.
- [ ] Busy state applies only to Run Report.
- [ ] Results use sticky headings and internal horizontal/vertical scrolling.
- [ ] Zero rows, row-limit reached, subtotals, and grand total render correctly.
- [ ] Generated SQL is visible only to managers.

### 26.4 Output

- [ ] Print View contains report metadata, filters, rows, totals, and repeated headings.
- [ ] CSV contains a BOM, safe headings/cells, rows in display order, and a safe filename.
- [ ] CSV formula-injection tests cover leading whitespace and `=`, `+`, `-`, `@`.

### 26.5 Security and compatibility

- [ ] All mutations and report executions reject invalid CSRF tokens.
- [ ] Viewer users cannot invoke manager actions directly.
- [ ] Unknown identifiers and disconnected joins are rejected.
- [ ] MySQL and SQL Server query generation both pass representative tests.
- [ ] Sensitive catalog exclusions cannot be recovered through visual or raw editing.
- [ ] Query errors do not disclose technical details to the browser.

### 26.6 Accessibility and responsive QA

- [ ] Complete workflow is keyboard operable.
- [ ] Icon buttons, filters, table controls, and new-tab actions have correct accessible names.
- [ ] Focus and live status behavior is verified.
- [ ] 200% and 400% zoom do not hide primary actions.
- [ ] 320 px reflow has no clipped controls.
- [ ] Light/dark AdminLTE tokens preserve readable subtotal and total rows.
- [ ] Print preview is readable across multiple pages.

## 27. Future extensions

Future additions may include saved column-width preferences, charts, scheduled delivery, or database-backed definition storage. Any extension must preserve:

- The current definition-validation boundary.
- Catalog-only identifiers and operations.
- Runtime prepared parameters.
- Role and CSRF enforcement.
- The Course Orbit-aligned core workflow.
- Accessible keyboard and responsive behavior.
- Backward compatibility or an explicit definition migration plan.
