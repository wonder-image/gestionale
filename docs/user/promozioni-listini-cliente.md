---
icon: journal-text
---

# Listini cliente

> **Da attivare.** Funzionalità *Listini cliente*. Finché è spenta non vedi la
> pagina, la scelta del listino sparisce dalle schede e tutti pagano i prezzi
> di catalogo; i listini già scritti restano.

## A cosa servono

Un listino è **un elenco di prezzi riservati**. Può valere per uno o più
clienti (un rivenditore, un'azienda con cui hai un accordo) e per uno o più
**canali di vendita**: il sito, l'ufficio, la cassa, i feed di Google e Meta.
Il cliente che ce l'ha paga quei prezzi negli ordini da ufficio, nei
preventivi e, se entra con il suo account, nel negozio online.

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
* **Prodotti senza riga**: *Prezzo di catalogo* (nessuna regola),
  *Ricarico in %* o *Sconto in %*, con la **Percentuale**. Vale per i prodotti che non
  hanno una riga nel listino, a qualunque quantità, e si calcola sul prezzo
  pieno di catalogo. Per esempio, con *Sconto in %* al 10 un prodotto da 20,00 costa
  18,00. Un prodotto che ha una riga paga il prezzo della riga.
* **Canali**: i canali in cui il listino vale per chi non ne ha uno suo. Un
  canale ha un solo listino: se lo scegli in due listini, il secondo non si
  salva e il messaggio dice quale listino lo tiene già. Un modulo che collega
  un nuovo canale (per esempio un marketplace) lo aggiunge da solo all'elenco.

## Le righe e gli scaglioni

Una riga per prodotto e scaglione: **Da quantità** è la quantità da cui vale
il prezzo. Per esempio:

| Prodotto | Da quantità | Prezzo |
|----------|-------------|--------|
| Maglia   | 1           | 9,00   |
| Maglia   | 10          | 8,00   |

Con 4 maglie si paga 9,00 l'una, con 12 si paga 8,00. Un prodotto che non è
nel listino si paga a prezzo di catalogo, oppure con la regola in percentuale
se il listino ne ha una.

## Importare ed esportare

Dalla scheda del listino, il pulsante **Scarica** apre un menu da cui scegli
**Excel (.xlsx)** o **CSV (.csv)** per le righe del listino (colonne
SKU, Prodotto, Quantità minima, Prezzo). Lo stesso file, modificato, si
ricarica nel campo **Importa righe**: al salvataggio **sostituisce tutte le
righe** del listino. Il prodotto lo riconosce dallo SKU; se una riga ha uno
SKU sconosciuto o un numero sbagliato, il file non entra e il messaggio dice
quale riga correggere.

Per cambiare molti prezzi in una volta, anche di più listini, dall'elenco
dei listini usa **Importa** con il tracciato *Prezzi di listino*: colonne Listino (nome
o numero), SKU, Prodotto, Quantità minima e Prezzo. Il file cambia il prezzo
delle righe che ci sono già e aggiunge quelle nuove; non cancella nessuna
riga.

## Assegnarlo a un cliente

Nella scheda del cliente, riquadro **Listino**. Un cliente ha un solo
listino; lo stesso listino può valere per tanti clienti.

## Quale listino vale

Per ogni prodotto il gestionale guarda, in quest'ordine:

1. il **listino del cliente**;
2. il **listino del canale** dell'ordine (sito, ufficio, cassa…);
3. il **prezzo di catalogo**.

Vince il primo listino che ha il prodotto, con una riga o con la regola in
percentuale. I listini non si sommano mai. Un listino scaduto o spento si
salta e si passa al successivo. L'ordine ricorda il listino usato.

## Negli ordini

* **Ordini da ufficio e preventivi**: la bozza nasce con il listino del
  cliente. Dopo il primo salvataggio, in *Pagamento e sconto*, lo puoi
  cambiare o togliere, e i prezzi si rifanno.
* **Ordine confermato**: i prezzi restano quelli del giorno della conferma,
  anche se poi cambi il listino.
* **Negozio online**: il cliente che ha fatto l'accesso vede nel carrello i
  prezzi del suo listino; gli altri, anche senza account, quelli del listino
  del canale *Sito*, se c'è.
* **Feed di Google e Meta**: mandano il prezzo del listino del loro canale,
  se c'è.

## Con campagne, coupon e sconti

* **Campagne**: il listino le sostituisce, non si sommano. Su un prodotto del
  listino vale il prezzo del listino; sugli altri la campagna resta.
* **Prezzo scontato del prodotto**: anche questo lo sostituisce il listino.
* **Coupon e sconto dell'ordine**: si aggiungono sopra il listino. Un coupon
  con *Escludi prodotti scontati* non tocca le righe a prezzo di listino.
* **Prezzo a mano** su una riga dell'ordine da ufficio: vince su tutto.
