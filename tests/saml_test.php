<?php
declare(strict_types=1);

// SAML single sign-on: builds signed responses with a throwaway key and checks
// what Core\Saml accepts and refuses, including the attacks that matter
// (forged, wrapped, tampered, replayed, expired, wrong audience or issuer,
// SHA-1), plus configuration checks, SP metadata, the pending-request store and
// the route map.
//
//   php tests/saml_test.php

require_once __DIR__ . '/../src/system/Core/Security.php';
require_once __DIR__ . '/../src/system/Core/Authorization.php';
require_once __DIR__ . '/../src/system/Core/Config.php';
require_once __DIR__ . '/../src/system/Core/Saml.php';

use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use SLOCloud\Core\Authorization;
use SLOCloud\Core\Saml;
use SLOCloud\Core\SamlSignInError;

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
$refuses = static function (callable $run, string $expected = ''): ?string {
    try {
        $run();
    } catch (Throwable $e) {
        return $expected === '' || stripos($e->getMessage(), $expected) !== false
            ? null
            : 'refused for another reason: ' . $e->getMessage();
    }
    return 'was accepted';
};

const ACS = 'https://slo.example.edu/saml/acs.php';
const SP_ENTITY = 'https://slo.example.edu/saml/metadata.php';
const IDP_ENTITY = 'https://sts.windows.net/11111111-2222-3333-4444-555555555555/';
const IDP_SSO = 'https://login.microsoftonline.com/11111111-2222-3333-4444-555555555555/saml2';
const EMAIL_CLAIM = 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress';

/** A throwaway RSA key and self-signed certificate. */
$makeIdentity = static function (string $cn): array {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => $cn], $key);
    $cert = openssl_csr_sign($csr, null, $key, 1);
    openssl_pkey_export($key, $privatePem);
    openssl_x509_export($cert, $certPem);
    return ['private' => $privatePem, 'cert' => $certPem];
};
$idp = $makeIdentity('idp');
$other = $makeIdentity('attacker');
$idpNext = $makeIdentity('idp-next');
$sp = $makeIdentity('slo.example.edu');

$config = [
    'enabled' => true,
    'site_url' => '',
    'idp_entity_id' => IDP_ENTITY,
    'idp_sso_url' => IDP_SSO,
    'idp_slo_url' => '',
    'idp_cert' => $idp['cert'],
    'idp_cert_2' => '',
    'sp_entity_id' => SP_ENTITY,
    'sp_acs_url' => ACS,
    'sp_slo_url' => '',
    'sp_cert' => '',
    'sp_private_key_file' => '',
    'attr_email' => EMAIL_CLAIM,
    'attr_name' => 'http://schemas.microsoft.com/identity/claims/displayname',
    'sign_requests' => false,
    'require_encrypted_assertions' => false,
    'require_signed_response' => false,
    'force_authn' => false,
    'auto_provision' => false,
    'auto_provision_domains' => '',
];

// The library compares Destination with the URL the request arrived at.
$_SERVER['HTTP_HOST'] = 'slo.example.edu';
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = '443';
$_SERVER['SCRIPT_NAME'] = '/saml/acs.php';
$_SERVER['REQUEST_URI'] = '/saml/acs.php';

const NS_P = 'urn:oasis:names:tc:SAML:2.0:protocol';
const NS_A = 'urn:oasis:names:tc:SAML:2.0:assertion';

/** Sign the element with the given ID in place; the signature follows the Issuer. */
$sign = static function (DOMDocument $doc, string $elementId, array $identity, string $algorithm = XMLSecurityKey::RSA_SHA256, string $digest = XMLSecurityDSig::SHA256): void {
    $xpath = new DOMXPath($doc);
    $element = $xpath->query("//*[@ID='{$elementId}']")->item(0);
    $dsig = new XMLSecurityDSig();
    $dsig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);
    $dsig->addReference(
        $element,
        $digest,
        ['http://www.w3.org/2000/09/xmldsig#enveloped-signature', XMLSecurityDSig::EXC_C14N],
        ['id_name' => 'ID', 'overwrite' => false]
    );
    $key = new XMLSecurityKey($algorithm, ['type' => 'private']);
    $key->loadKey($identity['private'], false);
    $dsig->sign($key);
    $dsig->add509Cert($identity['cert'], true);
    $issuer = $xpath->query('./*[local-name()="Issuer"]', $element)->item(0);
    $dsig->insertSignature($element, $issuer->nextSibling);
};

