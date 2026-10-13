---
icon: clipboard-check
---

# Inventario

> **Inclusa.** Fa parte del gestionale, non c'è niente da attivare.

Con un **inventario** conti i pezzi che ci sono davvero e il gestionale
corregge la giacenza dove non torna. È un documento come il
[carico e lo scarico](magazzino-carichi-scarichi.md): una riga per versione,
una bozza che salvi mentre conti, una conferma alla fine. Solo la conferma
tocca la giacenza.

Lo trovi in **Magazzino → Inventari**.

## L'elenco

Come quello dei carichi: **Numero**, **Data**, **Sede** (con più sedi),
**Righe**, **Nota** e **Chi**, più due colonne sue: **Contate** (le righe con
un numero scritto) e **Con differenza** (le righe contate che non tornano). I
filtri sono *Stato*, *Periodo* e *Sede*.

## Fare un inventario

1. **Nuovo inventario**.
2. In alto **Sede** (con più sedi) e **Nota**: per esempio quale scaffale o
   reparto hai contato.
3. Aggiungi le versioni da contare, in due modi:
   - una per una dalla casella **Cerca o scansiona**, come in un carico;
     con il lettore ogni lettura aggiunge un pezzo alla riga;
   - tutte insieme con **Aggiungi versioni**: scegli *Tutte le versioni*,
     una **categoria** (con le sue sottocategorie) o un **marchio**. Entrano
     le versioni attive e quelle spente che hanno ancora pezzi nella sede;
     i multiprodotti no. Le righe che ci sono già restano come sono.
4. Scrivi i **Contati** di ogni riga. Accanto vedi gli **Attesi** (la
   giacenza della sede) e la **Differenza**: in verde i pezzi in più, in
   rosso quelli in meno.
5. **Salva bozza** quando ti fermi, **Conferma inventario** quando hai
   finito. La conferma riassume il lavoro: quante righe hai contato, quante
   hanno una differenza e di quanti pezzi è la correzione.

Una riga **lasciata vuota** non è uno zero: non è stata contata e non muove
niente. Uno zero scritto, invece, vuol dire «non ce n'è nessuno».

Confermato, l'inventario prende numero e data, e nei
[Movimenti](magazzino-movimenti.md) compare un movimento con causale
*Inventario* per ogni riga contata con una differenza. Le righe che tornano
non muovono niente.

## Gli attesi

Gli attesi sono la giacenza nel momento in cui hai scritto il numero. Se
mentre conti il negozio vende un pezzo di una riga già contata, la
correzione resta quella giusta: la vendita ha già tolto il suo pezzo, e
l'inventario sistema solo la differenza che avevi trovato tu.

Se riscrivi un numero, o cambi la sede, gli attesi di quella riga ripartono
dalla giacenza di adesso.

## Un magazzino grande

Un inventario tiene al massimo **500 righe**. Se *Aggiungi versioni*
ne porterebbe di più, te lo dice e non aggiunge niente: conta per
**categorie** o per **marchi**, un inventario per ognuna. È anche il modo più
comodo di lavorare: uno scaffale, un documento.

## Dopo la conferma

Si apre in sola lettura con Attesi, Contati e Differenza riga per riga, e in
fondo il totale («40 righe · 12 contate · 3 con differenza»). Come per i
carichi:

- **Annulla** rimette la giacenza com'era prima della conferma, con uno
  storno per ogni movimento;
- **Duplica** crea una bozza con le stesse righe e gli stessi contati, ma
  con gli attesi di adesso: ricontrolla i numeri prima di confermarla.

Una **bozza** si modifica quante volte vuoi e si elimina dai tre puntini.

Con i [lotti](magazzino-lotti.md) l'inventario conta la versione, non i
lotti: i pezzi che mancano escono prima dalla merce senza lotto e poi dai
lotti, dalla scadenza più vicina, scaduti compresi; quelli in più entrano senza
lotto.

## Inventario o scheda del prodotto?

Per correggere un numero o due basta la colonna **Giacenza** nella
[scheda del prodotto](magazzino-giacenze.md#dalla-scheda-del-prodotto-il-caso-normale).
L'inventario serve quando conti tanti articoli insieme e vuoi un documento
che lo ricordi.

## Da telefono

Le righe si leggono una sotto l'altra con i soli Contati; Attesi e
Differenza compaiono da tablet in su. La casella **Cerca o scansiona** prende
i codici di un lettore Bluetooth.
