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

*Vendite → Ordini*. Ogni riga ha **Numero**, due icone senza titolo di colonna
(il **canale**: sito, ufficio o cassa; la **consegna**: spedizione o ritiro in
negozio; passa sopra con il mouse per leggerle), **Cliente**, **Totale**,
**Data** e tre etichette
colorate: **Ordine**, **Pagamento**, **Evasione**. Cliccando il numero si apre
la scheda.

In alto puoi filtrare per **Ordine**, **Pagamento**, **Evasione** e **Periodo**,
e cercare per numero, nome, ragione sociale o email del cliente.
Nell'elenco ci sono solo gli ordini veri: i carrelli ancora aperti non
compaiono. Con la **Vendita in ufficio** ci sono anche le bozze, con *Bozza* al
posto del numero: si aprono e si confermano dalla loro pagina (vedi
[Ordini da ufficio](vendite-ordini-da-ufficio.md)).

## La scheda

Il numero sta nel titolo; sotto, in piccolo, *Creato il* con data e ora e il
canale (*Online*, *Ufficio*, *Cassa*). La prima riga della scheda sono i tre
stati: **Ordine**, **Pagamento** e come arriva la merce — **Spedizione**
(*Da spedire*, *Spedito in parte*, *Spedito*), **Ritiro** (*Da preparare*,
*Pronto per il ritiro*, *Ritirato*) o **Consegna** per un ordine senza nessuna
delle due. Poi il **cliente** (un link: apre la sua scheda, se è in
anagrafica), gli indirizzi di **Fatturazione** e di spedizione, il metodo di
pagamento e le note. A destra i **Totali** e il **Riepilogo IVA**. Sotto, i
riquadri a tendina:

* **Righe** — cosa ha comprato, con quantità e prezzi (aperto di default);
* **Pagamenti** — il denaro arrivato o in arrivo ([vedi Pagamenti](vendite-pagamenti.md));
* **Resi** — la merce tornata indietro, solo con i resi attivi ([vedi Resi](vendite-resi.md));
* **Spedizioni** — i pacchi e i ritiri, con le spedizioni attive ([vedi Spedire](spedizioni-spedire.md));
* **Storico** — ogni cambio di stato, con chi o cosa l'ha fatto.

### Il menu ⋯ delle tabelle

In fondo a una riga delle tabelle c'è un menu **⋯** quando su quella riga si
può fare qualcosa. Ogni voce apre una finestra che dice cosa farà:

* **Righe** — *Annulla riga*, per un ordine da ufficio confermato: la
  quantità parte da tutto quello che non è ancora partito; scrivine meno per
  annullarne una parte. La merce torna in magazzino, totali e rate si
  rifanno ([vedi Ordini da ufficio](vendite-ordini-da-ufficio.md)).
* **Pagamenti** — *Registra pagamento* su una rata in attesa, con l'importo
  della rata. Per un pagamento libero c'è **Registra pagamento** in alto,
  fra le azioni della scheda.
* **Resi** — *Chiudi* e *Annulla* un reso ricevuto.
* **Spedizioni** — le azioni della spedizione: *Segna spedita*, *Segna
  consegnata*, *Pronto per il ritiro*, *Ritirato*, *Modifica spedizione* e
  *Annulla spedizione*.

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

In alto a destra, da sinistra a destra:

1. il menu **Documenti**: i PDF e i documenti dell'ordine;
2. i pulsanti che **portano avanti** l'ordine, sempre a vista;
3. il menu **Altro**: *Registra reso* e *Annulla*.

Compaiono solo i pulsanti che valgono per lo stato dell'ordine. Quando
*Documenti* ha una voce sola resta un pulsante.

In **Documenti**:

* **Proforma** — righe, totali, IVA e come pagare (metodo, rate, IBAN), con la
  scritta «non valido ai fini fiscali». È anche la conferma d'ordine da
  mandare al cliente. C'è per ogni ordine, dal sito o dall'ufficio, **anche in
  bozza**; non per un ordine annullato. Si fa al momento, con l'ordine com'è:
  dopo una modifica è già aggiornato. Il cliente lo scarica anche dalla sua
  area del sito, a ordine confermato.
* **Crea DDT** — con i DDT attivi, finché resta merce da mettere in un DDT
  ([vedi DDT](vendite-ddt.md)).

Ogni pulsante e ogni voce apre una finestra che dice **prima** cosa farà.

| Pulsante | Dove | Quando | Cosa fa |
|---|---|---|---|
| **Conferma** | a vista | ordine in attesa | Conferma l'ordine e **scarica** i pezzi dal magazzino. Non registra denaro: l'incasso si scrive a parte con *Registra pagamento*. |
| **Segna evaso** | a vista | confermato, non ancora evaso, con le spedizioni spente | Segna l'ordine come evaso. Il magazzino **non cambia**: la merce era già uscita alla conferma. |
| **Crea spedizione** | a vista | con le spedizioni attive, finché resta merce da spedire | Crea un pacco con le quantità da spedire. |
| **Pronto per il ritiro** / **Ritirato** | a vista | ritiro in negozio, con le spedizioni attive | Segna il ritiro pronto (e avvisa il cliente), poi ritirato. |
| **Registra pagamento** | a vista | c'è ancora da incassare | Apre la finestra del pagamento ([vedi Pagamenti](vendite-pagamenti.md)). |
| **Registra reso** | Altro | ordine confermato o chiuso, con i resi attivi | Porta alla pagina del reso ([vedi Resi](vendite-resi.md)). |
| **Annulla** | Altro | in attesa o confermato | Annulla l'ordine. Se era in attesa **libera** i pezzi prenotati; se era confermato li **rimette in magazzino**, tranne quelli già resi con un reso (sono già rientrati, o erano rotti). |

Una **bozza da ufficio** ha *Proforma* e **Modifica bozza**, che apre la sua
pagina: lì si completa, si conferma o si elimina.

> **Il denaro già incassato non si rimborsa da qui.** Se annulli un ordine
> pagato, la finestra ti ricorda quanto c'è da restituire: il rimborso lo
> fai dal gestore dei pagamenti (la carta) o dal tuo conto (il bonifico).

Un'azione ripetuta per sbaglio (due clic, la pagina aperta due volte) non fa
danni: la seconda volta non scarica né rimette niente.

## Il checkout del sito

Mentre il cliente compila, il riepilogo a lato si aggiorna: spese di spedizione,
commissione del pagamento e sconto del coupon. Se c'è un solo metodo di spedizione
per l'indirizzo, o una sola sede di ritiro, viene scelto da solo. Un coupon che non
vale più, o un metodo che non arriva al nuovo indirizzo, sparisce con un avviso.

All'invio l'ordine si ferma se il ritiro è in una sede che non è (più) di ritiro o
se la spedizione non ha un metodo che arrivi all'indirizzo. Gli ordini di soli
servizi e quelli con le spedizioni spente non chiedono il metodo.
