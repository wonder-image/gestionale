---
icon: arrow-repeat
---

# Abbonamenti

> **Da attivare.** Funzionalità *Abbonamenti*. Finché è spenta non puoi creare
> prodotti di tipo *Abbonamento* e non vedi *Vendite → Abbonamenti*; i dati già
> scritti restano. Non si spegne
> mentre ci sono abbonamenti in corso.

## A cosa servono

Un abbonamento è un **prezzo che si ripete**: il cliente paga ogni mese o ogni
anno per uno o più servizi. Gli abbonamenti sono **separati dagli ordini**: un
rinnovo non crea un ordine, crea un pagamento.

Le pagine sono due:

* **Catalogo → Abbonamenti**: cosa vendi. Un abbonamento (il «piano») è un
  prodotto di tipo **Abbonamento**, con i suoi prezzi ricorrenti e i suoi
  metadata (i limiti del piano);
* **Vendite → Abbonamenti**: chi è abbonato, a cosa, e quando rinnova.

> Gli abbonamenti nascono dal **sito**, con *Abbonamenti* e *Vendita online*
> accese, oppure **da ufficio**, con «Nuovo abbonamento» (vedi sotto).

## I piani

In *Catalogo → Abbonamenti*, **+ Aggiungi** crea un prodotto di tipo
*Abbonamento*, che compare in questa lista. Il tipo si sceglie alla creazione e non cambia. La scheda è
quella di un prodotto, senza quello che a un servizio non serve: magazzino,
spedizione, lotti, varianti, confezione, resi e unità di misura. Restano:

* **Nome**, **descrizione** e **foto**: il cliente li vede nella scheda del
  sito e nella pagina dei piani; la foto anche in carrello e checkout.
* **Categorie**, **tag**, URL e SEO, come ogni prodotto: l'abbonamento compare
  nel catalogo e nella ricerca del sito.
* **Tipo fiscale**: dà l'aliquota IVA, come per i prodotti: parte dal tipo
  predefinito ed è obbligatorio. Per ora vale solo l'IVA italiana. Con la
  funzionalità «Aliquote» spenta il campo non c'è e l'abbonamento usa il
  predefinito.
* **Acquistabile online**: spento, toglie il piano dal sito, ma lo lascia agli
  abbonati e all'ufficio.
* **Stato**: un piano spento non si propone più a nessuno.
* **Si lega a**: il piano si compra solo insieme a uno di questi, e va sullo
  stesso abbonamento. L'hosting, per esempio, si lega al dominio: non si vende
  un hosting senza il suo dominio. Un livello solo: si scelgono piani che non si
  legano a nulla, e mai il piano stesso.

### I servizi

Un piano può essere un **servizio** fra altri: dominio, hosting, assistenza. Lo
stesso abbonamento li tiene insieme, una **riga** per servizio, ognuna col suo
prezzo e la sua quantità. Il piano si fa allo stesso modo; quello che lo rende
un servizio è come lo vendi.

### Le personalizzazioni

Con la funzionalità *Personalizzazione*, nella scheda del piano il riquadro
**Personalizzazioni** collega i campi che chi compra deve compilare: il nome del
dominio, per esempio. Si scelgono fra quelle accese **«Per gli abbonamenti»**,
ognuna obbligatoria o no (vedi [Personalizzazioni](catalogo-personalizzazioni.md)).
Il sovrapprezzo, se c'è, si paga a ogni periodo del servizio, per la quantità.

### I prezzi

Un piano ha uno o più prezzi, una riga per prezzo:

* **Nome**: «Mensile», «Annuale».
* **Prezzo**: IVA inclusa o esclusa come i prezzi del catalogo
  (*Impostazioni → Prezzi del catalogo*).
* **Addebito**: ogni quanto si paga il prezzo: Mensile, Bimestrale,
  Trimestrale, Semestrale o Annuale. Il prezzo è l'importo di ogni addebito:
  «Trimestrale» a 120 € vuol dire 120 € ogni tre mesi.
* **Una tantum**: un importo che si paga una volta sola, col primo addebito
  (vuoto o zero: niente). Con la prova si paga subito, all'inizio della prova;
  il canone parte alla fine. Non torna sui rinnovi, sui cambi di prezzo e sui
  servizi aggiunti dopo. Ha l'IVA del prodotto abbonamento e il coupon non lo
  sconta.
* **Stato**: un prezzo spento non si propone più, ma resta a chi lo usa.

