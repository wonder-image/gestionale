# G2c — La scheda prodotto, secondo giro

- **Sotto-progetto:** seguito di G2a-bis, prima di G2b
- **Stato:** rivista il 2026-09-22 dopo la terza prova in pannello (§4, §5,
  §12 riscritte; decisioni P26-P32)
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

### 12. Una griglia sola, e il vocabolario

*Sezione nuova, terzo giro.*

Erano due cose diverse: in creazione una griglia scritta a mano in HTML, in
modifica il repeater vero, con campi diversi. Adesso è **una**: il repeater
«Quello che si vende» sta in tutte e due le pagine, a piena larghezza in fondo
— sette caselle per riga dentro due terzi di schermo vanno a capo, ed era il
disallineamento — e le spunte degli attributi ci aggiungono le righe che stanno
per nascere, con la chiave della loro combinazione.

Le righe senza id **non arrivano al sync del core**: le tiene fuori
`prepareRepeaterRows()`, perché il colore a cui appartengono lo crea
`Generator::run()` in `afterStore`/`afterUpdate`, e il sync gira prima. Con la
griglia sempre stampata questo non è un dettaglio: il repeater stampa una riga
vuota di cortesia quando non ne ha, e quella riga — con la sua `select` di
stato che posta sempre un valore — sarebbe arrivata al database come un
prodotto senza colore. Per questo il core ha preso `repeaterStartEmpty()`.

**Le parole.** Attributo (Colore, Taglia, Materiale — al massimo **tre** per
prodotto) e opzione in vendita (la riga con SKU, prezzo, giacenza e foto).
"Versione" sparisce dall'interfaccia.

**Il nome non si scrive.** Nasce dagli attributi — "Blu / S" — e si riallinea a
ogni salvataggio, sui prodotti e sui colori: rinominare "Blu" nell'anagrafica
rinomina il colore ovunque. Nella griglia si legge solo quello che resta ("S",
o "S / Gomma"), in una colonna finta: una colonna `name` di sola lettura viene
postata lo stesso, e avrebbe scritto "S" al posto di "Blu / S".

**Il raggruppamento non si sceglie**: è il colore, sempre, anche con una riga
sola — serve anche alla vetrina. Un articolo venduto solo per taglia non ha un
colore su cui raggruppare, e la griglia resta piatta.

**La giacenza si scrive nella riga**: si scrive quanti pezzi ci sono, il
pannello registra il movimento della differenza con causale «Inventario», e una
casella lasciata com'era non muove niente (`Stocktake::changes()`). Il rifiuto
di un numero negativo si calcola in `mutateRequestValues()`: dopo l'insert non
c'è nessuna rete, e un errore diventerebbe una pagina di guasto su un articolo
già scritto a metà.

**La foto sta nella riga**, è un `fileDragDrop`, e appartiene a quella singola
opzione: `gst_product_images` prende `product_id`, nullable, così eliminando
l'opzione la foto torna a valere per il colore invece di sparire.

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
| P26 | Una griglia sola, la stessa in aggiunta e in modifica | Due schermate con campi diversi per la stessa cosa sono due cose da imparare |
| P27 | Le righe nuove non passano dal sync del core | Il colore a cui appartengono non esiste ancora quando il sync gira |
| P28 | Il raggruppamento per colore è obbligatorio | È parte del significato, non una comodità di chi guarda: serve anche alla vetrina |
| P29 | Il nome lo scrive il sistema, sempre | Due sorgenti per lo stesso nome divergono al primo rename; e la griglia mostra il residuo, in una colonna finta |
| P30 | La giacenza si scrive nella riga, con causale automatica | Si corregge dove si guarda; il movimento nasce dalla differenza, e le caselle non toccate non muovono niente |
| P31 | La foto appartiene alla singola opzione | «Blu / S» può avere la sua; l'eredità opzione → colore → articolo la fa la lettura |
| P32 | Tre attributi al massimo, ma solo nel selettore | Nel server bloccherebbe per sempre un articolo che ne ha già di più, anche solo per correggergli il prezzo |

## 13. Quarto giro: l'ordine lo decide chi vende

