# VPAT (Voluntary Product Accessibility Template)

> **Superseded and withdrawn:** The conformance levels and summaries below are retained only as historical wording and must be treated as **Not Evaluated** because supporting test evidence was not retained. Do not use them for procurement or compliance decisions. Use the [SLOCloud 5.1.0 LTI assessment report](assessment-page-vpat-v5.1.0.md), which records verified implementation evidence and outstanding manual testing.
## WCAG 2.2 Edition
### Version 2.5 | February 2024

---

## Product Information

**Name of Product/Version:** SLOCloud Student Learning Outcome Assessment Entry Interface v5.0.1
**Product Description:** LTI-integrated Student Learning Outcome assessment interface for instructors to evaluate student achievement levels. Embedded in Learning Management Systems via LTI 1.1/1.3 launch.
**Date of Evaluation:** May 10, 2026
**Contact Information:** SLOCloud Project Team
**Evaluation Methods Used:** Manual code review, keyboard navigation testing, screen reader testing (NVDA 2024.1), automated accessibility testing (WAVE browser plugin), contrast verification (WebAIM Contrast Checker), text spacing testing (custom CSS overrides)
**Notes:** This VPAT documents conformance to WCAG 2.2 (October 2023) at Level A, Level AA, and strong Level AAA support. No Level A or Level AA criteria are unmet.

---

## Applicable Standards/Guidelines

This report covers the degree of conformance for the following accessibility standard/guidelines:

| Standard/Guideline | Included In Report | Conformance Level |
|-------------------|-------------------|------------------|
| **Web Content Accessibility Guidelines 2.2** (October 2023) | Yes | Level A: Full, Level AA: Full, Level AAA: Partial |
| Revised Section 508 standards published January 18, 2017 and corrected January 22, 2018 | Yes | Full conformance for applicable criteria |
| EN 301 549 Accessibility requirements for ICT products and services - V3.2.1 (2021-03) | Yes | Full conformance for web content (Chapter 9) |

---

## Terms

The terms used in the Conformance Level information are defined as follows:

- **Supports:** The functionality of the product has at least one method that meets the criterion without known defects or meets with equivalent facilitation.
- **Partially Supports:** Some functionality of the product does not meet the criterion.
- **Does Not Support:** The majority of product functionality does not meet the criterion.
- **Not Applicable:** The criterion is not relevant to the product.
- **Not Evaluated:** The product has not been evaluated against the criterion. This can be used only in WCAG 2.x Level AAA.

---

## WCAG 2.2 Report

### Table 1: Success Criteria, Level A

