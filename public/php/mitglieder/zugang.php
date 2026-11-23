<?php
declare(strict_types=1);

// Mitgliederbereich – Knöpfe «Akzeptieren» / «Ablehnen» aus der Mail an sekretariat@ + it@ (Zugangsanfrage).
// GET zeigt eine Bestätigungsseite (Namen prüfen, Mail an die Person ja/nein); erst der
// Klick (POST) führt aus. So lösen Link-Prüfer von Mailprogrammen nichts aus.

define('FWVJ_MITGLIEDER', true);
require __DIR__ . '/_lib.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

$q = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$aktion = in_array($q['a'] ?? '', ['annehmen', 'ablehnen'], true) ? (string) $q['a'] : '';
$id = (int) ($q['id'] ?? 0);
$exp = (int) ($q['exp'] ?? 0);
$sig = (string) ($q['s'] ?? '');

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

require_once dirname(__DIR__) . '/_seite.php';

/** Zweisprachige Seite mit Umschalter (Stefan 23.11.2026); Start = Sprache des geklickten Mail-Knopfs (?l=). */
function seite2(string $titelDe, string $inhaltDe, string $titelEn, string $inhaltEn, bool $fehler = false): never
{
    fwvj_seite(['titel' => $titelDe, 'inhalt' => $inhaltDe], ['titel' => $titelEn, 'inhalt' => $inhaltEn], fwvj_seiten_sprache('de'), $fehler);
}

