---
icon: cash-coin
---

# Pagamenti e scadenze

> **Da attivare.** Fa parte di **Ordini**.

## A cosa serve

Un ordine sa sempre **quanto è stato incassato**. Ogni incasso è una riga del
riquadro **Pagamenti** della scheda, con **Codice**, **Tipo**, **Importo**,
**Stato**, **Data**, **Metodo** e **Riferimento**. Lo stato del pagamento
dell'ordine si ricalcola da queste righe: non si scrive a mano.

## Registrare un pagamento

Il bonifico è arrivato, il cliente ha pagato in negozio: dalla scheda premi
**Registra pagamento**. Compare una finestra con quattro campi:

| Campo | Cosa scrivere |
|---|---|
| **Importo (€)** | Quanto è arrivato. Parte dal **residuo**: scrivi meno per un **acconto**. |
| **Data** | Il giorno in cui il denaro è arrivato, anche se lo registri in ritardo. |
| **Metodo** | Come ha pagato; parte dal metodo scelto dal cliente. |
| **Riferimento** | Il numero di CRO o di operazione. Facoltativo. |

In cima alla finestra vedi **Totale**, **Già incassato** e **Residuo**. Il
pulsante compare finché c'è ancora qualcosa da incassare.

Con un acconto l'ordine passa a **Pagato in parte**; quando la somma arriva al
totale diventa **Pagato**. Registrare un pagamento **non conferma** l'ordine:
se è ancora in attesa, la merce esce dal magazzino quando premi **Conferma**.
Se invece l'ordine era già confermato ed evaso, l'ultimo incasso lo chiude da
solo (**Completato**).

## Metodi e conti

Si configurano in *Set Up → Pagamenti*:

* **Metodi di pagamento** — carta, bonifico, contrassegno… Per ognuno scegli
  **Quando arriva il denaro** (subito, più tardi, alla consegna), per chi è
  **Disponibile** e se è **Online**. Le scelte sui canali (sito, ufficio, cassa)
  compaiono solo se hai acceso più di un [canale di vendita](canali-di-vendita.md).
* **Conti di pagamento** — dove arriva il denaro (le coordinate del bonifico,
  per esempio).

## L'attesa e la scadenza

La merce di un ordine in attesa è **prenotata**, non regalata. Per questo
l'attesa ha un limite, e lo decidono due impostazioni (*Impostazioni*):

* **Minuti di prenotazione** — per i pagamenti immediati (la carta): scaduti,
  la merce torna in vendita. Predefinito: 30.
* **Giorni di attesa del pagamento** — per il bonifico: a **metà** dell'attesa
  il cliente riceve **un promemoria**, una volta sola; alla **fine** l'ordine si
  annulla e i pezzi tornano disponibili. Predefinito: 7.

Se metti **zero** non scade niente. Col contrassegno e il ritiro in negozio non
c'è scadenza: la merce esce alla conferma.

Il controllo gira da solo **ogni ora**; lo vedi nello **Storico** dell'ordine,
scritto da solo, senza un utente accanto.

## Cosa vede il cliente

Il cliente riceve una email quando l'ordine è **ricevuto**, **confermato** e
**annullato**, e il **promemoria** a metà attesa del bonifico. Il negozio
riceve le sue: nuovo ordine e ordine annullato. Gli ordini finti della demo
non spediscono email.
