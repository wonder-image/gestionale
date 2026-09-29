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
