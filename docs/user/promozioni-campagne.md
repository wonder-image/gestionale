---
icon: percent
---

# Campagne di sconto

> **Da attivare.** Funzionalità *Campagne di sconto*. Finché è spenta non vedi
> la pagina e nessuna campagna si applica; i dati già scritti restano.

## A cosa servono

Una campagna è **un prezzo che cambia da solo**: dal primo all'ultimo giorno
che scegli, i prodotti che dici tu costano meno. Non devi toccare i prezzi dei
prodotti e, quando la campagna finisce, tornano da soli quelli di prima.

Si gestiscono in **Promozioni → Campagne di sconto**.

## Il modulo

* **Sconto**: una percentuale (da 0,01 a 100) oppure un importo in euro, tolto
  dal prezzo di ogni pezzo.
* **Dal / Fino al**: estremi **compresi**. Senza «Fino al» la campagna non
  finisce; senza «Dal» è già in corso. L'ultimo giorno vale fino a
  mezzanotte.
* **Interruttore**: una campagna disattivata non si applica, anche se le date
  coincidono.
* **Non sui prodotti già scontati**: se un prodotto ha già un prezzo
  scontato, la campagna lo lascia com'è.
* **Dove vale**: Sito, Ufficio, Cassa. Il sito applica la campagna nel carrello.
  Compaiono solo i canali accesi in *Funzionalità → Canali di vendita* (vedi
  [Canali di vendita](canali-di-vendita.md)).

## Quali prodotti

* **Tutto il catalogo**, oppure **Solo la selezione**: categorie (con le loro
  sottocategorie), tag, marchi e articoli. Un prodotto basta che rientri in
  *uno* dei criteri.
* **Articoli esclusi**: non hanno mai lo sconto, anche se rientrano in una
  categoria scelta o nel catalogo intero.

## I quattro stati

| Stato | Quando |
|---|---|
| **In corso** | l'interruttore è acceso e oggi è fra le due date |
| **Programmata** | l'interruttore è acceso ma «Dal» è ancora a venire |
| **Terminata** | «Fino al» è passato |
| **Disattivata** | l'interruttore è spento |

Lo stato non si scrive: lo ricava il gestionale da interruttore e date.

## Anteprima e avviso

Anteprima e avviso stanno nella **scheda della campagna**, la pagina di sola
lettura che si apre dall'elenco (clic sul nome, o sulla lente); per cambiare
qualcosa c'è «Modifica». Dopo ogni salvataggio torni alla scheda, e lì trovi:

* **Anteprima**: quanti prodotti prende la campagna e, sotto, l'elenco di
  tutti, in una tabella come quella delle righe di un ordine: foto, nome per
  intero (articolo — opzione) con lo SKU sotto, prezzo di prima e prezzo con la
  campagna. La casella **Cerca** filtra per nome o SKU, il nome si può
  ordinare e l'elenco va a pagine. Riguarda la campagna **come è salvata**:
  per vedere l'effetto di una modifica si salva (può restare disattivata).
* **Avviso di sovrapposizione**: se un'altra campagna attiva copre gli stessi
  prodotti negli stessi giorni compare un avviso con i nomi. Non blocca
  niente.

## Cosa vince quando ce n'è più d'una

Su un prodotto vale **lo sconto maggiore**; le campagne non si sommano. Un
prezzo scritto a mano sulla riga d'ordine vince su tutto, e un prezzo di
listino cliente (quando ci sarà) vince sulla campagna.

Una campagna può portare il prezzo a **zero**: è un omaggio, e il gestionale
lo scrive come tale, senza confonderlo con «nessuna campagna».

## Eliminare

Eliminare una campagna la toglie dall'elenco; gli ordini già fatti restano
com'erano, perché portano scritto il prezzo che hanno pagato.
