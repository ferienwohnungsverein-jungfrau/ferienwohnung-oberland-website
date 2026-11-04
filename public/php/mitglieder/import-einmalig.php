<?php
declare(strict_types=1);
// EINMAL-Import der Vorstandsinfos (04.11.2026). Hintergrund: Das Repo ist öffentlich – die Inhalte
// dürfen nicht hinein, und ein Admin-Login war für den Import nicht verfügbar. Hier steht nur der
// SHA-256-Fingerabdruck des Schlüssels; der Schlüssel selbst liegt nicht im Repo. Nach dem ersten
// erfolgreichen Import sperrt sich die Adresse (Einstellung «import_einmalig_erledigt»).
// Diese Datei wird nach dem Import wieder entfernt.
define('FWVJ_MITGLIEDER', true);
require __DIR__ . '/_lib.php';
header('X-Robots-Tag: noindex, nofollow');

const IMPORT_SCHLUESSEL_HASH = '9ec69d5699e5a87dd4b0bb5dd7c73e4a720b289b7dab7fb5fa88a9d7468c3cb7';

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
$st = $pdo->prepare("SELECT wert FROM einstellungen WHERE schluessel = 'import_einmalig_erledigt'");
$st->execute();
if ($st->fetchColumn() !== false) {
    antwort(['success' => false, 'message' => 'Bereits erledigt.'], 410);
}
$daten = json_decode((string) file_get_contents('php://input'), true);
$liste = is_array($daten) && isset($daten['beitraege']) ? $daten['beitraege'] : $daten;
if (!is_array($liste) || $liste === [] || count($liste) > 500) {
    antwort(['success' => false, 'message' => 'Keine gültige Beitragsliste.'], 422);
}
$n = beitraege_importieren($liste, 'einmal-import');
$pdo->prepare("INSERT OR REPLACE INTO einstellungen (schluessel, wert) VALUES ('import_einmalig_erledigt', ?)")->execute([(string) jetzt()]);
audit('beitraege_import', null, null, "{$n} Beiträge (Einmal-Import)", 0, 'einmal-import');
antwort(['success' => true, 'anzahl' => $n]);
