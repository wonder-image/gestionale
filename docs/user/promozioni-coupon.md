---
icon: ticket-perforated
---

# Coupon

> **Da attivare.** Funzionalità *Coupon*. Finché è spenta non vedi la pagina e
> nessun codice si applica; i dati già scritti restano.

## A cosa servono

Un coupon è **un codice** che il cliente scrive nel carrello per avere uno
sconto, la spedizione gratuita o uno sconto solo su certi prodotti. Il codice lo
scegli tu.

Si gestiscono in **Promozioni → Coupon**.

## Il modulo

* **Codice**: non distingue maiuscole e minuscole (`ESTATE10` e `estate10` sono
  lo stesso coupon) ed è **unico**, anche rispetto ai coupon che hai eliminato.
* **Tipo di sconto**: percentuale (da 0,01 a 100), importo in euro oppure
  spedizione gratuita. Con la spedizione gratuita il campo «Sconto» sparisce.
* **Dal / Fino al**: estremi **compresi**. Senza «Fino al» il coupon non
  finisce; l'ultimo giorno vale fino a mezzanotte.
* **Interruttore**: un coupon disattivato non si applica, anche se le date
  coincidono.
* **Spesa minima**: il carrello deve arrivare a questa cifra, contando solo i
  prodotti a cui il coupon si applica (per la spedizione gratuita, tutti i
  prodotti).
* **Utilizzi massimi** e **utilizzi per cliente**: zero vuol dire senza limite.
  Per i clienti senza account vale l'email dell'ordine.
* **Solo sul primo ordine**: non vale per chi ha già ordinato.
* **Non sui prodotti già scontati**: lascia stare i prodotti con una campagna o
  un'offerta.
* **Dove vale**: Sito, Ufficio, Cassa.

## Quali prodotti e quali clienti

* **Tutto il catalogo**, oppure **Solo la selezione**: categorie (con le loro
  sottocategorie), tag, marchi e articoli. Gli **articoli esclusi** non hanno
  mai lo sconto.
* **Clienti riservati**: se ne scegli, solo loro possono usare il codice.

## Gli utilizzi

Un utilizzo si conta **quando l'ordine viene creato**, non quando viene pagato.
Se l'ordine viene annullato o scade senza pagamento, l'utilizzo **torna
disponibile** (sulla riga compare «Rilasciato»). Un reso non lo restituisce.

Sotto il modulo trovi la tabella degli utilizzi: ordine (con il link), cliente,
sconto, data e se è stato rilasciato. Nell'elenco la colonna «Utilizzi» mostra
quelli ancora validi sul limite (`3 / 10`, `3 / ∞` senza limite).

## Cose da sapere

* Il coupon **non si somma** a uno sconto scritto a mano sull'ordine.
* Il codice viene ricontrollato a ogni modifica del carrello: se non regge più
  (la spesa scende sotto il minimo, il coupon scade) esce dal carrello e il
  cliente ne vede il motivo.
* Se due clienti provano a usare l'ultimo utilizzo nello stesso momento, ne
  passa uno solo: l'altro vede che il codice è esaurito.
* Eliminare un coupon lo toglie dall'elenco e il codice non si applica più, ma
  gli ordini già fatti restano come sono.
