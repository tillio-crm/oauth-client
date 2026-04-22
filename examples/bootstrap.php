<?php

declare(strict_types=1);

use TillioCrm\OAuth\Client\Client;

require __DIR__ . '/../vendor/autoload.php';

$configPath = __DIR__ . '/config.php';

if (!is_file($configPath)) {
    http_response_code(500);
    echo '<h1>Brak konfiguracji</h1>';
    echo '<p>Skopiuj <code>examples/config.example.php</code> do <code>examples/config.php</code> i wstaw swoje credentials.</p>';
    exit;
}

$config = require $configPath;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

return new Client($config);
