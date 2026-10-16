<?php
declare(strict_types=1);

// Mitgliederbereich – Download und Vorschaubilder, nur mit gültiger Sitzung.
// /php/mitglieder/datei.php?id=<dok>           → Datei als Download (einheitlicher Name)
// /php/mitglieder/datei.php?id=<dok>&vorschau=1 → Vorschaubild (inline)

define('FWVJ_MITGLIEDER', true);
require __DIR__ . '/_lib.php';

header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');

$ich = sitzung();
if (!$ich) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Bitte anmelden / please sign in.');
}
$d = dokument((string) ($_GET['id'] ?? ''));
$darf = $d && (
    $d['status'] === 'sichtbar'
    || $ich['rolle'] === 'admin'
    || ((int) $d['autor_id'] === (int) $ich['id'] && !in_array($d['status'], ['geloescht', 'abgelehnt'], true))
);
if (!$darf) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Nicht gefunden / not found.');
}

if (!empty($_GET['vorschau'])) {
    $pfad = $d['vorschau_id'] ? vorschau_pfad($d['vorschau_id']) : '';
    if ($pfad === '' || !is_file($pfad)) {
        http_response_code(404);
        exit;
    }
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($pfad);
    header('Content-Type: ' . (in_array($mime, ['image/webp', 'image/jpeg'], true) ? $mime : 'application/octet-stream'));
    header('Content-Length: ' . filesize($pfad));
    header('Cache-Control: private, max-age=86400');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    readfile($pfad);
    exit;
}

// Gegen Absaugen: Download-Bremse je Konto
if (anzahl('download', 'konto:' . $ich['id'], 3600) >= LIMIT_DOWNLOADS_STUNDE) {
    alarm('downloads-' . $ich['id'], 'viele Downloads', "Konto {$ich['email']} (IP " . client_ip() . ') hat die Download-Bremse erreicht (' . LIMIT_DOWNLOADS_STUNDE . '/Stunde).');
    http_response_code(429);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Zu viele Downloads in kurzer Zeit – bitte später wieder. / Too many downloads – please try again later.');
}
$pfad = $d['speicher_id'] ? datei_pfad($d['speicher_id']) : '';
if ($pfad === '' || !is_file($pfad)) {
    http_response_code(404);
    exit;
}
ereignis('download', 'konto:' . $ich['id']);
audit('herunterladen', $ich, $d['id']);

$name = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $d['anzeige_name']);
header('Content-Type: ' . $d['mime']);
header('Content-Length: ' . filesize($pfad));
header('Content-Disposition: attachment; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('Cache-Control: private, no-store');
header("Content-Security-Policy: default-src 'none'; sandbox");
readfile($pfad);
