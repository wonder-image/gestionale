# G2c — La scheda prodotto, secondo giro

- **Sotto-progetto:** seguito di G2a-bis, prima di G2b
- **Stato:** rivista il 2026-09-22 dopo la seconda prova in pannello (§4, §5,
  §8 e §11 nuove o riscritte; decisioni P22-P25)
- **Documento di riferimento:** [G2a-bis — La scheda prodotto semplice](2026-09-21-scheda-prodotto-semplice-design.md),
  [G2a — Catalogo](2026-09-21-catalogo-design.md)
- **Dipende da:** C1 — repeater raggruppato (spec in `wonder-image/app`:
  `docs/superpowers/specs/2026-09-22-repeater-raggruppato-design.md`),
  rilasciato come `wonder-image/app` v.2.3.0

## Contesto

G2a-bis ha reso la scheda leggibile: una parola sola, due colonne, le versioni
in una griglia invece che in dodici pagine. Provandola accanto alla pagina di
inserimento prodotto di Shopify restano tre distanze.

1. **Le opzioni si spuntano solo fra quelle che esistono già.** Se il colore
   che serve non è in anagrafica bisogna uscire dalla scheda, andare in
   Attributi, aggiungerlo, tornare indietro e ritrovare il punto. Shopify ti fa
   scrivere il valore lì dove serve.
2. **Le versioni si possono creare solo dopo.** La creazione chiede cinque
   campi e atterra sulla scheda; i colori e le taglie si spuntano al secondo
   salvataggio. Chi sa già cosa sta caricando fa due giri per una cosa sola.
3. **La griglia delle versioni è piatta.** Dodici righe che si somigliano, e il
   prezzo o si scrive dodici volte o si scrive per tutte. In mezzo — "queste
   quattro blu costano così" — non c'è niente.

E una quarta, che non viene dalla scheda ma dal mondo: **il prodotto si
spedisce dentro una scatola**. Oggi la scheda chiede peso e misure del
prodotto, che è il dato sbagliato da solo: quello che parte è prodotto più
imballo, e l'imballo è una cosa del negozio, non dell'articolo.

## Obiettivo

Che caricare una maglietta in cinque taglie e tre colori sia **una schermata,
un salvataggio e tre prezzi scritti**, senza uscire dalla scheda per andare a
creare un colore, e che quello che si spedisce sia un dato completo.

## Non obiettivi

- **Opzioni scritte sul prodotto** alla maniera di Shopify: i valori restano
  un'anagrafica condivisa, altrimenti ogni cliente si inventa "Colore",
  "Colori" e "Tinta" e i filtri della vetrina si sbriciolano.
- **Creare un'opzione intera al volo** (un attributo nuovo dalla scheda): si
  crea il valore che manca dentro un'opzione che c'è.
- **Raggruppare per taglia**: la taglia non è una colonna della griglia, è un
  collegamento ad attributo (§4).
- **Un imballaggio per versione**: sta sul prodotto (§5).
- **Costi e corrieri**: l'imballaggio nasce come dato, non come calcolo. Lo usa
  G4.
- **Il "+" su tag e categorie multiple**: sono alberi, e gli adattatori
  `checkTree` del quick-create non sono ancora stati provati in un sito.

## Design

### 1. Il "+" sui gruppi di opzioni

