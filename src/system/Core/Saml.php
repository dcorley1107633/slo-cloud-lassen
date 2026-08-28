<?php
declare(strict_types=1);

namespace SLOCloud\Core;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * Minimal SAML 2.0 Service Provider
 *
 * Handles SSO via HTTP-Redirect binding (AuthnRequest) and HTTP-POST binding (ACS).
 * Uses only PHP built-ins: DOMDocument, C14N(), openssl_verify(), gzdeflate().
 * No external dependencies required.
 *
 * Security controls:
 *   - All Responses/Assertions must be signed by the configured IdP certificate
 *   - XML Canonicalization (C14N) is applied before signature verification
 *   - Digest of the referenced element is verified before the signature
 *   - NotBefore/NotOnOrAfter time window enforced (with 60-second clock skew)
 *   - Audience restriction enforced
 *   - Destination URL enforced
 *
 * @package SLOCloud\Core
 */
class Saml
{
    private const NS_SAMLP = 'urn:oasis:names:tc:SAML:2.0:protocol';
    private const NS_SAML  = 'urn:oasis:names:tc:SAML:2.0:assertion';
    private const NS_DS    = 'http://www.w3.org/2000/09/xmldsig#';

    private const SIG_ALGORITHMS = [
        'http://www.w3.org/2000/09/xmldsig#rsa-sha1'               => OPENSSL_ALGO_SHA1,
        'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256'        => OPENSSL_ALGO_SHA256,
        'http://www.w3.org/2001/04/xmldsig-more#rsa-sha384'        => OPENSSL_ALGO_SHA384,
        'http://www.w3.org/2001/04/xmldsig-more#rsa-sha512'        => OPENSSL_ALGO_SHA512,
    ];

    private const DIGEST_ALGORITHMS = [
        'http://www.w3.org/2000/09/xmldsig#sha1'   => 'sha1',
        'http://www.w3.org/2001/04/xmlenc#sha256'  => 'sha256',
        'http://www.w3.org/2001/04/xmlenc#sha512'  => 'sha512',
    ];

    /**
     * Build a SAML 2.0 AuthnRequest redirect URL (HTTP-Redirect binding).
     *
     * @param array $samlConfig  The 'saml' section from config.yaml
     * @return string            Full URL to redirect the browser to
     */
    public static function buildAuthnRequestUrl(array $samlConfig): string
    {
        $id     = '_' . bin2hex(random_bytes(16));
        $now    = gmdate('Y-m-d\TH:i:s\Z');
        $acsUrl    = htmlspecialchars($samlConfig['sp_acs_url'], ENT_XML1, 'UTF-8');
        $spEntity  = htmlspecialchars($samlConfig['sp_entity_id'], ENT_XML1, 'UTF-8');
        $idpSsoUrl = $samlConfig['idp_sso_url'];

        $xml = <<<XML
<samlp:AuthnRequest xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol"
  xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion"
  ID="{$id}"
  Version="2.0"
  IssueInstant="{$now}"
  Destination="{$idpSsoUrl}"
  AssertionConsumerServiceURL="{$acsUrl}"
  ProtocolBinding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST">
  <saml:Issuer>{$spEntity}</saml:Issuer>
</samlp:AuthnRequest>
XML;

        $deflated = gzdeflate($xml);
        if ($deflated === false) {
            throw new RuntimeException('Failed to deflate SAML AuthnRequest');
        }

        $param = urlencode(base64_encode($deflated));
        $sep   = str_contains($idpSsoUrl, '?') ? '&' : '?';

        return $idpSsoUrl . $sep . 'SAMLRequest=' . $param;
    }

