<?php
declare(strict_types=1);

// Mitgliederbereich – JSON-API. Aufruf: /php/mitglieder/api.php?aktion=<name>
// Die Seiten /mitglieder/ und /en/members/ sind statische Hüllen; alles Dynamische
// läuft hierüber. Lesen = GET, Schreiben = POST mit Header X-CSRF-Token.

define('FWVJ_MITGLIEDER', true);
require __DIR__ . '/_lib.php';
require __DIR__ . '/_pruefung.php';

header('X-Robots-Tag: noindex, nofollow');

try {
    aufraeumen();
    $aktion = (string) ($_GET['aktion'] ?? '');
    match ($aktion) {
        'ich' => aktion_ich(),
        'code_anfordern' => aktion_code_anfordern(),
        'code_pruefen' => aktion_code_pruefen(),
        'zugang_beantragen' => aktion_zugang_beantragen(),
        'admin_anfragen' => aktion_admin_anfragen(),
        'admin_anfrage' => aktion_admin_anfrage(),
        'abmelden' => aktion_abmelden(),
        'liste' => aktion_liste(),
        'hochladen' => aktion_hochladen(),
        'aendern' => aktion_aendern(),
        'loeschen' => aktion_loeschen(),
        'melden' => aktion_melden(),
        'admin_dokument' => aktion_admin_dokument(),
        'admin_mitglieder' => aktion_admin_mitglieder(),
        'admin_mitglied_speichern' => aktion_admin_mitglied_speichern(),
        'admin_mitglied_status' => aktion_admin_mitglied_status(),
        'admin_audit' => aktion_admin_audit(),
        'admin_ip_sperren' => aktion_admin_ip_sperren(),
        'admin_ip_sperre_setzen' => aktion_admin_ip_sperre_setzen(),
        'admin_ip_sperre_aufheben' => aktion_admin_ip_sperre_aufheben(),
        'admin_einstellung' => aktion_admin_einstellung(),
        default => fehler('Unbekannte Aktion.', 'Unknown action.', 404),
    };
} catch (Throwable $e) {
    error_log('[fwvj-mitglieder] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    antwort(['success' => false, 'message' => sprache() === 'en' ? 'Server error. Please try again later.' : 'Serverfehler. Bitte später nochmals versuchen.'], 500);
}

// ── Anmeldung ──

function aktion_ich(): never
{
    $m = sitzung();
    if (!$m) {
        antwort(['success' => true, 'angemeldet' => false]);
    }
    antwort([
        'success' => true,
        'angemeldet' => true,
        'csrf' => $m['csrf'],
        'ich' => [
            'id' => (int) $m['id'],
            'email' => $m['email'],
            'vorname' => $m['vorname'],
            'name' => $m['name'],
            'admin' => $m['rolle'] === 'admin',
        ],
        'limits' => ['max_bytes' => MAX_DATEI_BYTES, 'max_bild_seite' => MAX_BILD_PIXEL_SEITE],
    ]);
}

function aktion_code_anfordern(): never
{
    post_pruefen(false);
    $email = email_norm((string) ($_POST['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 200) {
        fehler('Bitte gib eine gültige E-Mail-Adresse ein.', 'Please enter a valid email address.');
    }
    $ip = client_ip();
    // Gleiche Antwort für alle Adressen – verrät nicht, wer Mitglied ist.
    $gleich = fn() => antwort([
        'success' => true,
        'message' => sprache() === 'en'
            ? 'If this address is activated, you will receive a 6-digit code by email within a few minutes. Not activated yet? Write to ' . FWVJ_IT_ADRESSE . '.'
            : 'Wenn diese Adresse freigeschaltet ist, erhältst du in wenigen Minuten einen 6-stelligen Code per E-Mail. Noch nicht freigeschaltet? Schreib an ' . FWVJ_IT_ADRESSE . '.',
    ]);

    if (ip_gesperrt($ip)) {
        audit('code_gesperrte_ip', null, null, 'Adresse: ' . $email, 0, $email);
        $gleich();
    }
    if (anzahl('code', 'ip:' . $ip, 3600) >= LIMIT_CODE_IP_STUNDE
        || anzahl_verschieden('code_adr', 'ip:' . $ip, 3600) >= LIMIT_CODE_ADRESSEN_JE_IP_STUNDE) {
        alarm('codes-ip-' . $ip, 'viele Login-Codes von einer IP', "Von der IP {$ip} wurden in der letzten Stunde Login-Codes für viele verschiedene Adressen angefordert (zuletzt: {$email}).");
        fehler('Zu viele Anfragen. Bitte warte eine Stunde.', 'Too many requests. Please wait an hour.', 429);
    }
    if (anzahl('code', 'adr:' . $email, 900) >= LIMIT_CODE_ADRESSE_15MIN || anzahl('code', 'adr:' . $email, 86400) >= LIMIT_CODE_ADRESSE_TAG) {
        fehler('Für diese Adresse wurden gerade mehrere Codes angefordert. Bitte prüfe dein Postfach (auch Spam) oder warte 15 Minuten.', 'Several codes were just requested for this address. Please check your inbox (and spam) or wait 15 minutes.', 429);
    }
    ereignis('code', 'ip:' . $ip);
    ereignis('code_adr', 'ip:' . $ip, $email);
    ereignis('code', 'adr:' . $email);

    $st = db()->prepare("SELECT * FROM mitglieder WHERE email = ? AND status = 'aktiv'");
    $st->execute([$email]);
    $m = $st->fetch();
    if (!$m) {
        usleep(random_int(150000, 450000)); // Antwortzeit angleichen
        audit('code_unbekannt', null, null, '', 0, $email);
        $gleich();
    }

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $pdo = db();
    $pdo->prepare('UPDATE login_codes SET verbraucht = 1 WHERE email = ? AND verbraucht = 0')->execute([$email]);
    $pdo->prepare('INSERT INTO login_codes (email, code_hash, erstellt, ablauf) VALUES (?, ?, ?, ?)')
        ->execute([$email, hash_hmac('sha256', $email . '|' . $code, secret()), jetzt(), jetzt() + CODE_GUELTIG_SEK]);

    $hallo = trim((string) $m['vorname']) !== '' ? $m['vorname'] : '';
    if (sprache() === 'en') {
        $betreff = "{$code} is your code for the members' area";
        $text = "Hello {$hallo}\n\nYour sign-in code for the members' area of the Ferienwohnungsverein Jungfrau:\n\n    {$code}\n\n"
            . "The code is valid for 10 minutes. If you did not request it, you can ignore this email.\n\n"
            . "Ferienwohnungsverein Jungfrau\n" . basis_url() . "/en/members/\n";
    } else {
        $betreff = "{$code} ist dein Code für den Mitgliederbereich";
        $text = "Hallo {$hallo}\n\nDein Anmeldecode für den Mitgliederbereich des Ferienwohnungsvereins Jungfrau:\n\n    {$code}\n\n"
            . "Der Code ist 10 Minuten gültig. Falls du ihn nicht angefordert hast, kannst du diese Mail ignorieren.\n\n"
            . "Ferienwohnungsverein Jungfrau\n" . basis_url() . "/mitglieder/\n";
    }
    mail_senden($email, $betreff, $text);
    audit('code_gesendet', $m);
    $gleich();
}

function aktion_code_pruefen(): never
{
    post_pruefen(false);
    $email = email_norm((string) ($_POST['email'] ?? ''));
    $code = preg_replace('/\D/', '', (string) ($_POST['code'] ?? '')) ?? '';
    $ip = client_ip();
    $falsch = fn() => fehler('Der Code ist falsch oder abgelaufen. Fordere bei Bedarf einen neuen an.', 'The code is wrong or has expired. Request a new one if needed.', 422);

    if (ip_gesperrt($ip) || anzahl('fehlcode', 'ip:' . $ip, 3600) >= LIMIT_FEHLCODES_IP_STUNDE) {
        fehler('Zu viele Versuche. Bitte warte eine Stunde.', 'Too many attempts. Please wait an hour.', 429);
    }
    if (strlen($code) !== 6 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $falsch();
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM login_codes WHERE email = ? AND verbraucht = 0 AND ablauf > ? ORDER BY id DESC LIMIT 1');
    $st->execute([$email, jetzt()]);
    $row = $st->fetch();
    if (!$row || (int) $row['versuche'] >= CODE_MAX_VERSUCHE) {
        ereignis('fehlcode', 'ip:' . $ip);
        $falsch();
    }
    if (!hash_equals((string) $row['code_hash'], hash_hmac('sha256', $email . '|' . $code, secret()))) {
        $pdo->prepare('UPDATE login_codes SET versuche = versuche + 1 WHERE id = ?')->execute([$row['id']]);
        ereignis('fehlcode', 'ip:' . $ip);
        audit('code_falsch', null, null, 'Versuch ' . ((int) $row['versuche'] + 1), 0, $email);
        $falsch();
    }
    $st = $pdo->prepare("SELECT * FROM mitglieder WHERE email = ? AND status = 'aktiv'");
    $st->execute([$email]);
    $m = $st->fetch();
    if (!$m) {
        $falsch();
    }
    $pdo->prepare('UPDATE login_codes SET verbraucht = 1 WHERE id = ?')->execute([$row['id']]);
    $pdo->prepare('UPDATE mitglieder SET letzte_anmeldung = ? WHERE id = ?')->execute([jetzt(), $m['id']]);
    sitzung_setzen((int) $m['id']);
    audit('anmeldung', $m);
    antwort(['success' => true]);
}

function aktion_abmelden(): never
{
    post_pruefen(false);
    $m = sitzung();
    sitzung_beenden();
    if ($m) {
        audit('abmeldung', $m);
    }
    antwort(['success' => true]);
}

// ── Zugang beantragen (öffentlich) ──

function aktion_zugang_beantragen(): never
{
    post_pruefen(false);
    $email = email_norm((string) ($_POST['email'] ?? ''));
    $vorname = sauber((string) ($_POST['vorname'] ?? ''), 80);
    $name = sauber((string) ($_POST['name'] ?? ''), 80);
    $angaben = sauber((string) ($_POST['angaben'] ?? ''), 500);
    $ip = client_ip();
    $ok = fn() => antwort([
        'success' => true,
        'message' => sprache() === 'en'
            ? 'Thank you – your request has been sent. As soon as we have confirmed your membership, you will receive an email.'
            : 'Danke – deine Anfrage ist eingegangen. Sobald wir die Mitgliedschaft bestätigt haben, bekommst du eine E-Mail.',
    ]);
    if (trim((string) ($_POST['website'] ?? '')) !== '') {
        $ok(); // Honeypot: Bot – Erfolg vortäuschen
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 200) {
        fehler('Bitte gib eine gültige E-Mail-Adresse ein.', 'Please enter a valid email address.');
    }
    if ($vorname === '' || $name === '') {
        fehler('Bitte Vorname und Name angeben.', 'Please enter your first and last name.');
    }
    if (ip_gesperrt($ip)) {
        $ok();
    }
    if (anzahl('anfrage', 'ip:' . $ip, 3600) >= LIMIT_ANFRAGEN_IP_STUNDE) {
        alarm('anfragen-ip-' . $ip, 'viele Zugangsanfragen', "Von der IP {$ip} kamen in der letzten Stunde " . LIMIT_ANFRAGEN_IP_STUNDE . ' oder mehr Zugangsanfragen.');
        fehler('Zu viele Anfragen. Bitte versuche es später nochmals.', 'Too many requests. Please try again later.', 429);
    }
    ereignis('anfrage', 'ip:' . $ip);
    // Schon freigeschaltet? Gleiche Antwort (verrät nichts), aber keine Mail an it@.
    $st = db()->prepare('SELECT status FROM mitglieder WHERE email = ?');
    $st->execute([$email]);
    $status = $st->fetchColumn();
    if ($status === 'aktiv' || anzahl('anfrage', 'adr:' . $email, 86400) >= LIMIT_ANFRAGEN_ADRESSE_TAG) {
        $ok();
    }
    ereignis('anfrage', 'adr:' . $email);
    db()->prepare('INSERT INTO zugangsanfragen (email, vorname, name, angaben, locale, zeit, ip) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$email, $vorname, $name, $angaben, sprache(), jetzt(), $ip]);
    $id = (int) db()->lastInsertId();
    audit('anfrage', null, null, "{$vorname} {$name}" . ($angaben !== '' ? ": {$angaben}" : ''), 0, $email);

    $e = fn(string $x) => htmlspecialchars($x, ENT_QUOTES, 'UTF-8');
    $ja = anfrage_link('annehmen', $id);
    $nein = anfrage_link('ablehnen', $id);
    $hinweisGesperrt = $status === 'gesperrt' ? "\nACHTUNG: Diese Adresse ist im Mitgliederbereich gesperrt.\n" : '';
    $text = "Neue Anfrage für den Mitgliederbereich.\n\nName: {$vorname} {$name}\nE-Mail: {$email}\n"
        . ($angaben !== '' ? "Angaben: {$angaben}\n" : '') . $hinweisGesperrt
        . "\nBitte prüfen, ob die Person Mitglied ist.\n\nAkzeptieren: {$ja}\n\nAblehnen: {$nein}\n\n"
        . 'Die Links sind ' . ANFRAGE_GUELTIG_TAGE . " Tage gültig. Alternativ im Admin-Reiter: " . basis_url() . "/mitglieder/#admin\n";
    $html = '<p style="margin:0 0 16px;"><strong>Neue Anfrage für den Mitgliederbereich</strong></p>'
        . '<table role="presentation" style="margin:0 0 16px;font-size:15px;">'
        . '<tr><td style="color:#666;padding-right:16px;">Name</td><td><strong>' . $e("{$vorname} {$name}") . '</strong></td></tr>'
        . '<tr><td style="color:#666;padding-right:16px;">E-Mail</td><td>' . $e($email) . '</td></tr>'
        . ($angaben !== '' ? '<tr><td style="color:#666;padding-right:16px;vertical-align:top;">Angaben</td><td>' . $e($angaben) . '</td></tr>' : '')
        . '</table>'
        . ($status === 'gesperrt' ? '<p style="margin:0 0 16px;color:#b91c1c;"><strong>Achtung: Diese Adresse ist im Mitgliederbereich gesperrt.</strong></p>' : '')
        . '<p style="margin:0 0 20px;">Bitte prüfen, ob die Person Mitglied ist.</p>'
        . '<p style="margin:0 0 16px;">' . mail_knopf($ja, '✓ Akzeptieren') . mail_knopf($nein, '✕ Ablehnen', false) . '</p>'
        . '<p style="margin:0;color:#666666;font-size:13px;">Der Knopf öffnet eine Bestätigungsseite – dort Namen prüfen und mit einem Klick abschliessen. Beim Akzeptieren erhält die Person automatisch eine Begrüssungsmail. Links ' . ANFRAGE_GUELTIG_TAGE . ' Tage gültig; alternativ im <a href="' . $e(basis_url() . '/mitglieder/#admin') . '" style="color:#004D7A;">Admin-Reiter</a>.</p>';
    mail_senden_html(FWVJ_IT_ADRESSE, "[Mitgliederbereich] Zugangsanfrage: {$vorname} {$name}", $text, mail_html_rahmen($html), $email);
    $ok();
}

function aktion_admin_anfragen(): never
{
    admin_pflicht();
    $rows = db()->query("SELECT id, email, vorname, name, angaben, locale, zeit FROM zugangsanfragen WHERE status = 'offen' ORDER BY zeit")->fetchAll();
    antwort(['success' => true, 'anfragen' => $rows]);
}

function aktion_admin_anfrage(): never
{
    post_pruefen();
    $ich = admin_pflicht();
    $st = db()->prepare("SELECT * FROM zugangsanfragen WHERE id = ? AND status = 'offen'");
    $st->execute([(int) ($_POST['id'] ?? 0)]);
    $a = $st->fetch();
    if (!$a) {
        fehler('Diese Anfrage ist bereits erledigt.', 'This request has already been handled.', 409);
    }
    $annehmen = ($_POST['was'] ?? '') === 'annehmen';
    $ergebnis = anfrage_entscheiden($a, $annehmen, sauber((string) ($_POST['vorname'] ?? $a['vorname']), 80) ?: $a['vorname'], sauber((string) ($_POST['name'] ?? $a['name']), 80) ?: $a['name'], !empty($_POST['mail']), $ich);
    if ($ergebnis === 'gesperrt') {
        fehler('Diese Adresse ist gesperrt – zuerst in der Mitgliederliste entsperren.', 'This address is blocked – unblock it in the member list first.', 409);
    }
    antwort(['success' => true]);
}

// ── Liste ──

function dokument_fuer_ausgabe(array $d, array $ich, array $autoren, array $meldungen): array
{
    $admin = $ich['rolle'] === 'admin';
    $meins = (int) $d['autor_id'] === (int) $ich['id'];
    $autor = $autoren[(int) $d['autor_id']] ?? null;
    $autorName = $autor ? autor_name($autor) : '–';
    $aus = [
        'id' => $d['id'],
        'titel' => $d['titel'],
        'kategorie' => $d['kategorie'],
        'sprache' => $d['sprache'],
        'format' => $d['format'],
        'tags' => $d['tags'] === '' ? [] : explode(',', $d['tags']),
        'seitenformat' => $d['seitenformat'],
        'anzeige_name' => $d['anzeige_name'],
        'vorschau' => !empty($d['vorschau_id']),
        'groesse' => (int) $d['groesse'],
        'version' => (int) $d['version'],
        'status' => $d['status'],
        'anonym' => (bool) $d['anonym'],
        'autor' => ($d['anonym'] && !$admin && !$meins) ? null : $autorName,
        'autor_id' => ($d['anonym'] && !$admin && !$meins) ? null : (int) $d['autor_id'],
        'meins' => $meins,
        'erstellt' => (int) $d['erstellt'],
        'geaendert' => (int) $d['geaendert'],
        'text' => mb_substr($d['erkannter_text'], 0, 1500),
        'von_mir_gemeldet' => in_array((int) $ich['id'], array_column($meldungen[$d['id']] ?? [], 'mitglied_id'), true),
    ];
    if ($admin) {
        $aus['autor_email'] = $autor['email'] ?? '';
        $aus['meldungen'] = array_map(fn($x) => [
            'von' => isset($autoren[(int) $x['mitglied_id']]) ? autor_name($autoren[(int) $x['mitglied_id']]) : '?',
            'grund' => $x['grund'],
            'zeit' => (int) $x['zeit'],
            'erledigt' => (bool) $x['erledigt'],
        ], $meldungen[$d['id']] ?? []);
        $aus['versteckt_grund'] = $d['versteckt_grund'];
    }
    return $aus;
}

function aktion_liste(): never
{
    $ich = sitzung_pflicht();
    $pdo = db();
    if ($ich['rolle'] === 'admin') {
        $st = $pdo->query("SELECT * FROM dokumente WHERE status <> 'geloescht' ORDER BY erstellt DESC");
    } else {
        $st = $pdo->prepare("SELECT * FROM dokumente WHERE status = 'sichtbar' OR (autor_id = ? AND status <> 'geloescht') ORDER BY erstellt DESC");
        $st->execute([$ich['id']]);
    }
    $doks = $st->fetchAll();
    $autoren = [];
    foreach ($pdo->query('SELECT id, email, vorname, name FROM mitglieder')->fetchAll() as $a) {
        $autoren[(int) $a['id']] = $a;
    }
    $meldungen = [];
    foreach ($pdo->query('SELECT * FROM meldungen ORDER BY zeit')->fetchAll() as $x) {
        $x['mitglied_id'] = (int) $x['mitglied_id'];
        $meldungen[$x['dokument_id']][] = $x;
    }
    antwort([
        'success' => true,
        'dokumente' => array_map(fn($d) => dokument_fuer_ausgabe($d, $ich, $autoren, $meldungen), $doks),
    ]);
}

// ── Hochladen / Ersetzen ──

/** Metadaten aus dem Formular prüfen (gegen den Katalog – keine freien Kategorien). */
function metadaten_pruefen(): array
{
    $titel = sauber((string) ($_POST['titel'] ?? ''), 120);
    $kategorie = (string) ($_POST['kategorie'] ?? '');
    $sprache = (string) ($_POST['sprache'] ?? '');
    $tags = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) ($_POST['tags'] ?? ''))))));
    $seitenformat = (string) ($_POST['seitenformat'] ?? '');

    if (mb_strlen($titel) < 3) {
        fehler('Bitte gib einen Titel mit mindestens 3 Zeichen ein.', 'Please enter a title with at least 3 characters.');
    }
    if (!in_array($kategorie, katalog_keys('kategorien'), true)) {
        fehler('Bitte wähle eine Kategorie aus der Liste.', 'Please choose a category from the list.');
    }
    if (!in_array($sprache, katalog_keys('sprachen'), true)) {
        fehler('Bitte wähle die Sprache des Dokuments.', 'Please choose the language of the document.');
    }
    $erlaubteTags = katalog_keys('tags');
    $tags = array_slice(array_values(array_filter($tags, fn($t) => in_array($t, $erlaubteTags, true))), 0, 8);
    $formate = ['', 'a3-hoch', 'a3-quer', 'a4-hoch', 'a4-quer', 'a5-hoch', 'a5-quer', 'letter-hoch', 'letter-quer', 'hoch', 'quer', 'quadratisch'];
    if (!in_array($seitenformat, $formate, true)) {
        $seitenformat = '';
    }
    return [
        'titel' => $titel,
        'kategorie' => $kategorie,
        'sprache' => $sprache,
        'tags' => implode(',', $tags),
        'seitenformat' => $seitenformat,
    ];
}

