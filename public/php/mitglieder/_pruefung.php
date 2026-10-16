<?php
declare(strict_types=1);

// Mitgliederbereich – Prüfung hochgeladener Dateien.
// Typ immer über den Inhalt (finfo), nie über die Endung. Abgelehnt werden Makros,
// aktive PDF-Inhalte (JavaScript, Launch, eingebettete Dateien), verschlüsselte PDFs,
// Archive und alles ausserhalb der Liste. Bilder werden neu kodiert (entfernt EXIF/GPS
// und eingebettete Daten).

if (!defined('FWVJ_MITGLIEDER')) {
    http_response_code(404);
    exit;
}

const ERLAUBTE_TYPEN = [
    'application/pdf' => 'pdf',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
    'application/vnd.oasis.opendocument.text' => 'odt',
    'image/png' => 'png',
    'image/jpeg' => 'jpg',
    'image/webp' => 'webp',
];

/** Ergebnis: ['mime' => …, 'ext' => …] oder Ablehnung über fehler(). */
function datei_typ_pruefen(string $pfad): array
{
    $fi = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $fi->file($pfad);

    // Office-Dateien sind ZIP-Container; manche finfo-Versionen melden nur «zip».
    if (in_array($mime, ['application/zip', 'application/octet-stream', 'application/x-zip-compressed'], true)
        || str_starts_with($mime, 'application/vnd.openxmlformats') || $mime === 'application/vnd.oasis.opendocument.text') {
        $mime = office_typ($pfad) ?? $mime;
    }
    if ($mime === 'image/svg+xml') {
        fehler('SVG-Dateien sind nicht erlaubt (können Skripte enthalten). Bitte als PNG oder PDF exportieren.', 'SVG files are not allowed (they can contain scripts). Please export as PNG or PDF.');
    }
    if (!isset(ERLAUBTE_TYPEN[$mime])) {
        fehler('Dieser Dateityp ist nicht erlaubt. Erlaubt: PDF, Word (DOCX), Excel (XLSX), PowerPoint (PPTX), ODT, PNG, JPG, WebP.', 'This file type is not allowed. Allowed: PDF, Word (DOCX), Excel (XLSX), PowerPoint (PPTX), ODT, PNG, JPG, WebP.');
    }
    $ext = ERLAUBTE_TYPEN[$mime];
    if (in_array($ext, ['docx', 'xlsx', 'pptx', 'odt'], true)) {
        office_pruefen($pfad);
    } elseif ($ext === 'pdf') {
        pdf_pruefen($pfad);
    }
    return ['mime' => $mime, 'ext' => $ext];
}

/** Echten Office-Typ aus dem ZIP-Inhalt bestimmen (oder null = kein Office-Dokument). */
function office_typ(string $pfad): ?string
{
    $zip = new ZipArchive();
    if ($zip->open($pfad, ZipArchive::RDONLY) !== true) {
        return null;
    }
    $typ = null;
    $mimetype = $zip->getFromName('mimetype');
    if ($mimetype !== false && trim($mimetype) === 'application/vnd.oasis.opendocument.text') {
        $typ = 'application/vnd.oasis.opendocument.text';
    } elseif ($zip->locateName('word/document.xml') !== false) {
        $typ = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    } elseif ($zip->locateName('xl/workbook.xml') !== false) {
        $typ = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    } elseif ($zip->locateName('ppt/presentation.xml') !== false) {
        $typ = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
    }
    $zip->close();
    return $typ;
}

