---
icon: journal-text
---

# Listini cliente

> **Da attivare.** Funzionalità *Listini cliente*. Finché è spenta non vedi la
> pagina, la scelta del listino sparisce dalle schede e tutti pagano i prezzi
> di catalogo; i listini già scritti restano.

## A cosa servono

Un listino è **un elenco di prezzi riservati** a uno o più clienti: un
rivenditore, un'azienda con cui hai un accordo. Il cliente che ce l'ha paga
quei prezzi negli ordini da ufficio, nei preventivi e, se entra con il suo
account, nel negozio online.

Si gestiscono in **Promozioni → Listini**.

## Il modulo

* **Nome** e **Valuta** (di solito EUR). Un ordine in un'altra valuta non usa
  il listino.
* **Prezzi**: *IVA inclusa* o *IVA esclusa*, cioè come scrivi i prezzi qui
  sotto. Il gestionale li porta da solo nella base dell'ordine, con l'aliquota
  di ogni prodotto.
* **Dal / Al**: estremi compresi; vuoti, il listino vale sempre. Scaduto o
  non ancora cominciato, il cliente paga i prezzi di catalogo.
* **Stato**: un listino non attivo non si applica.

## Le righe e gli scaglioni

Una riga per prodotto e scaglione: **Da quantità** è la quantità da cui vale
il prezzo. Per esempio:

| Prodotto | Da quantità | Prezzo |
|----------|-------------|--------|
| Maglia   | 1           | 9,00   |
| Maglia   | 10          | 8,00   |

Con 4 maglie si paga 9,00 l'una, con 12 si paga 8,00. Un prodotto che non è
nel listino si paga a prezzo di catalogo.

## Importare ed esportare

Dalla scheda del listino scarichi le righe in **Excel** o **CSV** (colonne
SKU, Prodotto, Quantità minima, Prezzo). Lo stesso file, modificato, si
ricarica nel campo **Importa righe**: al salvataggio **sostituisce tutte le
righe** del listino. Il prodotto lo riconosce dallo SKU; se una riga ha uno
SKU sconosciuto o un numero sbagliato, il file non entra e il messaggio dice
quale riga correggere.

## Assegnarlo a un cliente

Nella scheda del cliente, riquadro **Listino**. Un cliente ha un solo
listino; lo stesso listino può valere per tanti clienti.

## Negli ordini

* **Ordini da ufficio e preventivi**: la bozza nasce con il listino del
  cliente. Dopo il primo salvataggio, in *Pagamento e sconto*, lo puoi
  cambiare o togliere, e i prezzi si rifanno.
* **Ordine confermato**: i prezzi restano quelli del giorno della conferma,
  anche se poi cambi il listino.
* **Negozio online**: il cliente che ha fatto l'accesso vede nel carrello i
  prezzi del suo listino.

## Con campagne, coupon e sconti

* **Campagne**: il listino le sostituisce, non si sommano. Su un prodotto del
  listino vale il prezzo del listino; sugli altri la campagna resta.
* **Prezzo scontato del prodotto**: anche questo lo sostituisce il listino.
* **Coupon e sconto dell'ordine**: si aggiungono sopra il listino. Un coupon
  con *Escludi prodotti scontati* non tocca le righe a prezzo di listino.
* **Prezzo a mano** su una riga dell'ordine da ufficio: vince su tutto.
