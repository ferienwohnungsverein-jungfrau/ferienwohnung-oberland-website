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

function seite(string $titel, string $inhalt, bool $en): never
{
    $t = e($titel);
    $lang = $en ? 'en' : 'de-CH';
    echo <<<HTML
<!doctype html>
<html lang="{$lang}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>{$t} – Ferienwohnungsverein Jungfrau</title>
<style>
body{margin:0;background:#F9F9F9;font-family:Arial,Helvetica,sans-serif;color:#334155;line-height:1.5}
main{max-width:480px;margin:40px auto;padding:28px;background:#fff;border-radius:12px;border:1px solid rgba(0,77,122,.14);text-align:center}
.kopf{font-weight:700;color:#004D7A;margin:0 0 18px}
h1{color:#1a1a1a;font-size:1.4rem;margin:0 0 12px}
button{background:#F4A261;color:#1e293b;border:0;border-radius:6px;padding:14px 28px;font-weight:700;font-size:1.05rem;cursor:pointer;margin-top:8px}
.klein{font-size:.85rem;color:#64748b}
a{color:#004D7A}
</style>
</head><body><main><p class="kopf">Ferienwohnungsverein Jungfrau</p><h1>{$t}</h1>{$inhalt}</main></body></html>
HTML;
    exit;
}

$ungueltig = fn() => seite(
    $en ? 'Link no longer valid' : 'Link nicht mehr gültig',
    $en
        ? '<p>This sign-in link has expired or has already been used (valid 10 minutes, once).</p><p><a href="/en/members/">Request a new link</a></p>'
        : '<p>Dieser Anmeldelink ist abgelaufen oder wurde schon verwendet (10 Minuten gültig, einmal).</p><p><a href="/mitglieder/">Neuen Link anfordern</a></p>',
    $en
);

$ip = client_ip();
if (ip_gesperrt($ip) || anzahl('fehlcode', 'ip:' . $ip, 3600) >= LIMIT_FEHLCODES_IP_STUNDE) {
    seite($en ? 'Too many attempts' : 'Zu viele Versuche', $en ? '<p>Please wait an hour.</p>' : '<p>Bitte warte eine Stunde.</p>', $en);
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

$felder = '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="t" value="' . e($token) . '">'
    . ($en ? '<input type="hidden" name="l" value="en">' : '');
seite(
    $en ? 'Sign in to the members’ area' : 'Im Mitgliederbereich anmelden',
    '<p>' . e((string) $row['email']) . '</p><form method="post">' . $felder
        . '<button type="submit">' . ($en ? 'Sign in now' : 'Jetzt anmelden') . '</button></form>'
        . '<p class="klein">' . ($en ? 'This device stays signed in for 30 days.' : 'Dieses Gerät bleibt 30 Tage angemeldet.') . '</p>',
    $en
);
