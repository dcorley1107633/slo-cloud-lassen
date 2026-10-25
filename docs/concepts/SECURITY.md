# Security Architecture

## Security Philosophy

Defense-in-depth approach with FERPA compliance for educational assessment data.

## Security Layers

```
Transport (HTTPS/TLS)
    ↓
Authentication (3 methods)
    ↓
Authorization (RBAC + Context)
    ↓
Application (Validation, Escaping, CSRF)
    ↓
Data (Prepared Statements, Encryption, Audit)
```

## Threat Model

**Assets to Protect:**
- Student assessment data (FERPA protected)
- User credentials
- Institutional data
- System integrity

**Threat Sources:**
- External unauthorized access
- Misuse by authenticated users
- Compromised accounts
- Insider misuse

**Attack Vectors:**
- SQL injection
- XSS (Cross-Site Scripting)
- CSRF (Cross-Site Request Forgery)
- Session hijacking
- Brute force attacks
- Data exfiltration

## Security Controls

### 1. Input Validation

**All User Input:**
- Type checking
- Length limits
- Whitelist validation
- Reject suspicious patterns

### 2. SQL Injection Prevention

**Prepared Statements:**
- All database queries use parameterized statements
- No string concatenation in SQL
- Input bound separately from query structure

### 3. XSS Prevention

**Output Escaping:**
- HTML escape all user-generated content
- Context-aware escaping (HTML, JS, URL)
- Content Security Policy headers

### 4. CSRF Protection

**Token Validation:**
- Unique token per session
- Token in all forms
- Verify token on POST/PUT/DELETE
- Reject requests without valid token

Every cookie-authenticated state-changing route checks `csrf_token` with
`hash_equals()` against the session-stored value, including `logout.php`,
`session.php`, and the setup wizard.

**Protocol endpoints are the one documented exception**, because a session
cookie isn't how they authenticate a request in the first place:

- `lti/launch.php` and related LTI routes authenticate the request via the
  signed OAuth 1.0 launch (a forged request can't reproduce a valid signature
  without the shared secret).
- `saml/acs.php` and related SAML routes authenticate via the IdP-signed
  assertion, which must also answer a sign-in this application started (see
  AUTH.md, SAML 2.0 Single Sign-On).
- `api/api.php` authenticates via `X-Api-Key` or an existing admin session
  (which itself remains CSRF-checked for any state-changing call).

These routes are classified separately in `Core\Authorization`'s route policy
map (`lti_protocol`, `saml_protocol`, `api_key_or_admin`) rather than
`authenticated_csrf`, precisely because a session-cookie CSRF token has no
role to play when the request's authenticity already comes from a signature
or a key.

### 5. Authentication Security

**Password Security:**
- Argon2id hashing (64MB memory, 4 iterations, 2 threads)
- Salt automatically handled by Argon2id
- No password length maximum
- Minimum 12 characters, enforced by `Core\PasswordPolicy`
- Common and shipped-default passwords rejected, including trailing-digit
  variants such as `password2024`
- Passwords containing the account's name or email address rejected
- No composition rules: character-class requirements push users toward short
  predictable patterns, so length plus a blocklist is used instead

`PasswordPolicy` is the single source of truth and is called by the admin user
editor, the setup wizard, and `system/scripts/create_admin_user.php`. Adding a
new account-creation path means calling it there too.

**Login Rate Limiting (`Core\LoginLimiter`):**
- Attempts are counted by both client address and targeted account.
- Three free attempts are allowed before an escalating lock.
- Counters are updated with exclusive file locks and stored under the protected
  data root (`var/loginlimits`), never under the document root.
- Refusals and lockout durations are logged for monitoring.

> **Multi-node deployments:** `flock` only coordinates processes sharing one
> filesystem. Behind a load balancer the counters are per-node, not global, and
> need a database- or cache-backed store. The `LoginLimiter` interface is
> backend-agnostic so that change will not touch callers.

**OAuth Security:**
- Signature validation (LTI)
- Nonce checking (replay prevention) through a unique
  consumer+nonce+timestamp constraint, which makes replay consumption atomic
