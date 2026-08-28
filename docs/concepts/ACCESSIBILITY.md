# Accessibility Strategy

## Compliance Target

WCAG 2.2 Level AA conformance for all users including those with disabilities.

## Four Principles (POUR)

1. **Perceivable** - Information presented in perceivable ways
2. **Operable** - Interface operable by all users
3. **Understandable** - Information and operation understandable
4. **Robust** - Works with current and future assistive technologies

## Key Requirements

### 1. Semantic HTML

- Use proper HTML5 elements (`<header>`, `<nav>`, `<main>`, `<section>`)
- Maintain logical heading hierarchy (h1 → h2 → h3)
- Label all form inputs
- Provide alt text for images
- Use tables for tabular data only

### 2. Keyboard Navigation

- All functionality accessible via keyboard
- Logical tab order
- Visible focus indicators
- Skip navigation links
- Keyboard shortcuts for assessment grid (arrow keys)
- No keyboard traps

### 3. Screen Reader Support

- ARIA labels for interactive elements
- ARIA live regions for dynamic content
- Descriptive link text (not "click here")
- Hidden labels for context
- Proper role attributes

### 4. Color & Contrast

**Minimum Contrast Ratios:**
- Normal text: 4.5:1
- Large text (18pt+): 3:1
- UI components: 3:1

**Don't Rely on Color Alone:**
- Use icons + text + color
- Patterns in addition to colors
- Multiple visual cues

### 5. Forms & Error Handling

- Label all inputs explicitly
- Mark required fields clearly
- Provide helpful error messages
- Error summary at top of form
- Maintain form state on error
- Inline validation with aria-invalid

### 6. Responsive & Mobile

- Touch targets minimum 44x44 pixels
- Allow browser zoom
- Support 200% text resize
- Reflow content at narrow widths
- Swipe gestures optional (buttons available)

### 7. Assessment Grid Accessibility

**Special Considerations:**
- Keyboard navigation with arrow keys
- Screen reader announces row/column headers
- Descriptive labels for each input cell
- Save progress indication
- Undo capability

## Testing Approach

### Automated Testing

**Tools:**
- WAVE browser extension
- axe DevTools
- Lighthouse accessibility audit
- Pa11y command-line

**Run on:**
- All major pages
- Interactive components
- Forms
- Data tables

### Manual Testing

**Keyboard Navigation:**
- Tab through all interactive elements
- Test all keyboard shortcuts
- Verify focus indicators visible
- Confirm no keyboard traps

**Screen Reader Testing:**
- NVDA (Windows)
- JAWS (Windows)
- VoiceOver (Mac/iOS)
- TalkBack (Android)

**Visual Testing:**
- Zoom to 200%
- High contrast mode
- Reflow at 320px width
- Color contrast verification

### User Testing

- Include users with various disabilities
- Test with actual assistive technologies
- Document and prioritize fixes
- Retest after implementing changes

## Implementation Checklist

### Development

- [ ] Use semantic HTML5 elements
- [ ] Logical heading hierarchy
- [ ] All images have alt text
- [ ] All form inputs labeled
- [ ] Keyboard accessible
- [ ] Visible focus indicators
- [ ] Sufficient color contrast
- [ ] ARIA attributes where needed
- [ ] Error messages accessible
- [ ] Dynamic content announced

### Pre-Launch

- [ ] Automated testing passed
- [ ] Manual keyboard testing complete
- [ ] Screen reader testing complete
- [ ] Multiple browser testing
- [ ] Mobile accessibility tested
- [ ] User testing with disabilities
- [ ] Accessibility statement published
- [ ] VPAT documentation complete

### Ongoing

- [ ] Test new features for accessibility
- [ ] Monitor user feedback
- [ ] Annual accessibility audit
- [ ] Update WCAG as standards evolve
- [ ] Train content editors

## Accessibility Statement

**Public page at `/accessibility`:**

- Conformance claim (WCAG 2.2 AA)
- Known limitations
- Feedback mechanism
- Contact information
- Compatible technologies
- Date of last assessment

## Resources

- **WCAG 2.2 Quick Reference**: w3.org/WAI/WCAG22/quickref/
- **ARIA Authoring Practices**: w3.org/WAI/ARIA/apg/
- **WebAIM**: webaim.org

---

**Last Updated**: February 2026