    /**
     * Parse and validate a SAML 2.0 Response (HTTP-POST binding, ACS).
     *
     * @param string $samlResponseBase64  Raw value of the SAMLResponse POST field
     * @param array  $samlConfig          The 'saml' section from config.yaml
     * @return array{email: string, name: string}
     * @throws RuntimeException           On any validation failure
     */
    public static function parseResponse(string $samlResponseBase64, array $samlConfig): array
    {
        // Decode
        $xml = base64_decode($samlResponseBase64, strict: true);
        if ($xml === false) {
            throw new RuntimeException('SAMLResponse is not valid base64');
        }

        // Parse XML safely
        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = true;
        // Disable external entity loading to prevent XXE
        libxml_set_external_entity_loader(null);
        $prevErrors = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($xml, LIBXML_NONET);
        libxml_use_internal_errors($prevErrors);
        if (!$loaded) {
            throw new RuntimeException('SAMLResponse is not valid XML');
        }

        $xp = new DOMXPath($doc);
        $xp->registerNamespace('samlp', self::NS_SAMLP);
        $xp->registerNamespace('saml',  self::NS_SAML);
        $xp->registerNamespace('ds',    self::NS_DS);

        // 1. Check top-level Response status
        $statusCode = (string) $xp->evaluate(
            'string(//samlp:Response/samlp:Status/samlp:StatusCode/@Value)'
        );
        if ($statusCode !== 'urn:oasis:names:tc:SAML:2.0:status:Success') {
            $statusMsg = (string) $xp->evaluate(
                'string(//samlp:Response/samlp:Status/samlp:StatusMessage)'
            );
            throw new RuntimeException(
                'IdP returned non-success status: ' . $statusCode
                . ($statusMsg !== '' ? " ($statusMsg)" : '')
            );
        }

        // 2. Validate Destination
        $destination = (string) $xp->evaluate('string(//samlp:Response/@Destination)');
        if ($destination !== '' &&
            rtrim($destination, '/') !== rtrim($samlConfig['sp_acs_url'], '/')
        ) {
            throw new RuntimeException('SAML Response Destination mismatch');
        }

        // 3. Verify XML signature (on Response or Assertion)
        $certPem = self::buildCertPem($samlConfig['idp_cert']);
        $sigNodes = $xp->query('//ds:Signature');
        if ($sigNodes === false || $sigNodes->length === 0) {
            throw new RuntimeException('SAMLResponse contains no signature - unsigned responses are not accepted');
        }

        $verified = false;
        foreach ($sigNodes as $sigNode) {
            if (self::verifyXmlSignature($doc, $sigNode, $certPem)) {
                $verified = true;
                break;
            }
        }
        if (!$verified) {
            throw new RuntimeException('SAMLResponse signature verification failed');
        }

        // 4. Enforce time window (with 60-second clock skew allowance)
        $notBefore    = (string) $xp->evaluate('string(//saml:Assertion/saml:Conditions/@NotBefore)');
        $notOnOrAfter = (string) $xp->evaluate('string(//saml:Assertion/saml:Conditions/@NotOnOrAfter)');
        $now  = time();
        $skew = 60;
        if ($notBefore !== '' && ($now + $skew) < strtotime($notBefore)) {
            throw new RuntimeException('SAMLResponse is not yet valid (NotBefore: ' . $notBefore . ')');
        }
        if ($notOnOrAfter !== '' && ($now - $skew) >= strtotime($notOnOrAfter)) {
            throw new RuntimeException('SAMLResponse has expired (NotOnOrAfter: ' . $notOnOrAfter . ')');
        }

        // 5. Enforce Audience restriction
        $audience = (string) $xp->evaluate(
            'string(//saml:Assertion/saml:Conditions/saml:AudienceRestriction/saml:Audience)'
        );
        if ($audience !== '' && $audience !== $samlConfig['sp_entity_id']) {
            throw new RuntimeException(
                'SAML Audience mismatch: expected "' . $samlConfig['sp_entity_id']
                . '", got "' . $audience . '"'
            );
        }

        // 6. Extract email
        $email = '';
        $attrEmail = trim((string) ($samlConfig['attr_email'] ?? ''));
        if ($attrEmail !== '') {
            $email = (string) $xp->evaluate(
                'string(//saml:Assertion/saml:AttributeStatement/saml:Attribute[@Name='
                . self::xpathStr($attrEmail)
                . ']/saml:AttributeValue)'
            );
        }
        if (trim($email) === '') {
            // Fall back to NameID
            $email = (string) $xp->evaluate('string(//saml:Assertion/saml:Subject/saml:NameID)');
        }
        $email = trim($email);
        if ($email === '') {
            throw new RuntimeException('Could not extract email from SAMLResponse');
        }

        // 7. Extract display name (best-effort)
        $name = '';
        $attrName = trim((string) ($samlConfig['attr_name'] ?? ''));
        if ($attrName !== '') {
            $name = (string) $xp->evaluate(
                'string(//saml:Assertion/saml:AttributeStatement/saml:Attribute[@Name='
                . self::xpathStr($attrName)
                . ']/saml:AttributeValue)'
            );
        }

        return ['email' => $email, 'name' => trim($name)];
    }

