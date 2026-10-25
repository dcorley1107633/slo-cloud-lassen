# Authentication & Authorization

## Authentication Strategies

Three authentication methods can run side by side (dashboard password, LTI, and optional SAML single sign-on for the dashboard):

### 1. Dashboard Authentication
- Local username/password
- Argon2id hashing (64MB memory, 4 iterations, 2 threads)
- Session-based
- Used by: Admins, faculty, coordinators

### 2. LTI Authentication
- **LTI 1.1**: OAuth 1.0 signature validation with an explicit algorithm allowlist (HMAC-SHA256 preferred; HMAC-SHA1 retained for LMS compatibility)
- **LTI 1.3**: JWT/JWKS with RSA keys (TODO)
- Auto-provision users from LMS
- Role mapping from launch parameters
- Nonce-based replay attack prevention
- Used by: Faculty and students via LMS

**LTI 1.1 OAuth Flow:**
1. LMS sends POST to `/lti/launch.php` with OAuth parameters
2. SLOCloud validates consumer key against configured key
3. SLOCloud decrypts consumer secret from config (AES-256-GCM)
4. SLOCloud calculates the expected signature using an explicitly allowed algorithm against the configured canonical launch URL
5. Compares submitted signature with expected signature
6. Checks nonce hasn't been used (prevents replay attacks)
7. Validates timestamp is within 5 minutes
8. If all checks pass, creates authenticated session

**Configuration:**
```yaml
security:
  encryption_key: "64-character-hex-string"  # For encrypting secrets

lti:
  consumer_key: "institution-provisioned-key"
  consumer_secret: "ENC:encrypted_secret_value"
  launch_url: "https://slo.example.edu/lti/launch.php"
  validate_signatures: true
  nonce_expiration: 3600                      # Replay attack prevention window
```

**Security Features:**
- Consumer secret encrypted at rest with AES-256-GCM
- Authenticated encryption prevents tampering
- Nonce validation prevents replay attacks
- Timestamp validation (5-minute window)
- Constant-time signature comparison
- Signature validation can be disabled only when `SLOCLOUD_ENV=test`

### 3. SAML 2.0 Single Sign-On (dashboard)

Optional. Replaces the password for the administration dashboard with the
institution's identity provider (Microsoft Entra ID, Okta, Shibboleth, ADFS).
Setup for Entra ID: [ENTRA_SSO_SETUP.md](../ENTRA_SSO_SETUP.md).

- The SP Entity ID, Reply (ACS) URL, Sign-on URL and logout URL are built from the
  configured `saml.site_url` and `app.base_url`, never from the request's `Host`
  header, and are shown on the Configuration page to copy to the IdP. The
  `sp_*` settings override them when set.
- Built on the bundled `onelogin/php-saml` (see `src/system/lib/README.md`);
  `Core\Saml` configures it and adds the checks below.
- Files: `saml/sso.php` (start), `saml/acs.php` (sign-in), `saml/metadata.php`
  (SP metadata for the IdP administrator), `saml/slo.php` (single logout).
- **Accounts.** The email in the assertion must match an existing, active
  SLOCloud user, or the sign-in is refused and logged. Optional
  `saml.auto_provision` (off by default) creates the missing account at first
  sign-in for emails at the domains in `saml.auto_provision_domains` (required;
  subdomains do not match). The new account is active, has **no admin role**
  (the read/export tier), and gets a random password nobody knows. An existing
  or deactivated account is never changed or reactivated. Logged as
  `saml_auto_provisioned`.
- **Who may sign in is decided at the IdP** (Microsoft Entra ID: *Assignment
  required = Yes* and the people or group assigned). SLO Cloud does not check
  group membership. Auto-provisioning's domain list is only a second check.
- Accepted only if the IdP signed the Assertion (and the Response, when
  `saml.require_signed_response` is on) with a configured certificate using
  SHA-256 or stronger. SHA-1 is refused. Two certificates can be configured
  so the IdP can rotate its certificate without an outage.
- Signature wrapping is refused: the signature must cover the one Assertion
  that is read, and a Response with more than one Assertion is rejected.
- Audience, issuer, destination, recipient, time window and `InResponseTo` are
  all enforced; none may be absent.