function aktion_hochladen(): never
{
    post_pruefen();
    $ich = sitzung_pflicht();
    $ip = client_ip();
    $pdo = db();

    if (ip_gesperrt($ip)) {
        audit('upload_gesperrte_ip', $ich);
        fehler('Hochladen ist von deinem Netzwerk aus derzeit gesperrt. Bitte melde dich bei ' . FWVJ_IT_ADRESSE . '.', 'Uploading is currently blocked from your network. Please contact ' . FWVJ_IT_ADRESSE . '.', 403);
    }
    foreach (['konto:' . $ich['id'], 'ip:' . $ip] as $k) {
        if (anzahl('upload', $k, 3600) >= LIMIT_UPLOADS_STUNDE || anzahl('upload', $k, 86400) >= LIMIT_UPLOADS_TAG) {
            alarm('uploads-' . $k, 'viele Uploads', "Konto {$ich['email']} / IP {$ip} hat die Upload-Bremse erreicht (" . LIMIT_UPLOADS_STUNDE . '/Stunde bzw. ' . LIMIT_UPLOADS_TAG . '/Tag).');
            fehler('Du hast die Höchstzahl an Uploads erreicht (' . LIMIT_UPLOADS_STUNDE . ' pro Stunde, ' . LIMIT_UPLOADS_TAG . ' pro Tag). Bitte später weitermachen.', 'You have reached the upload limit (' . LIMIT_UPLOADS_STUNDE . ' per hour, ' . LIMIT_UPLOADS_TAG . ' per day). Please continue later.', 429);
        }
    }

    $f = $_FILES['datei'] ?? null;
    if (!is_array($f) || is_array($f['error'] ?? null)) {
        fehler('Bitte wähle eine Datei aus.', 'Please choose a file.');
    }
    if ((int) $f['error'] === UPLOAD_ERR_INI_SIZE || (int) $f['error'] === UPLOAD_ERR_FORM_SIZE || (int) $f['size'] > MAX_DATEI_BYTES) {
        fehler('Die Datei ist grösser als 5 MB. Bitte als kleineres PDF exportieren oder das Bild verkleinern.', 'The file is larger than 5 MB. Please export a smaller PDF or reduce the image size.', 413);
    }
    if ((int) $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
        fehler('Die Datei konnte nicht empfangen werden. Bitte nochmals versuchen.', 'The file could not be received. Please try again.');
    }
    $meta = metadaten_pruefen();

    // Ersetzen = neue Version eines eigenen Dokuments
    $alt = null;
    $ersetzt = (string) ($_POST['ersetzt'] ?? '');
    if ($ersetzt !== '') {
        $alt = dokument($ersetzt);
        if (!$alt || (int) $alt['autor_id'] !== (int) $ich['id'] || in_array($alt['status'], ['geloescht', 'abgelehnt'], true)) {
            fehler('Dieses Dokument kannst du nicht ersetzen.', 'You cannot replace this document.', 403);
        }
    }

    $st = $pdo->prepare("SELECT COALESCE(SUM(groesse), 0) FROM dokumente WHERE autor_id = ? AND status NOT IN ('geloescht','abgelehnt')");
    $st->execute([$ich['id']]);
    if ((int) $st->fetchColumn() - (int) ($alt['groesse'] ?? 0) + (int) $f['size'] > MAX_VOLUMEN_JE_KONTO) {
        fehler('Dein Speicherplatz (500 MB) ist voll. Bitte lösche alte Uploads.', 'Your storage (500 MB) is full. Please delete old uploads.', 413);
    }

    // In die Ablage verschieben, dann prüfen (Prüfungen lesen die Datei mehrfach)
    $speicherId = zufall_id();
    $ziel = datei_pfad($speicherId);
    if (!move_uploaded_file((string) $f['tmp_name'], $ziel)) {
        fehler('Die Datei konnte nicht gespeichert werden.', 'The file could not be saved.', 500);
    }
    @chmod($ziel, 0600);
    $vorschauId = null;
    $fertig = false;
    // fehler() beendet per exit – abgelehnte Dateien hier sicher wieder entfernen.
    register_shutdown_function(function () use (&$fertig, &$vorschauId, $ziel): void {
        if (!$fertig) {
            @unlink($ziel);
            if ($vorschauId) {
                @unlink(vorschau_pfad($vorschauId));
            }
        }
    });
    $typ = datei_typ_pruefen($ziel);
    if (str_starts_with($typ['mime'], 'image/')) {
        [$bw, $bh] = bild_neu_kodieren($ziel, $typ['mime']);
        if ($meta['seitenformat'] === '') {
            $meta['seitenformat'] = $bw === $bh ? 'quadratisch' : ($bw > $bh ? 'quer' : 'hoch');
        }
        $vorschauId = vorschau_erzeugen($ziel, $typ['mime']);
    } else {
        $vorschauId = vorschau_aus_formular();
    }
    $sha = hash_file('sha256', $ziel);
    $st = $pdo->prepare('SELECT 1 FROM hash_sperren WHERE sha256 = ?');
    $st->execute([$sha]);
    if ($st->fetchColumn()) {
        audit('upload_gesperrter_hash', $ich, null, 'SHA-256 ' . $sha);
        alarm('hash-' . $ich['id'], 'gesperrte Datei erneut hochgeladen', "Konto {$ich['email']} (IP {$ip}) wollte eine Datei hochladen, die auf der Sperrliste steht (SHA-256 {$sha}).");
        fehler('Diese Datei wurde vom Vorstand entfernt und kann nicht erneut hochgeladen werden.', 'This file was removed by the board and cannot be uploaded again.', 403);
    }

    $groesse = (int) filesize($ziel);
    $text = mb_substr(sauber((string) ($_POST['erkannter_text'] ?? ''), 20000), 0, 4000);
    $anonym = !empty($_POST['anonym']) ? 1 : 0;
    $zeit = jetzt();
    $name = anzeige_name($meta['kategorie'], $meta['titel'], $meta['sprache'], $typ['ext'], $zeit);
    $original = sauber((string) ($f['name'] ?? ''), 200);

    if ($alt) {
        // Anonyme Dokumente gehen nach dem Ersetzen wieder in die Freigabe.
        $anonym = (int) $alt['anonym'];
        $status = $anonym ? 'wartet' : ($alt['status'] === 'versteckt' ? 'versteckt' : 'sichtbar');
        audit_datei_weg($alt['id']);
        dateien_entfernen($alt);
        $pdo->prepare('UPDATE dokumente SET titel=?, kategorie=?, sprache=?, format=?, mime=?, tags=?, seitenformat=?, anzeige_name=?, original_name=?, speicher_id=?, vorschau_id=?, groesse=?, sha256=?, erkannter_text=?, status=?, version=version+1, geaendert=? WHERE id=?')
            ->execute([$meta['titel'], $meta['kategorie'], $meta['sprache'], $typ['ext'], $typ['mime'], $meta['tags'], $meta['seitenformat'], $name, $original, $speicherId, $vorschauId, $groesse, $sha, $text, $status, $zeit, $alt['id']]);
        $id = $alt['id'];
        audit('ersetzen', $ich, $id, "Version " . ((int) $alt['version'] + 1) . ", {$groesse} Bytes, SHA-256 {$sha}, Datei «{$original}»", 1);
    } else {
        $id = zufall_id(8);
        $status = $anonym ? 'wartet' : 'sichtbar';
        $pdo->prepare('INSERT INTO dokumente (id, titel, kategorie, sprache, format, mime, tags, seitenformat, anzeige_name, original_name, speicher_id, vorschau_id, groesse, sha256, erkannter_text, autor_id, anonym, status, erstellt, geaendert) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $meta['titel'], $meta['kategorie'], $meta['sprache'], $typ['ext'], $typ['mime'], $meta['tags'], $meta['seitenformat'], $name, $original, $speicherId, $vorschauId, $groesse, $sha, $text, $ich['id'], $anonym, $status, $zeit, $zeit]);
        audit('hochladen', $ich, $id, ($anonym ? 'anonym, ' : '') . "{$groesse} Bytes, SHA-256 {$sha}, Datei «{$original}»", 1);
    }
    $fertig = true;
    ereignis('upload', 'konto:' . $ich['id']);
    ereignis('upload', 'ip:' . $ip);

    $link = basis_url() . '/mitglieder/?dok=' . $id;
    $autor = autor_name($ich) . ' <' . $ich['email'] . '>';
    if ($status === 'wartet') {
        mail_an_it('Anonymer Upload wartet auf Freigabe: ' . $meta['titel'],
            "Ein Mitglied möchte ein Dokument anonym veröffentlichen.\n\n"
            . "Titel: {$meta['titel']}\nKategorie: {$meta['kategorie']}\nSprache: {$meta['sprache']}\nDatei: {$name} (" . round($groesse / 1024) . " KB)\n"
            . "Absender (nur für den Vorstand sichtbar): {$autor}\n\n"
            . "Ansehen (Anmeldung als Admin): {$link}\n\n"
            . "Freigeben: " . freigabe_link('freigeben', $id) . "\n\n"
            . "Ablehnen: " . freigabe_link('ablehnen', $id) . "\n\n"
            . "Die Links sind " . SIGNATUR_GUELTIG_TAGE . " Tage gültig und fragen vor der Ausführung nochmals nach.\n");
    } elseif (einstellung('mail_bei_veroeffentlichung', '1') === '1') {
        mail_an_it(($alt ? 'Neue Version: ' : 'Neu veröffentlicht: ') . $meta['titel'],
            "Titel: {$meta['titel']}\nKategorie: {$meta['kategorie']}\nAutor: {$autor}\nLink: {$link}\n\n"
            . "(Diese Benachrichtigung lässt sich in der Admin-Ansicht abschalten.)\n");
    }
    antwort(['success' => true, 'id' => $id, 'status' => $status, 'anzeige_name' => $name]);
}

/** Vom Browser erzeugtes Vorschaubild (PDF Seite 1) prüfen und neu kodieren. */
function vorschau_aus_formular(): ?string
{
    $v = $_FILES['vorschau'] ?? null;
    if (!is_array($v) || is_array($v['error'] ?? null) || (int) $v['error'] !== UPLOAD_ERR_OK || (int) $v['size'] > MAX_VORSCHAU_BYTES || !is_uploaded_file((string) $v['tmp_name'])) {
        return null;
    }
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file((string) $v['tmp_name']);
    if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
        return null;
    }
    try {
        return vorschau_erzeugen((string) $v['tmp_name'], $mime);
    } finally {
        @unlink((string) $v['tmp_name']);
    }
}

