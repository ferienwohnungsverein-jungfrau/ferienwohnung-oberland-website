<?php
declare(strict_types=1);

// Zweisprachige Rückmeldeseiten (Stefan 23.11.2026: «überall die Möglichkeit zum Umschalten»).
// Für alle Seiten, die PHP selbst ausgibt: Bestätigung der Mitglied-Anmeldung, Anmeldelink
// Mitgliederbereich, Aufnehmen/Ablehnen. Jede Seite enthält Deutsch UND Englisch; der Umschalter
// oben arbeitet rein mit CSS (Radio-Knöpfe) – kein JavaScript, kein Neuladen, also wird beim
// Umschalten nichts ein zweites Mal ausgelöst. Startsprache = Parameter l=en/de bzw. Vorgabe.
// Gibt beim direkten Aufruf nichts aus.

/** Startsprache aus ?l= / POST l, sonst $vorgabe. */
function fwvj_seiten_sprache(string $vorgabe = 'de'): string
{
    $l = (string) ($_POST['l'] ?? $_GET['l'] ?? '');
    if ($l === 'en' || $l === 'de') {
        return $l;
    }
    return $vorgabe === 'en' ? 'en' : 'de';
}

/**
 * Gibt die Seite aus und beendet. $de/$en = ['titel' => Text, 'inhalt' => HTML].
 * $fehler färbt den Titel rot. Inhalte müssen bereits escaped sein.
 */
function fwvj_seite(array $de, array $en, string $start = 'de', bool $fehler = false, string $breite = '520px'): never
{
    $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $start = $start === 'en' ? 'en' : 'de';
    $checkedDe = $start === 'de' ? ' checked' : '';
    $checkedEn = $start === 'en' ? ' checked' : '';
    $lang = $start === 'en' ? 'en' : 'de-CH';
    $farbe = $fehler ? '#b91c1c' : '#004D7A';
    $titelDe = $h($de['titel']);
    $titelEn = $h($en['titel']);
    $titel = $start === 'en' ? $titelEn : $titelDe;
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    echo <<<HTML
<!doctype html>
<html lang="{$lang}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{$titel} – Ferienwohnungsverein Jungfrau</title>
<style>
  body { margin:0; font-family:'Open Sans', Arial, Helvetica, sans-serif; background:#F9F9F9; color:#334155; line-height:1.55; }
  .wrap { max-width:{$breite}; margin:48px auto; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 8px 24px rgba(0,0,0,.08); }
  .head { background:#004D7A; color:#fff; padding:18px 28px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; }
  .head strong { font-family:'Poppins', Arial, sans-serif; font-size:17px; }
  .umschalter { display:flex; border:1px solid rgba(255,255,255,.45); border-radius:999px; overflow:hidden; font-size:13px; }
  .umschalter label { padding:4px 12px; cursor:pointer; color:#fff; }
  .body { padding:28px; }
  h1 { color:{$farbe}; font-family:'Poppins', Arial, sans-serif; font-size:1.45rem; margin:0 0 14px; }
  label.feld { display:block; margin:12px 0; } label.feld span { display:block; font-weight:700; margin-bottom:4px; font-size:.95rem; }
  input[type=text] { width:100%; box-sizing:border-box; padding:10px 12px; border:1.5px solid #D1D5DB; border-radius:4px; font-size:1rem; }
  label.check { display:flex; gap:8px; align-items:flex-start; margin:14px 0; font-size:.95rem; }
  button, a.btn { display:inline-block; background:#F4A261; color:#1e293b; border:0; border-radius:6px; padding:12px 24px; font-weight:700; font-size:1rem; cursor:pointer; margin-top:10px; text-decoration:none; font-family:inherit; }
  button.nein { background:#fee2e2; color:#b91c1c; }
  .daten { background:#F0F5FA; border-radius:6px; padding:12px 16px; margin-bottom:8px; }
  .klein { font-size:.85rem; color:#64748b; }
  a { color:#004D7A; }
  /* Umschalter ohne JavaScript */
  .sprachwahl { position:absolute; opacity:0; pointer-events:none; }
  #fwvj-de:checked ~ .wrap .en, #fwvj-en:checked ~ .wrap .de { display:none; }
  #fwvj-de:checked ~ .wrap label[for=fwvj-de], #fwvj-en:checked ~ .wrap label[for=fwvj-en] { background:#fff; color:#004D7A; font-weight:700; }
  #fwvj-de:focus-visible ~ .wrap label[for=fwvj-de], #fwvj-en:focus-visible ~ .wrap label[for=fwvj-en] { outline:2px solid #F4A261; outline-offset:-2px; }
  @media (max-width:560px) { .wrap { margin:0; border-radius:0; min-height:100vh; } }
</style>
</head>
<body>
<input type="radio" name="fwvj-sprache" id="fwvj-de" class="sprachwahl"{$checkedDe}>
<input type="radio" name="fwvj-sprache" id="fwvj-en" class="sprachwahl"{$checkedEn}>
<div class="wrap">
  <div class="head">
    <strong>Ferienwohnungsverein Jungfrau</strong>
    <div class="umschalter" role="group" aria-label="Sprache / Language">
      <label for="fwvj-de" lang="de">Deutsch</label><label for="fwvj-en" lang="en">English</label>
    </div>
  </div>
  <div class="body de" lang="de-CH"><h1>{$titelDe}</h1>{$de['inhalt']}</div>
  <div class="body en" lang="en"><h1>{$titelEn}</h1>{$en['inhalt']}</div>
</div>
</body>
</html>
HTML;
    exit;
}
