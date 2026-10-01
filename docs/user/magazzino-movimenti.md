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
| **Tipo** | rettifica, vendita, reso, carico… Per le vendite e i resi dice anche il **documento**: *Vendita · 2026/0012*, cliccabile, apre l'ordine |
| **Causale** | il perché: inventario, danneggiato, regalo… |
| **Sede** | dove si è mossa la merce (solo se hai più sedi) |
| **Prima** | quanti pezzi c'erano prima del movimento |
| **Pezzi** | `+10` sono entrati, `−3` sono usciti |
| **Giacenza dopo** | quanti ne restavano dopo quel movimento |
| **Chi** | la persona che l'ha fatto; se l'ha fatto il sistema (un ordine, una scadenza) c'è scritto *Sistema* (o *Importazione*, per i dati caricati da file) |

## Non si modifica e non si cancella

Un movimento è storia: resta com'è. Se hai sbagliato una rettifica **ne fai
un'altra** che rimette a posto la quantità — così resta scritto anche
l'errore, ed è giusto che sia così.

## Trovare quello che cerchi

In alto ci sono i filtri per **tipo**, **causale**, **sede** (con più sedi) e
**periodo** — *Oggi*, *Ultimi 7 giorni*, *Questo mese*, *Mese scorso*, *Quest'anno* — e la
ricerca per codice del movimento, per nota o per **nome e codice dell'articolo**.

Dalla scheda di una versione il riquadro *Ultimi movimenti* mostra gli ultimi
dieci con le stesse colonne, e il pulsante *Vedi tutti i movimenti* apre questa
pagina già filtrata su quell'articolo.