/** Makros, ActiveX und eingebettete Programme ablehnen; ZIP-Bomben abfangen. */
function office_pruefen(string $pfad): void
{
    $zip = new ZipArchive();
    if ($zip->open($pfad, ZipArchive::RDONLY) !== true) {
        fehler('Die Datei ist beschädigt oder kein gültiges Office-Dokument.', 'The file is damaged or not a valid Office document.');
    }
    $summe = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $st = $zip->statIndex($i);
        if ($st === false) {
            continue;
        }
        $name = strtolower((string) $st['name']);
        $summe += (int) $st['size'];
        if (str_ends_with($name, 'vbaproject.bin') || str_contains($name, 'vbadata') || str_starts_with($name, 'basic/')
            || str_starts_with($name, 'scripts/') || str_contains($name, '/activex/')
            || (str_contains($name, '/embeddings/') && preg_match('/\.(bin|exe|dll|bat|cmd|js|vbs|ps1|scr|msi)$/', $name))) {
            $zip->close();
            fehler('Dokumente mit Makros, ActiveX oder eingebetteten Programmen sind nicht erlaubt. Bitte ohne Makros speichern (z. B. als .docx statt .docm) oder als PDF exportieren.', 'Documents with macros, ActiveX or embedded programs are not allowed. Please save without macros (e.g. .docx instead of .docm) or export as PDF.');
        }
    }
    $ct = (string) $zip->getFromName('[Content_Types].xml');
    $zip->close();
    if (stripos($ct, 'macroEnabled') !== false || stripos($ct, 'vbaProject') !== false) {
        fehler('Dokumente mit Makros sind nicht erlaubt. Bitte ohne Makros speichern oder als PDF exportieren.', 'Documents with macros are not allowed. Please save without macros or export as PDF.');
    }
    if ($summe > 200 * 1024 * 1024) {
        fehler('Die Datei ist ungewöhnlich stark gepackt und wird deshalb abgelehnt.', 'The file is unusually highly compressed and has been rejected.');
    }
}

/**
 * Aktive PDF-Inhalte ablehnen. Durchsucht die Datei UND alle entpackbaren Streams
 * (Objekt-Streams können Schlüssel verstecken) – mit #xx-kodierten Namen normalisiert.
 */
function pdf_pruefen(string $pfad): void
{
    $roh = (string) file_get_contents($pfad);
    if (!str_starts_with(ltrim(substr($roh, 0, 1024)), '%PDF')) {
        fehler('Die Datei ist kein gültiges PDF.', 'The file is not a valid PDF.');
    }
    // Lange Schlüssel überall suchen; «/JS» (3 Zeichen) nur in Textbereichen – in
    // Binärdaten (Bilder, Schriften) käme die Folge sonst zufällig vor.
    $lang = '~/(JavaScript|Launch|EmbeddedFiles?|RichMedia)(?![A-Za-z0-9])|x-javascript~';
    $kurz = '~/JS(?![A-Za-z0-9])~';
    $pruefe = function (string $teil) use ($lang, $kurz): void {
        $probe = substr($teil, 0, 65536);
        $text = $probe === '' || preg_match_all('/[\x09\x0A\x0D\x20-\x7E]/', $probe) / strlen($probe) > 0.85;
        $teil = preg_replace_callback('~/[^\s/<>\[\]()%{}]+~', fn($m) => preg_replace_callback('/#([0-9A-Fa-f]{2})/', fn($h) => chr(hexdec($h[1])), $m[0]), $teil) ?? $teil;
        if (preg_match($lang, $teil) || ($text && preg_match($kurz, $teil))) {
            fehler('PDFs mit JavaScript, Startbefehlen oder eingebetteten Dateien sind nicht erlaubt. Bitte das PDF neu exportieren (z. B. «Drucken als PDF»).', 'PDFs with JavaScript, launch actions or embedded files are not allowed. Please re-export the PDF (e.g. "Print to PDF").');
        }
    };
    if (preg_match('~/Encrypt(?![A-Za-z0-9])~', $roh)) {
        fehler('Passwortgeschützte oder verschlüsselte PDFs können nicht geprüft werden. Bitte ohne Schutz exportieren.', 'Password-protected or encrypted PDFs cannot be checked. Please export without protection.');
    }

    $budget = 60 * 1024 * 1024; // max. entpackte Gesamtmenge
    $ausserhalb = '';            // PDF-Struktur ohne Stream-Inhalte
    $pos = 0;
    $letztesEnde = 0;
    while (($start = strpos($roh, 'stream', $pos)) !== false) {
        $datenStart = $start + 6;
        if (substr($roh, $datenStart, 2) === "\r\n") {
            $datenStart += 2;
        } elseif (in_array(substr($roh, $datenStart, 1), ["\n", "\r"], true)) {
            $datenStart += 1;
        } else {
            $pos = $datenStart; // z. B. «endstream» oder Text
            continue;
        }
        $ende = strpos($roh, 'endstream', $datenStart);
        if ($ende === false) {
            break;
        }
        $ausserhalb .= substr($roh, $letztesEnde, $datenStart - $letztesEnde);
        $letztesEnde = $ende;
        $daten = substr($roh, $datenStart, $ende - $datenStart);
        $pos = $ende + 9;
        $pruefe($daten); // unkomprimierte Streams
        $ctx = @inflate_init(ZLIB_ENCODING_DEFLATE);
        if ($ctx === false) {
            continue;
        }
        $entpackt = '';
        foreach (str_split($daten, 65536) as $stueck) {
            $teil = @inflate_add($ctx, $stueck, ZLIB_SYNC_FLUSH);
            if ($teil === false) {
                break; // kein Flate-Stream (z. B. JPEG) – überspringen
            }
            $entpackt .= $teil;
            if (strlen($entpackt) > $budget) {
                fehler('Das PDF ist zu komplex für die Sicherheitsprüfung. Bitte als einfacheres PDF exportieren.', 'The PDF is too complex for the security check. Please export a simpler PDF.');
            }
        }
        if ($entpackt !== '') {
            $budget -= strlen($entpackt);
            $pruefe($entpackt);
        }
    }
    $ausserhalb .= substr($roh, $letztesEnde);
    $pruefe($ausserhalb);
}

