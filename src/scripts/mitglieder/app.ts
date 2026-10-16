// Mitgliederbereich – Oberfläche (statische Astro-Hülle + PHP-JSON-API).
// Sprache kommt aus data-locale am Wurzelelement (#mb). Filter stehen im URL
// (?kat=…&spr=…&q=…), Reiter im Hash (#hochladen, #admin), ?dok=<id> hebt ein Dokument hervor.

import type { Katalog } from './erkennung';
import { vorschlagen } from './erkennung';
import { analysieren, art as dateiArt, MAX_BYTES, type Analyse } from './analyse';

const API = '/php/mitglieder/api.php';
const DATEI = '/php/mitglieder/datei.php';

const wurzel = document.getElementById('mb') as HTMLElement;
const L: 'de' | 'en' = wurzel?.dataset.locale === 'en' ? 'en' : 'de';
const IT = 'it@ferienwohnungsverein-jungfrau.ch';

const T = {
  de: {
    laden: 'Wird geladen …',
    loginTitel: 'Anmelden',
    loginText: 'Gib die E-Mail-Adresse ein, die für den Mitgliederbereich freigeschaltet ist. Du bekommst einen 6-stelligen Code per Mail – kein Passwort nötig.',
    email: 'E-Mail-Adresse',
    codeAnfordern: 'Code anfordern',
    code: 'Code aus der E-Mail',
    anmelden: 'Anmelden',
    andereAdresse: 'Andere Adresse / neuen Code anfordern',
    nochNicht: 'Noch nicht freigeschaltet? Schreib eine kurze Mail an',
    nochNichtRest: '– von der Adresse, mit der du dich anmelden willst. Wir prüfen die Mitgliedschaft und schalten dich frei.',
    hallo: 'Hallo',
    abmelden: 'Abmelden',
    reiterVorlagen: 'Vorlagen',
    reiterHochladen: 'Hochladen',
    reiterAdmin: 'Admin',
    filter: 'Filter',
    suche: 'Suche',
    suchePh: 'Titel, Stichwort, Text …',
    alle: 'Alle',
    kategorie: 'Kategorie',
    sprache: 'Sprache',
    format: 'Format',
    tag: 'Thema',
    autor: 'Autor',
    nurMeine: 'Nur meine Uploads',
    sortierung: 'Sortierung',
    neu: 'Neueste zuerst',
    alt: 'Älteste zuerst',
    az: 'A–Z',
    zuruecksetzen: 'Filter zurücksetzen',
    treffer: (n: number) => (n === 1 ? '1 Vorlage' : `${n} Vorlagen`),
    keineTreffer: 'Keine Vorlage passt zu diesen Filtern.',
    leer: 'Noch keine Vorlagen – lade die erste hoch!',
    herunterladen: 'Herunterladen',
    bearbeiten: 'Bearbeiten',
    neueVersion: 'Neue Version',
    loeschen: 'Löschen',
    melden: 'Melden',
    gemeldet: 'Gemeldet',
    anonym: 'anonym',
    von: 'von',
    du: 'du',
    status: { wartet: 'Wartet auf Freigabe', versteckt: 'Ausgeblendet – wird geprüft', abgelehnt: 'Abgelehnt', sichtbar: '' } as Record<string, string>,
    version: 'Version',
    // Upload
    hochTitel: 'Vorlage hochladen',
    ersetzenTitel: 'Neue Version hochladen',
    ersetzenAbbrechen: 'Abbrechen',
    hochIntro: 'PDF, Word, Excel, PowerPoint, ODT oder Bild (PNG, JPG, WebP), max. 5 MB. Grosse Fotos werden automatisch verkleinert. Was hochgeladen ist, sehen alle Mitglieder sofort.',
    datei: 'Datei wählen oder hierher ziehen',
    dateiWaehlen: 'Datei wählen',
    analysiere: 'Datei wird gelesen …',
    erkannt: 'Vorschläge aus dem Inhalt erkannt – bitte prüfen und bei Bedarf korrigieren.',
    erkanntName: 'Vorschläge aus dem Dateinamen – bitte prüfen.',
    nichtsErkannt: 'Es liess sich nichts erkennen – bitte Titel, Kategorie und Sprache selbst wählen.',
    verkleinert: 'Das Bild wurde für den Upload verkleinert.',
    titel: 'Titel',
    titelPh: 'z. B. Rauchverbot auf dem Balkon',
    bitteWaehlen: 'Bitte wählen',
    tags: 'Themen (optional)',
    seitenformat: 'Format/Grösse',
    anonymLabel: 'Anonym veröffentlichen',
    anonymText: 'Andere Mitglieder sehen dann «anonym» statt deines Namens. Der Vorstand (IT) sieht den Absender weiterhin. Anonyme Uploads werden erst nach Freigabe durch it@ sichtbar.',
    protokoll: 'Uploads werden mit deinem Konto und deiner IP-Adresse protokolliert (Missbrauchsschutz).',
    datenschutz: 'Mehr dazu in der',
    datenschutzLink: 'Datenschutzerklärung',
    datenschutzHref: '/datenschutz/',
    gespeichertAls: 'Wird gespeichert als',
    hochladen: 'Hochladen',
    hochladenLaeuft: 'Wird hochgeladen …',
    fertigSichtbar: 'Hochgeladen – die Vorlage ist jetzt für alle Mitglieder sichtbar.',
    fertigWartet: 'Hochgeladen – die Vorlage wird nach der Freigabe durch it@ sichtbar.',
    zuGross: 'Die Datei ist grösser als 5 MB. Bitte als kleineres PDF exportieren (z. B. «Datei → Exportieren → PDF» mit reduzierter Grösse) oder die Datei aufteilen.',
    typNein: 'Dieser Dateityp ist nicht erlaubt. Erlaubt: PDF, DOCX, XLSX, PPTX, ODT, PNG, JPG, WebP.',
    pflicht: 'Bitte Titel, Kategorie und Sprache ausfüllen.',
    // Dialoge
    speichern: 'Speichern',
    abbrechen: 'Abbrechen',
    bearbeitenTitel: 'Vorlage bearbeiten',
    meldenTitel: 'Vorlage melden',
    meldenText: 'Was stimmt nicht? Die Meldung geht an den Vorstand (IT). Melden zwei Mitglieder dieselbe Vorlage, wird sie automatisch ausgeblendet, bis der Vorstand entschieden hat.',
    grund: 'Grund',
    meldenSenden: 'Meldung senden',
    gemeldetDanke: 'Danke – die Meldung ist beim Vorstand.',
    loeschenFrage: (t: string) => `«${t}» wirklich löschen? Das lässt sich nicht rückgängig machen.`,
    geloescht: 'Gelöscht.',
    gespeichert: 'Gespeichert.',
    fehler: 'Das hat nicht geklappt. Bitte nochmals versuchen.',
    netz: 'Verbindungsfehler. Bitte nochmals versuchen.',
    // Admin
    aWartend: 'Warten auf Freigabe (anonym)',
    aGemeldet: 'Gemeldet oder ausgeblendet',
    aKeine: 'Nichts offen.',
    freigeben: 'Freigeben',
    ablehnen: 'Ablehnen',
    verstecken: 'Ausblenden',
    einblenden: 'Wieder einblenden',
    erledigt: 'Meldungen erledigt',
    sperrlisteFrage: 'Datei zusätzlich sperren (kann nicht erneut hochgeladen werden)?',
    aMitglieder: 'Mitglieder freischalten',
    aMitgliederText: 'Nach einer Mail an it@ die Mitgliedschaft prüfen und die Adresse hier eintragen. Vorname und Name erscheinen als Autor.',
    vorname: 'Vorname',
    name: 'Name',
    rolle: 'Rolle',
    rolleMitglied: 'Mitglied',
    rolleAdmin: 'Admin',
    freischalten: 'Freischalten / speichern',
    sperren: 'Sperren',
    entsperren: 'Entsperren',
    gesperrt: 'gesperrt',
    sperrenFrage: (e: string) => `Konto ${e} sperren? Alle Sitzungen werden sofort beendet.`,
    uploadsAuchVerstecken: 'Seine/ihre Uploads auch ausblenden?',
    uploadsEinblenden: 'Beim Sperren ausgeblendete Uploads wieder einblenden?',
    letzteAnmeldung: 'Letzte Anmeldung',
    uploads: 'Uploads',
    aIp: 'IP-Sperren',
    aIpText: 'Gesperrte Adressen erhalten keinen Login-Code und können nicht hochladen.',
    ip: 'IP-Adresse',
    umfang: 'Umfang',
    einzeln: 'nur diese Adresse',
    netzUmfang: 'ganzes Netz (IPv4 /24, IPv6 /64)',
    tage: 'Dauer (Tage)',
    ipSperren: 'IP sperren',
    aufheben: 'Aufheben',
    bis: 'bis',
    deineIp: 'Deine aktuelle IP',
    hashSperren: (n: number) => `${n} Datei(en) auf der Sperrliste.`,
    aAudit: 'Protokoll (Audit-Log)',
    aAuditText: 'Uploads mit IP bleiben, solange die Datei online ist, plus 90 Tage; übrige Einträge 90 Tage.',
    auditFilter: 'Filtern (Adresse, IP, Aktion, Titel)',
    zeit: 'Zeit',
    wer: 'Wer',
    aktion: 'Aktion',
    dokument: 'Dokument',
    details: 'Details',
    aEinstellung: 'Benachrichtigung',
    mailVeroeffentlichung: 'Bei jeder Veröffentlichung eine kurze Mail an it@ senden',
  },
  en: {
    laden: 'Loading …',
    loginTitel: 'Sign in',
    loginText: 'Enter the email address that has been activated for the members’ area. You will receive a 6-digit code by email – no password needed.',
    email: 'Email address',
    codeAnfordern: 'Request code',
    code: 'Code from the email',
    anmelden: 'Sign in',
    andereAdresse: 'Other address / request a new code',
    nochNicht: 'Not activated yet? Send a short email to',
    nochNichtRest: '– from the address you want to sign in with. We will check your membership and activate you.',
    hallo: 'Hello',
    abmelden: 'Sign out',
    reiterVorlagen: 'Templates',
    reiterHochladen: 'Upload',
    reiterAdmin: 'Admin',
    filter: 'Filters',
    suche: 'Search',
    suchePh: 'Title, keyword, text …',
    alle: 'All',
    kategorie: 'Category',
    sprache: 'Language',
    format: 'Format',
    tag: 'Topic',
    autor: 'Author',
    nurMeine: 'Only my uploads',
    sortierung: 'Sort',
    neu: 'Newest first',
    alt: 'Oldest first',
    az: 'A–Z',
    zuruecksetzen: 'Reset filters',
    treffer: (n: number) => (n === 1 ? '1 template' : `${n} templates`),
    keineTreffer: 'No template matches these filters.',
    leer: 'No templates yet – upload the first one!',
    herunterladen: 'Download',
    bearbeiten: 'Edit',
    neueVersion: 'New version',
    loeschen: 'Delete',
    melden: 'Report',
    gemeldet: 'Reported',
    anonym: 'anonymous',
    von: 'by',
    du: 'you',
    status: { wartet: 'Awaiting approval', versteckt: 'Hidden – under review', abgelehnt: 'Rejected', sichtbar: '' } as Record<string, string>,
    version: 'Version',
    hochTitel: 'Upload a template',
    ersetzenTitel: 'Upload a new version',
    ersetzenAbbrechen: 'Cancel',
    hochIntro: 'PDF, Word, Excel, PowerPoint, ODT or image (PNG, JPG, WebP), max. 5 MB. Large photos are reduced automatically. Uploads are visible to all members immediately.',
    datei: 'Choose a file or drop it here',
    dateiWaehlen: 'Choose file',
    analysiere: 'Reading file …',
    erkannt: 'Suggestions detected from the content – please check and correct if needed.',
    erkanntName: 'Suggestions from the file name – please check.',
    nichtsErkannt: 'Nothing could be detected – please choose title, category and language yourself.',
    verkleinert: 'The image was reduced for uploading.',
    titel: 'Title',
    titelPh: 'e.g. No smoking on the balcony',
    bitteWaehlen: 'Please choose',
    tags: 'Topics (optional)',
    seitenformat: 'Format/size',
    anonymLabel: 'Publish anonymously',
    anonymText: 'Other members will see “anonymous” instead of your name. The board (IT) can still see the sender. Anonymous uploads only become visible after approval by it@.',
    protokoll: 'Uploads are logged with your account and your IP address (abuse protection).',
    datenschutz: 'More in the',
    datenschutzLink: 'privacy policy',
    datenschutzHref: '/en/privacy/',
    gespeichertAls: 'Will be saved as',
    hochladen: 'Upload',
    hochladenLaeuft: 'Uploading …',
    fertigSichtbar: 'Uploaded – the template is now visible to all members.',
    fertigWartet: 'Uploaded – the template will become visible after approval by it@.',
    zuGross: 'The file is larger than 5 MB. Please export a smaller PDF (e.g. “File → Export → PDF” with reduced size) or split the file.',
    typNein: 'This file type is not allowed. Allowed: PDF, DOCX, XLSX, PPTX, ODT, PNG, JPG, WebP.',
    pflicht: 'Please fill in title, category and language.',
    speichern: 'Save',
    abbrechen: 'Cancel',
    bearbeitenTitel: 'Edit template',
    meldenTitel: 'Report template',
    meldenText: 'What is wrong? The report goes to the board (IT). If two members report the same template, it is hidden automatically until the board has decided.',
    grund: 'Reason',
    meldenSenden: 'Send report',
    gemeldetDanke: 'Thank you – the board has received your report.',
    loeschenFrage: (t: string) => `Really delete “${t}”? This cannot be undone.`,
    geloescht: 'Deleted.',
    gespeichert: 'Saved.',
    fehler: 'That did not work. Please try again.',
    netz: 'Connection error. Please try again.',
    aWartend: 'Awaiting approval (anonymous)',
    aGemeldet: 'Reported or hidden',
    aKeine: 'Nothing pending.',
    freigeben: 'Approve',
    ablehnen: 'Reject',
    verstecken: 'Hide',
    einblenden: 'Show again',
    erledigt: 'Reports resolved',
    sperrlisteFrage: 'Also block the file (cannot be uploaded again)?',
    aMitglieder: 'Activate members',
    aMitgliederText: 'After an email to it@, check the membership and enter the address here. First and last name are shown as author.',
    vorname: 'First name',
    name: 'Last name',
    rolle: 'Role',
    rolleMitglied: 'Member',
    rolleAdmin: 'Admin',
    freischalten: 'Activate / save',
    sperren: 'Block',
    entsperren: 'Unblock',
    gesperrt: 'blocked',
    sperrenFrage: (e: string) => `Block account ${e}? All sessions end immediately.`,
    uploadsAuchVerstecken: 'Also hide their uploads?',
    uploadsEinblenden: 'Show uploads that were hidden when blocking again?',
    letzteAnmeldung: 'Last sign-in',
    uploads: 'Uploads',
    aIp: 'IP blocks',
    aIpText: 'Blocked addresses get no sign-in code and cannot upload.',
    ip: 'IP address',
    umfang: 'Scope',
    einzeln: 'this address only',
    netzUmfang: 'whole network (IPv4 /24, IPv6 /64)',
    tage: 'Duration (days)',
    ipSperren: 'Block IP',
    aufheben: 'Lift',
    bis: 'until',
    deineIp: 'Your current IP',
    hashSperren: (n: number) => `${n} file(s) on the block list.`,
    aAudit: 'Audit log',
    aAuditText: 'Uploads with IP are kept while the file is online plus 90 days; other entries 90 days.',
    auditFilter: 'Filter (address, IP, action, title)',
    zeit: 'Time',
    wer: 'Who',
    aktion: 'Action',
    dokument: 'Document',
    details: 'Details',
    aEinstellung: 'Notification',
    mailVeroeffentlichung: 'Send a short email to it@ on every publication',
  },
}[L];