// ── Bearbeiten / Löschen / Melden ──

function aktion_aendern(): never
{
    post_pruefen();
    $ich = sitzung_pflicht();
    $d = dokument((string) ($_POST['id'] ?? ''));
    $admin = $ich['rolle'] === 'admin';
    if (!$d || in_array($d['status'], ['geloescht', 'abgelehnt'], true) || ((int) $d['autor_id'] !== (int) $ich['id'] && !$admin)) {
        fehler('Dieses Dokument kannst du nicht bearbeiten.', 'You cannot edit this document.', 403);
    }
    $meta = metadaten_pruefen();
    if ($meta['seitenformat'] === '') {
        $meta['seitenformat'] = $d['seitenformat'];
    }
    $name = anzeige_name($meta['kategorie'], $meta['titel'], $meta['sprache'], $d['format'], (int) $d['erstellt']);
    db()->prepare('UPDATE dokumente SET titel=?, kategorie=?, sprache=?, tags=?, seitenformat=?, anzeige_name=?, geaendert=? WHERE id=?')
        ->execute([$meta['titel'], $meta['kategorie'], $meta['sprache'], $meta['tags'], $meta['seitenformat'], $name, jetzt(), $d['id']]);
    audit('aendern', $ich, $d['id'], "Titel «{$meta['titel']}», Kategorie {$meta['kategorie']}, Sprache {$meta['sprache']}, Tags {$meta['tags']}");
    antwort(['success' => true, 'anzeige_name' => $name]);
}

