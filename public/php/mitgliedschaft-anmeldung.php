<?php
declare(strict_types=1);

// Anmeldeformular "Mitglied werden" – Ferienwohnungsverein Jungfrau
// Double-Opt-in: dieses Skript verschickt nur den Bestätigungslink.
// Erst ein Klick auf den Link (mitgliedschaft-bestaetigen.php) löst die
// Weiterleitung der Anmeldung an den Verein aus – so ist sichergestellt,
// dass die angegebene E-Mail-Adresse wirklich der interessierten Person
// gehört, bevor wir eine Anfrage erhalten.
//
// Zielpostfach für Rückfragen/Antworten: anmeldung@ferienwohnungsverein-jungfrau.ch
//
// Härtung: Das echte Secret liegt in der nicht versionierten Datei
// form-signing-secret.key eine Ebene über httpdocs (oder alternativ in der
// Server-Umgebungsvariable FORM_SIGNING_SECRET). Fehlen beide, greift ein
// Platzhalter – dann sind Bestätigungslinks fälschbar.

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://ferienwohnungsverein-jungfrau.ch');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Methode nicht erlaubt.']);
    exit;
}

require __DIR__ . '/_mitgliedschaft.php';

$vorname = fwvj_feld((string) ($_POST['vorname'] ?? ''), 80);
$nachname = fwvj_feld((string) ($_POST['nachname'] ?? ''), 80);
$firma = fwvj_feld((string) ($_POST['firma'] ?? ''), 120);
$strasse = fwvj_feld((string) ($_POST['strasse'] ?? ''), 120);
$plzOrt = fwvj_feld((string) ($_POST['plz_ort'] ?? ''), 120);
$geburtsdatum = fwvj_feld((string) ($_POST['geburtsdatum'] ?? ''), 10);
$telefon = fwvj_feld((string) ($_POST['telefon'] ?? ''), 40);
$email = fwvj_feld((string) ($_POST['email'] ?? ''), 200);
$betten = fwvj_feld((string) ($_POST['betten'] ?? ''), 5);
$statuten = $_POST['statuten'] ?? '';
$datenschutz = $_POST['datenschutz'] ?? '';
$honeypot = trim((string) ($_POST['website'] ?? ''));
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
        'zustimmung' => 'Bitte bestätigen Sie die Statuten und die Datenschutzerklärung.',
        'email' => 'Bitte geben Sie eine gültige E-Mail-Adresse ein.',
        'betten' => 'Bitte geben Sie die Anzahl Betten als Zahl an.',
        'geburtsdatum' => 'Bitte geben Sie ein gültiges Geburtsdatum an.',
        'sendError' => 'E-Mail konnte nicht versendet werden. Bitte versuchen Sie es später erneut oder rufen Sie uns an.',
    ],
    'en' => [
        'missing' => 'Please fill in all required fields.',
        'zustimmung' => 'Please confirm the statutes and the privacy policy.',
        'email' => 'Please enter a valid email address.',
        'betten' => 'Please enter the number of beds as a number.',
        'geburtsdatum' => 'Please enter a valid date of birth.',
        'sendError' => 'The email could not be sent. Please try again later or call us.',
    ],
];
$m = $messages[$locale];

function fwvj_fehler(string $text): never
{
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $text]);
    exit;
}

// Firma ist freiwillig, alles andere Pflicht (Vorgabe Sekretariat 20.11.2026).
foreach ([$vorname, $nachname, $strasse, $plzOrt, $geburtsdatum, $telefon, $email, $betten] as $pflicht) {
    if ($pflicht === '') {
        fwvj_fehler($m['missing']);
    }
}
if ($statuten === '' || $datenschutz === '') {
    fwvj_fehler($m['zustimmung']);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwvj_fehler($m['email']);
}
if (!ctype_digit($betten) || (int) $betten < 1 || (int) $betten > 9999) {
    fwvj_fehler($m['betten']);
}
$geb = DateTimeImmutable::createFromFormat('!Y-m-d', $geburtsdatum);
if ($geb === false || $geb->format('Y-m-d') !== $geburtsdatum
    || $geb < new DateTimeImmutable('1900-01-01') || $geb > new DateTimeImmutable('today')) {
    fwvj_fehler($m['geburtsdatum']);
}

$nameSafe = trim($vorname . ' ' . $nachname);
$emailSafe = $email;

$payload = [
    'vorname' => $vorname,
    'nachname' => $nachname,
    'firma' => $firma,
    'strasse' => $strasse,
    'plz_ort' => $plzOrt,
    'geburtsdatum' => $geburtsdatum,
    'telefon' => $telefon,
    'email' => $email,
    'betten' => $betten,
    'erklaerung' => FWVJ_ERKLAERUNG_FASSUNG,
    'locale' => $locale,
    'ts' => time(),
];

try {
    [$data, $signature] = fwvj_anmeldung_verpacken($payload);
} catch (Throwable) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $m['sendError']]);
    exit;
}

