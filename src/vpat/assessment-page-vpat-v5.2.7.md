# Accessibility Conformance Report
## SLOCloud LTI Assessment Entry Interface 5.2.7

VPAT WCAG Edition 2.5, based on WCAG 2.2. Evaluation date: October 24, 2026.

This release was evaluated by complete in-scope source inventory, automated PHP and markup-regression checks, and computed checks of the application-defined contrast palette, including all 18 color schemes (white text on every scheme color, scheme-colored links on white, light gray and striped rows, and highlight text on each scheme's dark menu color, all at least 4.5:1). Every WCAG 2.2 Level A and AA criterion was found supported or not applicable in the scoped interface. SLOCloud LTI Assessment Entry Interface 5.2.7 claims WCAG 2.2 Level AA conformance for this scope. SLOCloud is a browser-based application, so assistive-technology support is provided by the user's browser and assistive technology; conformance depends on the markup, semantics and styling the application delivers, which is what this report evaluates.

Implementation and browser evidence: [SLOCloud accessibility verification](evidence/v5.1.0-accessibility-verification.md).

## Product information

| Field | Value |
|---|---|
| Product | SLOCloud LTI Assessment Entry Interface 5.2.7 |
| Description | Instructor workflow for selecting a section/outcome and recording assessment methods, achievement levels, and improvement strategies |
| Contact | SLOCloud Project Team |
| Evaluation methods | Source review; PHP syntax and regression tests; static accessibility assertions; browser verification of shared validation, tables, status messages, dialogs, focus return, and session warning/extension |
| Evaluation scope | LTI embedded assessment route, all empty/error/data states visible in source (including the 5.2.7 descriptive launch, section, outcome, roster and save error messages, which are plain text in `role="alert"` or message-page containers with decorative icons hidden), and shared embedded theme |
| Exclusions | Parent LMS authentication and iframe chrome are controlled by the LMS and must be evaluated with each supported integration |

## WCAG 2.2 Level A

| Criterion | Conformance | Remarks |
|---|---|---|
| 1.1.1 Non-text Content | Supports | Decorative icon fonts and the session-expiry symbol are hidden; functional controls retain text names. |
| 1.2.1 Audio-only and Video-only (Prerecorded) | Not Applicable | No audio or video is present. |
| 1.2.2 Captions (Prerecorded) | Not Applicable | No prerecorded synchronized media is present. |
| 1.2.3 Audio Description or Media Alternative (Prerecorded) | Not Applicable | No prerecorded synchronized media is present. |
| 1.3.1 Info and Relationships | Supports | Landmarks, ordered headings, fieldsets, legends, explicit labels, a table caption, and scoped headers expose relationships programmatically. |
| 1.3.2 Meaningful Sequence | Supports | Source and focus order follow the section, outcome, method, student-assessment, and strategy task sequence at every responsive width. |
| 1.3.3 Sensory Characteristics | Supports | Instructions identify controls and choices by text, not sensory position alone. |
| 1.4.1 Use of Color | Supports | Achievement, save, error, selection, and disabled states use text, icons, borders, and programmatic state in addition to color. |
| 1.4.2 Audio Control | Not Applicable | No audio is present. |
| 2.1.1 Keyboard | Supports | Every action uses native links, buttons, radios, checkboxes, and selects with keyboard activation. |
| 2.1.2 No Keyboard Trap | Supports | No custom trap exists; collapsible sections use native activation and the modal timeout dialog provides explicit continuation/logout actions. |
| 2.1.4 Character Key Shortcuts | Supports | No single-character shortcut is implemented. |
| 2.2.1 Timing Adjustable | Supports | Save notifications are dismissible and pause on hover/focus. Inactivity warning begins two minutes before expiry and permits unlimited explicit extensions. |
| 2.2.2 Pause, Stop, Hide | Supports | Notifications can be dismissed, pause on hover/focus, and persistent errors do not disappear automatically. |
| 2.3.1 Three Flashes or Below Threshold | Supports | No flashing content is present. |
| 2.4.1 Bypass Blocks | Supports | The embedded theme provides a first-focusable skip link and focusable main target. |
| 2.4.2 Page Titled | Supports | The document title identifies the SLO assessment entry route. |
| 2.4.3 Focus Order | Supports | DOM and tab order follow the visible task sequence; status updates do not move focus and dialogs return it intentionally. |
| 2.4.4 Link Purpose (In Context) | Supports | Section and outcome links include course/outcome text and current-state semantics. |
| 2.5.1 Pointer Gestures | Supports | No multipoint or path gesture is used. |
| 2.5.2 Pointer Cancellation | Supports | Native click/change activation occurs on the up event. |
| 2.5.3 Label in Name | Supports | Visible section, outcome, and action text is included in accessible names. |
| 2.5.4 Motion Actuation | Not Applicable | No motion-actuated input is used. |
| 3.1.1 Language of Page | Supports | The embedded theme declares `lang="en"`. |
| 3.2.1 On Focus | Supports | Focus alone does not submit data or navigate. |
| 3.2.2 On Input | Supports | Assessment choices save the selected value without navigation or an unexpected context change and announce the result textually. |
| 3.2.6 Consistent Help | Supports | The instructions region is consistently available at the start of the workflow. |
| 3.3.1 Error Identification | Supports | Save errors are persistent textual alerts; shared constraint validation provides associated field messages and a focused linked summary. |
| 3.3.2 Labels or Instructions | Supports | Inputs have visible labels or fieldset legends and the task includes step-by-step instructions. |
| 3.3.7 Redundant Entry | Supports | Known section, outcome, assessment method, and saved values are populated from launch/context data. |
| 4.1.2 Name, Role, Value | Supports | Native controls, named regions, collapse states, current-page state, dialogs, and live save states expose their names, roles, and values. |

## WCAG 2.2 Level AA

| Criterion | Conformance | Remarks |
|---|---|---|
| 1.2.4 Captions (Live) | Not Applicable | No live audio is present. |
| 1.2.5 Audio Description (Prerecorded) | Not Applicable | No prerecorded video is present. |
| 1.3.4 Orientation | Supports | No orientation lock or orientation-dependent interaction exists. |
| 1.3.5 Identify Input Purpose | Not Applicable | The form does not collect user-purpose personal data covered by the criterion. |
| 1.4.3 Contrast (Minimum) | Supports | Application-defined text and badge colors meet minimum ratios against their declared backgrounds, including muted, warning, and informational states. |
| 1.4.4 Resize Text | Supports | Content uses responsive, wrapping layouts and flexible heights without clipped text-sized containers at 200 percent. |
| 1.4.5 Images of Text | Supports | No images of text are used. |
| 1.4.10 Reflow | Supports | Controls and notifications fit and wrap at 320 CSS pixels; the student result grid is genuinely two-dimensional tabular content covered by the exception. |
| 1.4.11 Non-text Contrast | Supports | Outcome, control, and state borders meet 3:1 where required; the two-color focus indicator is visible on light and dark backgrounds. |
| 1.4.12 Text Spacing | Supports | Flexible-height controls and containers wrap content, and application styles do not prevent user line, word, or letter-spacing overrides. |
| 1.4.13 Content on Hover or Focus | Supports | Notifications are persistent while hovered/focused and are explicitly dismissible. |
| 2.4.5 Multiple Ways | Not Applicable | This is a single task launched from the parent LMS, not a set of pages. |
| 2.4.6 Headings and Labels | Supports | A page heading, step headings, field labels, and legends identify purpose. |
| 2.4.7 Focus Visible | Supports | Shared high-visibility focus styling covers links and form controls. |
| 2.4.11 Focus Not Obscured (Minimum) | Supports | Focus targets use scroll margins and the fixed notification region automatically moves when it would overlap the newly focused control. |
| 2.5.7 Dragging Movements | Supports | No dragging interaction is used. |
| 2.5.8 Target Size (Minimum) | Supports | Standalone controls, outcome labels, small/icon buttons, checks, and notification actions meet the 24 CSS-pixel minimum; inline links use the inline exception. |
| 3.1.2 Language of Parts | Supports | Authored interface content is English and inherits the declared page language; no authored foreign-language passage was found. |
| 3.2.3 Consistent Navigation | Not Applicable | The scoped interface is a single task page. |
| 3.2.4 Consistent Identification | Supports | Repeated achievement and save controls are consistently named and presented. |
| 3.3.3 Error Suggestion | Supports | Field errors include browser correction guidance; save failures identify the failed operation and retain the prior control value. |
| 3.3.4 Error Prevention (Legal, Financial, Data) | Supports | Individual assessment, method, and strategy changes provide an Undo action; bulk changes require confirmation before saving. |
| 3.3.8 Accessible Authentication (Minimum) | Not Applicable | Authentication occurs in the parent LMS; SLOCloud receives a signed LTI launch and presents no cognitive test. |
| 4.1.3 Status Messages | Supports | Per-student save state and dismissible notifications use status/alert semantics without moving focus. |

## Revised Section 508 and EN 301 549

Applicable web requirements map to the supporting WCAG results above. SLOCloud 5.2.7 claims conformance with the WCAG-based web requirements of Revised Section 508 and EN 301 549 for the evaluated SLOCloud scope. The parent LMS remains outside SLOCloud's control and evaluation scope.

## Additional release validation

The implementation has no known A/AA conformance gap. SLOCloud is a browser-based application, so assistive-technology support is provided by the user's browser and assistive technology; conformance depends on the markup, semantics and styling the application delivers, which is what this report evaluates.

Report version 3.7. Last updated October 24, 2026.
