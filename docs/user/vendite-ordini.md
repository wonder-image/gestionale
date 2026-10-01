---
icon: receipt
---

# Ordini

> **Da attivare.** Serve la funzionalità **Ordini**, in *Funzionalità*.
> Con quella accesa compare il menu **Vendite**.

## A cosa serve

Qui vedi gli ordini dei clienti, dal momento in cui li fanno al momento in cui
la merce parte. L'elenco si **consulta**; ogni cambiamento (confermare,
evadere, annullare) passa dai pulsanti della scheda, così il magazzino e lo
storico restano sempre coerenti con lo stato.

## L'elenco

*Vendite → Ordini*. Ogni riga ha **Numero**, **Cliente**, **Totale**, **Data** e
tre etichette colorate: **Ordine**, **Pagamento**, **Evasione**. Cliccando il
numero si apre la scheda.

In alto puoi filtrare per **Ordine**, **Pagamento**, **Evasione** e **Periodo**,
e cercare per numero, nome, ragione sociale o email del cliente.
Nell'elenco ci sono solo gli ordini veri: i carrelli ancora aperti non
compaiono.

## La scheda

Mostra, in alto, numero, data, canale (*Online*, *Ufficio*, *Cassa*), i tre
stati, il **cliente** (un link: apre la sua scheda, se è in anagrafica),
gli indirizzi di **Fatturazione** e di consegna, il metodo di pagamento e le
note. A destra i **Totali** e il **Riepilogo IVA**. Sotto, quattro riquadri a
tendina:

* **Righe** — cosa ha comprato, con quantità e prezzi (aperto di default);
* **Pagamenti** — il denaro arrivato o in arrivo ([vedi Pagamenti](vendite-pagamenti.md));
* **Resi** — la merce tornata indietro, solo con i resi attivi ([vedi Resi](vendite-resi.md));
* **Storico** — ogni cambio di stato, con chi o cosa l'ha fatto.

### Le note

Sono tre: **Nota del cliente** (la scrive lui, non si modifica), **Nota
interna** e **Nota sul documento**. Per le ultime due c'è una matita. Quando
l'ordine è **evaso e pagato** al posto della matita compare un lucchetto: le
note non si modificano più.

## Gli stati

| Stato dell'ordine | Cosa significa |
|---|---|
| **In attesa** | L'ordine c'è, ma non è ancora confermato: la merce è **prenotata**, non uscita. |
| **Confermato** | La merce è **uscita dal magazzino**. Il denaro può essere già arrivato, in arrivo o da incassare alla consegna. |
| **In lavorazione** | Come confermato, ma già in preparazione. |
| **Completato** | Evaso e pagato: l'ordine è chiuso. |
| **Annullato** | L'ordine non vale più. |

Il **pagamento** dice a che punto è il denaro (*Da pagare*, *In attesa*, *Pagato
in parte*, *Pagato*, *Rimborsato in parte*, *Rimborsato*); l'**evasione** dice
se è partito (*Da evadere*, *Pronto per il ritiro*, *Evaso in parte*, *Evaso*).
Un ordine diventa **Completato** da solo quando è sia pagato sia evaso, in
qualunque ordine arrivino le due cose.

## I pulsanti

In testa alla scheda compaiono solo quelli che valgono per lo stato in cui
si trova l'ordine. Ognuno apre una finestra che dice **prima** cosa farà.

| Pulsante | Quando | Cosa fa |
|---|---|---|
| **Conferma** | ordine in attesa | Conferma l'ordine e **scarica** i pezzi dal magazzino. Non registra denaro: l'incasso si scrive a parte con *Registra pagamento*. |
| **Segna evaso** | confermato, non ancora evaso | Segna l'ordine come evaso. Il magazzino **non cambia**: la merce era già uscita alla conferma. |
| **Registra pagamento** | c'è ancora da incassare | Apre la finestra del pagamento ([vedi Pagamenti](vendite-pagamenti.md)). |
| **Registra reso** | ordine confermato o chiuso, con i resi attivi | Porta alla pagina del reso ([vedi Resi](vendite-resi.md)). |
| **Annulla** | in attesa o confermato | Annulla l'ordine. Se era in attesa **libera** i pezzi prenotati; se era confermato li **rimette in magazzino**, tranne quelli già resi con un reso (sono già rientrati, o erano rotti). |

> **Il denaro già incassato non si rimborsa da qui.** Se annulli un ordine
> pagato, la finestra ti ricorda quanto c'è da restituire: il rimborso lo
> fai dal gestore dei pagamenti (la carta) o dal tuo conto (il bonifico).

Un'azione ripetuta per sbaglio (due clic, la pagina aperta due volte) non fa
danni: la seconda volta non scarica né rimette niente.
