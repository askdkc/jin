<?php

test('the application is publicly reachable when IP restriction is disabled', function () {
    config([
        'access.enabled' => false,
        'access.allowed_ips' => [],
    ]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
        ->get('/up')
        ->assertOk();
});

test('configured IP addresses can access the application', function (array $allowedIps, string $remoteAddress) {
    config(['access.allowed_ips' => $allowedIps]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $remoteAddress])
        ->get('/up')
        ->assertOk();
})->with([
    'exact IPv4' => [['203.0.113.10'], '203.0.113.10'],
    'IPv4 CIDR' => [['198.51.100.0/24'], '198.51.100.42'],
    'exact IPv6' => [['2001:db8::10'], '2001:db8::10'],
    'IPv6 CIDR' => [['2001:db8::/32'], '2001:db8::42'],
]);

test('unlisted IP addresses receive a not found response', function () {
    config(['access.allowed_ips' => ['203.0.113.10']]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
        ->get('/up')
        ->assertNotFound();
});

test('an empty allowlist denies access', function () {
    config(['access.allowed_ips' => []]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->get('/up')
        ->assertNotFound();
});

test('unlisted IP addresses cannot reach broadcast authentication', function () {
    config(['access.allowed_ips' => ['203.0.113.10']]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
        ->post('/broadcasting/auth')
        ->assertNotFound();
});

test('trusted proxies resolve the original client IP address', function () {
    config([
        'access.allowed_ips' => ['203.0.113.10'],
        'trustedproxy.proxies' => ['10.0.0.10'],
    ]);

    $this
        ->withServerVariables([
            'REMOTE_ADDR' => '10.0.0.10',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.10',
        ])
        ->get('/up')
        ->assertOk();
});

test('forwarded client IP addresses from untrusted proxies are ignored', function () {
    config([
        'access.allowed_ips' => ['203.0.113.10'],
        'trustedproxy.proxies' => [],
    ]);

    $this
        ->withServerVariables([
            'REMOTE_ADDR' => '198.51.100.20',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.10',
        ])
        ->get('/up')
        ->assertNotFound();
});
