<?php
declare(strict_types=1);

// Zweiter Schritt des Double-Opt-in: wird über den Link in der Bestätigungs-
// mail aufgerufen. Prüft die Signatur, prüft das Alter des Links (max. 48h)
// und verschickt erst dann die eigentliche Anmeldung an den Verein.
//
// Zielpostfach für Lead-Benachrichtigungen: anmeldung@ — die Verteilung an
// sekretariat@ und vorstand@ ist in Plesk als Weiterleitung hinterlegt.

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

require __DIR__ . '/_mitgliedschaft.php';

$payload = fwvj_anmeldung_entpacken($data, $signature);
if ($payload === null) {
    http_response_code(403);
    renderPage($texts['de']['invalidTitle'], $texts['de']['invalidSignature'], false);
    exit;
}

$locale = (isset($payload['locale']) && $payload['locale'] === 'en') ? 'en' : 'de';
$t = $texts[$locale];

if (time() - (int) $payload['ts'] > FWVJ_ANMELDUNG_MAX_ALTER) {
    http_response_code(410);
    renderPage($t['expiredTitle'], $t['expiredMessage'], false, $locale);
    exit;
}

$f = static fn (string $k): string => fwvj_feld((string) ($payload[$k] ?? ''), 200);
$leer = '(nicht angegeben)';
$zeile = static fn (string $label, string $wert): string => $label . ': ' . ($wert !== '' ? $wert : $leer) . "\n";

if (isset($payload['vorname'])) {
    // Format ab 23.11.2026
    $nameSafe = trim($f('vorname') . ' ' . $f('nachname'));
    $geb = DateTimeImmutable::createFromFormat('!Y-m-d', $f('geburtsdatum'));
    $angaben = $zeile('Vorname', $f('vorname'))
        . $zeile('Nachname', $f('nachname'))
        . $zeile('Firma', $f('firma'))
        . $zeile('Strasse / Nr.', $f('strasse'))
        . $zeile('PLZ / Ort', $f('plz_ort'))
        . $zeile('Geburtsdatum', $geb ? $geb->format('d.m.Y') : $f('geburtsdatum'))
        . $zeile('Telefon', $f('telefon'))
        . $zeile('E-Mail', $f('email'))
        . $zeile('Anzahl Gästebetten', $f('betten'))
        . 'Jahresbeitrag gemäss Angabe: CHF ' . ((int) $f('betten') >= 11 ? '200' : '100') . " (ab 11 Betten 200, sonst 100)\n\n"
        . 'Beitrittserklärung (Statuten, Jahresbeitrag, Vereinsjahr, Austritt schriftlich) und Datenschutzerklärung '
        . 'akzeptiert – Wortlaut Fassung ' . ($f('erklaerung') ?: '?') . ', Sprache ' . strtoupper($locale) . ".\n"
        . 'Formular abgeschickt: ' . date('d.m.Y H:i', (int) $payload['ts']) . "\n";
} else {
    // Altes Format (Links vor 23.11.2026, höchstens 48 h gültig)
    $nameSafe = $f('name');
    $angaben = $zeile('Name', $f('name'))
        . $zeile('Anzahl Gästebetten', $f('betten'))
        . $zeile('E-Mail', $f('email'))
        . $zeile('Telefon', $f('telefon'))
        . "(Anmeldung über das frühere Formular – ohne Adresse, Geburtsdatum und Beitrittserklärung)\n";
}
$emailSafe = $f('email');

