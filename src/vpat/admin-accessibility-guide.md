# SLOCloud accessibility implementation guide

Use native HTML controls and landmarks before adding ARIA. Every page must have a descriptive title, one page-level heading, a first-focusable skip link, and one stable `main` target. The shared themes provide the skip link, focus indicator, reduced-motion behavior, forced-colors support, and minimum control sizing.

For forms, give every control a unique `id` and an explicit `<label for="…">`. Connect help and error text with `aria-describedby`, set `aria-invalid="true"` when validation fails, and present a focusable error summary that links to invalid fields. Do not disable paste or password managers.

For dynamic interfaces, use `role="status"` for routine completion and result-count messages and `role="alert"` only for urgent errors. Do not move focus for routine saves. When opening a modal, move focus into it, keep focus inside while open, support Escape when safe, and return focus to the invoking control.

Tables need a caption or accessible name and correctly scoped headers. Dense data tables may scroll horizontally at narrow widths, but the rest of each workflow must reflow at 320 CSS pixels.

For release-quality validation, attach dated results for keyboard-only critical journeys, NVDA with Firefox and Chrome, VoiceOver with Safari, 200% text, 400% zoom/320 CSS pixels, WCAG text-spacing overrides, forced colors, reduced motion, both orientations, touch-target measurements, and contrast measurements. These checks catch deployment- and assistive-technology-specific regressions beyond the code-level conformance evidence.
