<?php
declare(strict_types=1);

namespace SLOCloud\Core;

use DOMDocument;
use DOMXPath;
use OneLogin\Saml2\Auth;
use OneLogin\Saml2\Settings;
use OneLogin\Saml2\Utils as SamlUtils;
use OneLogin\Saml2\ValidationError;
use RuntimeException;

require_once __DIR__ . '/../lib/autoload.php';

/**
 * A sign-in the identity provider answered but SLO Cloud cannot accept.
 *
 * getMessage() is the technical reason (for the log file). explanation says
 * what is wrong and what to change, and details lists the values worth
 * comparing; both are shown to the person signing in, so neither may hold
 * anything secret. Values come from the response, so print them escaped.
 */
final class SamlSignInError extends RuntimeException
{
    /**
     * @param string $reason Short code for the Activity Log
     * @param array<string, string> $details Label => value to compare
     */
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly string $explanation,
        public readonly array $details = []
    ) {
        parent::__construct($message);
    }
}

/**
 * SAML 2.0 Service Provider settings and checks, on top of onelogin/php-saml.
 *
 * The library (bundled in system/lib, see the README there) does the XML work:
 * signatures, canonicalization, conditions, audience, destination, issuer,
 * InResponseTo and encrypted assertions. This class decides how it is
 * configured and adds what the library leaves to the application.
 *
 * Fixed security choices (not settings):
 *   - strict mode on; debug off
 *   - the Assertion must be signed by the IdP certificate, and the signature
 *     must cover the Assertion that is read (the library's wrapping defence)
 *   - sign-in is SP-initiated only: every Response must answer an AuthnRequest
 *     this application sent (recorded under <data>/var/saml), and a request ID
 *     works once, so a captured Response cannot be replayed and an
 *     unsolicited (IdP-initiated) Response is refused
 *   - SHA-1 signatures and digests are refused (the library still accepts them)
 *   - no RequestedAuthnContext, so the IdP may sign the user in by any method
 *     (an exact password-only context makes Microsoft Entra ID fail for users
 *     who sign in with MFA or a passkey)
 *   - an AuthnRequest never carries a NameIDPolicy, so the IdP's configured
 *     NameID is used
 *   - logout messages from the IdP must be signed
 *
 * No account is created here. acs.php signs in an existing, active account, and
 * creates a non-admin one only when saml.auto_provision is on and the email is at
 * a listed domain.
 *
 * @package SLOCloud\Core
 */
final class Saml
{
    /** RelayState we send; the value is never trusted or read back. */
    public const RELAY_STATE = 'slocloud';

    /** Seconds an answer may take to come back from the IdP, sign-in prompts included. */
    private const REQUEST_LIFETIME = 600;

    private const BINDING_POST = 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST';
    private const BINDING_REDIRECT = 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect';
    private const NAMEID_UNSPECIFIED = 'urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified';
    private const SIGNATURE_ALGORITHM = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';
    private const DIGEST_ALGORITHM = 'http://www.w3.org/2001/04/xmlenc#sha256';
    private const NS_DS = 'http://www.w3.org/2000/09/xmldsig#';

    private const WEAK_ALGORITHMS = [
        'http://www.w3.org/2000/09/xmldsig#rsa-sha1',
        'http://www.w3.org/2000/09/xmldsig#sha1',
    ];

    private const BOOLEAN_KEYS = [
        'enabled', 'sign_requests', 'require_encrypted_assertions', 'require_signed_response', 'force_authn', 'auto_provision',
    ];

    private const STRING_KEYS = [
        'site_url', 'idp_entity_id', 'idp_sso_url', 'idp_slo_url', 'idp_cert', 'idp_cert_2',
        'sp_entity_id', 'sp_acs_url', 'sp_slo_url', 'sp_cert', 'sp_private_key_file',
        'attr_email', 'attr_name', 'auto_provision_domains',
    ];