| Criteria | Conformance Level | Remarks and Explanations |
|----------|------------------|--------------------------|
| **1.1.1 Non-text Content** | Supports | All decorative icons marked with aria-hidden="true". All functional images have appropriate text alternatives via aria-label. |
| **1.2.1 Audio-only and Video-only (Prerecorded)** | Not Applicable | No audio or video content present. |
| **1.2.2 Captions (Prerecorded)** | Not Applicable | No audio or video content present. |
| **1.2.3 Audio Description or Media Alternative (Prerecorded)** | Not Applicable | No audio or video content present. |
| **1.3.1 Info and Relationships** | Supports | Semantic HTML used throughout (header, main, footer, nav). Tables use proper th/scope. Forms use labels and fieldsets. Headings follow H1->H2->H3 hierarchy without skipping levels. |
| **1.3.2 Meaningful Sequence** | Supports | Reading order is logical and follows visual order. Skip link allows bypassing repeated content (provided by theme header). |
| **1.3.3 Sensory Characteristics** | Supports | Instructions do not rely solely on shape, size, visual location, orientation, or sound. Text describes functionality clearly. |
| **1.4.1 Use of Color** | Supports | Color is not used as the only means of conveying information. Success/error states include icons and descriptive text. |
| **1.4.2 Audio Control** | Not Applicable | No auto-playing audio. |
| **2.1.1 Keyboard** | Supports | All functionality available via keyboard. Tab order is logical. No keyboard traps. Radio buttons navigable with arrow keys. |
| **2.1.2 No Keyboard Trap** | Supports | Users can navigate away from all components using standard keyboard commands (Tab, Shift+Tab, Esc). |
| **2.1.4 Character Key Shortcuts** | Not Applicable | No character key shortcuts implemented. |
| **2.2.1 Timing Adjustable** | Supports | No application-level session timeout. LTI session managed by parent LMS. No time limits on form completion. AJAX auto-save preserves work continuously. |
| **2.2.2 Pause, Stop, Hide** | Supports | Toast notifications auto-dismiss after 3 seconds but do not interfere with content. All animations respect prefers-reduced-motion user preference. |
| **2.3.1 Three Flashes or Below Threshold** | Supports | No flashing content present anywhere in the interface. |
| **2.4.1 Bypass Blocks** | Supports | Skip link implemented in theme header to jump to main content. Proper ARIA landmark regions (header, main, footer, nav, contentinfo). |
| **2.4.2 Page Titled** | Supports | Page has descriptive title: "Student Learning Outcome Assessment Entry - SLOCloud". |
| **2.4.3 Focus Order** | Supports | Focus order follows logical sequence matching visual layout: instructions -> section selector -> SLO buttons -> assessment form -> save controls. |
| **2.4.4 Link Purpose (In Context)** | Supports | All links have descriptive text or aria-label. Student Learning Outcome selection buttons include outcome code and full description for context. |
| **2.5.1 Pointer Gestures** | Supports | All functionality available with single pointer actions (click/tap). No complex gestures required. |
| **2.5.2 Pointer Cancellation** | Supports | Up-event activation used for all controls. No down-event triggers that could cause accidental activation. |
| **2.5.3 Label in Name** | Supports | Visible labels match accessible names for all controls. Button text matches aria-label content. |
| **2.5.4 Motion Actuation** | Not Applicable | No motion-actuated functionality. |
| **3.1.1 Language of Page** | Supports | HTML lang attribute set to "en" for English content. |
| **3.2.1 On Focus** | Supports | Focus does not cause unexpected context changes. No auto-submit on focus. |
| **3.2.2 On Input** | Supports | Changing settings does not cause unexpected context changes. Auto-save provides clear toast notifications confirming actions. |
| **3.3.1 Error Identification** | Supports | Required field errors described in text. AJAX errors shown in toast notifications with descriptive messages. |
| **3.3.2 Labels or Instructions** | Supports | All form fields have visible labels. Complete instructions provided in collapsible card. Required fields marked with asterisk and aria-required="true". |
| **4.1.1 Parsing** | Supports | HTML follows proper structure with semantic elements, unique IDs for form controls, correct nesting, and proper closing tags. No parsing errors identified. |
| **4.1.2 Name, Role, Value** | Supports | All UI components have proper roles, states, and properties via ARIA attributes. Radio groups use fieldset/legend. Buttons have descriptive labels. Live regions for status updates. |

### Table 2: Success Criteria, Level AA