Nasce `AttributeValueResource` (`src/Resources/Catalog/`), fuori dal menu
(`navigationSchema()->enabled(false)`): esiste per esporre lo **store API** e
fare da bersaglio a `quickCreate`. Scrive `label`, `attribute_id`, e da sé
`position` (in fondo all'elenco) e lo `slug`, come fa oggi il repeater dentro
`AttributeResource` — che resta il posto dove si riordinano e si cancellano i
valori.

Ogni gruppo di spunte in `optionFields()` prende:

```php
FormField::key('option_'.$id)->checkbox()->options(...)
    ->quickCreate(
        AttributeValueResource::class,
        label: 'label',
        layout: fn () => (new Form)->components([(new Container)->components([
            FormField::key('attribute_id')->hidden()->value((string) $id),
            AttributeValueResource::getInput('label'),
        ])]),
    )
```

Il pulsante dice **"+ Aggiungi colore"** — il nome dell'opzione, minuscolo — e
il modal ha una casella sola, *Valore*. Alla conferma il valore compare
spuntato.

Il tooltip del riquadro avverte di cosa sta succedendo davvero: *"Il valore
entra nell'elenco dei colori del negozio: lo ritrovi su tutti i prodotti"*.
Perché è la verità, e perché è la ragione per cui non si chiama "aggiungi
colore a questo prodotto".

**Da verificare prima di costruirci sopra:** che `quickCreate` porti fino allo
store un campo nascosto con il valore già scritto (`attribute_id`). Se non lo
fa, è una riga nel core, in C1.

### 2. Il "+" sugli altri campi collegati

Stesso pulsante su **marchio** (`brand_id`), **categoria principale**
(`main_category`), **tipo fiscale** (`tax_category_id`) e **imballaggio**
(`package_id`, §5): quattro select singole, quattro modal con i soli campi
obbligatori del bersaglio.

Precondizione per tutti: la Resource di destinazione deve esporre lo store API.
`TaxCategoryResource` oggi ha `apiSchema()->enabled(false)`; va aperto lo
`store` con i soli campi che servono. Non serve dare permessi a nessun ruolo:
la chiamata parte lato server come `@system`, e il "+" compare solo a chi può
creare quella risorsa.

### 3. La creazione: una schermata sola

*Sezione riscritta il 2026-09-22, dopo aver usato la creazione a cinque campi.*

Compilare un pezzo, salvare, aprire la scheda, compilare il resto e salvare
ancora è troppo lungo: chi carica un prodotto vuole vedere tutto quello che
gli sarà chiesto, una volta sola, e salvare quando ha finito.

**"Aggiungi prodotto" mostra la scheda intera**: gli stessi riquadri della
modifica, nello stesso ordine, con i blocchi lunghi richiudibili — *Si vende
in più versioni?*, *Scheda tecnica*, *Spedizione*. Chi vende un cappello
compila nome, prezzo e categoria e salva; chi vende magliette apre i blocchi
che gli servono e salva una volta sola.

Si può fare senza trucchi perché il core **sincronizza già i repeater alla
creazione**: `ResourcePageController` chiama `syncRepeaterRelations($insertId,
$_POST, $_FILES, 'store')` subito dopo l'insert. Foto, versioni e
collegamenti nascono quindi nello stesso salvataggio del prodotto.

Le uniche differenze rispetto alla scheda di un prodotto che esiste già:

- niente colonna della giacenza finché le versioni non esistono (§10);
- niente pulsante "Dettagli delle versioni", che porta a una pagina di righe
  che non ci sono ancora.

### 4. Le versioni nascono sotto gli occhi

*Sezione riscritta insieme alla §3.*

Oggi si spuntano i colori, si salva, e **solo allora** compaiono le righe da
prezzare. Due salvataggi per una cosa sola.

Spuntando i valori, la griglia delle versioni si costruisce **subito nel
browser**: una riga per combinazione, con il nome ("Blu / M") e lo SKU
proposti. In chiaro si compilano le quattro cose che si compilano sempre —
**nome, prezzo, giacenza, foto** — e il resto sta dietro il bottone **Compila
tutto**, che apre codice, EAN e costo su tutte le righe insieme (P23).

Al salvataggio il generatore crea le righe che mancano **con i valori
scritti**, invece di inventarli e farli correggere dopo. La giacenza non
diventa un numero in una colonna: diventa un movimento di magazzino con
causale "giacenza iniziale" e il costo scritto (P24), perché il magazzino ha
una porta sola. La foto si attacca al **colore** della versione — la tabella
delle immagini si lega al modello e alla variante, non al singolo prodotto — e
la coda ne fa le misure come per ogni altra.

Il calcolo delle combinazioni è lo stesso di `Combinations::plan()`, rifatto in
JS sui valori spuntati: gli id e le etichette sono già nel DOM delle caselle.
Il server non si fida di quello che arriva — ricalcola il piano e accetta solo
le combinazioni che tornano.

Sulla scheda di un prodotto che esiste già la griglia resta quella che è, con
il raggruppamento per colore e il prezzo di gruppo (§5).

### 5. La griglia delle versioni

Una colonna nuova, **in testa**: il nome dell'opzione con pagina propria
(`pageOptionName()`: "Colore" in un negozio di magliette, "Gusto" in
gelateria), una `select` con le varianti del prodotto. Oggi dalla griglia non
si vede nemmeno a quale colore appartenga una riga, e spostarla è impossibile.

**Le larghezze non tornano, e vanno sistemate qui.** La griglia di oggi è già
oltre il dodici — nome 3, SKU 2, EAN 2, prezzo 2, scontato 2, stato 1, più la
colonna delle azioni che con il riordino attivo ne vale 3 — e infatti le righe
vanno a capo. Con una colonna in più bisogna togliere qualcosa:

- **Il riordino sparisce** dalla griglia delle versioni. L'ordine lo decide il
  generatore, e trascinare la quinta riga sopra la terza non significa niente
  per chi compra. Le azioni tornano a valere 1.
- **Lo scontato esce dalla griglia.** Uno sconto su una taglia sola è raro; lo
  sconto dell'articolo si scrive nella casella in alto, che va su tutte le
  versioni, e il caso singolo si corregge nella pagina della versione.

Restano: colore 2, versione 2, SKU 2, EAN 2, prezzo 2, stato 1, azioni 1. Dodici
esatti, una riga per versione. L'EAN resta perché i codici a barre si scrivono
uno dopo l'altro guardando la griglia, ed è il lavoro che la griglia serve a
rendere veloce.

Su quella colonna si raggruppa:

```php
->repeaterGroupBy('variant', 'active')
->repeaterGroupCommand('price', 'Prezzo del gruppo')
->repeaterGroupCountLabel('versione', 'versioni')
```

`variant` è una colonna **calcolata e nascosta** — il nome del colore, scritto
da `mutateFormValues()` accanto alla giacenza — non `product_variant_id`: una
select scrivibile sposterebbe una versione da un colore all'altro senza
spostarne i collegamenti agli attributi. In chiaro il colore lo scrive la
testata del gruppo, che è dove si legge una volta sola invece che su ogni riga.

Il selettore e le testate compaiono solo quando le varianti sono più di una:
con un colore solo non c'è niente da raggruppare.

**Si raggruppa per colore, non per taglia.** La taglia è un collegamento ad
attributo, non una colonna: diventerà raggruppabile quando le colonne degli
attributi saranno scrivibili dalla griglia, che è un progetto suo.

La casella *Prezzo* in alto resta quella che è — un comando che scrive su
**tutte** le versioni, lasciata vuota quando le versioni sono più di una. Ora
però ne esiste una più precisa sulla testata del gruppo, che agisce sotto gli
occhi invece che al salvataggio; se all'uso la casella in alto risulta di
troppo, si toglie in un secondo momento.

### 6. Spedizione e imballaggi

**Tabella `gst_packages`**: `code` (`pkg_`, costante nuova in `Codes`), `name`,
`length`, `width`, `height` (cm, misure interne), `weight` (la **tara**: quanto
pesa la scatola vuota), `is_default`, `position`, `active`. Nessuna portata
massima: nessuno la controllerebbe finché non c'è un corriere.

**Pagina `app/gestionale/imballaggi`**, sezione *Set up* accanto a Tipi
fiscali, solo admin. Elenco: nome, misure, tara, predefinito.

**Sul prodotto** il riquadro *Peso e misure* diventa **Spedizione**:

- **Imballaggio**, select con il "+"; vuoto vuol dire *"Imballaggio
  predefinito"*, quello segnato `is_default`.
- **Peso del prodotto**, come oggi.
- In sola lettura, sotto: **"Spedito: 1,4 kg — 1,2 di prodotto e 0,2 di
  scatola"**. È il numero che G4 darà al corriere, e vederlo qui è il modo di
  accorgersi che la tara manca.
- Le **misure del prodotto** restano, per i fuori misura che nella scatola
  scelta non ci stanno.
- Se l'articolo **non si spedisce**, il riquadro non compare.

**"Si spedisce" resta in Pubblicazione**, dove sta oggi per scelta esplicita,
accanto a "Si accettano resi".

`package_id` nasce su `gst_product_models`. Sulle versioni no: una maglietta S
e una XL nella stessa scatola sono il caso normale, e la colonna in più oggi
non la userebbe nessuno.

### 7. Le foto, e i video

*Sezione nuova.*

La riga-per-foto con il menù "Vale per" e il select "Stato" chiede di capire
un modello dati per caricare un'immagine. Al suo posto, **aree di
caricamento**: una *Foto dell'articolo* e una per ogni colore — *Foto Blu*,
*Foto Rosso* — ognuna un `fileDragDrop` multi-file con un massimo dichiarato.
Si trascinano le foto dove appartengono, e non si sceglie niente da un menù.

- **Lo stato non è un campo.** Una foto pronta non ha niente da dire; una che
  non è riuscita lo scrive accanto a sé, con il modo di farla riprovare.
- **La descrizione resta**: è quella che leggono i motori di ricerca e chi non
  vede le immagini, ed è una riga sola sotto la miniatura.
- **I video stanno con le foto.** Serve un tipo nuovo nel core, `gallery`
  (png, jpeg, webp, mp4): oggi un campo accetta immagini **oppure** video, mai
  tutti e due. `ProductImage` accetta l'mp4, e la coda delle miniature salta i
  video invece di fallire su di loro.

### 8. Modificare un'opzione da dove la si usa

*Sezione riscritta: la matita è stata tolta.*

C'era un collegamento "Modifica colore" sotto ogni gruppo di spunte. Alla
prova diceva poco e occupava una riga in ogni blocco: chi vuole rinominare i
valori di un'opzione va nella sua anagrafica, che sta nel menù. Il "+" per
aggiungere un valore resta dov'è, perché quello serve mentre si compila.

### 9. La giacenza si corregge dove si modifica il prodotto

*Sezione nuova, da coordinare con G2b.*

Una colonna **Giacenza scrivibile** nella griglia delle versioni, e una
casella nel riquadro *Prodotto* quando la versione è una sola. Si scrive la
quantità nuova; al salvataggio parte un movimento di rettifica con la
differenza e la causale "correzione da scheda prodotto", senza chiedere altro
a chi sta correggendo un numero.

La pagina *Rettifica* resta per i carichi lunghi — centinaia di righe con una
causale sola — ma non è più la strada normale.

### 11. Prima l'opzione, poi i valori

*Sezione nuova.*

I blocchi delle opzioni stavano tutti aperti: chi vende cappelli si trovava
davanti colori, taglie e gusti senza averne chiesto nessuno, e la scheda
diventava lunga il doppio. Come su Shopify, la scheda ne mostra **zero** e
chiede quale serve: un menù "Aggiungi un'opzione…" con le opzioni del negozio,
e solo quella scelta apre il suo elenco di valori da spuntare.

Un'opzione aggiunta per sbaglio si toglie con "Togli colore", che spunta via i
valori e richiude il blocco. Le opzioni che l'articolo **usa già** partono
aperte e senza il bottone: nasconderle direbbe che non ci sono, e invece ci
sono.

### 10. Dati di prova

`php forge gestionale:demo` aggiunge **due imballaggi** — una busta imbottita e
una scatola media, la scatola predefinita — e li assegna ai tre articoli. Uno
degli articoli di prova nasce con tre colori e quattro taglie, così la griglia
raggruppata si vede senza doverla costruire a mano.

## Validazione

- **Unità:** `Packages::shippingWeight()` (prodotto + tara, con la tara
  mancante che vale zero); la proposta dello SKU e il nome della versione già
  coperti restano verdi.
- **Integrazione:** creare un prodotto con le opzioni spuntate dalla schermata
  di creazione e verificare che nascano le versioni giuste al primo
  salvataggio; creare un valore di attributo dal modal e ritrovarlo in
  anagrafica, spuntato, una volta sola.
- **A mano, sul sito di prova:** caricare una maglietta tre colori per quattro
  taglie dalla sola schermata di creazione; raggruppare per colore, chiudere
  due gruppi, scrivere un prezzo di gruppo, salvare e ricaricare; creare un
  colore nuovo dal "+" e usarlo subito; scegliere un imballaggio e leggere il
  peso spedito.

## Documentazione

- `docs/user/catalogo-prodotti.md`: le opzioni in creazione, il "+", la griglia
  raggruppata, il riquadro Spedizione.
- `docs/user/imballaggi.md`: nuovo, corto — cos'è la tara e perché conviene
  averne due o tre buoni.
- `docs/dev/concetti/catalogo.md`: `AttributeValueResource` e perché esiste,
  `gst_packages`, il raggruppamento della griglia; e via la nota sul
  `foldable()` che era una Card, sostituita da quella sul pavimento `^2.3.0`.

## Decisioni di questa spec

| # | Decisione | Perché |
|---|---|---|
| P1 | I valori restano un'anagrafica condivisa | "Blu" dev'essere lo stesso Blu ovunque, o i filtri della vetrina non tengono |
| P2 | Il "+" crea un valore, non un'opzione | Creare attributi al volo moltiplica i doppioni che P1 vuole evitare |
| P3 | `AttributeValueResource` fuori dal menu | Serve allo store API, non è una pagina da visitare |
| P4 | Il "+" solo sulle quattro select singole | Gli adattatori ad albero del core non sono ancora provati in un sito |
| P5 | Opzioni in creazione, in un blocco chiuso | Chi vende un prodotto unico non deve incontrarle; chi le usa risparmia un giro |
| P6 | Colonna del colore nella griglia | Senza non si sa a chi appartenga una riga, e non c'è niente per cui raggruppare |
| P7 | Raggruppamento solo per il colore | La taglia non è una colonna: prometterlo vorrebbe dire renderla scrivibile |
| P8 | La casella prezzo in alto resta | Toglierla adesso sarebbe una scelta al buio; si giudica all'uso |
| P9 | `gst_packages` con la tara | Il peso spedito è prodotto + scatola: da solo il peso del prodotto è il dato sbagliato |
| P10 | L'imballaggio sul prodotto, non sulla versione | Le taglie viaggiano nella stessa scatola; la colonna in più non la userebbe nessuno |
| P11 | "Si spedisce" resta in Pubblicazione | È stata una scelta esplicita del giro precedente, non un caso |
| P12 | Via il riordino dalla griglia delle versioni | L'ordine lo decide il generatore; e serviva spazio per la colonna del colore |
| P13 | Lo scontato esce dalla griglia, l'EAN resta | I codici a barre si scrivono in griglia uno dopo l'altro; uno sconto su una taglia sola è raro |
| P14 | La creazione mostra la scheda intera | Compilare, salvare, riaprire e salvare ancora è la cosa che rende l'inserimento lungo |
| P15 | Le versioni si costruiscono nel browser | Prezzarle richiedeva un secondo salvataggio: le righe devono esserci mentre le spunti |
| P16 | Il server ricalcola il piano delle combinazioni | Quello che arriva dal browser è una proposta, non una verità |
| P17 | Un'area di caricamento per colore | Trascinare la foto dove appartiene non chiede di capire cos'è una variante |
| P18 | Lo stato della foto non è un campo | Una foto pronta non ha niente da dire; una fallita lo scrive da sé |
| P19 | Tipo `gallery` nel core: foto e video insieme | Oggi un campo accetta immagini oppure video, e un catalogo ha bisogno di tutti e due |
| P20 | La giacenza si corregge dalla scheda, con causale automatica | Si rettifica dove si guarda il prodotto; chiedere una causale per correggere un numero è un attrito |
| P21 | ~~L'opzione si modifica con un collegamento~~ — annullata da P25 | Alla prova il collegamento diceva poco e occupava una riga per blocco |
| P22 | Prima si sceglie l'opzione, poi compaiono i valori | Mostrare tutte le opzioni sempre raddoppia la scheda a chi non ne usa nessuna |
| P23 | La griglia chiede quattro cose; codice, EAN e costo dietro "Compila tutto" | Nome, prezzo, giacenza e foto si compilano sempre; il resto solo da chi lo usa |
| P24 | La giacenza di una versione nuova è un movimento, non un numero | Il magazzino ha una porta sola: si carica con causale "giacenza iniziale" e il suo costo |
| P25 | Via la matita accanto all'opzione | L'anagrafica si apre dal menù; in scheda serviva solo il "+" per un valore nuovo |

## Piani

Da scrivere dopo l'approvazione.