Con **Compila le informazioni avanzate** compaiono anche:

* **Prezzo scontato**: se più basso del prezzo, vince.
* **Vincolo (mesi)**: per quanti mesi il cliente si impegna. Zero vuol dire
  nessun vincolo. Una disdetta durante il vincolo vale alla fine del vincolo.
* **Prova (giorni)**: i giorni gratuiti prima del primo addebito. Una prova si
  usa una volta per cliente e per piano.
* **A fine vincolo passa a**: il prezzo che l'abbonamento prende quando il
  vincolo finisce, ad esempio da «Annuale promo» ad «Annuale». Si possono
  scegliere solo i prezzi **già salvati**: per un prezzo nuovo, salva il piano
  e poi sceglilo.

Un prezzo cambiato vale per i **nuovi** abbonati: chi è già abbonato resta sul
prezzo di prima finché non cambia la riga.

Un prezzo **usato da un abbonamento**, anche chiuso, non si può togliere: il
salvataggio lo rifiuta e ti chiede di spegnerlo. Un piano con abbonamenti in
corso non si elimina.

### Metadata

Quello che il piano permette sul sito, a coppie **chiave** e **valore**: ad
esempio `max_progetti` e `5`, oppure `supporto` e `prioritario`. Le chiavi le
decide chi ha fatto il sito, che le controlla quando il cliente crea qualcosa.

Passare a un piano più basso **non toglie niente**: il limite vale per quello
che si crea dopo.

## Le righe dell'abbonamento

Un abbonamento ha una o più righe, una per servizio. Le regole:

* **Una data di rinnovo sola.** Ogni riga rinnova dalla data d'inizio
  dell'abbonamento, a multipli del suo intervallo: con un dominio annuale e
  un'assistenza mensile partiti il 10 marzo, l'assistenza rinnova ogni 10 del
  mese e il dominio ogni 10 marzo.
* **Intervalli compatibili.** Gli intervalli devono essere multipli del più
  piccolo: mensile e annuale stanno insieme, bimestrale e trimestrale no.
* **La prova** vale solo alla creazione: la più lunga fra i prezzi scelti. Un
  servizio aggiunto dopo non ne ha.
