<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// Force the test environment BEFORE loading .env.
//
// .env pins APP_ENV=dev for local development, and Dotenv::populate() does not
// override an already-set variable — so whichever runs first wins. PHPUnit's
// <server name="APP_ENV"> block is applied after this bootstrap file, which
// means .env would otherwise win and the kernel would boot in dev: no
// test.service_container, no _test database suffix.
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';

// symfony/dotenv is a hard requirement of this project, so bootEnv() always
// exists — no method_exists() guard (it would never be false, and PHPStan
// rightly reports that at level max).
new Dotenv()->bootEnv(dirname(__DIR__).'/.env');

if (filter_var($_SERVER['APP_DEBUG'] ?? false, \FILTER_VALIDATE_BOOL)) {
    umask(0o000);
}
