---
icon: truck-ramp-box
---

# Carichi e scarichi

> **Inclusa.** Fa parte del gestionale, non c'è niente da attivare.

Un **carico** registra la merce che entra: un corriere che consegna, un
fornitore che porta il riassortimento. Uno **scarico** quella che esce senza
essere venduta: pezzi rotti, scaduti, regalati, usati in negozio. Tutti e due
sono documenti con le loro righe, uno per ogni versione che si muove, e
toccano la giacenza solo quando li **confermi**.

Li trovi in **Magazzino → Carichi** e **Magazzino → Scarichi**.

## L'elenco

Ogni riga è un documento: **Numero**, **Data**, **Sede** (con più sedi),
**Causale** (negli scarichi), **Righe**, **Pezzi**, **Nota** e **Chi** l'ha
fatto. In alto i filtri *Stato* (*Bozze*, *Confermati*, *Annullati*),
*Periodo*, *Sede* e, negli scarichi, *Causale*.

Una bozza non ha ancora numero e la sua data è quella in cui l'hai creata:
numero e data del documento arrivano con la conferma. I tre
puntini in fondo alla riga aprono il documento (*Modifica* per una bozza,
*Visualizza* per gli altri), lo duplicano o, solo per una bozza, lo
eliminano.

## Fare un carico

1. **Nuovo carico**.
2. In alto **Sede** (con più sedi; parte dalla principale) e **Nota**: chi ha
   consegnato, il numero della bolla, quello che fra sei mesi ti servirà
   sapere.
3. Nella casella **Cerca o scansiona** scrivi il nome, lo SKU o l'EAN della
   versione: compaiono fino a 20 risultati con foto e giacenza nella sede
   scelta. Un clic la aggiunge alle righe. I multiprodotti non ci sono: la
   loro giacenza è quella dei pezzi che li compongono.
4. Scrivi i **pezzi** di ogni riga: una riga nuova parte da 1. Una versione
   sta una volta sola: se la clicchi di nuovo nei risultati, il cursore va
   sui pezzi della riga che c'è già, da correggere a mano. Solo il lettore
   di codici a barre aggiunge da sé il pezzo in più (vedi sotto).
5. **Salva bozza** se devi interrompere, **Conferma carico** quando hai
   finito. La conferma chiede «{pezzi} pezzi in {righe} righe entrano in
   magazzino. Dopo non si modifica più: si può solo annullare.».

Confermato, il carico prende numero e data, e nei
[Movimenti](magazzino-movimenti.md) compare una riga per versione.

Con *Acquisti* attiva il carico ha anche **Fornitore**, il suo documento e il
**costo** di ogni riga: vedi [Acquisti e valore del
magazzino](magazzino-acquisti.md).

Con *Lotti e scadenze* attiva, le righe degli articoli che gestiscono i lotti
hanno anche **Lotto** e **Scadenza**, e il bottone **+ lotto** per un secondo
lotto dello stesso prodotto: vedi [Lotti e scadenze](magazzino-lotti.md).

## Con il lettore di codici a barre

Il lettore scrive il codice nella casella **Cerca o scansiona** e preme
Invio da solo. Con un codice che corrisponde **esattamente** allo SKU o
all'EAN di una versione sola, la riga si aggiunge (o prende un pezzo in più)
e la casella si svuota subito per il codice dopo. Così si carica un cartone
passando i pezzi uno a uno, anche in fretta: ogni lettura conta.

Invio non sceglie mai a caso:

- **Più versioni con questo codice: scegline una** — due versioni hanno lo
  stesso codice; clicca quella giusta.
- **Nessuna versione con questo codice: …** — il codice letto, scritto dopo
  i due punti, non è di nessuna versione: controllalo, o scrivilo nella
  scheda della versione.

## Fare uno scarico

Come il carico, da **Nuovo scarico**, con in più la **Causale**:
*Danneggiato*, *Scaduto*, *Regalo*, *Uso interno*, *Altro*. Si può lasciare vuota
nella bozza, ma serve per confermare.

Uno scarico che porterebbe una versione **sotto zero** non si conferma: il
messaggio dice quale versione, e la bozza resta com'era, con le modifiche
salvate. Correggi i pezzi, o fai prima il carico che manca. Fanno eccezione
gli articoli con **Vendita senza giacenza** accesa (vedi [Sotto
zero](magazzino-giacenze.md#sotto-zero)).

Con *Lotti e scadenze* ogni riga di un articolo con i lotti ha la tendina
**Lotto** (i lotti scaduti in rosso), e con *Acquisti* ogni riga ha la tendina
**Fornitore**. Lasciate su *automatico*, la merce esce dal lotto che scade
prima e dal fornitore che costa meno; i lotti scaduti si scaricano solo
scegliendoli.

## Un documento confermato

Si apre in sola lettura: righe, numero, data, chi l'ha fatto. Non si
modifica più. Se è sbagliato:

- **Annulla** lo annulla: ogni suo movimento ne ha uno uguale e contrario,
  lo **storno**, e la giacenza torna com'era. Il documento resta, annullato,
  e nei Movimenti si leggono tutte e due le righe: *Carico · 2026/100003* e
  *Annullamento del carico · 2026/100003*. Un carico la cui merce è già
  stata venduta non si annulla se la giacenza andrebbe sotto zero.
- **Duplica** crea una bozza nuova con le stesse righe, da correggere e
  confermare. È anche il modo veloce per rifare un carico che arriva uguale
  ogni mese.

Una **bozza** invece si modifica quante volte vuoi e si elimina dai tre
puntini: non ha ancora mosso niente.

## Il primo carico

Appena installato il gestionale, i pezzi che hai si caricano con un
**carico**: uno per sede, con la nota «Giacenza di partenza». Con il
lettore fai prima: passi gli articoli scaffale per scaffale, e se un pezzo
ha già la sua riga il numero sale da solo.

## Da telefono

La bozza funziona anche sul telefono: le righe si leggono una sotto l'altra
e la casella **Cerca o scansiona** prende i codici di un lettore Bluetooth.
