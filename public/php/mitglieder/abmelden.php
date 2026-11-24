<?php
declare(strict_types=1);

// Mitgliederbereich – Abmeldelink aus der Wochenmail «Neu im Mitgliederbereich» (Stefan 24.11.2026).
// GET zeigt nur einen Knopf; erst der Klick (POST) meldet ab – Link-Prüfer der Mailprogramme lösen nichts aus.
// Wieder einschalten: im Mitgliederbereich (Übersicht → Schalter «Wochenmail»).

define('FWVJ_MITGLIEDER', true);
require __DIR__ . '/_lib.php';
require_once dirname(__DIR__) . '/_seite.php';

header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$q = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$id = (int) ($q['m'] ?? 0);
$sig = (string) ($q['s'] ?? '');
$start = fwvj_seiten_sprache('de');
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$st = db()->prepare("SELECT id, email FROM mitglieder WHERE id = ? AND status = 'aktiv'");
$st->execute([$id]);
$m = $st->fetch();
if (!$m || !hash_equals(signatur('wochenmail_ab', (string) $id, 0), $sig)) {
    fwvj_seite(
        ['titel' => 'Link ungültig', 'inhalt' => '<p>Dieser Abmeldelink ist ungültig. Die Wochenmail lässt sich auch im <a href="/mitglieder/">Mitgliederbereich</a> ausschalten.</p>'],
        ['titel' => 'Invalid link', 'inhalt' => '<p>This unsubscribe link is invalid. You can also switch off the weekly email in the <a href="/en/members/">members’ area</a>.</p>'],
        $start, true
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    db()->prepare('UPDATE mitglieder SET wochenmail = 0 WHERE id = ?')->execute([$id]);
    audit('wochenmail_aus', $m, null, 'über den Abmeldelink');
    fwvj_seite(
        ['titel' => 'Abgemeldet', 'inhalt' => '<p>Du bekommst keine Wochenmail mehr an <strong>' . $e($m['email']) . '</strong>. Der Mitgliederbereich bleibt für dich offen – dort kannst du die Wochenmail jederzeit wieder einschalten.</p><a class="btn" href="/mitglieder/">Zum Mitgliederbereich</a>'],
        ['titel' => 'Unsubscribed', 'inhalt' => '<p>You will no longer receive the weekly email at <strong>' . $e($m['email']) . '</strong>. The members’ area stays open for you – you can switch the weekly email back on there at any time.</p><a class="btn" href="/en/members/">Go to the members’ area</a>'],
        fwvj_seiten_sprache($start)
    );
}

$form = static fn (string $l, string $knopf): string => '<form method="post"><input type="hidden" name="m" value="' . $id . '"><input type="hidden" name="s" value="' . $e($sig) . '"><input type="hidden" name="l" value="' . $l . '"><button type="submit">' . $knopf . '</button></form>';
fwvj_seite(
    ['titel' => 'Wochenmail abbestellen?', 'inhalt' => '<p>Keine Wochenmail «Neu im Mitgliederbereich» mehr an <strong>' . $e($m['email']) . '</strong>?</p>' . $form('de', 'Ja, abmelden')],
    ['titel' => 'Unsubscribe from the weekly email?', 'inhalt' => '<p>Stop the weekly email “New in the members’ area” to <strong>' . $e($m['email']) . '</strong>?</p>' . $form('en', 'Yes, unsubscribe')],
    $start
);