Alla prova a schermo la riga dell'opzione era sfasata: prezzo, giacenza e stato
larghi quanto la riga, uno sotto l'altro, e il campo delle foto schiacciato in
un sesto di larghezza. Non era un problema di stile: `resolvedColumnWidth()`
nel framework scambiava `columnSpan(1)` per "non dichiarato" e lo portava a
undici dodicesimi. Le tre caselle strette della griglia erano le uniche
dichiarate a uno, e quindi le uniche rotte. Lo stesso difetto sfasava già il
repeater degli indirizzi del cliente.

Da lì il giro tocca quattro cose.

**L'ordine degli attributi lo sceglie chi vende.** Fino a qui il
raggruppamento era cablato sul colore, cioè sull'unico attributo che il
negozio dichiara `variant`. Ora l'articolo porta il suo ordine: gli attributi
accesi nel selettore stanno in una lista riordinabile, il primo raggruppa e
gli altri compongono il nome nell'ordine in cui stanno. «Colore, poi Taglia»
dà i gruppi «Blu» e «Rosso» con dentro «S», «M», «L»; «Taglia, poi Colore» dà
i gruppi «S», «M», «L» con dentro «Blu» e «Rosso». L'ordine si salva
sull'articolo, perché la scheda deve riaprirsi come la si è lasciata e perché
la vetrina mostrerà i selettori nello stesso ordine.

Il raggruppamento resta a un livello solo. Tre testate annidate — «Blu» dentro
cui «Gomma» dentro cui «S» — richiederebbero di riscrivere il raggruppamento
del framework per guadagnare poco: raggruppo per il primo, e gli altri li
leggo nel nome della riga.

**Il raggruppamento sparisce quando non dice niente.** Con un attributo solo
ogni gruppo conterrebbe una riga e la testata ripeterebbe il nome della riga.
I gruppi compaiono da due attributi in su.

**La riga chiede quattro cose.** Opzione, prezzo, giacenza e stato stanno
nella riga; SKU, EAN e il campo delle foto stanno dietro «Compila le
informazioni avanzate», che nel framework diventa un modificatore del
repeater e non un pezzo di HTML del modulo. Dentro quel blocco le foto vanno
a tutta larghezza: un rettangolo su cui si trascina un file non può stare in
un sesto di riga. Il blocco nasce chiuso anche su una riga che ha già i suoi
codici: lo SKU lo propone il pannello, e aprirlo «perché c'è qualcosa dentro»
riportava la griglia a essere lunga come prima.

La giacenza resta nella riga, ma solo finché il magazzino ha una sede sola.
Oggi la casella mostra il totale di tutte le sedi e scrive sulla principale:
con due sedi, riscrivere il numero sposterebbe la merce da una all'altra
senza dirlo. Finché la sede è una l'asimmetria non esiste; dalla seconda in
poi la casella diventa il totale in sola lettura e manda alla rettifica.

**«Questo articolo ha varianti?»** La domanda sta in cima al riquadro del
prodotto, sotto il nome, e governa quello che le sta sotto: con «No» prezzo,
prezzo scontato, giacenza, SKU ed EAN sono dell'articolo e il riquadro delle
opzioni non compare; con «Sì» compaiono selettore e griglia, e il prezzo
dell'articolo torna a essere il comando che li scrive tutti. È una colonna
dell'articolo, non un calcolo: un articolo appena creato ha già un figlio, e
"non ho ancora scelto" non è "no". Il server non si fida della domanda
nascosta: le caselle nascoste vengono postate lo stesso, e un «No» salta la
generazione delle combinazioni comunque.

Un articolo che ha già più di un'opzione non può tornare indietro con un
interruttore: le opzioni hanno movimenti, prenotazioni e foto, e farle sparire
da una preferenza è una perdita di dati travestita. Si cancellano dalla
griglia, una per una, dove la cancellazione lo dice.

