<?php
declare(strict_types=1);

// Anfrageformular "Gewerbeangebote für Mitglieder" – Ferienwohnungsverein Jungfrau
// Die Anfrage geht ausschliesslich an sekretariat@. Das Sekretariat prüft, ob
// die anfragende Person Mitglied ist, und leitet die Anfrage erst dann an den
// Partnerbetrieb weiter. Bewusst KEINE Mail an die eingegebene Adresse – so kann
// niemand das Formular missbrauchen, um Mails an Dritte zu verschicken.

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://ferienwohnungsverein-jungfrau.ch');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Methode nicht erlaubt.']);
    exit;
}

// Partnerbetriebe – Schlüssel müssen mit den <option>-Werten im Formular übereinstimmen.
$partner = [
    'grueneisen' => ['name' => 'grüneisen küchenstudio ag', 'kontakt' => 'info@grueneisen-kuechen.ch'],
    'hobeda' => ['name' => 'Hobeda – Hotelbedarf Interlaken AG', 'kontakt' => 'info@hobeda.ch'],
];

$vorname = trim($_POST['vorname'] ?? '');
$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$telefon = trim($_POST['telefon'] ?? '');
$partnerKey = trim($_POST['partner'] ?? '');
$objekt = trim($_POST['objekt'] ?? '');
$bestellung = trim($_POST['bestellung'] ?? '');
$datenschutz = $_POST['datenschutz'] ?? '';
$honeypot = trim($_POST['website'] ?? '');
$locale = ($_POST['locale'] ?? 'de') === 'en' ? 'en' : 'de';

// Honeypot: verstecktes Feld, das nur Bots ausfüllen – Erfolg vortäuschen.
if ($honeypot !== '') {
    echo json_encode(['success' => true]);
    exit;
}

$messages = [
    'de' => [
        'missing' => 'Bitte füllen Sie alle Pflichtfelder aus.',
        'email' => 'Bitte geben Sie eine gültige E-Mail-Adresse ein.',
        'partner' => 'Bitte wählen Sie einen Partnerbetrieb aus.',
        'length' => 'Ihre Angaben sind zu lang. Bitte fassen Sie sich etwas kürzer.',
        'sendError' => 'Die Anfrage konnte nicht versendet werden. Bitte versuchen Sie es später erneut oder rufen Sie uns an.',
    ],
    'en' => [
        'missing' => 'Please fill in all required fields.',
        'email' => 'Please enter a valid email address.',
        'partner' => 'Please choose a partner business.',
        'length' => 'Your details are too long. Please keep it a little shorter.',
        'sendError' => 'The request could not be sent. Please try again later or call us.',
    ],
];
$m = $messages[$locale];

if ($vorname === '' || $name === '' || $email === '' || $objekt === '' || $bestellung === '' || $datenschutz === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $m['missing']]);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $m['email']]);
    exit;
}

if (!isset($partner[$partnerKey])) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $m['partner']]);
    exit;
}

if (mb_strlen($objekt) > 2000 || mb_strlen($bestellung) > 3000 || mb_strlen($vorname . $name . $telefon) > 300) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $m['length']]);
    exit;
}

// Kopfzeilen-Injection verhindern (nur einzeilige Felder; Freitext steht im Body)
$vornameSafe = str_replace(["\r", "\n"], '', $vorname);
$nameSafe = str_replace(["\r", "\n"], '', $name);
$emailSafe = str_replace(["\r", "\n"], '', $email);
$telefonSafe = str_replace(["\r", "\n"], '', $telefon);
$telefonAnzeige = $telefonSafe !== '' ? $telefonSafe : '(nicht angegeben)';
$p = $partner[$partnerKey];

$subject = 'Gewerbeangebot ' . $p['name'] . ': Anfrage von ' . $vornameSafe . ' ' . $nameSafe . ' – Mitgliedschaft prüfen';
$subjectEncoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';

$body = "Neue Anfrage über das Formular \"Gewerbeangebote für Mitglieder\".\n\n"
    . "BITTE PRÜFEN: Ist die anfragende Person Mitglied des Vereins?\n"
    . "Falls ja: Anfrage an den Partnerbetrieb weiterleiten ({$p['kontakt']}).\n"
    . "Falls nein: Person auf die Mitgliedschaft hinweisen (/mitgliedschaft/).\n\n"
    . "Partnerbetrieb: {$p['name']}\n\n"
    . "Vorname: {$vornameSafe}\n"
    . "Name: {$nameSafe}\n"
    . "E-Mail: {$emailSafe}\n"
    . "Telefon: {$telefonAnzeige}\n\n"
    . "Angaben zum Objekt:\n{$objekt}\n\n"
    . "Bestellung / Anliegen:\n{$bestellung}\n\n"
    . "Sprache: " . strtoupper($locale) . "\n"
    . "Eingegangen am: " . date('Y-m-d H:i:s') . "\n"
    . "Quelle: " . ($locale === 'en' ? '/en/membership/business-offers/' : '/mitgliedschaft/gewerbeangebote/') . "\n";

// From-Adresse MUSS zur sendenden Domain passen (SPF), siehe Kommentar in
// mitgliedschaft-anmeldung.php. Antworten gehen per Reply-To direkt an die anfragende Person.
$headers = "From: Website Gewerbeangebote <noreply@ferienwohnungsverein-jungfrau.ch>\r\n"
    . "Reply-To: {$emailSafe}\r\n"
    . "MIME-Version: 1.0\r\n"
    . "Content-Type: text/plain; charset=UTF-8\r\n";

$mailSent = @mail('sekretariat@ferienwohnungsverein-jungfrau.ch', $subjectEncoded, $body, $headers);

if (!$mailSent) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $m['sendError']]);
    exit;
}

echo json_encode(['success' => true]);