const FORMAT_LABEL: Record<string, string> = { pdf: 'PDF', docx: 'Word', xlsx: 'Excel', pptx: 'PowerPoint', odt: 'ODT', png: L === 'en' ? 'Image' : 'Bild', jpg: L === 'en' ? 'Image' : 'Bild', webp: L === 'en' ? 'Image' : 'Bild' };
const SEITENFORMAT: Record<string, string> = L === 'en'
  ? { hoch: 'portrait', quer: 'landscape', quadratisch: 'square' }
  : { hoch: 'hoch', quer: 'quer', quadratisch: 'quadratisch' };

// ── Zustand ──

interface Ich { id: number; email: string; vorname: string; name: string; admin: boolean }
interface Dok {
  id: string; titel: string; kategorie: string; sprache: string; format: string; tags: string[];
  seitenformat: string; anzeige_name: string; vorschau: boolean; groesse: number; version: number;
  status: string; anonym: boolean; autor: string | null; autor_id: number | null; meins: boolean;
  erstellt: number; geaendert: number; text: string; von_mir_gemeldet: boolean;
  autor_email?: string; meldungen?: { von: string; grund: string; zeit: number; erledigt: boolean }[]; versteckt_grund?: string;
}

let csrf = '';
let ich: Ich | null = null;
let katalog: Katalog | null = null;
let doks: Dok[] = [];
let ersetzeId: string | null = null;
let analyse: Analyse | null = null;