| # | Decisione | Perché |
|---|-----------|--------|
| P33 | `columnSpan(1)` vale un dodicesimo; il "non dichiarato" si riconosce dal flag | Il valore di partenza del framework è già `1`: leggerlo come "niente" rompeva ogni casella stretta |
| P34 | Le colonne avanzate sono un modificatore del repeater | Un blocco a scomparsa dentro una riga serve a chiunque abbia una griglia, non solo al catalogo |
| P35 | La giacenza resta scrivibile nella riga finché la sede è una | È quello che si compila con la merce davanti; con due sedi il numero diventerebbe ambiguo |
| P36 | L'ordine degli assi si salva sull'articolo, in `axes_order` | La scheda deve riaprirsi come la si è lasciata, e la vetrina leggerà lo stesso ordine. Non `option_axes`: il prefisso `option_` è delle spunte, e il salvataggio lo butterebbe via |
| P37 | Il raggruppamento resta a un livello | Le testate annidate costano un giro di framework e leggono poco meglio |
| P38 | I gruppi compaiono da due attributi in su | Con un attributo solo la testata ripete la riga |
| P39 | «Ha varianti» è una colonna, non un calcolo | Un articolo nuovo ha già un figlio: il calcolo direbbe sempre "no" |
| P40 | «No» è bloccato su un articolo con più opzioni | Le opzioni hanno movimenti e foto: si cancellano dove la cancellazione lo dice |

## 14. Quinto giro: quello che si vede

Il giro precedente aveva messo le cose al posto giusto; questo toglie quello
che non serve e rende reversibile quello che si rimpiange.

**L'interruttore governa davvero.** «Questo articolo ha varianti» accendeva la
griglia ma lasciava in pagina prezzo, prezzo scontato, SKU ed EAN
dell'articolo, che con le varianti non vogliono dire niente. Ora spariscono —
e il riquadro «Codici», rimasto senza campi, sparisce con loro. Sono nascosti,
non tolti: il valore continua a viaggiare, e lo SKU di famiglia continua a
proporre i codici delle righe anche mentre non si vede.

**Le foto sono un campo solo.** Una riga per file, con descrizione e stato, era
una tabella dentro una scheda. Ora ogni area è un rettangolo su cui si
trascina, fino a dieci file, che si riordinano trascinandoli. La descrizione e
lo stato per singola foto se ne vanno: la prima non la scriveva nessuno, il
secondo lo decide la coda delle misure. Il campo manda un manifesto — l'elenco
dei file nell'ordine voluto, dove una stringa è un file che c'era già e un
numero è la posizione di uno appena caricato — e il pannello lo traduce in
righe di `gst_product_images`, una per foto, perché è una riga per foto che la
coda sa lavorare.

**Le misure non sono spedizione.** Lunghezza, larghezza, altezza e la nuova
circonferenza descrivono il prodotto, non il pacco: stavano in «Spedizione» e
sparivano con l'articolo che non si spedisce, portandosi via anche l'unità di
misura, che è obbligatoria. Ora hanno il loro riquadro. «Spedito» — la frase
che sommava prodotto e tara — se ne va: era un calcolo che nessuno aveva
chiesto, e il conto resta a disposizione per quando ci saranno le spese di
spedizione vere.

**Una riga eliminata si può rimettere.** Cancellare un'opzione non la toglie
più dalla pagina: la sbiadisce, le spegne i campi e le mette accanto un
«Annulla». Per il server non cambia niente — i campi di un `fieldset`
disabilitato non vengono postati, e una riga che non arriva è una riga
cancellata, come prima — ma chi ha sbagliato se ne accorge prima di salvare.

**Il selettore degli attributi sta in una riga.** Due riquadri affiancati alti
mezza pagina sono diventati una riga per attributo: maniglia per trascinare,
nome, e i valori come pillole in linea. Le spunte incolonnate in un riquadro
che scorre dicono «qui c'è un elenco lungo»; cinque taglie non sono un elenco
lungo.

**L'anagrafica degli attributi.** «Gruppo» era un campo che non leggeva
nessuno: serviva a raggruppare i filtri di una vetrina che non c'è ancora, e
intanto chiedeva di compilare qualcosa che non produceva niente. Esce dalla
scheda; la colonna resta, così quando i filtri arriveranno torna senza perdere
dati. L'unità di misura diventa un elenco — due schede scrivevano «g» e
«grammi» per la stessa cosa — e si vede solo dove vuol dire qualcosa, cioè sui
tipi Numero e Testo. Ogni valore può avere una descrizione, dietro lo stesso
bottone delle informazioni avanzate della griglia. La riga si legge
Fantasia → Valore → Colore, e sta su nove dodicesimi, perché il riordino a
mano tiene per sé le altre tre.

**Una riga creata da un campo appartiene alla risorsa.** Una categoria creata
con il «+ Aggiungi» del select finiva solo in quel select, e l'albero delle
spunte della stessa pagina — che elenca le stesse righe — non se ne accorgeva.
Ora l'evento porta anche la risorsa, e ogni campo che dichiara di elencarla si
aggiorna.