    /**
     * The saml section of config.yaml as plain values. SP Entity ID, ACS URL
     * and logout URL that are left blank are built from the Site Address.
     *
     * @return array<string, string|bool>
     */
    public static function configFrom(Config $config): array
    {
        $values = [];
        foreach (self::BOOLEAN_KEYS as $key) {
            $values[$key] = filter_var($config->get('saml.' . $key) ?? false, FILTER_VALIDATE_BOOLEAN);
        }
        foreach (self::STRING_KEYS as $key) {
            $values[$key] = trim((string)($config->get('saml.' . $key) ?? ''));
        }

        $derived = self::derivedUrls($values['site_url'], (string)($config->get('app.base_url') ?? '/'));
        foreach (['sp_entity_id' => 'entity_id', 'sp_acs_url' => 'acs_url', 'sp_slo_url' => 'slo_url'] as $key => $derivedKey) {
            if ($values[$key] === '') {
                $values[$key] = $derived[$derivedKey];
            }
        }
        return $values;
    }

    /**
     * This installation's SAML addresses from the Site Address (scheme, host
     * and port) and the Base URL Path. Fixed in configuration, never taken from
     * the request, whose Host header the client controls.
     *
     * @return array{entity_id: string, acs_url: string, slo_url: string, sso_url: string, metadata_url: string}
     */
    public static function derivedUrls(string $siteUrl, string $basePath): array
    {
        $siteUrl = trim($siteUrl);
        if ($siteUrl === '') {
            return ['entity_id' => '', 'acs_url' => '', 'slo_url' => '', 'sso_url' => '', 'metadata_url' => ''];
        }
        $base = rtrim($siteUrl, '/') . '/' . trim($basePath === '' ? '/' : $basePath, '/');
        $base = rtrim($base, '/') . '/';
        return [
            'entity_id' => $base . 'saml/metadata.php',
            'acs_url' => $base . 'saml/acs.php',
            'slo_url' => $base . 'saml/slo.php',
            'sso_url' => $base . 'saml/sso.php',
            'metadata_url' => $base . 'saml/metadata.php',
        ];
    }

    /** Single logout needs both the IdP's logout URL and this application's logout endpoint. */
    public static function sloEnabled(array $c): bool
    {
        return (string)($c['idp_slo_url'] ?? '') !== '' && (string)($c['sp_slo_url'] ?? '') !== '';
    }

    /**
     * What is wrong with the configuration, in words an administrator can act
     * on. Empty when single sign-on can run.
     *
     * @return string[]
     */
    public static function problems(array $c): array
    {
        $problems = [];

        foreach ([
            'idp_entity_id' => 'Microsoft Entra Identifier (IdP Entity ID)',
            'idp_sso_url' => 'Login URL (IdP SSO URL)',
            'idp_cert' => 'Certificate (Base64): IdP signing certificate',
        ] as $key => $label) {
            if ((string)($c[$key] ?? '') === '') {
                $problems[] = "{$label} is empty.";
            }
        }
        if ((string)($c['sp_entity_id'] ?? '') === '' || (string)($c['sp_acs_url'] ?? '') === '') {
            $problems[] = 'Site Address is empty. Enter the address people use to reach SLO Cloud, for example https://slo.example.edu';
        }

        $siteUrl = (string)($c['site_url'] ?? '');
        if ($siteUrl !== '') {
            $parts = parse_url($siteUrl);
            $path = is_array($parts) ? (string)($parts['path'] ?? '') : '';
            if (!is_array($parts) || isset($parts['query']) || isset($parts['fragment']) || ($path !== '' && $path !== '/')) {
                $problems[] = 'Site Address must be only the address, for example https://slo.example.edu. The folder SLO Cloud is installed in comes from Base URL Path.';
            } elseif (!self::isSecureUrl($siteUrl)) {
                $problems[] = 'Site Address must be an https:// address.';
            }
        }

        foreach ([
            'idp_sso_url' => 'Login URL (IdP SSO URL)',
            'idp_slo_url' => 'Logout URL (IdP SLO URL)',
            'sp_acs_url' => 'Reply URL (Assertion Consumer Service URL) override',
            'sp_slo_url' => 'Logout Url override',
        ] as $key => $label) {
            $url = (string)($c[$key] ?? '');
            if ($url !== '' && !self::isSecureUrl($url)) {
                $problems[] = "{$label} must be an https:// URL.";
            }
        }

        foreach (['idp_cert' => 'Certificate (Base64): IdP signing certificate', 'idp_cert_2' => 'Certificate (Base64): rollover'] as $key => $label) {
            $cert = (string)($c[$key] ?? '');
            if ($cert !== '' && @openssl_x509_read(SamlUtils::formatCert($cert, true)) === false) {
                $problems[] = "{$label} is not a valid X.509 certificate.";
            }
        }

        if (!empty($c['auto_provision']) && self::provisionDomains($c) === []) {
            $problems[] = 'Auto-Provision Email Domains is empty. List the email domains that may get an account, for example example.edu.';
        }

        $needsSpKey = !empty($c['sign_requests']) || !empty($c['require_encrypted_assertions']);
        $spCert = (string)($c['sp_cert'] ?? '');
        $keyFile = (string)($c['sp_private_key_file'] ?? '');
        if ($needsSpKey || $spCert !== '' || $keyFile !== '') {
            $privateKey = self::spPrivateKey($c);
            if ($spCert === '' || @openssl_x509_read(SamlUtils::formatCert($spCert, true)) === false) {
                $problems[] = 'SP Certificate is missing or not a valid X.509 certificate.';
            } elseif ($privateKey === '') {
                $problems[] = 'SP Private Key File is empty or cannot be read.';
            } elseif (!openssl_x509_check_private_key(SamlUtils::formatCert($spCert, true), $privateKey)) {
                $problems[] = 'SP Private Key File does not match the SP Certificate.';
            }
        }

        return $problems;
    }

