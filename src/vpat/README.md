# VPAT Directory

This directory contains Voluntary Product Accessibility Templates (VPATs) for SLOCloud components.

## What is a VPAT?

A VPAT (Voluntary Product Accessibility Template) is a standardized document format used to communicate how well a product or service conforms to accessibility standards. Organizations, especially government agencies and educational institutions, often require VPATs during procurement to ensure products meet accessibility requirements.

## Standards Covered

Our VPATs document conformance to:

- **WCAG 2.2** (Web Content Accessibility Guidelines) - Levels A and AA. Level AAA has not been evaluated since 5.1.0, so no AAA conformance is claimed.
- **Section 508** (U.S. federal accessibility standards)
- **EN 301 549** (European accessibility standard for ICT products and services)

## Available VPATs

### [Assessment Page VPAT](assessment-page-vpat-v5.2.7.md)

**Component:** LTI Assessment Entry Interface  
**Status:** WCAG 2.2 Level AA supports conformance in the evaluated SLOCloud scope

**Last Updated:** October 24, 2026

**Summary:**
- All 55 Level A/AA criteria are recorded as `Supports` or `Not Applicable`
- Source, regression, computed-contrast, and targeted browser evidence is linked
- Browser-based: assistive-technology support is delegated to the user's browser, and the evaluated content meets the criteria

### [Admin Interface VPAT](admin-interface-vpat-v5.2.7.md)

**Component:** Administrative Dashboard and CRUD Pages  
**Status:** WCAG 2.2 Level AA supports conformance in the evaluated SLOCloud scope

**Last Updated:** October 24, 2026

**Summary:**
- All 55 Level A/AA criteria are recorded as `Supports` or `Not Applicable`
- Source, regression, computed-contrast, and targeted browser evidence is linked
- Browser-based: assistive-technology support is delegated to the user's browser, and the evaluated content meets the criteria

**Pages Covered:**
- Shared layout (header, sidebar, footer)
- Single sign-on error and sign-in continuation pages
- Dashboard with statistics
- 11 CRUD management pages

## Implementation Guide

See the [admin accessibility implementation guide](admin-accessibility-guide.md) for the required patterns.

## Using These VPATs

### For Procurement Officers
Review the VPAT to verify the product meets your organization's accessibility requirements before purchasing or adopting.

### For Accessibility Coordinators
Use the VPAT to:
- Assess compliance with institutional accessibility policies
- Identify any accommodations needed for specific users
- Plan accessibility testing and validation

### For Developers
Use the VPAT to:
- Understand implemented accessibility features
- Identify areas for improvement
- Maintain accessibility during updates
- Document accessibility decisions

Refer to [docs/concepts/ACCESSIBILITY.md](../../docs/concepts/ACCESSIBILITY.md) for WCAG compliance guidelines.

## VPAT Updates

VPATs should be updated:
- When significant features are added or changed
- When accessibility standards are updated
- At least annually for active products
- Before major releases

## Questions or Issues?

If you have questions about the accessibility of SLOCloud components or need clarification on any VPAT content, please contact the SLOCloud development team.

## Resources

- [WCAG 2.2 Guidelines](https://www.w3.org/WAI/WCAG22/quickref/)
- [Section 508 Standards](https://www.section508.gov/)
- [EN 301 549 Standard](https://www.etsi.org/deliver/etsi_en/301500_301599/301549/)
- [ITI VPAT Templates](https://www.itic.org/policy/accessibility/vpat)
