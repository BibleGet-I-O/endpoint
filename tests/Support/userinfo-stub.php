<?php

/**
 * Stub of Zitadel's /oidc/v1/userinfo for the server tests, run with
 * `php -S host:port tests/Support/userinfo-stub.php`. It decodes (without
 * verifying) the bearer token's payload and answers from test-only claims:
 *
 *   userinfo_verified  bool   email_verified to report (default false)
 *   userinfo_mode      string "error" → 500, "unauthorized" → 401,
 *                             "othersub" → a different sub, "garbage" → not JSON
 */

declare(strict_types=1);

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (preg_match('/^Bearer +[^.]+\.([^.]+)\./', $auth, $m) !== 1) {
    http_response_code(401);
    exit;
}
$claims = json_decode((string) base64_decode(strtr($m[1], '-_', '+/')), true);
$claims = is_array($claims) ? $claims : [];
$mode   = $claims['userinfo_mode'] ?? '';

header('Content-Type: application/json');
if ($mode === 'error') {
    http_response_code(500);
    echo '{"error":"server_error"}';
    exit;
}
if ($mode === 'unauthorized') {
    http_response_code(401);
    echo '{"error":"invalid_token"}';
    exit;
}
if ($mode === 'garbage') {
    echo 'not json';
    exit;
}
echo json_encode([
    'sub'            => $mode === 'othersub' ? 'someone-else' : ( $claims['sub'] ?? null ),
    'email'          => 'user@example.test',
    'email_verified' => ( $claims['userinfo_verified'] ?? false ) === true,
]);
