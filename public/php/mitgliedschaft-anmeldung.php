<?php
declare(strict_types=1);

// Anmeldeformular "Mitglied werden" – Ferienwohnungsverein Jungfrau
// Double-Opt-in: dieses Skript verschickt nur den Bestätigungslink.
// Erst ein Klick auf den Link (mitgliedschaft-bestaetigen.php) löst die
// Weiterleitung der Anmeldung an den Verein aus – so ist sichergestellt,
// dass die angegebene E-Mail-Adresse wirklich der interessierten Person
// gehört, bevor wir eine Anfrage erhalten.
//
// Zielpostfach für Rückfragen/Antworten: it@ferienwohnungsverein-jungfrau.ch
//
// TODO (bewusst zurückgestellt, siehe Konversation vom 2026-07-06):
// Für die Signatur des Bestätigungslinks sollte ein echtes Secret über die
// Server-Umgebungsvariable FORM_SIGNING_SECRET gesetzt werden (z.B. via
// Hosttech-Panel oder einer nicht versionierten .htaccess/php.ini). Ohne
// gesetzte Umgebungsvariable wird ein Platzhalter-Secret verwendet – das
// funktioniert, ist aber vor dem produktiven Realbetrieb zu härten.

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://ferienwohnungsverein-jungfrau.ch');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Methode nicht erlaubt.']);
    exit;
}

$name = trim($_POST['name'] ?? '');
$betten = trim($_POST['betten'] ?? '');
$email = trim($_POST['email'] ?? '');
$telefon = trim($_POST['telefon'] ?? '');
$datenschutz = $_POST['datenschutz'] ?? '';
$honeypot = trim($_POST['website'] ?? '');
$locale = ($_POST['locale'] ?? 'de') === 'en' ? 'en' : 'de';

// Honeypot: verstecktes Feld, das nur Bots ausfüllen. Wenn befüllt, Formular
// still verwerfen (Erfolg vortäuschen), damit der Bot nichts merkt.
if ($honeypot !== '') {
    echo json_encode(['success' => true]);
    exit;
}

$messages = [
    'de' => [
        'missing' => 'Bitte füllen Sie alle Pflichtfelder aus.',
        'email' => 'Bitte geben Sie eine gültige E-Mail-Adresse ein.',
        'betten' => 'Bitte geben Sie die Anzahl Betten als Zahl an.',
        'sendError' => 'E-Mail konnte nicht versendet werden. Bitte versuchen Sie es später erneut oder rufen Sie uns an.',
    ],
    'en' => [
        'missing' => 'Please fill in all required fields.',
        'email' => 'Please enter a valid email address.',
        'betten' => 'Please enter the number of beds as a number.',
        'sendError' => 'The email could not be sent. Please try again later or call us.',
    ],
];
$m = $messages[$locale];

if ($name === '' || $betten === '' || $email === '' || $telefon === '' || $datenschutz === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $m['missing']]);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $m['email']]);
    exit;
}

if (!ctype_digit($betten) || (int) $betten < 1) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $m['betten']]);
    exit;
}

// Kopfzeilen-Injection verhindern
$nameSafe = str_replace(["\r", "\n"], '', $name);
$emailSafe = str_replace(["\r", "\n"], '', $email);
$telefonSafe = str_replace(["\r", "\n"], '', $telefon);
$bettenSafe = str_replace(["\r", "\n"], '', $betten);

$secret = getenv('FORM_SIGNING_SECRET') ?: 'ferienwohnungsverein-jungfrau-platzhalter-secret';

$payload = [
    'name' => $nameSafe,
    'betten' => $bettenSafe,
    'email' => $emailSafe,
    'telefon' => $telefonSafe,
    'locale' => $locale,
    'ts' => time(),
];

$data = rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=');
$signature = hash_hmac('sha256', $data, $secret);

$confirmUrl = "https://ferienwohnungsverein-jungfrau.ch/php/mitgliedschaft-bestaetigen.php?d={$data}&s={$signature}";
$confirmUrlHtml = htmlspecialchars($confirmUrl, ENT_QUOTES, 'UTF-8');
$nameHtml = htmlspecialchars($nameSafe, ENT_QUOTES, 'UTF-8');

