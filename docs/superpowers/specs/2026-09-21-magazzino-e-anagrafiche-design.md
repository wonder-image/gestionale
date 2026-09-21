# G2b — Magazzino base e anagrafiche

- **Sotto-progetto:** G2b, secondo pezzo di G2 (il primo era G2a, il catalogo)
- **Stato:** da approvare
- **Documento di riferimento:** [architettura](2026-09-11-gestionale-ecommerce-architettura-design.md) §4.3, §4.4, §3.4
- **Dipende da:** G1 Fondamenta (codici, impostazioni, sedi, errori, hook, attività),
  G2a Catalogo e G2a-bis (prodotti, versioni in vendita)

## Contesto

Il catalogo esiste e si compila bene. Ma un articolo che non dice quanti pezzi
ci sono non si può vendere: gli ordini di G4 devono scaricare qualcosa, e la
vetrina di E1 deve sapere cosa mostrare come esaurito.

Insieme al magazzino arrivano le anagrafiche, per la stessa ragione: un ordine
ha sempre un cliente, e un carico avrà sempre un fornitore.

Quello che si decide qui lo useranno tutti i sotto-progetti dopo — G3 con
carichi e trasferimenti, G4 con vendite e resi, G5 con i componenti del
multiprodotto. Una giacenza sbagliata è la cosa che un commerciante scopre per
ultima e perdona per ultima.

## Obiettivo

Sapere in ogni momento quanti pezzi ci sono di ogni versione in vendita, perché
sono quelli, e chi sono i clienti e i fornitori.

Alla fine di G2b il commerciante deve poter caricare la giacenza iniziale di
tutto il catalogo in una schermata, correggere un pezzo rotto lasciando scritto
il perché, farsi avvisare quando sta per finire qualcosa, e avere la sua
rubrica di clienti e fornitori pronta per gli ordini.

## Non obiettivi

| Fuori da G2b | Dove |
|---|---|
| Più sedi e trasferimenti | G3 (`multi_location`) |
| Acquisti, documenti di carico, costi per fornitore, valore del magazzino | G3 (`purchasing`) |
| Lotti e scadenze | G3 (`batch_tracking`) |
| Prenotare e rilasciare la giacenza (il carrello) | G4 |
| Scarico alla conferma dell'ordine, resi con ricarico | G4 |
| Vendita senza giacenza | G4 (`backorders`) |
| Componenti del multiprodotto | G5 |
| Listini e condizioni di pagamento del cliente | G4, G6 |
| Account del cliente sul sito | E1 |
| Inventario con conteggio guidato, ordini a fornitore | funzionalità future |
| Import di catalogo, clienti e giacenze da file | fuori perimetro (D5) |

## Design

### 1. Tabelle

Tutte con prefisso `gst_` e **nessuna sincronizzazione**: giacenze, movimenti e
anagrafiche sono la storia di quell'ambiente, non configurazione che viaggia
con il deploy.

| Tabella | Colonne | Note |
|---|---|---|
| `stock` | `product_id`, `location_id`, `batch_id`, `supplier_id`, `quantity` (10,3) | una riga per combinazione; indice **unico** sulle quattro colonne |
| `stock_movements` | `code` (`mov_`), `product_id`, `location_id`, `batch_id`, `supplier_id`, `type`, `reason`, `quantity` (con segno), `quantity_before`, `quantity_after`, `unit_cost`, `reference_type`, `reference_id`, `source`, `user_id`, `note` | storia immutabile; il quando è la colonna `creation` del core |
| `stock_reservations` | `product_id`, `location_id`, `quantity`, `order_id`, `order_item_id`, `expires_at`, `released_at` | nasce vuota: la riempie il carrello in G4 |
| `stock_alerts` | `product_id`, `location_id`, `threshold`, `quantity_at_alert`, `notified_at`, `resolved_at` | un avviso aperto per prodotto; `location_id` resta a zero perché la soglia vale sul totale |
| `contacts` | `code` (`con_`), `is_customer`, `is_supplier`, i campi di `AddressExtension::billing()`, `email`, `user_id`, `price_list_id`, `payment_method_id`, `payment_term_id`, `color`, `note`, `custom_data`, `active` | |
| `contact_addresses` | `contact_id`, `label`, i campi di `AddressExtension::simple()` più destinatario e telefono, `is_default`, `position` | |

