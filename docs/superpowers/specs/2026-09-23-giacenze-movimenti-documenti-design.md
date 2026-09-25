# G2b-bis — Giacenze, movimenti e documenti di magazzino

- **Sotto-progetto:** G2b-bis, revisione di G2b prima di G3
- **Stato:** approvata il 2026-09-25 (sezioni 1 e 2 il 2026-09-23, riviste il 2026-09-24; 3, 4 e 5 il 2026-09-24); sei piani
- **Documento di riferimento:** [architettura](2026-09-11-gestionale-ecommerce-architettura-design.md) §4.1, §4.3;
  [G2b](2026-09-21-magazzino-e-anagrafiche-design.md) §5, §6
- **Dipende da:** G2b (giacenze, movimenti, rettifica, `Stock::apply()`), G1 (numerazione, log degli stati, codici)

## Contesto

Le pagine *Giacenze* e *Movimenti* sono difficili da capire:

- *Giacenze* è una pagina-form con una casella per riga, una causale per tutta
  la schermata, filtri e paginazione scritti a mano; la *Rettifica* porta su
  un'altra pagina e poi indietro.
- *Movimenti* ha le colonne in un ordine che non si legge, non dice chi ha
  fatto l'operazione né la giacenza di prima, mostra la data grezza e cerca
  solo per codice e nota.
- Non esiste un pannello di carico e scarico: la spec di architettura lo
  metteva in G3 con `purchasing`.

Precisazione del 2026-09-24: *Giacenze* serve a capire quanti pezzi ci sono,
sede per sede, non a fare carichi e scarichi. I pezzi cambiano altrove: dai
documenti di magazzino (§4) e dalla rettifica nella scheda della versione.

## Decisioni prese nel brainstorming