| # | Decisione | Perché |
|---|-----------|--------|
| P41 | Con le varianti spariscono prezzo, scontato, SKU ed EAN dell'articolo | Con le varianti quei numeri non hanno un solo valore; restano nel modulo, nascosti, perché lo SKU continua a proporre i codici |
| P42 | Le foto sono un campo solo, dieci per area | Una riga per file con descrizione e stato era una tabella dentro una scheda |
| P43 | Le misure hanno il loro riquadro, con la circonferenza | Descrivono il prodotto, non il pacco, e in «Spedizione» sparivano con l'articolo che non si spedisce |
| P44 | Via «Spedito» | Un calcolo che nessuno aveva chiesto; il conto resta per le spese di spedizione vere |
| P45 | Le righe eliminate restano annullabili | Cancellare un'opzione è una decisione che si rimpiange, e ricostruirla a mano costa più di un bottone |
| P46 | Gli attributi si scelgono in una riga, con i valori a pillole | Un riquadro che scorre dice «elenco lungo»; cinque taglie non lo sono |
| P47 | Via «Gruppo» dall'anagrafica; l'unità è un elenco; il valore ha una descrizione | Un campo che non legge nessuno chiede lavoro e non dà niente; il testo libero fa scrivere «g» e «grammi» per la stessa cosa |

## 15. Sesto giro: meno spazio, meno parole doppie

Il quinto giro aveva tolto il superfluo; questo lavora su quello che resta
e che si capisce male.

**Le spunte tornano a vedersi.** Riaprendo un articolo l'albero delle
categorie non ne mostrava nessuna: il tema confrontava in modo stretto le
chiavi delle opzioni, che PHP rende intere, con i valori salvati, che
arrivano stringa. Salvando senza toccare l'albero le categorie secondarie si
perdevano. Il confronto ora è fra stringhe, come già nel select, anche nei
gruppi di spunte.

**La categoria principale è una stella.** La principale non è un secondo
dato: è una delle categorie spuntate. Chiederla in un select a parte, prima
dell'albero, nascondeva il legame. Ora c'è un albero solo: ogni voce spuntata
ha una stella, quella piena è la principale, la prima spuntata lo diventa da
sé e un clic sulla stella la sposta. Il campo `main_category` resta, nascosto,
e l'albero lo tiene aggiornato. Se arriva vuoto vale la prima spuntata. Nell'
anagrafica delle categorie «principale» voleva dire «senza padre»: diventa
«Nessuna, sta in cima», così la parola ha un significato solo.

**Una categoria creata dalla scheda sa dove sta.** Il «+ Aggiungi categoria»
passa dal select all'albero e chiede nome e padre; lo store della risorsa
accetta ora anche padre e stato, che prima scartava. La risposta porta la
riga creata, e l'albero mette il nodo sotto il suo padre invece che in cima.

**«Aggiungi opzione», in linea.** Il valore creato dal «+» di un attributo
nasceva come una spunta fuori dalla fila di pillole e non avvisava nessuno:
la griglia non creava le righe. Ora nasce pillola come le altre, già scelta, e
la griglia si aggiorna. Il bottone si chiama «Aggiungi opzione» e sta nella
riga dell'attributo, dopo le pillole.

**«Opzioni in vendita» in meno righe.** Titolo e «Aggiungi un attributo» stanno
sulla stessa riga; il suggerimento e «Le opzioni prendono il nome da…» se ne
vanno, il riepilogo dell'ordine resta solo da due attributi in su. I testi
del riquadro non si avvolgono più in un `<p>`, che lasciava righe vuote.

**Il tipo fiscale ha un predefinito e un posto.** I tipi fiscali hanno il flag
«Predefinito», uno solo come per gli imballaggi; un articolo nuovo parte da
lì (o dal primo visibile, se nessuno è segnato). Con un tipo solo il campo
non si vede. Il campo lascia la card «Prodotto» e va nella card laterale
«Vendita», che prende il posto di «Pubblicazione».

**Foto e video.** L'area dell'articolo non ha più un'etichetta sua sotto il
titolo del riquadro; il tooltip parla di dove compaiono le foto, non delle
misure a cui vengono ridotte.

