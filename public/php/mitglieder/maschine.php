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

// --- Erinnerung an gescheiterte Logins (Stefan 25.11.2026) ---------------------------------------
// Viele Mitglieder versuchten sich anzumelden, ohne freigeschaltet zu sein (Protokoll «code_unbekannt»).
// login_versuche     : listet diese Adressen (ohne alle, die unter «Mitglieder» stehen) – nur lesen.
// erinnerung_zugang  : schickt jeder Adresse EINE eigene Mail mit dem Weg «Zugang anfragen».
//                      {"vorschau": true} → nur ein Muster an it@ (Gut zum Druck);
//                      {"adressen": [...]} → Versand; jede Adresse höchstens einmal (Protokoll «erinnerung_zugang»),
//                      nur Adressen aus login_versuche, höchstens 100 pro Aufruf.

/** Adressen mit gescheitertem Login, die nicht unter «Mitglieder» stehen. */
function login_versuche(): array
{
    $sql = "SELECT a.email, COUNT(*) AS versuche, MIN(a.zeit) AS erster, MAX(a.zeit) AS letzter,
                   (SELECT z.status FROM zugangsanfragen z WHERE z.email = a.email ORDER BY z.zeit DESC LIMIT 1) AS anfrage,
                   (SELECT MAX(b.zeit) FROM audit b WHERE b.aktion = 'erinnerung_zugang' AND b.email = a.email) AS erinnert
            FROM audit a
            WHERE a.aktion = 'code_unbekannt' AND a.email <> ''
              AND a.email NOT IN (SELECT email FROM mitglieder)
            GROUP BY a.email ORDER BY letzter DESC";
    return db()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function erinnerung_zugang_senden(string $an): bool
{
    $de = 'https://ferienwohnungsverein-jungfrau.ch/mitglieder/#zugang';
    $en = 'https://ferienwohnungsverein-jungfrau.ch/en/members/#zugang';
    $betreff = zweisprachig_betreff('Mitgliederbereich: So erhalten Sie Zugang', 'Members’ area: how to get access');
    $schritteDe = ['«Zugang anfragen» öffnen (Link unten).', 'Name und diese E-Mail-Adresse eingeben und absenden.',
        'Das Sekretariat prüft die Mitgliedschaft und schaltet Sie frei – Sie erhalten eine Bestätigung per Mail. Danach melden Sie sich ohne Passwort an: E-Mail-Adresse eingeben, Link aus der Mail anklicken, fertig.'];
    $schritteEn = ['Open “Request access” (link below).', 'Enter your name and this email address and send.',
        'The secretariat checks your membership and activates your access – you will receive a confirmation by email. After that you log in without a password: enter your email address, click the link in the email, done.'];
    $introDe = 'Sie haben kürzlich versucht, sich im Mitgliederbereich des Ferienwohnungsvereins Jungfrau anzumelden – das hat leider nicht geklappt. Das lag nicht an Ihnen: Aus Datenschutzgründen haben wir die E-Mail-Adressen unserer Mitglieder nicht einfach alle im Mitgliederbereich hinterlegt. Eine Adresse wird erst freigeschaltet, wenn das Mitglied den Zugang selbst anfragt. Das dauert eine Minute:';
    $introEn = 'You recently tried to log in to the members’ area of the Ferienwohnungsverein Jungfrau – unfortunately it did not work. That was not your fault: for data protection reasons we did not simply store all our members’ email addresses in the members’ area. An address is only activated once the member requests access. It takes one minute:';
    $neuDe = 'Neu im Mitgliederbereich: Neben den Infos des Vorstands und der Community finden Sie jetzt auch News aus der Region – dazu Vorlagen für die Vermietung.';
    $neuEn = 'New in the members’ area: besides updates from the board and the community, you will now also find regional news – plus templates for renting.';
    $fragenDe = 'Fragen? Antworten Sie einfach auf diese Mail – sie geht an unser Sekretariat.';
    $fragenEn = 'Questions? Simply reply to this email – it goes to our secretariat.';
    $grussDe = "Freundliche Grüsse\nVorstand und Sekretariat\nFerienwohnungsverein Jungfrau";
    $grussEn = "Kind regards\nBoard and Secretariat\nFerienwohnungsverein Jungfrau";
    $nummern = static fn (array $s): string => implode("\n", array_map(static fn ($i, $t) => ($i + 1) . '. ' . $t, array_keys($s), $s));
    $text = zweisprachig_text(
        "Guten Tag\n\n{$introDe}\n\n" . $nummern($schritteDe) . "\n\nZugang anfragen: {$de}\n\n{$neuDe}\n\n{$fragenDe}\n\n{$grussDe}",
        "Hello\n\n{$introEn}\n\n" . $nummern($schritteEn) . "\n\nRequest access: {$en}\n\n{$neuEn}\n\n{$fragenEn}\n\n{$grussEn}"
    );
    $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $liste = static fn (array $s): string => '<ol style="margin:0 0 16px;padding-left:22px;">' . implode('', array_map(static fn ($t) => '<li style="margin:0 0 6px;">' . $e($t) . '</li>', $s)) . '</ol>';
    $teil = static fn (string $hallo, string $intro, array $schritte, string $url, string $knopf, string $neu, string $fragen, string $gruss): string =>
        '<p style="margin:0 0 16px;">' . $e($hallo) . '</p><p style="margin:0 0 16px;">' . $e($intro) . '</p>' . $liste($schritte)
        . '<p style="margin:0 0 20px;">' . mail_knopf($url, $knopf) . '</p>'
        . '<p style="margin:0 0 16px;"><strong>' . $e($neu) . '</strong></p>'
        . '<p style="margin:0 0 16px;font-size:14px;">' . $e($fragen) . '</p>'
        . '<p style="margin:0;">' . nl2br($e($gruss)) . '</p>';
    $html = zweisprachig_html(
        $teil('Guten Tag', $introDe, $schritteDe, $de, 'Zugang anfragen', $neuDe, $fragenDe, $grussDe),
        $teil('Hello', $introEn, $schritteEn, $en, 'Request access', $neuEn, $fragenEn, $grussEn)
    );
    return mail_senden_html($an, $betreff, $text, mail_html_rahmen($html, 'de'), FWVJ_SEKRETARIAT_ADRESSE);
}

// Einladungslink WhatsApp-Gruppe für die Willkommensmail setzen (Juliet 26.11.); {"link":"…"} oder {"link":""} = entfernen.
// Mit {"vorschau":true} gehen zusätzlich Muster der Willkommens- und der Begrüssungsmail an it@.
if ($aktion === 'whatsapp_link') {
    $daten = json_decode((string) file_get_contents('php://input'), true);
    $daten = is_array($daten) ? $daten : [];
    if (array_key_exists('link', $daten)) {
        $l = trim((string) $daten['link']);
        if (preg_match('~^(https://chat\.whatsapp\.com/[A-Za-z0-9]{10,40})(?:\?.*)?$~', $l, $m)) {
            $l = $m[1];
        } elseif ($l !== '') {
            antwort(['success' => false, 'message' => 'nur https://chat.whatsapp.com/… erlaubt'], 400);
        }
        einstellung_setzen('whatsapp_gruppe_link', $l);
        audit('whatsapp_link_gesetzt', null, null, $l === '' ? 'entfernt' : 'gesetzt');
    }
    $vorschau = null;
    if (!empty($daten['vorschau'])) {
        $vorschau = willkommen_senden(FWVJ_IT_ADRESSE, 'Muster', ['angaben' => 'Neue Mitgliedschaft · 6 Betten']);
        $vorschau = begruessung_senden(FWVJ_IT_ADRESSE, 'Muster', 'de') && $vorschau;
        audit('willkommen_vorschau', null, null, $vorschau ? 'Muster Willkommen + Begrüssung an it@ gesendet' : 'Muster FEHLGESCHLAGEN', 0, FWVJ_IT_ADRESSE);
    }
    antwort(['success' => true, 'gesetzt' => whatsapp_gruppe_link() !== '', 'vorschau' => $vorschau]);
}

if ($aktion === 'login_versuche') {
    $liste = login_versuche();
    antwort(['success' => true, 'anzahl' => count($liste), 'adressen' => $liste]);
}

if ($aktion === 'erinnerung_zugang') {
    $daten = json_decode((string) file_get_contents('php://input'), true);
    $daten = is_array($daten) ? $daten : [];
    if (!empty($daten['vorschau'])) {
        $ok = erinnerung_zugang_senden(FWVJ_IT_ADRESSE);
        audit('erinnerung_zugang_vorschau', null, null, $ok ? 'Muster an it@ gesendet' : 'Muster FEHLGESCHLAGEN', 0, FWVJ_IT_ADRESSE);
        antwort(['success' => $ok, 'vorschau_an' => FWVJ_IT_ADRESSE]);
    }
    $erlaubt = [];
    foreach (login_versuche() as $z) {
        $erlaubt[$z['email']] = $z;
    }
    $gesendet = [];
    $uebersprungen = [];
    foreach (array_slice(array_values(array_unique(array_map('strval', (array) ($daten['adressen'] ?? [])))), 0, 100) as $adr) {
        $adr = email_norm($adr);
        $z = $erlaubt[$adr] ?? null;
        if ($z === null) {
            $uebersprungen[] = [$adr, 'nicht in der Liste (Mitglied oder kein gescheiterter Login)'];
            continue;
        }
        if (!empty($z['erinnert'])) {
            $uebersprungen[] = [$adr, 'schon erinnert'];
            continue;
        }
        $ok = erinnerung_zugang_senden($adr);
        audit('erinnerung_zugang', null, null, $ok ? 'Erinnerung «Zugang anfragen» gesendet' : 'Erinnerung FEHLGESCHLAGEN', 0, $adr);
        $ok ? $gesendet[] = $adr : $uebersprungen[] = [$adr, 'Versand fehlgeschlagen'];
        usleep(300000); // Mailserver schonen
    }
    antwort(['success' => true, 'gesendet' => $gesendet, 'uebersprungen' => $uebersprungen]);
}

antwort(['success' => false, 'message' => 'unbekannte Aktion'], 400);
