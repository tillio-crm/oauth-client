<?php

declare(strict_types=1);

/** @var \TillioCrm\OAuth\Client\Client $client */
$client = require __DIR__ . '/bootstrap.php';

$client->redirectToLogin();