**Gli attributi chiedono quello che serve al loro tipo.** Oltre a Elenco,
Colore, Testo e Numero c'è il tipo **Fantasia**: il valore ha un'immagine
invece del colore. Un Elenco chiede solo il valore, un Colore valore e colore,
una Fantasia immagine e valore; il valore si allarga nello spazio che le
altre colonne lasciano. La card dei valori e le colonne seguono il tipo mentre
lo si cambia, senza salvare. L'unità nascosta si svuota al salvataggio; colori
e immagini di un attributo che cambia tipo restano nel database.

**I dati di prova hanno nomi veri.** «Prova» davanti a tutto confondeva. I dati
del seed si riconoscono da un marcatore nel codice (`cat_demo-abbigliamento`),
che `--fresh` usa per togliere solo quelli; le tassonomie che un prodotto vero
usa ancora restano, e il comando lo dice. Le righe «Prova …» del vecchio seed
vengono tolte una volta, per nome esatto.

| # | Decisione | Perché |
|---|-----------|--------|
| P48 | Albero e gruppi di spunte confrontano i valori come stringhe | Riaprendo un articolo le spunte non si vedevano e salvando si perdevano |
| P49 | La categoria principale è una stella sull'albero; `main_category` resta nascosto | È una proprietà di una categoria spuntata, non un secondo campo |
| P50 | «Nessuna, sta in cima» al posto di «Nessuna (categoria principale)» | «Principale» voleva dire due cose diverse in due schede |
| P51 | Il «+ Aggiungi categoria» sta sull'albero, chiede il padre e crea il nodo sotto di lui | Dal select la categoria nasceva sempre in cima |
| P52 | Il valore creato dal «+» nasce pillola, scelto, e avvisa la griglia; il bottone è «Aggiungi opzione», in linea | Nasceva fuori fila e non creava le righe |
| P53 | «Opzioni in vendita»: titolo e selettore in una riga, via suggerimenti e testi ripetuti | Spazio che non diceva niente di nuovo |
| P54 | Tipo fiscale con predefinito, nascosto se è uno solo, nella card «Vendita» | Nella card «Prodotto» era un campo obbligatorio che quasi nessuno cambia |
| P55 | Foto: niente etichetta sull'area dell'articolo; il tooltip dice dove compaiono | Il titolo del riquadro basta; le misure non interessano a chi carica |
| P56 | Nuovo tipo di attributo Fantasia; colonne e card dei valori seguono il tipo | Il codice colore non ha senso su una taglia, l'immagine non ha senso su un colore |
| P57 | Seed senza «Prova», riconosciuto da un marcatore nel codice | Il prefisso confondeva, e un prefisso vuoto avrebbe fatto cancellare tutto a `--fresh` |

### Lavori del sesto giro

- [x] core: confronto a stringhe in CheckTree e CheckGroup, con test
- [x] core: `appendCheck` riconosce le pillole e lancia `change`; pillole in linea con il «+»
- [x] core: la risposta del quick-create porta la riga; `primaryField()` sull'albero
- [x] core: regola di visibilità sul contenitore della colonna del repeater; colonna «riempi»
- [x] lib: nodo creato sotto il padre; stella della principale
- [x] modulo: categorie (albero con stella e «+», store con padre, testi)
- [x] modulo: «Opzioni in vendita» compatto e «Aggiungi opzione»
- [x] modulo: tipo fiscale predefinito, card «Vendita»
- [x] modulo: foto e video
- [x] modulo: attributi per tipo, tipo Fantasia
- [x] modulo: seed senza «Prova»
- [x] prova nel browser, guide utente e dev, memoria

Emerso nella prova nel browser, e sistemato nel core: un campo `hidden()` non
prende più una colonna (in una `row g-3` lasciava il margine di una colonna
vuota), e la colonna di un campo con `visibleWhen`/`hiddenWhen` si marca come
contenitore condizionale, così sparisce intera. Nel modal di «Aggiungi
opzione» il campo non dichiarava la larghezza e prendeva una colonna su
dodici: ora ha `->columnSpan(12)`.

## 16. Settimo giro: le foto del colore, i campi spariti, gli attributi spiegati

Alla prova del sesto giro sono venute fuori tre cose che mancavano e due che
non si capivano.