// ── Hilfen ──

const $ = <E extends HTMLElement = HTMLElement>(sel: string, el: ParentNode = wurzel) => el.querySelector(sel) as E;

function esc(s: unknown): string {
  return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!);
}

function label(teil: 'kategorien' | 'sprachen' | 'tags', key: string): string {
  const e = katalog?.[teil].find((x) => x.key === key);
  return e ? e[L] : key;
}

function datum(ts: number): string {
  return new Date(ts * 1000).toLocaleDateString(L === 'en' ? 'en-GB' : 'de-CH', { day: 'numeric', month: 'short', year: 'numeric' });
}

function zeitpunkt(ts: number): string {
  return new Date(ts * 1000).toLocaleString(L === 'en' ? 'en-GB' : 'de-CH', { dateStyle: 'short', timeStyle: 'short' });
}

function groesse(b: number): string {
  return b > 1024 * 1024 ? `${(b / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`;
}

function seitenformatText(s: string): string {
  if (!s) return '';
  const [papier, lage] = s.split('-');
  if (!lage) return SEITENFORMAT[papier] ?? papier;
  return `${papier.toUpperCase()} ${SEITENFORMAT[lage] ?? lage}`;
}

/** Gleiche Regeln wie anzeige_name() in _lib.php (nur für die Live-Anzeige). */
function anzeigeName(kat: string, titel: string, sprache: string, ext: string): string {
  const slug = (titel.toLowerCase()
    .replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
    .normalize('NFD').replace(/[̀-ͯ]/g, '')
    .replace(/[^a-z0-9]+/g, '-').slice(0, 60).replace(/^-+|-+$/g, '')) || 'dokument';
  const sp = ({ mehrsprachig: 'multi', neutral: 'xx' } as Record<string, string>)[sprache] ?? sprache;
  const d = new Date();
  return `${kat}_${slug}_${sp}_${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}.${ext}`;
}

function endungFuer(a: Analyse): string {
  const n = a.datei.name.toLowerCase();
  const m = n.match(/\.([a-z0-9]+)$/);
  const e = m ? m[1] : '';
  return e === 'jpeg' ? 'jpg' : e;
}

let meldungTimer = 0;
function meldung(text: string, art: 'ok' | 'fehler' = 'ok'): void {
  const el = $('#mb-meldung');
  el.textContent = text;
  el.className = `mb-meldung mb-meldung--${art}`;
  el.hidden = false;
  window.clearTimeout(meldungTimer);
  meldungTimer = window.setTimeout(() => (el.hidden = true), art === 'ok' ? 4000 : 9000);
}

async function api<R = Record<string, unknown>>(aktion: string, daten?: Record<string, string | Blob | undefined> | FormData, query = ''): Promise<R & { success: boolean; message?: string }> {
  const opt: RequestInit = { credentials: 'same-origin', headers: { 'X-Locale': L } };
  if (daten) {
    let fd: FormData;
    if (daten instanceof FormData) fd = daten;
    else {
      fd = new FormData();
      for (const [k, v] of Object.entries(daten)) if (v !== undefined) fd.append(k, v);
    }
    fd.append('locale', L);
    opt.method = 'POST';
    opt.body = fd;
    (opt.headers as Record<string, string>)['X-CSRF-Token'] = csrf;
  }
  let res: Response;
  try {
    res = await fetch(`${API}?aktion=${aktion}&locale=${L}${query}`, opt);
  } catch {
    return { success: false, message: T.netz } as R & { success: boolean; message?: string };
  }
  const json = await res.json().catch(() => ({ success: false, message: T.fehler }));
  if (res.status === 401 && aktion !== 'ich') zeigeLogin();
  return json;
}

// ── Start ──

async function start(): Promise<void> {
  const [k, i] = await Promise.all([
    fetch('/php/mitglieder/katalog.json').then((r) => r.json()).catch(() => null),
    api<{ angemeldet: boolean; csrf: string; ich: Ich }>('ich'),
  ]);
  katalog = k;
  $('#mb-laden').hidden = true;
  if (!katalog) {
    $('#mb-laden').hidden = false;
    $('#mb-laden').textContent = T.netz;
    return;
  }
  if (i.success && i.angemeldet) {
    csrf = i.csrf;
    ich = i.ich;
    await zeigeBereich();
  } else {
    zeigeLogin();
  }
}

// ── Login ──

function zeigeLogin(): void {
  ich = null;
  $('#mb-bereich').hidden = true;
  $('#mb-login').hidden = false;
  $('#mb-login').innerHTML = `
    <div class="mb-karte mb-login">
      <h2>${T.loginTitel}</h2>
      <form id="mb-form-email" class="mb-form" novalidate>
        <p>${T.loginText}</p>
        <label class="mb-feld"><span>${T.email}</span>
          <input type="email" name="email" required autocomplete="email" inputmode="email" placeholder="name@beispiel.ch"></label>
        <button type="submit" class="btn btn-accent">${T.codeAnfordern}</button>
      </form>
      <form id="mb-form-code" class="mb-form" hidden novalidate>
        <p id="mb-code-info"></p>
        <label class="mb-feld"><span>${T.code}</span>
          <input type="text" name="code" required inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" placeholder="123456"></label>
        <button type="submit" class="btn btn-accent">${T.anmelden}</button>
        <button type="button" class="mb-link" data-aktion="andere-adresse">${T.andereAdresse}</button>
      </form>
      <p class="mb-klein">${T.nochNicht} <a href="mailto:${IT}">${IT}</a> ${T.nochNichtRest}</p>
    </div>`;
  const fEmail = $<HTMLFormElement>('#mb-form-email');
  const fCode = $<HTMLFormElement>('#mb-form-code');
  let email = '';
  fEmail.addEventListener('submit', async (e) => {
    e.preventDefault();
    email = (fEmail.email as HTMLInputElement).value.trim();
    if (!email || !(fEmail.email as HTMLInputElement).checkValidity()) {
      meldung(L === 'en' ? 'Please enter a valid email address.' : 'Bitte gib eine gültige E-Mail-Adresse ein.', 'fehler');
      return;
    }
    const btn = fEmail.querySelector('button')!;
    btn.disabled = true;
    const r = await api('code_anfordern', { email });
    btn.disabled = false;
    if (!r.success) return meldung(r.message || T.fehler, 'fehler');
    fEmail.hidden = true;
    fCode.hidden = false;
    $('#mb-code-info').textContent = r.message || '';
    (fCode.code as HTMLInputElement).focus();
  });
  fCode.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = fCode.querySelector('button[type=submit]') as HTMLButtonElement;
    btn.disabled = true;
    const r = await api('code_pruefen', { email, code: (fCode.code as HTMLInputElement).value });
    btn.disabled = false;
    if (!r.success) return meldung(r.message || T.fehler, 'fehler');
    const i = await api<{ angemeldet: boolean; csrf: string; ich: Ich }>('ich');
    if (i.angemeldet) {
      csrf = i.csrf;
      ich = i.ich;
      await zeigeBereich();
    }
  });
  fCode.querySelector('[data-aktion=andere-adresse]')!.addEventListener('click', () => {
    fCode.hidden = true;
    fEmail.hidden = false;
    (fEmail.email as HTMLInputElement).focus();
  });
}

// ── Bereich ──

async function zeigeBereich(): Promise<void> {
  if (!ich) return;
  $('#mb-meldung').hidden = true; // alte Fehlermeldung vom Login nicht stehen lassen
  $('#mb-login').hidden = true;
  $('#mb-bereich').hidden = false;
  $('#mb-kopf').innerHTML = `
    <span>${T.hallo} <strong>${esc(ich.vorname || ich.email)}</strong> <span class="mb-klein">(${esc(ich.email)})</span></span>
    <button type="button" class="mb-link" data-aktion="abmelden">${T.abmelden}</button>`;
  $('#mb-reiter').innerHTML = `
    <button type="button" role="tab" data-reiter="vorlagen">${T.reiterVorlagen}</button>
    <button type="button" role="tab" data-reiter="hochladen">${T.reiterHochladen}</button>
    ${ich.admin ? `<button type="button" role="tab" data-reiter="admin">${T.reiterAdmin}</button>` : ''}`;
  filterAufbauen();
  hochladenAufbauen();
  await listeLaden();
  reiterAus(location.hash.replace('#', '') || 'vorlagen');
  const dok = new URLSearchParams(location.search).get('dok');
  if (dok) hervorheben(dok);
}

