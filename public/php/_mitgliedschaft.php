<?php
declare(strict_types=1);

// Gemeinsame Funktionen für die Mitgliedschafts-Anmeldung
// (mitgliedschaft-anmeldung.php → Bestätigungslink → mitgliedschaft-bestaetigen.php).
// Gibt beim direkten Aufruf nichts aus.
//
// Seit 23.11.2026 trägt der Bestätigungslink Adresse und Geburtsdatum. Er wird deshalb
// verschlüsselt (AES-256-GCM), damit diese Angaben nicht im Klartext in Server-Logs,
// Mail-Archiven oder Link-Prüfern der Mailprogramme stehen. Format: «v2.<base64url>».
// Links im alten Format (base64-JSON ohne Präfix) werden bis zum Ablauf (48 h) weiter angenommen.

date_default_timezone_set('Europe/Zurich');

const FWVJ_ANMELDUNG_MAX_ALTER = 48 * 60 * 60;

// Fassung der Beitrittserklärung, die die Person akzeptiert. Bei jeder Textänderung im
// Formular (src/pages/mitgliedschaft/index.astro + en/membership) hier das Datum nachführen –
// es steht in der Mail an das Sekretariat und belegt, welcher Wortlaut galt.
const FWVJ_ERKLAERUNG_FASSUNG = '23.11.2026';

function fwvj_anmeldung_secret(): string
{
    // 1. Server-Umgebungsvariable, 2. Schlüsseldatei eine Ebene über httpdocs
    // (übersteht den Release-Tausch des Deploy-Hooks), 3. Erstlauf: einmalig erzeugen.
    $secretFile = dirname(__DIR__, 2) . '/form-signing-secret.key';
    $secret = getenv('FORM_SIGNING_SECRET')
        ?: (is_readable($secretFile) ? trim((string) file_get_contents($secretFile)) : '');
    if ($secret !== '') {
        return $secret;
    }
    $generiert = bin2hex(random_bytes(32));
    if (@file_put_contents($secretFile, $generiert . "\n", LOCK_EX) !== false) {
        @chmod($secretFile, 0600);
        return $generiert;
    }
    return 'ferienwohnungsverein-jungfrau-platzhalter-secret';
}

function fwvj_b64url_encode(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function fwvj_b64url_decode(string $text): string|false
{
    return base64_decode(strtr($text, '-_', '+/'), true);
}

/** Verschlüsselt die Angaben und liefert [d, s] für den Bestätigungslink. */
function fwvj_anmeldung_verpacken(array $payload): array
{
    $secret = fwvj_anmeldung_secret();
    $key = hash('sha256', 'fwvj-anmeldung-v2|' . $secret, true);
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt((string) json_encode($payload, JSON_UNESCAPED_UNICODE), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) {
        throw new RuntimeException('Verschlüsselung fehlgeschlagen');
    }
    $d = 'v2.' . fwvj_b64url_encode($iv . $tag . $cipher);
    return [$d, hash_hmac('sha256', $d, $secret)];
}

/** Prüft Signatur und entschlüsselt. Liefert das Payload-Array oder null. */
function fwvj_anmeldung_entpacken(string $d, string $s): ?array
{
    $secret = fwvj_anmeldung_secret();
    if (!hash_equals(hash_hmac('sha256', $d, $secret), $s)) {
        return null;
    }
    if (str_starts_with($d, 'v2.')) {
        $bin = fwvj_b64url_decode(substr($d, 3));
        if ($bin === false || strlen($bin) < 29) {
            return null;
        }
        $key = hash('sha256', 'fwvj-anmeldung-v2|' . $secret, true);
        $json = openssl_decrypt(substr($bin, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($bin, 0, 12), substr($bin, 12, 16));
    } else {
        // Altes Format (vor 23.11.2026): signiertes, unverschlüsseltes JSON.
        $json = fwvj_b64url_decode($d);
    }
    $payload = is_string($json) ? json_decode($json, true) : null;
    return is_array($payload) && isset($payload['email'], $payload['ts']) ? $payload : null;
}

/** Entfernt Zeilenumbrüche (Kopfzeilen-Injection) und begrenzt die Länge. */
function fwvj_feld(string $wert, int $max = 200): string
{
    $wert = trim(str_replace(["\r", "\n", "\t"], ' ', $wert));
    return mb_substr($wert, 0, $max);
}