function dokument_loeschen(array $d, array $ich, string $aktion, bool $sperrliste = false, string $status = 'geloescht'): void
{
    if ($sperrliste && $d['sha256'] !== '') {
        db()->prepare('INSERT OR REPLACE INTO hash_sperren (sha256, grund, zeit) VALUES (?, ?, ?)')
            ->execute([$d['sha256'], 'entfernt: ' . $d['titel'], jetzt()]);
    }
    audit_datei_weg($d['id']);
    dateien_entfernen($d);
    db()->prepare('UPDATE dokumente SET status = ?, speicher_id = NULL, vorschau_id = NULL, erkannter_text = \'\', geloescht_am = ?, geaendert = ? WHERE id = ?')
        ->execute([$status, jetzt(), jetzt(), $d['id']]);
    audit($aktion, $ich, $d['id'], "«{$d['titel']}»" . ($sperrliste ? ', SHA-256 auf Sperrliste' : ''));
}

function aktion_loeschen(): never
{
    post_pruefen();
    $ich = sitzung_pflicht();
    $d = dokument((string) ($_POST['id'] ?? ''));
    if (!$d || $d['status'] === 'geloescht' || ((int) $d['autor_id'] !== (int) $ich['id'] && $ich['rolle'] !== 'admin')) {
        fehler('Dieses Dokument kannst du nicht löschen.', 'You cannot delete this document.', 403);
    }
    dokument_loeschen($d, $ich, 'loeschen');
    ereignis('loeschen', 'konto:' . $ich['id']);
    if (anzahl('loeschen', 'konto:' . $ich['id'], 3600) >= ALARM_LOESCHUNGEN_STUNDE) {
        alarm('loeschen-' . $ich['id'], 'viele Löschungen', "Konto {$ich['email']} (IP " . client_ip() . ') hat in der letzten Stunde ' . ALARM_LOESCHUNGEN_STUNDE . ' oder mehr Dokumente gelöscht.');
    }
    antwort(['success' => true]);
}