function reiterAus(name: string): void {
  if (!['vorlagen', 'hochladen', 'admin'].includes(name) || (name === 'admin' && !ich?.admin)) name = 'vorlagen';
  wurzel.querySelectorAll<HTMLButtonElement>('[data-reiter]').forEach((b) => b.setAttribute('aria-selected', String(b.dataset.reiter === name)));
  wurzel.querySelectorAll<HTMLElement>('[data-panel]').forEach((p) => (p.hidden = p.dataset.panel !== name));
  const url = new URL(location.href);
  url.hash = name === 'vorlagen' ? '' : name;
  history.replaceState(null, '', url);
  if (name === 'admin') adminLaden();
}

function hervorheben(id: string): void {
  const d = doks.find((x) => x.id === id);
  if (!d) return;
  if (d.status !== 'sichtbar' && ich?.admin) reiterAus('admin');
  const karte = wurzel.querySelector<HTMLElement>(`[data-dok="${CSS.escape(id)}"]:not([hidden])`)
    ?? wurzel.querySelector<HTMLElement>(`[data-dok="${CSS.escape(id)}"]`);
  if (karte) {
    karte.classList.add('mb-doc--hervor');
    karte.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
}

async function listeLaden(): Promise<void> {
  const r = await api<{ dokumente: Dok[] }>('liste');
  if (!r.success) return meldung(r.message || T.fehler, 'fehler');
  doks = r.dokumente;
  filterOptionen();
  listeZeigen();
  if (ich?.admin && !$('[data-panel=admin]').hidden) adminDokumente();
}

// ── Filter ──

interface Filter { kat: string; spr: string; fmt: string; tag: string; autor: string; meine: boolean; q: string; sort: string }

function filterLesen(): Filter {
  const p = new URLSearchParams(location.search);
  return {
    kat: p.get('kat') ?? '', spr: p.get('spr') ?? '', fmt: p.get('fmt') ?? '', tag: p.get('tag') ?? '',
    autor: p.get('autor') ?? '', meine: p.get('meine') === '1', q: p.get('q') ?? '', sort: p.get('sort') ?? 'neu',
  };
}

function filterSchreiben(f: Filter): void {
  const url = new URL(location.href);
  for (const k of ['kat', 'spr', 'fmt', 'tag', 'autor', 'q', 'sort', 'meine', 'dok']) url.searchParams.delete(k);
  if (f.kat) url.searchParams.set('kat', f.kat);
  if (f.spr) url.searchParams.set('spr', f.spr);
  if (f.fmt) url.searchParams.set('fmt', f.fmt);
  if (f.tag) url.searchParams.set('tag', f.tag);
  if (f.autor) url.searchParams.set('autor', f.autor);
  if (f.meine) url.searchParams.set('meine', '1');
  if (f.q) url.searchParams.set('q', f.q);
  if (f.sort !== 'neu') url.searchParams.set('sort', f.sort);
  history.replaceState(null, '', url);
}

function filterAufbauen(): void {
  const p = $('[data-panel=vorlagen]');
  p.innerHTML = `
    <details class="mb-filter" id="mb-filter">
      <summary>${T.filter} <span id="mb-filter-zahl" class="mb-pill" hidden></span></summary>
      <form class="mb-filter__felder" id="mb-filter-form" role="search">
        <label class="mb-feld mb-feld--breit"><span>${T.suche}</span><input type="search" name="q" placeholder="${T.suchePh}"></label>
        <label class="mb-feld"><span>${T.kategorie}</span><select name="kat"></select></label>
        <label class="mb-feld"><span>${T.sprache}</span><select name="spr"></select></label>
        <label class="mb-feld"><span>${T.format}</span><select name="fmt"></select></label>
        <label class="mb-feld"><span>${T.tag}</span><select name="tag"></select></label>
        <label class="mb-feld"><span>${T.autor}</span><select name="autor"></select></label>
        <label class="mb-feld"><span>${T.sortierung}</span><select name="sort">
          <option value="neu">${T.neu}</option><option value="alt">${T.alt}</option><option value="az">${T.az}</option></select></label>
        <label class="mb-check"><input type="checkbox" name="meine"> ${T.nurMeine}</label>
        <button type="button" class="mb-link" data-aktion="filter-reset">${T.zuruecksetzen}</button>
      </form>
    </details>
    <p class="mb-treffer" id="mb-treffer" aria-live="polite"></p>
    <div class="mb-grid" id="mb-liste"></div>`;
  if (window.matchMedia('(min-width: 861px)').matches) $<HTMLDetailsElement>('#mb-filter').open = true;
  const form = $<HTMLFormElement>('#mb-filter-form');
  form.addEventListener('input', () => {
    const fd = new FormData(form);
    filterSchreiben({
      kat: String(fd.get('kat') ?? ''), spr: String(fd.get('spr') ?? ''), fmt: String(fd.get('fmt') ?? ''),
      tag: String(fd.get('tag') ?? ''), autor: String(fd.get('autor') ?? ''), meine: fd.get('meine') === 'on',
      q: String(fd.get('q') ?? '').trim(), sort: String(fd.get('sort') ?? 'neu'),
    });
    listeZeigen();
  });
  form.addEventListener('submit', (e) => e.preventDefault());
}

function filterOptionen(): void {
  const form = $<HTMLFormElement>('#mb-filter-form');
  if (!form || !katalog) return;
  const sichtbar = doks.filter((d) => d.status === 'sichtbar' || d.meins);
  const opts = (werte: [string, string][]) => `<option value="">${T.alle}</option>` + werte.map(([v, t]) => `<option value="${esc(v)}">${esc(t)}</option>`).join('');
  const vorhanden = (f: (d: Dok) => string[]) => new Set(sichtbar.flatMap(f));
  const kat = vorhanden((d) => [d.kategorie]);
  const spr = vorhanden((d) => [d.sprache]);
  const tag = vorhanden((d) => d.tags);
  const fmt = [...new Set(sichtbar.map((d) => FORMAT_LABEL[d.format] ?? d.format))].sort();
  const autoren = new Map<string, string>();
  for (const d of sichtbar) autoren.set(d.anonym && !d.autor ? 'anonym' : String(d.autor_id ?? ''), d.autor ?? T.anonym);
  (form.kat as HTMLSelectElement).innerHTML = opts(katalog.kategorien.filter((k) => kat.has(k.key)).map((k) => [k.key, k[L]]));
  (form.spr as HTMLSelectElement).innerHTML = opts(katalog.sprachen.filter((k) => spr.has(k.key)).map((k) => [k.key, k[L]]));
  (form.tag as HTMLSelectElement).innerHTML = opts(katalog.tags.filter((k) => tag.has(k.key)).map((k) => [k.key, k[L]]));
  (form.fmt as HTMLSelectElement).innerHTML = opts(fmt.map((f) => [f, f]));
  (form.autor as HTMLSelectElement).innerHTML = opts([...autoren.entries()].sort((a, b) => a[1].localeCompare(b[1])));
  const f = filterLesen();
  (form.q as HTMLInputElement).value = f.q;
  for (const k of ['kat', 'spr', 'fmt', 'tag', 'autor', 'sort'] as const) {
    const sel = form[k] as HTMLSelectElement;
    sel.value = f[k];
    if (sel.value !== f[k] && f[k]) {
      // Wert aus geteiltem Link, der (noch) nicht vorkommt – trotzdem anbieten
      sel.insertAdjacentHTML('beforeend', `<option value="${esc(f[k])}">${esc(f[k])}</option>`);
      sel.value = f[k];
    }
  }
  (form.meine as HTMLInputElement).checked = f.meine;
}

function passt(d: Dok, f: Filter): boolean {
  if (f.kat && d.kategorie !== f.kat) return false;
  if (f.spr && d.sprache !== f.spr) return false;
  if (f.fmt && (FORMAT_LABEL[d.format] ?? d.format) !== f.fmt) return false;
  if (f.tag && !d.tags.includes(f.tag)) return false;
  if (f.autor && (f.autor === 'anonym' ? !(d.anonym && !d.autor) : String(d.autor_id) !== f.autor)) return false;
  if (f.meine && !d.meins) return false;
  if (f.q) {
    const heu = `${d.titel} ${d.tags.map((t) => label('tags', t)).join(' ')} ${label('kategorien', d.kategorie)} ${d.text} ${d.anzeige_name}`
      .toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    const nadeln = f.q.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').split(/\s+/).filter(Boolean);
    if (!nadeln.every((n) => heu.includes(n))) return false;
  }
  return true;
}

function listeZeigen(): void {
  const f = filterLesen();
  // In der Vorlagen-Liste: Sichtbares + eigene (mit Status-Hinweis). Admin-Fälle stehen im Admin-Reiter.
  const basis = doks.filter((d) => d.status === 'sichtbar' || d.meins);
  const liste = basis.filter((d) => passt(d, f));
  liste.sort((a, b) => (f.sort === 'az' ? a.titel.localeCompare(b.titel, L) : f.sort === 'alt' ? a.erstellt - b.erstellt : b.erstellt - a.erstellt));
  const aktiv = ['kat', 'spr', 'fmt', 'tag', 'autor', 'q'].filter((k) => f[k as keyof Filter]).length + (f.meine ? 1 : 0);
  const zahl = $('#mb-filter-zahl');
  zahl.hidden = aktiv === 0;
  zahl.textContent = String(aktiv);
  $('#mb-treffer').textContent = basis.length === 0 ? '' : T.treffer(liste.length);
  $('#mb-liste').innerHTML = basis.length === 0
    ? `<p class="mb-leer">${T.leer}</p>`
    : liste.length === 0 ? `<p class="mb-leer">${T.keineTreffer}</p>` : liste.map((d) => karte(d)).join('');
}

function karte(d: Dok, adminModus = false): string {
  const autor = d.anonym
    ? (d.autor ? `${T.anonym} <span class="mb-klein">(${d.meins ? T.du : esc(d.autor)})</span>` : T.anonym)
    : esc(d.meins ? `${d.autor} (${T.du})` : d.autor);
  const status = T.status[d.status] ?? '';
  const meta = [label('kategorien', d.kategorie), label('sprachen', d.sprache), FORMAT_LABEL[d.format] ?? d.format, seitenformatText(d.seitenformat), groesse(d.groesse)].filter(Boolean);
  const vorschau = d.vorschau
    ? `<img src="${DATEI}?id=${d.id}&vorschau=1&v=${d.version}" alt="" loading="lazy">`
    : `<span class="mb-doc__format">${esc(FORMAT_LABEL[d.format] ?? d.format)}</span>`;
  const knoepfe: string[] = [];
  if (d.status !== 'abgelehnt') knoepfe.push(`<a class="btn btn-accent mb-btn-klein" href="${DATEI}?id=${d.id}" download>${T.herunterladen}</a>`);
  if (d.meins && d.status !== 'abgelehnt') {
    knoepfe.push(`<button type="button" class="mb-link" data-aktion="bearbeiten" data-id="${d.id}">${T.bearbeiten}</button>`);
    knoepfe.push(`<button type="button" class="mb-link" data-aktion="ersetzen" data-id="${d.id}">${T.neueVersion}</button>`);
  }
  if ((d.meins || ich?.admin) && !adminModus) knoepfe.push(`<button type="button" class="mb-link mb-link--rot" data-aktion="loeschen" data-id="${d.id}">${T.loeschen}</button>`);
  if (!d.meins && d.status === 'sichtbar') {
    knoepfe.push(d.von_mir_gemeldet
      ? `<span class="mb-klein">${T.gemeldet}</span>`
      : `<button type="button" class="mb-link" data-aktion="melden" data-id="${d.id}">${T.melden}</button>`);
  }
  const admin: string[] = [];
  if (adminModus && ich?.admin) {
    if (d.status === 'wartet') {
      admin.push(`<button type="button" class="btn btn-accent mb-btn-klein" data-aktion="admin" data-was="freigeben" data-id="${d.id}">${T.freigeben}</button>`);
      admin.push(`<button type="button" class="mb-link mb-link--rot" data-aktion="admin" data-was="ablehnen" data-id="${d.id}">${T.ablehnen}</button>`);
    }
    if (d.status === 'sichtbar') admin.push(`<button type="button" class="mb-link" data-aktion="admin" data-was="verstecken" data-id="${d.id}">${T.verstecken}</button>`);
    if (d.status === 'versteckt') admin.push(`<button type="button" class="btn btn-accent mb-btn-klein" data-aktion="admin" data-was="einblenden" data-id="${d.id}">${T.einblenden}</button>`);
    if (d.meldungen?.some((m) => !m.erledigt) && d.status === 'sichtbar') admin.push(`<button type="button" class="mb-link" data-aktion="admin" data-was="meldungen_erledigt" data-id="${d.id}">${T.erledigt}</button>`);
    admin.push(`<button type="button" class="mb-link mb-link--rot" data-aktion="admin" data-was="loeschen" data-id="${d.id}">${T.loeschen}</button>`);
  }
  const meldungen = adminModus && d.meldungen?.length
    ? `<ul class="mb-meldungen">${d.meldungen.map((m) => `<li${m.erledigt ? ' class="mb-erledigt"' : ''}><strong>${esc(m.von)}</strong>, ${zeitpunkt(m.zeit)}: ${esc(m.grund)}</li>`).join('')}</ul>`
    : '';
  return `
    <article class="mb-doc card" data-dok="${d.id}">
      <div class="mb-doc__bild">${vorschau}</div>
      <div class="mb-doc__inhalt">
        ${status ? `<p class="mb-status mb-status--${d.status}">${esc(status)}</p>` : ''}
        <h3 class="mb-doc__titel">${esc(d.titel)}</h3>
        <p class="mb-doc__meta">${meta.map(esc).join(' · ')}</p>
        ${d.tags.length ? `<p class="mb-tags">${d.tags.map((t) => `<span class="mb-pill">${esc(label('tags', t))}</span>`).join('')}</p>` : ''}
        <p class="mb-doc__autor">${T.von} ${autor} · ${datum(d.erstellt)}${d.version > 1 ? ` · ${T.version} ${d.version}` : ''}</p>
        ${adminModus && d.autor_email ? `<p class="mb-klein">${esc(d.autor_email)}</p>` : ''}
        <p class="mb-doc__name mb-klein">${esc(d.anzeige_name)}</p>
        ${meldungen}
        <div class="mb-doc__knoepfe">${knoepfe.join('')}</div>
        ${admin.length ? `<div class="mb-doc__knoepfe mb-doc__admin">${admin.join('')}</div>` : ''}
      </div>
    </article>`;
}

// ── Hochladen ──

function auswahl(teil: 'kategorien' | 'sprachen', name: string, wert = ''): string {
  return `<select name="${name}" required><option value="">${T.bitteWaehlen}</option>${katalog![teil]
    .map((k) => `<option value="${k.key}"${k.key === wert ? ' selected' : ''}>${esc(k[L])}</option>`).join('')}</select>`;
}

function tagAuswahl(gewaehlt: string[] = []): string {
  return `<div class="mb-chips">${katalog!.tags.map((t) => `<label class="mb-chip"><input type="checkbox" name="tags" value="${t.key}"${gewaehlt.includes(t.key) ? ' checked' : ''}><span>${esc(t[L])}</span></label>`).join('')}</div>`;
}

function hochladenAufbauen(): void {
  const p = $('[data-panel=hochladen]');
  p.innerHTML = `
    <div class="mb-karte mb-upload">
      <h2 id="mb-up-titel">${T.hochTitel}</h2>
      <p id="mb-up-ersetzen" class="mb-hinweis" hidden></p>
      <p>${T.hochIntro}</p>
      <form id="mb-up-form" class="mb-form" novalidate>
        <label class="mb-drop" id="mb-drop">
          <input type="file" name="datei" id="mb-datei" accept=".pdf,.docx,.xlsx,.pptx,.odt,.png,.jpg,.jpeg,.webp,application/pdf,image/png,image/jpeg,image/webp">
          <span class="mb-drop__text">${T.datei}</span>
          <span class="btn btn-accent mb-btn-klein" aria-hidden="true">${T.dateiWaehlen}</span>
          <span class="mb-drop__name" id="mb-drop-name"></span>
        </label>
        <div id="mb-up-felder" hidden>
          <p id="mb-up-erkannt" class="mb-hinweis"></p>
          <div class="mb-up-raster">
            <div class="mb-up-vorschau" id="mb-up-vorschau"></div>
            <div class="mb-up-felder">
              <label class="mb-feld"><span>${T.titel} *</span><input type="text" name="titel" required minlength="3" maxlength="120" placeholder="${T.titelPh}"></label>
              <label class="mb-feld"><span>${T.kategorie} *</span>${auswahl('kategorien', 'kategorie')}</label>
              <label class="mb-feld"><span>${T.sprache} *</span>${auswahl('sprachen', 'sprache')}</label>
              <p class="mb-klein" id="mb-up-format"></p>
            </div>
          </div>
          <fieldset class="mb-feld"><legend>${T.tags}</legend>${tagAuswahl()}</fieldset>
          <div class="mb-anonym" id="mb-anonym-block">
            <label class="mb-check"><input type="checkbox" name="anonym"> <strong>${T.anonymLabel}</strong></label>
            <p class="mb-klein">${T.anonymText}</p>
          </div>
          <p class="mb-name">${T.gespeichertAls}: <code id="mb-up-name"></code></p>
          <p class="mb-klein mb-protokoll">${T.protokoll} ${T.datenschutz} <a href="${T.datenschutzHref}">${T.datenschutzLink}</a>.</p>
          <div class="mb-fortschritt" id="mb-fortschritt" hidden><span></span></div>
          <div class="mb-up-knoepfe">
            <button type="submit" class="btn btn-accent" id="mb-up-senden">${T.hochladen}</button>
            <button type="button" class="mb-link" data-aktion="ersetzen-abbrechen" id="mb-up-abbrechen" hidden>${T.ersetzenAbbrechen}</button>
          </div>
        </div>
      </form>
    </div>`;
  const form = $<HTMLFormElement>('#mb-up-form');
  const input = $<HTMLInputElement>('#mb-datei');
  const drop = $('#mb-drop');
  input.addEventListener('change', () => input.files?.[0] && dateiGewaehlt(input.files[0]));
  drop.addEventListener('dragover', (e) => { e.preventDefault(); drop.classList.add('mb-drop--aktiv'); });
  drop.addEventListener('dragleave', () => drop.classList.remove('mb-drop--aktiv'));
  drop.addEventListener('drop', (e) => {
    e.preventDefault();
    drop.classList.remove('mb-drop--aktiv');
    const f = e.dataTransfer?.files?.[0];
    if (f) dateiGewaehlt(f);
  });
  form.addEventListener('input', nameAktualisieren);
  form.addEventListener('submit', (e) => { e.preventDefault(); hochladenSenden(); });
}

function nameAktualisieren(): void {
  const form = $<HTMLFormElement>('#mb-up-form');
  if (!analyse) return;
  const kat = (form.kategorie as HTMLSelectElement).value || 'kategorie';
  const sp = (form.sprache as HTMLSelectElement).value || 'sprache';
  $('#mb-up-name').textContent = anzeigeName(kat, (form.titel as HTMLInputElement).value, sp, endungFuer(analyse));
}

async function dateiGewaehlt(f: File): Promise<void> {
  const form = $<HTMLFormElement>('#mb-up-form');
  const a = dateiArt(f);
  if (a === 'unbekannt') {
    meldung(/\.svg$/i.test(f.name) ? (L === 'en' ? 'SVG files are not allowed (they can contain scripts). Please export as PNG or PDF.' : 'SVG-Dateien sind nicht erlaubt (können Skripte enthalten). Bitte als PNG oder PDF exportieren.') : T.typNein, 'fehler');
    return;
  }
  if (a !== 'bild' && f.size > MAX_BYTES) {
    meldung(T.zuGross, 'fehler');
    return;
  }
  $('#mb-drop-name').textContent = `${f.name} · ${groesse(f.size)}`;
  $('#mb-up-felder').hidden = false;
  $('#mb-up-erkannt').textContent = T.analysiere;
  $('#mb-up-vorschau').innerHTML = '<span class="mb-lader" aria-hidden="true"></span>';
  if (analyse?.vorschauUrl) URL.revokeObjectURL(analyse.vorschauUrl);
  analyse = await analysieren(f);
  if (analyse.datei.size > MAX_BYTES) {
    meldung(T.zuGross, 'fehler');
  }
  const v = vorschlagen(katalog!, { dateiname: f.name, text: analyse.text, seiten: analyse.seiten, seitenformat: analyse.seitenformat });
  // Beim Ersetzen bleiben die bisherigen Angaben, sofern ausgefüllt
  const titel = form.titel as HTMLInputElement;
  if (!ersetzeId || !titel.value) titel.value = v.titel;
  const kat = form.kategorie as HTMLSelectElement;
  if (!ersetzeId || !kat.value) kat.value = v.kategorie;
  const spr = form.sprache as HTMLSelectElement;
  if (!ersetzeId || !spr.value) spr.value = analyse.art === 'bild' && !analyse.text ? spr.value : v.sprache;
  if (!ersetzeId) form.querySelectorAll<HTMLInputElement>('input[name=tags]').forEach((c) => (c.checked = v.tags.includes(c.value)));
  $('#mb-up-erkannt').textContent = [
    v.grundlage === 'text' ? T.erkannt : v.grundlage === 'dateiname' ? T.erkanntName : T.nichtsErkannt,
    analyse.verkleinert ? T.verkleinert : '',
  ].filter(Boolean).join(' ');
  $('#mb-up-vorschau').innerHTML = analyse.vorschauUrl ? `<img src="${analyse.vorschauUrl}" alt="">` : `<span class="mb-doc__format">${esc(endungFuer(analyse).toUpperCase())}</span>`;
  $('#mb-up-format').textContent = [FORMAT_LABEL[endungFuer(analyse)] ?? '', seitenformatText(analyse.seitenformat), groesse(analyse.datei.size), analyse.seiten > 1 ? `${analyse.seiten} ${L === 'en' ? 'pages' : 'Seiten'}` : ''].filter(Boolean).join(' · ');
  nameAktualisieren();
  titel.focus();
}

function hochladenSenden(): void {
  const form = $<HTMLFormElement>('#mb-up-form');
  if (!analyse) return meldung(T.datei, 'fehler');
  const titel = (form.titel as HTMLInputElement).value.trim();
  const kat = (form.kategorie as HTMLSelectElement).value;
  const spr = (form.sprache as HTMLSelectElement).value;
  if (titel.length < 3 || !kat || !spr) return meldung(T.pflicht, 'fehler');
  if (analyse.datei.size > MAX_BYTES) return meldung(T.zuGross, 'fehler');
  const fd = new FormData();
  fd.append('datei', analyse.datei, analyse.datei.name);
  if (analyse.vorschau) fd.append('vorschau', analyse.vorschau, 'vorschau');
  fd.append('titel', titel);
  fd.append('kategorie', kat);
  fd.append('sprache', spr);
  fd.append('tags', [...form.querySelectorAll<HTMLInputElement>('input[name=tags]:checked')].map((c) => c.value).join(','));
  fd.append('seitenformat', analyse.seitenformat);
  fd.append('erkannter_text', analyse.text.slice(0, 20000));
  if ((form.anonym as HTMLInputElement).checked) fd.append('anonym', '1');
  if (ersetzeId) fd.append('ersetzt', ersetzeId);
  fd.append('locale', L);

  const btn = $<HTMLButtonElement>('#mb-up-senden');
  const balken = $('#mb-fortschritt');
  btn.disabled = true;
  btn.textContent = T.hochladenLaeuft;
  balken.hidden = false;
  const xhr = new XMLHttpRequest();
  xhr.open('POST', `${API}?aktion=hochladen&locale=${L}`);
  xhr.setRequestHeader('X-CSRF-Token', csrf);
  xhr.upload.onprogress = (e) => {
    if (e.lengthComputable) (balken.firstElementChild as HTMLElement).style.width = `${Math.round((e.loaded / e.total) * 100)}%`;
  };
  const ende = () => {
    btn.disabled = false;
    btn.textContent = T.hochladen;
    balken.hidden = true;
    (balken.firstElementChild as HTMLElement).style.width = '0';
  };
  xhr.onerror = () => { ende(); meldung(T.netz, 'fehler'); };
  xhr.onload = async () => {
    ende();
    let r: { success: boolean; message?: string; status?: string; id?: string };
    try { r = JSON.parse(xhr.responseText); } catch { r = { success: false, message: T.fehler }; }
    if (!r.success) return meldung(r.message || T.fehler, 'fehler');
    meldung(r.status === 'wartet' ? T.fertigWartet : T.fertigSichtbar);
    uploadZuruecksetzen();
    await listeLaden();
    reiterAus('vorlagen');
    if (r.id) hervorheben(r.id);
  };
  xhr.send(fd);
}

function uploadZuruecksetzen(): void {
  ersetzeId = null;
  if (analyse?.vorschauUrl) URL.revokeObjectURL(analyse.vorschauUrl);
  analyse = null;
  hochladenAufbauen();
}

function ersetzenStarten(d: Dok): void {
  uploadZuruecksetzen();
  ersetzeId = d.id;
  const form = $<HTMLFormElement>('#mb-up-form');
  $('#mb-up-titel').textContent = T.ersetzenTitel;
  const hinweis = $('#mb-up-ersetzen');
  hinweis.hidden = false;
  hinweis.textContent = `«${d.titel}» (${T.version} ${d.version})`;
  $('#mb-up-abbrechen').hidden = false;
  (form.titel as HTMLInputElement).value = d.titel;
  (form.kategorie as HTMLSelectElement).value = d.kategorie;
  (form.sprache as HTMLSelectElement).value = d.sprache;
  form.querySelectorAll<HTMLInputElement>('input[name=tags]').forEach((c) => (c.checked = d.tags.includes(c.value)));
  $('#mb-anonym-block').hidden = true; // Anonym-Status bleibt beim Ersetzen erhalten
  reiterAus('hochladen');
  $('#mb-datei').focus();
}

// ── Dialoge ──

function dialog(inhalt: string): HTMLDialogElement {
  const dlg = $<HTMLDialogElement>('#mb-dialog');
  dlg.innerHTML = inhalt;
  dlg.showModal();
  dlg.querySelector<HTMLElement>('[data-aktion=dialog-zu]')?.addEventListener('click', () => dlg.close());
  return dlg;
}

function bearbeitenDialog(d: Dok): void {
  const dlg = dialog(`
    <form method="dialog" class="mb-form" id="mb-dlg-form">
      <h2>${T.bearbeitenTitel}</h2>
      <label class="mb-feld"><span>${T.titel} *</span><input type="text" name="titel" required minlength="3" maxlength="120" value="${esc(d.titel)}"></label>
      <label class="mb-feld"><span>${T.kategorie} *</span>${auswahl('kategorien', 'kategorie', d.kategorie)}</label>
      <label class="mb-feld"><span>${T.sprache} *</span>${auswahl('sprachen', 'sprache', d.sprache)}</label>
      <fieldset class="mb-feld"><legend>${T.tags}</legend>${tagAuswahl(d.tags)}</fieldset>
      <div class="mb-up-knoepfe">
        <button type="submit" class="btn btn-accent">${T.speichern}</button>
        <button type="button" class="mb-link" data-aktion="dialog-zu">${T.abbrechen}</button>
      </div>
    </form>`);
  const form = dlg.querySelector('form') as HTMLFormElement;
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const r = await api('aendern', {
      id: d.id,
      titel: (form.titel as HTMLInputElement).value,
      kategorie: (form.kategorie as HTMLSelectElement).value,
      sprache: (form.sprache as HTMLSelectElement).value,
      tags: [...form.querySelectorAll<HTMLInputElement>('input[name=tags]:checked')].map((c) => c.value).join(','),
    });
    if (!r.success) return meldung(r.message || T.fehler, 'fehler');
    dlg.close();
    meldung(T.gespeichert);
    await listeLaden();
  });
}

