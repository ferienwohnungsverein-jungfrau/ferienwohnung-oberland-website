// Inhalts-Schemas. Alles, was der Vorstand über die Redaktion (Sveltia CMS) pflegt,
// wird hier beim Build geprüft: ein fehlendes Pflichtfeld lässt den Build rot werden,
// statt eine kaputte Seite zu veröffentlichen.
import { defineCollection, z } from 'astro:content';
import { glob } from 'astro/loaders';

// Zweisprachig: Dateien liegen unter <collection>/de/... und <collection>/en/...
// Die ID eines Eintrags ist damit z.B. "de/generalversammlung-2026".

// Editierbare Textblöcke fest gestalteter Seiten (Layout bleibt im Code).
const seiten = defineCollection({
  loader: glob({ pattern: '**/*.json', base: './src/content/seiten' }),
  schema: z.object({
    // Startseite
    hero_subline: z.string().optional(),
    zitat: z.string().optional(),
    zitat_autor: z.string().optional(),
    zitat_link: z.string().optional(),
    // Über uns
    hero_text: z.string().optional(),
    vorstand_intro: z.string().optional(),
    warum_titel: z.string().optional(),
    warum_text: z.string().optional(),
    einsatz_titel: z.string().optional(),
    einsatz_text: z.string().optional(),
    rollen_titel: z.string().optional(),
    rollen_text: z.string().optional(),
  }),
});

export const collections = { seiten };