function aktion_melden(): never
{
    post_pruefen();
    $ich = sitzung_pflicht();
    $d = dokument((string) ($_POST['id'] ?? ''));
    $grund = sauber((string) ($_POST['grund'] ?? ''), 500);
    if (!$d || $d['status'] !== 'sichtbar' || (int) $d['autor_id'] === (int) $ich['id']) {
        fehler('Dieses Dokument kann nicht gemeldet werden.', 'This document cannot be reported.', 403);
    }
    if (mb_strlen($grund) < 3) {
        fehler('Bitte schreib kurz, was nicht stimmt.', 'Please briefly describe the problem.');
    }
    if (anzahl('melden', 'konto:' . $ich['id'], 3600) >= LIMIT_MELDUNGEN_STUNDE) {
        fehler('Zu viele Meldungen in kurzer Zeit. Bitte später nochmals.', 'Too many reports in a short time. Please try again later.', 429);
    }
    $pdo = db();
    $neu = $pdo->prepare('INSERT OR IGNORE INTO meldungen (dokument_id, mitglied_id, grund, zeit) VALUES (?, ?, ?, ?)');
    $neu->execute([$d['id'], $ich['id'], $grund, jetzt()]);
    if ($neu->rowCount() === 0) {
        antwort(['success' => true, 'schon' => true]);
    }
    ereignis('melden', 'konto:' . $ich['id']);
    audit('melden', $ich, $d['id'], $grund);

    $st = $pdo->prepare('SELECT COUNT(DISTINCT mitglied_id) FROM meldungen WHERE dokument_id = ? AND erledigt = 0');
    $st->execute([$d['id']]);
    $zahl = (int) $st->fetchColumn();
    $versteckt = false;
    if ($zahl >= MELDUNGEN_BIS_AUSBLENDEN) {
        $pdo->prepare("UPDATE dokumente SET status = 'versteckt', versteckt_grund = 'meldungen', geaendert = ? WHERE id = ?")->execute([jetzt(), $d['id']]);
        audit('auto_versteckt', null, $d['id'], "{$zahl} Meldungen", 0, 'system');
        $versteckt = true;
    }
    mail_an_it(($versteckt ? 'Automatisch ausgeblendet: ' : 'Gemeldet: ') . $d['titel'],
        "Gemeldet von: " . autor_name($ich) . " <{$ich['email']}>\nGrund: {$grund}\n"
        . "Meldungen (offen, verschiedene Mitglieder): {$zahl}\n"
        . ($versteckt ? "Das Dokument ist jetzt automatisch versteckt, bis der Vorstand entscheidet.\n" : '')
        . "\nAnsehen: " . basis_url() . '/mitglieder/?dok=' . $d['id'] . "\nAdmin-Ansicht: " . basis_url() . "/mitglieder/#admin\n");
    antwort(['success' => true, 'versteckt' => $versteckt]);
}