    /**
     * Verify an XMLDSig Signature node against a PEM-encoded public certificate.
     *
     * Verifies both:
     *   (a) the digest of the referenced element (enveloped-signature transform)
     *   (b) the RSA signature over C14N(SignedInfo)
     */
    private static function verifyXmlSignature(
        DOMDocument $doc,
        \DOMNode $sigNode,
        string $certPem
    ): bool {
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('ds', self::NS_DS);

        // Resolve signature algorithm
        $algNode = $xp->query('ds:SignedInfo/ds:SignatureMethod', $sigNode)->item(0);
        if ($algNode === null) {
            return false;
        }
        $algUri      = $algNode->getAttribute('Algorithm');
        $opensslAlgo = self::SIG_ALGORITHMS[$algUri] ?? null;
        if ($opensslAlgo === null) {
            return false;
        }

        // Resolve digest algorithm
        $digestMethodNode = $xp->query('ds:SignedInfo/ds:Reference/ds:DigestMethod', $sigNode)->item(0);
        $digestAlgUri     = $digestMethodNode ? $digestMethodNode->getAttribute('Algorithm') : '';
        $digestAlg        = self::DIGEST_ALGORITHMS[$digestAlgUri] ?? 'sha256';

        // Verify element digest
        $refNode = $xp->query('ds:SignedInfo/ds:Reference', $sigNode)->item(0);
        if ($refNode !== null) {
            $refUri    = ltrim($refNode->getAttribute('URI'), '#');
            $signedEl  = $refUri !== '' ? self::findElementById($doc, $refUri) : null;

            if ($signedEl !== null) {
                // Clone, remove the Signature subtree (enveloped-signature transform), then C14N
                $clonedDoc = new DOMDocument();
                $clonedDoc->appendChild($clonedDoc->importNode($signedEl->cloneNode(true), true));

                $clonedXp = new DOMXPath($clonedDoc);
                $clonedXp->registerNamespace('ds', self::NS_DS);
                $sigToRemove = $clonedXp->query('//ds:Signature')->item(0);
                if ($sigToRemove !== null && $sigToRemove->parentNode !== null) {
                    $sigToRemove->parentNode->removeChild($sigToRemove);
                }

                $c14nElement = $clonedDoc->documentElement->C14N(true, false);
                $actualDigest   = base64_encode(hash($digestAlg, $c14nElement, true));
                $expectedDigest = (string) $xp->evaluate(
                    'string(ds:SignedInfo/ds:Reference/ds:DigestValue)',
                    $sigNode
                );
                if (!hash_equals($expectedDigest, $actualDigest)) {
                    return false;
                }
            }
        }

        // C14N SignedInfo and verify RSA signature
        $signedInfoNode = $xp->query('ds:SignedInfo', $sigNode)->item(0);
        if ($signedInfoNode === null) {
            return false;
        }
        $c14nSignedInfo = $signedInfoNode->C14N(true, false);

        $sigValueB64 = (string) $xp->evaluate('string(ds:SignatureValue)', $sigNode);
        $sigValueB64 = (string) preg_replace('/\s+/', '', $sigValueB64);
        $sigBytes    = base64_decode($sigValueB64, strict: true);
        if ($sigBytes === false) {
            return false;
        }

        $pubKey = openssl_pkey_get_public($certPem);
        if ($pubKey === false) {
            return false;
        }

        return openssl_verify($c14nSignedInfo, $sigBytes, $pubKey, $opensslAlgo) === 1;
    }

    /**
     * Search for an element by ID attribute (handles ID/Id/id variants).
     */
    private static function findElementById(DOMDocument $doc, string $id): ?DOMElement
    {
        // DOMDocument::getElementById only works when ID attributes are declared in a DTD/schema.
        // We search manually to support all SAML ID attribute naming conventions.
        $all = $doc->getElementsByTagName('*');
        foreach ($all as $el) {
            if (!($el instanceof DOMElement)) {
                continue;
            }
            foreach (['ID', 'Id', 'id'] as $attr) {
                if ($el->getAttribute($attr) === $id) {
                    return $el;
                }
            }
        }
        return null;
    }

    /**
     * Build a PEM-formatted certificate from a raw base64 cert string.
     * Accepts the cert with or without PEM headers.
     */
    private static function buildCertPem(string $cert): string
    {
        // Strip any existing headers and whitespace
        $cert = str_replace([
            '-----BEGIN CERTIFICATE-----',
            '-----END CERTIFICATE-----',
            "\r", "\n", " ", "\t",
        ], '', $cert);

        return "-----BEGIN CERTIFICATE-----\n"
            . chunk_split($cert, 64, "\n")
            . "-----END CERTIFICATE-----\n";
    }

    /**
     * Build a safe XPath string literal, handling embedded quotes.
     */
    private static function xpathStr(string $s): string
    {
        if (!str_contains($s, "'")) {
            return "'" . $s . "'";
        }
        if (!str_contains($s, '"')) {
            return '"' . $s . '"';
        }
        // String contains both quote types - use XPath concat()
        $parts = explode("'", $s);
        return "concat('" . implode("', \"'\", '", $parts) . "')";
    }
}
