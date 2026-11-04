<?php
declare(strict_types=1);
// Mitgliederbereich – Anhang eines Beitrags (Vorstandsinfos, News, Community), nur mit gültiger Sitzung.
// /php/mitglieder/beitrag-datei.php?id=<anhang-id>   Bilder und PDFs inline, Rest als Download
define('FWVJ_MITGLIEDER', true);
require __DIR__ . '/_lib.php';
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
if (!sitzung()) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Bitte anmelden / please log in.');
}
$st = db()->prepare('SELECT * FROM beitrag_anhaenge WHERE id = ?');
$st->execute([(int) ($_GET['id'] ?? 0)]);
$a = $st->fetch();
$pfad = $a ? datei_pfad((string) $a['speicher_id']) : '';
if (!$a || !is_file($pfad)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Nicht gefunden / not found.');
}
$mime = (string) $a['mime'];
$inline = str_starts_with($mime, 'image/') || $mime === 'application/pdf';
$name = preg_replace('/[^\pL\pN ._()-]/u', '_', (string) $a['name']) ?: 'anhang';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($pfad));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename=\"" . str_replace('"', '', $name) . "\"; filename*=UTF-8''" . rawurlencode((string) $a['name']));
header('Cache-Control: private, max-age=86400');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
readfile($pfad);