- **SP-initiated only.** `sso.php` records each AuthnRequest ID under
  `<data>/var/saml`; a response is accepted only if it answers a recorded
  request, once, within 10 minutes. This stops replay of a captured response
  and refuses responses the IdP sends on its own. (The record is on disk, not in
  the session, because the session cookie is `SameSite=Strict` and is not sent on
  the IdP's cross-site POST.)
- No `RequestedAuthnContext` or `NameIDPolicy` is sent, so the IdP chooses the
  sign-in method (MFA, passkey) and its configured NameID.
- `RelayState` is never read back, so there is no redirect target to tamper with.
- Optional: signed AuthnRequests and encrypted assertions (SP certificate and
  private key file, kept outside `config.yaml`); single logout when both
  `saml.idp_slo_url` and `saml.sp_slo_url` are set (IdP logout messages must
  be signed).
- The Configuration page refuses to enable SAML while its settings cannot work.
- Activity Log records `method: saml` successes and failures with a reason code
  (`saml_invalid_response`, `saml_unsolicited_response`, `unknown_account`,
  `account_inactive`, `saml_idp_logout`); the library's detailed reason goes to
  the log file only.

Tests: `php tests/saml_test.php` (signed fixtures for the accepted and refused cases).

### Emergency Admin (Break Glass Account)

**Configuration-Based Emergency Access**: Defined in `config.yaml` for recovery scenarios when database-backed login is unavailable.

**Purpose**: 
- Recover from database user lockouts
- Emergency access when normal authentication fails
- No database dependency

**Configuration** (`config.yaml`):
```yaml
emergency_admin:
  enabled: false
  username: ""
  password: ""                       # Argon2id hash when explicitly provisioned
```

**Security Considerations**:
- Plaintext passwords are rejected; only a password hash is accepted
- Uses configuration-backed recovery authentication
- All logins logged with "Emergency admin login used" warning
- Session marked with `is_emergency_admin` flag
- Disabled by default and provisioned only through an approved recovery procedure

**Authentication Flow**:
1. User submits login form
2. System checks emergency admin credentials FIRST
3. If match: Instant access with special session
4. If no match: Normal database authentication proceeds

**Session Attributes**:
- `user_id: 0` (special emergency admin ID)
- `user_name: "Emergency Admin"`
- `is_emergency_admin: true`
- Full system access (equivalent to System Admin role)

## Authentication Flow

**Unified Login Router:**
```
Request → Detect Auth Type → Route to Provider → Validate → Create Session
```

**Detection Logic:**
- LTI: POST to `/launch.php` with LTI parameters
- Dashboard: Default login form at `/login.php`

## Authorization

Dashboard authorization is deliberately **two-tier**, not a full role-based
system. The tiers are expressed with columns that already exist on an account —
no separate "user" role. Fine-grained RBAC is deferred as disproportionate to
the current single-institution deployment model.

### The two tiers

| Grant | Meaning | Enforced by |
| --- | --- | --- |
| `is_active` on the account | The **user / read tier**: may sign in, view data, and run and export reports. Being active *is* the user tier — there is no separate `user` role. | `login.php` (login requires `is_active`); route policy `authenticated` |
| Global `admin` role | The **edit tier**, added on top: all mutations plus user, key, and configuration management. | `Authorization::isGlobalAdmin()` / `requireAdministrationRoute()`; `report_user_can_manage()` for reports |

- `is_active = 0` → no access (the off switch; also revokes read access).
- No role → read-only. The global `admin` role is the only grant that unlocks
  editing. A legacy `user` role row and a role-less account are the same tier.
- Deny-by-default: routes not classified in `Core\Authorization` are denied, and
  the shared `deny()` path returns 403 with a sentence saying why (HTML, or JSON
  for background requests) and logs a `permission_denied` event.
- There is no separate step-up for sensitive edits: while a session is valid, an
  administrator can do everything the role allows. Sessions end instead (below).

The account editor exposes exactly these two controls: an **Active** toggle and
an **Administrator** toggle.

### Faculty and object scoping

Faculty are **not** dashboard roles. They reach assessment through a signed LTI
launch, and their scope comes from a short-lived server-side context rather than
a dashboard grant or browser-supplied ownership fields. Normal launches authorize
one term-bound section; cross-listed launches authorize the resolved sections in
that signed term and cross-list group.

### Not implemented (reserved for a future RBAC effort)

Per-role, per-context dashboard permissions — e.g. scoping a dashboard user to
their own department or courses — are **not** implemented. The `user_roles`
table carries `context_type` / `context_id` columns, currently always `NULL`
(global), reserved so that a scoped model can be added later without a schema
migration. Until then, any dashboard `view` user can see all institutional data,
including student PII, so dashboard logins are issued only to trusted staff.

## Session Management

**Security Configuration:**
- HttpOnly cookies
- Secure flag (HTTPS only)
- SameSite=Strict
- Session timeout: 2 hours idle (extendable from its warning), and 20 hours after
  sign-in however active (WCAG 2.2.1 20-hour exception). Either
  one signs the user out completely, explains why on the login page, and returns
  them to the page they were on after signing in. Background requests receive
  401 `session_ended` JSON, which `system/accessibility.js` turns into a redirect
  to the login page.
- Regenerate ID on login
- Destroy on logout

## Multi-Auth Scenarios

**Same User, Different Methods:**
System links accounts by email or institution ID:

```
user@example.edu logs in via:
  - Dashboard (username/password)
  - LTI from Canvas

All link to same user record.
```

## Security Considerations

**Password Policy:**
- Minimum 8 characters
- Requires: uppercase, lowercase, number
- Argon2id (64MB memory cost, 4 time cost, 2 threads)
- No password reset via email (admin reset only)

**OAuth Security:**
- Signature validation
- Nonce prevention (replay attacks)
- Clock skew tolerance: 5 minutes
- Certificate validation

**Session Security:**
- IP binding (optional)
- User agent validation
- Concurrent session limit
- Auto-logout on inactivity

## User Provisioning

**Automatic User Creation:**

**From LTI:**
- Email, name, role from launch parameters
- Institution mapped from LTI consumer
- Activated on first launch

**Dashboard:**
- Manual creation by admin
- Email invitation with temporary password
- Force password change on first login

---

**Last Updated**: February 2026
