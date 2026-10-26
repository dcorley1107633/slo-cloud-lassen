# SLOCloud Patch Notes

## v5.2.12 (2026-10-25)

### Security

- The web server no longer serves the Markdown documentation in the web
  folder (`README.md`, `PATCH_NOTES.md`, `EULA.md`, the VPATs and the
  others); they can be read only by signing in. `web.config` (IIS) and
  `.htaccess` (Apache) now deny `.md` files; IIS answers 404. The VPAT pages
  under Administration read the files from disk and are unaffected. The
  installer's validator now fails, instead of warning, if `.md` files are
  served.

### Accessibility

- The administrative interface and LTI assessment entry VPATs are reissued
  for 5.2.12 with no interface changes. Criterion results are carried forward.

## v5.2.11 (2026-10-25)

### Single sign-on

- The **Sign-in Could Not Be Validated** page no longer shows values from
  the settings or the response (such as the configured identifiers, which
  include the Entra tenant ID, the attributes received or the NameID). It
  still says what failed and what to check, and adds a reference such as
  `SSO-1A2B3C4D` and the time. The values go to the application log
  (`app_YYYY-MM-DD.log`) on the same line as that reference, with the
  technical reason.

### Accessibility

- The administrative interface and LTI assessment entry VPATs are reissued
  for 5.2.11 with no interface changes. Criterion results are carried forward.

## v5.2.10 (2026-10-25)

### Single sign-on

- The NameID is used as the email only when Email Attribute Name is blank.
  When a name is set and the response does not carry that attribute,
  sign-in is refused with "Response has no usable email address", which
  lists the attributes received. Before, SLO Cloud quietly used the NameID,
  so a wrong attribute name went unnoticed while the NameID happened to be
  the user's email (Microsoft Entra ID sends the user principal name there),
  and failed or created a second account for anyone whose NameID was not.
  The old `email` default still finds Entra's email claim. If sign-in stops
  after this update, set Email Attribute Name to one of the attributes the
  error lists, or leave it blank to sign in by NameID.

### Accessibility

- The administrative interface and LTI assessment entry VPATs are reissued
  for 5.2.10 with no interface changes. Criterion results are carried forward.

## v5.2.9 (2026-10-25)

### Single sign-on

- Email Attribute Name now defaults to Microsoft Entra ID's email claim
  (`http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress`)
  instead of Okta's `email`, and Full Name Attribute Name defaults to empty,
  because Entra sends no display name claim unless one is added. Without a
  name, an account created at sign-in is named by its email.
- Both settings also accept the short claim names Entra shows under
  Attributes & Claims, such as `emailaddress` or `displayname`. A short name
  matches the one attribute whose full name ends in it; a namespaced name
  must still match exactly.
- A site that kept the old `email` default now also signs in with Entra's
  email claim when no `email` attribute is sent, so it works without
  editing the setting. Before, such a site fell back to the NameID, which
  Entra fills with the user principal name.

### Accessibility

- The administrative interface and LTI assessment entry VPATs are reissued
  for 5.2.9 with no interface changes. Criterion results are carried forward.

## v5.2.8 (2026-10-25)

### Single sign-on

- The Single Sign-On (SAML) Settings card uses Microsoft Entra ID's field
  names, with the general SAML name in brackets for other identity
  providers, and the same names appear in sign-in error messages, the
  activity log and the Entra setup guide. The boxes for Entra's values are
  now Microsoft Entra Identifier (IdP Entity ID), Login URL (IdP SSO URL),
  Logout URL (IdP SLO URL) and Certificate (Base64). No setting was renamed
  in config.yaml.
- The values to copy into the identity provider are listed at the top of
  the card under "Copy these into your identity provider", named exactly as
  the Entra fields they go in (Identifier (Entity ID), Reply URL (Assertion
  Consumer Service URL), Sign on URL, Logout Url). They used to sit below the
  settings with names that matched neither Entra nor the boxes.
- The three override boxes for those values are under a collapsed
  "Advanced" section, and each shows the value in use as a grey placeholder.
  Blank still means "built from Site Address". The section opens by itself
  when an override is set.

### Administration

- Two new settings under App, both on by default, turn off the Primary
  Assessment Type card and the Continuous Improvement Strategies card on the
  instructor assessment page: Show Primary Assessment Type
  (`app.assessment_type_enabled`) and Show Continuous Improvement Strategies
  (`app.improvement_strategies_enabled`). A card that is off is hidden and
  its save is refused. Values already saved are kept, and saving the other
  card does not clear them. Existing sites need no change.

### Accessibility

- The administrative interface and LTI assessment entry VPATs are reissued
  for 5.2.8. The new Advanced section on the SSO card is a native
  `details`/`summary` disclosure, and the placeholders in its boxes add to
  their visible labels and help text rather than replace them. A turned-off
  assessment card is not rendered. Criterion results are carried forward.

## v5.2.7 (2026-10-24)

### Single sign-on

- Single sign-on (SAML 2.0) now runs on the maintained `onelogin/php-saml`
  library (with `xmlseclibs`), bundled unmodified in `system/lib` with no
  Composer. It replaces the hand-written signature check and strengthens how
  sign-in responses are verified.
