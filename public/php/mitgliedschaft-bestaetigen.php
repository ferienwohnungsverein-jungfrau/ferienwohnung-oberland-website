<?php
declare(strict_types=1);

// Zweiter Schritt des Double-Opt-in: wird über den Link in der Bestätigungs-
// mail aufgerufen. Prüft die Signatur, prüft das Alter des Links (max. 48h)
// und verschickt erst dann die eigentliche Anmeldung an den Verein.
//
// Zielpostfach für Lead-Benachrichtigungen: anmeldung@ — die Verteilung an
// sekretariat@ und vorstand@ ist in Plesk als Weiterleitung hinterlegt.

require_once __DIR__ . '/_seite.php';

/**
 * Rückmeldeseite – immer zweisprachig mit Umschalter (Stefan 23.11.2026). Startsprache:
 * ?l= aus dem geklickten Mail-Knopf, sonst die Sprache des Formulars.
 */
function renderPage(string $titelKey, string $textKey, bool $success, string $locale = 'de'): never
{
    global $texts;
    $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $teil = static fn (string $l): array => [
        'titel' => $texts[$l][$titelKey],
        'inhalt' => '<p>' . $h($texts[$l][$textKey]) . '</p><a class="btn" href="https://ferienwohnungsverein-jungfrau.ch' . ($l === 'en' ? '/en/' : '/') . '">'
            . ($l === 'en' ? 'Back to the website' : 'Zurück zur Website') . '</a>',
    ];
    fwvj_seite($teil('de'), $teil('en'), fwvj_seiten_sprache($locale), !$success);
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
    renderPage('invalidTitle', 'invalidIncomplete', false);
    exit;
}

require __DIR__ . '/_mitgliedschaft.php';

$payload = fwvj_anmeldung_entpacken($data, $signature);
if ($payload === null) {
    http_response_code(403);
    renderPage('invalidTitle', 'invalidSignature', false);
    exit;
}

$locale = (isset($payload['locale']) && $payload['locale'] === 'en') ? 'en' : 'de';
$t = $texts[$locale];

if (time() - (int) $payload['ts'] > FWVJ_ANMELDUNG_MAX_ALTER) {
    http_response_code(410);
    renderPage('expiredTitle', 'expiredMessage', false, $locale);
    exit;
}

$f = static fn (string $k): string => fwvj_feld((string) ($payload[$k] ?? ''), 200);

// Angaben als Zeilen [Bezeichnung DE, Bezeichnung EN, Wert] – daraus entsteht die Mail in beiden Sprachen.
if (isset($payload['vorname'])) {
    // Format ab 23.11.2026
    $nameSafe = trim($f('vorname') . ' ' . $f('nachname'));
    $geb = DateTimeImmutable::createFromFormat('!Y-m-d', $f('geburtsdatum'));
    $beitrag = (int) $f('betten') >= 11 ? '200' : '100';
    $felder = [
        ['Vorname', 'First name', $f('vorname')],
        ['Nachname', 'Last name', $f('nachname')],
        ['Firma', 'Company', $f('firma')],
        ['Strasse / Nr.', 'Street / no.', $f('strasse')],
        ['PLZ / Ort', 'Postcode / town', $f('plz_ort')],
        ['Geburtsdatum', 'Date of birth', $geb ? $geb->format('d.m.Y') : $f('geburtsdatum')],
        ['Telefon', 'Phone', $f('telefon')],
        ['E-Mail', 'Email', $f('email')],
        ['Anzahl Gästebetten', 'Guest beds', $f('betten')],
        ['Jahresbeitrag', 'Annual fee', "CHF {$beitrag}"],
    ];
    $fassung = $f('erklaerung') ?: '?';
    $sp = strtoupper($locale);
    $abgeschickt = date('d.m.Y H:i', (int) $payload['ts']);
    $notiz = [
        'de' => "Beitrittserklärung (Statuten, Jahresbeitrag, Vereinsjahr, Austritt schriftlich) und Datenschutzerklärung akzeptiert – Wortlaut Fassung {$fassung}, Formular {$sp}, abgeschickt {$abgeschickt}.",
        'en' => "Membership declaration (statutes, annual fee, association year, written resignation) and privacy policy accepted – wording version {$fassung}, form {$sp}, submitted {$abgeschickt}.",
    ];
} else {
    // Altes Format (Links vor 23.11.2026, höchstens 48 h gültig)
    $nameSafe = $f('name');
    $felder = [
        ['Name', 'Name', $f('name')],
        ['Anzahl Gästebetten', 'Guest beds', $f('betten')],
        ['E-Mail', 'Email', $f('email')],
        ['Telefon', 'Phone', $f('telefon')],
    ];
    $notiz = [
        'de' => 'Anmeldung über das frühere Formular – ohne Adresse, Geburtsdatum und Beitrittserklärung.',
        'en' => 'Submitted via the previous form – without address, date of birth and membership declaration.',
    ];
}
$emailSafe = $f('email');

