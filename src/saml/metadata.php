<?php
/**
 * SAML 2.0 Service Provider metadata
 *
 * Public by design: it holds only this application's entity ID, ACS URL,
 * logout URL and (when configured) its public certificate. Give this URL, or
 * the downloaded file, to the identity provider administrator instead of
 * typing the values by hand.
 *
 * Needs saml.sp_entity_id and saml.sp_acs_url only. It does not depend on
 * saml.enabled or the identity provider settings, so the file can be produced
 * before the IdP is set up.
 */

declare(strict_types=1);

require_once __DIR__ . '/../system/includes/init.php';
require_once __DIR__ . '/../system/Core/Saml.php';

use SLOCloud\Core\Saml;
use SLOCloud\Core\Logger;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}

$samlConfig = Saml::configFrom($config);

try {
    $xml = Saml::metadataXml($samlConfig);
} catch (Throwable $e) {
    Logger::getInstance()->error('SAML metadata could not be built: ' . $e->getMessage());
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'SAML metadata is not available. Set SP Entity ID and SP Assertion Consumer Service URL first.';
    exit;
}

header('Content-Type: application/samlmetadata+xml; charset=UTF-8');
header('Content-Disposition: inline; filename="slocloud-sp-metadata.xml"');
header('Cache-Control: no-cache');
echo $xml;
