// Mitgliederbereich – regelbasierte Dokumenterkennung (ohne DOM, auch in Node testbar).
// Grundlage ist public/php/mitglieder/katalog.json: Kategorie, Sprache, Tags werden
// aus erkanntem Text + Dateiname vorgeschlagen; das Mitglied bestätigt oder korrigiert.

export interface KatalogEintrag {
  key: string;
  de: string;
  en: string;
  woerter?: Record<string, string[]> | string[];
}

export interface Katalog {
  kategorien: KatalogEintrag[];
  sprachen: KatalogEintrag[];
  tags: KatalogEintrag[];
  stoppwoerter: Record<string, string[]>;
}

export interface Vorschlag {
  titel: string;
  kategorie: string;
  sprache: string;
  tags: string[];
  seitenformat: string;
  /** Worauf der Vorschlag beruht (für die Anzeige «erkannt aus …») */
  grundlage: 'text' | 'dateiname' | 'nichts';
}

/** Kleinbuchstaben, Akzente/Umlaute weg (ä→a), Satzzeichen zu Leerzeichen. */
export function normalisieren(s: string): string {
  return ` ${s
    .toLowerCase()
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/ß/g, 'ss')
    .replace(/[^a-z0-9'@.-]+/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()} `;
}

/**
 * Kommt das Wort vor? Immer am Wortanfang (Präfix): «rauch» trifft «Rauchverbot», aber
 * nicht «Gebrauch». Zusammensetzungen mit dem Wort hinten (Endreinigung) stehen deshalb
 * ausdrücklich in den Wortlisten.
 */
function treffer(text: string, wort: string): number {
  const w = normalisieren(wort).trim();
  if (!w) return 0;
  const re = new RegExp(`(^|[^a-z0-9])${w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`, 'g');
  return Math.min(20, (text.match(re) || []).length);
}

function alleWoerter(e: KatalogEintrag): string[] {
  if (!e.woerter) return [];
  return Array.isArray(e.woerter) ? e.woerter : Object.values(e.woerter).flat();
}

export function kategorieErkennen(katalog: Katalog, text: string, dateiname: string, kurzerText: boolean): string {
  const t = normalisieren(text);
  const d = normalisieren(dateiname.replace(/[_-]+/g, ' '));
  let best = '';
  let bestPunkte = 0;
  for (const k of katalog.kategorien) {
    let p = 0;
    for (const w of alleWoerter(k)) {
      p += Math.min(treffer(t, w), 5) + treffer(d, w) * 4; // Dateiname zählt stark
    }
    // Wenig Text auf einer Seite = typisch Schild/Plakat
    if (k.key === 'schild' && kurzerText && p > 0) p += 2;
    if (p > bestPunkte) {
      bestPunkte = p;
      best = k.key;
    }
  }
  // Ein einzelner Zufallstreffer im Text reicht nicht – dann wählt das Mitglied selbst.
  return bestPunkte >= 2 ? best : '';
}

export function spracheErkennen(katalog: Katalog, text: string): string {
  const woerter = normalisieren(text).trim().split(' ').filter(Boolean);
  if (woerter.length < 4) return '';
  const zaehler: Record<string, number> = {};
  for (const [sprache, liste] of Object.entries(katalog.stoppwoerter)) {
    const set = new Set(liste.map((w) => normalisieren(w).trim()));
    zaehler[sprache] = woerter.filter((w) => set.has(w)).length;
  }
  const rang = Object.entries(zaehler).sort((a, b) => b[1] - a[1]);
  const [erste, zweite] = rang;
  if (!erste || erste[1] < 2) return '';
  if (zweite && zweite[1] >= 4 && zweite[1] >= erste[1] * 0.35) return 'mehrsprachig';
  return erste[0];
}

export function tagsErkennen(katalog: Katalog, text: string, dateiname: string): string[] {
  const t = normalisieren(`${text} ${dateiname.replace(/[_-]+/g, ' ')}`);
  return katalog.tags
    .map((tag) => ({ key: tag.key, p: alleWoerter(tag).reduce((s, w) => s + treffer(t, w), 0) }))
    .filter((x) => x.p > 0)
    .sort((a, b) => b.p - a.p)
    .slice(0, 5)
    .map((x) => x.key);
}

/** Titel aus dem Dateinamen; taugt er nicht (IMG_1234, scan, …), dann erste sinnvolle Textzeile. */
export function titelVorschlagen(dateiname: string, text: string): string {
  let n = dateiname.replace(/\.[a-z0-9]{2,5}$/i, '');
  n = n
    .replace(/[_.]+/g, ' ')
    .replace(/\b(final|neu|new|kopie|copy|entwurf|draft|bearbeitet|edited|v\d+|\d{6,}|\d{4}[-_ ]\d{2}[-_ ]\d{2})\b/gi, ' ')
    .replace(/\b(scan|scanned|img|dsc|pxl|screenshot|bildschirmfoto|whatsapp image|image|foto|photo|dokument|document|unbenannt|untitled|datei|file)[\s\d-]*(?=\s|$)/gi, ' ')
    .replace(/\(\d+\)/g, ' ')
    .replace(/\s*-\s*/g, ' – ')
    .replace(/\s+/g, ' ')
    .replace(/^[\s–]+|[\s–]+$/g, '')
    .trim();
  const buchstaben = (n.match(/\p{L}/gu) || []).length;
  if (buchstaben >= 4) return gross(n).slice(0, 120);
  const zeile = text
    .split(/\n+/)
    .map((z) => z.replace(/\s+/g, ' ').trim())
    .find((z) => (z.match(/\p{L}/gu) || []).length >= 4 && z.length <= 90);
  return zeile ? gross(zeile).slice(0, 120) : '';
}

function gross(s: string): string {
  return s.charAt(0).toUpperCase() + s.slice(1);
}

/** Papierformat aus Seitengrösse in PDF-Punkten (1/72 Zoll). */
export function papierformat(breitePt: number, hoehePt: number): string {
  const kurz = Math.min(breitePt, hoehePt);
  const lang = Math.max(breitePt, hoehePt);
  const lage = breitePt > hoehePt ? 'quer' : 'hoch';
  const formate: [string, number, number][] = [
    ['a5', 420, 595],
    ['a4', 595, 842],
    ['a3', 842, 1191],
    ['letter', 612, 792],
  ];
  for (const [name, k, l] of formate) {
    if (Math.abs(kurz - k) / k < 0.03 && Math.abs(lang - l) / l < 0.03) return `${name}-${lage}`;
  }
  if (Math.abs(breitePt - hoehePt) / lang < 0.03) return 'quadratisch';
  return lage;
}

export function bildformat(breite: number, hoehe: number): string {
  if (!breite || !hoehe) return '';
  if (Math.abs(breite - hoehe) / Math.max(breite, hoehe) < 0.03) return 'quadratisch';
  return breite > hoehe ? 'quer' : 'hoch';
}

export function vorschlagen(
  katalog: Katalog,
  eingabe: { dateiname: string; text: string; seiten?: number; seitenformat?: string },
): Vorschlag {
  const text = eingabe.text || '';
  const kurz = text.trim().length < 400 && (eingabe.seiten ?? 1) <= 1;
  const kategorie = kategorieErkennen(katalog, text, eingabe.dateiname, kurz);
  return {
    titel: titelVorschlagen(eingabe.dateiname, text),
    kategorie,
    sprache: spracheErkennen(katalog, text),
    tags: tagsErkennen(katalog, text, eingabe.dateiname),
    seitenformat: eingabe.seitenformat || '',
    grundlage: text.trim() ? 'text' : kategorie ? 'dateiname' : 'nichts',
  };
}