    /**
     * The email domains allowed to get an account created at sign-in, lowercase.
     *
     * @return string[]
     */
    public static function provisionDomains(array $c): array
    {
        $domains = [];
        foreach (preg_split('/[\s,;]+/', strtolower((string)($c['auto_provision_domains'] ?? ''))) ?: [] as $domain) {
            $domain = ltrim($domain, '@');
            if (preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?\.[a-z]{2,}$/', $domain) === 1) {
                $domains[] = $domain;
            }
        }
        return array_values(array_unique($domains));
    }

    /** True when auto-provisioning is on and the email is at one of the listed domains (subdomains do not count). */
    public static function mayProvision(array $c, string $email): bool
    {
        if (empty($c['auto_provision'])) {
            return false;
        }
        $at = strrpos($email, '@');
        return $at !== false && in_array(strtolower(substr($email, $at + 1)), self::provisionDomains($c), true);
    }

    /** Settings for onelogin/php-saml. $slo adds the logout endpoint and requires signed logout messages. */
    public static function settings(array $c, bool $slo = false): array
    {
        $spCert = (string)($c['sp_cert'] ?? '');
        $privateKey = self::spPrivateKey($c);
        $canSign = $spCert !== '' && $privateKey !== '';
        $signing = array_values(array_filter([(string)($c['idp_cert'] ?? ''), (string)($c['idp_cert_2'] ?? '')], 'strlen'));

        $sp = [
            'entityId' => (string)($c['sp_entity_id'] ?? ''),
            'assertionConsumerService' => [
                'url' => (string)($c['sp_acs_url'] ?? ''),
                'binding' => self::BINDING_POST,
            ],
            'NameIDFormat' => self::NAMEID_UNSPECIFIED,
        ];
        if (self::sloEnabled($c)) {
            $sp['singleLogoutService'] = ['url' => (string)$c['sp_slo_url'], 'binding' => self::BINDING_REDIRECT];
        }
        if ($canSign) {
            $sp['x509cert'] = $spCert;
            $sp['privateKey'] = $privateKey;
        }

        $idp = [
            'entityId' => (string)($c['idp_entity_id'] ?? ''),
            'singleSignOnService' => ['url' => (string)($c['idp_sso_url'] ?? ''), 'binding' => self::BINDING_REDIRECT],
            'x509certMulti' => ['signing' => $signing],
        ];
        if (self::sloEnabled($c)) {
            $idp['singleLogoutService'] = ['url' => (string)$c['idp_slo_url'], 'binding' => self::BINDING_REDIRECT];
        }

        $signRequests = !empty($c['sign_requests']) && $canSign;

        return [
            'strict' => true,
            'debug' => false,
            'sp' => $sp,
            'idp' => $idp,
            'security' => [
                'nameIdEncrypted' => false,
                'authnRequestsSigned' => $signRequests,
                'logoutRequestSigned' => $signRequests,
                'logoutResponseSigned' => $signRequests,
                'signMetadata' => false,
                'wantMessagesSigned' => $slo || !empty($c['require_signed_response']),
                'wantAssertionsSigned' => true,
                'wantAssertionsEncrypted' => !empty($c['require_encrypted_assertions']),
                'wantNameId' => true,
                'wantNameIdEncrypted' => false,
                'requestedAuthnContext' => false,
                'wantXMLValidation' => true,
                'relaxDestinationValidation' => false,
                'destinationStrictlyMatches' => true,
                'allowRepeatAttributeName' => false,
                'rejectUnsolicitedResponsesWithInResponseTo' => true,
                'signatureAlgorithm' => self::SIGNATURE_ALGORITHM,
                'digestAlgorithm' => self::DIGEST_ALGORITHM,
                'lowercaseUrlencoding' => false,
            ],
        ];
    }