/** Bild laden (mit Grössengrenzen), EXIF-Drehung anwenden. */
function bild_laden(string $pfad, string $mime): GdImage
{
    @ini_set('memory_limit', '256M');
    $info = @getimagesize($pfad);
    if ($info === false) {
        fehler('Das Bild ist beschädigt.', 'The image is damaged.');
    }
    if ($info[0] > MAX_BILD_PIXEL_EINGANG || $info[1] > MAX_BILD_PIXEL_EINGANG) {
        fehler('Das Bild ist zu gross (max. 6000 Pixel je Seite).', 'The image is too large (max. 6000 pixels per side).');
    }
    $bild = match ($mime) {
        'image/png' => @imagecreatefrompng($pfad),
        'image/jpeg' => @imagecreatefromjpeg($pfad),
        'image/webp' => @imagecreatefromwebp($pfad),
        default => false,
    };
    if (!$bild) {
        fehler('Das Bild konnte nicht gelesen werden.', 'The image could not be read.');
    }
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($pfad);
        $drehung = ['3' => 180, '6' => -90, '8' => 90][(string) ($exif['Orientation'] ?? '')] ?? 0;
        if ($drehung !== 0) {
            $gedreht = imagerotate($bild, $drehung, 0);
            if ($gedreht) {
                $bild = $gedreht;
            }
        }
    }
    return $bild;
}

function bild_skalieren(GdImage $bild, int $maxSeite): GdImage
{
    $w = imagesx($bild);
    $h = imagesy($bild);
    if ($w <= $maxSeite && $h <= $maxSeite) {
        return $bild;
    }
    $f = $maxSeite / max($w, $h);
    $neu = imagescale($bild, max(1, (int) round($w * $f)), max(1, (int) round($h * $f)), IMG_BICUBIC);
    return $neu ?: $bild;
}

/** Bild neu kodieren und überschreiben. Rückgabe: [breite, hoehe]. */
function bild_neu_kodieren(string $pfad, string $mime): array
{
    $bild = bild_skalieren(bild_laden($pfad, $mime), MAX_BILD_PIXEL_SEITE);
    if ($mime === 'image/png') {
        imagealphablending($bild, false);
        imagesavealpha($bild, true);
        $ok = imagepng($bild, $pfad, 6);
    } elseif ($mime === 'image/webp') {
        $ok = imagewebp($bild, $pfad, 85);
    } else {
        $ok = imagejpeg($bild, $pfad, 88);
    }
    if (!$ok) {
        fehler('Das Bild konnte nicht verarbeitet werden.', 'The image could not be processed.');
    }
    return [imagesx($bild), imagesy($bild)];
}

/** Vorschaubild (max. 640 px, WebP) erzeugen. Rückgabe: Vorschau-ID oder null. */
function vorschau_erzeugen(string $quelle, string $mime): ?string
{
    if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
        return null;
    }
    $bild = bild_skalieren(bild_laden($quelle, $mime), 640);
    // Transparenz auf Weiss legen (Vorschau ist immer deckend)
    $flach = imagecreatetruecolor(imagesx($bild), imagesy($bild));
    imagefill($flach, 0, 0, imagecolorallocate($flach, 255, 255, 255));
    imagecopy($flach, $bild, 0, 0, 0, 0, imagesx($bild), imagesy($bild));
    $id = zufall_id();
    $ok = function_exists('imagewebp') ? imagewebp($flach, vorschau_pfad($id), 80) : imagejpeg($flach, vorschau_pfad($id), 80);
    if (!$ok) {
        return null;
    }
    @chmod(vorschau_pfad($id), 0600);
    return $id;
}