- Clock skew tolerance: 5 minutes
- Every failed launch is written to the security log with a machine-readable
  reason and the source address
- Failed launches are throttled, tuned so a lockout is very hard to reach by
  accident (see below)

**LTI launch throttling.** Failed launches are rate-limited with settings that avoid locking out a legitimate LMS during ordinary retries or configuration repair:

| Control | Setting | Why |
| --- | --- | --- |
| Bucket key | Source address only | Avoids institution-wide lockouts. |
| Counted failures | `invalid_consumer`, `signature_mismatch`, `nonce_replay` | Only credential failures. A misconfiguration cannot arm the lockout. |
| Allowance | 100 distinct failures per rolling 60s | High enough for legitimate recovery, low enough for monitoring. |
| Lock | 60s, flat | Not escalating, and matched to the window, so a fixed misconfiguration clears within a minute. |
| De-duplication | On `oauth_signature` | A retried request counts once, so a client cannot lock itself out. |

Keep LTI consumer secrets long and randomly generated. Monitor spikes in `signature_mismatch` and `invalid_consumer` security-log entries.

### 6. Session Security

**Configuration:**
- HttpOnly cookies (no JS access)
- Secure flag (HTTPS only)
- SameSite=Strict
- 2-hour timeout
- Regenerate ID on privilege change
- IP address validation (session hijacking prevention)

**Session Hijacking Prevention:**
- Initial IP stored in `$_SESSION['initial_ip']` on first request
- IP checked on every subsequent request
- Session destroyed if IP changes
- Automatic re-authentication required after IP change

**Implementation:**
```php
// Automatic in init.php
if (!isset($_SESSION['initial_ip'])) {
    $_SESSION['initial_ip'] = $_SERVER['REMOTE_ADDR'];
} elseif ($_SESSION['initial_ip'] !== $_SERVER['REMOTE_ADDR']) {
    session_destroy();
    session_start();
}
```

**Note:** IP validation may cause issues for users behind:
- Rotating proxies (mobile carriers, some ISPs)
- VPNs that rotate endpoints
- Dual-stack IPv4/IPv6 transitions

For environments with rotating client addresses, review this control before deployment and adjust it through the application security layer.

### 7. Authorization Security

**Access Control:**
- Every action checks permissions
- Context-aware authorization
- Deny by default
- Log authorization failures

### 8. Data Security

**Encryption:**
- Secrets encrypted at rest (AES-256-GCM)
- Passwords hashed with Argon2id
- API keys encrypted in database
- TLS 1.2+ for transport
- Key management: Static key (default) or Key Protection Service (enterprise)