/** A Response in the shape Microsoft Entra ID sends, then signed per $o. */
$buildResponse = static function (array $o = []) use ($sign, $idp): string {
    $o += [
        'request_id' => '_req0123456789abcdef',
        'email' => 'faculty@example.edu',
        'name' => 'Pat Faculty',
        'issuer' => IDP_ENTITY,
        'audience' => SP_ENTITY,
        'destination' => ACS,
        'recipient' => ACS,
        'not_before' => time() - 60,
        'not_on_or_after' => time() + 300,
        'sign_assertion' => true,
        'sign_response' => false,
        'signer' => $idp,
        'algorithm' => XMLSecurityKey::RSA_SHA256,
        'digest' => XMLSecurityDSig::SHA256,
        'email_attribute' => true,
    ];
    $instant = static fn(int $t): string => gmdate('Y-m-d\TH:i:s\Z', $t);
    $emailAttr = $o['email_attribute']
        ? '<saml:Attribute Name="' . EMAIL_CLAIM . '"><saml:AttributeValue>' . htmlspecialchars($o['email']) . '</saml:AttributeValue></saml:Attribute>'
        : '';
    $xml = '<samlp:Response xmlns:samlp="' . NS_P . '" xmlns:saml="' . NS_A . '" ID="_response1" Version="2.0"'
        . ' IssueInstant="' . $instant(time()) . '" Destination="' . $o['destination'] . '" InResponseTo="' . $o['request_id'] . '">'
        . '<saml:Issuer>' . $o['issuer'] . '</saml:Issuer>'
        . '<samlp:Status><samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/></samlp:Status>'
        . '<saml:Assertion xmlns:saml="' . NS_A . '" ID="_assertion1" Version="2.0" IssueInstant="' . $instant(time()) . '">'
        . '<saml:Issuer>' . $o['issuer'] . '</saml:Issuer>'
        . '<saml:Subject><saml:NameID Format="urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress">' . htmlspecialchars($o['email']) . '</saml:NameID>'
        . '<saml:SubjectConfirmation Method="urn:oasis:names:tc:SAML:2.0:cm:bearer"><saml:SubjectConfirmationData'
        . ' InResponseTo="' . $o['request_id'] . '" NotOnOrAfter="' . $instant($o['not_on_or_after']) . '" Recipient="' . $o['recipient'] . '"/>'
        . '</saml:SubjectConfirmation></saml:Subject>'
        . '<saml:Conditions NotBefore="' . $instant($o['not_before']) . '" NotOnOrAfter="' . $instant($o['not_on_or_after']) . '">'
        . '<saml:AudienceRestriction><saml:Audience>' . $o['audience'] . '</saml:Audience></saml:AudienceRestriction></saml:Conditions>'
        . '<saml:AuthnStatement AuthnInstant="' . $instant(time()) . '" SessionIndex="_session1"><saml:AuthnContext>'
        . '<saml:AuthnContextClassRef>urn:oasis:names:tc:SAML:2.0:ac:classes:Password</saml:AuthnContextClassRef></saml:AuthnContext></saml:AuthnStatement>'
        . '<saml:AttributeStatement>' . $emailAttr
        . '<saml:Attribute Name="http://schemas.microsoft.com/identity/claims/displayname"><saml:AttributeValue>' . htmlspecialchars($o['name']) . '</saml:AttributeValue></saml:Attribute>'
        . '</saml:AttributeStatement>'
        . '</saml:Assertion></samlp:Response>';

    $doc = new DOMDocument();
    $doc->preserveWhiteSpace = true;
    $doc->loadXML($xml);
    if ($o['sign_assertion']) {
        $sign($doc, '_assertion1', $o['signer'], $o['algorithm'], $o['digest']);
    }
    if ($o['sign_response']) {
        $sign($doc, '_response1', $o['signer'], $o['algorithm'], $o['digest']);
    }
    return $doc->saveXML();
};

