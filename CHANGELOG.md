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
  (`TableColumn::money()`, dal core).
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
