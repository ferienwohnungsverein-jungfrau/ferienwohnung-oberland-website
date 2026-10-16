<?php
declare(strict_types=1);

// Mitgliederbereich – gemeinsame Funktionen (Datenbank, Sitzung, Sperren, Audit, Mail).
// Wird nur von api.php, datei.php und freigabe.php eingebunden; direkt aufgerufen
// liefert die Datei nichts aus.
//
// Datenablage: Alles Persistente (SQLite, Dateien, Vorschaubilder, Secret) liegt in
// ../fwvj-mitglieder/ EINE EBENE ÜBER httpdocs – der Deploy-Hook tauscht httpdocs
// bei jedem Deploy komplett aus, und das Repo ist öffentlich. Lokal per
// Umgebungsvariable FWVJ_MITGLIEDER_DIR umlenkbar (z. B. /tmp/fwvj-mitglieder).

if (!defined('FWVJ_MITGLIEDER')) {
    http_response_code(404);
    exit;
}

// ── Einstellungen (bewusst als Konstanten, leicht änderbar) ──
const FWVJ_ERST_ADMIN = 'it@ferienwohnungsverein-jungfrau.ch';
const FWVJ_IT_ADRESSE = 'it@ferienwohnungsverein-jungfrau.ch';
const FWVJ_ABSENDER = 'Ferienwohnungsverein Jungfrau <noreply@ferienwohnungsverein-jungfrau.ch>';
const FWVJ_BASIS_URL_STANDARD = 'https://ferienwohnungsverein-jungfrau.ch';

const MAX_DATEI_BYTES = 5 * 1024 * 1024;          // 5 MB je Datei (Stefan 16.10.2026)
const MAX_VORSCHAU_BYTES = 1024 * 1024;           // vom Browser erzeugtes Vorschaubild
const MAX_VOLUMEN_JE_KONTO = 500 * 1024 * 1024;   // 500 MB Gesamtvolumen je Konto
const MAX_BILD_PIXEL_SEITE = 2500;                // grössere Bilder werden verkleinert
const MAX_BILD_PIXEL_EINGANG = 6000;              // noch grösser = abgelehnt (Speicher)

const CODE_GUELTIG_SEK = 600;                     // Login-Code 10 Minuten
const CODE_MAX_VERSUCHE = 5;
const SITZUNG_TAGE = 30;

const LIMIT_CODE_ADRESSE_15MIN = 3;
const LIMIT_CODE_ADRESSE_TAG = 10;
const LIMIT_CODE_IP_STUNDE = 20;
const LIMIT_CODE_ADRESSEN_JE_IP_STUNDE = 5;       // mehr verschiedene Adressen = Drosselung + Alarm
const LIMIT_FEHLCODES_IP_STUNDE = 20;
const LIMIT_UPLOADS_STUNDE = 20;                  // je Konto UND je IP
const LIMIT_UPLOADS_TAG = 100;
const LIMIT_DOWNLOADS_STUNDE = 200;               // je Konto (gegen Absaugen)
const LIMIT_MELDUNGEN_STUNDE = 10;
const ALARM_LOESCHUNGEN_STUNDE = 10;
const MELDUNGEN_BIS_AUSBLENDEN = 2;               // verschiedene Mitglieder

const AUFBEWAHRUNG_TAGE = 90;                     // Audit + IP (Uploads: solange online + 90 Tage)
const SIGNATUR_GUELTIG_TAGE = 7;                  // Freigabe-Links in Mails an it@

// ── Hilfen ──

function basis_url(): string
{
    return rtrim(getenv('FWVJ_BASIS_URL') ?: FWVJ_BASIS_URL_STANDARD, '/');
}