// ── Mitgliederbereich: jede bestätigte Anmeldung wird dort als offene Zugangsanfrage erfasst
// (Stefan 23.11.2026). Das Sekretariat entscheidet mit EINEM Klick: aufnehmen = Zugang frei +
// Begrüssungsmail. Klappt das nicht (z. B. Datenbank nicht erreichbar), geht die Mail trotzdem
// raus – dann ohne Knöpfe, mit Hinweis auf den Admin-Reiter.
$knoepfe = null;      // [ja, nein] oder null
$zugangHinweis = ['de' => '', 'en' => ''];
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
        $zugangHinweis = ['de' => 'Diese Adresse hat bereits Zugang zum Mitgliederbereich.', 'en' => 'This address already has access to the members’ area.'];
    } else {
        $kurz = 'Neue Mitgliedschaft / New membership · ' . $f('betten') . ' Betten/beds' . ($f('plz_ort') !== '' ? ' · ' . $f('plz_ort') : '') . ($f('firma') !== '' ? ' · ' . $f('firma') : '');
        $nachname = isset($payload['vorname']) ? $f('nachname') : '';
        $vorname = isset($payload['vorname']) ? $f('vorname') : $f('name');
        $pdo->prepare('INSERT INTO zugangsanfragen (email, vorname, name, angaben, locale, zeit, ip) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$emailNorm, $vorname, $nachname, mb_substr($kurz, 0, 500), $locale, jetzt(), client_ip()]);
        $id = (int) $pdo->lastInsertId();
        audit('anfrage', null, null, "{$nameSafe}: {$kurz}", 0, $emailNorm);
        $knoepfe = [anfrage_link('annehmen', $id), anfrage_link('ablehnen', $id)];
        if ($status === 'gesperrt') {
            $zugangHinweis = ['de' => 'ACHTUNG: Diese Adresse ist im Mitgliederbereich gesperrt.', 'en' => 'WARNING: This address is blocked in the members’ area.'];
        }
    }
} catch (Throwable $e) {
    error_log('mitgliedschaft-bestaetigen: Zugangsanfrage nicht angelegt: ' . $e->getMessage());
    $zugangHinweis = ['de' => 'Zugang zum Mitgliederbereich konnte nicht automatisch vorbereitet werden – bitte im Admin-Reiter von Hand freischalten.', 'en' => 'Access to the members’ area could not be prepared automatically – please activate it manually in the Admin tab.'];
}

if ($doppelt) {
    renderPage('successTitle', 'successMessage', true, $locale);
    exit;
}

if (!defined('ANFRAGE_GUELTIG_TAGE_TEXT')) {
    define('ANFRAGE_GUELTIG_TAGE_TEXT', defined('ANFRAGE_GUELTIG_TAGE') ? (string) ANFRAGE_GUELTIG_TAGE : '14');
}
$quelle = $locale === 'en' ? '/en/membership/' : '/mitgliedschaft/';
$bestaetigt = date('d.m.Y H:i');
$leadSubject = 'Neue Mitglied-Anmeldung / New membership application – ' . $nameSafe;
// anmeldung@ verteilt in Plesk an sekretariat@ + vorstand@; it@ zusätzlich direkt (Stefan 30.10.2026).
$empfaenger = 'anmeldung@ferienwohnungsverein-jungfrau.ch, it@ferienwohnungsverein-jungfrau.ch';

$L = [
    'de' => [
        'kopf' => 'Neue Mitglied-Anmeldung', 'leer' => '(nicht angegeben)',
        'intro' => "Neue bestätigte Anmeldung über das Formular «Mitglied werden» – E-Mail-Adresse bestätigt am {$bestaetigt}.",
        'ja' => '✓ Aufnehmen & freischalten', 'nein' => '✕ Ablehnen',
        'jaText' => 'Aufnehmen und Zugang zum Mitgliederbereich freischalten (Person erhält eine Begrüssungsmail)',
        'neinText' => 'Ablehnen',
        'knopfInfo' => '«Aufnehmen» schaltet den Zugang zum Mitgliederbereich frei; die Person erhält automatisch eine Begrüssungsmail. Der Knopf öffnet zuerst eine Bestätigungsseite. Links ' . ANFRAGE_GUELTIG_TAGE_TEXT . ' Tage gültig, wer zuerst klickt, erledigt – alternativ im Admin-Reiter des Mitgliederbereichs.',
        'schluss' => 'Über die Aufnahme entscheidet der Vorstand (Art. 4 der Statuten). Antworten auf diese Mail gehen direkt an die anmeldende Person.',
        'admin' => '/mitglieder/#admin', 'adminText' => 'Admin-Reiter',
    ],
    'en' => [
        'kopf' => 'New membership application', 'leer' => '(not provided)',
        'intro' => "New confirmed application via the “Become a member” form – email address confirmed on {$bestaetigt}.",
        'ja' => '✓ Admit & activate', 'nein' => '✕ Decline',
        'jaText' => 'Admit and activate access to the members’ area (the person receives a welcome email)',
        'neinText' => 'Decline',
        'knopfInfo' => '“Admit” activates access to the members’ area; the person automatically receives a welcome email. The button first opens a confirmation page. Links valid for ' . ANFRAGE_GUELTIG_TAGE_TEXT . ' days, whoever clicks first handles it – or use the Admin tab of the members’ area.',
        'schluss' => 'The board decides on admission (Art. 4 of the statutes). Replies to this email go directly to the applicant.',
        'admin' => '/en/members/#admin', 'adminText' => 'Admin tab',
    ],
];
$linkL = static fn (string $url, string $l): string => $url . '&l=' . $l;