function meldenDialog(d: Dok): void {
  const dlg = dialog(`
    <form method="dialog" class="mb-form">
      <h2>${T.meldenTitel}</h2>
      <p><strong>${esc(d.titel)}</strong></p>
      <p class="mb-klein">${T.meldenText}</p>
      <label class="mb-feld"><span>${T.grund} *</span><textarea name="grund" rows="3" required minlength="3" maxlength="500"></textarea></label>
      <div class="mb-up-knoepfe">
        <button type="submit" class="btn btn-accent">${T.meldenSenden}</button>
        <button type="button" class="mb-link" data-aktion="dialog-zu">${T.abbrechen}</button>
      </div>
    </form>`);
  const form = dlg.querySelector('form') as HTMLFormElement;
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const r = await api('melden', { id: d.id, grund: (form.grund as HTMLTextAreaElement).value });
    if (!r.success) return meldung(r.message || T.fehler, 'fehler');
    dlg.close();
    meldung(T.gemeldetDanke);
    await listeLaden();
  });
}

// ── Admin ──

async function adminLaden(): Promise<void> {
  const p = $('[data-panel=admin]');
  if (!p.dataset.aufgebaut) {
    p.dataset.aufgebaut = '1';
    p.innerHTML = `
      <section class="mb-admin-teil"><h2>${T.aWartend}</h2><div class="mb-grid" id="mb-a-wartend"></div></section>
      <section class="mb-admin-teil"><h2>${T.aGemeldet}</h2><div class="mb-grid" id="mb-a-gemeldet"></div></section>
      <section class="mb-admin-teil mb-karte">
        <h2>${T.aMitglieder}</h2><p class="mb-klein">${T.aMitgliederText}</p>
        <form class="mb-form mb-form--reihe" id="mb-a-mitglied">
          <label class="mb-feld"><span>${T.email} *</span><input type="email" name="email" required></label>
          <label class="mb-feld"><span>${T.vorname} *</span><input type="text" name="vorname" required maxlength="80"></label>
          <label class="mb-feld"><span>${T.name} *</span><input type="text" name="name" required maxlength="80"></label>
          <label class="mb-feld"><span>${T.rolle}</span><select name="rolle"><option value="mitglied">${T.rolleMitglied}</option><option value="admin">${T.rolleAdmin}</option></select></label>
          <button type="submit" class="btn btn-accent">${T.freischalten}</button>
        </form>
        <div class="mb-tabelle-wrap"><table class="mb-tabelle" id="mb-a-mitglieder"></table></div>
      </section>
      <section class="mb-admin-teil mb-karte">
        <h2>${T.aIp}</h2><p class="mb-klein">${T.aIpText} <span id="mb-a-meine-ip"></span></p>
        <form class="mb-form mb-form--reihe" id="mb-a-ip">
          <label class="mb-feld"><span>${T.ip} *</span><input type="text" name="ip" required></label>
          <label class="mb-feld"><span>${T.umfang}</span><select name="umfang"><option value="einzeln">${T.einzeln}</option><option value="netz">${T.netzUmfang}</option></select></label>
          <label class="mb-feld"><span>${T.tage}</span><input type="number" name="tage" value="30" min="1" max="3650"></label>
          <label class="mb-feld"><span>${T.grund}</span><input type="text" name="grund" maxlength="300"></label>
          <button type="submit" class="btn btn-accent">${T.ipSperren}</button>
        </form>
        <div class="mb-tabelle-wrap"><table class="mb-tabelle" id="mb-a-sperren"></table></div>
        <p class="mb-klein" id="mb-a-hash"></p>
      </section>
      <section class="mb-admin-teil mb-karte">
        <h2>${T.aEinstellung}</h2>
        <label class="mb-check"><input type="checkbox" id="mb-a-mail"> ${T.mailVeroeffentlichung}</label>
      </section>
      <section class="mb-admin-teil mb-karte">
        <h2>${T.aAudit}</h2><p class="mb-klein">${T.aAuditText}</p>
        <label class="mb-feld"><span>${T.auditFilter}</span><input type="search" id="mb-a-audit-filter"></label>
        <div class="mb-tabelle-wrap"><table class="mb-tabelle mb-tabelle--klein" id="mb-a-audit"></table></div>
      </section>`;
    $<HTMLFormElement>('#mb-a-mitglied').addEventListener('submit', async (e) => {
      e.preventDefault();
      const f = e.currentTarget as HTMLFormElement;
      const r = await api('admin_mitglied_speichern', Object.fromEntries(new FormData(f)) as Record<string, string>);
      if (!r.success) return meldung(r.message || T.fehler, 'fehler');
      f.reset();
      meldung(T.gespeichert);
      adminMitglieder();
    });
    $<HTMLFormElement>('#mb-a-ip').addEventListener('submit', async (e) => {
      e.preventDefault();
      const f = e.currentTarget as HTMLFormElement;
      const r = await api('admin_ip_sperre_setzen', Object.fromEntries(new FormData(f)) as Record<string, string>);
      if (!r.success) return meldung(r.message || T.fehler, 'fehler');
      f.reset();
      meldung(T.gespeichert);
      adminSperren();
    });
    $<HTMLInputElement>('#mb-a-mail').addEventListener('change', async (e) => {
      const r = await api('admin_einstellung', { mail_bei_veroeffentlichung: (e.target as HTMLInputElement).checked ? '1' : '' });
      meldung(r.success ? T.gespeichert : r.message || T.fehler, r.success ? 'ok' : 'fehler');
    });
    let t = 0;
    $<HTMLInputElement>('#mb-a-audit-filter').addEventListener('input', () => {
      window.clearTimeout(t);
      t = window.setTimeout(adminAudit, 300);
    });
  }
  adminDokumente();
  await Promise.all([adminMitglieder(), adminSperren(), adminAudit()]);
}

