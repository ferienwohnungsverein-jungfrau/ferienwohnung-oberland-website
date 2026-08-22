// Inhalts-Schemas. Alles, was der Vorstand über die Redaktion (Sveltia CMS) pflegt,
// wird hier beim Build geprüft: ein fehlendes Pflichtfeld lässt den Build rot werden,
// statt eine kaputte Seite zu veröffentlichen.
import { defineCollection, z } from 'astro:content';
import { glob } from 'astro/loaders';

// Zweisprachig: Dateien liegen unter <collection>/de/... und <collection>/en/...
// Die ID eines Eintrags ist damit z.B. "de/generalversammlung-2026".

const aktuelles = defineCollection({
  loader: glob({ pattern: '**/*.md', base: './src/content/aktuelles' }),
  schema: z.object({
    titel: z.string().min(1),
    datum: z.coerce.date(),
    teaser: z.string().min(1),
    bild: z.string().optional(),
    bild_alt: z.string().optional(),
    entwurf: z.boolean().default(false),
  }),
});

const veranstaltungen = defineCollection({
  loader: glob({ pattern: '**/*.md', base: './src/content/veranstaltungen' }),
  schema: z.object({
    titel: z.string().min(1),
    beginn: z.coerce.date(),
    ende: z.coerce.date().optional(),
    ort: z.string().optional(),
    anmeldelink: z.string().url().optional().or(z.literal('')),
    entwurf: z.boolean().default(false),
  }),
});

const downloads = defineCollection({
  loader: glob({ pattern: '**/*.json', base: './src/content/downloads' }),
  schema: z.object({
    titel: z.string().min(1),
    datei: z.string().min(1),
    kategorie: z.enum(['statuten', 'protokolle', 'medien', 'sonstiges']).default('sonstiges'),
    datum: z.coerce.date(),
    beschreibung: z.string().optional(),
    entwurf: z.boolean().default(false),
  }),
});

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

export const collections = { aktuelles, veranstaltungen, downloads, seiten };
