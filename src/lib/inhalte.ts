// Hilfen für zweisprachige Inhalte aus der Redaktion.
import { getCollection, getEntry, type CollectionEntry, type CollectionKey } from 'astro:content';
import { marked } from 'marked';

export type Locale = 'de' | 'en';

/** Slug ohne Sprachpräfix, z.B. "de/gv-2026" → "gv-2026" */
export function slugOf(id: string): string {
  return id.replace(/^(de|en)\//, '');
}

/**
 * Einträge einer Collection in der gewünschten Sprache. Fehlt die englische
 * Fassung, wird die deutsche gezeigt (besser als ein Loch auf der EN-Seite).
 * Entwürfe (entwurf: true) werden nie ausgeliefert.
 */
export async function getLokalisiert<C extends CollectionKey>(
  collection: C,
  locale: Locale,
): Promise<CollectionEntry<C>[]> {
  const alle = await getCollection(collection, (e) => !(e.data as { entwurf?: boolean }).entwurf);
  const bySlug = new Map<string, CollectionEntry<C>>();
  for (const e of alle) if (e.id.startsWith('de/')) bySlug.set(slugOf(e.id), e);
  if (locale === 'en') for (const e of alle) if (e.id.startsWith('en/')) bySlug.set(slugOf(e.id), e);
  return [...bySlug.values()];
}

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