function adminDokumente(): void {
  const wartend = doks.filter((d) => d.status === 'wartet');
  const gemeldet = doks.filter((d) => d.status === 'versteckt' || (d.status === 'sichtbar' && d.meldungen?.some((m) => !m.erledigt)));
  $('#mb-a-wartend').innerHTML = wartend.length ? wartend.map((d) => karte(d, true)).join('') : `<p class="mb-leer">${T.aKeine}</p>`;
  $('#mb-a-gemeldet').innerHTML = gemeldet.length ? gemeldet.map((d) => karte(d, true)).join('') : `<p class="mb-leer">${T.aKeine}</p>`;
}

async function adminMitglieder(): Promise<void> {
  const r = await api<{ mitglieder: Record<string, string | number | null>[]; mail_bei_veroeffentlichung: boolean }>('admin_mitglieder');
  if (!r.success) return;
  $<HTMLInputElement>('#mb-a-mail').checked = r.mail_bei_veroeffentlichung;
  $('#mb-a-mitglieder').innerHTML = `<thead><tr><th>${T.name}</th><th>${T.email}</th><th>${T.rolle}</th><th>${T.uploads}</th><th>${T.letzteAnmeldung}</th><th></th></tr></thead><tbody>${r.mitglieder.map((m) => `
    <tr${m.status === 'gesperrt' ? ' class="mb-gesperrt"' : ''}>
      <td>${esc(`${m.vorname} ${m.name}`)}${m.status === 'gesperrt' ? ` <span class="mb-status mb-status--versteckt">${T.gesperrt}</span>` : ''}</td>
      <td>${esc(m.email)}</td>
      <td>${m.rolle === 'admin' ? T.rolleAdmin : T.rolleMitglied}</td>
      <td>${esc(m.uploads)}</td>
      <td>${m.letzte_anmeldung ? zeitpunkt(Number(m.letzte_anmeldung)) : '–'}</td>
      <td class="mb-nowrap">
        <button type="button" class="mb-link" data-aktion="mitglied-bearbeiten" data-email="${esc(m.email)}" data-vorname="${esc(m.vorname)}" data-name="${esc(m.name)}" data-rolle="${esc(m.rolle)}">${T.bearbeiten}</button>
        ${Number(m.id) === ich?.id ? '' : `<button type="button" class="mb-link ${m.status === 'gesperrt' ? '' : 'mb-link--rot'}" data-aktion="mitglied-status" data-id="${m.id}" data-email="${esc(m.email)}" data-status="${m.status === 'gesperrt' ? 'aktiv' : 'gesperrt'}">${m.status === 'gesperrt' ? T.entsperren : T.sperren}</button>`}
      </td>
    </tr>`).join('')}</tbody>`;
}