$confirmUrl = 'https://ferienwohnungsverein-jungfrau.ch/php/mitgliedschaft-bestaetigen.php?d=' . rawurlencode($data) . '&s=' . $signature;
// Jede Sprache bekommt ihren eigenen Link (?l=), damit der englische Knopf auch englisch öffnet.
$confirmUrlDe = $confirmUrl . '&l=de';
$confirmUrlEn = $confirmUrl . '&l=en';
$confirmUrlHtml = htmlspecialchars($confirmUrlDe, ENT_QUOTES, 'UTF-8');
$confirmUrlHtmlEn = htmlspecialchars($confirmUrlEn, ENT_QUOTES, 'UTF-8');
$nameHtml = htmlspecialchars($nameSafe, ENT_QUOTES, 'UTF-8');

// Zweisprachig (Stefan 04.11.2026): Deutsch oben, Englisch darunter.
$subject = '=?UTF-8?B?' . base64_encode('Bitte bestätigen Sie Ihre Anmeldung / Please confirm your application – Ferienwohnungsverein Jungfrau') . '?=';
$textBody = "English version below.\n\n"
    . "Guten Tag {$nameSafe}\n\n"
    . "vielen Dank für Ihr Interesse an einer Mitgliedschaft beim Ferienwohnungsverein Jungfrau.\n\n"
    . "Bitte bestätigen Sie Ihre Anmeldung über den folgenden Link, damit wir Ihre Anfrage erhalten:\n{$confirmUrlDe}\n\n"
    . "Der Link ist 48 Stunden gültig.\n\n"
    . "Freundliche Grüsse\nFerienwohnungsverein Jungfrau\n\n"
    . "--- English ---\n\n"
    . "Hello {$nameSafe}\n\n"
    . "Thank you for your interest in becoming a member of the Ferienwohnungsverein Jungfrau.\n\n"
    . "Please confirm your application via the following link, so that we receive your request:\n{$confirmUrlEn}\n\n"
    . "This link is valid for 48 hours.\n\n"
    . "Kind regards\nFerienwohnungsverein Jungfrau\nhttps://ferienwohnungsverein-jungfrau.ch\n";
$htmlLang = 'de-CH';
$knopfStil = 'background:#F4A261;color:#1e293b;text-decoration:none;font-weight:700;padding:14px 28px;border-radius:6px;display:inline-block;';
$htmlInhalt = '<p style="margin:0 0 20px;color:#666666;font-size:13px;">English version below ↓</p>'
    . "<p style=\"margin:0 0 16px;\">Guten Tag {$nameHtml}</p>"
    . '<p style="margin:0 0 24px;">Vielen Dank für Ihr Interesse an einer Mitgliedschaft beim Ferienwohnungsverein Jungfrau. Bitte bestätigen Sie Ihre Anmeldung, damit wir Ihre Anfrage erhalten:</p>'
    . "<p style=\"margin:0 0 16px;text-align:center;\"><a href=\"{$confirmUrlHtml}\" style=\"{$knopfStil}\">Anmeldung bestätigen</a></p>"
    . '<p style="margin:0 0 16px;color:#666666;font-size:13px;">Der Link ist 48 Stunden gültig.</p>'
    . '<hr style="border:0;border-top:1px solid #e3e7eb;margin:28px 0 20px;">'
    . '<p style="margin:0 0 12px;color:#666666;font-size:12px;letter-spacing:.08em;text-transform:uppercase;">English</p>'
    . "<p style=\"margin:0 0 16px;\">Hello {$nameHtml}</p>"
    . '<p style="margin:0 0 24px;">Thank you for your interest in becoming a member of the Ferienwohnungsverein Jungfrau. Please confirm your application, so that we receive your request:</p>'
    . "<p style=\"margin:0 0 16px;text-align:center;\"><a href=\"{$confirmUrlHtmlEn}\" style=\"{$knopfStil}\">Confirm application</a></p>"
    . '<p style="margin:0 0 16px;color:#666666;font-size:13px;">This link is valid for 48 hours.</p>'
    . '<p style="margin:0;color:#666666;font-size:13px;">Freundliche Grüsse · Kind regards<br>Ferienwohnungsverein Jungfrau<br><a href="https://ferienwohnungsverein-jungfrau.ch" style="color:#004D7A;">ferienwohnungsverein-jungfrau.ch</a></p>';

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
              {$htmlInhalt}
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
    . "Reply-To: anmeldung@ferienwohnungsverein-jungfrau.ch\r\n"
    . "MIME-Version: 1.0\r\n"
    . "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";

// base64: Mailserver brechen Zeilen über 998 Zeichen um – am 23.11.2026 mitten im englischen
// Bestätigungslink, der dadurch ungültig wurde. base64 liefert kurze Zeilen, der Link bleibt ganz.
$message = "--{$boundary}\r\n"
    . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
    . chunk_split(base64_encode($textBody)) . "\r\n"
    . "--{$boundary}\r\n"
    . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
    . chunk_split(base64_encode($htmlBody)) . "\r\n"
    . "--{$boundary}--";

$mailSent = @mail($emailSafe, $subject, $message, $headers);

if (!$mailSent) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $m['sendError']]);
    exit;
}

echo json_encode(['success' => true]);