$text = static function (string $l) use ($L, $felder, $notiz, $zugangHinweis, $knoepfe, $quelle, $linkL): string {
    $t = $L[$l];
    $out = $t['intro'] . "\n\n";
    foreach ($felder as $z) {
        $out .= ($l === 'en' ? $z[1] : $z[0]) . ': ' . ($z[2] !== '' ? $z[2] : $t['leer']) . "\n";
    }
    $out .= "\n" . $notiz[$l] . "\n\n";
    if ($zugangHinweis[$l] !== '') {
        $out .= $zugangHinweis[$l] . "\n\n";
    }
    if ($knoepfe) {
        $out .= $t['jaText'] . ":\n" . $linkL($knoepfe[0], $l) . "\n\n" . $t['neinText'] . ":\n" . $linkL($knoepfe[1], $l) . "\n\n" . $t['knopfInfo'] . "\n\n";
    }
    return $out . $t['schluss'] . "\nQuelle / Source: {$quelle}\n";
};

if (function_exists('mail_senden_html')) {
    $h = static fn (string $x): string => htmlspecialchars($x, ENT_QUOTES, 'UTF-8');
    $html = static function (string $l) use ($L, $felder, $notiz, $zugangHinweis, $knoepfe, $quelle, $linkL, $h): string {
        $t = $L[$l];
        $zeilen = '';
        foreach ($felder as $z) {
            $zeilen .= '<tr><td style="color:#666;padding:2px 16px 2px 0;vertical-align:top;white-space:nowrap;">' . $h($l === 'en' ? $z[1] : $z[0])
                . '</td><td style="padding:2px 0;"><strong>' . $h($z[2] !== '' ? $z[2] : $t['leer']) . '</strong></td></tr>';
        }
        return '<p style="margin:0 0 16px;"><strong>' . $h($t['kopf']) . '</strong><br><span style="color:#666;font-size:13px;">' . $h($t['intro']) . '</span></p>'
            . '<table role="presentation" style="margin:0 0 12px;font-size:15px;border-collapse:collapse;">' . $zeilen . '</table>'
            . '<p style="margin:0 0 20px;color:#444;font-size:13px;">' . $h($notiz[$l]) . '</p>'
            . ($zugangHinweis[$l] !== '' ? '<p style="margin:0 0 16px;color:#b91c1c;"><strong>' . $h($zugangHinweis[$l]) . '</strong></p>' : '')
            . ($knoepfe
                ? '<p style="margin:0 0 16px;">' . mail_knopf($linkL($knoepfe[0], $l), $t['ja']) . mail_knopf($linkL($knoepfe[1], $l), $t['nein'], false) . '</p>'
                    . '<p style="margin:0 0 16px;color:#666666;font-size:13px;">' . $h($t['knopfInfo']) . ' <a href="' . $h(basis_url() . $t['admin']) . '" style="color:#004D7A;">' . $h($t['adminText']) . '</a></p>'
                : '')
            . '<p style="margin:0;color:#666666;font-size:13px;">' . $h($t['schluss']) . ' · ' . $h($quelle) . '</p>';
    };
    $mailSent = mail_senden_html($empfaenger, $leadSubject, zweisprachig_text($text('de'), $text('en')), mail_html_rahmen(zweisprachig_html($html('de'), $html('en'))), $emailSafe);
} else {
    // Ohne Mitgliederbereich-Bibliothek: schlichte Textmail. From MUSS zur Domain passen (SPF).
    $leadHeaders = "From: Website Anmeldeformular <noreply@ferienwohnungsverein-jungfrau.ch>\r\n"
        . "Reply-To: {$emailSafe}\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n";
    $mailSent = @mail($empfaenger, '=?UTF-8?B?' . base64_encode($leadSubject) . '?=', $text('de') . "\n--- English ---\n\n" . $text('en'), $leadHeaders);
}

if (!$mailSent) {
    http_response_code(500);
    renderPage('sendErrorTitle', 'sendErrorMessage', false, $locale);
    exit;
}

renderPage('successTitle', 'successMessage', true, $locale);
