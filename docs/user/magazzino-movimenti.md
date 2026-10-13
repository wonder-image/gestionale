---
icon: arrow-left-right
---

# Movimenti

> **Inclusa.** Fa parte del gestionale, non c'è niente da attivare.

## A cosa serve

Ogni volta che la giacenza di un articolo cambia, il gestionale scrive una
riga: quanti pezzi, in che verso, con che causale, chi l'ha fatto e quando.
Questa pagina è l'elenco di quelle righe.

Serve a rispondere alla domanda che prima o poi arriva sempre: **"perché qui
c'è scritto 3?"**.

## Come si legge una riga

| Colonna | Cosa dice |
|---|---|
| **Quando** | data e ora |
| **Articolo** | la versione in vendita che si è mossa |
| **Tipo** | rettifica, vendita, reso, carico… Per le vendite e i resi dice anche il **documento**: *Vendita · 2026/0012*, cliccabile, apre l'ordine. Per carichi e scarichi il documento è nel testo, *Carico · 2026/100003*; gli storni di un documento annullato si leggono *Annullamento del carico · 2026/100003* |
| **Causale** | il perché: inventario, danneggiato, regalo… *Assegnazione lotto* la scrive il gestionale quando un carico copre pezzi venduti prima che arrivassero |
| **Sede** | dove si è mossa la merce (solo se hai più sedi) |
| **Fornitore** | il fornitore di quella merce, o niente se è entrata senza (solo con gli [acquisti](magazzino-acquisti.md)); c'è anche il filtro *Fornitore* |
| **Prima** | quanti pezzi c'erano prima del movimento |
| **Pezzi** | `+10` sono entrati, `−3` sono usciti |
| **Giacenza dopo** | quanti ne restavano dopo quel movimento |
| **Chi** | la persona che l'ha fatto; se l'ha fatto il sistema (un ordine, una scadenza) c'è scritto *Sistema* (o *Importazione*, per i dati caricati da file) |

## La scheda di un movimento

Dal menu **⋯** di una riga, **Visualizza** apre la scheda del movimento con
tutti i dettagli, anche quelli che nell'elenco non ci stanno: il **codice** del
movimento, il **costo unitario**, il **lotto** e il **fornitore** (quando ci
sono) e la **nota** per intero. Il nome della versione è un link alla sua
scheda.

Per i movimenti di un ordine, nello stesso menu c'è anche **Visualizza
ordine**; per quelli di un carico o di uno scarico **Visualizza carico** o
**Visualizza scarico**, anche sugli storni, che aprono il documento annullato.

## Non si modifica e non si cancella

Un movimento è storia: resta com'è. Se hai sbagliato una rettifica **ne fai
un'altra** che rimette a posto la quantità — così resta scritto anche
l'errore, ed è giusto che sia così.

## Trovare quello che cerchi

In alto ci sono i filtri per **tipo**, **causale**, **sede** (con più sedi) e
**periodo** — *Oggi*, *Ultimi 7 giorni*, *Questo mese*, *Mese scorso*, *Quest'anno* — e la
ricerca per codice del movimento, per nota o per **nome e codice dell'articolo**.
Con i [lotti](magazzino-lotti.md) c'è la colonna **Lotto** e la ricerca trova
anche il numero del lotto. I [trasferimenti](magazzino-trasferimenti.md) sono
due righe: *Trasferimento in uscita* nella sede di partenza e *in entrata* in
quella d'arrivo.

Dalla scheda di una versione il riquadro *Ultimi movimenti* mostra gli ultimi
dieci con le stesse colonne, e il pulsante *Vedi tutti i movimenti* apre questa
pagina già filtrata su quell'articolo.