    public static function auth(array $c, bool $slo = false): Auth
    {
        return new Auth(self::settings($c, $slo));
    }

    /**
     * Start a sign-in.
     *
     * @return array{url: string, request_id: string} Send the browser to url and keep request_id in the session.
     */
    public static function beginLogin(array $c): array
    {
        $auth = self::auth($c);
        $url = $auth->login(self::RELAY_STATE, [], !empty($c['force_authn']), false, true, false);
        return ['url' => (string)$url, 'request_id' => (string)$auth->getLastRequestID()];
    }

    /**
     * Validate the SAMLResponse in $_POST against the AuthnRequest this
     * application sent ($requestId, already taken from the pending store),
     * and return who signed in.
     *
     * @return array{email: string, name: string, name_id: string, name_id_format: string, session_index: string, assertion_id: string}
     * @throws RuntimeException With the reason (for the security log; never show it to the user).
     */
    public static function validateResponse(array $c, string $requestId): array
    {
        if ($requestId === '') {
            throw new RuntimeException('No sign-in was started from this browser, or its request was already used');
        }

        $auth = self::auth($c);
        try {
            $auth->processResponse($requestId);
        } catch (\Throwable $e) {
            throw new SamlSignInError(
                'Response could not be processed: ' . $e->getMessage(),
                'saml_invalid_response',
                'The response from the identity provider could not be read as a SAML response.'
            );
        }
        if ($auth->getErrors() !== [] || !$auth->isAuthenticated()) {
            $exception = $auth->getLastErrorException();
            throw self::explainFailure(
                $exception instanceof ValidationError ? $exception->getCode() : -1,
                html_entity_decode((string)($auth->getLastErrorReason() ?? implode(',', $auth->getErrors())), ENT_QUOTES),
                $c
            );
        }

        self::refuseWeakAlgorithms((string)$auth->getLastResponseXML());

        $attributes = $auth->getAttributes();
        $email = '';
        $emailAttribute = (string)($c['attr_email'] ?? '');
        if ($emailAttribute !== '') {
            $email = self::attributeValue($attributes, $emailAttribute);
            // 'email' was the template default before 5.2.9; Microsoft Entra ID sends emailaddress.
            if ($email === '' && $emailAttribute === 'email') {
                $email = self::attributeValue($attributes, 'emailaddress');
            }
        }
        // The NameID stands in for the email only when no attribute is configured. A configured
        // attribute that is missing is a setting error, reported below rather than worked around.
        if ($emailAttribute === '') {
            $nameId = trim((string)$auth->getNameId());
            if (filter_var($nameId, FILTER_VALIDATE_EMAIL) !== false) {
                $email = $nameId;
            }
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new SamlSignInError(
                'Response has no usable email address',
                'saml_no_email',
                'The identity provider signed the user in but sent no usable email address, and SLO Cloud signs people in by email. '
                . 'Make sure the user has an email in the identity provider and that Email Attribute Name is one of the attributes listed below.',
                [
                    'Email Attribute Name (SLO Cloud setting)' => $emailAttribute !== '' ? $emailAttribute : '(blank: NameID is used)',
                    'Attributes in the response' => $attributes === [] ? '(none)' : implode(', ', array_keys($attributes)),
                    'NameID in the response' => (string)$auth->getNameId() !== '' ? (string)$auth->getNameId() : '(none)',
                ]
            );
        }

        $name = '';
        $nameAttribute = (string)($c['attr_name'] ?? '');
        if ($nameAttribute !== '') {
            $name = self::attributeValue($attributes, $nameAttribute);
        }

        return [
            'email' => $email,
            'name' => $name,
            'name_id' => (string)$auth->getNameId(),
            'name_id_format' => (string)$auth->getNameIdFormat(),
            'session_index' => (string)$auth->getSessionIndex(),
            'assertion_id' => (string)$auth->getLastAssertionId(),
        ];
    }

