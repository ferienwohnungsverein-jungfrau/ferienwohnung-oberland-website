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

## Redaktion (Sveltia CMS)

Vorstand (`src/data/vorstand.json`) und Kontaktdaten (`src/data/kontakt.json`) sind für den
Vereinsvorstand ohne Coding-Kenntnisse editierbar über eine Weboberfläche unter `/admin/`.

**Noch offen, bevor die Redaktion nutzbar ist:**

1. GitHub-Repository für dieses Projekt anlegen und den lokalen Git-Stand dorthin pushen.
2. In `public/admin/config.yml` den Platzhalter `OWNER/REPO` durch den echten Repo-Pfad ersetzen
   (z.B. `stefansterchi/ferienwohnung-oberland-website`).
3. Einen OAuth-Zugang für Sveltia CMS einrichten, damit sich Redakteur:innen mit ihrem
   GitHub-Account anmelden können (GitHub OAuth App + kleiner Auth-Worker, z.B. auf Cloudflare
   Workers – kostenlos, einmalige Einrichtung).
4. Diese vier GitHub-Actions-Secrets hinterlegen, sobald die Hosttech-Zugangsdaten vorliegen
   (Repo → Settings → Secrets and variables → Actions):
   - `FTP_HOST`
   - `FTP_USERNAME`
   - `FTP_PASSWORD`
   - `FTP_REMOTE_DIR`

Danach läuft der Ablauf automatisch: Redakteur:in speichert im CMS → Commit auf GitHub →
`.github/workflows/deploy.yml` baut die Seite und lädt sie per FTPS zu Hosttech hoch.

Bis dahin läuft die Seite unverändert wie in Phase 1: Inhalte direkt in den `.astro`-Dateien
bzw. den JSON-Dateien in `src/data/` bearbeiten, `npm run build` ausführen und den Inhalt von
`dist/` manuell per FTP (z.B. Cyberduck/FileZilla) zu Hosttech hochladen.