// ── Admin ──

function aktion_admin_dokument(): never
{
    post_pruefen();
    $ich = admin_pflicht();
    $d = dokument((string) ($_POST['id'] ?? ''));
    $was = (string) ($_POST['was'] ?? '');
    if (!$d || $d['status'] === 'geloescht') {
        fehler('Dokument nicht gefunden.', 'Document not found.', 404);
    }
    admin_dokument_ausfuehren($d, $ich, $was, !empty($_POST['sperrliste']));
    antwort(['success' => true]);
}

/** Auch von freigabe.php (signierter Link) genutzt. */
function admin_dokument_ausfuehren(array $d, ?array $ich, string $was, bool $sperrliste = false): void
{
    $pdo = db();
    $email = $ich ? null : 'it@ (signierter Link)';
    switch ($was) {
        case 'freigeben':
        case 'einblenden':
            if (!in_array($d['status'], ['wartet', 'versteckt'], true)) {
                fehler('Das Dokument ist bereits sichtbar oder entfernt.', 'The document is already visible or removed.', 409);
            }
            $pdo->prepare("UPDATE dokumente SET status = 'sichtbar', versteckt_grund = '', geaendert = ? WHERE id = ?")->execute([jetzt(), $d['id']]);
            $pdo->prepare('UPDATE meldungen SET erledigt = 1 WHERE dokument_id = ?')->execute([$d['id']]);
            audit($was, $ich, $d['id'], "«{$d['titel']}»", 0, $email);
            break;
        case 'verstecken':
            $pdo->prepare("UPDATE dokumente SET status = 'versteckt', versteckt_grund = 'admin', geaendert = ? WHERE id = ?")->execute([jetzt(), $d['id']]);
            audit('verstecken', $ich, $d['id'], "«{$d['titel']}»", 0, $email);
            break;
        case 'ablehnen':
            if ($d['status'] !== 'wartet') {
                fehler('Nur wartende Uploads können abgelehnt werden.', 'Only pending uploads can be rejected.', 409);
            }
            dokument_loeschen($d, $ich ?? ['id' => null, 'email' => $email], 'ablehnen', $sperrliste, 'abgelehnt');
            break;
        case 'loeschen':
            dokument_loeschen($d, $ich ?? ['id' => null, 'email' => $email], 'admin_loeschen', $sperrliste);
            break;
        case 'meldungen_erledigt':
            $pdo->prepare('UPDATE meldungen SET erledigt = 1 WHERE dokument_id = ?')->execute([$d['id']]);
            audit('meldungen_erledigt', $ich, $d['id'], '', 0, $email);
            break;
        default:
            fehler('Unbekannte Aktion.', 'Unknown action.');
    }
}