function daten_dir(): string
{
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }
    $dir = getenv('FWVJ_MITGLIEDER_DIR') ?: dirname(__DIR__, 3) . '/fwvj-mitglieder';
    foreach (['', '/dateien', '/vorschau'] as $sub) {
        $pfad = $dir . $sub;
        if (!is_dir($pfad)) {
            if (!@mkdir($pfad, 0700, true) && !is_dir($pfad)) {
                antwort(['success' => false, 'message' => 'Datenablage nicht verfügbar.'], 500);
            }
            @chmod($pfad, 0700);
        }
    }
    // Zweite Sicherung, falls der Ordner je doch unter einem Webroot landet.
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "Require all denied\nDeny from all\n");
        @chmod($ht, 0600);
    }
    return $dir;
}

function secret(): string
{
    static $s = null;
    if ($s !== null) {
        return $s;
    }
    $datei = daten_dir() . '/secret.key';
    $s = is_readable($datei) ? trim((string) file_get_contents($datei)) : '';
    if ($s === '') {
        // Erstlauf: Secret einmalig auf dem Server erzeugen (Muster mitgliedschaft-anmeldung.php)
        $s = bin2hex(random_bytes(32));
        if (@file_put_contents($datei, $s . "\n", LOCK_EX) === false) {
            antwort(['success' => false, 'message' => 'Secret konnte nicht angelegt werden.'], 500);
        }
        @chmod($datei, 0600);
    }
    return $s;
}

function antwort(array $daten, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
    echo json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fehler(string $de, string $en, int $code = 422): never
{
    antwort(['success' => false, 'message' => sprache() === 'en' ? $en : $de], $code);
}

function sprache(): string
{
    $l = $_POST['locale'] ?? $_GET['locale'] ?? $_SERVER['HTTP_X_LOCALE'] ?? 'de';
    return $l === 'en' ? 'en' : 'de';
}

function jetzt(): int
{
    return time();
}

function client_ip(): string
{
    // Bewusst nur REMOTE_ADDR: X-Forwarded-For kann jeder selbst setzen.
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 64);
}

