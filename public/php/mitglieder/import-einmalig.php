<?php
declare(strict_types=1);
// EINMAL-Import von Beiträgen samt Anhängen (04.11.2026). Das Repo ist öffentlich: Inhalte dürfen nicht
// hinein. Hier steht nur der SHA-256-Fingerabdruck des Schlüssels; der Schlüssel liegt nicht im Repo.
// Höchstens 3 Aufrufe je Schlüssel, danach gesperrt. Diese Datei wird nach dem Import wieder entfernt.
define('FWVJ_MITGLIEDER', true);
require __DIR__ . '/_lib.php';
require __DIR__ . '/_pruefung.php';
header('X-Robots-Tag: noindex, nofollow');

const IMPORT_SCHLUESSEL_HASH = '84aa52c95c258cae7360edf7fe09aa2fb809b26df4dd4cc5d283937311170c28';
const IMPORT_MAX_AUFRUFE = 3;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    antwort(['success' => false, 'message' => 'Nicht gefunden.'], 404);
}
$ip = client_ip();
if (anzahl('import_fehl', 'ip:' . $ip, 3600) >= 5) {
    antwort(['success' => false, 'message' => 'Zu viele Versuche.'], 429);
}
$schluessel = (string) ($_SERVER['HTTP_X_IMPORT_TOKEN'] ?? '');
if ($schluessel === '' || !hash_equals(IMPORT_SCHLUESSEL_HASH, hash('sha256', $schluessel))) {
    ereignis('import_fehl', 'ip:' . $ip);
    antwort(['success' => false, 'message' => 'Nicht gefunden.'], 404);
}
$pdo = db();
$flag = 'import_' . substr(IMPORT_SCHLUESSEL_HASH, 0, 12);
$st = $pdo->prepare('SELECT wert FROM einstellungen WHERE schluessel = ?');
$st->execute([$flag]);
$bisher = (int) ($st->fetchColumn() ?: 0);
if ($bisher >= IMPORT_MAX_AUFRUFE) {
    antwort(['success' => false, 'message' => 'Bereits erledigt.'], 410);
}
$roh = '';
if (!empty($_FILES['datei']['tmp_name']) && is_uploaded_file($_FILES['datei']['tmp_name'])) {
    $roh = (string) file_get_contents($_FILES['datei']['tmp_name']);
}
$daten = json_decode($roh, true);
$liste = is_array($daten) && isset($daten['beitraege']) ? $daten['beitraege'] : $daten;
if (!is_array($liste) || $liste === [] || count($liste) > 500) {
    antwort(['success' => false, 'message' => 'Keine gültige Beitragsliste.'], 422);
}
$dateien = [];
$f = $_FILES['anhaenge'] ?? null;
if ($f && is_array($f['name'])) {
    foreach ($f['name'] as $i => $name) {
        if (($f['error'][$i] ?? 1) === UPLOAD_ERR_OK && is_uploaded_file($f['tmp_name'][$i])) {
            $dateien[(string) $name] = $f['tmp_name'][$i];
        } else {
            antwort(['success' => false, 'message' => 'Upload-Fehler bei ' . $name . ' (Code ' . ($f['error'][$i] ?? '?') . ')'], 422);
        }
    }
}
$n = beitraege_importieren($liste, 'einmal-import', $dateien);
$pdo->prepare('INSERT OR REPLACE INTO einstellungen (schluessel, wert) VALUES (?, ?)')->execute([$flag, (string) ($bisher + 1)]);
audit('beitraege_import', null, null, "{$n} Beiträge, " . count($dateien) . ' Anhänge (Einmal-Import)', 0, 'einmal-import');
antwort(['success' => true, 'anzahl' => $n, 'anhaenge' => count($dateien), 'aufruf' => $bisher + 1]);
