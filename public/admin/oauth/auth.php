<?php
/**
 * Schritt 1: Sveltia öffnet dieses Script im Popup → Weiterleitung zu GitHub.
 * Erwartet ?provider=github (alles andere wird abgelehnt).
 */
declare(strict_types=1);
require __DIR__ . '/_common.php';
fvj_no_cache();

if (($_GET['provider'] ?? 'github') !== 'github') {
    http_response_code(400);
    exit('Nur GitHub wird unterstützt.');
}

[$clientId] = fvj_oauth_credentials();

// CSRF-Schutz: zufälliger State, im Cookie gespiegelt, im Callback verglichen.
$state = bin2hex(random_bytes(16));
setcookie('fvj_oauth_state', $state, [
    'expires' => time() + 600,
    'path' => dirname($_SERVER['SCRIPT_NAME']),
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);

$params = http_build_query([
    'client_id' => $clientId,
    'redirect_uri' => fvj_callback_url(),
    'scope' => 'repo,user:email',
    'state' => $state,
]);
header('Location: https://github.com/login/oauth/authorize?' . $params, true, 302);
exit;