function user_agent(): string
{
    return mb_substr(preg_replace('/[\x00-\x1F\x7F]/', '', (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 300);
}

function zufall_id(int $bytes = 16): string
{
    return bin2hex(random_bytes($bytes));
}

function sauber(string $s, int $max): string
{
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    return mb_substr($s, 0, $max);
}

function email_norm(string $e): string
{
    return mb_strtolower(trim($e));
}

function katalog(): array
{
    static $k = null;
    if ($k === null) {
        $k = json_decode((string) file_get_contents(__DIR__ . '/katalog.json'), true) ?: [];
    }
    return $k;
}

function katalog_keys(string $teil): array
{
    return array_map(fn($e) => $e['key'], katalog()[$teil] ?? []);
}

function slug(string $s, int $max = 60): string
{
    $s = mb_strtolower($s);
    $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'à' => 'a', 'â' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ù' => 'u', 'û' => 'u', 'ç' => 'c', 'ì' => 'i', 'ò' => 'o', 'á' => 'a', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
    if (function_exists('iconv')) {
        $s = (string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    }
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    $s = trim(substr($s, 0, $max), '-');
    return $s !== '' ? $s : 'dokument';
}

/** Einheitlicher Anzeige-/Downloadname: <kategorie>_<titel>_<sprache>_<JJJJ-MM>.<ext> */
function anzeige_name(string $kategorie, string $titel, string $sprache, string $ext, int $zeit): string
{
    $sp = ['mehrsprachig' => 'multi', 'neutral' => 'xx'][$sprache] ?? $sprache;
    return $kategorie . '_' . slug($titel) . '_' . $sp . '_' . date('Y-m', $zeit) . '.' . $ext;
}

// ── Datenbank ──

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $pfad = daten_dir() . '/mitglieder.sqlite';
    $neu = !is_file($pfad);
    $pdo = new PDO('sqlite:' . $pfad, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    if ($neu) {
        @chmod($pfad, 0600);
    }
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    migrieren($pdo);
    return $pdo;
}

function migrieren(PDO $pdo): void
{
    $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
    if ($version < 1) {
        $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS mitglieder (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email TEXT NOT NULL UNIQUE,
  vorname TEXT NOT NULL DEFAULT '',
  name TEXT NOT NULL DEFAULT '',
  rolle TEXT NOT NULL DEFAULT 'mitglied' CHECK (rolle IN ('mitglied','admin')),
  status TEXT NOT NULL DEFAULT 'aktiv' CHECK (status IN ('aktiv','gesperrt')),
  angelegt_am INTEGER NOT NULL,
  angelegt_von TEXT NOT NULL DEFAULT '',
  letzte_anmeldung INTEGER
);
CREATE TABLE IF NOT EXISTS login_codes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email TEXT NOT NULL,
  code_hash TEXT NOT NULL,
  erstellt INTEGER NOT NULL,
  ablauf INTEGER NOT NULL,
  versuche INTEGER NOT NULL DEFAULT 0,
  verbraucht INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS ix_codes_email ON login_codes(email);
CREATE TABLE IF NOT EXISTS sitzungen (
  token_hash TEXT PRIMARY KEY,
  mitglied_id INTEGER NOT NULL REFERENCES mitglieder(id) ON DELETE CASCADE,
  csrf TEXT NOT NULL,
  erstellt INTEGER NOT NULL,
  ablauf INTEGER NOT NULL,
  zuletzt INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS dokumente (
  id TEXT PRIMARY KEY,
  titel TEXT NOT NULL,
  kategorie TEXT NOT NULL,
  sprache TEXT NOT NULL,
  format TEXT NOT NULL,
  mime TEXT NOT NULL,
  tags TEXT NOT NULL DEFAULT '',
  seitenformat TEXT NOT NULL DEFAULT '',
  anzeige_name TEXT NOT NULL,
  original_name TEXT NOT NULL DEFAULT '',
  speicher_id TEXT,
  vorschau_id TEXT,
  groesse INTEGER NOT NULL DEFAULT 0,
  sha256 TEXT NOT NULL DEFAULT '',
  erkannter_text TEXT NOT NULL DEFAULT '',
  autor_id INTEGER REFERENCES mitglieder(id) ON DELETE SET NULL,
  anonym INTEGER NOT NULL DEFAULT 0,
  status TEXT NOT NULL CHECK (status IN ('sichtbar','wartet','versteckt','abgelehnt','geloescht')),
  versteckt_grund TEXT NOT NULL DEFAULT '',
  version INTEGER NOT NULL DEFAULT 1,
  erstellt INTEGER NOT NULL,
  geaendert INTEGER NOT NULL,
  geloescht_am INTEGER
);
CREATE INDEX IF NOT EXISTS ix_dok_status ON dokumente(status);
CREATE INDEX IF NOT EXISTS ix_dok_autor ON dokumente(autor_id);
CREATE TABLE IF NOT EXISTS meldungen (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  dokument_id TEXT NOT NULL,
  mitglied_id INTEGER NOT NULL,
  grund TEXT NOT NULL DEFAULT '',
  zeit INTEGER NOT NULL,
  erledigt INTEGER NOT NULL DEFAULT 0,
  UNIQUE (dokument_id, mitglied_id)
);
CREATE TABLE IF NOT EXISTS audit (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  zeit INTEGER NOT NULL,
  mitglied_id INTEGER,
  email TEXT NOT NULL DEFAULT '',
  aktion TEXT NOT NULL,
  dokument_id TEXT,
  ip TEXT NOT NULL DEFAULT '',
  user_agent TEXT NOT NULL DEFAULT '',
  details TEXT NOT NULL DEFAULT '',
  haengt_an_datei INTEGER NOT NULL DEFAULT 0,
  datei_weg_am INTEGER
);
CREATE INDEX IF NOT EXISTS ix_audit_zeit ON audit(zeit);
CREATE INDEX IF NOT EXISTS ix_audit_dok ON audit(dokument_id);
CREATE TABLE IF NOT EXISTS ereignisse (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  art TEXT NOT NULL,
  schluessel TEXT NOT NULL,
  wert TEXT NOT NULL DEFAULT '',
  zeit INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_ereignisse ON ereignisse(art, schluessel, zeit);
CREATE TABLE IF NOT EXISTS ip_sperren (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  netz TEXT NOT NULL,
  bis INTEGER NOT NULL,
  grund TEXT NOT NULL DEFAULT '',
  erstellt INTEGER NOT NULL,
  erstellt_von TEXT NOT NULL DEFAULT ''
);
CREATE TABLE IF NOT EXISTS hash_sperren (
  sha256 TEXT PRIMARY KEY,
  grund TEXT NOT NULL DEFAULT '',
  zeit INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS einstellungen (
  schluessel TEXT PRIMARY KEY,
  wert TEXT NOT NULL
);
SQL);
        $st = $pdo->prepare('INSERT OR IGNORE INTO mitglieder (email, vorname, name, rolle, status, angelegt_am, angelegt_von) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $st->execute([FWVJ_ERST_ADMIN, 'Vorstand', 'IT', 'admin', 'aktiv', jetzt(), 'system']);
        $pdo->exec("INSERT OR IGNORE INTO einstellungen (schluessel, wert) VALUES ('mail_bei_veroeffentlichung', '1')");
        $pdo->exec('PRAGMA user_version = 1');
    }
    if ($version < 2) {
        // Vorstands-Postfächer des Vereins freischalten (Stefan 16.10.2026). Nur die
        // Funktionsadressen der Vereinsdomain – persönliche Adressen gehören nie ins
        // öffentliche Repo, die schaltet it@ im Admin-Reiter frei. OR IGNORE: eine
        // spätere Sperre oder Namensänderung durch den Admin bleibt bestehen.
        $st = $pdo->prepare("INSERT OR IGNORE INTO mitglieder (email, vorname, name, rolle, status, angelegt_am, angelegt_von) VALUES (?, ?, ?, 'mitglied', 'aktiv', ?, 'system')");
        foreach ([
            ['vorstand@ferienwohnungsverein-jungfrau.ch', 'Vorstand', ''],
            ['sekretariat@ferienwohnungsverein-jungfrau.ch', 'Sekretariat', ''],
            ['medien@ferienwohnungsverein-jungfrau.ch', 'Medien und Kommunikation', ''],
            ['finanzen@ferienwohnungsverein-jungfrau.ch', 'Finanzen', ''],
        ] as [$email, $vorname, $name]) {
            $st->execute([$email, $vorname, $name, jetzt()]);
        }
        $pdo->exec('PRAGMA user_version = 2');
    }
    // Künftige Schemaänderungen: if ($version < 3) { … PRAGMA user_version = 3 } – idempotent halten.
}

function einstellung(string $schluessel, string $standard = ''): string
{
    $st = db()->prepare('SELECT wert FROM einstellungen WHERE schluessel = ?');
    $st->execute([$schluessel]);
    $w = $st->fetchColumn();
    return $w === false ? $standard : (string) $w;
}

// ── Mengenbremsen ──

function ereignis(string $art, string $schluessel, string $wert = ''): void
{
    db()->prepare('INSERT INTO ereignisse (art, schluessel, wert, zeit) VALUES (?, ?, ?, ?)')
        ->execute([$art, $schluessel, $wert, jetzt()]);
}

function anzahl(string $art, string $schluessel, int $fensterSek): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM ereignisse WHERE art = ? AND schluessel = ? AND zeit > ?');
    $st->execute([$art, $schluessel, jetzt() - $fensterSek]);
    return (int) $st->fetchColumn();
}

function anzahl_verschieden(string $art, string $schluessel, int $fensterSek): int
{
    $st = db()->prepare('SELECT COUNT(DISTINCT wert) FROM ereignisse WHERE art = ? AND schluessel = ? AND zeit > ?');
    $st->execute([$art, $schluessel, jetzt() - $fensterSek]);
    return (int) $st->fetchColumn();
}

/** Alarm-Mail an it@ – höchstens einmal je Stunde und Anlass. */
function alarm(string $anlass, string $betreff, string $text): void
{
    if (anzahl('alarm', $anlass, 3600) > 0) {
        return;
    }
    ereignis('alarm', $anlass);
    mail_an_it('Auffälligkeit: ' . $betreff, $text . "\n\nDas System drosselt automatisch. Admin-Ansicht: " . basis_url() . "/mitglieder/#admin\n");
}

// ── IP-Sperren ──

/** Netz normalisieren: IPv4 einzeln (/32) oder /24, IPv6 /64 (oder /128). */
function ip_netz(string $ip, string $umfang): ?string
{
    $bin = @inet_pton($ip);
    if ($bin === false) {
        return null;
    }
    $v6 = strlen($bin) === 16;
    $bits = $v6 ? ($umfang === 'einzeln' ? 128 : 64) : ($umfang === 'einzeln' ? 32 : 24);
    $maskiert = ip_maskieren($bin, $bits);
    return inet_ntop($maskiert) . '/' . $bits;
}

function ip_maskieren(string $bin, int $bits): string
{
    $out = '';
    for ($i = 0; $i < strlen($bin); $i++) {
        $b = max(0, min(8, $bits - $i * 8));
        $maske = $b === 0 ? 0 : (0xFF << (8 - $b)) & 0xFF;
        $out .= chr(ord($bin[$i]) & $maske);
    }
    return $out;
}

function ip_im_netz(string $ip, string $netz): bool
{
    [$basis, $bits] = array_pad(explode('/', $netz, 2), 2, null);
    $a = @inet_pton($ip);
    $b = @inet_pton((string) $basis);
    if ($a === false || $b === false || strlen($a) !== strlen($b)) {
        return false;
    }
    return ip_maskieren($a, (int) $bits) === $b;
}

function ip_gesperrt(?string $ip = null): bool
{
    $ip ??= client_ip();
    $st = db()->prepare('SELECT netz FROM ip_sperren WHERE bis > ?');
    $st->execute([jetzt()]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $netz) {
        if (ip_im_netz($ip, (string) $netz)) {
            return true;
        }
    }
    return false;
}

// ── Sitzung ──

const SITZUNG_COOKIE = 'fwvj_mitglied';

function cookie_sicher(): bool
{
    return getenv('FWVJ_UNSICHER_LOKAL') !== '1';
}

function sitzung_setzen(int $mitgliedId): string
{
    $token = zufall_id(32);
    $csrf = zufall_id(16);
    $ablauf = jetzt() + SITZUNG_TAGE * 86400;
    db()->prepare('INSERT INTO sitzungen (token_hash, mitglied_id, csrf, erstellt, ablauf, zuletzt) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([hash('sha256', $token), $mitgliedId, $csrf, jetzt(), $ablauf, jetzt()]);
    setcookie(SITZUNG_COOKIE, $token, [
        'expires' => $ablauf,
        'path' => '/',
        'secure' => cookie_sicher(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    return $csrf;
}

function sitzung_beenden(): void
{
    $token = (string) ($_COOKIE[SITZUNG_COOKIE] ?? '');
    if ($token !== '') {
        db()->prepare('DELETE FROM sitzungen WHERE token_hash = ?')->execute([hash('sha256', $token)]);
    }
    setcookie(SITZUNG_COOKIE, '', ['expires' => 1, 'path' => '/', 'secure' => cookie_sicher(), 'httponly' => true, 'samesite' => 'Lax']);
}

/** Angemeldetes, aktives Mitglied samt csrf – oder null. */
function sitzung(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    $cache = null;
    $token = (string) ($_COOKIE[SITZUNG_COOKIE] ?? '');
    if ($token === '' || !ctype_xdigit($token)) {
        return null;
    }
    $st = db()->prepare('SELECT m.*, s.csrf, s.token_hash, s.zuletzt FROM sitzungen s JOIN mitglieder m ON m.id = s.mitglied_id WHERE s.token_hash = ? AND s.ablauf > ?');
    $st->execute([hash('sha256', $token), jetzt()]);
    $row = $st->fetch();
    if (!$row || $row['status'] !== 'aktiv') {
        return null;
    }
    if (jetzt() - (int) $row['zuletzt'] > 300) {
        db()->prepare('UPDATE sitzungen SET zuletzt = ? WHERE token_hash = ?')->execute([jetzt(), $row['token_hash']]);
    }
    $cache = $row;
    return $row;
}

function sitzung_pflicht(): array
{
    $m = sitzung();
    if (!$m) {
        fehler('Bitte melde dich an.', 'Please sign in.', 401);
    }
    return $m;
}

function admin_pflicht(): array
{
    $m = sitzung_pflicht();
    if ($m['rolle'] !== 'admin') {
        fehler('Nur für Admins.', 'Admins only.', 403);
    }
    return $m;
}

/** Schreibende Aufrufe: gleiche Herkunft + CSRF-Token (sofern angemeldet). */
function post_pruefen(bool $csrfNoetig = true): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        fehler('Methode nicht erlaubt.', 'Method not allowed.', 405);
    }
    // Fremde Seiten dürfen nicht schreiben: Origin (falls gesendet) muss unser Host sein.
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '') {
        $erlaubt = [explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''))[0], parse_url(basis_url(), PHP_URL_HOST)];
        if (!in_array(parse_url($origin, PHP_URL_HOST), $erlaubt, true)) {
            fehler('Ungültige Herkunft.', 'Invalid origin.', 403);
        }
    }
    if ($csrfNoetig) {
        $m = sitzung_pflicht();
        $token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($token === '' || !hash_equals((string) $m['csrf'], $token)) {
            fehler('Sitzung abgelaufen – bitte Seite neu laden.', 'Session expired – please reload the page.', 403);
        }
    }
}

// ── Audit ──

/**
 * Jede Aktion protokollieren. $haengtAnDatei = 1 für Upload/Ersetzen: dieser Eintrag
 * (mit IP) bleibt, solange die Datei online ist, und wird 90 Tage danach gelöscht.
 */
function audit(string $aktion, ?array $mitglied, ?string $dokumentId = null, string $details = '', int $haengtAnDatei = 0, ?string $email = null): void
{
    db()->prepare('INSERT INTO audit (zeit, mitglied_id, email, aktion, dokument_id, ip, user_agent, details, haengt_an_datei) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([
            jetzt(),
            $mitglied['id'] ?? null,
            $email ?? ($mitglied['email'] ?? ''),
            $aktion,
            $dokumentId,
            client_ip(),
            user_agent(),
            mb_substr($details, 0, 1000),
            $haengtAnDatei,
        ]);
}

/** Datei ist nicht mehr online → Aufbewahrungsfrist der Upload-Einträge beginnt. */
function audit_datei_weg(string $dokumentId): void
{
    db()->prepare('UPDATE audit SET datei_weg_am = ? WHERE dokument_id = ? AND haengt_an_datei = 1 AND datei_weg_am IS NULL')
        ->execute([jetzt(), $dokumentId]);
}

/** Aufräumen ohne Cron: läuft höchstens einmal pro Stunde beim nächsten Aufruf. */
function aufraeumen(): void
{
    $pdo = db();
    if ((int) einstellung('letztes_aufraeumen', '0') > jetzt() - 3600) {
        return;
    }
    $pdo->prepare("INSERT OR REPLACE INTO einstellungen (schluessel, wert) VALUES ('letztes_aufraeumen', ?)")->execute([(string) jetzt()]);
    $grenze = jetzt() - AUFBEWAHRUNG_TAGE * 86400;
    $pdo->prepare('DELETE FROM audit WHERE haengt_an_datei = 0 AND zeit < ?')->execute([$grenze]);
    $pdo->prepare('DELETE FROM audit WHERE haengt_an_datei = 1 AND datei_weg_am IS NOT NULL AND datei_weg_am < ?')->execute([$grenze]);
    $pdo->prepare('DELETE FROM meldungen WHERE zeit < ? AND (erledigt = 1 OR dokument_id IN (SELECT id FROM dokumente WHERE status IN (\'geloescht\',\'abgelehnt\')))')->execute([$grenze]);
    $pdo->prepare("DELETE FROM dokumente WHERE status IN ('geloescht','abgelehnt') AND geloescht_am < ?")->execute([$grenze]);
    $pdo->prepare('DELETE FROM login_codes WHERE erstellt < ?')->execute([jetzt() - 86400]);
    $pdo->prepare('DELETE FROM sitzungen WHERE ablauf < ?')->execute([jetzt()]);
    $pdo->prepare('DELETE FROM ereignisse WHERE zeit < ?')->execute([jetzt() - 2 * 86400]);
    $pdo->prepare('DELETE FROM ip_sperren WHERE bis < ?')->execute([jetzt()]);
}

// ── Mail ──

function mail_senden(string $an, string $betreff, string $text): bool
{
    $an = str_replace(["\r", "\n"], '', $an);
    $headers = 'From: ' . FWVJ_ABSENDER . "\r\n"
        . 'Reply-To: ' . FWVJ_IT_ADRESSE . "\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n"
        . "X-Auto-Response-Suppress: All\r\n";
    $subject = '=?UTF-8?B?' . base64_encode(str_replace(["\r", "\n"], ' ', $betreff)) . '?=';
    return @mail($an, $subject, $text, $headers);
}

function mail_an_it(string $betreff, string $text): void
{
    mail_senden(FWVJ_IT_ADRESSE, '[Mitgliederbereich] ' . $betreff, $text);
}

// ── Signierte Admin-Links (Freigabe anonymer Uploads) ──

function signatur(string $aktion, string $id, int $ablauf): string
{
    return hash_hmac('sha256', "freigabe|{$aktion}|{$id}|{$ablauf}", secret());
}

function freigabe_link(string $aktion, string $id): string
{
    $ablauf = jetzt() + SIGNATUR_GUELTIG_TAGE * 86400;
    return basis_url() . '/php/mitglieder/freigabe.php?' . http_build_query([
        'a' => $aktion, 'id' => $id, 'exp' => $ablauf, 's' => signatur($aktion, $id, $ablauf),
    ]);
}

// ── Dokumente ──

function dokument(string $id): ?array
{
    if (!preg_match('/^[a-f0-9]{16}$/', $id)) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM dokumente WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function datei_pfad(string $speicherId): string
{
    return daten_dir() . '/dateien/' . preg_replace('/[^a-f0-9]/', '', $speicherId);
}

function vorschau_pfad(string $vorschauId): string
{
    return daten_dir() . '/vorschau/' . preg_replace('/[^a-f0-9]/', '', $vorschauId);
}

function dateien_entfernen(array $dok): void
{
    if (!empty($dok['speicher_id'])) {
        @unlink(datei_pfad($dok['speicher_id']));
    }
    if (!empty($dok['vorschau_id'])) {
        @unlink(vorschau_pfad($dok['vorschau_id']));
    }
}

/** Autor für die Anzeige: bei anonymen Uploads nur Admin und Autor selbst sehen den Namen. */
function autor_name(array $m): string
{
    return trim(($m['vorname'] ?? '') . ' ' . ($m['name'] ?? '')) ?: (string) ($m['email'] ?? '');
}
