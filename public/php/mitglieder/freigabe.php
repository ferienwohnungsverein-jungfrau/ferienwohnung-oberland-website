<?php
declare(strict_types=1);

// Mitgliederbereich – signierte Freigabe-Links aus der Mail an it@ (anonyme Uploads).
// GET zeigt nur eine Rückfrage; erst der Klick (POST) führt aus. So lösen
// Link-Prüfer von Mailprogrammen (z. B. Microsoft Safe Links) nichts versehentlich aus.

define('FWVJ_MITGLIEDER', true);
require __DIR__ . '/_lib.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

$q = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$aktion = in_array($q['a'] ?? '', ['freigeben', 'ablehnen'], true) ? (string) $q['a'] : '';
$id = (string) ($q['id'] ?? '');
$exp = (int) ($q['exp'] ?? 0);
$sig = (string) ($q['s'] ?? '');

function seite(string $titel, string $inhalt): never
{
    $t = htmlspecialchars($titel, ENT_QUOTES, 'UTF-8');
    echo <<<HTML
<!doctype html>
<html lang="de-CH"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>{$t} – Mitgliederbereich</title>
<style>body{margin:0;background:#F9F9F9;font-family:Arial,Helvetica,sans-serif;color:#334155}main{max-width:520px;margin:48px auto;padding:32px;background:#fff;border-radius:12px;border:1px solid rgba(0,77,122,.14)}h1{color:#004D7A;font-size:1.4rem;margin:0 0 16px}button{background:#F4A261;color:#1e293b;border:0;border-radius:4px;padding:12px 24px;font-weight:700;font-size:1rem;cursor:pointer}button.nein{background:#fee2e2}label{display:block;margin:16px 0}a{color:#004D7A}</style>
</head><body><main><h1>{$t}</h1>{$inhalt}</main></body></html>
HTML;
    exit;
}

$d = dokument($id);
if ($aktion === '' || !$d || $exp < jetzt() || !hash_equals(signatur($aktion, $id, $exp), $sig)) {
    seite('Link ungültig', '<p>Der Link ist ungültig oder abgelaufen (gültig ' . SIGNATUR_GUELTIG_TAGE . ' Tage). Bitte in der <a href="/mitglieder/#admin">Admin-Ansicht</a> entscheiden.</p>');
}
$titel = htmlspecialchars($d['titel'], ENT_QUOTES, 'UTF-8');
if ($d['status'] !== 'wartet') {
    $stand = ['sichtbar' => 'bereits freigegeben', 'abgelehnt' => 'bereits abgelehnt', 'geloescht' => 'gelöscht', 'versteckt' => 'versteckt'][$d['status']] ?? $d['status'];
    seite('Schon erledigt', "<p>«{$titel}» ist {$stand}.</p><p><a href=\"/mitglieder/#admin\">Zur Admin-Ansicht</a></p>");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Bewusst eigenständig (ohne api.php): nur Freigeben/Ablehnen wartender Uploads.
    if ($aktion === 'freigeben') {
        db()->prepare("UPDATE dokumente SET status = 'sichtbar', versteckt_grund = '', geaendert = ? WHERE id = ?")->execute([jetzt(), $d['id']]);
        audit('freigeben', null, $d['id'], "«{$d['titel']}» (signierter Link)", 0, 'it@ (signierter Link)');
        seite('Freigegeben', "<p>«{$titel}» ist jetzt für alle Mitglieder sichtbar (Autor: anonym).</p><p><a href=\"/mitglieder/?dok={$d['id']}\">Ansehen</a></p>");
    }
    $sperrliste = !empty($_POST['sperrliste']);
    if ($sperrliste && $d['sha256'] !== '') {
        db()->prepare('INSERT OR REPLACE INTO hash_sperren (sha256, grund, zeit) VALUES (?, ?, ?)')->execute([$d['sha256'], 'abgelehnt: ' . $d['titel'], jetzt()]);
    }
    audit_datei_weg($d['id']);
    dateien_entfernen($d);
    db()->prepare("UPDATE dokumente SET status = 'abgelehnt', speicher_id = NULL, vorschau_id = NULL, erkannter_text = '', geloescht_am = ?, geaendert = ? WHERE id = ?")->execute([jetzt(), jetzt(), $d['id']]);
    audit('ablehnen', null, $d['id'], "«{$d['titel']}» (signierter Link)" . ($sperrliste ? ', SHA-256 auf Sperrliste' : ''), 0, 'it@ (signierter Link)');
    seite('Abgelehnt', "<p>«{$titel}» wurde abgelehnt und die Datei gelöscht." . ($sperrliste ? ' Dieselbe Datei kann nicht erneut hochgeladen werden.' : '') . '</p>');
}

$felder = '';
foreach (['a' => $aktion, 'id' => $id, 'exp' => (string) $exp, 's' => $sig] as $k => $v) {
    $felder .= '<input type="hidden" name="' . $k . '" value="' . htmlspecialchars($v, ENT_QUOTES, 'UTF-8') . '">';
}
$info = "<p><strong>«{$titel}»</strong><br>" . htmlspecialchars($d['kategorie'] . ' · ' . $d['sprache'] . ' · ' . strtoupper($d['format']) . ' · ' . round((int) $d['groesse'] / 1024) . ' KB', ENT_QUOTES, 'UTF-8') . '</p>'
    . '<p>Datei ansehen: in der <a href="/mitglieder/?dok=' . $d['id'] . '">Admin-Ansicht</a> (Anmeldung mit it@ nötig).</p>';
if ($aktion === 'freigeben') {
    seite('Anonymen Upload freigeben?', $info . "<form method=\"post\">{$felder}<button type=\"submit\">Ja, freigeben</button></form>");
}
seite('Anonymen Upload ablehnen?', $info . "<form method=\"post\">{$felder}<label><input type=\"checkbox\" name=\"sperrliste\" value=\"1\"> Datei sperren (kann nicht erneut hochgeladen werden)</label><button type=\"submit\" class=\"nein\">Ja, ablehnen und löschen</button></form>");
