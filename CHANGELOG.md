# Changelog

Il formato segue [Keep a Changelog](https://keepachangelog.com/it/1.1.0/) e il
versionamento semantico.

## 0.1.0 — non rilasciata

### Aggiunto

- Scheletro del modulo: manifest, entrypoint, configurazione, Resource base.
- Test del modulo con harness proprio e `php tests/run.php`.
- Funzionalità sbloccabili: catalogo nel codice, stato su database sincronizzato
  con `id` stabili, pannello "Funzionalità" con un interruttore per funzionalità
  (`formSchema` e `formLayoutSchema` su una pagina-form del core `2.2.4`, con il
  campo `toggle` e il contenitore `masonry`), righe
  precaricate create da `forge update`, pagine che spariscono quando la
  funzionalità è bloccata.
- Documentazione: `gitbook-docs.yaml` con gli spazi GitBook "Sviluppatori"
  (`docs/`) e "Guida commercianti" (`guide/`), con le prime pagine.
- Avvisi di scorta minima (`low_stock_alerts`): campo *Scorta minima* nelle due
  schede, avviso aperto e chiuso da `Stock::apply()` e dal salvataggio della
  soglia, attività `gestionale.stock_alerts` (ogni quarto d'ora, nata spenta)
  che manda una email raggruppata ai *Destinatari degli avvisi* con la view
  sovrascrivibile e l'hook `beforeEmailSend` (`stock.low_stock`), anteprima
  `php forge gestionale:stock-alerts`, riquadro *Sotto scorta* nella home e
  giacenze negative in *Da controllare*.
- Scheda articolo, ottavo giro: prezzi con l'input prezzo del core (in euro),
  giacenza con l'unità dell'articolo (*pz*, *kg*), descrizione breve su una
  riga, descrizione con l'editor (grassetto, corsivo, link) salvata come HTML
  ripulito. Riquadro *Scheda tecnica* sempre presente, con *Nuova
  caratteristica* (Testo o Numero, creata al volo) e gli attributi a valori
  come pillole da spuntare, più d'uno per articolo
  (`ProductAttributes::rows()`). Il tipo di attributo *Icona* ha un'immagine
  per valore al posto dell'icona del font.
- Acquisti (`purchasing`), fornitori per opzione: da chi si compra ogni
  opzione in vendita, con quale codice e a quanto, in `gst_product_suppliers`
  (`ProductSuppliers`). Si compilano dietro «Compila le informazioni
  avanzate», nel riquadro *Prodotto* o nella riga della griglia, e nella
  scheda dell'opzione: con un fornitore solo *Codice fornitore* e *Costo
  d'acquisto*, con due o più il bottone *Fornitori* con la finestra a righe e
  *Salva per tutte le opzioni*. Accendendo le varianti le opzioni che nascono
  prendono i fornitori dell'articolo. Un fornitore con dei costi non si
  elimina e non perde il ruolo.
- Con più sedi la griglia delle opzioni non ha la colonna *Giacenza*: i pezzi
  si scrivono sede per sede dal bottone *Giacenza*.
- Giacenze in sola consultazione: l'elenco del core su `Product`, con una
  colonna per sede (`Locations::shown()`), *Totale*, *Scorta minima*,
  *Impegnati* e *Disponibili* secondo le funzionalità, numeri calcolati nella
  query (`LevelsSql`) e ordinabili, ricerca anche sul nome dell'articolo, filtri
  *Stato*, *Marchio*, *Categoria* e *Scorta*, menu ⋯ con *Movimenti* e *Apri la
  versione*. Tolta la pagina-form con le caselle e il salvataggio in blocco;
  `lowStockUrl()` apre l'elenco con il filtro *Scorta*.
- Scheda opzione, quindicesimo giro: dalla scheda dell'articolo *Dettagli delle
  opzioni* è un bottone piccolo che apre una finestra con Opzione · SKU ·
  Prezzo · Stato — il nome e i tre puntini portano alla scheda, in fondo resta
  *Apri l'elenco completo*. La scheda dell'opzione va su due colonne: a destra
  *Identificazione* con SKU, EAN, MPN (prima *Codice del produttore*) e le
  misure, e il riquadro *Misure* sparisce. Gli attributi si dividono in
  *Opzioni di vendita*, in sola lettura perché si cambiano dalla griglia
  dell'articolo, e *Scheda tecnica* con *Aggiungi caratteristica*: tutti e due
  mostrano solo quello che è compilato. Nel riquadro *Magazzino* la rettifica
  si fa in una finestra e i movimenti sono un datatable da cinque righe, con
  *Vedi tutti i movimenti* per la storia lunga. Nella rettifica *Come la
  scrivi* si chiama *Azione* e ha tre voci — *Aggiungi* (il predefinito),
  *Sottrai* e *Imposta*: il segno lo mette l'azione, la quantità si legge
  sempre in valore assoluto.
- Scheda articolo, sedicesimo giro: il nome dell'elenco apre la **scheda in
  lettura**, con lo stesso disegno a due colonne della modifica — *Prodotto* e
  *Opzioni in vendita* a sinistra, *Foto e video*, *Stato* e *Dove si trova* a
  destra, e sotto lo spazio per le statistiche che arriveranno con gli ordini.
  Le opzioni sono la tabella di `ProductResource` ristretta all'articolo
  (Opzione · SKU · Prezzo · Stato, tre puntini alla scheda), e lo stato si
  commuta con un click: quello dell'articolo dalla pillola *Pubblicato /
  Bozza*, quello di ogni opzione dalla sua riga (*Attiva / Ferma*). Dalla
  modifica spariscono il bottone *Dettagli delle opzioni*, la sua finestra e la
  tabella scritta a mano. Nell'elenco dei prodotti le colonne sono foto, nome,
  SKU, prezzo e numero di opzioni: il prezzo porta l'euro, barra il pieno
  quando c'è lo sconto e scrive *da 19,90 €* quando le opzioni costano diverso;
  il marchio non c'è più. I movimenti della scheda dell'opzione nascono dalle
  colonne che `StockMovementResource` dichiara (`Resource::backendTable()`), e
  le foto proprie seguono l'attributo che l'articolo usa davvero, non il primo
  del negozio.
- Magazzino delle vendite e pagamenti: `Support\Stock\Allocation` mette da
  parte la merce di un carrello, la scarica alla conferma senza vendere due
  volte l'ultimo pezzo e la fa rientrare da annullamenti e resi;
  `Support\Payments\Ledger` tiene le righe di denaro, regge la notifica doppia
  del gateway e ricalcola da solo il `payment_status` dell'ordine.
- Backend *Vendite*: l'elenco **Ordini** (tre etichette — ordine, pagamento,
  evasione — filtri per i tre stati e per periodo, ricerca per numero, cliente
  ed email) e la **scheda** «Ordine <numero>» (intestazione, riepilogo IVA e
  totali in riquadri; *Righe* — con la foto del prodotto —, *Pagamenti*, *Resi*
  e *Storico* in accordion, ciascuno una tabella del core con
  `TableLayoutSchema` e `TableColumn`: `OrderItemTableResource`,
  `OrderPaymentTableResource`, `OrderReturnTableResource`,
  `OrderHistoryTableResource`, senza pagina e senza menu). Il cliente è un link
  alla sua scheda. Le **note** interna e sul documento si modificano da una
  finestra (`OrderNoteResource`); la nota del cliente resta sua. Le azioni
  stanno nei pulsanti della scheda e chiamano `Lifecycle` e `Ledger` senza
  regole proprie: *Conferma*, *Segna evaso* e *Annulla* con una finestra che
  dice cosa succede al magazzino (`OrderActions`), *Registra pagamento* in una
  finestra che posta a `OrderPaymentResource`. Il ritorno all'elenco è la
  chevron del titolo. Nell'elenco il totale è una colonna importo
  (`TableColumn::price()`, dal core: importo all'italiana, a destra, cifre
  tabulari). In alto a destra, accanto all'intestazione, stanno *Totali* e poi
  *Riepilogo IVA*. Ogni riga d'ordine salva il **nome completo** dell'articolo
  (`ProductNames::full()`: articolo e opzione) e l'**indirizzo della foto** che
  aveva al momento dell'ordine (colonna `image`); le righe già esistenti ne
  sono prive e la tabella ripiega sulla foto del catalogo.
  Le *note* di un ordine evaso e pagato sono bloccate: lucchetto al posto
  della matita, e il salvataggio le rifiuta anche se la richiesta arriva a
  mano. Il cliente nella scheda ordine è un link alla **scheda cliente**.
- Scheda cliente (sola lettura, `view` della Resource *Clienti*; il nome
  nell'elenco la apre, il pulsante *Modifica* porta al form): *Statistiche*
  (ordini, speso, scontrino medio, primo e ultimo ordine, da pagare, carrello),
  *Ordini* (tabella del core, con i tre stati), *Prodotti nel carrello* e, in
  fondo, *Coupon assegnati* (per ora solo la frase: i coupon non esistono
  ancora). *Tutti i suoi dati* è una card sempre aperta (tipo, nome, ruolo,
  stato, contatti, accesso al sito, note) scritta con `DataItem` del core; i
  **dati di fatturazione** stanno in una card a parte, in alto a destra. Gli
  *Indirizzi di consegna* hanno una card con una card per indirizzo e, da
  finestre, **aggiungi, modifica, rendi predefinito, elimina**
  (`ContactAddressResource`, pagina-form senza menu: un solo predefinito, il
  primo lo diventa da sé, l'eliminazione manda l'indirizzo nel cestino). Senza
  la funzionalità «orders» restano dati, fatturazione, indirizzi e coupon. Le
  statistiche stanno in `Support\Contacts\CustomerStats` (non contano gli
  ordini annullati o rimborsati per intero né i carrelli) e il disegno in
  `Support\Contacts\CustomerSheet`. I fornitori non hanno scheda: il nome apre
  la modifica. Dati di prova: nuova cliente *Anna Verdi* con i suoi ordini; solo
  l'ospite resta senza scheda.
- `Support\Catalog\ProductPhotos`: la foto di un articolo (opzione, colore,
  poi modello), usata dalle giacenze e dalle righe dell'ordine.
  *Metodi di pagamento* e *Conti di pagamento* in Set Up, per l'`admin`.
  *Registra reso* e la sua voce nel menu arrivano col Piano 5.
- *Movimenti* leggibili: colonne *Chi*, *Prima*, *Sede*, il documento dentro
  *Tipo* col link all'ordine, filtri *Causale*, *Sede* e *Periodo*
  (`MovementPeriod`), ricerca sull'articolo, *Ultimi movimenti* nella scheda
  della versione.
- `gestionale:demo` crea **sette ordini di prova** in tutti gli stati (in
  attesa, pagato, evaso, annullato, pagamento parziale, ospite, azienda) e li
  toglie rimettendo in magazzino la merce; con `--fresh` cancella tutto in
  ordine inverso di registrazione prima di ricreare.
- Per chi aggiorna: `PaymentMethod` e `PaymentAccount` **non cambiano tabelle**
  in questo giro; `Defaults` semina tre metodi nuovi (bonifico, contanti, carta
  — la carta nasce spenta).
- Resi (`returns`): `Returns` registra, chiude e annulla i resi; il reso nasce
  *ricevuto* e il rientro a magazzino passa da `Allocation::returnGoods()`, solo
  per le righe con la spunta. Le regole pure stanno in `ReturnRules` (massimo
  rendibile, ricarico proposto dal motivo, quantità). Un reso si annulla solo se
  nessuna riga è rientrata (`return.already_restocked`): per correggere la
  giacenza c'è la rettifica in Magazzino. Il rimborso del denaro non fa parte
  dei resi: resta al gateway con `Ledger::refund()`.
- *Registra reso*: pagina con le righe dell'ordine, motivo, ricarico e sede, voce
  nel menu dell'ordine (`OrderActions::canRegisterReturn`) e tabella *Resi
  dell'ordine* nella scheda con *Chiudi* e *Annulla*.
- `gestionale:demo` crea anche un reso di un pezzo sull'ordine evaso (con `returns`
  accesa) e lo toglie restituendo al magazzino solo quel che non era già rientrato.
- Guide: tre pagine per il commerciante (*Ordini*, *Pagamenti*, *Resi*, sezione
  *Vendite*) e *Vendite: ordini, pagamenti e resi* per gli sviluppatori.
- Personalizzazioni (funzionalità `customizations`, richiede `orders`): campi che il
  cliente compila comprando, di testo o a scelta, con sovrapprezzo. Pagina *Catalogo →
  Personalizzazioni* con le opzioni, riquadro *Personalizzazioni* nella scheda
  dell'articolo con «Nuova personalizzazione» al volo (nome, tipo Testo o Numero,
  sovrapprezzo), `Customizations` (controllo,
  sovrapprezzo, codifica per latin1), `Cart::add()` che le controlla e le prezza dal
  server e fa uscire la riga se l'anagrafica cambia, righe d'ordine, email e reso
  (online escluso). `gestionale:demo` crea «Incisione» e «Confezione regalo» e mette
  l'incisione su metà degli ordini di prova. Chi aggiorna esegua `php forge update`
  per le tre tabelle nuove.