**`batch_id` e `supplier_id` nascono adesso**, anche se restano a zero fino a
G3: stanno nell'indice unico e in ogni lettura di `stock`, e aggiungerli dopo
vorrebbe dire rifare l'indice e tutte le query che ci passano.

**Le colonne di listino e pagamento** (`price_list_id`, `payment_method_id`,
`payment_term_id`) nascono senza nessun campo nel form, finché non ci sarà
niente da scegliere. È la stessa regola con cui `min_stock_quantity` è nato in
G2a.

**Sede.** `location_id` punta a `gst_locations` (G1) ed è sempre la sede
principale finché `multi_location` è bloccata: la colonna c'è, il campo no
(D20).

### 2. Movimenti e causali

L'enum dei tipi nasce **completo** — `sale`, `sale_cancel`, `return`,
`purchase`, `adjustment`, `transfer_in`, `transfer_out` — perché cambiare un
enum con dentro dei dati è la migrazione che si rimanda sempre. Ma **in G2b
l'unico tipo prodotto è `adjustment`**: gli altri li scriverà chi li genera, in
G3 e in G4.

Causali (`reason`): `damaged`, `gift`, `internal_use`, `expired`, `inventory`,
`initial_stock`, `other`.

`reference_type` + `reference_id` legano il movimento al documento che lo ha
causato (ordine, DDT, reso, documento di magazzino): in G2b restano vuoti.
`source` dice da dove arriva (`backend`, `online`, `import`), `user_id` chi
l'ha fatto.

Un movimento non si modifica e non si cancella mai. Una rettifica sbagliata si
corregge con un'altra rettifica.

### 3. Una sola porta di scrittura

`Support\Stock\Stock::apply()` è **l'unico codice che tocca `gst_stock`**.
Dentro `Transaction::run()`:

1. legge (o crea) la riga di `gst_stock` con `FOR UPDATE`;
2. calcola `quantity_before` e `quantity_after`;
3. scrive il movimento;
4. aggiorna la giacenza;
5. apre o chiude l'avviso di scorta minima.

G3 e G4 si agganceranno **qui dentro**, non accanto: è l'unico modo perché
"giacenza" e "somma dei movimenti" non divergano mai.

Rifiuta con `UserError` — il tipo che il backend trasforma in messaggio — la
quantità a zero, il prodotto che non esiste e, finché `backorders` è bloccata,
il risultato negativo.

### 4. Quattro classi pure

Senza database, provate con gli array: è dove sta la logica che tutti gli altri
sotto-progetti rileggeranno.

| Classe | Cosa risponde |
|---|---|
| `Stock\Reasons` | quali sono le causali, come si chiamano, quali ammettono segno libero |
| `Stock\Availability` | disponibile = giacenza − prenotazioni attive (una prenotazione scaduta o rilasciata non conta) |
| `Stock\LowStock` | dati soglia, disponibile e avviso aperto: quale avviso aprire, quale chiudere, quale lasciare stare |
| `Stock\Adjustment` | dalla quantità scritta dal commerciante al movimento: delta, prima, dopo |

### 5. Le pagine del magazzino

Nuova sezione di menu **Magazzino**: `Giacenze · Movimenti`. G3 ci aggiungerà
Carichi e Trasferimenti.

**Giacenze** è una **pagina-form** (`isFormPage()`, come il pannello
Funzionalità di G1), non un elenco CRUD: è l'unico modo per scrivere le
quantità riga per riga e salvarle in blocco, che è come si carica un catalogo
la prima volta.

- In cima la **causale della schermata**, predefinita `inventory`.
- Una riga per versione in vendita: foto, articolo, versione, SKU, **giacenza
  scrivibile**, scorta minima in sola lettura, link *Rettifica*.
- *Prenotato* e *Disponibile* sono due colonne in più che compaiono **solo con
  la funzionalità Ordini sbloccata**, perché è il carrello a riempirle: in G2b
  sarebbero due colonne sempre a zero. Il calcolo però esiste già ed è provato
  (`Stock\Availability`).
- Salvando, ogni riga cambiata diventa un movimento; tutte dentro una
  transazione sola. Le righe non toccate non producono niente.
- Ricerca (nome, SKU, EAN), filtri (categoria, marchio, solo sotto scorta) e
  paginazione a 50 righe **li scrive il modulo**: la pagina-form non eredita
  quelli dell'elenco del core. I filtri sono link, perché un modulo GET dentro
  il form del salvataggio non si può annidare; la casella di ricerca naviga con
  poche righe di script.

