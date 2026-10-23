# SLOCloud accessibility implementation guide

Use native HTML controls and landmarks before adding ARIA. Every page must have a descriptive title, one page-level heading, a first-focusable skip link, and one stable `main` target. The shared themes provide the skip link, focus indicator, reduced-motion behavior, forced-colors support, and minimum control sizing.

For forms, give every control a unique `id` and an explicit `<label for="…">`. Connect help and error text with `aria-describedby`, set `aria-invalid="true"` when validation fails, and present a focusable error summary that links to invalid fields. Do not disable paste or password managers.

For dynamic interfaces, use `role="status"` for routine completion and result-count messages and `role="alert"` only for urgent errors. Do not move focus for routine saves. When opening a modal, move focus into it, keep focus inside while open, support Escape when safe, and return focus to the invoking control.

Tables need a caption or accessible name and correctly scoped headers. Dense data tables may scroll horizontally at narrow widths, but the rest of each workflow must reflow at 320 CSS pixels.

SLOCloud is browser-based, so assistive-technology support is delegated to the browser; the content must keep its semantics, names, focus handling and contrast correct. Check keyboard-only critical journeys, 200% text, 400% zoom/320 CSS pixels, text spacing, forced colors, reduced motion, orientation, target size and contrast when changing a page.
