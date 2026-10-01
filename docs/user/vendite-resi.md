---
icon: arrow-return-left
---

# Resi

> **Da attivare.** Serve la funzionalità **Resi**, che richiede **Ordini**.

## A cosa serve

Un cliente riporta della merce. Il reso la scrive sull'ordine e, se è il caso,
la **rimette in giacenza**, con un movimento di tipo *Reso* che resta nello
storico del magazzino.

## Quando compare *Registra reso*

Solo su un ordine **confermato, in lavorazione o completato**. Non compare su
un ordine in attesa o annullato. Se ogni prodotto è già stato reso per intero,
la pagina te lo dice e non c'è niente da compilare.

## Come si compila

Dalla scheda dell'ordine, **Registra reso**. La pagina elenca i prodotti
dell'ordine con **Ordinati** e **Già resi**. Scrivi la **Quantità** solo sulle
righe che il cliente ha restituito: le righe vuote non si rendono.

| Campo | Cosa fa |
|---|---|
| **Quantità** | Quanti pezzi. Al massimo l'ordinato meno il già reso. |
| **Motivo** | *Danneggiato*, *Difettoso*, *Articolo sbagliato*, *Diverso dalla descrizione*, *Ripensamento*, *Taglia sbagliata*, *Altro*. |
| **Rientra a magazzino** | Rimette i pezzi in giacenza. |
| **Sede in cui rientra la merce** | Solo se hai più sedi. |
| **Nota interna** | Per te, non la vede il cliente. |

La spunta **Rientra a magazzino** si regola da sola col motivo: parte **spenta**
per *Danneggiato* e *Difettoso* (merce rotta, di solito non si rivende), accesa
per gli altri. Puoi sempre cambiarla.

Premendo **Salva** il reso nasce subito come **Ricevuto**, e ti dice quanti
pezzi sono rientrati.

## Cosa succede in magazzino

Per ogni riga con la spunta accesa la giacenza **sale** nella sede scelta e
nei **Movimenti** compare una riga *Reso* con il numero del reso, cliccabile.
Le righe senza spunta non toccano la giacenza: restano scritte sul reso, e
basta.

## Chiudere e annullare

Nel riquadro **Resi** della scheda ogni reso ha **Numero**, **Stato**, **Data**,
**Righe** e **Azioni**. Un reso *Ricevuto* ha due pulsanti:

* **Chiudi** — hai finito con quella pratica: il reso passa a *Completato*.
* **Annulla** — l'hai registrato per sbaglio. Si può **solo se nessuna riga è
  rientrata a magazzino**; altrimenti ti rimanda alla **rettifica** in
  *Magazzino*, che corregge la giacenza lasciando scritta la storia.

Un reso chiuso o annullato non si modifica più.

## Il rimborso non parte da qui

Registrare un reso **non restituisce denaro** e non cambia lo stato di
pagamento dell'ordine. Il rimborso lo fai dal gestore dei pagamenti o dal tuo
conto: dal reso non parte niente.