| Tema | Scelta | Alternative scartate |
|---|---|---|
| Carico e scarico | Versione **base** adesso, sempre disponibile; G3 (`purchasing`) aggiunge fornitore, costi e documento del fornitore | aspettare G3; farlo completo adesso |
| Colonne dei Movimenti | **Tipo e causale separati**: Quando · Articolo · (Sede) · Tipo · Causale · Pezzi · Prima · Dopo · Chi · Nota · ⋯ | tipo e causale in una colonna |
| Documento nei Movimenti | ***Tipo* mostra il documento e il numero** («Scarico 2026/090003»); il documento si apre dal menu ⋯ in fondo alla riga | il tipo registrato (uno scarico si leggerebbe «Rettifica»); link sul numero dentro la cella |
| Conta di magazzino | **Terzo documento: Inventario**, accanto a Carico e Scarico; su ogni riga i pezzi contati, la differenza diventa un movimento `adjustment` con causale *Inventario* | tenere la modifica in blocco dentro Giacenze |
| Stati dei documenti | quelli già scritti in architettura §4.3 e §4.1: `draft`, `completed`, `cancelled`, log in `stock_document_status_logs`, storno all'annullamento, numerazione `{YYYY}/{mm}{nnnn}`, codice `stk_` | — |
| Scopo di Giacenze | **solo consultazione**: quanti pezzi ci sono e dove; nessun numero si cambia da qui | modifica in blocco (com'era) |
| Sedi in Giacenze | **una colonna per sede più *Totale***, solo quando le sedi sono più di una | filtro *Sede*; una riga per sede |
| Rettifica | **resta nella scheda della versione**, sulla sua pagina, come oggi | pagina-form in popup aperta da Giacenze (scelta il 2026-09-23, tolta il 2026-09-24 insieme alla rettifica in Giacenze); rettifica in riga |
| Dati di Giacenze | **Resource sul Model Product** più colonne calcolate con `TableLayoutSchema::select()` | tabella scritta a mano; vista SQL |
| Numero del documento | **alla conferma**, una numerazione per tipo | alla creazione della bozza: eliminarla lascerebbe un buco |
| Conferma dell'inventario | **differenza *contati − attesi***, con gli attesi letti quando si salva il numero contato | la giacenza diventa il numero contato: si perderebbero le vendite fatte tra la conta e la conferma |
| Storni | **`reference_type` = `stock_document_cancel`** | riconoscerli dal segno: nell'inventario la differenza va nei due versi |
| Elenchi dei documenti | **uno per tipo**: Carichi, Scarichi, Inventari, tre Resource sul Model `StockDocument` | un solo elenco *Documenti* con il filtro *Tipo* |
| Righe dei documenti | **una casella «Cerca o scansiona»** sopra una tabella compatta, componenti del modulo | il repeater del core, un riquadro per riga: 300 righe d'inventario diventano lunghissime, e le righe non si scrivono a mano; una ricerca dentro ogni riga: nel backend non c'è |

## Design

### 1. Aggiunte al core (approvata, rivista il 2026-09-24)

**Azioni-array.** La tabella del core sa già disegnare voci di menu fatte ad
array (`Backend\Table\Field::actionButton()`):

- `label`, anche diversa riga per riga: un array indicizzato dai valori della
  colonna che ha il nome della voce (getrix lo usa per *In vendita* /
  *Venduto*); se il valore non c'è, la voce non compare;
- `href` con i segnaposto `{colonna}` presi dalla riga;
- `filter.row`, che mostra la voce solo su alcune righe.

`TableColumn::actions()` e `ResourceTableRenderer::resolvedActions()` però le
convertono in `true` e le perdono: devono lasciarle passare. Per esempio
`['movimenti' => ['label' => 'Movimenti', 'href' => '…?versione={id}']]`.
Nello stesso punto l'attributo `href` passa da `htmlspecialchars()`: oggi i
valori della riga entrano nell'HTML così come sono. Non `rawurlencode()`,
perché getrix mette in `{url}` un indirizzo intero.

**`TableLayoutSchema::select(string $sql)`.** Porta nello schema il
`Table::select()` che oggi esiste solo per le Table dirette; il frammento
viaggia firmato come gli altri. Le colonne calcolate si dichiarano come alias
e restano fuori dalla ricerca predefinita.

**Filtri e ricerca.** `filterQuery()` e le correzioni a `FilterCustom` sono
descritti in §2, la relazione annidata nella ricerca in §3: stanno dove
servono, ma vanno anche loro nel core.

**Documentazione e test.** `docs/app/concetti/tabelle/`; test per le
azioni-array (voce che passa, voce nascosta da `filter.row` o da
un'etichetta mancante, `href` con apici e `<` nei valori), per `select()`,
per i filtri e per la ricerca annidata.

La **pagina-form in popup**, approvata il 2026-09-23, non si fa: serviva solo
alla rettifica dentro Giacenze, che non c'è più (§2). Con lei cadono
`FormPageRefusal`, `formModalUrl()` e `formPageRedirect()`;
`StockAdjustmentResource` tiene `refuse()` e `goBack()` come oggi.

### 2. Giacenze (approvata, rivista il 2026-09-24)

`StockLevelResource` passa da pagina-form a **elenco datatable sul Model
`Product`**, stesso indirizzo `app/gestionale/giacenze`. Una riga per
versione, anche a zero e anche ferma. Serve a capire quanti pezzi ci sono e
in quale sede: **solo consultazione**, niente *Aggiungi*, niente modifica in
riga, niente rettifica, niente API.

| Colonna | Contenuto |
|---|---|
| Foto | la prima foto pronta: versione, poi variante, poi articolo |
| Articolo | «Articolo — Versione»; con una sola versione solo il nome dell'articolo (D20) |
| SKU | |
| *una per sede* | solo con più sedi (sotto); il titolo è il nome della sede |
| Giacenza | ordinabile; con più sedi si chiama *Totale*; sotto scorta: numero in rosso e badge *sotto scorta* |
| Scorta minima | solo con `low_stock_alerts` |
| Impegnati · Disponibili | solo con `orders`; totali di tutte le sedi |
| ⋯ | Movimenti (già filtrati sulla versione), Apri la versione |

**Sedi.** La regola sta in un solo punto, `Locations::shown()`, e vale anche
per §3 e §4:

- con `multi_location` bloccata c'è solo la sede principale e la sede non
  compare (architettura §4.3, D20): una colonna *Giacenza* con il totale;
- con `multi_location` attiva, le sedi da mostrare sono quelle con
  `has_stock`, nell'ordine della pagina *Sedi* (posizione, poi id), più ogni
  altra sede che ha ancora pezzi, così le colonne sommano sempre al *Totale*;
- «più sedi» vuol dire che le sedi da mostrare sono almeno due; con una sola,
  la tabella è quella con la colonna *Giacenza*.

La scorta minima è della versione, non della sede: il rosso e il badge stanno
sul *Totale*. Ogni colonna di sede è ordinabile e mostra gli zeri in grigio.

**Dati.** Con `select()` si aggiungono le colonne calcolate `model_name`,
`stock_quantity`, una `stock_loc_<id>` per ogni sede da mostrare e, secondo
le funzionalità, `stock_reserved`, `stock_available`, `stock_alert`, tutte
ordinabili. Le formule SQL stanno in un solo punto, accanto a
`Levels`/`Availability`; un test d'integrazione controlla che diano gli
stessi numeri di `Levels::forProducts` e delle righe di `gst_stock` sede per
sede. Le celle numeriche passano da un formatter (il core mostrerebbe lo zero
come cella vuota), allineate a destra, `tabular-nums`, interi senza decimali.

**Nessuna scrittura.** Da Giacenze non cambia nessun numero: la rettifica
resta nella scheda della versione, sulla sua pagina; carico, scarico e conta
passano dai documenti (§4).

**Ricerca e filtri.**

- Ricerca: nome, SKU ed EAN della versione, più il nome dell'articolo tramite
  la relazione `product_model_id` (già supportata dal core).
- *Stato* (in vendita / ferma): `filterCustom` esistente su `active`.
- *Marchio*, *Categoria* (sottocategorie comprese), *Scorta* (solo sotto
  scorta): nuova aggiunta al core `filterQuery($label, $key, $options, Closure
  $where)`. La closure riceve solo valori presenti tra le opzioni e restituisce
  la condizione SQL, firmata come gli altri filtri.
- Correzioni a `FilterCustom`: escaping dei valori (oggi un GET costruito
  apposta entra nell'SQL così com'è) e parentesi attorno agli `OR` dei filtri
  multipli.

**Cosa sparisce.** Le caselle per cambiare la giacenza e il salvataggio in
blocco con la causale di schermata (la conta passa al documento *Inventario*,
§4), la barra dei filtri scritta a mano e la paginazione a mano.
`lowStockUrl()` resta — la usano l'email e il riquadro degli avvisi di scorta
minima — e punta all'elenco con il filtro *Scorta*.

**Test.** `StockPagesTest` (senza database e d'integrazione) riscritto:
colonne per funzionalità; colonne delle sedi (assenti con `multi_location`
bloccata o con una sola sede, presenti con due; una sede senza `has_stock` ma
con pezzi compare; i numeri per sede uguali alle righe di `gst_stock`, la loro
somma uguale al *Totale*); colonne calcolate contro `Levels`; i filtri;
`lowStockUrl()`.

I multiprodotti (G5) non hanno giacenza propria: li toglierà G5.

### 3. Movimenti (approvata)

`StockMovementResource` resta un elenco del core in sola lettura, stesso
indirizzo `app/gestionale/movimenti`, dal movimento più recente.

| Colonna | Contenuto |
|---|---|
| Quando | `creation` come «24/09/2026 14:32» (`datetime()` del core); ordinabile |
| Articolo | «Articolo — Versione» come in Giacenze (D20) |
| Sede | solo con più sedi (regola di §2), perché *Prima* e *Dopo* sono la giacenza di quella sede |
| Tipo | per un movimento nato da un documento, il documento e il suo numero: «Scarico 2026/090003»; per gli altri l'etichetta del tipo (*Rettifica*; con G3 e G4 *Vendita*, *Reso*, *Trasferimento*…) |
| Causale | l'etichetta della causale, vuota se non c'è |
| Pezzi | con il segno: «+10», «−3» |
| Prima · Dopo | `quantity_before` e `quantity_after` |
| Chi | il nome dell'utente; senza utente, la provenienza (`source`): «Importazione» per `import`, «Sistema» per le altre; G3 e G4 aggiungono le etichette delle loro provenienze |
| Nota | |
| ⋯ | *Visualizza carico*, *Visualizza scarico* o *Visualizza inventario*, solo sulle righe nate da un documento; *Apri la versione* |

Il documento sta dentro *Tipo* perché uno scarico è registrato come
`adjustment` (architettura §4.3): il tipo registrato si leggerebbe
«Rettifica» e confonderebbe. *Articolo* e *Tipo* sono testo, come in
Giacenze: la versione e il documento si aprono dal menu ⋯. La voce del
documento usa le azioni-array di §1: una colonna calcolata con il tipo di
documento dà l'etichetta (*Visualizza carico*…), e sulle righe senza documento
l'etichetta manca e la voce non compare. Come si leggono gli storni di un
documento annullato lo dice §4.

Le celle numeriche seguono le regole di Giacenze: allineate a destra,
`tabular-nums`, interi senza decimali.

**Dati.** I nomi di articolo e versione arrivano con `select()`, con le stesse
formule di Giacenze; il tipo e il numero del documento anche. Sedi e utenti si
leggono una volta sola per pagina. Mai una query per riga.

**Ricerca.** Codice, nota, SKU, EAN e nome della versione, nome
dell'articolo. Oggi il core cerca nelle tabelle collegate a un passo solo
(movimento → versione). Nuova aggiunta al core: un descrittore di relazione in
`searchFields()` può contenerne un altro (`relations`), così la ricerca arriva
dalla versione all'articolo. La validazione dei descrittori scende anche nelle
relazioni annidate.

**Filtri.**

- *Tipo*: `filterQuery` di §2, con la stessa regola della colonna. *Carico*,
  *Scarico* e *Inventario* guardano il documento; *Rettifica* prende i
  movimenti `adjustment` senza documento. I tipi di G3 e G4 compaiono con le
  loro funzionalità.
- *Causale*: il `filterCustom` di oggi.
- *Sede*: solo con più sedi.
- *Periodo*: `filterQuery` con scelte pronte: oggi, ultimi 7 giorni, questo
  mese, mese scorso, quest'anno. Le date le calcola PHP con il fuso del sito,
  in una classe pura provata da sola.
- `?dal=` e `?al=` spariscono: nessun link li usa e il *Periodo* li
  sostituisce.

**Filtro sulla versione.** `?versione=<id>` resta: ci arrivano Giacenze
(⋯ *Movimenti*) e la scheda della versione (*Vedi tutti i movimenti*). Con il
filtro attivo il titolo diventa «Movimenti di Maglia — Rossa» (D20: con una
sola versione, «Movimenti di Maglia») e compare il pulsante *Mostra tutti*.

**Scheda della versione.** La tabellina *Ultimi movimenti* scrive le celle
come l'elenco: data leggibile, *Tipo* con il documento, *Sede* con più sedi.
Oggi mostra la data com'è nel database («2026-09-24 14:32:10»).

**Test.** `StockMovementResourceTest` riscritto:

- *Sede* solo con più sedi;
- le voci di *Tipo* e *Periodo* diventano l'SQL giusto;
- la voce del documento nel menu ⋯ solo sulle righe con documento;
- *Chi* senza utente;
- la ricerca per nome dell'articolo, con il test del core per la relazione
  annidata.

### 4. Documenti di magazzino: Carico, Scarico, Inventario

#### 4.1 Dati, stati e movimenti (approvata)

Le tabelle sono quelle dell'architettura (§4.3), con il prefisso del modulo:
`gst_stock_documents`, `gst_stock_document_items` e
`gst_stock_document_status_logs` (un `StatusLog` di G1). Con più sedi (regola
di §2) il documento chiede la sede; senza, è la sede principale e non compare.

**Tipi.** `receipt` (*Carico*), `issue` (*Scarico*) e il nuovo `stocktake`
(*Inventario*); `transfer` arriva con G3. Tutti e tre sono sempre
disponibili: l'architettura §4.3 legava carico e scarico a `purchasing` e si
aggiorna con questa spec (§5.1).

**Colonne.** Tutte quelle dell'architettura nascono subito, come G2b ha fatto
con lotto e fornitore su giacenze e movimenti. `to_location_id`,
`supplier_id`, `supplier_document_number`, `supplier_document_date` e
`total_cost` sul documento, `batch_id`, `supplier_id`, `unit_cost` e
`line_total` sulle righe restano vuote e nascoste finché G3 non le usa. Sulle
righe c'è in più `expected_quantity`, per l'inventario, che entra anche
nell'architettura.

- `quantity` è sempre il numero scritto sulla riga — pezzi caricati, scaricati
  o contati — e non ha segno: il verso lo dà il tipo di documento. In un
  inventario una riga non ancora contata ha `quantity` vuota, e
  `expected_quantity` pure.
- `user_id` è chi ha creato il documento; chi lo conferma e chi lo annulla
  stanno nel log degli stati.
- Una versione compare una volta sola per documento.
- Le quantità accettano i decimali come la rettifica.

**Causale.** Solo lo scarico ne ha una, sul documento, obbligatoria:
*Danneggiato*, *Scaduto*, *Regalo*, *Uso interno*, *Altro*. Il carico non ne
ha; l'inventario usa sempre *Inventario*. `Reasons` dice quali causali vanno
con quale documento.

**Stati.** `draft` (*Bozza*), `completed` (*Confermato*), `cancelled`
(*Annullato*); ogni passaggio scrive una riga nel log con `StatusLogger`.

- La bozza non muove niente: si modifica e si può eliminare. Un documento
  confermato o annullato non si elimina.
- Il numero arriva alla conferma, con `DocumentSequences::next()` e una
  sequenza per tipo (`stock_receipt`, `stock_issue`, `stock_stocktake`):
  eliminare una bozza non lascia buchi. «Carico 2026/090001» e «Scarico
  2026/090001» possono convivere, perché il tipo accompagna sempre il numero.
- `date` è il giorno della conferma, come per i suoi movimenti: la bozza non
  ha un campo data.
- Un documento confermato non si modifica: si annulla e se ne fa un duplicato
  da correggere.

**Conferma.** Una transazione sola: numero, stato, log e un `Stock::apply()`
per riga. O entra tutto o niente. Uno scarico che porterebbe sotto zero si
ferma con l'errore di oggi (`stock.insufficient`, che nomina la versione) e il
documento resta in bozza. La regola è quella di `Stock::apply()`: il muro vale
finché la vendita sotto zero di G4 (`backorders`) è bloccata.

| Documento | Movimento |
|---|---|
| Carico | `purchase`, pezzi in più |
| Scarico | `adjustment` con la causale del documento, pezzi in meno |
| Inventario | `adjustment` con causale `inventory`: la differenza *contati − attesi*; nessun movimento se è zero |

Tutti hanno `reference_type` = `stock_document`, `reference_id` = il
documento, la sede del documento e, in `user_id`, chi conferma.

**Annullamento.** Solo di un documento confermato, in una transazione sola:
per ogni movimento della conferma uno storno con lo stesso tipo, la stessa
causale e il segno opposto, con `reference_type` = `stock_document_cancel`.
Se lo storno di un carico porterebbe sotto zero, perché i pezzi sono già
stati venduti, si ferma con lo stesso errore e il documento resta confermato.

Nei Movimenti *Tipo* legge «Annullamento del carico 2026/090003» e il menu ⋯
apre il carico. Il segno non basterebbe a riconoscere gli storni:
nell'inventario la differenza va in tutte e due le direzioni. Il filtro *Tipo*
di §3 prende anche gli storni del suo documento.

**Inventario.** La riga ricorda in `expected_quantity` la giacenza della
versione, in quella sede, nel momento in cui la bozza salva il numero contato;
cambiando il numero, o la sede della bozza, la si rilegge. Alla conferma il
movimento è *contati − attesi*, applicato alla giacenza di quel momento: una
vendita fatta fra il salvataggio della conta e la conferma resta. Una vendita
fatta mentre si conta, prima di salvare, no: per questo la guida consiglia di
salvare la bozza man mano, per esempio a ogni scaffale. Una riga senza numero
contato non si tocca, come oggi: casella vuota non vuol dire zero
(`Stocktake`).

**Avvisi di scorta minima.** Come oggi: li registra `Stock::apply()` e li
manda il task pianificato, un'email sola con l'elenco.

#### 4.2 Pagine (approvata)

**Menu.** *Magazzino* diventa Giacenze · Carichi · Scarichi · Inventari ·
Movimenti, con ordine 10, 20, 30, 40, 50. Carichi, Scarichi e Inventari sono
tre Resource sullo stesso Model `StockDocument`, come Clienti e Fornitori su
`Contact`: ognuna vede solo il suo tipo e ha il suo bottone, «Nuovo carico»,
«Nuovo scarico», «Nuovo inventario». Indirizzi `app/gestionale/carichi`,
`app/gestionale/scarichi` e `app/gestionale/inventari`; permessi come il
resto di *Magazzino* (`admin`, `administrator`); niente API.

**Elenchi.** Tabella del core, ordinata per *Data*, i più recenti in cima.

| Colonna | Contenuto |
|---|---|
| Numero | «2026/090003». Una bozza ha al suo posto il badge *Bozza*; un documento annullato ha il badge *Annullato* accanto al numero. Lo stato normale, *Confermato*, non si scrive |
| Data | il giorno della conferma; per una bozza, in grigio, il giorno in cui è stata creata |
| Sede | solo con più sedi (regola di §2) |
| Causale | solo negli scarichi |
| Righe | quante righe ha il documento |
| Pezzi | la somma dei pezzi delle righe. Negli inventari al suo posto ci sono *Contate* (le righe con il numero contato) e *Con differenza* (le righe dove contati e attesi non coincidono) |
| Nota | |
| Chi | chi ha confermato, dal log degli stati; per una bozza, chi l'ha creata |
| ⋯ | *Modifica* sulle bozze, *Visualizza* sugli altri; *Duplica*; *Elimina* solo sulle bozze, con conferma |

- Le voci del menu ⋯ usano le azioni-array di §1. Il server rifiuta comunque
  l'eliminazione di un documento che non è in bozza, con `assertDeletable()`.
- Righe, pezzi, chi e data arrivano con `select()`, come in §3: mai una query
  per riga.
- Filtri: *Stato*; *Sede* con più sedi; *Periodo*, con le scelte di §3 sulla
  *Data*; *Causale* negli scarichi.
- Ricerca: numero e nota.

**Una pagina per stato.** La bozza si apre in modifica, un documento
confermato o annullato in sola lettura; chi apre l'indirizzo dell'altra pagina
ci viene portato. Il server rifiuta salvataggio, conferma ed eliminazione di un
documento che non è più in bozza, anche da una pagina rimasta aperta.

**Bozza.** La stessa pagina per un documento nuovo e per una bozza da
modificare. Usa un template suo con `PageSchema::view('form', …)`, come
`ScheduleResource`, perché il form del core ha solo il suo «Salva».

- In alto, su una riga: (Sede) · Causale (solo scarico) · Nota.
- Sotto, una casella sola **«Cerca o scansiona»**:
  - scrivendo nome, SKU o EAN compaiono al più 20 versioni, con foto, codice,
    giacenza nella sede e il badge *ferma* se non è in vendita. Cliccandone una
    si aggiunge la riga, con 1 pezzo (negli inventari con *Contati* vuoto), e il
    cursore va sui pezzi; se la riga c'è già, il cursore va su quella;
  - il lettore di codici a barre scrive il codice e preme Invio. Se l'EAN o lo
    SKU è esattamente quello di una versione, la riga si aggiunge con 1 pezzo, o
    prende +1 se c'è già. La casella si svuota e resta pronta per il pezzo dopo;
  - SKU ed EAN non sono unici. Se il codice è di più versioni, compaiono quelle
    e non si aggiunge niente: «Più versioni con questo codice: scegline una».
    Se il codice è sconosciuto, compare «Nessuna versione con questo codice».
    Invio non prende mai il primo risultato a caso;
  - i multiprodotti non compaiono (arrivano con G5);
  - le versioni arrivano da una rotta JSON del modulo, con i permessi della
    pagina. `gst_products` riceve un indice su `ean`, come quello su `sku`.
- Le righe, in una tabella compatta come un elenco: Articolo — Versione (con
  lo SKU) · Giacenza nella sede · Pezzi · cestino, che chiede conferma.
  - La versione di una riga non si cambia: si toglie la riga e se ne aggiunge
    un'altra.
  - Cambiando la sede, la giacenza delle righe si rilegge.
  - Su telefono restano Articolo e Pezzi.
- In fondo il totale, «12 righe · 34 pezzi», e i bottoni **Salva bozza** e
  **Conferma carico** (*Conferma scarico*, *Conferma inventario*).

Le righe viaggiano come un solo campo JSON: 500 righe con un campo per numero
supererebbero `max_input_vars` di PHP (1000 di norma).

**Salvataggio e conferma.**

- La bozza si salva anche incompleta, senza causale o senza righe; i pezzi non
  possono essere negativi.
- La conferma chiede tutto: la causale nello scarico, almeno una riga, pezzi
  maggiori di zero; negli inventari almeno una riga contata.
- Prima di confermare compare la domanda, con `modal()`:
  - carico: «34 pezzi in 12 righe entrano in magazzino. Dopo non si modifica
    più: si può solo annullare.»;
  - scarico: «34 pezzi in 12 righe escono dal magazzino. …»;
  - inventario: «12 righe contate, 3 con differenza: la giacenza di quelle 3
    versioni si corregge. …».
- La conferma salva la bozza e poi conferma (4.1). Se si ferma per pezzi
  insufficienti, le modifiche restano nella bozza e l'errore dice quale
  versione.
- Un documento tiene al massimo 500 righe: oltre, la pagina diventa lenta e la
  conferma lunga. Aggiungendo la riga numero 501 compare «Un documento tiene al
  massimo 500 righe», e il server la rifiuta comunque.

**Inventario.** Le stesse pagine, con le colonne Articolo — Versione ·
Attesi · Contati · Differenza · cestino.

- *Attesi*: per una riga non ancora contata, la giacenza di adesso; per una
  riga contata, quella ricordata. Il server la legge quando salva un numero
  contato nuovo o cambiato (4.1).
- *Differenza* è *contati − attesi*: verde se positiva, rossa se negativa, «—»
  se la riga non è contata.
- Il lettore fa +1 su *Contati* (da vuoto a 1).
- Il bottone **Aggiungi versioni** aggiunge tutte le versioni, quelle di una
  categoria (sottocategorie comprese) o quelle di un marchio, con *Contati*
  vuoto. Prende il posto della vecchia modifica in blocco di Giacenze.
  - Entrano le versioni in vendita e quelle ferme che hanno ancora pezzi nella
    sede; mai i multiprodotti; mai due volte la stessa versione.
  - Le versioni arrivano dalla rotta della ricerca e si aggiungono nella
    pagina, come quelle cercate: si salvano con la bozza.
  - Se si supererebbero le 500 righe non si aggiunge niente, e il messaggio
    dice quante sarebbero: un magazzino grande si conta per categorie.
- Su telefono restano Articolo e Contati.

**Documento confermato o annullato.** Pagina `view` in sola lettura, con un
template suo (`PageSchema::view('show', …)`).

- Il titolo è il documento con il numero, «Carico 2026/090003», con il badge
  *Annullato* se lo è.
- In alto: (Sede) · Causale (scarico) · Nota, e «Confermato da Mario il
  24/09/2026», più «Annullato da Luca il 25/09/2026» se c'è. Chi e quando
  vengono dal log degli stati.
- Sotto, le righe in tabella: Articolo — Versione (con lo SKU) · Pezzi; negli
  inventari Attesi · Contati · Differenza. In fondo il totale.
- Nell'intestazione, con `PageSchema::actions()`:
  - **Duplica** crea una nuova bozza con sede, causale, nota e righe, e la
    apre. Non copia numero, stato e date; negli inventari rilegge gli attesi.
    Le versioni che non esistono più non si copiano;
  - **Annulla**, solo sui documenti confermati, chiede conferma con `modal()`:
    «Il carico 2026/090003 si annulla: i suoi movimenti si stornano e il
    documento resta, annullato.» Se si ferma per pezzi insufficienti, l'errore
    dice quale versione e il documento resta confermato.

*Duplica* e *Elimina* del menu ⋯ fanno lo stesso che qui.

**Core.** Oltre a §1 non serve niente di nuovo. La casella «Cerca o
scansiona» e la tabella delle righe sono componenti del modulo, in PHP e JS
come la scelta delle opzioni della scheda prodotto
(`ProductModelResource::optionsPicker()`); G3 le riuserà per gli ordini ai
fornitori. Il resto c'è già:

- template propri con `PageSchema::view('form' | 'show', …)`;
- bottoni d'intestazione con `PageSchema::actions()`;
- conferme con lo stesso `modal()` di *Elimina*;
- rifiuto dell'eliminazione con `assertDeletable()`;
- salvataggio delle righe negli agganci della Resource
  (`afterStore()`, `afterUpdate()`).

### 5. Documentazione, test, piani (approvata)

#### 5.1 Documentazione

Ogni piano aggiorna le guide delle parti che tocca, per il commerciante e per
lo sviluppatore, e il CHANGELOG del modulo: una guida non racconta mai una
pagina che non c'è ancora.

**Guide per il commerciante.** La sezione *Magazzino* del sommario diventa
Giacenze e rettifiche · Carichi e scarichi · Inventario · Movimenti · Avvisi
di scorta minima.

- *Giacenze e rettifiche*, riscritta: la pagina serve a consultare, con una
  colonna per sede; la rettifica si fa dalla scheda della versione; il primo
  carico rimanda a *Carichi e scarichi*.
- *Movimenti*, aggiornata: le colonne nuove, il documento dentro *Tipo*, gli
  storni, il *Periodo*.
- *Carichi e scarichi* (`magazzino-carichi-scarichi.md`, nuova): la bozza,
  «Cerca o scansiona» e il lettore di codici a barre, la conferma, *Annulla*,
  *Duplica*, il primo carico.
- *Inventario* (`magazzino-inventario.md`, nuova): *Aggiungi versioni*, attesi
  e differenza, perché un magazzino grande si conta per categorie e perché la
  bozza si salva man mano mentre si conta (4.1).
- *Avvisi di scorta minima*: la parte sull'elenco Giacenze.
- *Funzionalità*: *Acquisti* non porta più i documenti di carico ma fornitore e
  costi sui carichi. In `config/features.php` la descrizione diventa
  «Fornitori e costi d'acquisto nei prodotti e nei carichi, valore del
  magazzino.» e la tabella della guida si rigenera con `php forge
  gestionale:features-doc`.

**Guide per lo sviluppatore.**

- *Magazzino*: le pagine nuove, `Locations::shown()`, i documenti (stati,
  conferma, annullamento, `reference_type`, attesi, limite di 500 righe) e i
  componenti che G3 riuserà.
- *Codici, numerazione e log degli stati*: le tre sequenze e il log dei
  documenti.

**Core.** `docs/app/concetti/tabelle/` con le aggiunte di §1, §2 e §3, e il
CHANGELOG del core.

**Architettura.** §4.3 si aggiorna insieme a questa spec: il tipo
`stocktake`, `expected_quantity`, il numero alla conferma, gli storni con
`stock_document_cancel`, carico, scarico e inventario sempre disponibili. Con
lei la riga di *Acquisti* fra le funzionalità, la riga di G2 e quella di G3
nella tabella dei sotto-progetti e le etichette dal documento di carico, che
non chiedono più `purchasing`.

#### 5.2 Dati di prova

`gestionale:demo` aggiunge un carico confermato e uno scarico confermato
(piano 5) e un inventario in bozza (piano 6), così le pagine nuove non sono
vuote. `--fresh` li toglie con i loro movimenti.

#### 5.3 Test

**Nel core.** Quelli di §1.

**Senza database.**

- Le regole dei documenti:
  - quali causali vanno con quale tipo;
  - cosa chiede la conferma;
  - il limite di 500 righe;
  - il movimento di ogni riga: in un inventario una riga senza numero contato,
    o con differenza zero, non muove niente;
  - lo storno, con il segno opposto.
- `Periodo`, con il fuso del sito.
- Il codice letto dal lettore: una versione, più versioni, nessuna.

**Con database.**

- Conferma: numero per tipo senza buchi, stato, log, movimenti con il
  documento e l'utente.
- Conferma fermata da `stock.insufficient`: nessun numero, nessun movimento,
  la bozza salvata.
- Annullamento, anche quando lo storno si ferma.
- Inventario: gli attesi letti al salvataggio e riletti quando cambia la sede;
  una vendita fatta fra il salvataggio della conta e la conferma resta.
- Si eliminano solo le bozze.
- *Duplica*.
- Le colonne calcolate degli elenchi dei documenti.
- Giacenze contro `Levels::forProducts` (§2).
- `forge update` lanciato due volte non cambia niente.

`StockPagesTest` e `StockMovementResourceTest` si riscrivono come dicono §2 e
§3.

#### 5.4 Validazione nel browser

1. Con due sedi, le colonne di Giacenze sommano al *Totale*; con una sede sola
   c'è la colonna *Giacenza*.
2. Nei Movimenti si leggono chi, prima e dopo; il *Periodo* filtra; la ricerca
   trova il nome dell'articolo.
3. Un carico fatto con il lettore di codici a barre.
4. Uno scarico che porterebbe sotto zero si ferma e dice quale versione; le
   modifiche restano nella bozza.
5. Un inventario di una categoria muove solo le righe contate con una
   differenza.
6. Un carico annullato mostra i suoi storni nei Movimenti.
7. La bozza si usa anche da telefono.

#### 5.5 Piani

Giacenze e Movimenti vengono subito dopo il core, prima dei documenti: sono
le pagine che oggi non si capiscono. Il modulo non è ancora rilasciato, quindi
nessun negozio resta senza la vecchia modifica in blocco di Giacenze fino al
piano 6; nel frattempo la rettifica dalla scheda della versione c'è sempre.

1. **Tabelle del core** (repository `app`): azioni-array,
   `TableLayoutSchema::select()`, `filterQuery()`, correzioni a
   `FilterCustom`, ricerca annidata, con guide e test. Entra nel `main` del
   core prima del piano 2; il sito di prova lo prende con `composer update
   wonder-image/app`.
2. **Giacenze:** `Locations::shown()`, l'elenco in sola consultazione con una
   colonna per sede, filtri, `lowStockUrl()` sul filtro *Scorta*. Via la
   pagina-form. Guide *Giacenze e rettifiche* e *Avvisi di scorta minima*:
   fino al piano 5 il primo carico si fa con la rettifica, causale *Giacenza
   iniziale*.
3. **Movimenti:** le colonne, *Chi*, *Prima*, `Periodo`, i filtri *Causale*,
   *Sede* e *Periodo*, la ricerca sull'articolo, il menu ⋯ con *Apri la
   versione*, *Ultimi movimenti* nella scheda della versione. Guida
   *Movimenti*.
4. **Documenti, dati e servizio:** tabelle, Model, regole, conferma,
   annullamento, duplica, inventario, senza pagine. Nei Movimenti il documento
   e gli storni dentro *Tipo* e il filtro *Tipo*. Guide per lo sviluppatore.
5. **Carichi e scarichi, pagine:** menu, elenchi, bozza con i componenti,
   rotta JSON e indice su `ean`, pagina in sola lettura, azioni, *Visualizza
   carico* e *Visualizza scarico* nel menu ⋯ dei Movimenti, carico e scarico
   nei dati di prova. Guide *Carichi e scarichi* e *Funzionalità*, con
   `config/features.php`.
6. **Inventario, pagine:** Attesi · Contati · Differenza, *Aggiungi versioni*,
   limite di 500 righe, *Visualizza inventario* nei Movimenti, l'inventario
   in bozza nei dati di prova. Guida *Inventario*; chiusura di G2b-bis
   (CHANGELOG, TODO).