/** Present $xml as the IdP's POST and validate it for $requestId. */
$validate = static function (string $xml, ?string $requestId = '_req0123456789abcdef', ?array $overrides = null) use (&$config): array {
    $_POST = ['SAMLResponse' => base64_encode($xml)];
    return Saml::validateResponse($overrides ?? $config, $requestId);
};

// --- a good response ---------------------------------------------------------
try {
    $identity = $validate($buildResponse());
    $check($identity['email'] === 'faculty@example.edu', 'valid response: wrong email ' . $identity['email']);
    $check($identity['name'] === 'Pat Faculty', 'valid response: wrong name');
    $check($identity['session_index'] === '_session1', 'valid response: session index not returned');
} catch (Throwable $e) {
    $check(false, 'a correctly signed response was refused: ' . $e->getMessage());
}

try {
    $identity = $validate($buildResponse(['sign_assertion' => true, 'sign_response' => true]), '_req0123456789abcdef', $config + ['require_signed_response' => true]);
    $check($identity['email'] === 'faculty@example.edu', 'response+assertion signed: wrong email');
} catch (Throwable $e) {
    $check(false, 'a response with both signatures was refused: ' . $e->getMessage());
}

// Email Attribute Name blank: the NameID (an email here) is used.
try {
    $identity = $validate($buildResponse(['email_attribute' => false]), '_req0123456789abcdef', ['attr_email' => ''] + $config);
    $check($identity['email'] === 'faculty@example.edu', 'NameID with a blank attribute name: wrong email');
} catch (Throwable $e) {
    $check(false, 'NameID with a blank attribute name failed: ' . $e->getMessage());
}

// Email Attribute Name set but the attribute is missing: refused, even though the NameID is an email.
try {
    $validate($buildResponse(['email_attribute' => false]));
    $check(false, 'a missing configured email attribute fell back to the NameID');
} catch (SamlSignInError $e) {
    $check($e->reason === 'saml_no_email', 'missing configured email attribute: got ' . $e->reason);
}

// The pre-5.2.9 default 'email' still finds Entra's email claim.
try {
    $identity = $validate($buildResponse(), '_req0123456789abcdef', ['attr_email' => 'email'] + $config);
    $check($identity['email'] === 'faculty@example.edu', 'old email default: wrong email');
} catch (Throwable $e) {
    $check(false, 'old email default did not find the emailaddress claim: ' . $e->getMessage());
}

// Entra's short claim names (as its Attributes & Claims page shows them) match the full names.
try {
    $identity = $validate($buildResponse(), '_req0123456789abcdef', ['attr_email' => 'emailaddress', 'attr_name' => 'DisplayName'] + $config);
    $check($identity['email'] === 'faculty@example.edu', 'short claim name: wrong email');
    $check($identity['name'] === 'Pat Faculty', 'short claim name: displayname not matched');
} catch (Throwable $e) {
    $check(false, 'short claim names were refused: ' . $e->getMessage());
}

$claims = [
    EMAIL_CLAIM => ['entra@example.edu'],
    'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name' => ['upn@example.edu'],
    'urn:example:a/name' => ['other'],
    'email' => ['okta@example.edu'],
];
$check(Saml::attributeValue($claims, EMAIL_CLAIM) === 'entra@example.edu', 'attributeValue: exact full name');
$check(Saml::attributeValue($claims, 'emailaddress') === 'entra@example.edu', 'attributeValue: short name not matched');
$check(Saml::attributeValue($claims, 'email') === 'okta@example.edu', 'attributeValue: an exact name must win over a suffix match');
$check(Saml::attributeValue($claims, 'name') === '', 'attributeValue: a short name ending two attributes must match neither');
$check(Saml::attributeValue($claims, 'urn:other:emailaddress') === '', 'attributeValue: a namespaced name must match exactly');
$check(Saml::attributeValue($claims, 'surname') === '', 'attributeValue: a missing attribute must be empty');