* **Il coupon** vale sull'insieme delle righe del periodo.
* **Un servizio legato** (l'hosting) si appoggia a una riga del piano a cui si
  lega (il dominio). Togliendo la riga madre si tolgono anche le sue figlie.
* Togliere **l'ultima riga** è una disdetta.

### Quanti abbonamenti per cliente

In *Impostazioni → Vendite*, **Abbonamenti in corso per cliente** dice quanti
abbonamenti in corso può avere un cliente: di serie **1**, con **0** senza
limite. Vale online e da ufficio. Al limite non se ne apre un altro: i servizi
nuovi si **aggiungono** all'abbonamento che c'è.

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

Cliccando sul codice si apre la scheda, disegnata come quella dell'ordine:

* in testata il codice, la data e il canale, e a destra le azioni: **Proforma**,
  **Aggiungi servizio**, **Disdici**, **Rimanda il proforma** e, nel menu
  **Altro**, **Annulla**;
* a sinistra **stato**, **pagamento**, **rinnovo automatico**, **cliente**
  (con email e telefono), **fatturazione**, **piano**, **metodo di pagamento**
  e **nota**;
* a destra **Totali del periodo** (servizi, sconto e coupon, imponibile, IVA,
  totale) e **Periodo** (inizio, periodo in corso, prossimo rinnovo, fine della
  prova e del vincolo, disdetta);
* sotto, a sezioni che si aprono e chiudono: **Servizi** (le righe, con prezzo,
  quantità, personalizzazioni e prossimo rinnovo di ognuna), **Pagamenti e
  rinnovi**, **Storia dei servizi** (aggiunti, tolti, cambiati, corretti) e
  **Storico** degli stati.

## Abbonamenti online

Non serve una funzionalità a parte: con **Abbonamenti** e **Vendita online**
accese (in *Funzionalità*) il sito vende gli abbonamenti come i prodotti. Un
abbonamento va sul sito se nella sua scheda è acceso **Acquistabile online**;
spento, si vende solo da ufficio. Il sito ha la pagina dei piani, e il cliente
si abbona da solo:

1. sceglie un prezzo nella scheda dell'abbonamento o nella pagina dei piani;
   serve un **account**. Nella scheda il prezzo scelto sta sotto il nome col
   suo periodo («30,00 €/Anno», «3,00 €/Mese»), col prezzo pieno barrato se
   è scontato, e cambia quando il cliente sceglie un'altra opzione; nelle
   card del catalogo c'è il prezzo più basso, «Da 3,00 €/Mese» se i prezzi
   sono più d'uno. Il
   servizio compare nel **carrello** e nel carrello laterale insieme ai
   prodotti, e da lì il cliente lo toglie con **Rimuovi** (togliendo un
   servizio si tolgono anche quelli che si appoggiano a lui). Nel checkout
   invece il servizio non ha il pulsante per toglierlo;
2. nel checkout, anche insieme a dei prodotti, conferma i dati di
   fatturazione e, se ce l'ha, scrive un codice sconto. Nel riepilogo il
   servizio ha il nome del piano e sotto il prezzo col periodo
   («9,90 €/Mese»); il totale è il **Totale di oggi** e sotto c'è quanto
   pagherà dopo («Poi 9,90 €/Mese», con la prova «Poi 9,90 €/Mese dal …»);
3. paga con la carta **dentro la pagina**, senza andare su Stripe: un solo
   pagamento per i prodotti e il primo addebito dell'abbonamento. Con la prova
   la carta si salva e il canone si addebita alla fine della prova. Con un
   abbonamento nel carrello si paga **solo con la carta**: bonifico, PayPal,
   Klarna, Satispay e gli altri metodi spariscono, perché non fanno addebiti
   ricorrenti; tornano negli ordini di soli prodotti;
4. l'abbonamento compare *In prova* o *Attivo* appena Stripe conferma il
   pagamento; l'ordine dei prodotti risulta pagato con lo stesso pagamento.

Con un prezzo che ha l'**una tantum**, il cliente la vede nella pagina degli
abbonamenti («+ 49,00 € una tantum» sotto il canone) e nella scheda del
prodotto, accanto al prezzo; nel checkout il primo addebito la comprende. In
prova paga subito solo l'una tantum e il checkout dice quanto pagherà alla fine
della prova.

Lo stato lo decide Stripe: rinnovi pagati, rinnovi non riusciti e chiusure
arrivano da soli, e ogni pagamento riuscito è una riga in **Pagamenti** della
scheda. Il cliente riceve un'email quando l'abbonamento si attiva, quando un
rinnovo non riesce (una volta, al primo tentativo: Stripe riprova da solo nei
giorni dopo; c'è il link per aggiornare la carta) e quando disdice.

Dall'**area cliente**, sezione *Abbonamenti*, il cliente:

* **cambia piano**: subito, il nuovo prezzo vale dal rinnovo dopo, senza
  conguagli; il codice sconto resta. Con più servizi cambia il servizio
  principale;
* **disdice**: l'abbonamento resta attivo fino alla fine del periodo pagato e
  poi non si rinnova. Durante un **vincolo** la disdetta si registra e vale alla
  fine del vincolo;
* **aggiorna la carta** nella pagina di Stripe.

Alla fine del vincolo, se il prezzo ha «A fine vincolo passa a», l'abbonamento
prende quel prezzo dal rinnovo dopo.

Chi lascia il pagamento a metà lascia un abbonamento *In attesa*:
dopo 24 ore si chiude da solo.

> **Per chi installa.** Servono le chiavi di Stripe del sito e il webhook già
> usato per gli ordini, con in più gli eventi `checkout.session.completed`,
> `customer.subscription.created`, `customer.subscription.updated`,
> `customer.subscription.deleted`, `invoice.paid` e `invoice.payment_failed`.
> Accendi le attività **Gestionale: riallineamento con Stripe** e
> **Gestionale: fine vincolo degli abbonamenti**. Un rimborso di un abbonamento
> si fa da Stripe e compare in *Da controllare*.

## Abbonamenti da ufficio

Dall'elenco *Abbonamenti*, **Nuovo abbonamento**: scegli il cliente e, con
**«Aggiungi servizio»**, una riga per servizio: piano e prezzo, quantità e, per
un servizio legato, la riga a cui **si appoggia** (contando dall'alto). Le
personalizzazioni stanno nelle informazioni avanzate della riga, ognuna coi
piani che la chiedono fra parentesi. Poi la data d'inizio, il metodo e la
condizione di pagamento (vuoti: quelli della scheda del cliente) e, se vuoi, un
coupon. Niente Stripe: il cliente paga come per un ordine da ufficio.

* L'abbonamento parte **attivo** dalla data scelta (o *in prova*, se il prezzo
  ne ha una): non aspetta il primo pagamento.
* A ogni periodo nascono le **rate** della condizione di pagamento (con
  «Bonifico 30 gg fine mese», una rata a fine del mese dopo l'inizio del
  periodo) e il cliente riceve il **proforma** in PDF, con l'importo, le
  scadenze e l'IBAN del conto. Il proforma non è una fattura.
* L'**una tantum** del prezzo è una rata a parte, con scadenza la data
  d'inizio (anche in prova), e il suo proforma: una riga per servizio.
* Le rate si incassano dallo **Scadenzario** (*Vendite → Scadenzario*, con
  *Vendita in ufficio*) o dalla scheda dell'abbonamento, con **Registra**. Un
  acconto lascia il resto con la stessa scadenza.
* Una rata **scaduta** porta l'abbonamento a *da pagare* e il cliente riceve un
  promemoria. Dopo i giorni scelti in *Impostazioni → Vendite → Giorni prima di
  «non pagato»* (15 di partenza) diventa *non pagato*: non si aprono più periodi
  finché non paga. Annullarlo lo decidi tu.

Dalla scheda dell'abbonamento (**Aggiungi servizio** in testata, le altre nel
menu ⋯ della riga in **Servizi**):

* **Aggiungi servizio**: piano e prezzo, quantità, la riga a cui si appoggia e
  le personalizzazioni. Il **conguaglio** sceglie come si paga il pezzo fino al
  rinnovo del servizio:
  * **Quota ora fino al rinnovo del servizio**: una rata per i giorni che
    mancano, col suo proforma;
  * **Gratis fino al rinnovo**: niente fino al rinnovo;
  * **Dal prossimo periodo**: il servizio parte col periodo dopo.
* **Cambia**, sulla riga: un altro prezzo, anche di un altro piano. *Conguaglio
  ora* dà un credito per i giorni non goduti del prezzo vecchio e una rata per
  la quota del nuovo; *Subito, senza conguaglio* cambia da oggi senza conti;
  *Dal prossimo rinnovo del servizio* cambia a fine periodo. Prende il posto di
  «Cambia piano».
* **Togli**, sulla riga: **A fine periodo**, oppure **Subito, con un credito
  per i giorni non goduti**. Durante il vincolo c'è solo la fine del vincolo.
  Le righe che si appoggiano a quella si tolgono insieme, e la finestra le
  elenca.
* **Correggi personalizzazioni**, sulla riga: cambia quello che ha scritto il
  cliente (il nome del dominio sbagliato).

Il **credito** riduce il prossimo proforma; il gestionale non fa rimborsi.

Sempre dalla scheda:

* **Disdici**: non si rinnova più e finisce alla fine del periodo (o del
  vincolo, se dura ancora).
* **Annulla**: si chiude subito e le rate in attesa si annullano. I pagamenti già
  arrivati restano.
* **Rimanda il proforma** e scarica il PDF del periodo in corso.

*Disdici* c'è anche per gli abbonamenti online, e passa a Stripe.

### Servizi di un abbonamento online

La card **Servizi** vale anche per gli abbonamenti online, appena Stripe li ha
fatti partire. Ogni riga è un servizio anche su Stripe, e i conti li fa Stripe
sulla carta del cliente:

* **Aggiungi servizio** con *Quota ora*: Stripe addebita subito i giorni fino al
  rinnovo; se la carta non passa il servizio non si aggiunge. *Gratis fino al
  rinnovo*: niente ora. *Dal prossimo periodo*: il servizio entra al rinnovo.
* **Cambia**: *Conguaglio ora* fa addebitare subito la differenza; *Subito,
  senza conguaglio* e *Dal prossimo rinnovo* come da ufficio.
* **Togli** *Subito, con un credito*: Stripe mette il credito sulla fattura
  dopo. L'**ultimo** servizio non si toglie così: è una disdetta, con
  *Disdici*.

Le modifiche che valgono dal rinnovo Stripe le tiene in un **programma** dei
rinnovi dell'abbonamento: compare nella pagina dell'abbonamento in Stripe e non
va toccato a mano. Una *Disdetta* lo toglie. Se Stripe non risponde, la scheda
dice di riprovare e non cambia niente.

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