**Rettifica** è una seconda pagina-form, fuori dal menu, con `?versione=<id>`:
giacenza attuale, nuova quantità *oppure* `+/−`, causale, nota. È la porta del
caso singolo, quella che lascia scritto il perché.

**Movimenti** è un elenco normale del core, in **sola lettura** — niente
aggiungi, niente modifica, niente elimina: data, articolo e versione, tipo,
causale, quantità con segno, prima → dopo, chi, riferimento, nota. Filtri per
versione, causale, tipo e periodo.

### 6. Il magazzino dentro il catalogo

Nessuna pagina nuova, tre innesti:

- **Scheda della versione** (`app/gestionale/versioni`): riquadro *Magazzino*
  con giacenza e scorta minima (prenotato e disponibile con *Ordini*), il
  pulsante Rettifica e gli **ultimi dieci movimenti** di quella riga.
- **Scheda del prodotto:** colonna *Giacenza* nella griglia "Versioni in
  vendita", in sola lettura.
- **Prodotto a versione unica:** la giacenza compare nel riquadro *Prodotto*,
  con il pulsante Rettifica accanto. La parola "versione" non si legge da
  nessuna parte (D20).

### 7. Anagrafiche

Sezione di menu **Anagrafiche** con `Clienti` e `Fornitori`: **due elenchi
sulla stessa tabella, una scheda sola**. Chi è cliente e fornitore compare in
tutti e due gli elenchi e si modifica in un posto solo. Con `purchasing`
bloccata la voce Fornitori non esiste e l'interruttore "è anche fornitore"
nemmeno.

`AddressExtension::billing()` del core porta tipo (privato/azienda), nome e
cognome, ragione sociale, codice fiscale e partita IVA **già validati**, SDI,
PEC, indirizzo e telefono. Il modulo aggiunge ruoli, email, `user_id` (l'account
del sito, che collegherà E1), colore, note, `custom_data` e `active`. I campi
aziendali compaiono solo per il tipo `business`.

`contact_addresses` è il repeater **Indirizzi di consegna** dentro la scheda:
etichetta, indirizzo, destinatario, telefono, predefinito.

**Una scheda = una identità fiscale.** Partita IVA, codice fiscale ed email,
quando compilati, sono unici; il rifiuto dice **quale scheda** li ha già, così
si va a cercarla invece di indovinare. L'unicità la controlla la Resource, come
SKU ed EAN in G2a: il framework scrive stringhe vuote e non `NULL`, e un indice
unico farebbe scontrare tutte le schede senza partita IVA.

Un'anagrafica usata da un documento non si elimina: si disattiva. In G2b non
c'è ancora niente che la usi, ma `assertDeletable()` nasce adesso, così G4 deve
solo aggiungere la sua condizione.

### 8. Avvisi di scorta minima (`low_stock_alerts`)

La soglia è il campo *Scorta minima* sulla versione, nato in G2a e **visibile
solo** a funzionalità sbloccata. Vale sul disponibile totale del prodotto,
somma di tutte le sedi.

- `Stock::apply()` apre la riga di `gst_stock_alerts` quando si scende sotto
  soglia e la chiude (`resolved_at`) quando si risale: l'avviso **non si
  ripete** finché il prodotto resta sotto.
- L'attività `gestionale.stock_alerts`, ogni 15 minuti e **spenta alla
  nascita** come la coda delle immagini, prende gli avvisi con `notified_at`
  vuoto e manda **una email sola** con l'elenco ai destinatari scelti nelle
  impostazioni del commerciante (*Destinatari degli avvisi*, impostazione nuova
  di questo sotto-progetto; vuota, nessuna email parte).
- L'email non parte mai dentro il salvataggio: dieci rettifiche di fila non
  fanno dieci email, e il salvataggio non dipende dal server di posta. Vale
  anche quando a scaricare sarà un ordine online (G4).