// --- refused responses -------------------------------------------------------
$cases = [
    'unsigned response' => [$buildResponse(['sign_assertion' => false])],
    'signed by another key (forged)' => [$buildResponse(['signer' => $other])],
    'wrong audience' => [$buildResponse(['audience' => 'https://evil.example/'])],
    'wrong issuer' => [$buildResponse(['issuer' => 'https://sts.windows.net/other-tenant/'])],
    'expired' => [$buildResponse(['not_before' => time() - 900, 'not_on_or_after' => time() - 600])],
    'not yet valid' => [$buildResponse(['not_before' => time() + 900, 'not_on_or_after' => time() + 1200])],
    'wrong destination' => [$buildResponse(['destination' => 'https://evil.example/saml/acs.php'])],
    'wrong recipient' => [$buildResponse(['recipient' => 'https://evil.example/saml/acs.php'])],
    'answers a different request' => [$buildResponse(['request_id' => '_someoneelsesrequest1'])],
    'no email at all' => [$buildResponse(['email' => 'not-an-email', 'email_attribute' => false])],
];
foreach ($cases as $label => [$xml]) {
    $problem = $refuses(static fn() => $validate($xml));
    $check($problem === null, "{$label}: {$problem}");
}

// Each refusal tells the person what is wrong, with a specific reason code.
$reasonOf = static function (string $xml, ?string $requestId = '_req0123456789abcdef') use ($validate): ?SamlSignInError {
    try {
        $validate($xml, $requestId);
    } catch (SamlSignInError $e) {
        return $e;
    } catch (Throwable $e) {
        return null;
    }
    return null;
};
foreach ([
    'saml_unsigned' => $buildResponse(['sign_assertion' => false]),
    'saml_bad_signature' => $buildResponse(['signer' => $other]),
    'saml_wrong_audience' => $buildResponse(['audience' => 'https://evil.example/']),
    'saml_wrong_issuer' => $buildResponse(['issuer' => 'https://sts.windows.net/other-tenant/']),
    'saml_clock' => $buildResponse(['not_before' => time() - 900, 'not_on_or_after' => time() - 600]),
    'saml_wrong_destination' => $buildResponse(['destination' => 'https://evil.example/saml/acs.php']),
    'saml_wrong_request' => $buildResponse(['request_id' => '_someoneelsesrequest1']),
    'saml_no_email' => $buildResponse(['email' => 'not-an-email', 'email_attribute' => false]),
    'saml_weak_algorithm' => $buildResponse(['algorithm' => XMLSecurityKey::RSA_SHA1, 'digest' => XMLSecurityDSig::SHA1]),
] as $expectedReason => $xml) {
    $error = $reasonOf($xml);
    $check($error !== null && $error->reason === $expectedReason, "{$expectedReason}: got " . ($error?->reason ?? 'no SamlSignInError'));
    $check($error !== null && strlen($error->explanation) > 40, "{$expectedReason}: the explanation does not say what to do");
}
$audience = $reasonOf($buildResponse(['audience' => 'https://evil.example/']));
$check($audience !== null && ($audience->details['Identifier (Entity ID) in SLO Cloud'] ?? '') === SP_ENTITY && ($audience->details['Audience in the response'] ?? '') === 'https://evil.example/', 'audience error does not show expected and received values: ' . json_encode($audience?->details));
$issuer = $reasonOf($buildResponse(['issuer' => 'https://sts.windows.net/other-tenant/']));
$check($issuer !== null && ($issuer->details['Issuer in the response'] ?? '') === 'https://sts.windows.net/other-tenant/', 'issuer error does not show the received issuer: ' . json_encode($issuer?->details));
$destination = $reasonOf($buildResponse(['destination' => 'https://evil.example/saml/acs.php']));
$check($destination !== null && ($destination->details['Addressed to'] ?? '') === 'https://evil.example/saml/acs.php', 'destination error does not show where the response was addressed: ' . json_encode($destination?->details));
$noEmail = $reasonOf($buildResponse(['email' => 'not-an-email', 'email_attribute' => false]));
$check($noEmail !== null && str_contains($noEmail->details['Attributes in the response'] ?? '', 'displayname'), 'the no-email error does not list the attributes received: ' . json_encode($noEmail?->details));

// SHA-1 signature and digest (the library alone accepts them).
$problem = $refuses(
    static fn() => $validate($buildResponse(['algorithm' => XMLSecurityKey::RSA_SHA1, 'digest' => XMLSecurityDSig::SHA1])),
    'SHA-1'
);
$check($problem === null, "SHA-1 signed response: {$problem}");

