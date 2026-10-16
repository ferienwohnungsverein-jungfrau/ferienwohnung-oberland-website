// Mitgliederbereich – Analyse der gewählten Datei im Browser (vor dem Hochladen).
// Text: PDF über pdf.js, Office (DOCX/XLSX/PPTX/ODT) über fflate (ZIP entpacken, XML-Text).
// Vorschau: PDF Seite 1 bzw. verkleinertes Bild. Bilder über 2500 px oder 5 MB werden
// hier verkleinert, damit Handyfotos durchgehen. Bibliotheken sind lokal gebündelt und
// werden erst beim ersten Upload geladen.

import { bildformat, papierformat } from './erkennung';

export const MAX_BYTES = 5 * 1024 * 1024;
const MAX_SEITE = 2500;

export interface Analyse {
  datei: File;            // ggf. verkleinerte Fassung
  art: 'pdf' | 'office' | 'bild' | 'unbekannt';
  text: string;
  seiten: number;
  seitenformat: string;
  vorschau: Blob | null;  // nur PDF (Bilder macht der Server selbst)
  vorschauUrl: string | null;
  verkleinert: boolean;
}

const OFFICE_ENDUNG = /\.(docx|xlsx|pptx|odt)$/i;
const BILD_TYP = /^image\/(png|jpeg|webp)$/;

export function art(datei: File): Analyse['art'] {
  if (datei.type === 'application/pdf' || /\.pdf$/i.test(datei.name)) return 'pdf';
  if (OFFICE_ENDUNG.test(datei.name)) return 'office';
  if (BILD_TYP.test(datei.type) || /\.(png|jpe?g|webp)$/i.test(datei.name)) return 'bild';
  return 'unbekannt';
}

export async function analysieren(datei: File): Promise<Analyse> {
  const a = art(datei);
  const leer: Analyse = { datei, art: a, text: '', seiten: 1, seitenformat: '', vorschau: null, vorschauUrl: null, verkleinert: false };
  try {
    if (a === 'pdf') return { ...leer, ...(await pdfAnalysieren(datei)) };
    if (a === 'office') return { ...leer, text: await officeText(datei) };
    if (a === 'bild') return { ...leer, ...(await bildVorbereiten(datei)) };
  } catch (e) {
    console.warn('Analyse fehlgeschlagen', e);
  }
  return leer;
}

// ── PDF ──

async function pdfAnalysieren(datei: File): Promise<Partial<Analyse>> {
  const pdfjs = await import('pdfjs-dist');
  // ?worker&url: Vite bündelt den Worker als .js (.mjs kennt nicht jeder Webserver als JavaScript)
  const worker = (await import('pdfjs-dist/build/pdf.worker.min.mjs?worker&url')).default;
  pdfjs.GlobalWorkerOptions.workerSrc = worker;
  const daten = new Uint8Array(await datei.arrayBuffer());
  const doc = await pdfjs.getDocument({ data: daten, isEvalSupported: false, enableXfa: false }).promise;
  const teile: string[] = [];
  for (let n = 1; n <= Math.min(doc.numPages, 5); n++) {
    const seite = await doc.getPage(n);
    const inhalt = await seite.getTextContent();
    let zeile = '';
    for (const item of inhalt.items as { str?: string; hasEOL?: boolean }[]) {
      zeile += item.str ?? '';
      if (item.hasEOL) zeile += '\n';
      else zeile += ' ';
    }
    teile.push(zeile);
  }
  const erste = await doc.getPage(1);
  const [x0, y0, x1, y1] = erste.view;
  const drehung = (erste.rotate || 0) % 180 !== 0;
  const b = Math.abs(x1 - x0);
  const h = Math.abs(y1 - y0);
  const seitenformat = drehung ? papierformat(h, b) : papierformat(b, h);

  // Vorschau Seite 1, ca. 600 px breit
  const basis = erste.getViewport({ scale: 1 });
  const viewport = erste.getViewport({ scale: Math.min(3, 600 / basis.width) });
  const canvas = document.createElement('canvas');
  canvas.width = Math.round(viewport.width);
  canvas.height = Math.round(viewport.height);
  const ctx = canvas.getContext('2d');
  let vorschau: Blob | null = null;
  if (ctx) {
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    await erste.render({ canvasContext: ctx, viewport }).promise;
    vorschau = await alsBlob(canvas, 'image/webp', 0.8);
    // Safari exportiert kein WebP (liefert PNG) → JPEG, bleibt klein
    if (!vorschau || vorschau.type !== 'image/webp') vorschau = await alsBlob(canvas, 'image/jpeg', 0.8);
  }
  const seiten = doc.numPages;
  await doc.destroy();
  return {
    text: teile.join('\n').replace(/[ \t]+/g, ' ').trim(),
    seiten,
    seitenformat,
    vorschau,
    vorschauUrl: vorschau ? URL.createObjectURL(vorschau) : null,
  };
}

// ── Office ──