// ── Mitgliederbereich: jede bestätigte Anmeldung wird dort als offene Zugangsanfrage erfasst
// (Stefan 23.11.2026). Das Sekretariat entscheidet mit EINEM Klick: aufnehmen = Zugang frei +
// Begrüssungsmail. Klappt das nicht (z. B. Datenbank nicht erreichbar), geht die Mail trotzdem
// raus – dann ohne Knöpfe, mit Hinweis auf den Admin-Reiter.
$knoepfe = null;      // [ja, nein] oder null
$zugangHinweis = '';
$doppelt = false;
try {
    define('FWVJ_MITGLIEDER', true);
    require_once __DIR__ . '/mitglieder/_lib.php';
    $emailNorm = email_norm($emailSafe);
    $pdo = db();
    $st = $pdo->prepare('SELECT status FROM mitglieder WHERE email = ?');
    $st->execute([$emailNorm]);
    $status = (string) ($st->fetchColumn() ?: '');
    // Link doppelt geklickt? Dann gibt es seit dem Absenden schon eine Anfrage → nichts doppelt.
    $st = $pdo->prepare("SELECT id FROM zugangsanfragen WHERE email = ? AND zeit >= ? AND angaben LIKE 'Neue Mitgliedschaft%'");
    $st->execute([$emailNorm, (int) $payload['ts']]);
    if ($st->fetchColumn()) {
        $doppelt = true;
    } elseif ($status === 'aktiv') {
        $zugangHinweis = 'Diese Adresse hat bereits Zugang zum Mitgliederbereich.';
    } else {
        $kurz = 'Neue Mitgliedschaft (Formular) · ' . $f('betten') . ' Betten' . ($f('plz_ort') !== '' ? ' · ' . $f('plz_ort') : '') . ($f('firma') !== '' ? ' · ' . $f('firma') : '');
        $nachname = isset($payload['vorname']) ? $f('nachname') : '';
        $vorname = isset($payload['vorname']) ? $f('vorname') : $f('name');
        $pdo->prepare('INSERT INTO zugangsanfragen (email, vorname, name, angaben, locale, zeit, ip) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$emailNorm, $vorname, $nachname, mb_substr($kurz, 0, 500), $locale, jetzt(), client_ip()]);
        $id = (int) $pdo->lastInsertId();
        audit('anfrage', null, null, "{$nameSafe}: {$kurz}", 0, $emailNorm);
        $knoepfe = [anfrage_link('annehmen', $id), anfrage_link('ablehnen', $id)];
        if ($status === 'gesperrt') {
            $zugangHinweis = 'ACHTUNG: Diese Adresse ist im Mitgliederbereich gesperrt.';
        }
    }
} catch (Throwable $e) {
    error_log('mitgliedschaft-bestaetigen: Zugangsanfrage nicht angelegt: ' . $e->getMessage());
    $zugangHinweis = 'Zugang zum Mitgliederbereich konnte nicht automatisch vorbereitet werden – bitte im Admin-Reiter von Hand freischalten.';
}

if ($doppelt) {
    renderPage($t['successTitle'], $t['successMessage'], true, $locale);
    exit;
}

$quelle = $locale === 'en' ? '/en/membership/' : '/mitgliedschaft/';
$leadSubject = 'Neue Mitglied-Anmeldung – ' . $nameSafe;
$leadBody = "Neue bestätigte Anmeldung über das Formular «Mitgliedschaft anfragen».\n"
    . "Die Person hat ihre E-Mail-Adresse über den Bestätigungslink bestätigt.\n\n"
    . $angaben
    . 'E-Mail bestätigt: ' . date('d.m.Y H:i') . "\n"
    . "Quelle: {$quelle}\n\n"
    . ($zugangHinweis !== '' ? $zugangHinweis . "\n\n" : '')
    . ($knoepfe
        ? "Aufnehmen und Zugang zum Mitgliederbereich freischalten (Person erhält eine Begrüssungsmail):\n{$knoepfe[0]}\n\nAblehnen:\n{$knoepfe[1]}\n\n"
            . 'Die Links sind ' . ANFRAGE_GUELTIG_TAGE . " Tage gültig; wer zuerst klickt, erledigt. Alternativ im Admin-Reiter des Mitgliederbereichs.\n\n"
        : '')
    . "Über die Aufnahme entscheidet der Vorstand (Art. 4 der Statuten).\n"
    . "Antworten auf diese Mail gehen direkt an die anmeldende Person.\n";

// anmeldung@ verteilt in Plesk an sekretariat@ + vorstand@; it@ zusätzlich direkt (Stefan 30.10.2026).
$empfaenger = 'anmeldung@ferienwohnungsverein-jungfrau.ch, it@ferienwohnungsverein-jungfrau.ch';

if (function_exists('mail_senden_html')) {
    $h = static fn (string $x): string => htmlspecialchars($x, ENT_QUOTES, 'UTF-8');
    $zeilen = '';
    foreach (explode("\n", trim($angaben)) as $z) {
        if ($z === '') {
            $zeilen .= '<tr><td colspan="2" style="height:10px;"></td></tr>';
        } elseif (preg_match('/^([^:]{1,40}): (.*)$/u', $z, $m) && !str_starts_with($z, 'Beitrittserklärung')) {
            $zeilen .= '<tr><td style="color:#666;padding:2px 16px 2px 0;vertical-align:top;white-space:nowrap;">' . $h($m[1]) . '</td><td style="padding:2px 0;"><strong>' . $h($m[2]) . '</strong></td></tr>';
        } else {
            $zeilen .= '<tr><td colspan="2" style="padding:2px 0;color:#444;font-size:13px;">' . $h($z) . '</td></tr>';
        }
    }
    $html = '<p style="margin:0 0 16px;"><strong>Neue Mitglied-Anmeldung</strong> – E-Mail-Adresse bestätigt am ' . date('d.m.Y H:i') . '</p>'
        . '<table role="presentation" style="margin:0 0 20px;font-size:15px;border-collapse:collapse;">' . $zeilen . '</table>'
        . ($zugangHinweis !== '' ? '<p style="margin:0 0 16px;color:#b91c1c;"><strong>' . $h($zugangHinweis) . '</strong></p>' : '')
        . ($knoepfe
            ? '<p style="margin:0 0 16px;">' . mail_knopf($knoepfe[0], '✓ Aufnehmen & freischalten') . mail_knopf($knoepfe[1], '✕ Ablehnen', false) . '</p>'
                . '<p style="margin:0 0 16px;color:#666666;font-size:13px;">«Aufnehmen» schaltet den Zugang zum Mitgliederbereich frei; die Person erhält automatisch eine Begrüssungsmail. Der Knopf öffnet zuerst eine Bestätigungsseite. Links ' . ANFRAGE_GUELTIG_TAGE . ' Tage gültig, wer zuerst klickt, erledigt – alternativ im <a href="' . $h(basis_url() . '/mitglieder/#admin') . '" style="color:#004D7A;">Admin-Reiter</a>.</p>'
            : '')
        . '<p style="margin:0;color:#666666;font-size:13px;">Über die Aufnahme entscheidet der Vorstand (Art. 4 der Statuten). Antworten auf diese Mail gehen direkt an die anmeldende Person. Quelle: ' . $h($quelle) . '</p>';
    $mailSent = mail_senden_html($empfaenger, $leadSubject, $leadBody, mail_html_rahmen($html), $emailSafe);
} else {
    // From-Adresse MUSS zur sendenden Domain passen (SPF), siehe mitgliedschaft-anmeldung.php.
    $leadHeaders = "From: Website Anmeldeformular <noreply@ferienwohnungsverein-jungfrau.ch>\r\n"
        . "Reply-To: {$emailSafe}\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n";
    $mailSent = @mail($empfaenger, '=?UTF-8?B?' . base64_encode($leadSubject) . '?=', $leadBody, $leadHeaders);
}

if (!$mailSent) {
    http_response_code(500);
    renderPage($t['sendErrorTitle'], $t['sendErrorMessage'], false, $locale);
    exit;
}

renderPage($t['successTitle'], $t['successMessage'], true, $locale);