// No sign-in was started here: a response nobody asked for cannot be accepted.
$problem = $refuses(static fn() => $validate($buildResponse(), ''));
$check($problem === null, "unsolicited response: {$problem}");

// Tampering after signing.
$tampered = str_replace('faculty@example.edu', 'admin@example.edu', $buildResponse());
$problem = $refuses(static fn() => $validate($tampered));
$check($problem === null, "tampered email: {$problem}");

// Signature wrapping: a forged assertion for the administrator placed first,
// the genuine signed one kept so a valid signature is still present.
$genuine = new DOMDocument();
$genuine->loadXML($buildResponse());
$forged = new DOMDocument();
$forged->loadXML($buildResponse(['email' => 'admin@example.edu', 'sign_assertion' => false]));
$forgedAssertion = $genuine->importNode($forged->getElementsByTagNameNS(NS_A, 'Assertion')->item(0), true);
$forgedAssertion->setAttribute('ID', '_forged1');
$response = $genuine->documentElement;
$response->insertBefore($forgedAssertion, $response->getElementsByTagNameNS(NS_A, 'Assertion')->item(0));
$problem = $refuses(static fn() => $validate($genuine->saveXML()));
$check($problem === null, "signature wrapping (forged assertion first): {$problem}");

// The same attack with the genuine assertion wrapped inside a forged Extensions-like container.
$genuine2 = new DOMDocument();
$genuine2->loadXML($buildResponse());
$wrapper = $genuine2->createElementNS(NS_P, 'samlp:Extensions');
$original = $genuine2->getElementsByTagNameNS(NS_A, 'Assertion')->item(0);
$forged2 = new DOMDocument();
$forged2->loadXML($buildResponse(['email' => 'admin@example.edu', 'sign_assertion' => false]));
$forgedAssertion2 = $genuine2->importNode($forged2->getElementsByTagNameNS(NS_A, 'Assertion')->item(0), true);
$forgedAssertion2->setAttribute('ID', '_forged2');
$genuine2->documentElement->replaceChild($forgedAssertion2, $original);
$wrapper->appendChild($original);
$genuine2->documentElement->appendChild($wrapper);
$problem = $refuses(static fn() => $validate($genuine2->saveXML()));
$check($problem === null, "signature wrapping (genuine assertion moved aside): {$problem}");

// --- certificate rollover ----------------------------------------------------
$rollover = $config + [];
$rollover['idp_cert_2'] = $idpNext['cert'];
try {
    $identity = $validate($buildResponse(['signer' => $idpNext]), '_req0123456789abcdef', $rollover);
    $check($identity['email'] === 'faculty@example.edu', 'rollover certificate: wrong email');
} catch (Throwable $e) {
    $check(false, 'a response signed with the rollover certificate was refused: ' . $e->getMessage());
}
$problem = $refuses(static fn() => $validate($buildResponse(['signer' => $idpNext])));
$check($problem === null, "next certificate accepted before it is configured: {$problem}");

// --- addresses built from the Site Address ------------------------------------
$urls = Saml::derivedUrls('https://slo.example.edu', '/');
$check($urls['acs_url'] === 'https://slo.example.edu/saml/acs.php' && $urls['entity_id'] === 'https://slo.example.edu/saml/metadata.php' && $urls['slo_url'] === 'https://slo.example.edu/saml/slo.php' && $urls['sso_url'] === 'https://slo.example.edu/saml/sso.php', 'derived URLs at the site root are wrong: ' . json_encode($urls));
$urls = Saml::derivedUrls('https://example.edu/', '/slo/');
$check($urls['acs_url'] === 'https://example.edu/slo/saml/acs.php', 'a trailing slash or a folder is not handled: ' . $urls['acs_url']);
$urls = Saml::derivedUrls('https://example.edu:8443', 'beta');
$check($urls['acs_url'] === 'https://example.edu:8443/beta/saml/acs.php', 'a port or a path without slashes is not handled: ' . $urls['acs_url']);
$check(Saml::derivedUrls('', '/')['acs_url'] === '', 'a blank Site Address produces URLs');

