<?php

declare(strict_types=1);

use TillioCrm\OAuth\Client\Exception\AuthorizationDeniedException;
use TillioCrm\OAuth\Client\Exception\InvalidStateException;
use TillioCrm\OAuth\Client\Exception\TillioOAuthException;

/** @var \TillioCrm\OAuth\Client\Client $client */
$client = require __DIR__ . '/bootstrap.php';

try {
    $client->handleCallback();
    header('Location: index.php');
    exit;
} catch (AuthorizationDeniedException $e) {
    http_response_code(400);
    $title   = 'Autoryzacja odrzucona';
    $message = $e->getMessage();
} catch (InvalidStateException $e) {
    http_response_code(400);
    $title   = 'Błąd CSRF';
    $message = $e->getMessage();
} catch (TillioOAuthException $e) {
    http_response_code(500);
    $title   = 'Błąd logowania';
    $message = $e->getMessage();
}

?><!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars($title) ?></title>
    <style>body{font-family:sans-serif;max-width:640px;margin:2rem auto;padding:0 1rem}</style>
</head>
<body>
<h1><?= htmlspecialchars($title) ?></h1>
<p><?= htmlspecialchars($message) ?></p>
<p><a href="index.php">Wróć do strony głównej</a></p>
</body>
</html>
