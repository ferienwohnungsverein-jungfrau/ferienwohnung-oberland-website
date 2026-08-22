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

Vorstand (`src/data/vorstand.json`) und Kontaktdaten (`src/data/kontakt.json`) sind für den
Vereinsvorstand ohne Coding-Kenntnisse editierbar über eine Weboberfläche.

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