$configClass = new ReflectionClass(\SLOCloud\Core\Config::class);
$fromConfig = static function (array $data) use ($configClass): array {
    $instance = $configClass->newInstanceWithoutConstructor();
    $configClass->getProperty('config')->setValue($instance, $data);
    return Saml::configFrom($instance);
};
$built = $fromConfig(['app' => ['base_url' => '/slo/'], 'saml' => ['site_url' => 'https://example.edu', 'idp_slo_url' => 'https://idp.example/slo']]);
$check($built['sp_acs_url'] === 'https://example.edu/slo/saml/acs.php' && $built['sp_entity_id'] === 'https://example.edu/slo/saml/metadata.php', 'configFrom does not build the URLs from Site Address and Base URL Path');
$check(Saml::sloEnabled($built), 'single logout is not on when the IdP SLO URL is set and the Site Address is known');
$override = $fromConfig(['app' => ['base_url' => '/'], 'saml' => ['site_url' => 'https://example.edu', 'sp_acs_url' => 'https://gateway.example.edu/acs']]);
$check($override['sp_acs_url'] === 'https://gateway.example.edu/acs' && $override['sp_entity_id'] === 'https://example.edu/saml/metadata.php', 'an explicit ACS URL does not override the built one (or overrides the others too)');
$check($fromConfig(['saml' => ['sp_entity_id' => 'urn:legacy', 'sp_acs_url' => 'https://old.example.edu/saml/acs.php']])['sp_entity_id'] === 'urn:legacy', 'an existing install with explicit URLs and no Site Address loses them');
$blank = $fromConfig([]);
$check($blank['sp_acs_url'] === '' && !Saml::sloEnabled($blank), 'a blank configuration produces SP URLs or enables logout');

$check(Saml::problems(['site_url' => 'https://slo.example.edu'] + $config) === [], 'a valid Site Address reports problems: ' . implode(' | ', Saml::problems(['site_url' => 'https://slo.example.edu'] + $config)));
foreach (['http://slo.example.edu', 'https://slo.example.edu/slo/', 'https://slo.example.edu?x=1', 'slo.example.edu'] as $badSite) {
    $check(Saml::problems(['site_url' => $badSite] + $config) !== [], "Site Address '{$badSite}' is accepted");
}
$check(Saml::problems(['site_url' => 'http://localhost:8080'] + $config) === [], 'http://localhost is refused as a Site Address for local testing');
$noSite = Saml::problems(['sp_entity_id' => '', 'sp_acs_url' => ''] + $config);
$check(count($noSite) === 1 && str_contains($noSite[0], 'Site Address'), 'a missing Site Address is not reported as one clear problem: ' . implode(' | ', $noSite));

// --- configuration checks ----------------------------------------------------
$check(Saml::problems($config) === [], 'a complete configuration reports problems: ' . implode(' | ', Saml::problems($config)));
$check(count(Saml::problems(['enabled' => true])) >= 4, 'an empty configuration does not list what is missing');
$check(Saml::problems(['idp_sso_url' => 'http://login.example.edu/sso'] + $config) !== [], 'a plain http IdP URL is accepted');
$check(Saml::problems(['sp_acs_url' => 'http://localhost/saml/acs.php'] + $config) === [], 'http://localhost is refused for local testing');
$check(Saml::problems(['idp_cert' => 'not a certificate'] + $config) !== [], 'a garbage IdP certificate is accepted');
$check(Saml::problems(['sign_requests' => true] + $config) !== [], 'sign_requests without an SP certificate and key is accepted');