async function adminSperren(): Promise<void> {
  const r = await api<{ sperren: Record<string, string | number>[]; hash_sperren: number; deine_ip: string }>('admin_ip_sperren');
  if (!r.success) return;
  $('#mb-a-meine-ip').textContent = `${T.deineIp}: ${r.deine_ip}`;
  $('#mb-a-hash').textContent = T.hashSperren(r.hash_sperren);
  $('#mb-a-sperren').innerHTML = r.sperren.length
    ? `<tbody>${r.sperren.map((s) => `<tr><td><code>${esc(s.netz)}</code></td><td>${T.bis} ${zeitpunkt(Number(s.bis))}</td><td>${esc(s.grund)}</td><td><button type="button" class="mb-link" data-aktion="ip-aufheben" data-id="${s.id}">${T.aufheben}</button></td></tr>`).join('')}</tbody>`
    : '';
}

async function adminAudit(): Promise<void> {
  const filter = $<HTMLInputElement>('#mb-a-audit-filter')?.value ?? '';
  const r = await api<{ eintraege: Record<string, string | number | null>[] }>('admin_audit', undefined, `&filter=${encodeURIComponent(filter)}`);
  if (!r.success) return;
  $('#mb-a-audit').innerHTML = `<thead><tr><th>${T.zeit}</th><th>${T.wer}</th><th>${T.aktion}</th><th>${T.dokument}</th><th>IP</th><th>${T.details}</th></tr></thead><tbody>${r.eintraege.map((a) => `
    <tr>
      <td class="mb-nowrap">${zeitpunkt(Number(a.zeit))}</td>
      <td>${esc(a.email)}</td>
      <td>${esc(a.aktion)}</td>
      <td>${esc(a.dokument_titel ?? (a.dokument_id ? '…' : ''))}</td>
      <td><button type="button" class="mb-link" data-aktion="ip-uebernehmen" data-ip="${esc(a.ip)}" title="${esc(a.user_agent)}">${esc(a.ip)}</button></td>
      <td>${esc(a.details)}</td>
    </tr>`).join('')}</tbody>`;
}

