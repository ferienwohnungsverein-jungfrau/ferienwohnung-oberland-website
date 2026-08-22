// Geprüfter Zugriff auf die Datenfiles aus der Redaktion (src/data/*.json).
// Ein Tippfehler im CMS (z.B. fehlender Name) lässt den Build hier scheitern.
import { z } from 'astro:content';
import vorstandRoh from '../data/vorstand.json';
import kontaktRoh from '../data/kontakt.json';

const VorstandSchema = z.object({
  vorstand: z
    .array(
      z.object({
        rolle: z.string().min(1, 'Rolle fehlt'),
        name: z.string().min(1, 'Name fehlt'),
        ort: z.string().min(1, 'Ort fehlt'),
        offen: z.boolean().default(false),
      }),
    )
    .min(1, 'Vorstand darf nicht leer sein'),
});

const KontaktSchema = z.object({
  presseEmail: z.string().email('Presse-E-Mail ist keine gültige Adresse'),
  vereinsTelefon: z.string().min(1),
  vereinsTelefonLink: z.string().regex(/^\+\d{6,15}$/, 'Telefon-Link muss wie +41783195971 aussehen'),
});

export const vorstand = VorstandSchema.parse(vorstandRoh).vorstand;
export const kontakt = KontaktSchema.parse(kontaktRoh);