    /**
     * First value of an attribute. A name without a namespace, as Microsoft Entra ID
     * shows claims (emailaddress, displayname), also matches the one attribute whose
     * full name ends in it, for example .../identity/claims/emailaddress. Case is
     * ignored for that match; if several attributes end in the name, none is used.
     */
    public static function attributeValue(array $attributes, string $name): string
    {
        if (isset($attributes[$name])) {
            return trim((string)($attributes[$name][0] ?? ''));
        }
        if ($name === '' || strpbrk($name, '/:') !== false) {
            return '';
        }
        $matches = [];
        foreach ($attributes as $key => $values) {
            $short = substr((string)$key, (int)strrpos('/' . $key, '/'));
            if (strcasecmp($short, $name) === 0) {
                $matches[] = $values;
            }
        }
        return count($matches) === 1 ? trim((string)($matches[0][0] ?? '')) : '';
    }

    /**
     * The URL that signs the user out at the IdP.
     *
     * @return array{url: string, request_id: string}
     */
    public static function beginLogout(array $c, string $nameId, string $nameIdFormat, string $sessionIndex): array
    {
        $auth = self::auth($c, true);
        $url = $auth->logout(
            self::RELAY_STATE,
            [],
            $nameId !== '' ? $nameId : null,
            $sessionIndex !== '' ? $sessionIndex : null,
            true,
            $nameIdFormat !== '' ? $nameIdFormat : null
        );
        return ['url' => (string)$url, 'request_id' => (string)$auth->getLastRequestID()];
    }

    /**
     * Handle a LogoutResponse (the IdP answering our logout) or a LogoutRequest
     * (the IdP ending the session because the user signed out elsewhere) from
     * $_GET. Both must be signed by the IdP.
     *
     * @param callable $destroySession Called to end the local session for a LogoutRequest.
     * @return array{kind: string, redirect: ?string, error: ?string}
     */
    public static function handleLogout(array $c, ?string $requestId, callable $destroySession): array
    {
        $kind = isset($_GET['SAMLRequest']) ? 'request' : 'response';
        $auth = self::auth($c, true);
        try {
            $redirect = $auth->processSLO($kind === 'response', $requestId, true, $destroySession, true);
        } catch (\Throwable $e) {
            return ['kind' => $kind, 'redirect' => null, 'error' => 'Logout message could not be processed: ' . $e->getMessage()];
        }
        if ($auth->getErrors() !== []) {
            return ['kind' => $kind, 'redirect' => null, 'error' => (string)($auth->getLastErrorReason() ?? implode(',', $auth->getErrors()))];
        }
        return ['kind' => $kind, 'redirect' => $redirect === null ? null : (string)$redirect, 'error' => null];
    }

    /** SP metadata XML for the identity provider administrator. Needs only the SP settings. */
    public static function metadataXml(array $c): string
    {
        $settings = new Settings(self::settings($c), true);
        $metadata = $settings->getSPMetadata();
        $errors = $settings->validateMetadata($metadata);
        if ($errors !== []) {
            throw new RuntimeException('SP metadata is not valid: ' . implode(', ', $errors));
        }
        return $metadata;
    }

