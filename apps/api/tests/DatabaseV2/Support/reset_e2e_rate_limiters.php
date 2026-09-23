<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\RateLimiter;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$app = require dirname(__DIR__, 3) . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (!$app->environment('e2e') || getenv('MEPA_E2E_RATE_LIMIT_RESET') !== '1') {
    fwrite(STDERR, "Refusing rate-limit reset outside the explicit E2E test environment.\n");
    exit(2);
}

$ip = '127.0.0.1';

// Laravel's effective keys for the named API/login limiters and the
// route-local numeric throttles used by the Academy API.
RateLimiter::clear(md5('api' . $ip));
RateLimiter::clear(md5('login' . hash('sha256', $ip)));
RateLimiter::clear(sha1('|' . $ip));

fwrite(STDOUT, "E2E_RATE_LIMITERS_RESET\n");