// Auto-provisioning is off unless asked for, and only for the listed domains.
$provision = ['auto_provision' => true, 'auto_provision_domains' => 'Example.edu, @staff.example.edu;bad domain,example.edu'] + $config;
$check(Saml::provisionDomains($provision) === ['example.edu', 'staff.example.edu'], 'domain list is not normalized: ' . implode(',', Saml::provisionDomains($provision)));
$check(!Saml::mayProvision($config, 'new@example.edu'), 'auto-provisioning is on by default');
$check(!Saml::mayProvision(['auto_provision_domains' => 'example.edu'] + $config, 'new@example.edu'), 'domains alone turn auto-provisioning on');
$check(Saml::mayProvision($provision, 'new@example.edu') && Saml::mayProvision($provision, 'NEW@Staff.Example.EDU'), 'a listed domain is refused');
$check(!Saml::mayProvision($provision, 'new@other.edu'), 'an unlisted domain is allowed');
$check(!Saml::mayProvision($provision, 'new@sub.example.edu'), 'a subdomain of a listed domain is allowed');
$check(!Saml::mayProvision($provision, 'new@example.edu.evil.com') && !Saml::mayProvision($provision, 'example.edu'), 'a look-alike address is allowed');
$check(Saml::problems($provision) === [], 'valid auto-provisioning reports problems: ' . implode(' | ', Saml::problems($provision)));
$check(Saml::problems(['auto_provision_domains' => ''] + $provision) !== [], 'auto-provisioning is accepted with no domains');

$keyFile = tempnam(sys_get_temp_dir(), 'slo-spkey-');
file_put_contents($keyFile, $sp['private']);
$withSp = ['sp_cert' => $sp['cert'], 'sp_private_key_file' => $keyFile, 'sign_requests' => true] + $config;
$check(Saml::problems($withSp) === [], 'a matching SP certificate and key report problems: ' . implode(' | ', Saml::problems($withSp)));
file_put_contents($keyFile, $other['private']);
$check(Saml::problems($withSp) !== [], 'an SP key that does not match the certificate is accepted');
file_put_contents($keyFile, $sp['private']);

