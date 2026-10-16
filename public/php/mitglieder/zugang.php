<?php
declare(strict_types=1);

// Mitgliederbereich – Knöpfe «Akzeptieren» / «Ablehnen» aus der Mail an it@ (Zugangsanfrage).
// GET zeigt eine Bestätigungsseite (Namen prüfen, Mail an die Person ja/nein); erst der
// Klick (POST) führt aus. So lösen Link-Prüfer von Mailprogrammen nichts aus.

define('FWVJ_MITGLIEDER', true);
require __DIR__ . '/_lib.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

$q = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$aktion = in_array($q['a'] ?? '', ['annehmen', 'ablehnen'], true) ? (string) $q['a'] : '';
$id = (int) ($q['id'] ?? 0);
$exp = (int) ($q['exp'] ?? 0);
$sig = (string) ($q['s'] ?? '');

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function seite(string $titel, string $inhalt): never
{
    $t = e($titel);
    echo <<<HTML
<!doctype html>
<html lang="de-CH"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>{$t} – Mitgliederbereich</title>
<style>
body{margin:0;background:#F9F9F9;font-family:Arial,Helvetica,sans-serif;color:#334155;line-height:1.5}
main{max-width:520px;margin:32px auto;padding:28px;background:#fff;border-radius:12px;border:1px solid rgba(0,77,122,.14)}
h1{color:#004D7A;font-size:1.4rem;margin:0 0 16px}
label{display:block;margin:12px 0;font-size:.95rem}
label.feld span{display:block;font-weight:700;margin-bottom:4px}
input[type=text]{width:100%;box-sizing:border-box;padding:10px 12px;border:1.5px solid #D1D5DB;border-radius:4px;font-size:1rem}
button{background:#F4A261;color:#1e293b;border:0;border-radius:4px;padding:12px 24px;font-weight:700;font-size:1rem;cursor:pointer;margin-top:12px}
button.nein{background:#fee2e2;color:#b91c1c}
.daten{background:#F0F5FA;border-radius:6px;padding:12px 16px}
.klein{font-size:.85rem;color:#64748b}
a{color:#004D7A}
</style>
</head><body><main><h1>{$t}</h1>{$inhalt}</main></body></html>
HTML;
    exit;
}

$st = db()->prepare('SELECT * FROM zugangsanfragen WHERE id = ?');
$st->execute([$id]);
$a = $st->fetch();
if ($aktion === '' || !$a || $exp < jetzt() || !hash_equals(signatur('zugang_' . $aktion, (string) $id, $exp), $sig)) {
    seite('Link ungültig', '<p>Der Link ist ungültig oder abgelaufen (gültig ' . ANFRAGE_GUELTIG_TAGE . ' Tage). Offene Anfragen findest du im <a href="/mitglieder/#admin">Admin-Reiter</a>.</p>');
}
if ($a['status'] !== 'offen') {
    seite('Schon erledigt', '<p>Die Anfrage von <strong>' . e($a['email']) . '</strong> wurde bereits ' . ($a['status'] === 'angenommen' ? 'akzeptiert' : 'abgelehnt')
        . ' (' . e($a['erledigt_von']) . ', ' . date('d.m.Y H:i', (int) $a['erledigt_am']) . ').</p><p><a href="/mitglieder/#admin">Zur Admin-Ansicht</a></p>');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vorname = sauber((string) ($_POST['vorname'] ?? ''), 80) ?: $a['vorname'];
    $name = sauber((string) ($_POST['name'] ?? ''), 80) ?: $a['name'];
    $mail = !empty($_POST['mail']);
    $ergebnis = anfrage_entscheiden($a, $aktion === 'annehmen', $vorname, $name, $mail, null);
    if ($ergebnis === 'gesperrt') {
        seite('Adresse gesperrt', '<p>' . e($a['email']) . ' ist im Mitgliederbereich gesperrt. Bitte zuerst im <a href="/mitglieder/#admin">Admin-Reiter</a> entsperren.</p>');
    }
    if ($ergebnis === 'angenommen') {
        seite('Freigeschaltet', '<p><strong>' . e("{$vorname} {$name}") . '</strong> (' . e($a['email']) . ') kann sich jetzt anmelden.</p>'
            . '<p>' . ($mail ? 'Die Begrüssungsmail mit dem Link ist unterwegs.' : 'Es wurde keine Mail verschickt – bitte selbst Bescheid geben.') . '</p>');
    }
    seite('Abgelehnt', '<p>Die Anfrage von ' . e($a['email']) . ' ist abgelehnt.' . ($mail ? ' Die Person hat eine kurze Absage per Mail erhalten.' : ' Es wurde keine Mail verschickt.') . '</p>');
}

$felder = '';
foreach (['a' => $aktion, 'id' => (string) $id, 'exp' => (string) $exp, 's' => $sig] as $k => $v) {
    $felder .= '<input type="hidden" name="' . $k . '" value="' . e($v) . '">';
}
$daten = '<div class="daten"><strong>' . e($a['vorname'] . ' ' . $a['name']) . '</strong><br>' . e($a['email'])
    . ($a['angaben'] !== '' ? '<br><span class="klein">' . e($a['angaben']) . '</span>' : '')
    . '<br><span class="klein">Anfrage vom ' . date('d.m.Y H:i', (int) $a['zeit']) . '</span></div>';

if ($aktion === 'annehmen') {
    seite('Zugang freischalten?', $daten . '<form method="post">' . $felder
        . '<label class="feld"><span>Vorname</span><input type="text" name="vorname" maxlength="80" value="' . e($a['vorname']) . '"></label>'
        . '<label class="feld"><span>Name</span><input type="text" name="name" maxlength="80" value="' . e($a['name']) . '"></label>'
        . '<p class="klein">Vorname und Name erscheinen im Mitgliederbereich als Autor.</p>'
        . '<label><input type="checkbox" name="mail" value="1" checked> Begrüssungsmail mit Link an die Person senden</label>'
        . '<button type="submit">✓ Ja, freischalten</button></form>');
}
seite('Anfrage ablehnen?', $daten . '<form method="post">' . $felder
    . '<label><input type="checkbox" name="mail" value="1" checked> Kurze, neutrale Absage per Mail senden («keine Mitgliedschaft bestätigt, bei Irrtum antworten»)</label>'
    . '<button type="submit" class="nein">✕ Ja, ablehnen</button></form>');