$st = db()->prepare('SELECT * FROM zugangsanfragen WHERE id = ?');
$st->execute([$id]);
$a = $st->fetch();
$admin = ['de' => '<a href="/mitglieder/#admin">Admin-Reiter</a>', 'en' => '<a href="/en/members/#admin">Admin tab</a>'];
if ($aktion === '' || !$a || $exp < jetzt() || !hash_equals(signatur('zugang_' . $aktion, (string) $id, $exp), $sig)) {
    seite2(
        'Link ungültig', '<p>Der Link ist ungültig oder abgelaufen (gültig ' . ANFRAGE_GUELTIG_TAGE . ' Tage). Offene Anfragen findest du im ' . $admin['de'] . '.</p>',
        'Invalid link', '<p>The link is invalid or has expired (valid ' . ANFRAGE_GUELTIG_TAGE . ' days). Open requests are listed in the ' . $admin['en'] . '.</p>',
        true
    );
}
if ($a['status'] !== 'offen') {
    $wann = e($a['erledigt_von']) . ', ' . date('d.m.Y H:i', (int) $a['erledigt_am']);
    $ja = $a['status'] === 'angenommen';
    seite2(
        'Schon erledigt', '<p>Die Anfrage von <strong>' . e($a['email']) . '</strong> wurde bereits ' . ($ja ? 'angenommen' : 'abgelehnt') . " ({$wann}).</p><p>" . $admin['de'] . '</p>',
        'Already handled', '<p>The request from <strong>' . e($a['email']) . '</strong> has already been ' . ($ja ? 'accepted' : 'declined') . " ({$wann}).</p><p>" . $admin['en'] . '</p>'
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vorname = sauber((string) ($_POST['vorname'] ?? ''), 80) ?: $a['vorname'];
    $name = sauber((string) ($_POST['name'] ?? ''), 80) ?: $a['name'];
    $mail = !empty($_POST['mail']);
    $ergebnis = anfrage_entscheiden($a, $aktion === 'annehmen', $vorname, $name, $mail, null);
    $wer = e("{$vorname} {$name}") . ' (' . e($a['email']) . ')';
    if ($ergebnis === 'gesperrt') {
        seite2(
            'Adresse gesperrt', '<p>' . e($a['email']) . ' ist im Mitgliederbereich gesperrt. Bitte zuerst im ' . $admin['de'] . ' entsperren.</p>',
            'Address blocked', '<p>' . e($a['email']) . ' is blocked in the members’ area. Please unblock it in the ' . $admin['en'] . ' first.</p>',
            true
        );
    }
    if ($ergebnis === 'angenommen') {
        seite2(
            'Aufgenommen & freigeschaltet', "<p><strong>{$wer}</strong> kann sich jetzt im Mitgliederbereich anmelden.</p><p>"
                . ($mail ? 'Die Begrüssungsmail mit dem Link ist unterwegs.' : 'Es wurde keine Mail verschickt – bitte selbst Bescheid geben.') . '</p>',
            'Admitted & activated', "<p><strong>{$wer}</strong> can now log in to the members’ area.</p><p>"
                . ($mail ? 'The welcome email with the link is on its way.' : 'No email was sent – please let the person know yourself.') . '</p>'
        );
    }
    seite2(
        'Abgelehnt', '<p>Die Anfrage von ' . e($a['email']) . ' ist abgelehnt.' . ($mail ? ' Die Person hat eine kurze Absage per Mail erhalten.' : ' Es wurde keine Mail verschickt.') . '</p>',
        'Declined', '<p>The request from ' . e($a['email']) . ' has been declined.' . ($mail ? ' The person received a short rejection email.' : ' No email was sent.') . '</p>'
    );
}

$felder = static function (string $l) use ($aktion, $id, $exp, $sig): string {
    $f = '';
    foreach (['a' => $aktion, 'id' => (string) $id, 'exp' => (string) $exp, 's' => $sig, 'l' => $l] as $k => $v) {
        $f .= '<input type="hidden" name="' . $k . '" value="' . e($v) . '">';
    }
    return $f;
};
$daten = static fn (string $l): string => '<div class="daten"><strong>' . e($a['vorname'] . ' ' . $a['name']) . '</strong><br>' . e($a['email'])
    . ($a['angaben'] !== '' ? '<br><span class="klein">' . e($a['angaben']) . '</span>' : '')
    . '<br><span class="klein">' . ($l === 'en' ? 'Request of ' : 'Anfrage vom ') . date('d.m.Y H:i', (int) $a['zeit']) . '</span></div>';

if ($aktion === 'annehmen') {
    $namen = static fn (string $vn, string $nn): string =>
        '<label class="feld"><span>' . $vn . '</span><input type="text" name="vorname" maxlength="80" value="' . e($a['vorname']) . '"></label>'
        . '<label class="feld"><span>' . $nn . '</span><input type="text" name="name" maxlength="80" value="' . e($a['name']) . '"></label>';
    seite2(
        'Aufnehmen & freischalten?',
        $daten('de') . '<form method="post">' . $felder('de') . $namen('Vorname', 'Nachname')
            . '<p class="klein">Vorname und Nachname erscheinen im Mitgliederbereich als Autor.</p>'
            . '<label class="check"><input type="checkbox" name="mail" value="1" checked> Begrüssungsmail mit Link an die Person senden</label>'
            . '<button type="submit">✓ Ja, aufnehmen & freischalten</button></form>',
        'Admit & activate?',
        $daten('en') . '<form method="post">' . $felder('en') . $namen('First name', 'Last name')
            . '<p class="klein">First and last name are shown as author in the members’ area.</p>'
            . '<label class="check"><input type="checkbox" name="mail" value="1" checked> Send a welcome email with the link to the person</label>'
            . '<button type="submit">✓ Yes, admit & activate</button></form>'
    );
}
seite2(
    'Anfrage ablehnen?',
    $daten('de') . '<form method="post">' . $felder('de')
        . '<label class="check"><input type="checkbox" name="mail" value="1" checked> Kurze, neutrale Absage per Mail senden («keine Mitgliedschaft bestätigt, bei Irrtum antworten»)</label>'
        . '<button type="submit" class="nein">✕ Ja, ablehnen</button></form>',
    'Decline request?',
    $daten('en') . '<form method="post">' . $felder('en')
        . '<label class="check"><input type="checkbox" name="mail" value="1" checked> Send a short, neutral rejection email (“no membership confirmed, reply if this is a mistake”)</label>'
        . '<button type="submit" class="nein">✕ Yes, decline</button></form>'
);
