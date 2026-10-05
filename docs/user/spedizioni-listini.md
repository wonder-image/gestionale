---
icon: truck
---

# Spedizioni: corrieri, zone e listini

> **Da attivare.** Funzionalità *Spedizioni*. Finché è spenta non vedi le pagine
> e il carrello non propone nessuna spedizione; i dati già scritti restano.

## Come funziona

Il prezzo della spedizione dipende da **dove va** il pacco e da **quanto pesa**.
Lo scrivi in tre pagine, sotto **Spedizioni**:

* **Corrieri**: chi consegna (BRT, GLS, SDA…) e il link per seguire il pacco.
* **Zone**: gruppi di destinazioni («Italia», «Isole», «Unione Europea»).
* **Metodi**: ciò che il cliente sceglie («Standard», «Espresso»). Ogni metodo
  ha **un listino per ogni zona** in cui spedisce.

Il cliente indica la destinazione nel carrello; il sito trova la sua zona e
propone i metodi che hanno un listino per quella zona, ognuno col suo prezzo.

## Corrieri

Il nome, il **link di tracking** e lo stato. Nel link scrivi `{tracking}` dove
va il numero di spedizione: `https://tracking.esempio.it/?codice={tracking}`.
Un corriere su un metodo non si elimina: spegnilo, oppure toglilo dai metodi.

## Zone

Una zona è una tabella di **aree**: un paese e, se vuoi, una provincia, che
scegli da due elenchi (la provincia dipende dal paese). Lasciare la provincia
vuota vuol dire «tutto il paese».

* La zona più **specifica** vince: «Isole» con Italia + Cagliari batte «Italia»
  con tutta Italia per un indirizzo a Cagliari.
* A pari specificità vince la zona più in alto nell'elenco.
* Se due zone hanno la stessa area, sopra il modulo compare un avviso.
* Una zona con listini accesi non si elimina: prima toglila dai metodi.

## Metodi e listini

Nel metodo scrivi nome, tempi di consegna (che il cliente vede), corriere e
codice del servizio. Sotto c'è **un riquadro per ogni zona**: accendi
«Spedisce verso questa zona» e compili il listino.

### Scaglioni di peso

Una tabella di righe:

* **Prezzo fino a**: «fino a 5 kg: 8,50 €», «fino a 20 kg: 15 €». Il carrello
  paga lo scaglione più basso che copre il suo peso. Li puoi scrivere in
  qualunque ordine; due scaglioni con lo stesso peso non sono ammessi.
* **Tariffa al kg oltre**: per i pesi sopra l'ultimo scaglione (il peso massimo
  su questa riga non conta: lascialo vuoto). Con «Tutto il
  peso» la tariffa si applica a tutto il peso; con «Solo l'eccedenza» si paga
  l'ultimo scaglione per intero più la tariffa sui soli kg oltre. **Senza**
  tariffa al kg, un carrello più pesante dell'ultimo scaglione non può usare quel
  metodo: non viene proposto.

### Dal prezzo di listino al prezzo finale

Il prezzo cresce in quest'ordine:

1. il prezzo dello scaglione (più l'eventuale tariffa al kg);
2. la **maggiorazione carburante** (in %);
3. il **margine** (in %, anche negativo per uno sconto, fino a −100 %);
4. l'**arrotondamento** per eccesso (con 0,50, 8,20 diventa 8,50);
5. il **prezzo minimo**.

### Peso reale e volumetrico

Il peso che conta è il **maggiore** tra quello reale e quello volumetrico
(lunghezza × larghezza × altezza ÷ divisore). Il **divisore volumetrico**
(spesso 5000) lo scrivi nel listino; senza, conta solo il peso reale. Peso e
misure stanno sulle opzioni dei prodotti.

Un articolo da spedire **senza peso** si paga come lo scaglione più basso: se ce
ne sono, in cima al metodo compare un avviso.

### Spedizione gratuita

* **Gratis sopra**: la spedizione costa zero se i prodotti (dopo gli sconti)
  **superano** quell'importo.
* **Gratis fino a**: costa zero se il peso sta **sotto** quel valore.
* Se scrivi tutte e due, devono valere tutte e due.

### Contrassegno

La **commissione contrassegno** si somma al prezzo solo quando il cliente
sceglie un pagamento in contrassegno.

### Dove vale

Con più canali accesi, il metodo si può offrire solo sul sito o solo
dall'ufficio (vedi [Canali di vendita](canali-di-vendita.md)).

## Spegnere, togliere, eliminare

* Spegnere «Spedisce verso questa zona» **non cancella** il listino: resta
  salvato e, riaccendendolo, ritrovi i suoi valori.
* Un metodo già su un ordine non si elimina: mettilo su «Non attivo» e non si
  propone più.