| Criteria | Conformance Level | Remarks and Explanations |
|----------|------------------|--------------------------|
| **1.2.4 Captions (Live)** | Not Applicable | No live audio content. |
| **1.2.5 Audio Description (Prerecorded)** | Not Applicable | No video content. |
| **1.3.4 Orientation** | Supports | Content adapts fluidly to portrait and landscape orientations. Responsive design using Bootstrap 5 grid system. |
| **1.3.5 Identify Input Purpose** | Not Applicable | This criterion applies to fields collecting information about the user (name, email, address, etc.). Assessment form collects data about student achievement, not about the instructor. No user-identifying input fields present. |
| **1.4.3 Contrast (Minimum)** | Supports | All text meets 4.5:1 contrast ratio minimum. Large text meets 3:1. Enhanced to 8.59:1 for body text (exceeds AA requirement). |
| **1.4.4 Resize Text** | Supports | Text can be resized up to 200% without loss of content or functionality. Responsive design accommodates browser zoom without horizontal scrolling. |
| **1.4.5 Images of Text** | Supports | No images of text used. All text is actual HTML text. Icons use Font Awesome icon fonts. |
| **1.4.10 Reflow** | Supports | Content reflows at 320px viewport width without horizontal scrolling. Mobile-first responsive layout with Bootstrap grid. |
| **1.4.11 Non-text Contrast** | Supports | UI components and graphical objects meet 3:1 contrast ratio. Focus indicators have enhanced contrast. Button borders verified at 3.87:1+ ratio. |
| **1.4.12 Text Spacing** | Supports | CSS uses inherit and relative units to support user text spacing overrides. Explicitly tested with line-height: 1.5em, letter-spacing: 0.12em, word-spacing: 0.16em, paragraph spacing: 2em. No content loss or functionality issues. |
| **1.4.13 Content on Hover or Focus** | Supports | Toast notifications are dismissible and do not block interactive content. Hover/focus content follows WCAG requirements for dismissibility, persistence, and hoverability. |
| **2.4.5 Multiple Ways** | Not Applicable | Single-page LTI application accessed via LMS launch. No site navigation structure. |
| **2.4.6 Headings and Labels** | Supports | Headings are clear and descriptive. Labels clearly describe the purpose of every form field. |
| **2.4.7 Focus Visible** | Supports | Enhanced focus indicators (3px solid outline + 4px shadow) visible on all interactive elements. Exceeds minimum 2px requirement. |
| **2.4.11 Focus Not Obscured (Minimum)** | Supports | Focused elements are never fully obscured by other content. Toast notifications positioned at bottom-right to avoid main content area. |
| **2.5.7 Dragging Movements** | Not Applicable | No drag-and-drop functionality. All interactions via click/tap or keyboard. |
| **2.5.8 Target Size (Minimum)** | Supports | All interactive targets meet 24x24px minimum. Enhanced to 44x44px for improved accessibility. |
| **3.1.2 Language of Parts** | Not Applicable | Content is entirely in English. No language changes within content. |
| **3.2.3 Consistent Navigation** | Not Applicable | Single-page application with no multi-page navigation. |
| **3.2.4 Consistent Identification** | Supports | UI components with the same functionality are identified consistently: save indicators, achievement level buttons, and toast notifications all use consistent structure. |
| **3.2.6 Consistent Help** | Supports | Instructions card is consistently positioned at top of form and accessible throughout session. Same help information available on every page load. |
| **3.3.3 Error Suggestion** | Supports | Required field validation provides clear guidance. Error messages suggest specific corrections needed. |
| **3.3.4 Error Prevention (Legal, Financial, Data)** | Supports | Assessment data saved via AJAX with immediate confirmation feedback. Bulk actions require explicit confirmation dialog. |
| **3.3.7 Redundant Entry** | Not Applicable | No redundant data entry required. Previous selections preserved across page interactions. |
| **3.3.8 Accessible Authentication (Minimum)** | Supports | LTI authentication handled by parent Learning Management System. No cognitive function tests (CAPTCHA, puzzles, or object recognition) required. No memorization beyond parent LMS credentials. |
| **4.1.3 Status Messages** | Supports | ARIA live regions implemented for all dynamic updates: toast notifications use aria-live="polite" and aria-atomic="true", save indicators use role="status", error messages use role="alert". |

### Table 3: Success Criteria, Level AAA