- Multiprodotto (funzionalità `bundles`): un articolo che ne contiene altri, in tre
  modi (fisso, a scelta del cliente, misto) con gruppi di scelta, minimo e massimo e
  sovrapprezzo per opzione. Nella lista degli articoli due bottoni («Aggiungi Prodotto»,
  «Aggiungi Multiprodotto») scelgono il tipo; composizione nella scheda, valore
  dei componenti facoltativo, disponibilità dal componente più scarso. `Bundles`
  (composizione, `resolve()`, `available()`, `usedBy()`), `OrderLines` (madre e
  figlie), `Cart::add()` con `choices` e righe figlie che il server riscrive, scheda
  dell'ordine ed email con i componenti sotto la confezione, reso della confezione
  con il «rientra a magazzino» di ogni componente. Un prodotto usato da un
  multiprodotto non si elimina né si spegne (`bundle.in_use`). `gestionale:demo`
  crea tre cesti e, con la funzionalità accesa, quattro ordini (uno con un reso).
  Chi aggiorna deve lanciare `php forge update`: tre tabelle e tre colonne nuove
  su `gst_product_models`.
- Elenco e scheda degli articoli: stato **Pubblicato**/**Bozza** (non più
  visibile/nascosto), filtri per stato, marchio, categoria (con le sottocategorie)
  e, con `bundles`, tipo. La scheda in lettura ha per titolo il nome dell'articolo,
  non ha i bottoni di aggiunta in «Opzioni in vendita» e, per un multiprodotto,
  mostra il badge e il riquadro «Composizione» con la tipologia, i «Componenti» e i
  «Gruppi di scelta».
- Campagne di sconto (`discount_campaigns`, G6 piano 1): sconto in percentuale o
  in importo su categorie, tag, marchi o articoli per un periodo e per canale,
  con prezzo applicato dal carrello (`price_source = 'campaign'`, `discount_campaign_id`
  sulla riga), pagina *Promozioni → Campagne di sconto* con anteprima dei
  prodotti coinvolti e avviso di sovrapposizione, tre campagne di prova
  (`PromotionsDemo`) e guide. Chi aggiorna deve lanciare `php forge update`: cinque
  tabelle nuove e una colonna su `gst_order_items`.
- Coupon (`coupons`, G6 piano 2): codice in percentuale, importo o spedizione gratuita,
  con spesa minima, limiti totali e per cliente, solo primo ordine, clienti riservati e lo
  stesso selettore dei prodotti delle campagne. Il carrello ricontrolla il codice a ogni
  ricalcolo; gli utilizzi si contano alla creazione dell'ordine (anche con due processi
  insieme) e si liberano all'annullo o alla scadenza. Pagina *Promozioni → Coupon* con la
  tabella degli utilizzi, coupon sulla scheda dell'ordine e sulla scheda cliente, cinque
  coupon e tre ordini di prova (`PromotionsDemo`, `OrdersDemo`) e guide. Chi aggiorna deve
  lanciare `php forge update`.
- Schede di lettura per coupon e campagne: dall'elenco si apre una pagina con i dettagli, i
  canali, l'ambito e (coupon) gli utilizzi o (campagne) anteprima e avviso di sovrapposizione;
  il form di modifica ha solo i campi.
- Canali di vendita: tre funzionalità (`online_sales`, `office_sales`, `pos`, quest'ultima
  ancora non accendibile) nell'area *Canali di vendita*. Coupon, campagne e metodi di pagamento
  mostrano «Dove vale» solo con più di un canale acceso e fanno nascere i nuovi record sui
  canali accesi; senza canali sbloccati vale solo il sito. Il motore non cambia. Chi aggiorna
  deve lanciare `php forge update` (due righe nuove in `gst_features`); la funzionalità `pos`
  si chiamava «Banco».

### Corretto

- *Registra reso* dava errore 500: `Returns::returned()` chiedeva il blocco delle
  righe anche quando la pagina leggeva soltanto; ora blocca solo dentro una
  transazione.
- Tabella *Righe* dell'ordine: il nome con un trattino lungo (o altre entità) si
  leggeva come codice, `&#8212;`; ora si decodifica e si escapa una volta sola
  (`GestionaleResource::escapeStored()`).