**Key Protection Service (KPS):**
- External key management for enterprise deployments
- Separates encryption key from config file
- Centralized key rotation and auditing
- Falls back gracefully on KPS failure (logs error, encryption unavailable)
- 5-second timeout to prevent blocking
- See [CONFIGURATION.md](CONFIGURATION.md#encryption-key-sources) for setup

**Audit Logging:**
- All authentication events
- Authorization failures
- Data modifications
- Admin actions
- Include: user, timestamp, IP, action

### 8a. Student PII Encryption

**Purpose**: FERPA-compliant protection of student identifiable information (names and C# numbers).

**Encryption Strategy:**
- **Separate Key**: Uses `security.student_encryption_key` (NEVER reuse `security.encryption_key`)
- **Algorithm**: AES-256-GCM authenticated encryption
- **Storage**: JSON blob `{c_number, first_name, last_name}` encrypted as single field
- **Hash-Based Lookups**: keyed HMAC-SHA256 of C# stored in `student_id_hash` for import matching,
  when a lookup key is configured (see below)

**Schema Design:**
```
students table:
  - students_pk (PK)
  - student_data_encrypted (TEXT) - Encrypted JSON blob
  - student_id_hash (VARCHAR(64)) - SHA-256(c_number), UNIQUE INDEX
  - is_active, timestamps, audit fields
```

**Rationale:**
- **Single Blob**: Names and C# encrypted together (atomic, faster than 3 separate encryptions)
- **Hash Column**: Enables import matching without decrypting existing records
- **One-Way Hash**: Cannot reverse hash to obtain C# (limits exposure in SQL dumps)
- **Separate Key**: Different threat model from config secrets (FERPA compliance)

**Lookup key.** `security.student_lookup_key` makes `student_id_hash` a keyed
HMAC instead of plain SHA-256. Keep this key outside database backups and rotate
it only with the supported tool.

`setup/index.php` generates this key for every fresh install. An install with
no key keeps using the original unkeyed SHA-256, unchanged, so upgrading the
code never silently breaks existing lookups.

**Never add, change, or remove this key by hand.** `student_id_hash` is a
search-by-recompute column: the application hashes an incoming C# and searches
for a match, so every stored digest must have been produced by exactly the key
in force. Editing the key without rewriting those rows makes every existing
student stop matching on the next import - real, already-enrolled students get
re-inserted as duplicates, with no error anywhere.

**To adopt or rotate the key, use the tool:**

```
php src/system/scripts/rotate_student_lookup_key.php --dry-run   # report only
php src/system/scripts/rotate_student_lookup_key.php             # rewrite + swap key
```

Rotation is possible because the C# is stored *twice*: one-way in
`student_id_hash`, and reversibly in `student_data_encrypted` under a separate
key. The encrypted column is effectively escrow for the hash input, so the tool
decrypts each row, recomputes the digest under the new key, and updates
`key.yaml` itself as the final step. No re-import from the SIS is required.

Because recomputation depends only on (plaintext, target key), it is idempotent:
an interrupted run is repaired by re-running it with the same key, which the
tool records to a protected recovery file before touching any row. That is why
no key-version column is needed and this stays a data migration rather than a
schema change.

It requires a maintenance window. While the rewrite is in progress, stored
digests and the configured key are necessarily out of step, and a concurrent
import would fail to match and insert duplicates. The tool refuses to run unless
`app.maintenance_mode` is on, refuses to swap the key if any row failed to
decrypt, and verifies all digests are distinct before committing to the new key.

After rotating, run a small CSV import of known-existing students and confirm
they **match** rather than being inserted as new records.

**Import Workflow:**
1. CSV contains plaintext C# "12345678"
2. Hash C# → HMAC-SHA256 (or SHA-256 if no lookup key is configured) → `abc123...def789`
3. Query: `SELECT students_pk WHERE student_id_hash = 'abc123...def789'`
4. If found: Use existing `students_pk` for FK
5. If not found: Encrypt `{c_number, first_name, last_name}` and INSERT

**Performance:**
- 30k student records: ~30-60 seconds for full re-encryption
- Hash lookups are indexed (fast during imports)
- Decryption only on display (not in WHERE/JOIN clauses)

**Trade-offs:**
- **Cannot search encrypted fields**: Student name search disabled in DataTables (only non-encrypted fields searchable)
- **Cannot sort by name server-side**: Pagination works, but name-based sorting limited
- **Decryption overhead**: Every record displayed requires decryption (acceptable for 30k scale)

**Key Rotation:**
For 30k student scale, use direct re-encryption during maintenance window:
```sql
-- Read all records, decrypt with old key, encrypt with new key, UPDATE
-- Takes 30-60 seconds for 30k records
```

Envelope encryption and versioned keys are overkill for this scale (YAGNI principle).

**Migration:**
Existing installations must run one-time migration:
```bash
php src/system/migrations/migrate_encrypt_students.php
```

See migration script for detailed steps.

**Benefits:**
- FERPA-compliant PII protection
- SQL dump doesn't expose student identities
- Database and encryption keys are stored and protected separately
- Separate key isolation (different rotation schedule, different access controls)

### 9. HTTP Security Headers

Sent centrally by `Security::sendResponseHeaders()`, called from `init.php` so
every entry point gets the same set:

- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Permissions-Policy: camera=(), microphone=(), geolocation=()`
- Enforced `Content-Security-Policy` limiting scripts, styles, images, fonts,
  connections, forms, base URLs, objects, and frame ancestors to local sources.
  Existing inline scripts and styles remain allowed for compatibility. Normal
  pages use `frame-ancestors 'self'`; LTI pages use `'self' https:` so LMS host
  changes do not break embedding.
- `Cache-Control: no-store, private`

`X-XSS-Protection` is deliberately not sent. The header is obsolete and ignored
by current browsers.

**Transport security (opt-in).** Both controls stay off until an operator has
confirmed every route is reachable over HTTPS, because a premature HSTS policy
can block access to routes that still require HTTP:

| Variable | Effect |
| --- | --- |
| `SLOCLOUD_FORCE_HTTPS=true` | `Security::requireHttps()` redirects plaintext requests before the session cookie is issued. GET/HEAD get 301; other methods get 308 so the method and body survive. |
| `SLOCLOUD_HSTS_MAX_AGE=<seconds>` | Sends `Strict-Transport-Security`, and implies the redirect above. Only emitted on a connection that is already secure. |
| `SLOCLOUD_HSTS_INCLUDE_SUBDOMAINS=true` | Adds `includeSubDomains`. Verify every subdomain serves HTTPS first. |

Redirect targets come from `SERVER_NAME` unless the immediate peer is listed in
`SLOCLOUD_TRUSTED_PROXIES`; an untrusted `Host` header is never allowed to steer
the redirect.

### 9a. Database Transport Security

SQL Server connections require encrypted transport. Deploy the database on a
trusted network segment and review certificate-validation requirements whenever
the database host or network boundary changes.

### 10. File Upload Security

**Validation:**
- Whitelist file types
- Size limits
- Virus scanning (if available)
- Store in protected directory (src/uploads/ with .htaccess deny)
- Serve with Content-Disposition: attachment

## FERPA Compliance

**Requirements:**

1. **Access Control**: Only authorized users access student records
2. **Audit Trail**: Log all access to student data
3. **Consent**: Student consent for data sharing (if applicable)
4. **Data Minimization**: Collect only necessary data
5. **Breach Notification**: Incident response plan
6. **Data Retention**: Documented retention policies

**Implementation:**
- Role-based access control
- Audit logging on all student data access
- Encrypted storage of sensitive data
- Secure data disposal procedures

## Security Best Practices

### Code Level

**Do:**
- Validate all input
- Escape all output
- Use prepared statements
- Check permissions on every action
- Log security events
- Handle errors gracefully
- Use HTTPS everywhere

**Don't:**
- Trust user input
- Store passwords in plain text
- Use string concatenation in SQL
- Display detailed error messages to users
- Log sensitive data
- Use deprecated cryptography

### Deployment

**Pre-Production:**
- Penetration testing
- Security code review
- Dependency vulnerability scan
- Configuration audit

**Production:**
- HTTPS with valid certificate
- Disable debug mode
- Restrict file permissions
- Regular security updates
- Monitor logs for anomalies
- Backup regularly

## Incident Response

**Process:**

1. **Detection**: Monitor logs, user reports
2. **Containment**: Isolate affected systems
3. **Investigation**: Determine scope and cause
4. **Remediation**: Fix vulnerabilities
5. **Recovery**: Restore normal operations
6. **Post-Mortem**: Document and improve

**Contacts:**
- Security team
- System administrators
- Institution's IT security office
- FERPA compliance officer

## Security Checklist

### Pre-Deployment

- [ ] All passwords hashed with Argon2id
- [ ] Prepared statements for all queries
- [ ] Output escaping on all user data
- [ ] CSRF tokens on all forms
- [ ] HTTPS configured with valid certificate
- [ ] Security headers configured
- [ ] Debug mode disabled
- [ ] Error logging enabled (not displayed)
- [ ] File permissions restrictive
- [ ] Database credentials secured
- [ ] Audit logging enabled

### Ongoing

- [ ] Regular security updates
- [ ] Monitor audit logs
- [ ] Review access permissions
- [ ] Test backup restoration
- [ ] Incident response plan updated
- [ ] Security training for admins
- [ ] Third-party code reviews

---

**Last Updated**: February 2026