- La view dell'email sta nel modulo ed è sovrascrivibile dal sito
  (`custom/modules/gestionale/view`, §7 dell'architettura).

**Nella home:** riquadro *Sotto scorta* con i prodotti da riordinare; le
giacenze negative entrano nel riquadro *Da controllare* di G1.

### 9. Dati di prova

`php forge gestionale:demo` si arricchisce di:

- una **giacenza iniziale** sulle versioni dei tre articoli di prova, con
  movimenti `initial_stock`, compresa una versione sotto scorta minima per far
  vedere l'avviso;
- **quattro anagrafiche**: un cliente privato, un cliente azienda con partita
  IVA e PEC, due fornitori, con i loro indirizzi di consegna.

`--fresh` le toglie insieme ai loro movimenti.

### 10. Documentazione

- **Commerciante:** *Giacenze e rettifiche*, *Movimenti*, *Clienti e
  fornitori*.
- **Sviluppatore:** *Magazzino* — la porta unica `Stock::apply()`, le quattro
  classi pure, come ci si agganciano G3 e G4, perché nessun altro codice deve
  scrivere su `gst_stock`.

## Validazione

1. Test unitari sulle quattro classi pure e di integrazione su `Stock::apply()`
   (transazione annullata, avviso aperto e chiuso, due scritture contemporanee);
   CI verde.
2. `forge update` crea le tabelle; una seconda esecuzione non cambia niente.
3. Nel browser: la giacenza iniziale di più righe si carica da *Giacenze* in un
   salvataggio solo, e i movimenti nati sono quelli.
4. Nel browser: una rettifica singola con nota si vede nei Movimenti e nella
   scheda della versione.
5. Una giacenza sbagliata si spiega leggendo i Movimenti, senza aprire il
   database.
6. Portando una versione sotto la sua scorta minima nasce l'avviso, e
   l'attività manda **una** email con l'elenco.
7. Un cliente azienda con partita IVA non valida viene rifiutato con un
   messaggio; una partita IVA già usata dice di chi è.
8. Con *Acquisti* bloccata la parola "fornitore" non compare da nessuna parte.
9. Con *Avvisi di scorta minima* bloccata il campo *Scorta minima* non compare
   e nessuna email parte.
10. `gestionale:demo` riempie giacenze e anagrafiche su un sito vuoto;
    `--fresh` le toglie.

## Decisioni di questa spec

| # | Decisione |
|---|---|
| G2b.1 | `Stock::apply()` è **l'unica** porta di scrittura di giacenze e movimenti: G3 e G4 si agganciano lì dentro |
| G2b.2 | `batch_id` e `supplier_id` nascono ora, nell'indice unico, anche se restano a zero fino a G3 |
| G2b.3 | L'enum dei tipi di movimento nasce completo; in G2b l'unico prodotto è `adjustment` |
| G2b.4 | Prenotazioni: tabella e regola del disponibile adesso, `reserve()`/`release()` in G4 con il carrello; le colonne *Prenotato* e *Disponibile* compaiono solo con *Ordini* sbloccata |
| G2b.5 | *Giacenze* è una pagina-form con la quantità scrivibile riga per riga e una causale per schermata; ricerca, filtri e paginazione li scrive il modulo |
| G2b.6 | La rettifica singola, con nota, è una pagina-form a parte richiamata dalla riga e dalla scheda |
| G2b.7 | I movimenti sono in sola lettura: non si modificano e non si cancellano, si correggono con un'altra rettifica |
| G2b.8 | Due voci sotto *Anagrafiche* sulla stessa tabella e sulla stessa scheda; chi è cliente e fornitore compare in tutte e due |
| G2b.9 | L'email di scorta minima la manda un'attività che raggruppa, mai il salvataggio |
| G2b.10 | Le colonne di listino e pagamento nascono senza campo, come `min_stock_quantity` in G2a |
| G2b.11 | Partita IVA, codice fiscale ed email unici quando compilati, controllati dalla Resource; un'anagrafica usata si disattiva, non si elimina |
| G2b.12 | Magazzino e anagrafiche **non si sincronizzano**: sono la storia di quell'ambiente |

## Piani

1. **Fondamenta del magazzino:** le quattro tabelle, `Stock::apply()` con la
   transazione e il `FOR UPDATE`, le quattro classi pure, l'elenco *Movimenti*.
2. **Giacenze e rettifiche:** la pagina-form *Giacenze* con ricerca, filtri e
   paginazione, la pagina *Rettifica*, i tre innesti nel catalogo, la giacenza
   nei dati di prova.
3. **Anagrafiche:** clienti e fornitori con gli indirizzi, unicità e
   disattivazione, riquadro della home, dati di prova, guide.
4. **Avvisi di scorta minima:** campo, avvisi, attività ed email raggruppata,
   riquadro *Sotto scorta*, guide, chiusura di G2b.
