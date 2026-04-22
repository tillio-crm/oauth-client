<?php

declare(strict_types=1);

use TillioCrm\OAuth\Client\Client;
/** @var Client $client */

$client = require __DIR__ . '/bootstrap.php';

if (isset($_GET['logout'])) {
    $client->logout();
    header('Location: index.php');
    exit;
}

?><!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <title>Tillio OAuth Client — demo</title>
    <style>
        body { font-family: sans-serif; max-width: 640px; margin: 2rem auto; padding: 0 1rem; }
        pre  { background: #f4f4f4; padding: 1rem; overflow: auto; }
        .btn { display: inline-block; padding: .6rem 1rem; background: #2563eb; color: white; text-decoration: none; border-radius: .25rem; }
        .muted { color: #666; font-size: .85rem; }
    </style>
</head>
<body>

<h1>Tillio OAuth Client — demo</h1>

<?php if (!$client->isAuthenticated()): ?>

    <p>Nie jesteś zalogowany.</p>
    <p><a class="btn" href="login.php">Zaloguj się przez Tillio</a></p>

<?php else: ?>

    <?php
        $user = $client->user();
        $showProfile = isset($_GET['profile']);
        $profile = null;
        $profileError = null;
        if ($showProfile) {
            try {
                $profile = $client->profile();
            } catch (\Throwable $e) {
                $profileError = $e->getMessage();
            }
        }
    ?>

    <p>Jesteś zalogowany jako <strong><?= htmlspecialchars($user->getName() ?? $user->getEmail() ?? $user->getId() ?? '—') ?></strong>.</p>

    <h2>Dane użytkownika</h2>
    <pre><?= htmlspecialchars(print_r($user->toArray(), true)) ?></pre>

    <p>
        <?php if (!$showProfile): ?>
            <a class="btn" href="?profile=1">Pokaż pełny profil</a>
        <?php else: ?>
            <a class="btn" href="index.php">Ukryj pełny profil</a>
        <?php endif; ?>
        <a class="btn" href="?logout=1" style="background:#dc2626;">Wyloguj</a>
    </p>

    <?php if ($showProfile): ?>
        <h2>Pełny profil (<code>/api/v1/auth/user/profile</code>)</h2>
        <?php if ($profileError !== null): ?>
            <pre style="color:#b91c1c;"><?= htmlspecialchars($profileError) ?></pre>
        <?php else: ?>
            <pre><?= htmlspecialchars(print_r($profile, true)) ?></pre>
        <?php endif; ?>
    <?php endif; ?>

<?php endif; ?>

<p class="muted">Plik: <code>examples/index.php</code>. Po zmianie konfiguracji edytuj <code>examples/config.php</code>.</p>

</body>
</html>
