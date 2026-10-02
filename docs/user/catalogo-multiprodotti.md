---
icon: box-open
---

# Multiprodotti

> **Da attivare.** Funzionalità *Multiprodotto*. Finché è spenta non vedi il
> tipo nella scheda dell'articolo; i multiprodotti già creati e gli ordini già
> fatti restano come sono.

## A cosa servono

Un multiprodotto è **un articolo che ne contiene altri**: un cesto
degustazione, un kit, una confezione regalo. Il cliente ne compra uno solo, a
un prezzo solo; il magazzino invece scarica **i prodotti che ci stanno
dentro**. Il multiprodotto in sé non ha giacenza: quanti ne puoi vendere lo
dicono i suoi componenti.

Si crea dalla scheda dell'articolo, scegliendo **Multiprodotto** al posto di
*Articolo singolo*. Il prezzo lo scrivi tu, come per ogni articolo.

## Fissa, a scelta o mista

| Come si compone | Cosa compra il cliente |
|---|---|
| **Fissa** | sempre gli stessi componenti, ognuno con la sua quantità (il cesto con una maglietta, due paia di calzini e una felpa) |
| **A scelta del cliente** | niente di fisso: sceglie lui, **gruppo per gruppo** |
| **Fissa e a scelta** | una base fissa più uno o più gruppi da scegliere |

Un **gruppo** ha un nome («Vino», «Dolce»), un numero minimo e un massimo di
scelte e le sue **opzioni**: ogni opzione è un prodotto, con un eventuale
**sovrapprezzo** che il cliente paga in più se la sceglie. Con minimo 1 il
gruppo è obbligatorio; con minimo 0 si può lasciare vuoto. Le opzioni si
aprono dal pulsante *Opzioni* della riga del gruppo.

Il sovrapprezzo non può essere negativo, e lo stesso prodotto non può comparire
due volte nello stesso elenco. Un multiprodotto **non può contenerne un altro**.

## Il valore dei componenti

Con **Mostra il valore dei componenti** acceso, la scheda dice anche quanto
varrebbero i componenti comprati uno per uno: è il «risparmi» del cesto. Per i
gruppi si conta il minimo che il cliente deve scegliere, con le opzioni meno
care.

## Quanti se ne possono vendere

Lo calcola il gestionale dai componenti: il multiprodotto è disponibile quanto
lo permette il suo **componente più scarso**. Se un componente è esaurito o
spento, il multiprodotto non si vende finché non torna disponibile. Non
compare nelle giacenze, nei movimenti e negli avvisi di scorta: lì ci sono
solo i prodotti veri.

## Perché un componente non si elimina

Un prodotto usato da un multiprodotto, come componente fisso o come opzione,
**non si elimina e non si spegne**: la confezione resterebbe senza un pezzo. Il
gestionale rifiuta e ti dice **in quali multiprodotti** è usato. Toglilo prima
da lì, oppure cambia il multiprodotto.

Eliminare il multiprodotto, invece, porta via anche la sua composizione.

## Cosa vede il cliente

Nel carrello, nell'ordine e nelle email la confezione è **una riga sola, col
suo prezzo**, e sotto ci sono i componenti, rientrati e senza prezzo. Le
parti scelte dal cliente sono segnate con **«Scelta:»** e il nome del prodotto.
Se l'anagrafica cambia dopo che il cliente ha riempito il carrello (un
componente spento, un'opzione tolta), la riga **esce dal carrello** e il
cliente viene avvisato; gli **ordini già fatti non cambiano**.

## Come si rende una confezione

Dalla scheda dell'ordine, **Registra reso** ([Resi](vendite-resi.md)). Una
confezione si rende **intera**: c'è una riga per la confezione, con la quantità
resa e il motivo, e **una riga per ogni componente**, ciascuna con la sua
spunta *Rientra a magazzino*. Così un cesto tornato con un pezzo rotto rimette
a scaffale gli altri e lascia fuori quello.

Come per ogni reso, la spunta parte spenta per *Danneggiato* e *Difettoso* e
accesa per gli altri motivi, e si può cambiare. Il rimborso del prezzo del cesto
resta a te, fuori dal gestionale.

## I multiprodotti di prova

`php forge gestionale:demo` crea tre cesti, uno per modo di comporsi (*Cesto
degustazione*, *Cesto componibile*, *Cesto completo*) e, con la funzionalità
accesa, quattro ordini che li usano, uno dei quali reso con un componente che non
rientra.
