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
