<?php
declare(strict_types=1);
// Mitgliederbereich – Anmeldelink aus der Code-Mail (Magic-Link, Stefan 04.11.2026).
// GET zeigt nur einen Knopf «Jetzt anmelden»; erst der Klick (POST) meldet an und verbraucht
// den Link. So lösen Link-Prüfer von Mailprogrammen (Outlook «Safe Links» u. ä.) nichts aus.
// Link und Code gehören zur selben login_codes-Zeile: wer eines nutzt, verbraucht beides.
define('FWVJ_MITGLIEDER', true);
require __DIR__ . '/_lib.php';
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$q = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$id = (int) ($q['id'] ?? 0);
$token = preg_replace('/[^a-f0-9]/', '', (string) ($q['t'] ?? '')) ?? '';
$en = ($q['l'] ?? '') === 'en';
$bereich = $en ? '/en/members/' : '/mitglieder/';

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

require_once dirname(__DIR__) . '/_seite.php';

/** Zweisprachige Seite mit Umschalter (Stefan 23.11.2026); Start in der Sprache des geklickten Mail-Knopfs. */
function seite2(string $titelDe, string $inhaltDe, string $titelEn, string $inhaltEn, bool $fehler = false): never
{
    global $en;
    fwvj_seite(['titel' => $titelDe, 'inhalt' => $inhaltDe], ['titel' => $titelEn, 'inhalt' => $inhaltEn], $en ? 'en' : 'de', $fehler, '480px');
}

$ungueltig = fn() => seite2(
    'Link nicht mehr gültig',
    '<p>Dieser Anmeldelink ist abgelaufen oder wurde schon verwendet (10 Minuten gültig, einmal).</p><p><a class="btn" href="/mitglieder/">Neuen Link anfordern</a></p>',
    'Link no longer valid',
    '<p>This log-in link has expired or has already been used (valid 10 minutes, once).</p><p><a class="btn" href="/en/members/">Send a new link</a></p>',
    true
);

$ip = client_ip();
if (ip_gesperrt($ip) || anzahl('fehlcode', 'ip:' . $ip, 3600) >= LIMIT_FEHLCODES_IP_STUNDE) {
    seite2('Zu viele Versuche', '<p>Bitte warte eine Stunde.</p>', 'Too many attempts', '<p>Please wait an hour.</p>', true);
}

// Schon angemeldet (z. B. Link zweimal angetippt) → direkt in den Bereich
if (sitzung() !== null) {
    header('Location: ' . $bereich, true, 303);
    exit;
}

$pdo = db();
$st = $pdo->prepare('SELECT * FROM login_codes WHERE id = ? AND verbraucht = 0 AND ablauf > ?');
$st->execute([$id, jetzt()]);
$row = $st->fetch();
if (!$row || strlen($token) !== 64 || ($row['link_hash'] ?? '') === '' || !hash_equals((string) $row['link_hash'], link_hash($token))) {
    ereignis('fehlcode', 'ip:' . $ip);
    $ungueltig();
}
$st = $pdo->prepare("SELECT * FROM mitglieder WHERE email = ? AND status = 'aktiv'");
$st->execute([$row['email']]);
$m = $st->fetch();
if (!$m) {
    $ungueltig();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo->prepare('UPDATE login_codes SET verbraucht = 1 WHERE id = ?')->execute([$row['id']]);
    $pdo->prepare('UPDATE mitglieder SET letzte_anmeldung = ? WHERE id = ?')->execute([jetzt(), $m['id']]);
    sitzung_setzen((int) $m['id']);
    audit('anmeldung_link', $m);
    header('Location: ' . $bereich, true, 303);
    exit;
}

$formular = static function (string $l, string $knopf) use ($id, $token): string {
    return '<form method="post"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="t" value="' . e($token) . '">'
        . '<input type="hidden" name="l" value="' . $l . '"><button type="submit">' . $knopf . '</button></form>';
};
$adr = '<p>' . e((string) $row['email']) . '</p>';
seite2(
    'Im Mitgliederbereich anmelden',
    $adr . $formular('de', 'Jetzt anmelden') . '<p class="klein">Dieses Gerät bleibt 30 Tage angemeldet.</p>',
    'Log in to the members’ area',
    $adr . $formular('en', 'Log in now') . '<p class="klein">This device stays signed in for 30 days.</p>'
);