**Il tipo fiscale si vede sempre.** Con un tipo solo il campo spariva (P54):
l'idea era non chiedere una cosa che non ha alternative, ma chi apre la scheda
non sa che il campo esiste, e il giorno in cui gli serve non lo trova. Ora il
select c'è sempre, nella card «Vendita», con il predefinito già scelto e il
«+» per crearne uno nuovo da lì. Un tipo nascosto che un articolo usa ancora
resta nell'elenco di quell'articolo, in coda, come «Nome (nascosto)»: senza,
il select mostrerebbe un altro tipo e salvando lo sostituirebbe. Senza nessun
tipo il campo dice «Nessuno: vale l'aliquota di ripiego» e non è obbligatorio,
perché il calcolo delle imposte ricade già sull'aliquota di ripiego. Il «+»
del quick-create non compare a chi la risorsa la legge soltanto: prima
compariva e rispondeva 403.

**La giacenza c'è anche in creazione.** Senza varianti la casella «Giacenza»
esisteva solo su un articolo già salvato: in creazione chi aveva la merce
davanti non sapeva dove scriverla. Ora c'è sempre. In creazione vale come
carico iniziale — un movimento con causale «giacenza iniziale», come le righe
nuove della griglia (P24) — e si scrive anche con più sedi, perché su un
articolo che nasce non c'è niente da rendere ambiguo: va sulla sede
principale. Dopo il primo salvataggio torna la regola di P35. Una giacenza
negativa scritta a mano si rifiuta; una già negativa per le vendite in
arretrato, lasciata com'era, passa.

**Le foto del colore stanno nella riga del colore.** Le aree «Foto blu», «Foto
rosso» del riquadro «Foto e video» c'erano solo su un articolo salvato (il
colore in creazione non esiste ancora) e stavano lontane dalle righe a cui
appartengono. Ora la testata del gruppo ha, accanto a «Prezzo del gruppo», un
bottone «Foto del colore (n)» che apre un'area di caricamento: in creazione
come in modifica. Il riquadro «Foto e video» tiene solo le foto comuni
dell'articolo; la foto della singola opzione resta dietro «Compila le
informazioni avanzate».

Il bottone compare solo quando il primo attributo è uno con foto proprie (il
colore, livello `variant`): con «Taglia, poi Colore» i gruppi sono taglie, e
una foto della taglia S non vuol dire niente. Per lo stesso motivo la testata
compare anche con un attributo solo, se è quello con foto proprie: la testata
ripete il nome della riga, ma è il posto delle foto del colore, e metterle
altrove per un caso solo vorrebbe dire due posti per la stessa cosa. Aggiungere
dopo un secondo attributo non sposta niente: le foto erano già del colore.

Il gruppo si riconosce dall'id del valore, non dal nome: due colori possono
chiamarsi uguale in due articoli, e un nome si rinomina. Nel framework la
testata diventa persistente — le testate esistenti si riusano invece di
distruggerle e ricrearle a ogni riga aggiunta — perché un'area di caricamento
distrutta perde i file appena scelti. Il campo della testata si posta con la
chiave del gruppo nel nome, e il manifesto è quello di ogni altro campo di
file (P42). Un colore di cui si sono annullate tutte le righe: in creazione le
sue foto si ignorano (il colore non nasce), in modifica si salvano (il colore
c'è ancora, e l'annullamento si può ripensare).

**L'attributo dice a cosa serve, con parole sue.** «Come si usa» elencava tre
voci uguali per tutti i tipi, e una di queste parlava di un «carrello» che non
c'entra. Il campo si chiama **Uso** e offre solo le voci che il tipo permette:

| Tipo | Voci |
|---|---|
| Testo, Numero | Scheda tecnica dell'articolo · Scheda tecnica di ogni opzione |
| Elenco, Colore, Fantasia, Icona | Scheda tecnica dell'articolo · Opzione da scegliere · Opzione con foto proprie |

Dietro restano i tre livelli di sempre (`model`, `product`, `variant`): cambia
solo come si dicono. Un Testo non può essere un'opzione con foto proprie —
non ha valori da scegliere — e il server lo rifiuta con un codice solo.
L'uso non si cambia più su un attributo che sta già su degli articoli:
spostarlo da scheda tecnica a opzione lascerebbe i collegamenti a un livello
che non legge più nessuno. Il campo si spegne, e il tooltip dice di creare un
attributo nuovo.

