<?php
/**
 * Invitation privacy and retry policy tests. No PrestaShop installation required.
 *
 * php tests/KiyohInvitationTest.php
 */

define('_PS_VERSION_', '9.2.0');

require dirname(__DIR__) . '/kiyoh/classes/KiyohInvitationSupport.php';

$failures = 0;
$assertions = 0;

function check($condition, $message)
{
    global $failures, $assertions;
    $assertions++;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: $message\n");
    }
}

function same($expected, $actual, $message)
{
    check(
        $expected === $actual,
        $message . ' expected ' . var_export($expected, true) . ' got ' . var_export($actual, true)
    );
}

same('j***@example.com', KiyohInvitationSupport::maskEmailForLog('john@example.com'), 'email is masked for logs');
check(
    strpos(KiyohInvitationSupport::maskEmailForLog('john@example.com'), 'john') === false,
    'plain local part is not logged'
);
same('j***@mail.domain.nl', KiyohInvitationSupport::maskEmailForLog('jan.doe@mail.domain.nl'), 'long local part is masked');

same(503, KiyohInvitationSupport::parseHttpStatusFromMessage('HTTP 503: Service Unavailable'), 'parse HTTP status');
same('temporary', KiyohInvitationSupport::classifyInviteFailure('HTTP 503: down', 503), '503 is temporary');
same('temporary', KiyohInvitationSupport::classifyInviteFailure('cURL error: timeout', null), 'curl errors are temporary');
same('permanent', KiyohInvitationSupport::classifyInviteFailure('HTTP 400: Bad Request', 400), '400 is permanent');
same('permanent', KiyohInvitationSupport::classifyInviteFailure('Invitation data validation failed: email', null), 'validation is permanent');

same(true, KiyohInvitationSupport::isTemporaryHttpStatus(503), '503 temporary');
same(false, KiyohInvitationSupport::isTemporaryHttpStatus(404), '404 not temporary');

same(900, KiyohInvitationSupport::retryBackoffSeconds(0), 'first backoff');
same(3600, KiyohInvitationSupport::retryBackoffSeconds(1), 'second backoff');

same(true, KiyohInvitationSupport::isRetryEligible('failed', 'temporary', 0), 'failed temporary row is retryable');
same(true, KiyohInvitationSupport::isRetryEligible('failed', 'temporary', 2), 'two attempts still retryable');
same(false, KiyohInvitationSupport::isRetryEligible('failed', 'temporary', 3), 'three attempts not retryable');
same(false, KiyohInvitationSupport::isRetryEligible('failed', 'permanent', 0), 'permanent is not retryable');
same(false, KiyohInvitationSupport::isRetryEligible('failed_final', 'final', 3), 'final is not retryable');

same(true, KiyohInvitationSupport::blocksNewInviteForOrder('sent', null, 0), 'sent blocks duplicate');
same(true, KiyohInvitationSupport::blocksNewInviteForOrder('failed', 'temporary', 1), 'retryable failed blocks hook resend');
same(true, KiyohInvitationSupport::blocksNewInviteForOrder('failed', 'permanent', 0), 'permanent failed blocks');
same(true, KiyohInvitationSupport::blocksNewInviteForOrder('pending', null, 0), 'pending blocks');

$simulatedLog = sprintf(
    'Kiyoh Invitation %s successful - Order ID: %d, Email: %s',
    'sendInvitation',
    42,
    KiyohInvitationSupport::maskEmailForLog('customer@shop.example')
);
check(strpos($simulatedLog, 'customer@shop.example') === false, 'success log line has no plain email');
check(strpos($simulatedLog, 'c***@shop.example') !== false, 'success log line uses masked email');

fwrite(STDOUT, "KiyohInvitationTest: $assertions assertions, $failures failures\n");
exit($failures > 0 ? 1 : 0);