function aktion_admin_mitglieder(): never
{
    admin_pflicht();
    $rows = db()->query("SELECT m.id, m.email, m.vorname, m.name, m.rolle, m.status, m.angelegt_am, m.angelegt_von, m.letzte_anmeldung,
        (SELECT COUNT(*) FROM dokumente d WHERE d.autor_id = m.id AND d.status NOT IN ('geloescht','abgelehnt')) AS uploads
        FROM mitglieder m ORDER BY m.name, m.vorname, m.email")->fetchAll();
    antwort(['success' => true, 'mitglieder' => $rows, 'mail_bei_veroeffentlichung' => einstellung('mail_bei_veroeffentlichung', '1') === '1']);
}

function aktion_admin_mitglied_speichern(): never
{
    post_pruefen();
    $ich = admin_pflicht();
    $email = email_norm((string) ($_POST['email'] ?? ''));
    $vorname = sauber((string) ($_POST['vorname'] ?? ''), 80);
    $name = sauber((string) ($_POST['name'] ?? ''), 80);
    $rolle = ($_POST['rolle'] ?? '') === 'admin' ? 'admin' : 'mitglied';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fehler('Bitte eine gültige E-Mail-Adresse eingeben.', 'Please enter a valid email address.');
    }
    if ($vorname === '' || $name === '') {
        fehler('Vorname und Name sind nötig (werden als Autor angezeigt).', 'First and last name are required (shown as author).');
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM mitglieder WHERE email = ?');
    $st->execute([$email]);
    $vorhanden = $st->fetch();
    if ($vorhanden) {
        if ((int) $vorhanden['id'] === (int) $ich['id'] && $rolle !== 'admin') {
            fehler('Du kannst dir die Admin-Rolle nicht selbst entziehen.', 'You cannot remove your own admin role.');
        }
        $pdo->prepare('UPDATE mitglieder SET vorname = ?, name = ?, rolle = ? WHERE id = ?')->execute([$vorname, $name, $rolle, $vorhanden['id']]);
        audit('mitglied_geaendert', $ich, null, "{$email}: {$vorname} {$name}, {$rolle}");
    } else {
        $pdo->prepare("INSERT INTO mitglieder (email, vorname, name, rolle, status, angelegt_am, angelegt_von) VALUES (?, ?, ?, ?, 'aktiv', ?, ?)")
            ->execute([$email, $vorname, $name, $rolle, jetzt(), $ich['email']]);
        audit('mitglied_freigeschaltet', $ich, null, "{$email}: {$vorname} {$name}, {$rolle}");
        if (!empty($_POST['begruessung'])) {
            begruessung_senden($email, $vorname, ($_POST['mail_sprache'] ?? '') === 'en' ? 'en' : 'de');
        }
        // Offene Formular-Anfrage derselben Adresse ist damit erledigt
        $pdo->prepare("UPDATE zugangsanfragen SET status = 'angenommen', erledigt_am = ?, erledigt_von = ? WHERE email = ? AND status = 'offen'")
            ->execute([jetzt(), $ich['email'], $email]);
    }
    antwort(['success' => true, 'neu' => !$vorhanden]);
}

function aktion_admin_mitglied_status(): never
{
    post_pruefen();
    $ich = admin_pflicht();
    $id = (int) ($_POST['id'] ?? 0);
    $status = ($_POST['status'] ?? '') === 'gesperrt' ? 'gesperrt' : 'aktiv';
    if ($id === (int) $ich['id']) {
        fehler('Du kannst dich nicht selbst sperren.', 'You cannot block yourself.');
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM mitglieder WHERE id = ?');
    $st->execute([$id]);
    $m = $st->fetch();
    if (!$m) {
        fehler('Mitglied nicht gefunden.', 'Member not found.', 404);
    }
    $pdo->prepare('UPDATE mitglieder SET status = ? WHERE id = ?')->execute([$status, $id]);
    $details = $m['email'];
    if ($status === 'gesperrt') {
        $pdo->prepare('DELETE FROM sitzungen WHERE mitglied_id = ?')->execute([$id]); // sofort abgemeldet
        $pdo->prepare('UPDATE login_codes SET verbraucht = 1 WHERE email = ?')->execute([$m['email']]);
        if (!empty($_POST['uploads_verstecken'])) {
            $n = $pdo->prepare("UPDATE dokumente SET status = 'versteckt', versteckt_grund = 'konto_gesperrt', geaendert = ? WHERE autor_id = ? AND status IN ('sichtbar','wartet')");
            $n->execute([jetzt(), $id]);
            $details .= ', ' . $n->rowCount() . ' Uploads versteckt';
        }
    } elseif (!empty($_POST['uploads_einblenden'])) {
        $pdo->prepare("UPDATE dokumente SET status = 'sichtbar', versteckt_grund = '', geaendert = ? WHERE autor_id = ? AND status = 'versteckt' AND versteckt_grund = 'konto_gesperrt' AND anonym = 0")
            ->execute([jetzt(), $id]);
    }
    audit($status === 'gesperrt' ? 'konto_gesperrt' : 'konto_entsperrt', $ich, null, $details);
    antwort(['success' => true]);
}

function aktion_admin_audit(): never
{
    admin_pflicht();
    $filter = sauber((string) ($_GET['filter'] ?? ''), 100);
    $sql = 'SELECT a.*, d.titel AS dokument_titel FROM audit a LEFT JOIN dokumente d ON d.id = a.dokument_id';
    $param = [];
    if ($filter !== '') {
        $sql .= ' WHERE a.email LIKE ? OR a.ip LIKE ? OR a.aktion LIKE ? OR d.titel LIKE ?';
        $param = array_fill(0, 4, '%' . $filter . '%');
    }
    $st = db()->prepare($sql . ' ORDER BY a.id DESC LIMIT 300');
    $st->execute($param);
    antwort(['success' => true, 'eintraege' => $st->fetchAll()]);
}

function aktion_admin_ip_sperren(): never
{
    admin_pflicht();
    $st = db()->prepare('SELECT * FROM ip_sperren WHERE bis > ? ORDER BY bis DESC');
    $st->execute([jetzt()]);
    $hashes = (int) db()->query('SELECT COUNT(*) FROM hash_sperren')->fetchColumn();
    antwort(['success' => true, 'sperren' => $st->fetchAll(), 'hash_sperren' => $hashes, 'deine_ip' => client_ip()]);
}

function aktion_admin_ip_sperre_setzen(): never
{
    post_pruefen();
    $ich = admin_pflicht();
    $ip = trim((string) ($_POST['ip'] ?? ''));
    $umfang = ($_POST['umfang'] ?? '') === 'netz' ? 'netz' : 'einzeln';
    $tage = max(1, min(3650, (int) ($_POST['tage'] ?? 30)));
    $grund = sauber((string) ($_POST['grund'] ?? ''), 300);
    $netz = ip_netz($ip, $umfang);
    if ($netz === null) {
        fehler('Bitte eine gültige IP-Adresse eingeben.', 'Please enter a valid IP address.');
    }
    if (ip_im_netz(client_ip(), $netz)) {
        fehler('Diese Sperre würde dich selbst aussperren.', 'This block would lock you out yourself.');
    }
    db()->prepare('INSERT INTO ip_sperren (netz, bis, grund, erstellt, erstellt_von) VALUES (?, ?, ?, ?, ?)')
        ->execute([$netz, jetzt() + $tage * 86400, $grund, jetzt(), $ich['email']]);
    audit('ip_gesperrt', $ich, null, "{$netz} für {$tage} Tage: {$grund}");
    antwort(['success' => true, 'netz' => $netz]);
}

function aktion_admin_ip_sperre_aufheben(): never
{
    post_pruefen();
    $ich = admin_pflicht();
    $id = (int) ($_POST['id'] ?? 0);
    $st = db()->prepare('SELECT netz FROM ip_sperren WHERE id = ?');
    $st->execute([$id]);
    $netz = (string) $st->fetchColumn();
    db()->prepare('DELETE FROM ip_sperren WHERE id = ?')->execute([$id]);
    audit('ip_entsperrt', $ich, null, $netz);
    antwort(['success' => true]);
}

function aktion_admin_einstellung(): never
{
    post_pruefen();
    $ich = admin_pflicht();
    $wert = !empty($_POST['mail_bei_veroeffentlichung']) ? '1' : '0';
    db()->prepare("INSERT OR REPLACE INTO einstellungen (schluessel, wert) VALUES ('mail_bei_veroeffentlichung', ?)")->execute([$wert]);
    audit('einstellung', $ich, null, 'Mail bei Veröffentlichung: ' . ($wert === '1' ? 'an' : 'aus'));
    antwort(['success' => true]);
}
