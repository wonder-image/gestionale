---
icon: right-left
---

# Trasferimenti

> **Da attivare.** Funzionalità *Più sedi*, con almeno due sedi che tengono la
> giacenza. Finché è spenta il magazzino è una sede sola.

## A cosa serve

Spostare la merce da una sede all'altra: dal magazzino al negozio, da un
negozio all'altro, dal negozio di nuovo al magazzino. Il trasferimento ricorda
cosa è partito, quando e chi l'ha ricevuto.

La pagina è **Magazzino → Trasferimenti**.

## In due passi

1. **Invia.** La merce esce dalla sede di partenza e resta **in viaggio**: non
   è più lì, non è ancora dall'altra parte.
2. **Ricevi.** Quando arriva, la merce entra tutta nella sede d'arrivo.

Così, se il furgone parte oggi e arriva domani, i numeri delle due sedi dicono
sempre la verità.

## Fare un trasferimento

1. **Nuovo trasferimento**.
2. In alto scegli **Da** (la sede di partenza) e **A** (quella d'arrivo): devono
   essere due sedi diverse.
3. Aggiungi le righe come in un carico: cerca il prodotto o passalo con il
   lettore di codici a barre, e scrivi i pezzi. La colonna *Giacenza* è quella
   della sede di partenza.
4. **Salva bozza** se devi finire dopo, oppure **Invia**.

All'invio il gestionale controlla che i pezzi ci siano nella sede di partenza.
Se mancano, il trasferimento non parte e la bozza resta com'era: i prodotti
che si vendono senza giacenza non fanno eccezione, perché non si può spedire
merce che non c'è. Non conta invece se una parte è prenotata da un ordine:
come per lo scarico, la merce si sposta lo stesso.

Con *Lotti e scadenze* puoi scegliere il **lotto** di ogni riga (lasciato
vuoto parte prima quello che scade prima); con *Acquisti* il **fornitore**.
Lotto e fornitore arrivano uguali nella sede d'arrivo.

## Ricevere

Apri il trasferimento in viaggio e premi **Ricevi**: tutta la merce entra
nella sede d'arrivo. Se è arrivata solo una parte, ricevi tutto e fai un
trasferimento all'indietro (o uno scarico) per quello che manca.

## Annullare

Dalla scheda, **Annulla**:

- **in viaggio**: la merce torna nella sede di partenza, come se non fosse mai
  partita;
- **ricevuto**: la merce esce dalla sede d'arrivo e torna in quella di
  partenza. Se nella sede d'arrivo una parte è già stata venduta o scaricata,
  il trasferimento non si annulla: riporta la merce con un trasferimento
  nuovo.

Un trasferimento annullato resta nell'elenco, con il segno *Annullato*.

## Dove si vede

- **Trasferimenti**: numero, data, *Da*, *A*, righe, pezzi e stato (*Bozza*,
  *In viaggio*, *Ricevuto*, *Annullato*). Il filtro *Sede* trova i
  trasferimenti che partono o arrivano in quella sede.
- **Giacenze**: la colonna **In viaggio**, con i pezzi partiti e non ancora
  ricevuti. Non sono in nessuna sede: non entrano nel totale e non si vendono.
- **Movimenti**: *Trasferimento in uscita* nella sede di partenza e
  *Trasferimento in entrata* in quella d'arrivo; dal menu ⋯ *Visualizza
  trasferimento*.

## Spegnere *Più sedi*

Finché ci sono pezzi in una sede che non è la principale, o trasferimenti in
viaggio, *Più sedi* non si spegne: il pannello delle funzionalità dice dove
sono. Riporta la merce nella principale con un trasferimento e ricevi quelli in
viaggio, poi riprova.
