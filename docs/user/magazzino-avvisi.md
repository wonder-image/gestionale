---
icon: arrow-trend-down
---

# Avvisi di scorta minima

> **Da attivare.** Funzionalità *Avvisi di scorta minima*. Finché è spenta non
> vedi né la casella, né il riquadro, né l'email.

## A cosa serve

Ti dice quando un prodotto sta finendo, prima che finisca. Scegli tu a quanti
pezzi vuoi saperlo; il gestionale te lo scrive una volta sola, senza che tu
debba controllare le giacenze ogni giorno.

## La scorta minima

È la casella **Scorta minima** nella scheda del prodotto:

- per un articolo che si vende in **un'unica opzione**, nella scheda
  dell'articolo, sotto il prezzo, dietro **«Compila le informazioni
  avanzate»**, accanto a SKU ed EAN;
- per un articolo **con più opzioni**, nella griglia di *Opzioni in vendita*,
  dietro **«Compila le informazioni avanzate»** di ogni riga, e nel riquadro
  *Magazzino* della scheda di ogni opzione: ogni taglia o colore ha la sua.

Scrivi a quanti pezzi vuoi essere avvisato. Con **5**, l'avviso nasce quando ne
restano cinque. **Zero**, o la casella vuota, vuol dire nessun avviso.

Contano i pezzi **disponibili**: se hai più sedi, tutte insieme.

La soglia vale da subito: se la alzi sopra i pezzi che hai, il prodotto è già
sotto scorta; se la abbassi sotto i pezzi che hai, l'avviso si chiude.

## L'email

Ogni quarto d'ora il gestionale guarda se qualche prodotto è arrivato alla sua
scorta minima (o sotto) e, se sì, manda **un'email sola** con l'elenco: nome,
opzione, SKU, quanti ne restano e la soglia. In fondo c'è il link che apre le
giacenze già filtrate.

- **Per ogni prodotto l'email arriva una volta.** Torna solo se il prodotto
  risale sopra la soglia e poi ci ricade.
- Dieci rettifiche di fila non fanno dieci email: l'elenco parte al giro
  successivo, tutto insieme.
- L'email arriva agli indirizzi scritti in **Destinatari degli avvisi**, nelle
  [impostazioni del negozio](impostazioni.md). Se il campo è vuoto l'email non
  parte: gli avvisi aspettano, e arrivano al primo giro dopo che hai scritto un
  indirizzo.

Il giro ogni quarto d'ora lo accende chi ti segue, insieme alla funzionalità.

## Nella home

Il riquadro **Sotto scorta** elenca i prodotti che sono sotto la scorta minima
**adesso**: dieci al massimo, poi quanti altri ce ne sono e il pulsante **Apri
le giacenze**. Un prodotto che hai appena ricaricato sparisce subito, senza
aspettare il giro dell'email.

Se nessuno riceve l'email, il riquadro te lo ricorda con il link per aggiungere
i destinatari.

## Nell'elenco Giacenze

In **Magazzino → Giacenze** il pulsante **Solo sotto scorta** mostra le sole
righe da riordinare. È il posto giusto per caricare la merce quando arriva:
scrivi la quantità nuova, salva, e l'avviso si chiude da sé.

Come si caricano e si correggono le quantità lo trovi in
[Giacenze e rettifiche](magazzino-giacenze.md).
