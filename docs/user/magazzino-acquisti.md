---
icon: cart-flatbed
---

# Acquisti e valore del magazzino

> **Da attivare.** Funzionalità *Acquisti*. Finché è spenta non vedi fornitori
> e costi nei carichi, né il valore del magazzino.

## A cosa serve

Sapere da chi hai comprato la merce, quanto l'hai pagata e quanto vale quello
che hai in magazzino. Tutti i costi sono **IVA esclusa**.

## Il carico da un fornitore

In **Magazzino → Carichi → Nuovo carico**, in alto:

- **Fornitore**: chi ti ha consegnato la merce. Si può lasciare su *Nessuno*.
- **Documento** e **Data**: il numero e la data della sua bolla o fattura.

Ogni riga ha in più il **Costo** di un pezzo. Se nella scheda del prodotto hai
già scritto il costo per quel fornitore, la casella si riempie da sola quando
aggiungi la riga o scegli il fornitore; puoi sempre correggerla. Sotto la
tabella vedi il totale del carico.

Quando **confermi**:

- la merce entra in magazzino **a nome di quel fornitore**;
- il costo che hai scritto diventa il **costo di oggi** del fornitore nella
  scheda del prodotto. Se quel fornitore non c'era ancora fra i fornitori
  dell'opzione, viene aggiunto. Un costo lasciato vuoto non cambia niente.

## Da quale fornitore esce la merce

Una vendita, uno scarico o una rettifica in meno prendono la merce da sé, in
quest'ordine:

1. prima quella entrata **senza fornitore** (per esempio quella che avevi
   prima di attivare *Acquisti*);
2. poi dal fornitore che **costa meno**.

Così il magazzino conserva la merce più cara solo quando serve davvero.

## Venduto prima che arrivasse

Se vendi un prodotto che non avevi (con la *Vendita senza giacenza* accesa),
la giacenza va sotto zero. Al carico successivo il gestionale assegna a quel
fornitore i pezzi già venduti: nei *Movimenti* lo trovi come **Assegnazione
lotto**. La giacenza totale non cambia.

## Il valore del magazzino

- In home, il riquadro **Valore del magazzino**: il totale e, con più sedi,
  quanto vale ogni sede.
- In **Magazzino → Giacenze**, la colonna **Valore** per ogni opzione.

Il valore è **pezzi × costo di oggi del fornitore**. La merce senza fornitore
vale al costo più basso fra i fornitori dell'opzione. Se un'opzione non ha
nessun costo, i suoi pezzi non entrano nel valore: il riquadro te lo dice
(«3 pezzi senza costo»), così sai quali costi scrivere.

## Dove vedi il fornitore

- **Carichi**: le colonne *Fornitore* e *Costo* e il filtro *Fornitore*.
- **Movimenti**: la colonna e il filtro *Fornitore*; la scheda del movimento
  ha il costo unitario.
- **Scheda della versione**: sotto la giacenza, quanti pezzi hai per ogni
  fornitore.
