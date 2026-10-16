# ferienwohnung-oberland.ch – Astro-Nachbau

Phase 1: 1:1-Nachbau der bisherigen WordPress/Elementor-Seite (Verein IGFI) in Astro,
99% visuell identisch. Ursprüngliche Seite lief unter dem Alias digy.ch.

Design-Tokens (Farben/Fonts) und Inhalte wurden von der Live-Seite übernommen
(siehe `src/styles/global.css` für Farben #004D7A / #74C2E1 / #F4A261, Poppins + Open Sans).

## Seiten

- `/` – Startseite
- `/ueber-uns/` – Wer wir sind (Vorstand-Akkordeon, YouTube-Video)
- `/airbnb-initiative/` – Unsere Positionen (Zahlen/Fakten-Tabelle)
- `/mitglied/` – Mitglied werden
- `/kontakt/` – Kontakt
- `/datenschutz/` – Datenschutz (Datenschutzerklärung eingebettet via privacybee.io-iframe)

## Commands

| Command           | Aktion                                   |
| :----------------- | :---------------------------------------- |
| `npm install`       | Dependencies installieren                 |
| `npm run dev`       | Lokalen Dev-Server starten                |
| `npm run build`     | Produktions-Build nach `./dist/`          |
| `npm run preview`   | Build lokal testen vor Deployment         |

## Deployment (Pull-Modell)

Hosttech blockiert FTP/FTPS-Datenverbindungen von GitHub-Actions-IPs (ECONNRESET) – ein
direkter FTP-Push aus der CI funktioniert deshalb nicht. Stattdessen läuft ein **Pull-Deploy**:

1. Push auf `main` → `.github/workflows/deploy.yml` baut die Astro-Seite, packt `dist/` als
   ZIP und lädt es als GitHub Release (`latest`) hoch.
2. Der Workflow ruft danach `https://ferienwohnungsverein-jungfrau.ch/deploy-hook.php?token=…`
   auf (Secret `DEPLOY_TOKEN`).
3. `deploy-hook.php` (liegt direkt in `httpdocs/` auf dem Server, ist **nicht** Teil dieses
   Repos) lädt das Release selbst herunter (ausgehende Verbindung vom Server, wird von Hostern
   praktisch nie blockiert) und tauscht `httpdocs/` gegen den neuen Build aus.

Damit das funktioniert, muss das Repo **öffentlich** sein (private Repos liefern
Release-Downloads nicht ohne Login aus – Zugangsdaten liegen ausschliesslich in GitHub-Secrets,
nicht im Code).

GitHub-Secrets: `DEPLOY_TOKEN`, `DEPLOY_HOST_IP` (IP von ferienwohnungsverein-jungfrau.ch,
solange DNS noch nicht auf Hosttech zeigt). `FTP_*`-Secrets sind Altlasten vom ersten,
verworfenen Ansatz und werden nicht mehr verwendet.

## Redaktion (Sveltia CMS)

Der Vereinsvorstand pflegt ohne Coding-Kenntnisse über eine Weboberfläche:

| Bereich im CMS | Dateien | erscheint |
|---|---|---|
| Seiten-Texte | `src/content/seiten/{de,en}/{startseite,ueber-uns}.json` | Startseite (Einleitung, Zitat), Über uns (alle Textblöcke) |
| Vorstand / Kontakt | `src/data/vorstand.json`, `src/data/kontakt.json` | Über uns, Kontakt, Mitgliedschaft, Schema.org |

Seiten-Texte zweisprachig (DE/EN-Umschalter im Eintrag; fehlt EN, wird DE gezeigt). Schemas: `src/content.config.ts` (Collections) und
`src/lib/daten.ts` (Vorstand/Kontakt) – ein ungültiger Eintrag lässt den Build scheitern, statt
eine kaputte Seite zu veröffentlichen.

**Adresse für den Vorstand:** `https://login.ferienwohnungsverein-jungfrau.ch/`
(Subdomain, Dokumentstamm = `httpdocs/admin`; die Dateien liegen im Repo unter `public/admin/`).
Dieselben Dateien sind auch unter `/admin/` der Hauptdomain erreichbar.

**Login:** GitHub-OAuth über einen eigenen Vermittler in `public/admin/oauth/` (PHP, läuft auf dem
hosttech-Server – kein Cloudflare/Drittanbieter). Zugangsdaten der GitHub-OAuth-App liegen **nicht**
im Repo, sondern in `github-oauth.key` eine Ebene über `httpdocs` (zwei Zeilen `CLIENT_ID=` /
`CLIENT_SECRET=`), analog zum Formular-Secret. Fehlt die Datei, meldet `/oauth/auth.php` das
klartextlich, statt zu scheitern.

Einmalige Einrichtung (Browser, ca. 15 Min.):
1. hosttech/Plesk: Subdomain `login` anlegen, Dokumentstamm `httpdocs/admin`, Let's-Encrypt aktivieren
   (DNS löst per Wildcard bereits auf).
2. GitHub → Org `ferienwohnungsverein-jungfrau` → Settings → Developer settings → OAuth Apps → New:
   Homepage `https://login.ferienwohnungsverein-jungfrau.ch`, Callback
   `https://login.ferienwohnungsverein-jungfrau.ch/oauth/callback.php`.
3. Client-ID + Secret in `github-oauth.key` neben `httpdocs` ablegen (chmod 600).
4. Redaktionspersonen brauchen ein GitHub-Konto und Schreibrecht (Collaborator) auf diesem Repo.

Sveltia ist lokal gepinnt (`public/admin/sveltia-cms.js`, Version im `index.html`-Kommentar);
Update = Datei von `https://unpkg.com/@sveltia/cms@<version>/dist/sveltia-cms.js` ersetzen.
Bild-Uploads werden vom CMS auf 1920 px verkleinert und als WebP abgelegt.

Ablauf danach: Redakteur:in speichert im CMS → Commit auf GitHub → Pull-Deploy (siehe oben)
→ Seite ist nach ca. 2–3 Minuten live. Rückgängig = Git-Revert.

## Mitgliederbereich (`/mitglieder/`, `/en/members/`)

Geschützter Bereich zum Austausch von Vorlagen (Schilder, Hausordnungen, Checklisten …).
Statische Astro-Hülle (`src/components/Mitgliederbereich.astro`, Logik in `src/scripts/mitglieder/`)
plus PHP-JSON-API in `public/php/mitglieder/`:

| Datei | Zweck |
|---|---|
| `api.php` | Login per Mail-Code, Liste, Hochladen/Ersetzen, Bearbeiten, Löschen, Melden, Admin |
| `datei.php` | Download + Vorschaubilder (nur mit Sitzung) |
| `freigabe.php` | signierte Freigabe-/Ablehnen-Links für anonyme Uploads (Mail an it@) |
| `zugang.php` | Knöpfe «Akzeptieren»/«Ablehnen» für Zugangsanfragen (Mail an it@) |
| `_lib.php`, `_pruefung.php` | gemeinsame Funktionen, Dateiprüfung; **alle Grenzwerte als Konstanten oben in `_lib.php`** |
| `katalog.json` | Kategorien, Sprachen, Themen-Tags, Erkennungswörter DE/EN/FR/IT – einzige Quelle für Browser und Server |

**Datenablage – nie im Repo, nie in `httpdocs`:** `../fwvj-mitglieder/` eine Ebene über `httpdocs`
(übersteht den Deploy-Tausch). Wird beim ersten Aufruf selbst angelegt (Ordner 700, Dateien 600):
`mitglieder.sqlite` (Mitglieder, Sitzungen, Dokumente, Audit-Log, Sperren), `dateien/`, `vorschau/`,
`secret.key` (signiert Freigabe-Links, hasht Login-Codes). Lokal per `FWVJ_MITGLIEDER_DIR` umlenkbar.

**Admin:** Erst-Admin ist `it@ferienwohnungsverein-jungfrau.ch`; die Vorstands-Postfächer vorstand@, sekretariat@,
medien@, finanzen@ sind als Mitglieder freigeschaltet (Migration v2). **Zugang:** Mitglied wählt auf der
Login-Seite «Zugang beantragen» → Mail an it@ mit Knöpfen «Akzeptieren»/«Ablehnen» (Bestätigungsseite, Links
14 Tage gültig) → beim Akzeptieren automatische Begrüssungsmail, beim Ablehnen optional neutrale Absage.
Gleiches geht im Reiter «Admin» (offene Anfragen; Freischalten von Hand mit Häkchen «Begrüssungsmail»). Dort auch: anonyme Uploads
freigeben, Gemeldetes prüfen, Konten/IP-Adressen sperren, Audit-Log, Mail bei Veröffentlichung an/aus.

**Backup:** den ganzen Ordner `fwvj-mitglieder/` (Plesk-Dateimanager oder FTP) herunterladen – Datenbank
und Dateien gehören zusammen. Wiederherstellen = Ordner zurückkopieren.

**Aufbewahrung (ohne Cron, beim nächsten Aufruf):** Upload-Einträge mit IP solange die Datei online ist
+ 90 Tage; übrige Audit-Einträge 90 Tage; gelöschte/abgelehnte Dokumente nach 90 Tagen ganz entfernt.

**Lokal testen:**

    npm run build && cd dist
    FWVJ_MITGLIEDER_DIR=/tmp/fwvj-mitglieder FWVJ_UNSICHER_LOKAL=1 FWVJ_BASIS_URL=http://localhost:8765 \
      php -d sendmail_path="cat >> /tmp/fwvj_mail.eml" -S localhost:8765

(Mails landen in `/tmp/fwvj_mail.eml`, Login-Code steht dort.) Neue Kategorie/Wörter: nur `katalog.json`
ergänzen – Schlüssel bestehender Kategorien nie umbenennen.
