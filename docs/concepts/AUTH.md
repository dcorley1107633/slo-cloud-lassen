# Authentication & Authorization

## Authentication Strategies

Two concurrent authentication methods:

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
  the shared `deny()` path returns 403 and logs a `permission_denied` event.
- Sensitive edits (users, keys, configuration) additionally require recent
  reauthentication.

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
- Session timeout: 2 hours
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
