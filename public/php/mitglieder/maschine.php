<?php
declare(strict_types=1);

// Mitgliederbereich – Adresse für automatische Läufe vom Mac (Stefan 24.11.2026).
//   aktion=news_import  : monatlicher News-Lauf (Stefan-OS/02_Skripte/verein/fwvj_news_lauf.py) stellt
//                         «News aus der Region» direkt online, gekennzeichnet als «automatisch veröffentlicht».
//                         Nur Art «news»; Dubletten (gleicher Link) werden übersprungen, nie überschrieben.
//   aktion=wochenmail   : wöchentlicher Auslöser der Mail «Neu im Mitgliederbereich».
// Schutz: Kopfzeile X-FWVJ-Schluessel; auf dem Server liegt nur der SHA-256 (Admin-Reiter → «Schlüssel erzeugen»).

define('FWVJ_MITGLIEDER', true);
require __DIR__ . '/_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    antwort(['success' => false, 'message' => 'POST erwartet'], 405);
}
$ip = client_ip();
if (ip_gesperrt($ip) || anzahl('maschine_fehl', 'ip:' . $ip, 3600) >= 5) {
    antwort(['success' => false, 'message' => 'gesperrt'], 429);
}
if (!maschine_pruefen((string) ($_SERVER['HTTP_X_FWVJ_SCHLUESSEL'] ?? ''))) {
    ereignis('maschine_fehl', 'ip:' . $ip);
    audit('maschine_abgewiesen', null, null, 'falscher oder fehlender Schlüssel');
    antwort(['success' => false, 'message' => 'Schlüssel ungültig'], 403);
}
if (anzahl('maschine', 'ip:' . $ip, 3600) >= 30) {
    antwort(['success' => false, 'message' => 'zu viele Aufrufe'], 429);
}
ereignis('maschine', 'ip:' . $ip);

$aktion = (string) ($_GET['aktion'] ?? '');

if ($aktion === 'wochenmail') {
    antwort(['success' => true] + wochenmail_senden(false));
}

if ($aktion === 'news_import') {
    $daten = json_decode((string) file_get_contents('php://input'), true);
    $liste = is_array($daten) ? ($daten['beitraege'] ?? $daten) : null;
    if (!is_array($liste)) {
        antwort(['success' => false, 'message' => 'JSON mit «beitraege» erwartet'], 400);
    }
    $pdo = db();
    $linkDa = $pdo->prepare('SELECT 1 FROM beitraege WHERE link = ? LIMIT 1');
    $neu = [];
    $uebersprungen = 0;
    foreach (array_slice($liste, 0, 20) as $q) {
        if (!is_array($q)) {
            continue;
        }
        $link = trim((string) ($q['link'] ?? ''));
        if (!preg_match('#^https?://#i', $link)) {
            $uebersprungen++;
            continue; // News ohne Quelle werden nie veröffentlicht
        }
        $linkDa->execute([$link]);
        if ($linkDa->fetchColumn()) {
            $uebersprungen++;
            continue;
        }
        $q['art'] = 'news';
        $q['autor'] = FWVJ_NEWS_AUTOR;
        $q['schluessel'] = 'auto-news-' . substr(hash('sha256', $link), 0, 20);
        unset($q['anhaenge']);
        $neu[] = $q;
    }
    $n = $neu ? beitraege_importieren($neu, 'news-lauf') : 0;
    if ($neu) {
        $setze = $pdo->prepare('UPDATE beitraege SET automatisch = 1 WHERE schluessel = ?');
        foreach ($neu as $q) {
            $setze->execute([$q['schluessel']]);
        }
    }
    audit('news_lauf', null, null, "{$n} News automatisch veröffentlicht, {$uebersprungen} übersprungen (schon vorhanden / ohne Quelle)");
    antwort(['success' => true, 'veroeffentlicht' => $n, 'uebersprungen' => $uebersprungen]);
}

antwort(['success' => false, 'message' => 'unbekannte Aktion'], 400);
