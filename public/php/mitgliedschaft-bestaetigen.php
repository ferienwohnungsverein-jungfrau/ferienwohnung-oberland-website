<?php
declare(strict_types=1);

// Zweiter Schritt des Double-Opt-in: wird über den Link in der Bestätigungs-
// mail aufgerufen. Prüft die Signatur, prüft das Alter des Links (max. 48h)
// und verschickt erst dann die eigentliche Anmeldung an den Verein.
//
// TESTPHASE: Zielpostfach ist aktuell office@surfershome.ch statt der
// echten Vereinsadresse.

function renderPage(string $title, string $message, bool $success, string $locale = 'de'): void
{
    $titleHtml = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $messageHtml = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $accent = $success ? '#004D7A' : '#dc2626';
    $htmlLang = $locale === 'en' ? 'en' : 'de-CH';
    $backLabel = $locale === 'en' ? 'Back to the website' : 'Zurück zur Website';
    $backHref = $locale === 'en' ? 'https://ferienwohnungsverein-jungfrau.ch/en/' : 'https://ferienwohnungsverein-jungfrau.ch/';
    echo <<<HTML
<!doctype html>
<html lang="{$htmlLang}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$titleHtml} – Ferienwohnungsverein Jungfrau</title>
<style>
  body { margin:0; font-family: 'Open Sans', Arial, sans-serif; background:#F9F9F9; color:#334155; }
  .wrap { max-width:520px; margin:64px auto; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 8px 24px rgba(0,0,0,0.08); }
  .head { background:#004D7A; color:#fff; padding:24px 32px; font-family: 'Poppins', Arial, sans-serif; font-weight:700; font-size:18px; }
  .body { padding:32px; }
  h1 { color:{$accent}; font-family: 'Poppins', Arial, sans-serif; font-size:1.5rem; margin:0 0 16px; }
  a.btn { display:inline-block; margin-top:16px; background:#F4A261; color:#fff; text-decoration:none; font-weight:700; padding:12px 24px; border-radius:6px; }
</style>
</head>
<body>
  <div class="wrap">
    <div class="head">Ferienwohnungsverein Jungfrau</div>
    <div class="body">
      <h1>{$titleHtml}</h1>
      <p>{$messageHtml}</p>
      <a class="btn" href="{$backHref}">{$backLabel}</a>
    </div>
  </div>
</body>
</html>
HTML;
}

$texts = [
    'de' => [
        'invalidTitle' => 'Ungültiger Link',
        'invalidIncomplete' => 'Dieser Bestätigungslink ist unvollständig. Bitte fordern Sie die Anmeldung erneut an.',
        'invalidSignature' => 'Dieser Bestätigungslink konnte nicht verifiziert werden. Bitte fordern Sie die Anmeldung erneut an.',
        'invalidPayload' => 'Dieser Bestätigungslink konnte nicht gelesen werden. Bitte fordern Sie die Anmeldung erneut an.',
        'expiredTitle' => 'Link abgelaufen',
        'expiredMessage' => 'Dieser Bestätigungslink ist älter als 48 Stunden und nicht mehr gültig. Bitte fordern Sie die Anmeldung erneut an.',
        'sendErrorTitle' => 'Fehler beim Versand',
        'sendErrorMessage' => 'Ihre Bestätigung wurde erkannt, aber die Anmeldung konnte nicht übermittelt werden. Bitte kontaktieren Sie uns direkt.',
        'successTitle' => 'Vielen Dank!',
        'successMessage' => 'Ihre Anmeldung wurde bestätigt und an uns übermittelt. Wir melden uns bei Ihnen.',
    ],
    'en' => [
        'invalidTitle' => 'Invalid Link',
        'invalidIncomplete' => 'This confirmation link is incomplete. Please submit the application again.',
        'invalidSignature' => 'This confirmation link could not be verified. Please submit the application again.',
        'invalidPayload' => 'This confirmation link could not be read. Please submit the application again.',
        'expiredTitle' => 'Link Expired',
        'expiredMessage' => 'This confirmation link is older than 48 hours and no longer valid. Please submit the application again.',
        'sendErrorTitle' => 'Error Sending',
        'sendErrorMessage' => 'Your confirmation was recognised, but the application could not be submitted. Please contact us directly.',
        'successTitle' => 'Thank You!',
        'successMessage' => 'Your application has been confirmed and submitted to us. We will get back to you.',
    ],
];

$data = $_GET['d'] ?? '';
$signature = $_GET['s'] ?? '';

if ($data === '' || $signature === '') {
    http_response_code(400);
    renderPage($texts['de']['invalidTitle'], $texts['de']['invalidIncomplete'], false);
    exit;
}

$secret = getenv('FORM_SIGNING_SECRET') ?: 'ferienwohnungsverein-jungfrau-platzhalter-secret';
$expectedSignature = hash_hmac('sha256', $data, $secret);

if (!hash_equals($expectedSignature, $signature)) {
    http_response_code(403);
    renderPage($texts['de']['invalidTitle'], $texts['de']['invalidSignature'], false);
    exit;
}

$json = base64_decode(strtr($data, '-_', '+/'), true);
$payload = $json !== false ? json_decode($json, true) : null;

if (!is_array($payload) || !isset($payload['name'], $payload['betten'], $payload['email'], $payload['telefon'], $payload['ts'])) {
    http_response_code(400);
    renderPage($texts['de']['invalidTitle'], $texts['de']['invalidPayload'], false);
    exit;
}

$locale = (isset($payload['locale']) && $payload['locale'] === 'en') ? 'en' : 'de';
$t = $texts[$locale];

$maxAgeSeconds = 48 * 60 * 60;
if (time() - (int) $payload['ts'] > $maxAgeSeconds) {
    http_response_code(410);
    renderPage($t['expiredTitle'], $t['expiredMessage'], false, $locale);
    exit;
}

$nameSafe = str_replace(["\r", "\n"], '', (string) $payload['name']);
$bettenSafe = str_replace(["\r", "\n"], '', (string) $payload['betten']);
$emailSafe = str_replace(["\r", "\n"], '', (string) $payload['email']);
$telefonSafe = str_replace(["\r", "\n"], '', (string) $payload['telefon']);

$leadSubject = 'Neue Mitglied-Anmeldung – ' . $nameSafe;
$leadBody = "Neue bestätigte Anmeldung über das Formular \"Mitglied werden\":\n\n"
    . "Name: {$nameSafe}\n"
    . "Anzahl Betten: {$bettenSafe}\n"
    . "E-Mail: {$emailSafe}\n"
    . "Telefon: {$telefonSafe}\n"
    . "Sprache: " . strtoupper($locale) . "\n"
    . "Bestätigt am: " . date('Y-m-d H:i:s') . "\n"
    . "Quelle: " . ($locale === 'en' ? '/en/membership/' : '/mitgliedschaft/') . "\n";

$leadHeaders = "From: Website Anmeldeformular <office@surfershome.ch>\r\n"
    . "Reply-To: {$emailSafe}\r\n"
    . "Content-Type: text/plain; charset=UTF-8\r\n";

$mailSent = @mail('office@surfershome.ch', $leadSubject, $leadBody, $leadHeaders);

if (!$mailSent) {
    http_response_code(500);
    renderPage($t['sendErrorTitle'], $t['sendErrorMessage'], false, $locale);
    exit;
}

renderPage($t['successTitle'], $t['successMessage'], true, $locale);
