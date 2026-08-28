<?php
declare(strict_types=1);

/**
 * Which LTI launch failures count toward the launch throttle.
 *
 * Kept in its own file so the policy can be asserted by the test suite;
 * oauth_validate.php cannot be included standalone because it expects a live
 * request context and exits.
 *
 * The list holds only failures where a caller presented credentials that did
 * not check out. Every other way a launch can fail is either a
 * misconfiguration on our side or a malformed request. Every launch from one
 * LMS shares a source address and consumer key, so counting operational
 * failures would let an operator mistake - a wrong launch URL, an allowlist
 * that omits the LMS's signature method - lock out the whole institution.
 *
 * Excluding them costs nothing defensively: a credential guesser sends a
 * well-formed launch with a wrong signature and lands on `signature_mismatch`,
 * which is counted.
 *
 * A reason absent from this list is not throttled, so adding a new
 * lti_auth_fail() reason fails toward availability rather than silently arming
 * a lockout.
 *
 * @package SLOCloud\LTI
 */
const LTI_THROTTLED_FAILURE_REASONS = [
    'invalid_consumer',   // unknown or wrong consumer key
    'signature_mismatch', // wrong secret, or a forged signature
    'nonce_replay',       // a launch with a valid signature presented twice
];

// Two deliberate omissions, both of which would otherwise cause self-inflicted
// outages:
//
// `launch_rate_limited` - a throttled launch must not record another failure,
// or a retrying client extends its own lock on every attempt and ratchets
// straight to the ceiling.
//
// `expired_or_invalid_nonce` - fires on timestamp skew, which an attacker
// replaying an old capture triggers, but so does ordinary clock drift on
// either server. Nothing is lost by excluding it: a replay inside the skew
// window is caught by `nonce_replay` above, and one outside the window fails
// deterministically whether or not it is counted, because no secret is being
// guessed.
