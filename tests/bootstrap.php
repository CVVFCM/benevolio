<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// symfony/dotenv is a hard requirement of this project, so bootEnv() always
// exists — no method_exists() guard (it would never be false, and PHPStan
// rightly reports that at level max).
new Dotenv()->bootEnv(dirname(__DIR__).'/.env');

if (filter_var($_SERVER['APP_DEBUG'] ?? false, \FILTER_VALIDATE_BOOL)) {
    umask(0o000);
}