| Criteria | Conformance Level | Remarks and Explanations |
|----------|------------------|--------------------------|
| **1.2.6 Sign Language (Prerecorded)** | Not Applicable | No audio content. |
| **1.2.7 Extended Audio Description (Prerecorded)** | Not Applicable | No video content. |
| **1.2.8 Media Alternative (Prerecorded)** | Not Applicable | No synchronized media. |
| **1.2.9 Audio-only (Live)** | Not Applicable | No live audio. |
| **1.3.6 Identify Purpose** | Supports | Form controls use appropriate autocomplete attributes where applicable. Buttons have clear purpose via labels and accompanying icons. Semantic HTML elements convey purpose. |
| **1.4.6 Contrast (Enhanced)** | Supports | Text contrast enhanced to 8.59:1 for body text, substantially exceeding 7:1 requirement. |
| **1.4.7 Low or No Background Audio** | Not Applicable | No audio content. |
| **1.4.8 Visual Presentation** | Supports | Text is left-aligned (not fully justified). Line height minimum 1.5 for body text. Paragraph spacing 1.5em minimum. Text resizable without assistive technology. |
| **1.4.9 Images of Text (No Exception)** | Supports | No images of text anywhere. All text rendered as actual HTML text. Icons use icon fonts, not images. |
| **2.1.3 Keyboard (No Exception)** | Supports | All functionality available via keyboard without exception. Modal dialogs, dropdowns, and collapsible sections all keyboard accessible. |
| **2.2.3 No Timing** | Supports | No application-level timing constraints. LTI session lifecycle managed entirely by parent Learning Management System per LTI specification. |
| **2.2.4 Interruptions** | Supports | Toast notifications are non-intrusive (bottom-right), auto-dismiss after 3 seconds, and can be manually dismissed immediately. |
| **2.2.5 Re-authenticating** | Supports | LTI session maintained by parent LMS. No application-level re-authentication required. Assessment data saved via AJAX prevents data loss. |
| **2.2.6 Timeouts** | Supports | No application-level timeout. LTI tool correctly delegates session management to parent LMS per LTI specification. |
| **2.3.2 Three Flashes** | Supports | No flashing content anywhere. |
| **2.3.3 Animation from Interactions** | Supports | All CSS transitions and animations respect prefers-reduced-motion user preference. When motion reduction enabled, animations complete immediately. |
| **2.4.8 Location** | Not Applicable | Single-page LTI embedded tool accessed via LMS launch. No hierarchical site structure. |
| **2.4.9 Link Purpose (Link Only)** | Supports | All links and buttons have descriptive aria-labels meaningful out of context. Student Learning Outcome selection buttons include code and full description. |
| **2.4.10 Section Headings** | Supports | Content organized with descriptive headings. H2 for major sections, H3 for subsections. Clear hierarchical structure. |
| **2.4.12 Focus Not Obscured (Enhanced)** | Supports | Focused elements are fully visible without any obscuring. Toast notifications positioned to never overlap focused elements. |
| **2.4.13 Focus Appearance** | Supports | Focus indicator is 3px solid outline with 4px shadow, providing highly visible focus ring. Contrast verified against all background colors. |
| **2.5.5 Target Size (Enhanced)** | Supports | All interactive targets are minimum 44x44px. Adequate spacing between adjacent targets prevents accidental activation. |
| **2.5.6 Concurrent Input Mechanisms** | Supports | Interface supports mouse, keyboard, and touch input simultaneously. Users can switch between input methods freely. |
| **3.1.3 Unusual Words** | Supports | "Student Learning Outcome" spelled out in full in instructional text and section headers. Domain-specific terms are standard higher education terminology familiar to the target audience. Contextual help provided in Instructions card. |
| **3.1.4 Abbreviations** | Supports | "Student Learning Outcome" spelled out consistently in instructional text. "CRN" used only when displaying actual data values where abbreviation is the standard institutional format. |
| **3.1.5 Reading Level** | Partially Supports | Instructions written at upper secondary education level, appropriate for college instructors. Some educational terminology (assessment method, achievement level, continuous improvement strategies) assumes familiarity with higher education practices. Appropriate for professional users. |
| **3.1.6 Pronunciation** | Not Applicable | No words where meaning is ambiguous without knowing pronunciation. |
| **3.2.5 Change on Request** | Supports | All context changes initiated explicitly by user action. Auto-save provides immediate notification toast confirming action. No automatic redirects or content updates. |
| **3.3.5 Help** | Supports | Instructions card provides comprehensive context-sensitive help for the assessment workflow. Available throughout session via collapsible card. |
| **3.3.6 Error Prevention (All)** | Supports | Confirmation dialogs for all bulk actions. Dialog shows student count and warns about irreversibility before executing. |
| **3.3.9 Accessible Authentication (Enhanced)** | Supports | LTI authentication requires no cognitive function tests beyond standard credentials in parent LMS. No CAPTCHA, puzzles, memory tests, or object recognition. |

