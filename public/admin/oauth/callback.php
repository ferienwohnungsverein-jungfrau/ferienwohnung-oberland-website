<?php
/**
 * Schritt 2: GitHub schickt den Code zurück → Token holen → an das CMS-Fenster
 * übergeben (postMessage-Handshake wie bei Decap/Sveltia üblich).
 */
declare(strict_types=1);
require __DIR__ . '/_common.php';
fvj_no_cache();
header('Content-Type: text/html; charset=utf-8');

$code = $_GET['code'] ?? '';
$state = $_GET['state'] ?? '';
$cookieState = $_COOKIE['fvj_oauth_state'] ?? '';

$fehler = '';
$token = '';

if ($code === '' || $state === '' || !hash_equals($cookieState, $state)) {
    $fehler = 'Ungültige oder abgelaufene Anmeldung. Bitte das Fenster schliessen und erneut anmelden.';
} else {
    [$clientId, $clientSecret] = fvj_oauth_credentials();
    $ch = curl_init('https://github.com/login/oauth/access_token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: fvj-sveltia-auth'],
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'redirect_uri' => fvj_callback_url(),
        ]),
    ]);
    $antwort = curl_exec($ch);
    $curlFehler = curl_error($ch);
    curl_close($ch);

    $daten = is_string($antwort) ? json_decode($antwort, true) : null;
    if (!is_array($daten) || empty($daten['access_token'])) {
        $fehler = 'GitHub hat kein Token geliefert'
            . ($curlFehler ? " ($curlFehler)" : '')
            . (isset($daten['error_description']) ? ': ' . $daten['error_description'] : '') . '.';
    } else {
        $token = (string) $daten['access_token'];
    }
}

// Cookie aufräumen
setcookie('fvj_oauth_state', '', ['expires' => 1, 'path' => dirname($_SERVER['SCRIPT_NAME']), 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);

$status = $fehler === '' ? 'success' : 'error';
$payload = $fehler === ''
    ? ['token' => $token, 'provider' => 'github']
    : ['error' => $fehler, 'provider' => 'github'];
$nachricht = 'authorization:github:' . $status . ':' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$origins = json_encode(FVJ_ALLOWED_ORIGINS);
?>
<!doctype html>
<html lang="de-CH">
<head><meta charset="utf-8"><title>Anmeldung – Redaktion</title><meta name="robots" content="noindex,nofollow"></head>
<body style="font-family:system-ui,sans-serif;padding:2rem">
<p id="msg"><?= $fehler === '' ? 'Anmeldung erfolgreich – dieses Fenster schliesst sich gleich.' : htmlspecialchars($fehler, ENT_QUOTES, 'UTF-8') ?></p>
<script>
(function () {
  var message = <?= json_encode($nachricht, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  var allowed = <?= $origins ?>;
  if (!window.opener) { document.getElementById('msg').textContent += ' (Kein CMS-Fenster gefunden – bitte Anmeldung aus der Redaktion heraus starten.)'; return; }
  function receive(e) {
    if (allowed.indexOf(e.origin) === -1) return;
    window.opener.postMessage(message, e.origin);
    window.removeEventListener('message', receive);
    setTimeout(function () { window.close(); }, 300);
  }
  window.addEventListener('message', receive);
  allowed.forEach(function (o) { window.opener.postMessage('authorizing:github', o); });
})();
</script>
</body>
</html>
