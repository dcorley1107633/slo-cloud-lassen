# Bundled third-party PHP libraries

SLO Cloud has no Composer. The libraries below are copied here unmodified and
loaded by `autoload.php`. Nothing is downloaded at install or run time.

| Library | Version | License | Used for |
|---|---|---|---|
| [onelogin/php-saml](https://github.com/SAML-Toolkits/php-saml) | 4.3.2 | MIT | SAML 2.0 single sign-on (`system/Core/Saml.php`) |
| [robrichards/xmlseclibs](https://github.com/robrichards/xmlseclibs) | 3.1.5 | BSD-3-Clause | XML signatures and encryption, required by php-saml |

Each library's `LICENSE` sits beside its code. Only the runtime files are
kept (`src/`, the license and the changelog); tests, examples and CI files are
left out.

## Updating

Check both projects for security releases when you update SLO Cloud.

1. On a development machine with Composer, in an empty folder, run
   `composer require onelogin/php-saml`. This pulls the matching xmlseclibs.
2. Replace `onelogin-php-saml/src` and `xmlseclibs/src` (and `xmlseclibs.php`,
   `LICENSE`, the changelog) with the files from `vendor/onelogin/php-saml`
   and `vendor/robrichards/xmlseclibs`. Do not edit the library files.
3. Update the versions in this file and in `autoload.php`.
4. Run `php tests/saml_test.php` and sign in through your identity provider
   in a test environment.

## Requirements

PHP extensions `dom`, `libxml`, `openssl` and `zlib`. The IdP metadata parser
(`IdPMetadataParser`) is bundled but not used, so `curl` is not needed.