**Quando un attributo compare nelle opzioni in vendita.** Quando valgono tutte
e quattro: è visibile, il suo uso è «Opzione da scegliere» o «Opzione con foto
proprie», il tipo ha valori (Elenco, Colore, Fantasia, Icona), ed esiste
almeno un valore. Lo dice il tooltip del riquadro «Opzioni in vendita». Se il
negozio non ha nessun attributo così, la domanda «Questo articolo ha
varianti?» non si fa: non avrebbe niente da offrire.

**Il tipo Icona.** Un valore con un'icona invece del colore: «Impermeabile»,
«Lavabile in lavatrice», «Spedizione rapida». L'icona si sceglie da una
raccolta (le Bootstrap Icons, circa duemila, in una griglia con la ricerca
anche in italiano) oppure si carica un'immagine PNG o WebP. SVG no: è un
documento che può portare script, e un'anagrafica non è il posto per
controllarlo. Se ci sono tutti e due vince il file. Nel framework nasce un
input `icon()` riusabile, e le opzioni dei select e delle pillole imparano a
mostrare icona, colore o immagine accanto al nome. Un valore creato dal «+»
della scheda nasce senza icona: si completa dall'anagrafica.

**La personalizzazione non passa dagli attributi.** Un attributo descrive
quello che l'articolo è, e le sue opzioni sono cose che stanno a magazzino.
Un'incisione, un nome ricamato, un biglietto non stanno a magazzino: li
scrive chi compra. Arriva in G5, dopo gli ordini, come **elenco riusabile**,
simile agli attributi: «Incisione, max 20 caratteri, +5 €» si definisce una
volta e si spunta sugli articoli. Il sovrapprezzo sta nell'anagrafica;
l'obbligatorietà si sceglie sul singolo articolo, perché la stessa incisione
è facoltativa su una penna e obbligatoria su una targa. Corregge D23, che
metteva i campi sul modello e li faceva riscrivere uguali su ogni articolo.

| # | Decisione | Perché |
|---|-----------|--------|
| P58 | Il tipo fiscale si vede sempre, con il predefinito; un tipo nascosto in uso resta in coda; senza tipi vale l'aliquota di ripiego. Corregge P54 | Un campo che sparisce non si trova il giorno in cui serve |
| P59 | La giacenza senza varianti c'è anche in creazione, come carico iniziale sulla sede principale; niente negativi scritti a mano. Estende P35 | Chi crea l'articolo ha la merce davanti; su un articolo che nasce non c'è ambiguità fra sedi |
| P60 | Le foto del colore stanno nella testata del gruppo, in creazione e in modifica; il gruppo si riconosce dall'id del valore; la testata c'è anche con un attributo solo se ha foto proprie. Corregge P17, estende P28, P31 e P38 | Le foto vanno dove sta il colore, e in creazione il riquadro non poteva ospitarle |
| P61 | «Come si usa» diventa «Uso», con le voci che il tipo permette; bloccato quando l'attributo è in uso | Tre voci uguali per tutti i tipi non dicevano quale serviva, e cambiare uso a un attributo in uso lascia dati orfani |
| P62 | Tipo Icona: Bootstrap Icons o un'immagine PNG/WebP, il file vince; niente SVG | Le caratteristiche si leggono meglio con un simbolo; un SVG caricato è un documento con script |
| P63 | La personalizzazione è un elenco riusabile in G5, non un attributo; sovrapprezzo sull'anagrafica, obbligo sull'articolo. Corregge D23 | Gli attributi descrivono cose a magazzino; un testo scritto da chi compra no |

### Lavori del settimo giro

- [ ] core: niente «+» del quick-create a chi legge soltanto; guida `quick-create.md`
- [ ] core: opzioni con icona, colore o immagine in select e pillole
- [ ] core: input `icon()` con i due renderer
- [ ] core: testate del repeater persistenti; `repeaterGroupFiles()`; lettura dei file di gruppo
- [ ] lib: selettore delle icone con parole chiave italiane; icone e colori nelle opzioni di Select2
- [ ] modulo: tipo fiscale sempre visibile
- [ ] modulo: giacenza in creazione
- [ ] modulo: «Uso», `levelsFor()`, blocco in uso, tooltip delle opzioni in vendita
- [ ] modulo: tipo Icona
- [ ] modulo: foto del colore nella testata
- [ ] guide utente e dev, spec d'architettura (D23), prova nel browser, memoria

## Piani

Da scrivere dopo l'approvazione.
