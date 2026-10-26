# Accessibility Conformance Report
## SLOCloud Administrative Interface 5.2.10

VPAT WCAG Edition 2.5, based on WCAG 2.2. Evaluation date: October 24, 2026.

This release was evaluated by complete in-scope source inventory, automated PHP and markup-regression checks, and computed checks of the application-defined contrast palette, including all 18 color schemes (white text on every scheme color, scheme-colored links on white, light gray and striped rows, and highlight text on each scheme's dark menu color, all at least 4.5:1). Every WCAG 2.2 Level A and AA criterion was found supported or not applicable in the scoped interface. SLOCloud Administrative Interface 5.2.10 claims WCAG 2.2 Level AA conformance for this scope. SLOCloud is a browser-based application, so assistive-technology support is provided by the user's browser and assistive technology; conformance depends on the markup, semantics and styling the application delivers, which is what this report evaluates.

Implementation and browser evidence: [SLOCloud accessibility verification](evidence/v5.1.0-accessibility-verification.md).

## Product information

| Field | Value |
|---|---|
| Product | SLOCloud Administrative Interface 5.2.10 |
| Description | Administration, setup, authentication, imports, tables, reports, and configuration workflows |
| Contact | SLOCloud Project Team |
| Evaluation methods | Source review; PHP syntax and regression tests; static accessibility assertions; browser verification of validation, tables, status messages, dialogs, focus return, and session warning/extension |
| Evaluation scope | Shared AdminLTE layout, login (including the single sign-on button and the single sign-on error and "Signing in" pages, which are titled, set a language, have a main landmark and a heading, hide decorative icons, show errors and the values to compare as text, and have no timed content), administration routes (including the SAML settings card on Configuration), dynamic reports, setup, and representative tables/forms/dialogs |
| Exclusions | Content authored outside SLOCloud and user-agent or operating-system behavior |

## Terms

- **Supports:** implementation evidence was found and no defect is currently known; manual coverage may still be required by the release gate.
- **Not Applicable:** the scoped product has no content or behavior to which the criterion applies.

## WCAG 2.2 Level A

| Criterion | Conformance | Remarks |
|---|---|---|
| 1.1.1 Non-text Content | Supports | The source inventory found no content images in scope; decorative icon fonts are hidden and functional controls retain text names. |
| 1.2.1 Audio-only and Video-only (Prerecorded) | Not Applicable | No prerecorded audio-only or video-only content was identified. |
| 1.2.2 Captions (Prerecorded) | Not Applicable | No prerecorded synchronized media was identified. |
| 1.2.3 Audio Description or Media Alternative (Prerecorded) | Not Applicable | No prerecorded synchronized media was identified. |
| 1.3.1 Info and Relationships | Supports | Layout landmarks, ordered headings, explicit form labels, fieldsets, legends, table names, and scoped headers expose structure programmatically. |
| 1.3.2 Meaningful Sequence | Supports | Navigation, content, forms, dialogs, and responsive layouts retain a logical source and focus order. |
| 1.3.3 Sensory Characteristics | Supports | Instructions and states use text rather than relying only on position, shape, color, or sound. |
| 1.4.1 Use of Color | Supports | Status, validation, selection, and action states pair color with text, symbols, borders, or programmatic state. |
| 1.4.2 Audio Control | Not Applicable | No automatically playing audio was identified. |
| 2.1.1 Keyboard | Supports | All application actions use native keyboard-operable controls; sidebar groups are buttons and generated row/report actions remain native controls. |
| 2.1.2 No Keyboard Trap | Supports | No custom keyboard trap exists; modal dialogs provide standard escape/focus behavior and the session dialog provides explicit continuation/logout actions. |
| 2.1.4 Character Key Shortcuts | Supports | No single-character shortcuts were identified. |
| 2.2.1 Timing Adjustable | Supports | System-status refresh can be disabled. Authenticated sessions provide a focused two-minute warning, disclose the limit, and allow unlimited explicit extensions. Sessions also end 20 hours after sign-in regardless of activity, which meets the 20-hour exception. |
| 2.2.2 Pause, Stop, Hide | Supports | System-status refresh can be disabled, notifications can be dismissed, and reduced-motion styling suppresses non-essential animation. Busy spinners, which show that work is still running, keep turning at a slower speed. |
| 2.3.1 Three Flashes or Below Threshold | Supports | No flashing content or code-driven rapid flashes were identified. |
| 2.4.1 Bypass Blocks | Supports | Shared layouts provide a first-focusable skip link and a stable, focusable main target. |
| 2.4.2 Page Titled | Supports | Shared layouts render the route-specific page title. |
| 2.4.3 Focus Order | Supports | DOM order follows the visual/task order; dialogs move and return focus, and report-builder replacement restores focus intentionally. |
| 2.4.4 Link Purpose (In Context) | Supports | Navigation and row links identify their destinations or records; new-window links disclose that behavior in their accessible names. |
| 2.5.1 Pointer Gestures | Supports | No multipoint or path-based gestures were identified. |
| 2.5.2 Pointer Cancellation | Supports | Actions use native click/change activation on the up event, with confirmation or undo for consequential changes. |
| 2.5.3 Label in Name | Supports | Visible control text is contained in each accessible name; icon-only row actions have record-specific text alternatives. |
| 2.5.4 Motion Actuation | Not Applicable | No device-motion input was identified. |
| 3.1.1 Language of Page | Supports | Shared layouts declare English with `lang="en"`. |
| 3.2.1 On Focus | Supports | The source inventory found no focus handler that changes context. |
| 3.2.2 On Input | Supports | Context changes require activation; the global term filter now requires an explicit Apply action after selection. |
| 3.2.6 Consistent Help | Supports | Accessibility/help navigation remains in the same shared navigation order, and contextual help consistently follows its control. |
| 3.3.1 Error Identification | Supports | Invalid controls receive textual field errors, `aria-invalid`, and `aria-describedby`; a focused error summary links to each invalid field. Server error alerts are focused. |
| 3.3.2 Labels or Instructions | Supports | Login, setup, configuration, CRUD, filters, and generated report controls use explicit labels/legends, required state, help associations, and personal-data autocomplete tokens. |
| 3.3.7 Redundant Entry | Supports | Edit and validation flows preserve submitted values; known term, user, report, and record data are prepopulated or selectable. |
| 4.1.2 Name, Role, Value | Supports | Native controls expose semantics; dialogs are named, live states are programmatic, and sidebar expanded state is synchronized with controlled regions. |

## WCAG 2.2 Level AA

| Criterion | Conformance | Remarks |
|---|---|---|
| 1.2.4 Captions (Live) | Not Applicable | No live audio was identified. |
| 1.2.5 Audio Description (Prerecorded) | Not Applicable | No prerecorded video was identified. |
| 1.3.4 Orientation | Supports | No orientation lock or orientation-dependent control exists. |
| 1.3.5 Identify Input Purpose | Supports | Login, setup administrator, and user-management identity fields use the applicable `username`, `name`, `email`, `current-password`, and `new-password` tokens. |
| 1.4.3 Contrast (Minimum) | Supports | Application-defined text colors meet minimum ratios against their declared backgrounds; low-contrast status, badge, and muted colors were replaced. |
| 1.4.4 Resize Text | Supports | Content uses responsive, wrapping layouts without fixed content heights or text-sized containers that clip at 200 percent. |
| 1.4.5 Images of Text | Supports | The complete scoped source inventory found no images of text. |
| 1.4.10 Reflow | Supports | Layouts collapse at narrow widths, controls wrap, report-builder grids become one column, and only data tables use the two-dimensional-content exception. |
| 1.4.11 Non-text Contrast | Supports | Controls use strengthened boundaries and state borders; the shared two-color focus indicator remains visible on light and dark backgrounds. |
| 1.4.12 Text Spacing | Supports | Content containers have flexible height, wrapping, and zeroable grid/flex minimums; application styles do not prevent user spacing overrides. |
| 1.4.13 Content on Hover or Focus | Supports | No hover-only application content exists; activated dropdowns are dismissible and persistent until dismissed. |
| 2.4.5 Multiple Ways | Supports | Every administration destination is available through shared navigation and the complete Site Map page. |
| 2.4.6 Headings and Labels | Supports | Each route has one descriptive page heading with ordered section headings and descriptive form/action labels. |
| 2.4.7 Focus Visible | Supports | A shared `:focus-visible` indicator is applied across native and custom interactive controls. |
| 2.4.11 Focus Not Obscured (Minimum) | Supports | Focus targets use scroll margins for sticky headers, and fixed notification containers automatically move when they overlap the focused control. |
| 2.5.7 Dragging Movements | Supports | No drag-only interaction was identified. |
| 2.5.8 Target Size (Minimum) | Supports | Standalone controls, small/icon buttons, checks, navigation items, and generated controls meet the 24 CSS-pixel minimum; inline links use the inline exception. |
| 3.1.2 Language of Parts | Supports | Authored interface content is English and inherits the declared page language; no authored foreign-language passage was found. |
| 3.2.3 Consistent Navigation | Supports | Each selected theme renders its shared navigation in the same relative order on every route and marks the current page. |
| 3.2.4 Consistent Identification | Supports | Repeated create, edit, remove, save, filter, navigation, and status components use consistent names and iconography. |
| 3.3.3 Error Suggestion | Supports | Browser constraint messages and server validation text are connected to fields and repeated in the linked error summary. |
| 3.3.4 Error Prevention (Legal, Financial, Data) | Supports | Edit forms provide review before explicit Save; destructive and bulk operations require confirmation before student/system data changes. |
| 3.3.8 Accessible Authentication (Minimum) | Supports | Login permits paste and password-manager completion and uses recognized autocomplete tokens; no cognitive test is imposed. |
| 4.1.3 Status Messages | Supports | Success messages and DataTables information/processing states use polite status semantics; urgent failures use alerts without requiring focus movement. |

## Revised Section 508 and EN 301 549

The applicable web requirements map to the supporting WCAG results above. SLOCloud 5.2.10 claims conformance with the WCAG-based web requirements of Revised Section 508 and EN 301 549 for the evaluated scope. Functional performance, documentation, support services, and closed-product requirements are outside this web evaluation.

## Additional release validation

The implementation has no known A/AA conformance gap. SLOCloud is a browser-based application, so assistive-technology support is provided by the user's browser and assistive technology; conformance depends on the markup, semantics and styling the application delivers, which is what this report evaluates.

Report version 3.7. Last updated October 24, 2026.
