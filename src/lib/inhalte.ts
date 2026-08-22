// Hilfen für zweisprachige Inhalte aus der Redaktion.
import { getEntry } from 'astro:content';
import { marked } from 'marked';

export type Locale = 'de' | 'en';

/** Seiten-Textblöcke (src/content/seiten/<locale>/<name>.json), EN fällt auf DE zurück. */
export async function getSeitenTexte(name: string, locale: Locale) {
  const entry = (await getEntry('seiten', `${locale}/${name}`)) ?? (await getEntry('seiten', `de/${name}`));
  if (!entry) throw new Error(`Seitentexte fehlen: src/content/seiten/de/${name}.json`);
  return entry.data;
}

/** Markdown aus einem Textfeld (mehrere Absätze) in HTML wandeln. */
export function md(text: string | undefined): string {
  return text ? (marked.parse(text, { async: false }) as string) : '';
}

export function formatDatum(d: Date, locale: Locale, mitZeit = false): string {
  return d.toLocaleString(locale === 'en' ? 'en-GB' : 'de-CH', {
    timeZone: 'Europe/Zurich',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    ...(mitZeit ? { hour: '2-digit', minute: '2-digit' } : {}),
  });
}
