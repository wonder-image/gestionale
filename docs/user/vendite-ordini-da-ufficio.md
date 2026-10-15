---
icon: pen-to-square
---

# Ordini da ufficio

> **Da attivare.** Funzionalità **Vendita in ufficio**. Finché è spenta gli
> ordini arrivano solo dal sito.

## A cosa serve

Fare un ordine dal gestionale: al telefono, per email, per un cliente che
compra a rate o a cui fai un prezzo suo. L'ordine nasce come **bozza**: prende il
**numero** al primo salvataggio, non tocca il magazzino e lo puoi modificare
quanto vuoi. Quando lo **confermi** tiene lo stesso numero, la merce esce e nascono le
[rate](vendite-pagamenti-a-rate.md).

## Nuovo ordine

*Vendite → Ordini da ufficio* elenca le bozze; **Nuovo ordine** ne apre una.
Dal menu **⋯** di ogni bozza: **Conferma ordine** (senza email al cliente) ed
**Elimina**.

* **Cliente** — cercalo per nome, email o partita IVA. Scegliendolo si
  riempiono fatturazione, consegna, metodo e condizione di pagamento dalla sua
  scheda: per questo ordine li puoi cambiare. Il cliente nuovo si crea con
  **Nuovo cliente**, in un'altra scheda del browser; poi cercalo qui.
* **Fatturazione** — i dati del documento.
* **Consegna** — *Spedizione*, *Ritiro in sede* o *Nessuna consegna*. Con la
  spedizione servono l'indirizzo e, con le spedizioni attive, il metodo. Il
  **costo di spedizione** vuoto è quello del listino; scrivilo per fissarlo a
  mano, anche zero. Con più sedi scegli quella che evade.
* **Righe** — vedi sotto.
* **Pagamento e sconto** — il metodo, la condizione (in quante rate) e uno
  sconto sul totale, in percentuale o in euro.
* **Note** — la *nota stampata* va sul documento del cliente, quella *interna*
  la vedi solo tu.

### Le righe

**Cerca o scansiona** trova le versioni in vendita per nome, SKU o EAN; con il
lettore di codici a barre il codice esatto aggiunge la riga da solo.

| Colonna | Cosa vuol dire |
|---|---|
| **Disponibili** | La merce della sede. Se l'ordine ne chiede di più la riga lo dice, ma non ti ferma: lo decide la conferma. |
| **Quantità** | Anche con i decimali (2,5). |
| **Prezzo** | Vuoto = il prezzo del listino o della campagna in corso, che vedi in grigio. Scrivilo per fare un prezzo a mano. |
| **Sconto** | Sulla riga: percentuale o importo. |
| **Lotto · Fornitore** | Con *Lotti e scadenze* o *Acquisti*: vuoti = automatico. |

**Riga libera** vende qualcosa che non è in catalogo (un montaggio, un
servizio): descrizione, prezzo e categoria IVA. **Testo** aggiunge una riga
solo da leggere, senza prezzo.

I multiprodotti e gli articoli con una personalizzazione obbligatoria da
ufficio non si scelgono ancora: vendili come riga libera.

### Salvare e confermare

**Salva bozza** salva tutto e mostra i **totali** veri, con listino, campagne,
sconti e spedizione. La bozza la ritrovi in *Ordini da ufficio* e negli **Ordini**, già col suo
numero.

**Conferma ordine**:

1. controlla che ci siano il cliente, almeno una riga che si vende e, se si
   spedisce, l'indirizzo;
2. controlla la merce della sede: se manca si ferma e ti dice quale articolo e
   quanti ne hai. Fanno eccezione gli articoli che si vendono senza giacenza;
3. fa uscire la merce; il numero resta quello della bozza;
4. crea le rate della condizione di pagamento nello
   [Scadenzario](vendite-pagamenti-a-rate.md).

Con l'interruttore **Alla conferma scrivi al cliente** acceso parte l'email di conferma, con la
conferma d'ordine in PDF allegata.

**Conferma ordine**, **Elimina bozza** e **Proforma** stanno in alto, vicino a
*Guida*. **Elimina bozza** la toglie del tutto: una bozza non ha movimenti, e
il suo numero resta un buco nella numerazione.

## Dopo la conferma

Le righe non si modificano più. Dalla scheda dell'ordine:

* **Annulla riga** — dal menu **⋯** della riga, nel riquadro *Righe*: scrivi
  quanto annullare (parte da tutto quello che si può). Si annulla solo quello
  che **non è ancora partito** con una spedizione. La merce torna in
  magazzino, i totali e le rate si rifanno, e sotto la quantità della riga
  compare *annullati*. Annullando tutte le righe si annulla l'ordine.
* **Documenti → Proforma** — la conferma d'ordine da mandare al cliente, con
  le rate e le scadenze. C'è già dalla bozza.
* **Registra pagamento** — sulla rata più vicina, o dallo Scadenzario sulla
  rata che scegli.
* Per la merce già consegnata: il [reso](vendite-resi.md), come per gli ordini
  del sito.