    /**
     * Folder for the requests waiting for an answer. The session cannot hold
     * them: its cookie is SameSite=Strict, so the browser does not send it on
     * the IdP's cross-site POST back to acs.php.
     */
    public static function pendingDirectory(): string
    {
        return Security::dataRoot(dirname(__DIR__, 2)) . '/var/saml';
    }

    /** Remember a request this application just sent, so one answer to it is accepted. */
    public static function rememberRequest(string $kind, string $requestId, string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the SAML request folder.');
        }
        $now = time();
        foreach (glob($directory . '/*.pending') ?: [] as $file) {
            if ($now - (int)@filemtime($file) > self::REQUEST_LIFETIME) {
                @unlink($file);
            }
        }
        if (file_put_contents(self::pendingFile($kind, $requestId, $directory), (string)$now, LOCK_EX) === false) {
            throw new RuntimeException('Unable to record the SAML request.');
        }
    }

    /**
     * Use up the request an answer says it is replying to. True once per
     * request, and only while it is recent.
     */
    public static function takeRequest(string $kind, string $requestId, string $directory): bool
    {
        if (preg_match('/^[A-Za-z0-9_.\-]{8,128}$/', $requestId) !== 1) {
            return false;
        }
        $file = self::pendingFile($kind, $requestId, $directory);
        $recorded = is_file($file) ? (int)@filemtime($file) : 0;
        // unlink succeeds for only one of two simultaneous answers.
        if ($recorded === 0 || !@unlink($file)) {
            return false;
        }
        return time() - $recorded <= self::REQUEST_LIFETIME;
    }

    /**
     * The InResponseTo of an unvalidated SAML message (read only to find the
     * stored request; the library validates the message itself).
     */
    public static function inResponseTo(string $encoded, bool $deflated): string
    {
        $xml = base64_decode($encoded, true);
        if ($xml === false) {
            return '';
        }
        if ($deflated) {
            $xml = @gzinflate($xml);
            if ($xml === false) {
                return '';
            }
        }
        $document = new DOMDocument();
        if (!@$document->loadXML($xml, LIBXML_NONET) || $document->documentElement === null) {
            return '';
        }
        return $document->documentElement->getAttribute('InResponseTo');
    }

    private static function pendingFile(string $kind, string $requestId, string $directory): string
    {
        return rtrim($directory, '/\\') . '/' . $kind . '-' . hash('sha256', $requestId) . '.pending';
    }

    /**
     * Turn a library validation failure into what the person signing in (and
     * the administrator behind them) can act on. $message is the library's
     * own wording, used only to pull out the values it compared.
     */
    private static function explainFailure(int $code, string $message, array $c): SamlSignInError
    {
        $between = static function (string $pattern) use ($message): string {
            return preg_match($pattern, $message, $m) === 1 ? $m[1] : '';
        };
        $technical = 'Response is not valid: ' . $message;
        $error = static fn(string $reason, string $explanation, array $details = []): SamlSignInError
            => new SamlSignInError($technical, $reason, $explanation, $details);

        switch ($code) {
            case ValidationError::STATUS_CODE_IS_NOT_SUCCESS:
            case ValidationError::MISSING_STATUS:
            case ValidationError::MISSING_STATUS_CODE:
                return $error('saml_idp_error', 'The identity provider answered that the sign-in did not succeed. The user may have cancelled, or the provider refused the request; its own error page usually says why.', ['Identity provider status' => $message]);

            case ValidationError::NO_SIGNED_MESSAGE:
                return $error('saml_unsigned', 'Require Signed Response is on in SLO Cloud, but the identity provider signed only the assertion. Turn the setting off, or set the provider to sign the response too (Microsoft Entra ID: Signing Option = Sign SAML response and assertion).');
            case ValidationError::NO_SIGNED_ASSERTION:
            case ValidationError::NO_SIGNATURE_FOUND:
            case ValidationError::WRONG_NUMBER_OF_SIGNATURES_IN_ASSERTION:
            case ValidationError::WRONG_NUMBER_OF_SIGNATURES_IN_RESPONSE:
            case ValidationError::WRONG_NUMBER_OF_SIGNATURES:
                return $error('saml_unsigned', 'The assertion is not signed. SLO Cloud only accepts signed assertions. Microsoft Entra ID: Single sign-on, SAML Certificates, Signing Option = Sign SAML assertion (or Sign SAML response and assertion).');

            case ValidationError::INVALID_SIGNATURE:
                return $error('saml_bad_signature', 'The signature could not be verified with the Certificate (Base64) saved in SLO Cloud. Either that is not the certificate the identity provider signs with (it may have been replaced; download the current Certificate (Base64) and paste it into the IdP signing certificate box, or into the rollover box), or the response was altered on the way.');

            case ValidationError::WRONG_NUMBER_OF_ASSERTIONS:
            case ValidationError::WRONG_SIGNED_ELEMENT:
            case ValidationError::ID_NOT_FOUND_IN_SIGNED_ELEMENT:
            case ValidationError::DUPLICATED_ID_IN_SIGNED_ELEMENTS:
            case ValidationError::INVALID_SIGNED_ELEMENT:
            case ValidationError::DUPLICATED_REFERENCE_IN_SIGNED_ELEMENTS:
            case ValidationError::UNEXPECTED_SIGNED_ELEMENTS:
            case ValidationError::UNEXPECTED_REFERENCE:
                return $error('saml_bad_structure', 'The signature does not cover the one assertion SLO Cloud would read, or the response holds more than one assertion. A normal identity provider does not send this; the response may have been tampered with.');

            case ValidationError::WRONG_AUDIENCE:
                return $error('saml_wrong_audience', 'The response is addressed to a different application than this SLO Cloud. The Identifier (Entity ID) at the identity provider must equal the Identifier (Entity ID) SLO Cloud lists, character for character.', [
                    'Identifier (Entity ID) in SLO Cloud' => (string)($c['sp_entity_id'] ?? ''),
                    'Audience in the response' => $between("/got '(.*)'\\)?$/s"),
                ]);
            case ValidationError::WRONG_ISSUER:
            case ValidationError::ISSUER_NOT_FOUND_IN_ASSERTION:
            case ValidationError::ISSUER_MULTIPLE_IN_RESPONSE:
                return $error('saml_wrong_issuer', 'The response comes from a different identity provider than the one configured. Microsoft Entra Identifier (IdP Entity ID) in SLO Cloud must equal the identity provider\'s identifier exactly (in Microsoft Entra ID, the Microsoft Entra Identifier, including the trailing slash and the right tenant).', [
                    'Microsoft Entra Identifier (IdP Entity ID) in SLO Cloud' => (string)($c['idp_entity_id'] ?? ''),
                    'Issuer in the response' => $between("/got '(.*)'\\)?$/s"),
                ]);
            case ValidationError::WRONG_DESTINATION:
            case ValidationError::EMPTY_DESTINATION:
                return $error('saml_wrong_destination', 'The response was addressed to a different address than the one it arrived at. The Reply URL (Assertion Consumer Service URL) at the identity provider must equal the one SLO Cloud lists, and people must reach SLO Cloud by that same host name.', [
                    'Reply URL (Assertion Consumer Service URL) in SLO Cloud' => (string)($c['sp_acs_url'] ?? ''),
                    'Addressed to' => $between('/instead of (.*)$/s'),
                    'Arrived at' => $between('/received at (.*) instead of/s'),
                ]);
            case ValidationError::WRONG_SUBJECTCONFIRMATION:
                return $error('saml_wrong_destination', 'The assertion is not confirmed for this address or has already expired. Most often the Reply URL (Assertion Consumer Service URL) at the identity provider differs from the one SLO Cloud lists, or the server clock is wrong.', [
                    'Reply URL (Assertion Consumer Service URL) in SLO Cloud' => (string)($c['sp_acs_url'] ?? ''),
                    'Server time (UTC)' => gmdate('Y-m-d H:i:s'),
                ]);

            case ValidationError::ASSERTION_TOO_EARLY:
            case ValidationError::ASSERTION_EXPIRED:
            case ValidationError::RESPONSE_EXPIRED:
            case ValidationError::SESSION_EXPIRED:
                return $error('saml_clock', 'The response is outside its validity period. This usually means the SLO Cloud server clock is wrong (more than a minute off), or the sign-in sat open too long. Check time synchronization on the web server.', ['Server time (UTC)' => gmdate('Y-m-d H:i:s')]);

            case ValidationError::WRONG_INRESPONSETO:
                return $error('saml_wrong_request', 'The response answers a different sign-in than the one SLO Cloud started. Start again from the login page.');

            case ValidationError::NO_ENCRYPTED_ASSERTION:
            case ValidationError::NO_ENCRYPTED_NAMEID:
            case ValidationError::MISSING_ENCRYPTED_ELEMENT:
            case ValidationError::KEYINFO_NOT_FOUND_IN_ENCRYPTED_DATA:
            case ValidationError::CHILDREN_NODE_NOT_FOUND_IN_KEYINFO:
            case ValidationError::ENCRYPTED_ATTRIBUTES:
                return $error('saml_encryption', 'Require Encrypted Assertions is on in SLO Cloud, but the response is not encrypted to this installation\'s SP Certificate as expected. Upload the SP Certificate to the identity provider and turn its encryption on, or turn the SLO Cloud setting off.');

            case ValidationError::KEY_ALGORITHM_ERROR:
            case ValidationError::NOT_SUPPORTED:
                return $error('saml_bad_signature', 'The response uses a signing or encryption algorithm SLO Cloud does not support. Use RSA with SHA-256.', ['Library message' => $message]);

            case ValidationError::INVALID_XML_FORMAT:
            case ValidationError::UNSUPPORTED_SAML_VERSION:
            case ValidationError::MISSING_ID:
                return $error('saml_malformed', 'The response is not a well-formed SAML 2.0 response.');

            case ValidationError::MISSING_CONDITIONS:
            case ValidationError::NO_ATTRIBUTESTATEMENT:
            case ValidationError::WRONG_NUMBER_OF_AUTHSTATEMENTS:
            case ValidationError::NO_NAMEID:
            case ValidationError::EMPTY_NAMEID:
            case ValidationError::DUPLICATED_ATTRIBUTE_NAME_FOUND:
                return $error('saml_malformed', 'The response is missing a part SLO Cloud requires (conditions, authentication statement, attributes or NameID), or repeats an attribute.', ['Library message' => $message]);
        }

        return $error('saml_invalid_response', 'The response from the identity provider failed validation.', $message !== '' ? ['Library message' => $message] : []);
    }

    /** Refuse a Response signed or digested with SHA-1. */
    private static function refuseWeakAlgorithms(string $xml): void
    {
        $document = new DOMDocument();
        if ($xml === '' || !@$document->loadXML($xml, LIBXML_NONET)) {
            throw new RuntimeException('Validated response could not be re-read');
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('ds', self::NS_DS);
        foreach ($xpath->query('//ds:SignatureMethod/@Algorithm | //ds:DigestMethod/@Algorithm') ?: [] as $algorithm) {
            if (in_array($algorithm->nodeValue, self::WEAK_ALGORITHMS, true)) {
                throw new SamlSignInError(
                    'Response uses a SHA-1 signature or digest (' . $algorithm->nodeValue . ')',
                    'saml_weak_algorithm',
                    'The identity provider signed the response with SHA-1, which SLO Cloud refuses. Set its signing algorithm to SHA-256 (Microsoft Entra ID: Single sign-on, SAML Certificates, Signing Algorithm).',
                    ['Algorithm used' => (string)$algorithm->nodeValue]
                );
            }
        }
    }

    private static function spPrivateKey(array $c): string
    {
        $file = (string)($c['sp_private_key_file'] ?? '');
        if ($file === '' || !is_file($file) || !is_readable($file)) {
            return '';
        }
        $contents = file_get_contents($file);
        return $contents === false ? '' : trim($contents);
    }

    /** https, or http only to this machine for development. */
    private static function isSecureUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return false;
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if ($scheme === 'https') {
            return true;
        }
        return $scheme === 'http' && in_array(strtolower($parts['host']), ['localhost', '127.0.0.1', '[::1]'], true);
    }
}