// ── Klicks (Ereignis-Delegation) ──

wurzel?.addEventListener('click', async (e) => {
  const el = (e.target as HTMLElement).closest<HTMLElement>('[data-aktion], [data-reiter]');
  if (!el) return;
  if (el.dataset.reiter) return reiterAus(el.dataset.reiter);
  const d = doks.find((x) => x.id === el.dataset.id);
  switch (el.dataset.aktion) {
    case 'abmelden':
      await api('abmelden', {});
      csrf = '';
      zeigeLogin();
      break;
    case 'filter-reset':
      filterSchreiben({ kat: '', spr: '', fmt: '', tag: '', autor: '', meine: false, q: '', sort: 'neu' });
      filterOptionen();
      listeZeigen();
      break;
    case 'bearbeiten':
      if (d) bearbeitenDialog(d);
      break;
    case 'ersetzen':
      if (d) ersetzenStarten(d);
      break;
    case 'ersetzen-abbrechen':
      uploadZuruecksetzen();
      reiterAus('vorlagen');
      break;
    case 'melden':
      if (d) meldenDialog(d);
      break;
    case 'loeschen':
      if (d && confirm(T.loeschenFrage(d.titel))) {
        const r = await api('loeschen', { id: d.id });
        if (!r.success) return meldung(r.message || T.fehler, 'fehler');
        meldung(T.geloescht);
        await listeLaden();
      }
      break;
    case 'admin': {
      if (!d) return;
      const was = el.dataset.was!;
      let sperrliste = '';
      if (was === 'loeschen' || was === 'ablehnen') {
        if (!confirm(T.loeschenFrage(d.titel))) return;
        sperrliste = confirm(T.sperrlisteFrage) ? '1' : '';
      }
      const r = await api('admin_dokument', { id: d.id, was, sperrliste });
      if (!r.success) return meldung(r.message || T.fehler, 'fehler');
      meldung(T.gespeichert);
      await listeLaden();
      break;
    }
    case 'mitglied-bearbeiten': {
      const f = $<HTMLFormElement>('#mb-a-mitglied');
      (f.email as HTMLInputElement).value = el.dataset.email ?? '';
      (f.vorname as HTMLInputElement).value = el.dataset.vorname ?? '';
      (f.elements.namedItem('name') as HTMLInputElement).value = el.dataset.name ?? '';
      (f.rolle as HTMLSelectElement).value = el.dataset.rolle ?? 'mitglied';
      f.scrollIntoView({ behavior: 'smooth', block: 'center' });
      break;
    }
    case 'mitglied-status': {
      const sperren = el.dataset.status === 'gesperrt';
      if (sperren && !confirm(T.sperrenFrage(el.dataset.email ?? ''))) return;
      const zusatz = sperren ? confirm(T.uploadsAuchVerstecken) : confirm(T.uploadsEinblenden);
      const r = await api('admin_mitglied_status', {
        id: el.dataset.id, status: el.dataset.status,
        uploads_verstecken: sperren && zusatz ? '1' : '', uploads_einblenden: !sperren && zusatz ? '1' : '',
      });
      if (!r.success) return meldung(r.message || T.fehler, 'fehler');
      meldung(T.gespeichert);
      await Promise.all([adminMitglieder(), listeLaden()]);
      break;
    }
    case 'ip-uebernehmen': {
      const f = $<HTMLFormElement>('#mb-a-ip');
      (f.ip as HTMLInputElement).value = el.dataset.ip ?? '';
      f.scrollIntoView({ behavior: 'smooth', block: 'center' });
      (f.grund as HTMLInputElement).focus();
      break;
    }
    case 'ip-aufheben': {
      const r = await api('admin_ip_sperre_aufheben', { id: el.dataset.id });
      if (!r.success) return meldung(r.message || T.fehler, 'fehler');
      adminSperren();
      break;
    }
  }
});

window.addEventListener('hashchange', () => ich && reiterAus(location.hash.replace('#', '')));

if (wurzel) start();
