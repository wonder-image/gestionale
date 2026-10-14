---
icon: calendar-check
---

# Pagamenti a rate e scadenzario

> **Da attivare.** Le rate e lo scadenzario arrivano con **Vendita in
> ufficio**. Le *Condizioni di pagamento* in Set Up ci sono sempre.

## A cosa serve

Un cliente d'ufficio non sempre paga tutto subito: «bonifico a 30 giorni fine
mese», «RiBa 30/60». La **condizione di pagamento** dice in quante rate e
quando; alla conferma dell'ordine ogni rata diventa un pagamento **in attesa**
con la sua **scadenza**. Lo **Scadenzario** le mette in fila, così sai chi deve
pagare e quando.

## Le condizioni di pagamento

Si configurano in *Set Up → Pagamenti → Condizioni di pagamento*. Ne trovi già
tre:

| Condizione | Rate |
|---|---|
| **Rimessa diretta** | una, subito |
| **Bonifico 30 gg fine mese** | una, a 30 giorni fine mese |
| **RiBa 30/60 gg fine mese** | due da 50%, a 30 e 60 giorni fine mese |

Per ognuna scrivi:

* **Nome** — come lo diresti al cliente.
* **Condizione** — quello che chiede la fattura elettronica: *Pagamento
  completo*, *Pagamento a rate* o *Anticipo*.
* **Rate** — una riga per rata, con **Giorni**, **Fine mese** e
  **Percentuale**. Le percentuali devono fare 100, altrimenti la condizione non
  si salva. I giorni vanno da 0 a 365.

I giorni partono dalla **conferma** dell'ordine. Con **Fine mese** la scadenza
va all'ultimo giorno di quel mese: «30 giorni fine mese» da un ordine del 10
ottobre scade il 30 novembre. Gli importi si arrotondano al centesimo e
l'**ultima rata prende i centesimi** che avanzano: la somma torna sempre.

Una condizione usata da un ordine o da un cliente non si elimina: mettila
**Non attiva** e non si propone più.

## Sulla scheda del cliente

Nel riquadro **Pagamento** della scheda scegli il **metodo** e la
**condizione** che l'ordine da ufficio propone per quel cliente. La condizione
compare solo se ne hai più di una attiva.

## Lo scadenzario

*Vendite → Scadenzario* elenca le rate, dalla scadenza più vicina:
**Scadenza**, **Cliente**, **Ordine**, **Rata** («Rata 1 di 2»), **Importo**,
**Metodo** e **Stato**. Le scadute sono in **rosso**, quelle in scadenza in
**giallo**.

Il filtro **Stato** mostra *Tutte in attesa* (si parte da qui), *Scadute*, *In
scadenza* e *Pagate (90 giorni)*. La ricerca trova il cliente, il numero
d'ordine o la rata.

Quanti giorni prima una rata diventa «in scadenza» lo decidi in
*Impostazioni → Vendite → Scadenzario* (**Giorni di preavviso per le
scadenze**, predefinito 7). Si cambia anche sul sito in produzione.

## Registrare il pagamento di una rata

Dal menu della riga premi **Registra pagamento**: si apre la pagina
dell'incasso sull'ordine con la rata già scelta e il suo importo.

* **Importo uguale alla rata** — la rata è pagata.
* **Importo minore** — la rata è pagata per quanto è arrivato, e il **resto**
  resta in attesa con la **stessa scadenza**.
* **Importo maggiore** — la rata è pagata e quello in più **scala le rate
  successive**, dalla più vicina. Una rata che arriva a zero si annulla.
* Più di quanto resta da pagare non si registra.

Anche **Registra pagamento** dalla scheda dell'ordine funziona: con le rate in
attesa l'incasso chiude **prima la rata che scade prima**.

Quando tutte le rate sono pagate l'ordine è **Pagato**; prima è **Pagato in
parte** o **In attesa**.

## Il riquadro della home

**Pagamenti in scadenza** mostra le rate scadute o in scadenza, le prime dieci,
con il totale da incassare e quanto è già scaduto. Senza rate da guardare il
riquadro non compare.
