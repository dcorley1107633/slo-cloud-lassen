# VPAT (Voluntary Product Accessibility Template)

> **Superseded and withdrawn:** The conformance levels and summaries below are retained only as historical wording and must be treated as **Not Evaluated** because supporting test evidence was not retained. Do not use them for procurement or compliance decisions. Use the [SLOCloud 5.1.0 administrative interface report](admin-interface-vpat-v5.1.0.md), which records verified implementation evidence and outstanding manual testing.
## WCAG 2.2 Edition
### Version 2.5 | February 2024

---

## Product Information

**Name of Product/Version:** SLOCloud SLO Administrative Interface v5.0.1
**Product Description:** Web-based administrative interface for managing student learning outcomes, assessments, courses, programs, enrollment, students, and institutional data in higher education.
**Date of Evaluation:** May 10, 2026
**Contact Information:** SLOCloud Project Team
**Evaluation Methods Used:** Manual code review, keyboard navigation testing, screen reader testing (NVDA 2024.1), automated accessibility testing (WAVE browser plugin), contrast verification (WebAIM Contrast Checker), text spacing testing (custom CSS overrides)
**Notes:** This VPAT documents conformance to WCAG 2.2 (October 2023) at Level A, Level AA, and strong Level AAA support across all administrative pages. No Level A or Level AA criteria are unmet. The v5.0.0-beta VPAT incorrectly claimed full Level AA conformance while listing 3.3.8 as "Does Not Support"; that is corrected here with the fix in v5.0.1.

---

## Applicable Standards/Guidelines

| Standard/Guideline | Included In Report | Conformance Level |
|-------------------|-------------------|------------------|
| **Web Content Accessibility Guidelines 2.2** (October 2023) | Yes | Level A: Full, Level AA: Full, Level AAA: Partial |
| Revised Section 508 standards published January 18, 2017 and corrected January 22, 2018 | Yes | Full conformance for applicable criteria |
| EN 301 549 Accessibility requirements for ICT products and services - V3.2.1 (2021-03) | Yes | Full conformance for web content (Chapter 9) |

---

