---
icon: file-contract
---

# DDT

> **Da attivare.** Funzionalità **DDT**. Finché è spenta non vedi né i DDT né
> il pulsante *Crea DDT*.

## A cosa serve

Il **documento di trasporto** accompagna la merce dal magazzino al cliente:
dice cosa parte, quanto, da quale lotto, chi la porta e dove. Non muove il
magazzino, che è già uscito alla conferma dell'ordine; serve a consegnare e,
domani, alla fattura differita.

## Crearlo

Dalla scheda di un ordine confermato premi **Crea DDT**. Nasce una **bozza**
con le righe dell'ordine non ancora in un DDT emesso:

* una riga per **lotto**: se un articolo è uscito da due lotti, sono due righe;
* le quantità si possono **abbassare** per consegnare una parte; zero toglie la
  riga. Il resto lo metti in un altro DDT, sempre con *Crea DDT*.

Il pulsante compare finché c'è qualcosa da consegnare, anche a ordine già
chiuso.

## La bozza

* **Luogo di consegna** — parte dall'ordine, si può cambiare.
* **Trasporto** — *a cura del* mittente, del destinatario o del vettore; il
  **vettore** dai tuoi corrieri; **inizio trasporto** (vuoto = il momento
  dell'emissione); **aspetto dei beni**, **colli**, **peso lordo** e **netto**,
  **porto** (franco o assegnato); **note** stampate.

I valori di partenza si scelgono in *Impostazioni → Vendite → DDT*.

**Salva bozza** salva; **Elimina bozza** la toglie (le righe tornano da
consegnare).

## Emettere

**Emetti** dà al DDT il **numero** e la **data**, crea il **PDF** e non si
modifica più. L'ordine risulta **evaso** per quelle righe: *Evaso* se c'è
tutto, *Evaso in parte* se manca qualcosa. Il PDF non porta prezzi: righe con
codice, lotto e quantità, causale, trasporto e spazio per le firme.

Dopo l'emissione:

* **PDF** — lo apri o lo stampi;
* **Invia per email** — il cliente lo riceve allegato;
* **Annulla DDT** — il DDT resta, annullato, con il suo numero; le righe
  tornano da consegnare e l'evasione torna indietro.

## DDT e spedizioni insieme

Un DDT e una spedizione di solito sono **la stessa merce**: il DDT accompagna il
pacco. Per questo una riga conta come evasa per il **più grande** dei due, non
per la somma, e una merce già in un DDT emesso non si rispedisce.

> **Attenzione.** Se per lo stesso ordine una parte parte con un DDT senza
> spedizione e un'altra con una spedizione senza DDT, l'evasione conta meno del
> vero. Per un ordine usa un tipo di documento solo.

*Annulla righe* di un ordine da ufficio non tocca quello che è già in un DDT
emesso. Prima di annullare un ordine intero, annulla i suoi DDT emessi: da
soli restano emessi.

## L'elenco

*Vendite → DDT*: numero, data, destinatario, ordine e stato, con il filtro per
stato e la ricerca per numero e cliente.