$settings = Saml::settings($config);
$security = $settings['security'];
$check($settings['strict'] === true && $settings['debug'] === false, 'strict mode must be on and debug off');
$check($security['wantAssertionsSigned'] === true, 'assertions must be required to be signed');
$check($security['requestedAuthnContext'] === false, 'RequestedAuthnContext must be off (breaks Entra MFA sign-ins)');
$check($security['rejectUnsolicitedResponsesWithInResponseTo'] === true, 'unsolicited responses must be rejected');
$check($security['relaxDestinationValidation'] === false && $security['destinationStrictlyMatches'] === true, 'Destination must be matched strictly');
$check($security['signatureAlgorithm'] === 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256', 'requests must be signed with SHA-256');
$check($security['authnRequestsSigned'] === false, 'requests are signed without being asked to');
$check($security['wantMessagesSigned'] === false, 'wantMessagesSigned follows require_signed_response');
$check(Saml::settings($config, true)['security']['wantMessagesSigned'] === true, 'logout messages must be required to be signed');
$check(Saml::settings($withSp)['security']['authnRequestsSigned'] === true, 'sign_requests did not turn on request signing');
$check(!isset($settings['sp']['privateKey']), 'a private key is loaded without sign_requests or encryption configured');

// --- sign-in request ---------------------------------------------------------
$begin = Saml::beginLogin($config);
$check(str_starts_with($begin['url'], IDP_SSO . '?SAMLRequest='), 'sign-in does not go to the IdP SSO URL');
$check(preg_match('/^ONELOGIN_[0-9a-f]{40}$/', $begin['request_id']) === 1, 'request ID is not a random token: ' . $begin['request_id']);
parse_str((string)parse_url($begin['url'], PHP_URL_QUERY), $query);
$requestXml = (string)gzinflate(base64_decode($query['SAMLRequest']));
$check(str_contains($requestXml, 'ID="' . $begin['request_id'] . '"'), 'AuthnRequest ID differs from the recorded ID');
$check(!str_contains($requestXml, 'RequestedAuthnContext'), 'AuthnRequest carries a RequestedAuthnContext');
$check(!str_contains($requestXml, 'NameIDPolicy'), 'AuthnRequest carries a NameIDPolicy');
$check(!str_contains($requestXml, 'ForceAuthn'), 'AuthnRequest forces re-authentication by default');
$check(($query['RelayState'] ?? '') === Saml::RELAY_STATE, 'RelayState is not the fixed value');
$check(!isset($query['Signature']), 'an unsigned configuration sends a signature');
parse_str((string)parse_url(Saml::beginLogin(['force_authn' => true] + $config)['url'], PHP_URL_QUERY), $forcedQuery);
$check(str_contains((string)gzinflate(base64_decode($forcedQuery['SAMLRequest'])), 'ForceAuthn="true"'), 'force_authn is not sent');
parse_str((string)parse_url(Saml::beginLogin($withSp)['url'], PHP_URL_QUERY), $signedQuery);
$check(isset($signedQuery['Signature'], $signedQuery['SigAlg']) && str_contains($signedQuery['SigAlg'], 'rsa-sha256'), 'sign_requests does not sign the AuthnRequest with SHA-256');

// --- single logout -----------------------------------------------------------
$sloConfig = ['idp_slo_url' => 'https://login.microsoftonline.com/tenant/saml2', 'sp_slo_url' => 'https://slo.example.edu/saml/slo.php'] + $config;
$check(!Saml::sloEnabled($config) && Saml::sloEnabled($sloConfig), 'single logout is not tied to both URLs');
$logout = Saml::beginLogout($sloConfig, 'faculty@example.edu', 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress', '_session1');
$check(str_starts_with($logout['url'], $sloConfig['idp_slo_url'] . '?SAMLRequest='), 'logout does not go to the IdP logout URL');

// --- SP metadata -------------------------------------------------------------
$metadata = Saml::metadataXml($sloConfig);
$check(str_contains($metadata, 'entityID="' . SP_ENTITY . '"'), 'metadata lacks the SP entity ID');
$check(str_contains($metadata, 'Location="' . ACS . '"'), 'metadata lacks the ACS URL');
$check(str_contains($metadata, 'SingleLogoutService') && str_contains($metadata, 'slo.php'), 'metadata lacks the logout endpoint');
$check(!str_contains($metadata, 'PRIVATE KEY') && !str_contains($metadata, base64_encode($sp['private'])), 'metadata exposes a private key');
$check(!str_contains(Saml::metadataXml($config), 'SingleLogoutService'), 'metadata advertises logout when it is off');
$check(str_contains(Saml::metadataXml($withSp), 'KeyDescriptor'), 'metadata omits the SP certificate when one is configured');
unlink($keyFile);

// --- pending requests --------------------------------------------------------
$directory = sys_get_temp_dir() . '/slo-saml-' . bin2hex(random_bytes(4));
Saml::rememberRequest('login', '_abcdef123456', $directory);
$check(!Saml::takeRequest('logout', '_abcdef123456', $directory), 'a login request is accepted as a logout request');
$check(Saml::takeRequest('login', '_abcdef123456', $directory), 'a recorded request is not accepted');
$check(!Saml::takeRequest('login', '_abcdef123456', $directory), 'a request is accepted twice (replay)');
$check(!Saml::takeRequest('login', '_neverrecorded1', $directory), 'an unknown request is accepted');
$check(!Saml::takeRequest('login', '../../etc/passwd', $directory), 'a request ID with path characters is accepted');
$check(!Saml::takeRequest('login', '', $directory), 'an empty request ID is accepted');
Saml::rememberRequest('login', '_oldrequest1234', $directory);
foreach (glob($directory . '/*.pending') as $file) {
    touch($file, time() - 4000);
}
$check(!Saml::takeRequest('login', '_oldrequest1234', $directory), 'an expired request is accepted');
Saml::rememberRequest('login', '_freshrequest12', $directory);
$check(count(glob($directory . '/*.pending')) === 1, 'expired requests are not cleaned up');
array_map('unlink', glob($directory . '/*'));
rmdir($directory);

$check(Saml::inResponseTo(base64_encode($buildResponse()), false) === '_req0123456789abcdef', 'InResponseTo is not read from a response');
$check(Saml::inResponseTo('%%%', false) === '' && Saml::inResponseTo(base64_encode('<not xml'), false) === '', 'garbage yields an InResponseTo');
$check(Saml::inResponseTo(base64_encode(gzdeflate('<a InResponseTo="_x"/>')), true) === '_x', 'InResponseTo is not read from a redirect-binding message');

// --- routes ------------------------------------------------------------------
foreach (glob(__DIR__ . '/../src/saml/*.php') ?: [] as $file) {
    $route = 'saml/' . basename($file);
    $check(Authorization::policyForRoute($route) === 'saml_protocol', "{$route} is not classified as a SAML protocol route");
}

if ($failures) {
    fwrite(STDERR, "FAIL\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "saml_test: OK\n";