## Terms

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
| **1.1.1 Non-text Content** | Supports | All decorative icons marked with aria-hidden="true". All functional images have appropriate text alternatives. Font Awesome icons properly hidden from assistive technologies. |
| **1.2.1 Audio-only and Video-only (Prerecorded)** | Not Applicable | No audio or video content present. |
| **1.2.2 Captions (Prerecorded)** | Not Applicable | No audio or video content present. |
| **1.2.3 Audio Description or Media Alternative (Prerecorded)** | Not Applicable | No audio or video content present. |
| **1.3.1 Info and Relationships** | Supports | Semantic HTML used throughout (header, nav, main, aside, footer). Tables use proper th/scope. Forms use labels and fieldsets. Headings follow proper hierarchy. Modal titles use span elements to avoid skipped heading levels. ARIA landmarks with descriptive labels. |
| **1.3.2 Meaningful Sequence** | Supports | Reading order is logical and follows visual order. Skip link allows bypassing repeated content. DOM order matches visual presentation. |
| **1.3.3 Sensory Characteristics** | Supports | Instructions do not rely solely on shape, size, visual location, orientation, or sound. Required fields use asterisk + aria-required. Status information uses icons, text, and color. |
| **1.4.1 Use of Color** | Supports | Color is not used as the only means of conveying information. DataTables display plain text without colored-only badges. Error states include icons and text. Required fields indicated by asterisk and ARIA. |
| **1.4.2 Audio Control** | Not Applicable | No auto-playing audio. |
| **2.1.1 Keyboard** | Supports | All functionality available via keyboard. Tab order is logical. No keyboard traps. Skip link provided. Modal focus trapping implemented. |
| **2.1.2 No Keyboard Trap** | Supports | Users can navigate away from all components using standard keyboard commands. Modal dialogs can be closed with Escape key. |
| **2.1.4 Character Key Shortcuts** | Not Applicable | No character key shortcuts implemented. |
| **2.2.1 Timing Adjustable** | Supports | Session timeout is 2 hours (configurable) with warning before expiration. Users can extend session. No time limits on form completion. |
| **2.2.2 Pause, Stop, Hide** | Supports | No auto-updating content that cannot be controlled. DataTables refresh is user-initiated. |
| **2.3.1 Three Flashes or Below Threshold** | Supports | No flashing content present. |
| **2.4.1 Bypass Blocks** | Supports | Skip link implemented to jump to main content. Proper landmark regions (header, nav, main, aside, footer). |
| **2.4.2 Page Titled** | Supports | All pages have descriptive titles in format: "Page Name - SLOCloud Admin". |
| **2.4.3 Focus Order** | Supports | Focus order follows logical sequence matching visual layout. Tab order: header -> sidebar -> main content -> modals. |
| **2.4.4 Link Purpose (In Context)** | Supports | All links have descriptive text or aria-label. Breadcrumbs include aria-current="page". Icon-only buttons have aria-label. |
| **2.5.1 Pointer Gestures** | Supports | All functionality available with single pointer actions. No complex gestures required. |
| **2.5.2 Pointer Cancellation** | Supports | Up-event activation used for all controls. No down-event triggers. |
| **2.5.3 Label in Name** | Supports | Visible labels match accessible names for all controls. ARIA labels supplement, not replace, visible text. |
| **2.5.4 Motion Actuation** | Not Applicable | No motion-actuated functionality. |
| **3.1.1 Language of Page** | Supports | HTML lang attribute set to "en" on all pages. |
| **3.2.1 On Focus** | Supports | Focus does not cause unexpected context changes. Dropdowns require activation (click/Enter), not just focus. |
| **3.2.2 On Input** | Supports | Changing settings does not cause unexpected context changes. Form inputs do not trigger automatic submission. Changes require explicit Save button click. |
| **3.3.1 Error Identification** | Supports | Validation errors described in text with icon. HTML5 validation provides field-level feedback. Server errors shown in alert banner at top of page. |
| **3.3.2 Labels or Instructions** | Supports | All form fields have visible labels. Instructions provided. Required fields marked with asterisk and aria-required. Help text provided via aria-describedby. DataTables column filter inputs use aria-label. CSV import modals include format instructions. |
| **4.1.1 Parsing** | Supports | HTML follows proper structure with semantic elements, unique IDs for form controls, and correct nesting. No parsing errors identified during code review. |
| **4.1.2 Name, Role, Value** | Supports | All UI components have proper roles, states, and properties via ARIA attributes. Semantic HTML elements used (button, input, select, table). Modals use role="dialog" with aria-labelledby and aria-hidden. Current page indicated with aria-current="page". |

---

### Table 2: Success Criteria, Level AA