if ($locale === 'en') {
    $subject = 'Please confirm your application – Ferienwohnungsverein Jungfrau';
    $textBody = "Hello {$nameSafe}\n\n"
        . "Thank you for your interest in becoming a member of the Ferienwohnungsverein Jungfrau.\n\n"
        . "Please confirm your application via the following link, so that we receive your request:\n{$confirmUrl}\n\n"
        . "This link is valid for 48 hours.\n\n"
        . "Kind regards\nFerienwohnungsverein Jungfrau\nhttps://ferienwohnungsverein-jungfrau.ch\n";
    $htmlLang = 'en';
    $htmlGreeting = "Hello {$nameHtml}";
    $htmlIntro = 'Thank you for your interest in becoming a member of the Ferienwohnungsverein Jungfrau. Please confirm your application, so that we receive your request:';
    $htmlCta = 'Confirm application';
    $htmlValidity = 'This link is valid for 48 hours.';
    $htmlSignoff = 'Kind regards';
} else {
    $subject = 'Bitte bestätigen Sie Ihre Anmeldung – Ferienwohnungsverein Jungfrau';
    $textBody = "Guten Tag {$nameSafe}\n\n"
        . "vielen Dank für Ihr Interesse an einer Mitgliedschaft beim Ferienwohnungsverein Jungfrau.\n\n"
        . "Bitte bestätigen Sie Ihre Anmeldung über den folgenden Link, damit wir Ihre Anfrage erhalten:\n{$confirmUrl}\n\n"
        . "Der Link ist 48 Stunden gültig.\n\n"
        . "Freundliche Grüsse\nFerienwohnungsverein Jungfrau\nhttps://ferienwohnungsverein-jungfrau.ch\n";
    $htmlLang = 'de-CH';
    $htmlGreeting = "Guten Tag {$nameHtml}";
    $htmlIntro = 'Vielen Dank für Ihr Interesse an einer Mitgliedschaft beim Ferienwohnungsverein Jungfrau. Bitte bestätigen Sie Ihre Anmeldung, damit wir Ihre Anfrage erhalten:';
    $htmlCta = 'Anmeldung bestätigen';
    $htmlValidity = 'Der Link ist 48 Stunden gültig.';
    $htmlSignoff = 'Freundliche Grüsse';
}

$htmlBody = <<<HTML
<!doctype html>
<html lang="{$htmlLang}">
<body style="margin:0;padding:0;background:#F9F9F9;font-family:Arial,Helvetica,sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F9F9F9;padding:32px 16px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" style="max-width:520px;background:#ffffff;border-radius:12px;overflow:hidden;">
          <tr>
            <td style="background:#004D7A;padding:24px 32px;">
              <span style="color:#ffffff;font-size:18px;font-weight:700;letter-spacing:0.02em;">Ferienwohnungsverein Jungfrau</span>
            </td>
          </tr>
          <tr>
            <td style="padding:32px;color:#1a1a1a;font-size:15px;line-height:1.6;">
              <p style="margin:0 0 16px;">{$htmlGreeting}</p>
              <p style="margin:0 0 24px;">{$htmlIntro}</p>
              <p style="margin:0 0 24px;text-align:center;">
                <a href="{$confirmUrlHtml}" style="background:#F4A261;color:#ffffff;text-decoration:none;font-weight:700;padding:14px 28px;border-radius:6px;display:inline-block;">{$htmlCta}</a>
              </p>
              <p style="margin:0 0 16px;color:#666666;font-size:13px;">{$htmlValidity}</p>
              <p style="margin:0;color:#666666;font-size:13px;">{$htmlSignoff}<br>Ferienwohnungsverein Jungfrau<br><a href="https://ferienwohnungsverein-jungfrau.ch" style="color:#004D7A;">ferienwohnungsverein-jungfrau.ch</a></p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;

// From-Adresse MUSS zur sendenden Domain (ferienwohnungsverein-jungfrau.ch)
// passen, sonst schlägt der SPF-Check beim Empfänger fehl und die Mail wird
// von vielen Providern (Gmail etc.) stillschweigend verworfen statt
// zugestellt. Antworten laufen über Reply-To an die Vereinsadresse.
$boundary = 'fvj-' . bin2hex(random_bytes(8));
$headers = "From: Ferienwohnungsverein Jungfrau <noreply@ferienwohnungsverein-jungfrau.ch>\r\n"
    . "Reply-To: it@ferienwohnungsverein-jungfrau.ch\r\n"
    . "MIME-Version: 1.0\r\n"
    . "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";

$message = "--{$boundary}\r\n"
    . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
    . $textBody . "\r\n"
    . "--{$boundary}\r\n"
    . "Content-Type: text/html; charset=UTF-8\r\n\r\n"
    . $htmlBody . "\r\n"
    . "--{$boundary}--";

$mailSent = @mail($emailSafe, $subject, $message, $headers);

if (!$mailSent) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $m['sendError']]);
    exit;
}

echo json_encode(['success' => true]);