async function officeText(datei: File): Promise<string> {
  const { unzipSync, strFromU8 } = await import('fflate');
  const wanted = (f: string) =>
    f === 'word/document.xml' ||
    f === 'xl/sharedStrings.xml' ||
    /^ppt\/slides\/slide\d+\.xml$/.test(f) ||
    f === 'content.xml';
  const dateien = unzipSync(new Uint8Array(await datei.arrayBuffer()), {
    filter: (f) => wanted(f.name) && f.originalSize < 20 * 1024 * 1024,
  });
  const reihenfolge = Object.keys(dateien).sort((a, b) => {
    const na = Number(a.match(/(\d+)\.xml$/)?.[1] ?? 0);
    const nb = Number(b.match(/(\d+)\.xml$/)?.[1] ?? 0);
    return na - nb;
  });
  const text = reihenfolge
    .map((f) =>
      strFromU8(dateien[f])
        .replace(/<\/(w:p|a:p|text:p|text:h|si)>/g, '\n')
        .replace(/<(w:tab|w:br|text:tab|text:line-break)[^>]*\/>/g, ' ')
        .replace(/<[^>]+>/g, '')
        .replace(/&amp;/g, '&')
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>')
        .replace(/&quot;/g, '"')
        .replace(/&apos;/g, "'"),
    )
    .join('\n');
  return text.replace(/[ \t]+/g, ' ').replace(/\n{3,}/g, '\n\n').trim().slice(0, 20000);
}

// ── Bilder ──

async function bildLaden(datei: File): Promise<ImageBitmap | HTMLImageElement> {
  if ('createImageBitmap' in window) {
    try {
      return await createImageBitmap(datei, { imageOrientation: 'from-image' });
    } catch {
      /* Fallback unten */
    }
  }
  const url = URL.createObjectURL(datei);
  try {
    const img = new Image();
    img.src = url;
    await img.decode();
    return img;
  } finally {
    URL.revokeObjectURL(url);
  }
}

async function bildVorbereiten(datei: File): Promise<Partial<Analyse>> {
  const bild = await bildLaden(datei);
  const w = 'naturalWidth' in bild ? bild.naturalWidth : bild.width;
  const h = 'naturalHeight' in bild ? bild.naturalHeight : bild.height;
  const zuGross = Math.max(w, h) > MAX_SEITE || datei.size > MAX_BYTES;
  // JPEG immer neu zeichnen: Handy-Drehung (EXIF) wird so fest übernommen
  const neuZeichnen = zuGross || datei.type === 'image/jpeg';
  let ergebnis = datei;
  let fb = w;
  let fh = h;
  if (neuZeichnen) {
    const f = Math.min(1, MAX_SEITE / Math.max(w, h));
    fb = Math.max(1, Math.round(w * f));
    fh = Math.max(1, Math.round(h * f));
    const canvas = document.createElement('canvas');
    canvas.width = fb;
    canvas.height = fh;
    const ctx = canvas.getContext('2d');
    if (!ctx) throw new Error('canvas');
    let typ = datei.type === 'image/png' ? 'image/png' : datei.type === 'image/webp' ? 'image/webp' : 'image/jpeg';
    if (typ !== 'image/png') {
      ctx.fillStyle = '#fff';
      ctx.fillRect(0, 0, fb, fh);
    }
    ctx.drawImage(bild, 0, 0, fb, fh);
    let blob = await alsBlob(canvas, typ, 0.88);
    // Safari kennt kein WebP-Export → JPEG; zu gross → JPEG mit sinkender Qualität
    if (!blob || blob.type !== typ || blob.size > MAX_BYTES) {
      if (typ === 'image/png') {
        const flach = document.createElement('canvas');
        flach.width = fb;
        flach.height = fh;
        const c2 = flach.getContext('2d')!;
        c2.fillStyle = '#fff';
        c2.fillRect(0, 0, fb, fh);
        c2.drawImage(canvas, 0, 0);
        canvas.getContext('2d')!.drawImage(flach, 0, 0);
      }
      typ = 'image/jpeg';
      for (const q of [0.88, 0.8, 0.7, 0.6]) {
        blob = await alsBlob(canvas, typ, q);
        if (blob && blob.size <= MAX_BYTES) break;
      }
    }
    if (!blob) throw new Error('blob');
    const endung = typ === 'image/png' ? 'png' : typ === 'image/webp' ? 'webp' : 'jpg';
    ergebnis = new File([blob], datei.name.replace(/\.[a-z0-9]+$/i, '') + '.' + endung, { type: typ });
  }
  if ('close' in bild) bild.close();
  return {
    datei: ergebnis,
    seitenformat: bildformat(fb, fh),
    vorschauUrl: URL.createObjectURL(ergebnis),
    verkleinert: zuGross,
  };
}

function alsBlob(canvas: HTMLCanvasElement, typ: string, q: number): Promise<Blob | null> {
  return new Promise((ok) => canvas.toBlob((b) => ok(b), typ, q));
}