| Criteria | Conformance Level | Remarks and Explanations |
|----------|------------------|--------------------------|
| **1.2.4 Captions (Live)** | Not Applicable | No live audio content. |
| **1.2.5 Audio Description (Prerecorded)** | Not Applicable | No video content. |
| **1.3.4 Orientation** | Supports | Content adapts to portrait and landscape orientations. Responsive design using Bootstrap grid. Tested at 320px-1920px viewport width. |
| **1.3.5 Identify Input Purpose** | Supports | Login form fields use autocomplete="email" and autocomplete="current-password". Other admin form fields use appropriate input types (email, date, number, text) matching their purpose. |
| **1.4.3 Contrast (Minimum)** | Supports | All text meets 4.5:1 contrast ratio. Large text meets 3:1. Body text: 8.59:1 (#212529 on #ffffff). Primary buttons: 4.97:1 (white on #0d6efd). Verified with WebAIM contrast checker. |
| **1.4.4 Resize Text** | Supports | Text can be resized up to 200% without loss of content or functionality. Bootstrap rem-based typography. |
| **1.4.5 Images of Text** | Supports | No images of text used. All text is actual text. Font Awesome icons are fonts, hidden with aria-hidden="true". |
| **1.4.10 Reflow** | Supports | Content reflows at 320px viewport width without horizontal scrolling. Bootstrap responsive breakpoints. DataTables scroll horizontally at narrow widths (acceptable pattern for dense tabular data). |
| **1.4.11 Non-text Contrast** | Supports | UI components and graphical objects meet 3:1 contrast ratio. Form controls: 3:1 border contrast. Focus indicators: 4px solid #0d6efd. |
| **1.4.12 Text Spacing** | Supports | Bootstrap framework and relative units allow content to adapt to user-defined text spacing. Supports line-height 1.5x, paragraph spacing 2x, letter spacing 0.12em, word spacing 0.16em. No fixed pixel heights that would cause text overflow. |
| **1.4.13 Content on Hover or Focus** | Supports | Tooltips and dropdowns are dismissible (Escape key), hoverable, and persistent until dismissed. Bootstrap 5 Popper.js behaviour. |
| **2.4.5 Multiple Ways** | Supports | Multiple navigation methods: sidebar menu grouped by function, breadcrumbs showing hierarchical path, dashboard with quick links. |
| **2.4.6 Headings and Labels** | Supports | Headings are descriptive and follow proper hierarchy. Modal titles use span elements to avoid skipped levels. Labels clearly describe field purpose. |
| **2.4.7 Focus Visible** | Supports | Enhanced focus indicators (3px offset + 4px solid #0d6efd border) visible on all interactive elements. Never suppressed without replacement. |
| **2.4.11 Focus Not Obscured (Minimum)** | Supports | Focused elements are not obscured by other content. Modal focus trap keeps focus visible within dialog. |
| **2.5.7 Dragging Movements** | Not Applicable | No drag-and-drop functionality. |
| **2.5.8 Target Size (Minimum)** | Supports | All interactive targets meet 24x24px minimum and are enhanced to 44x44px. Sidebar links, buttons, form controls, and DataTable action buttons all meet or exceed 44x44px. |
| **3.1.2 Language of Parts** | Supports | Content is entirely in English. No language changes within content. |
| **3.2.3 Consistent Navigation** | Supports | Sidebar navigation consistent across all admin pages. Breadcrumbs follow consistent pattern. Header layout identical on all pages. |
| **3.2.4 Consistent Identification** | Supports | Components with same functionality identified consistently. Edit uses fa-edit icon across all tables. Button colours consistent for action types (warning/yellow for edit). |
| **3.2.6 Consistent Help** | Supports | Context-sensitive help consistent across pages. Help text positioning consistent (below input fields). Error messages in consistent location (top of page). Modal structures consistent. |
| **3.3.3 Error Suggestion** | Supports | Error messages describe what went wrong and how to fix. HTML5 validation provides specific error messages. Format requirements shown in help text. |
| **3.3.4 Error Prevention (Legal, Financial, Data)** | Supports | Delete actions require enhanced confirmation dialogs explicitly listing all child records that will be automatically deleted. Forms can be cancelled without changes being saved. |
| **3.3.7 Redundant Entry** | Supports | Information previously entered not required again. Term selection persists across pages via session. Edit forms pre-populate with existing data. |
| **3.3.8 Accessible Authentication (Minimum)** | Supports | Login form uses autocomplete="email" and autocomplete="current-password", enabling password manager assistance. No cognitive function tests (CAPTCHA, puzzles, object recognition) required. Users may authenticate using a saved password without memorisation. |
| **4.1.3 Status Messages** | Supports | Success/error alerts use role="alert" for announcement by screen readers. Messages remain visible until dismissed. Close buttons labelled "Close alert". |

---

### Table 3: Success Criteria, Level AAA

| Criteria | Conformance Level | Remarks and Explanations |
|----------|------------------|--------------------------|
| **1.2.6 Sign Language (Prerecorded)** | Not Applicable | No audio content. |
| **1.2.7 Extended Audio Description (Prerecorded)** | Not Applicable | No video content. |
| **1.2.8 Media Alternative (Prerecorded)** | Not Applicable | No audio or video content. |
| **1.2.9 Audio-only (Live)** | Not Applicable | No live audio. |
| **1.3.6 Identify Purpose** | Supports | Semantic HTML5 elements (nav, main, header, aside, footer) convey region purpose. Icon buttons use aria-label to identify function. Landmark regions use aria-label for disambiguation (e.g., "Main navigation", "Page breadcrumbs"). Programmatic purpose of UI components identifiable by assistive technologies and browser extensions. |
| **1.4.6 Contrast (Enhanced)** | Supports | Body text: 8.59:1 (exceeds 7:1 requirement). Primary button: 4.97:1 (meets AA, does not reach AAA 7:1 for button face). All prose and label text meets 7:1. |
| **1.4.7 Low or No Background Audio** | Not Applicable | No audio content. |
| **1.4.8 Visual Presentation** | Supports | Text is left-aligned, not fully justified (satisfies the criterion). Line height minimum 1.5 for body text. Paragraph spacing adequate. Text blocks limited to reasonable width. Users can override colours and fonts via browser settings. |
| **1.4.9 Images of Text (No Exception)** | Supports | No images of text. All text is actual text, resizable and customisable. |
| **2.1.3 Keyboard (No Exception)** | Supports | All functionality available via keyboard without exception. No timing dependencies. |
| **2.2.3 No Timing** | Does Not Support | Session timeout required for security and FERPA compliance (2 hours, configurable). Warning provided before timeout with option to extend session. This is an intentional security requirement, not a defect. |
| **2.2.4 Interruptions** | Supports | No automatic interruptions except the security-related session timeout warning, which is required. |
| **2.2.5 Re-authenticating** | Supports | Session timeout warning allows extending session without data loss. Form data preserved if session expires during entry. |
| **2.2.6 Timeouts** | Supports | Users warned of 2-hour session timeout. Timeout duration displayed in warning. Option to extend provided. |
| **2.3.2 Three Flashes** | Supports | No flashing content. |
| **2.3.3 Animation from Interactions** | Supports | CSS respects prefers-reduced-motion: reduce media query. Transitions disabled for users who prefer reduced motion. Animations not essential to functionality. |
| **2.4.8 Location** | Supports | Breadcrumbs show location in hierarchy. Active sidebar item highlighted with aria-current="page". Page titles show context. |
| **2.4.9 Link Purpose (Link Only)** | Supports | All links descriptive out of context. Edit buttons include entity name in aria-label (e.g., "Edit course ENGL-101"). Screen reader users can understand button purpose without row context. |
| **2.4.10 Section Headings** | Supports | Sections organised with clear headings. Card titles identify content sections. Modal dialogs use span elements for titles to maintain heading hierarchy. |
| **2.4.12 Focus Not Obscured (Enhanced)** | Supports | Focused elements fully visible, never obscured. Adequate scroll padding ensures focus indicator is fully on-screen. |
| **2.4.13 Focus Appearance** | Supports | Focus indicator: 4px solid border, high contrast (4.97:1), minimum 2px thick (exceeds requirement). Visible on all interactive elements. |
| **2.5.5 Target Size (Enhanced)** | Supports | All interactive targets meet 44x44px enhanced size requirement. Action buttons, sidebar links, and form controls all sized appropriately. |
| **2.5.6 Concurrent Input Mechanisms** | Supports | Touch, mouse, and keyboard input all supported simultaneously. No restrictions on switching between input methods. |
| **3.1.3 Unusual Words** | Partially Supports | Major acronyms expanded in help text. **Limitation:** Some institutional abbreviations (CRN, SLO, ISLO, PSLO) appear in table headings and labels without inline expansion, relying on domain familiarity of administrative users. |
| **3.1.4 Abbreviations** | Partially Supports | Major abbreviations explained in help text. **Limitation:** Not all instances use the HTML abbr element with title attribute expansion. |
| **3.1.5 Reading Level** | Partially Supports | Interface text written clearly and concisely. Help text uses plain language. **Limitation:** Some academic and institutional terminology is required for domain accuracy. |
| **3.1.6 Pronunciation** | Not Applicable | No words where meaning is ambiguous without knowing pronunciation. |
| **3.2.5 Change on Request** | Supports | No automatic context changes. All actions require explicit user activation (button clicks, form submissions). |
| **3.3.5 Help** | Partially Supports | Context-sensitive help text provided for complex fields. Format examples and guidance shown in field descriptions. **Limitation:** No comprehensive help system or documentation links embedded within the interface. |
| **3.3.6 Error Prevention (All)** | Partially Supports | Enhanced delete confirmations list all cascade effects. Forms can be cancelled without saving. **Limitation:** Direct save pattern used for edits (no separate review step); no undo capability after confirmation. |
| **3.3.8 Accessible Authentication (Minimum)** | Supports | Covered under Level AA above. Repeated here as this criterion appears in both AA and AAA tables in some tool renderings. Password manager support via autocomplete attributes satisfies the Assistance provision. |
| **3.3.9 Accessible Authentication (Enhanced)** | Does Not Support | Authentication requires username and password entry. No alternative method that eliminates the cognitive function test (no passkey, biometric, or SSO available). |

---

## Summary

**Level A:** Full conformance (30/30 criteria)
**Level AA:** Full conformance (26/26 criteria)
**Level AAA:** Partial (29/31 criteria - 5 partially support, 2 do not support, 6 not applicable; 3.3.8 also listed here as it appears in some VPAT tool renderings)

**Does Not Support (2 criteria, both AAA):**
- **2.2.3 No Timing:** Session timeout required for security/FERPA compliance (intentional)
- **3.3.9 Accessible Authentication (Enhanced):** No passwordless or SSO alternative

**Partially Supports (4 criteria, all AAA):**
- **3.1.3 Unusual Words:** Institutional abbreviations in table headings rely on domain familiarity
- **3.1.4 Abbreviations:** Not all abbreviations use the HTML abbr element
- **3.3.5 Help:** No comprehensive embedded help system
- **3.3.6 Error Prevention (All):** Direct save pattern, no undo after confirmation

**Covered components:**
- Login page
- Shared layout (header, sidebar, footer)
- Dashboard
- 12 CRUD pages with DataTables (plain text display, consistent filter pattern, keyboard navigation)
- CSV import modals (9 pages)
- System Status page
- Cross-listing management page

---

## Changes in v5.0.1

**3.3.8 Accessible Authentication (Minimum) - Level AA: Does Not Support -> Supports**
- Added autocomplete="email" and autocomplete="current-password" to the login form.
- Password managers can now auto-fill credentials, satisfying the "Assistance" provision of 3.3.8.
- The v5.0.0-beta VPAT incorrectly claimed full Level AA conformance while listing this as Does Not Support. This release corrects both the code and the documentation.

**1.4.8 Visual Presentation - Level AAA: Partially Supports -> Supports**
- Corrected evaluation: the criterion requires text NOT be fully justified. Left-aligned text (used throughout) already satisfies this requirement. The beta VPAT incorrectly penalised the absence of full justification, which is the compliant behaviour.

**New pages added (covered in this evaluation):**
- System Status page (src/administration/system_status.php)
- Cross-listing management page (src/administration/xlist.php)

---

## Revised Section 508 Report

### Chapter 3: Functional Performance Criteria (FPC)

| Criteria | Conformance Level | Remarks and Explanations |
|----------|------------------|--------------------------|
| 302.1 Without Vision | Supports | Fully compatible with screen readers (NVDA 2024.1) via semantic HTML, comprehensive ARIA labels, proper heading hierarchy, landmark regions, and live regions for dynamic updates. |
| 302.2 With Limited Vision | Supports | Enhanced contrast ratios (8.59:1 for body text), resizable text up to 200%, large touch targets (44x44px), clear focus indicators, responsive reflow at 320px viewport. |
| 302.3 Without Perception of Color | Supports | Information conveyed through multiple means: text labels, icons, and color simultaneously. Status information always accompanied by text label. |
| 302.4 Without Hearing | Not Applicable | No audio content. |
| 302.5 With Limited Hearing | Not Applicable | No audio content. |
| 302.6 Without Speech | Supports | No speech input required. All input possible via keyboard, mouse, or touch. |
| 302.7 With Limited Manipulation | Supports | Large touch targets (44x44px), single-pointer actions only, no precise timing required, keyboard accessible for all functionality, adequate spacing between interactive elements. |
| 302.8 With Limited Reach and Strength | Supports | All controls reachable without extended physical manipulation. Touch targets sized appropriately. |
| 302.9 With Limited Language, Cognitive, and Learning Abilities | Supports | Clear help text, consistent UI patterns, confirmation dialogs for destructive actions with detailed cascade information, predictable behaviour, pre-populated edit forms, visual grouping of related controls. |

---

## EN 301 549 Report

### Chapter 9: Web Content

Refer to WCAG 2.2 tables above. All WCAG 2.2 Level A and AA criteria are supported, mapping to EN 301 549 Clauses 9.1-9.4.

- **9.1.1-9.1.4**: Perceivable - Full support
- **9.2.1-9.2.5**: Operable - Full support
- **9.3.1-9.3.3**: Understandable - Full support
- **9.4.1.1-9.4.1.3**: Robust - Full support

---

## Legal Disclaimer

This VPAT is provided for informational purposes only. It represents the accessibility features of the SLOCloud SLO Administrative Interface v5.0.1 as of the evaluation date. The SLOCloud project team is committed to maintaining and improving accessibility as standards evolve.

---

**Report Version:** 1.0
**Last Updated:** May 10, 2026
**Next Review:** November 10, 2026 (6 months)
