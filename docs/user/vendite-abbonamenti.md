---
icon: arrow-repeat
---

# Abbonamenti

> **Da attivare.** Funzionalità *Abbonamenti*. Finché è spenta non vedi le
> pagine *Piani* e *Abbonamenti*; i dati già scritti restano. Non si spegne
> mentre ci sono abbonamenti in corso.

## A cosa servono

Un abbonamento è un **prezzo che si ripete**: il cliente paga ogni mese o ogni
anno per un servizio. Gli abbonamenti sono **separati dagli ordini**: un rinnovo
non crea un ordine, crea un pagamento.

Le pagine sono due, in **Vendite**:

* **Piani**: cosa vendi, a quali prezzi e con quali limiti;
* **Abbonamenti**: chi è abbonato, a cosa, e quando rinnova.

> Gli abbonamenti nascono dal **sito**, con la funzionalità *Abbonamenti online*,
> oppure **da ufficio**, con «Nuovo abbonamento» (vedi sotto).

## I piani

Un piano ha:

* **Nome** e **descrizione**: li vede il cliente nella pagina dei piani del sito.
* **Tipo fiscale**: dà l'aliquota IVA, come per i prodotti. Per ora vale solo
  l'IVA italiana.
* **Sul sito**: «Nascosto» toglie il piano dalla pagina pubblica, ma lo lascia
  agli abbonati e all'ufficio.
* **Stato**: un piano spento non si propone più a nessuno.

### I prezzi

Un piano ha uno o più prezzi, una riga per prezzo:

* **Nome**: «Mensile», «Annuale».
* **Ogni** e **mesi per addebito**: ogni quanto si paga e quanti mesi copre un
  addebito. Il mensile è «Mese» con 1, l'annuale è «Anno» con 12. Un trimestrale
  è «Mese» con 3.
* **Prezzo** e **prezzo scontato**: IVA inclusa o esclusa come i prezzi del
  catalogo (*Impostazioni → Prezzi del catalogo*). Il prezzo scontato, se più
  basso, vince.
* **Vincolo (mesi)**: per quanti mesi il cliente si impegna. Zero vuol dire
  nessun vincolo. Una disdetta durante il vincolo vale alla fine del vincolo.
* **Prova (giorni)**: i giorni gratuiti prima del primo addebito. Una prova si
  usa una volta per cliente e per piano.
* **A fine vincolo passa a**: il prezzo che l'abbonamento prende quando il
  vincolo finisce, ad esempio da «Annuale promo» ad «Annuale». Si possono
  scegliere solo i prezzi **già salvati**: per un prezzo nuovo, salva il piano
  e poi sceglilo.
* **Stato**: un prezzo spento non si propone più, ma resta a chi lo usa.

Un prezzo cambiato vale per i **nuovi** abbonati: chi è già abbonato resta sul
prezzo di prima finché non cambia piano.

Un prezzo **usato da un abbonamento**, anche chiuso, non si può togliere: il
salvataggio lo rifiuta e ti chiede di spegnerlo. Un piano con abbonamenti in
corso non si elimina.

### I limiti

Quello che il piano permette sul sito, a coppie **chiave** e **valore**: ad
esempio `max_progetti` e `5`, oppure `supporto` e `prioritario`. Le chiavi le
decide chi ha fatto il sito, che le controlla quando il cliente crea qualcosa.

Passare a un piano più basso **non toglie niente**: il limite vale per quello
che si crea dopo.

## Gli abbonamenti

L'elenco mostra codice, cliente, piano e prezzo, stato, prossimo rinnovo,
totale e canale (sito o ufficio). In alto filtri per stato e per canale.

Gli stati:

| Stato | Cosa vuol dire |
|---|---|
| In attesa | Creato, il primo pagamento non è ancora arrivato. |
| In prova | Nei giorni gratuiti. |
| Attivo | Pagato e in corso. |
| Da pagare | Un rinnovo non è riuscito: si riprova, il cliente ha ancora l'abbonamento. |
| Non pagato | I tentativi sono finiti senza pagamento. |
| Chiuso | Disdetto o annullato. |

«In prova», «Attivo» e «Da pagare» sono gli abbonamenti **in corso**: valgono
i limiti del piano, il piano non si elimina e la funzionalità non si spegne.

Cliccando sul codice si apre la scheda:

* **Abbonamento**: stato, cliente, piano, canale, come si paga, se rinnova da
  solo;
* **Periodo**: inizio, periodo in corso, prossimo rinnovo, fine della prova e
  del vincolo, disdetta;
* **Importi del periodo**: subtotale, sconto del coupon, IVA, totale;
* **Pagamenti**, **Cambi di piano** e **Storia** degli stati.

## Abbonamenti online

Con la funzionalità **Abbonamenti online** (in *Funzionalità → Canali di
vendita*; chiede *Vendita online* e *Abbonamenti*) il sito ha la pagina dei
piani, e il cliente si abbona da solo:

1. sceglie un prezzo nella pagina dei piani; serve un **account**;
2. conferma i dati di fatturazione e, se ce l'ha, scrive un codice sconto;
3. paga sulla pagina di **Stripe**, con la carta (anche con Link);
4. torna sul sito: l'abbonamento compare *In prova* o *Attivo* appena Stripe
   conferma il pagamento.

Lo stato lo decide Stripe: rinnovi pagati, rinnovi non riusciti e chiusure
arrivano da soli, e ogni pagamento riuscito è una riga in **Pagamenti** della
scheda. Il cliente riceve un'email quando l'abbonamento si attiva, quando un
rinnovo non riesce (una volta, al primo tentativo: Stripe riprova da solo nei
giorni dopo; c'è il link per aggiornare la carta) e quando disdice.

Dall'**area cliente**, sezione *Abbonamenti*, il cliente:

* **cambia piano**: subito, il nuovo prezzo vale dal rinnovo dopo, senza
  conguagli; il codice sconto resta;
* **disdice**: l'abbonamento resta attivo fino alla fine del periodo pagato e
  poi non si rinnova. Durante un **vincolo** la disdetta si registra e vale alla
  fine del vincolo;
* **aggiorna la carta** nella pagina di Stripe.

Alla fine del vincolo, se il prezzo ha «A fine vincolo passa a», l'abbonamento
prende quel prezzo dal rinnovo dopo.

Chi lascia la pagina di Stripe senza pagare lascia un abbonamento *In attesa*:
dopo 24 ore si chiude da solo.

> **Per chi installa.** Servono le chiavi di Stripe del sito e il webhook già
> usato per gli ordini, con in più gli eventi `checkout.session.completed`,
> `customer.subscription.created`, `customer.subscription.updated`,
> `customer.subscription.deleted`, `invoice.paid` e `invoice.payment_failed`.
> Accendi le attività **Gestionale: riallineamento con Stripe** e
> **Gestionale: fine vincolo degli abbonamenti**. Un rimborso di un abbonamento
> si fa da Stripe e compare in *Da controllare*.

## Abbonamenti da ufficio

Dall'elenco *Abbonamenti*, **Nuovo abbonamento**: scegli il cliente, il piano e
il prezzo, la data d'inizio, il metodo e la condizione di pagamento (vuoti: quelli
della scheda del cliente) e, se vuoi, un coupon. Niente Stripe: il cliente paga
come per un ordine da ufficio.

* L'abbonamento parte **attivo** dalla data scelta (o *in prova*, se il prezzo
  ne ha una): non aspetta il primo pagamento.
* A ogni periodo nascono le **rate** della condizione di pagamento (con
  «Bonifico 30 gg fine mese», una rata a fine del mese dopo l'inizio del
  periodo) e il cliente riceve il **proforma** in PDF, con l'importo, le
  scadenze e l'IBAN del conto. Il proforma non è una fattura.
* Le rate si incassano dallo **Scadenzario** (*Vendite → Scadenzario*, con
  *Vendita in ufficio*) o dalla scheda dell'abbonamento, con **Registra**. Un
  acconto lascia il resto con la stessa scadenza.
* Una rata **scaduta** porta l'abbonamento a *da pagare* e il cliente riceve un
  promemoria. Dopo i giorni scelti in *Impostazioni → Vendite → Giorni prima di
  «non pagato»* (15 di partenza) diventa *non pagato*: non si aprono più periodi
  finché non paga. Annullarlo lo decidi tu.

Dalla scheda dell'abbonamento:

* **Cambia piano**: il nuovo prezzo vale dal periodo dopo; le rate di quello in
  corso restano.
* **Disdici**: non si rinnova più e finisce alla fine del periodo (o del
  vincolo, se dura ancora).
* **Annulla**: si chiude subito e le rate in attesa si annullano. I pagamenti già
  arrivati restano.
* **Rimanda il proforma** e scarica il PDF del periodo in corso.

*Cambia piano* e *Disdici* ci sono anche per gli abbonamenti online: passano a
Stripe.

> **Per chi installa.** Accendi l'attività **Gestionale: periodi e scadenze
> degli abbonamenti da ufficio**: apre i periodi, chiude i disdetti e segue le
> rate scadute.

## Coupon sugli abbonamenti

Un coupon vale anche per gli abbonamenti se nella sua scheda accendi **Vale per
gli abbonamenti** (vedi [Coupon](promozioni-coupon.md)). Solo sconti in
percentuale o in importo, con una durata (da ufficio il coupon deve valere anche
per *Ufficio*):

* **Sul primo addebito**;
* **Per N mesi**: un mensile lo prende per N addebiti, un annuale solo sul
  primo se N è meno di 12;
* **Per sempre**.