- A sign-in response is accepted only if it answers a sign-in that SLO Cloud
  started, once, within 10 minutes. Replayed responses and responses the
  identity provider sends on its own (including Entra's **Test** button) are
  refused. The Activity Log shows these as "did not answer a sign-in started
  here".
- Every check that was optional is now required: audience, issuer, destination,
  recipient, validity period and the signature on the assertion. SHA-1
  signatures are refused. A second IdP signing certificate can be set so the
  identity provider can replace its certificate without an outage.
- Single sign-on errors now say what failed and what to change, with the values
  to compare, instead of "could not be validated": an unsigned or wrongly signed
  response (wrong or replaced certificate, SHA-1), a different Entity ID,
  issuer or Reply URL than configured (both values are shown), a clock that is
  off, and a response with no usable email (the attributes received are
  listed). A sign-in that began at the identity provider, such as Entra's Test
  button, says "You have arrived here unsolicited" and tells you to test from
  the SLO Cloud login page. The Activity Log records a specific reason for each.
- Sign-in no longer asks the identity provider for a password-only
  authentication method, which made Microsoft Entra ID fail for people who sign
  in with MFA or a passkey.
- New `saml/metadata.php` gives the identity provider administrator the SP
  metadata instead of typing the Entity ID and ACS URL by hand.
- Single logout: signing out of SLO Cloud also signs the user out at the
  identity provider, and a logout at the identity provider ends the SLO Cloud
  session (`saml/slo.php`). It is on when **IdP SLO URL** and the new **SP
  Single Logout URL** are both set. Logout messages from the identity provider
  must be signed.
- New **Auto-Provision Accounts** setting (off by default) with **Auto-Provision
  Email Domains**. When on, a person whose email is at a listed domain and who
  has no account gets one at first single sign-on: active, no admin role, and a
  random password nobody knows. Existing and deactivated accounts are never
  changed. It will not turn on without at least one domain.
- Optional signed sign-in requests and encrypted assertions, with the SP
  certificate in Configuration and the private key in a file outside the web
  root (new settings **SP Certificate**, **SP Private Key File**, **Sign
  Requests**, **Require Encrypted Assertions**, **Require Signed Response**,
  **Force Re-Authentication**).
- New **Site Address** setting (for example `https://slo.example.edu`). SLO Cloud
  builds its Entity ID, Reply (ACS) URL, Sign-on URL and logout URL from it and
  Base URL Path, and the Configuration page lists them ready to copy into the
  identity provider, so they no longer have to be typed and kept in step by
  hand. They are never built from the request's Host header. **SP Entity ID**,
  **SP Assertion Consumer Service URL** and **SP Single Logout URL** are now
  optional overrides; an installation that already has them set keeps working
  unchanged.
- Configuration has a **Single Sign-On (SAML) Settings** card with help text for
  Microsoft Entra ID, and refuses to save with **Enable SAML SSO** on while the
  settings cannot work, naming the problem.
- New guide, `docs/ENTRA_SSO_SETUP.md`: step-by-step Microsoft Entra ID setup, starting from Site Address and the values the page shows,
  testing, troubleshooting and certificate rollover.

### Fixes

- The SSO error pages now have a main landmark and hide their decorative
  icons from screen readers, and the "Signing in" page has a heading.
- The SSO error pages loaded no styles or scripts because their asset links were
  never filled in.
- After a successful single sign-on the browser could land on the login page
  again, because the redirect from the identity provider's cross-site POST did
  not carry the new session cookie. The sign-in now continues through a page
  that refreshes itself.
- Single sign-on no longer uses `RelayState`; after sign-in the browser
  stays on SLO Cloud.

### Upgrading

- No database changes; the schema version stays 5.2.1, so there is no
  migration to run. Replace the application files, including the new
  `system/lib` folder and `web.config`. The new `saml` settings are added to
  `config.yaml` automatically, all off or blank.
- If single sign-on is already in use, check it after the update (see
  `docs/ENTRA_SSO_SETUP.md`): the identity provider must now sign the
  assertion with SHA-256, a sign-in must start at SLO Cloud (the identity
  provider's own Test button and portal tiles that start at the provider are
  refused), and IdP Entity ID, SP Entity ID and the Reply URL must match
  exactly. Password login is unchanged.
- Give the web server account Modify access to `<data>\var` (it creates
  `var\saml`), if it did not already have it.

## v5.2.6 (2026-10-23)

### LTI

- LTI error pages now say what went wrong and show the values received,
  instead of a generic "Course Not Available" or "Access Denied". They name
  the failing case (no section matches the CRN and term, no Student Learning
  Outcomes defined for the course, no students enrolled in the section, a
  missing, malformed, expired or unknown launch token, or an invalid
  `section_id` or `slo_id` in the URL) and echo the CRN, term, course and
  parameters involved. Failed saves return the reason instead of "Access
  denied."
- A launch no longer needs a term. When `lti.offering_format` has no
  `{TERM}` token, the section is matched on CRN alone. If that CRN exists in
  more than one term, the launch stops and the error names the terms and says
  to add `{TERM}` to the format. A section is not auto-created from such a
  launch, because creating one needs a term, so it must already be imported.
- The course label no longer carries the CRN twice. The test launch on the
  LTI page sends `COURSE-CRN` instead of `COURSE-CRN (section)`, and a
  section created automatically by a launch gets section ID `01` instead of
  whatever was in the label's parentheses. Existing sections are unchanged.

### Administration

- System Status shows the PHP version next to the application version, and
  the status API returns it as `php_version`.
- Each file on Term Imports (Normalized) has an info button (ⓘ) next to its
  name that shows what importing that file does, before you import it: which
  records it adds, which it updates and how it matches them, which it skips,
  and whether it creates missing parent records. It is the same summary the
  progress window shows during an import, and its last line follows the
  "Auto-create missing parent records" box. The hint opens on hover or
  keyboard focus, stays open when clicked, and closes with Escape or a click
  elsewhere.
- The import summary now names every parent record that auto-create can add,
  including their own parents. For example, a CSLO file whose course does
  not exist yet also creates that course's department and term, and the
  summary used to name only the course. This affects the PSLO,
  Program-Course Mapping, CSLO, CSLO-PSLO Mapping and Section files. The
  import itself is unchanged.
- The Important notice on Term Imports (Normalized) still asks for the files
  in order, 1 through 12, and now explains how to fix an import done out of
  order. Imports update the records they match rather than adding
  duplicates, so importing again is safe: importing a parent's file after
  the fact replaces the "Auto-created: …" placeholder names and term dates,
  and skipped rows are saved by importing the missing file and then the
  skipped file again.

### Upgrading

- No database changes; the schema version stays 5.2.1, so there is no
  migration to run. Replace the application files.

## v5.2.5 (2026-10-20)

### Administration

- Theme choices (public, administration and LTI) are back on System >
  Configuration, in a Theme Settings section, and the Theme Settings page is
  gone. The `theme.*` values in `config.yaml` are unchanged.
- There is a new System > Color Scheme page (administrators only) for the
  Color Scheme setting, which is no longer on the Configuration page.
- The Color Scheme setting offers 18 schemes in three groups:
  Standard (Blue, the default and unchanged; Teal, Green, Maroon, Purple,
  Slate gray), Fallout (Vault-Tec, Pip-Boy green, Brotherhood of Steel, Nuka-Cola,
  Raiders, New California Republic, Enclave; each has a fourth, light
  highlight color used on the dark menu, such as Vault-Tec yellow) and Other
  (Ocean, Midnight, Sunset, Mocha, Pride rainbow, which also adds a rainbow
  bar along the top of every page and under the menu brand). A scheme applies to every user and every theme,
  including the login page and LTI pages, and recolors buttons, links,
  badges, alerts, pagination, progress bars, checked boxes, page header
  colors and the administration sidebar menu (Blue keeps the original dark
  gray menu). Schemes are chosen from color swatches, and choosing one
  previews it on the page immediately, including the menu, and a panel of
  static samples shows every element it recolors, including the highlight; it is applied to
  everyone only when Save Color Scheme is used.
- Every scheme keeps text readable: white text on scheme colors, and scheme
  colored links on white, light gray and striped table rows, are at least
  4.5:1. Keyboard focus outlines keep their existing dark blue in every
  scheme.
- The setting is saved as `theme.color_scheme` in `config.yaml`. Existing
  installs stay Blue until it is changed.
- Each file on Administration > Term Imports (Normalized) now has a
  "Download sample" link to an example CSV showing the expected columns. The
  samples moved from `docs/sample_data` to `assets/sample_data` so they are
  served with the application.

### Accessibility

- The administrative interface and LTI assessment entry VPATs are reissued
  for 5.2.5. They record the Color Scheme setting (contrast of every scheme
  checked at 4.5:1 or better; focus outlines unchanged; the sidebar menu uses
  each scheme's dark color behind white text; swatch choice with live preview;
  the setting has its own Color Scheme page)
  and the sample
  download links on Term Imports (Normalized). Criterion results are carried
  forward from the 5.1.0 evaluation.

### Upgrading

- No database changes; the schema version stays 5.2.1, so there is no
  migration to run.
- Replace the application files. Existing installs stay on the Blue color
  scheme until System > Color Scheme is changed. The old Theme Settings page
  (`administration/theme_settings.php`) is removed; theme choices are on
  Configuration.
- The sample import files now live in `assets/sample_data`; the copy in
  `docs/sample_data` is gone.

## v5.2.4 (2026-10-20)

### Administration

- The Activity Log now records sign-ins (password, emergency administrator,
  single sign-on and from the LMS), failed sign-ins, sign-outs, and sessions
  that end on their own (2 hours idle, 20 hours after sign-in, account
  deactivated). Before, these went only to a text log file, so the Activity
  Log showed refusals with no sign-in before them.
- A new Details column says why in plain words, for example "Wrong password",
  "Signed out after 2 hours idle" or "Administrator role required". It is
  also in the CSV export.
- Ordinary refusals (a page your role does not allow, an expired form, Key
  rotation with Maintenance Mode off) are no longer marked "threat"; they show
  as "denied" or "failed". "Threat" is kept for requests to pages the
  application does not have, repeated failed sign-ins and an unauthenticated
  key rotation attempt. Refusals recorded before this release for an ordinary
  reason also show as "denied".
- Importing a file on Term Imports (Normalized) now opens a progress window
  that blocks the rest of the page until the import finishes, so nothing can
  be clicked or changed while the page is about to reload with the results.
  Before, only the button's spinner showed, and it often did not move. The
  window shows:
  - the time elapsed, inside the spinner;
  - the two steps, uploading the file and importing its records, with the
    upload's progress;
  - what the import does with that file, in plain words: which records it
    adds, which it updates and how it matches them, which it skips, and
    whether it creates missing parent records (from the "Auto-create missing
    parent records" box);
  - how long the import usually takes for a file that size, with the time
    left, once this server has imported that file before.
  If the import fails or the connection drops, the window says so and can be
  closed. An expired form shows "This form has expired" there instead of a
  bare error.
- Import results now say how long the import took, for example "FILE 6
  (Course) import: 120 imported/updated, 0 skipped (took 8.0 seconds)". An
  import that fails after running for a while says how long it ran.
- The time each successful import takes, with the file's size and number of
  rows, is kept on the server in `<data>/var/import_timings.json` (the last
  20 per file) and is used only for the estimate. No student data is stored
  in it, and it is safe to delete; estimates start again from the next
  import.
- System > Configuration has a new Encryption Keys Folder section. It shows
  the `key.yaml` in use and what set its location (`security.key_path` in
  `config.yaml`, the `SLOCloud_KEY_PATH` environment variable, or the old
  default inside the web root), and warns when it is inside the web root.
- An administrator can move the keys to another folder from that section.
  Check Folder tests a folder without changing anything. The folder must
  already exist, sit outside the web root, hold no `key.yaml` or `backups`
  folder yet, and let the web server account write, read and delete files
  there; on Linux and macOS it must not be writable by every account. Create
  it first and restrict it: on Windows to Administrators, SYSTEM and the
  application pool, on Linux and macOS to the web server account (for
  example `chmod 700`).
- Move Keys Folder needs Maintenance Mode, like key rotation, and cannot run
  at the same time as a rotation. It copies `key.yaml` and the key rotation
  backups beside it, checks each copy against the original, saves the new
  path as `security.key_path` in `config.yaml`, checks SLO Cloud now loads
  the key from there, and only then deletes the old copies. If any step
  before the delete fails, everything is left as it was. Other files in the
  old folder, and the folder itself, are kept. Moves are recorded on the
  Activity Log.
- Older installs whose `key.yaml` is still in `src/config/` can use it to
  move the key out of the web root; the move adds `security.key_path` to
  `config.yaml`. The move is not available while `SLOCloud_KEY_PATH` is set,
  because that variable overrides `config.yaml`; move the file and update
  the variable on the server instead.
- Theme settings (the themes for public, administration and LTI pages) have
  their own page, System > Theme Settings, for administrators. They are no
  longer on the Configuration page, and saving the Configuration page no
  longer changes them. The page is also in the Metis administration menus,
  the user menu and the Site Map.

### Accessibility

- The accessibility report pages no longer show ratings for 5.0.0 and 5.0.0
  Beta. Like 5.0.1, their supporting evidence was not retained, so they are
  marked "Historical claim; evidence not retained". Their report links led to
  "Document not found" because those reports were never kept. The earlier
  Level AAA "Partial" figures are withdrawn with them; the current reports
  cover Level A and AA.
- Loading spinners keep turning, more slowly, when the operating system's
  "Reduce motion" setting is on. The setting stopped every spinner, so a page
  that was still working looked frozen.
- The administrative interface and LTI assessment entry VPATs are reissued
  for 5.2.4. They record the Activity Log's Details column, the import
  progress window, the Encryption Keys Folder section, the Theme Settings
  page, and spinners that keep turning slowly under "Reduce motion" (2.2.2
  Pause, Stop, Hide). The LTI assessment entry pages did not change.
  Criterion results are carried forward from the 5.1.0 evaluation.

### Upgrading

- No database changes; the schema version stays 5.2.1, so there is no
  migration to run.
- On Windows Server, rerun `Install-SLOCloud5.ps1` from the 5.2.4
  `automation` folder. Otherwise replace the application files.
- Theme settings already in `config.yaml` are kept. Change them on System >
  Theme Settings from now on.
- The Activity Log shows sign-ins, sign-outs and session endings from the
  upgrade onwards; earlier ones are only in the text log files.
- If System > System Status reports Key Location as critical, the key is
  still inside the web root. Create a folder outside it, restrict its
  access, turn on Maintenance Mode and use Move Keys Folder on System >
  Configuration. If you move the keys out of the installer's `secrets`
  folder on Windows Server, the installer and `Test-SLOCloud5.ps1` still
  manage and check only `secrets`.

## v5.2.3 (2026-10-09)

### Fixes

- Saving System > Configuration no longer takes the whole site down. The Log
  Directory box showed its help text as the current value, and saving any
  setting stored that text as the log folder; every page then failed with an
  empty HTTP 500 and nothing in the logs. The box is now empty by default,
  saving rejects a Log Directory that is not a full path, and the application
  ignores a non-absolute log folder (including the stored help text) and logs
  to the data folder instead.
- Removed the 15-minute "recent login" requirement. Fifteen minutes after
  signing in, saving on System > Users or System > Configuration, loading the
  Users list, running a Canvas deployment and rotating keys all failed with a
  generic "Access Denied" (or "DataTables warning: Ajax error") while every
  other action kept working. An administrator can now do everything the role
  allows for as long as the session is valid.
- Sessions now end cleanly instead: after 2 hours idle (as before, with a
  warning that lets you continue) or 20 hours after sign-in. Twenty hours is
  the shortest fixed limit WCAG 2.2.1 (Timing Adjustable) allows without an
  option to extend it. You are signed out, the login page says why, and after
  signing in you return to the page you were on. Tables that load in the
  background go to the login page instead of showing an "Ajax error".
- Refusals say why. "Only administrators can do this" replaces the generic
  "Access Denied" page on administration pages and the API, and an expired
  form now says "This form has expired. Reload the page and try again."
- Setup no longer crashes on its last step under PHP 8, so the completion
  screen with the LTI key and secret is shown again. Installs that hit the
  crash were complete; the key and secret are under System > Configuration >
  LTI.
- Typing in a form field that is not valid yet (an email address without @, a
  password under 12 characters) no longer moves focus to the error summary on
  every keystroke. This affected every form.
- Choosing "Server Mail" in setup no longer leaves a site where no page loads.
  Setup saved the choice as `server`, which the configuration rules reject,
  so every page failed with an HTTP 500 right after setup. It is now saved as
  `mail` (PHP mail()), and existing configurations with `server` load as
  `mail`.
- Setup checks the database host, SMTP port and other values it is about to
  write against the configuration rules before it creates anything, so a
  value the rules reject is reported on the setup form instead of breaking
  every page after setup finishes.
- A SQL Server named instance (for example `SQL01\SQLEXPRESS`) is accepted as
  the database host. It was rejected when saving System > Configuration and,
  when entered in setup, stopped every page from loading.
- System > Configuration tests changed database host, port, username or
  password before saving and refuses settings that cannot connect, instead of
  saving them and taking every page down.
- A Log Directory that does not exist and cannot be created (a missing
  drive, no permission for the app pool) no longer stops every page from
  loading. Saving one on System > Configuration is refused, and an existing
  one is ignored in favour of the data folder's `logs` folder.
- A Base URL Path without a trailing slash (for example `/slo`) no longer
  breaks every link and stylesheet; the slash is added.
- Clearing a number box on System > Configuration keeps that setting's
  default instead of saving 0 and refusing the whole save.
- When config.yaml cannot be loaded, pages show a "Configuration Error" page
  instead of an empty HTTP 500, and the reason is written to `php_errors.log`
  in the data folder's `logs` folder.
- Setup's "could not open database" message now gives SQL Server steps
  (create the database, add the login with db_owner, or db_ddladmin plus read
  and write) instead of hosting control panel advice, and is no longer
  escaped twice.
- Pages that load in the background no longer show "Invalid JSON response" or
  an empty error when you are signed out or an unexpected error occurs; they
  go to the login page or show a short error message. Key rotation now shows
  the server's reason when it is refused.
- Rotating the configuration encryption key on System > Configuration left
  every encrypted setting, including the database password, unreadable, so no
  page could load. Rotation now re-encrypts every encrypted setting and
  refuses to run if it cannot account for all of them.

#### SQL Server

- Export All (CSV, Copy, Print) on the administration tables returned nothing
  or failed; it now exports every row.
- Cross-listed Sections could not be sorted by CRN.
- Add Enrollment never created a new student and never detected a duplicate
  enrollment.
- Clear Term Data always rolled back because of an error in the program
  outcomes step; it now clears them with the rest of the term.
- Column filters on Courses, Disciplines, Programs, Program Outcomes and
  Institutional Outcomes failed; Institutional Outcomes' Created By and
  Updated By columns pointed at columns that do not exist.
- Custom reports can total and average yes/no fields.

#### PHP 8

- Institutional Outcomes, Program Outcomes and SLO tables failed to load when
  a code looked like a number (for example "1.1"), and a code like "1.10" was
  shown as "1.1".
- Sections without a CRN broke the Enrollment and Assessments tables and the
  Assessments add and edit lists; an enrollment whose student record is
  missing is now skipped instead of breaking the page.
- Add User failed after saving and never assigned the Administrator role.
- Add Student failed.
- A database error during single sign-on produced an HTTP 500 instead of an
  error message.
- On the LTI assessment page, saved assessment methods and improvement
  strategies never loaded, so saving again overwrote them.

### Security Fixes

- The status API (`api/api.php`) requires a Monitoring API Key and never
  accepts an empty `X-Api-Key` header.

### Installation

- The installer grants the app pool read & execute on the install root folder
  itself (not inherited). Without it PHP cannot resolve paths under the root
  on Windows and setup reports the keys folder as missing.
- Preflight asks for confirmation before changing a machine-level
  `SLOCLOUD_DATA_PATH` or `SLOCLOUD_ENV` that is already set to a different
  value.

### Accessibility

- The administrative interface and LTI assessment entry VPATs are reissued
  for 5.2.3. They record the form-validation focus fix, the clearer refusal
  and session-ended messages, and the 20-hour maximum session length under
  2.2.1 Timing Adjustable (WCAG's 20-hour exception; the two-hour inactivity
  timeout can still be extended from its warning). Criterion results are
  carried forward from the 5.1.0 evaluation.

### Upgrading

- No database changes; the schema version stays 5.2.1, so there is no
  migration to run.
- On Windows Server, rerun `Install-SLOCloud5.ps1` from the 5.2.3
  `automation` folder; it fetches the release and deploys it. Run the 5.2.3
  installer, not an older copy: older installers remove the install-root
  permission added in this release. If `web.config` was edited by hand, add
  `-OverwriteModifiedWebFiles` (the edited file is backed up first).
- Otherwise replace the application files. `web.config` changed in this
  release.
- If System > Configuration was saved under 5.2.2, Log Directory may hold the
  text "(empty - uses the protected application data directory)". 5.2.3
  ignores it and logs to the data folder; clear the box on System >
  Configuration to tidy it up.
- Signed-in users stay signed in through the upgrade. Their 20-hour session
  limit starts counting from the first page they open afterwards.
- Users added on System > Users before 5.2.3 were saved without the
  Administrator role even when its box was ticked. Check the role of every
  user added since installation on System > Users.

## v5.2.2 (2026-09-30)

### Administration

- Added Administration > Deploy to Canvas for global administrators. It uses a
  Canvas account admin token to install the SLO Cloud LTI 1.1 tool in a course
  by ID, a term, all active courses, or all courses, as an instructor-only
  course navigation tab with an optional unpublished module link. Courses that
  already have the tool (directly or from their account) are left alone, so
  runs are safe to repeat; Blueprint child courses are skipped. A dry run
  reports what would change, and results download as CSV with SIS ID checks
  against imported sections. The token is never saved: during a run only an
  encrypted copy is held, and runs are processed in short batches that stay
  within IIS time limits. Defaults are kept in the new `canvas:` configuration
  section. No database changes.
- The LTI page links to Deploy to Canvas, and the page is listed in the
  administration navigation and site map. Its defaults are edited under
  System > Configuration > Canvas Deployment Settings.

### Installation

- Added a Windows Server installer in `automation/`. `Install-SLOCloud5.ps1`
  prepares the web node:
  - IIS and PHP 8.3 FastCGI, with `pdo_sqlsrv` and `yaml`;
  - the `repo`, `web`, `secrets`, `data` and `backup` folders, with
    least-privilege permissions;
  - the environment variables the application reads;
  - a localhost-only HTTPS binding with a self-signed certificate;
  - the application files, deployed from a Git commit.

  It then hands over to the setup wizard. `-Plan` previews every change
  without making it, and every change is recorded in a journal first, so
  `Undo-SLOCloud5.ps1` can roll back a run or uninstall. Databases, keys,
  configuration and data are always kept. `Test-SLOCloud5.ps1` validates the
  result independently. It never asks for SQL credentials and never reads
  secret files. See [automation/README.md](../automation/README.md).
- `docs/SYSTEM_REQUIREMENTS.md` and `src/setup/README.md` now describe the
  current setup flow (keys-folder verification code instead of a setup
  token) and the installer's folder layout.

### Accessibility

- The administrative interface and LTI assessment entry VPATs are reissued
  for 5.2.2. The new Deploy to Canvas page has not yet been independently
  evaluated; criterion results are carried forward from the 5.1.0
  evaluation.

### Upgrading

- No database changes; the schema version stays 5.2.1, so there is no
  migration to run.
- Replace the application files. The new `canvas:` settings take their
  defaults from `config.yaml.template` automatically.

## v5.2.1 (2026-09-26)

### Reports

- Added live orphaned-enrollment summary and detail reports for reviewing
  retained section-migration exceptions without deleting them.
- Custom reports can now start from the record type they need to preserve,
  allowing null-parent/orphan reviews that cannot be reached from Terms.
- Legacy enrollment term, CRN, and course values are labeled cleanup-only and
  remain non-relational; the catalog cannot recreate Enrollment -> Course.
- Cyclic report join graphs are rejected before SQL generation to prevent
  duplicate aliases, row fan-out, and gateway timeouts.

### Setup

- Setup no longer needs a token from the command line. Open `/setup/` from
  the server itself (localhost, or an address listed in
  `SLOCLOUD_SETUP_ALLOWED_IPS`), enter a folder outside the web root for the
  encryption keys, and paste back the verification code setup writes there.
  `key.yaml` is created in that folder and its location is recorded as
  `security.key_path` in `config.yaml`, so nothing has to be moved after
  install. `system/scripts/create_setup_token.php` has been removed.
- Setup generates the LTI consumer key and shared secret (32 random bytes),
  stores the secret encrypted, and shows both on the completion screen and
  under System > Configuration > LTI for copying into the LMS.
- The application, key rotation, and the status API now share one key lookup
  order: `SLOCloud_KEY_PATH`, then `security.key_path`, then the old
  `src/config/key.yaml` and `src/key.yaml` locations. Key-rotation backups are
  written beside `key.yaml` instead of into the web root.

### Upgrading

This release changes the database schema. Follow
[docs/upgrades/Ver5.1.1_to_5.2.1.md](../docs/upgrades/Ver5.1.1_to_5.2.1.md)
(and the 5.1.0 -> 5.1.1 plan if the database has never been versioned).

- Schema changes now ship as versioned migration files in
  `src/system/database/migrations/` for MySQL and SQL Server, each with an
  upgrade plan in `docs/upgrades/`. Apply them with
  `php src/system/scripts/apply_migration.php`, which reads the database's
  recorded schema version, applies the pending files in order with the
  configured table prefix, and requires Maintenance Mode.
- The database now records its schema version in a new `meta` table.
- Fresh SQL Server installs now include the record lifecycle columns that the
  MySQL schema already had.
- The migration converts existing "Pending" achievement levels (and empty
  values) to "Not Assessed".

### New Features

- Custom reports can display decrypted student identity fields (Student ID,
  First Name, Last Name). They are decoded after the query runs, so they can
  be shown, exported, printed, and counted but not filtered, sorted, or
  grouped; runs, prints, and exports that include them are audited. Refresh
  the report catalog after upgrading to make the fields available. Internal
  row keys are now labeled "Key" so they are not confused with the Student ID.
- Every custom report has an "Administrators only" option, on by default and
  on for all existing reports. Turning it off lets any active user run, print,
  and export that report.
- New reports can be created by pasting a JSON definition (New from JSON, or
  Advanced Source from the editor), not only by editing an existing one.
- Added the "CSLO report" starter: student ID, achievement level, CSLO code and
  description, course, and section for the selected terms, up to 20,000 rows.
  The default maximum row limit is now 20,000 to match.
- Added a global-administrator-only LTI 1.1 launch bootstrapper using the standard AdminLTE interface. Administrators can open canonical section and cross-listed launch contexts directly from their respective administration screens, review the generated LTI parameters, create an OAuth HMAC signature in the browser, inspect its timestamp, nonce, and signature, and submit the simulated launch through the production validation path.
- Added activation and deactivation controls for terms, departments, outcomes,
  programs, courses, sections, students, enrollments, assessments, and
  cross-list entries. Management screens show active records by default and
  provide a page-local toggle for viewing deactivated records only.
- Added expandable related-record summaries to administration tables with
  direct child dependencies, including counts, active/inactive state, and
  compact relationship-specific tables.

### Improvements

- The "Pending" assessment achievement level is renamed "Not Assessed" and is
  the default for new assessments; achievement level can no longer be empty.
- A failed LTI launch signature check now shows a page explaining the likely
  cause (consumer key, shared secret, or launch URL in the LMS not matching
  SLO Cloud) and what to do next. On IIS, `web.config` passes this page
  through instead of replacing it with the generic 401 error page.
- Custom report limits are now configurable instead of hardcoded: the new
  `reports` section of `config.yaml` (also on the System Configuration page)
  sets the maximum and default row limits, the multi-select option search
  size, the `in` filter value cap, and the option search debounce.
- Normalized enrollments under course sections using `section_fk`; term, CRN, and course context now resolve through the section record across imports, administration, dashboards, LTI authorization, and Dynamic Reports.
- Added a guarded enrollment migration utility that backs up legacy mappings, validates term/CRN resolution, supports retaining unresolved legacy rows for later review, and works with both MySQL and SQL Server installations.
- Enforced one CRN per term and updated fresh setup schemas and starter reports for the section-owned enrollment model.
- Added effective-parent status handling: children of a deactivated parent are
  excluded without overwriting the children's stored activation state and
  automatically become available again when their required parent is active.
- Excluded deactivated and effectively inactive records from dashboard
  statistics, report results and filter options, active selectors, normalized
  imports, and term-copy operations.
- Imports and term copy now skip deactivated matching records instead of
  updating, duplicating, or implicitly reactivating them, and report the
  affected records in their results.
- Disabled individual academic-record deletion from dashboard screens and
  server-side dashboard requests in favor of deactivation. The guarded Clear
  Term Data workflow and deletion of saved custom-report definitions remain
  available.
- Added indexed, default-active lifecycle fields to the connected database and
  the MySQL reference schema, plus an idempotent maintenance utility that
  synchronizes and verifies lifecycle metadata and effective-status queries.
- Added audit events for record activation and deactivation.
- Moved the infrequently used activation and deactivation actions from table
  rows into each record's edit dialog, while preserving confirmation,
  parent-status safeguards, and audit logging.
- Standardized administration-row actions as non-wrapping control groups and
  added accessible names, expanded-state announcements, live loading and error
  messages, scoped table headings and captions, keyboard-scrollable dependency
  regions, and text labels that do not rely on color alone.

- Custom Report builder accessibility: display-column and filter rows are
  identified by a row header and every row control is named by its column
  header and field; joined-table checkboxes are grouped under a legend;
  Break and Summarize instructions are visible text instead of hover-only
  tooltips; keyboard focus is kept on the equivalent control when rows are
  re-rendered, moved, or removed; and additions, moves, and removals are
  announced to screen readers.

### Security Fixes

- Every encryption key rotation (config, student data, and student lookup)
  now requires Maintenance Mode, not only the student lookup key. The API
  rejects the request and the Configuration page disables the rotate buttons
  while Maintenance Mode is off.
- System Status has a new Key Location check that reports critical when any
  `key.yaml` (the active key, a `SLOCloud_KEY_PATH` target, or a key-rotation
  backup) is inside the web root or the site's document root.
- The status API's overall status is now decided by which check failed, not
  by how many: a failing configuration, PHP extension, session, or database
  check reports `down` on its own, and any other problem reports `degraded`.
  A database connection failure previously reported only `degraded`.
- The status API's OAuth and debug-mode checks now read the settings the
  application uses (`lti.validate_signatures`, `app.debug_mode`); before,
  they read keys that did not exist and never appeared.

## v5.1.1 (2026-07-20)

### New Features

- Added an administrator-only Activity Log report with filters and CSV export.
- Added administrator controls to create or rotate the keyed student-ID lookup index, including first-time adoption for installations still using legacy unkeyed hashes.

### Security Fixes

- LTI assessment reads and writes are now bound to the signed launch context and reject stale, altered, or unauthorized requests.
- Dashboard XSS paths were closed, and shared Content-Security-Policy enforcement now applies across routes.
- Sessions, protected configuration, logs, and runtime data are better isolated from web access.
- Administrator-only routes are centrally enforced, with simplified user access controls.
- Student lookup hashes now use an install-specific HMAC key. Administration-panel and maintenance-script rotations recover each plaintext ID from encrypted student data, rehash all rows transactionally, and do not depend on the previous lookup key.
- Student data operations and key rotation now share a coordination lock so imports and updates cannot overlap a key transition.
- Security headers, OAuth validation, login throttling, session rotation, and emergency credential handling were hardened.
- Configuration writes are encrypted, atomic, backed up, and fail closed on invalid secret values.
- Setup now requires a short-lived token, CSRF validation, an allowed source, and a named initial administrator.
- Admin error handling now returns generic browser messages outside development while preserving server-side detail.
- Activity Log output excludes raw log payloads and student PII.

### Improvements

- Added a shared in-app Markdown renderer for patch notes and accessibility documentation; VPAT reports and guides now render within the administration theme instead of opening as raw Markdown files.
- Corrected the normalized import routine to keep departments, programs, courses, outcomes, sections, enrollments, mappings, and cross-listings scoped to each CSV row's term.
- Corrected File 5 PSLO-to-ISLO alignment imports and preserved those alignments when copying term data.
- Corrected Copy Term Data to upsert institutional outcomes, departments, programs, program outcomes, courses, student learning outcomes, and program-course mappings using the same entity keys as normalized imports. Existing destination records are now refreshed in place, missing records are created, CSLO-to-PSLO mappings are refreshed, and repeated copies no longer create duplicates for any copied entity type.
- Improved CSV text normalization for Windows-1252 punctuation and unsupported Unicode characters that previously caused database-operation failures.
- Added preflight validation requiring `term_code` as the first column in all 12 normalized import files; malformed files now fail before any data is written.
- Added an import processing state that disables all import buttons and shows a spinner on the submitted file to prevent duplicate submissions.
- Removed the PHP execution-time ceiling from normalized imports so deployments can allow long-running imports without extending unrelated requests.
- Corrected floated AdminLTE card headings on the key-management and normalized-import screens so descriptions remain below their titles and action buttons align consistently.

## v5.1.0 (2026-07-19)

### New Features

- Added Dynamic Reports for validated custom reporting with saved definitions, printing, and CSV export.
- Added multi-launch LTI session support and an embedded assessment keepalive endpoint.
- Added branded 403, 404, and 500 error pages.
- Improved user and role management screens.

### Security Fixes

- Added default-deny route authorization across web entry points.
- Moved sessions, logs, setup tokens, and report state outside the shipped `src/` web root.
- Hardened session cookies, session rotation, LTI expiry checks, and CSRF-protected logout.
- Hardened setup, configuration encryption, LTI OAuth validation, structured logging, and audit event normalization.
- Vendored browser dependencies under `src/assets/vendor/` with licenses, policy, and checksums.
- Blocked direct web access to sensitive config, log, backup, and archive directories.
- Added key-rotation backups, critical failure alerts, LTI session checks, and safer cross-driver database queries.

### Improvements

- Completed WCAG 2.2 Level AA implementation evidence for administration and LTI scopes.
- Added security regression checks for route policy, protected paths, OAuth/setup/logout controls, encryption, local assets, and vendor checksums.
- Improved CSV date normalization, PSLO import column handling, SLO form handling, and assessment save errors.
- Added IIS deployment support and configurable logging location/retention.
- Documented moving encryption keys outside the web root.
- Changed the default table prefix for new installs to `slocloud_tbl_`.
- Centralized schema prefix substitution and clarified report catalog behavior.
- Report catalogs are now live per-install runtime state and saved reports are revalidated after catalog rebuilds.
- Backups now use directory copies instead of requiring `ZipArchive`.

### Maintenance

- Removed legacy standalone cross-listing scripts and bulk staging CSV files.

---

## v5.0.2 (2026-05-11)

### New Features

- Added key rotation controls for config and student data encryption keys.

### Security Fixes

- Config/key rotation now uses atomic writes and restores originals on failure.
- Student data re-encryption now runs in one database transaction before key updates are committed.

---

## v5.0.1 (2026-05-10)

### Removed

- Removed unfinished denormalized CSV import UI paths and raw staging data files.
- Removed the de-scoped `imports.php` UI link.

### Security Fixes

- Removed invisible characters from PHP files.
- Hardened CSV upload validation on the remaining import path.
- Added API key authentication to `/api/`.
- Tightened cross-listed CRN authorization in LTI launches.

### New Features

- Added cross-listed section management.

### Improvements

- Normalized cross-listed CRN handling and improved related error messages.
- Updated the VPAT to reflect the current implementation state.

---

## v5.0.0 (2026-05-10)

### New Features

- Added the Administration Dashboard, with the current term, section statistics and a sections explorer.
- Added User Management: add and edit users with their name, email, password and Administrator role, and deactivate accounts.
- Added management screens for terms, disciplines, programs, courses, sections, students, enrollments, assessments, and institutional, program and course learning outcomes.
- Added the System Configuration page.
- Added cross-listing management, normalized term imports, CSLO reporting, system status, VPAT viewing, global term selection, and LTI administration.

### Improvements

- Added the AdminLTE 4 theme, reorganized navigation, two-file encryption, configuration template merging, parameterized queries, configurable table prefixes, and session hardening.

### Security

- Added Argon2id password hashing, configurable emergency admin access, CSRF protection, and AES-256 student data encryption.

---

## v4.x (Legacy)

Prior releases did not maintain formal patch notes. Please refer to git history for changes prior to v5.0.0.
