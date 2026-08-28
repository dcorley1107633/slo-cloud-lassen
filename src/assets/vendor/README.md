# Vendored browser dependencies

These production assets are shipped with SLOCloud so authenticated pages, setup,
SAML, LTI, and reports do not execute code or load fonts from third-party CDNs.
Only the compiled files used by the application and their upstream licenses are
included. Versioned paths make dependency changes explicit in code review.

| Dependency | Version | Upstream package | License | Owner / purpose |
|---|---:|---|---|---|
| Bootstrap | 5.3.8 | `bootstrap` | MIT | Bootstrap team; UI framework |
| Bootstrap Icons | 1.11.3 | `bootstrap-icons` | MIT | Bootstrap team; interface icons |
| Font Awesome Free | 6.4.0 | `@fortawesome/fontawesome-free` | Icons CC BY 4.0, fonts SIL OFL 1.1, code MIT | Fonticons, Inc.; interface icons |
| AdminLTE | 4.1.0 | `admin-lte` | MIT | ColorlibHQ; administration layout |
| jQuery | 3.7.0 | `jquery` | MIT | OpenJS Foundation; legacy table/UI integration |
| DataTables | 1.13.7 | `datatables.net`, `datatables.net-bs5` | MIT | SpryMedia; interactive tables |
| DataTables Buttons | 2.4.2 | `datatables.net-buttons`, `datatables.net-buttons-bs5` | MIT | SpryMedia; table export/print controls |
| JSZip | 3.10.1 | `jszip` | MIT or GPL-3.0 | Stuart Knightley; spreadsheet export dependency |
| pdfmake | 0.2.7 | `pdfmake` | MIT | bpampuch; PDF export dependency |
| marked | 12.0.2 | `marked` | MIT | MarkedJS; patch-note Markdown rendering |
| Roboto | 5.3.0 package build | `@fontsource/roboto` | Apache-2.0 | Google / Fontsource; Metis typography |
| Source Sans Pro | 5.3.0 package build | `@fontsource/source-sans-pro` | SIL OFL 1.1 | Adobe / Fontsource; AdminLTE typography |
| Material Icons | 1.13.14 package build | `material-icons` | Apache-2.0 | Google; Metis interface icons |

`SHA256SUMS` records every shipped vendor file. Packages were obtained from the
official npm registry tarballs. License files are retained beside each package.

## Update policy

- Review upstream stable releases and security advisories at least quarterly.
- Prioritize actively exploited or high-severity browser dependency issues and
  complete triage within five business days.
- Update one dependency family at a time, regenerate `SHA256SUMS`, run the PHP and
  reporting suites, and smoke-test login, setup, all three themes, DataTables
  filtering/export, report export, LTI embedding, and SAML error pages.
- Do not use floating CDN URLs or release-candidate packages in production.
- Preserve upstream copyright and license notices when replacing compiled assets.