---

## Revised Section 508 Report

### Chapter 3: Functional Performance Criteria (FPC)

| Criteria | Conformance Level | Remarks and Explanations |
|----------|------------------|--------------------------|
| 302.1 Without Vision | Supports | Fully compatible with screen readers (tested with NVDA 2024.1) via semantic HTML, comprehensive ARIA labels, proper heading hierarchy, landmark regions, and live regions for dynamic updates. |
| 302.2 With Limited Vision | Supports | Enhanced contrast ratios (8.59:1 for body text), resizable text up to 200%, large touch targets (44x44px), clear focus indicators, responsive reflow at 320px viewport. |
| 302.3 Without Perception of Color | Supports | Information conveyed through multiple means: text labels, icons, and color. Achievement levels use text labels, icons, and color simultaneously. |
| 302.4 Without Hearing | Not Applicable | No audio content. |
| 302.5 With Limited Hearing | Not Applicable | No audio content. |
| 302.6 Without Speech | Supports | No speech input required. All input possible via keyboard, mouse, or touch. |
| 302.7 With Limited Manipulation | Supports | Large touch targets (44x44px), single-pointer actions only, no precise timing required, keyboard accessible for all functionality. |
| 302.8 With Limited Reach and Strength | Supports | All controls reachable without extended physical manipulation. Touch targets sized appropriately. |
| 302.9 With Limited Language, Cognitive, and Learning Abilities | Supports | Clear step-by-step instructions, consistent UI patterns, confirmation dialogs for bulk actions, predictable behavior, auto-save with immediate feedback, visual grouping of related controls. |

### Chapter 5: Software

Not applicable - this is a web application accessed via browser.

### Chapter 6: Support Documentation and Services

Not applicable to product interface evaluation.

---

## EN 301 549 Report

### Chapter 9: Web Content

Refer to WCAG 2.2 tables above. All WCAG 2.2 Level A and AA criteria are supported, mapping to EN 301 549 Clauses 9.1-9.4.

- **9.1.1-9.1.4**: Perceivable - All content perceivable via text alternatives, adaptable layout, distinguishable visual presentation
- **9.2.1-9.2.5**: Operable - Keyboard accessible, sufficient time, no seizures, navigable, input modalities
- **9.3.1-9.3.3**: Understandable - Readable, predictable, input assistance
- **9.4.1.1-9.4.1.3**: Robust - Compatible with assistive technologies, proper parsing, status messages

---

## Changes in v5.0.1

**LTI Error Messaging (improved):**
- Cross-listed sections (XLS format) now show the correct "section not imported yet" message when the cross-listing group is recognised but has no database records, rather than the generic "unrecognisable CRN" error.
- Offering code is displayed in error messages to aid administrator troubleshooting.

**Compliance Status (unchanged from v5.0.0):**
- Level A: Full conformance (30/30 criteria)
- Level AA: Full conformance (26/26 criteria)
- Level AAA: Partial (30/31 criteria - 6 not applicable, 1 partially supports)

---

## Legal Disclaimer

This VPAT is provided for informational purposes only. It represents the accessibility features of the SLOCloud Student Learning Outcome Assessment Entry Interface v5.0.1 as of the evaluation date. The SLOCloud project team is committed to maintaining and improving accessibility as standards evolve.

For questions or accessibility concerns, please contact the SLOCloud Project Team.

---

**Report Version:** 1.0
**Last Updated:** May 10, 2026
**Next Review:** November 10, 2026 (6 months)
