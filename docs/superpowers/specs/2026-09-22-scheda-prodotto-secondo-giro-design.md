# G2c — La scheda prodotto, secondo giro

- **Sotto-progetto:** seguito di G2a-bis, prima di G2b
- **Stato:** dodicesimo giro scritto il 2026-09-25 (§21, decisioni P84-P94)
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
Con «Taglia, poi Colore» invece le aree per colore restano nel riquadro «Foto e
video», come prima (e quindi solo su un articolo salvato): le foto del colore
devono stare da qualche parte, e la testata della taglia non è il posto.

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

- [x] core: niente «+» del quick-create a chi legge soltanto; guida `quick-create.md`
- [x] core: opzioni con icona, colore o immagine in select e pillole
- [x] core: input `icon()` con i due renderer
- [x] core: testate del repeater persistenti; `repeaterGroupFiles()`; lettura dei file di gruppo
- [x] lib: selettore delle icone con parole chiave italiane; icone e colori nelle opzioni di Select2
- [x] modulo: tipo fiscale sempre visibile
- [x] modulo: giacenza in creazione
- [x] modulo: «Uso», `levelsFor()`, blocco in uso, tooltip delle opzioni in vendita
- [x] modulo: tipo Icona
- [x] modulo: foto del colore nella testata
- [x] guide utente e dev, spec d'architettura (D23)
- [x] prova nel browser (1600×950: attributo Icona col selettore, Uso per tipo e spento se in uso, tipo fiscale e giacenza in creazione, foto del colore caricate in creazione e rilette in modifica), memoria

## 17. Ottavo giro: l'icona è un'immagine, i campi dicono cosa contengono, la scheda tecnica si trova

Alla prova del settimo giro sono venute tre richieste: l'Icona doveva essere
un'immagine, i campi della scheda dovevano dire cosa contengono, e non si
capiva dove scrivere materiali e lavaggio.

**L'Icona è un'immagine caricata.** Corregge P62. Chi vende ha già i suoi
simboli, come quelli di lavaggio, le certificazioni o «fatto a mano», e una
raccolta generica non li ha. Ogni valore di un attributo Icona si carica
nella riga del valore, come la Fantasia (colonna `image`), in PNG, JPG o WebP.
Conviene un'immagine quadrata con lo sfondo trasparente, perché le opzioni la
mostrano a 16×16 ritagliata.

Il modulo non usa più la raccolta. Si tolgono:
- la colonna `icon` di `gst_attribute_values` (la sincronizzazione del core la
  toglie da sola);
- il campo del model;
- la colonna del repeater;
- la pulizia in `prepareRepeaterRelationRow()`.

`valueVisual('icon', …)` restituisce solo l'immagine. Nel core e nella lib
l'input `icon()` e il selettore restano come input generico, senza
utilizzatori nel modulo.

**I prezzi sono prezzi.** Prezzo, prezzo scontato e la colonna Prezzo della
griglia usano `->price()` del core. Nel core i tre campi numerici nascono in
formato italiano:
- numero e percentuale con la virgola decimale;
- prezzo anche con il punto delle migliaia e « €» in coda, per esempio
  «1.299,90 €».

Cambia solo quello che si vede. Al server arriva il numero grezzo (AutoNumeric
invia il valore senza formato) e il parsing del modulo resta com'è. Un campo
che vuole un formato diverso lo chiede con i setter che esistono già. L'Element
del prezzo smette di dichiararsi anche percentuale (`data-wi-percentige`):
funzionava solo per l'ordine dei cicli nella lib.

**La giacenza dice l'unità.** La giacenza dell'articolo e la colonna Giacenza
della griglia mostrano l'unità dell'articolo dopo il numero: «12 pz»,
«2,500 kg». I decimali dipendono dall'unità:
- nessuno per pz, conf, g e ml;
- tre per kg, l e m.

Se si cambia l'unità nel riquadro Misure, i campi si aggiornano subito,
compreso il modello delle righe nuove.

Una giacenza con decimali su un'unità intera (2,5 pz, scritta prima) si mostra
con i decimali. Arrotondarla vorrebbe dire salvare un movimento di +0,5 che
nessuno ha chiesto. Nel core nascono `integer()` e `suffix()`.
`suffix()` è il simbolo in coda: su un prezzo sostituirebbe il «€», quindi
non va usato lì.

**Le righe nuove della griglia si formattano.** Le righe create dalle spunte
nascevano come caselle di testo semplici, senza € né pz. Ora `setInput()`
della lib avvia AutoNumeric. Anche il repeater del core lo chiama dopo aver
aggiunto una riga, per chi ha una lib più vecchia: la funzione salta i campi
già avviati.

**La descrizione breve è una riga.** È la frase sotto il nome, non un testo:
`->text()->maxLength(255)`. Nel core `maxLength()` arriva all'input di testo
dello schema, e i due renderer lo emettono. La colonna resta `TEXT`. Una
descrizione breve già scritta su più righe si legge su una sola.

**La descrizione ha il grassetto, e poco altro.** Si usa `->textarea('plus')`:
grassetto, corsivo, sottolineato, barrato, link e cancella formato.

Nel core nasce `Field::richText()`:
- sul server salva HTML pulito a lista bianca: `p`, `br`, `strong`/`b`,
  `em`/`i`, `u`, `s`, e `a` solo con `href` http, https, mailto o tel;
- non applica la sanitize né in lettura né in scrittura;
- un editor vuoto salva una stringa vuota.

Il browser pulisce già con DOMPurify, ma il server non si fida del browser.
Una descrizione senza tag, scritta prima, si apre con un paragrafo per riga.
Nella lib si corregge la rilettura: le lettere accentate tornavano rovinate
(`atob` senza UTF-8).

**La scheda tecnica c'è sempre.** Il riquadro «Scheda tecnica» sta sotto
«Misure» anche quando non ha campi. Contiene:
- un campo per ogni attributo visibile con uso «Scheda tecnica dell'articolo»,
  come oggi;
- se non ce n'è nessuno, una riga che dice a cosa serve: materiale,
  composizione, lavaggio;
- il bottone **«Nuova caratteristica»**.

Il bottone apre un modal con Nome, Tipo (Testo o Numero, parte da Testo) e
Unità facoltativa. L'attributo nasce con uso Scheda tecnica, visibile, e non
come filtro. Il suo campo compare subito nel riquadro, vuoto e con il cursore
dentro, e si salva con l'articolo. Il salvataggio rilegge gli attributi dal
database, quindi non serve altro.

Elenchi e Icone (per esempio i simboli di lavaggio) si creano in Catalogo →
Attributi, perché hanno valori e immagini da preparare, e il riquadro lo dice
con il link. Nel core il quick-create si può mettere anche su un bottone
staccato da un campo, `QuickCreateButton`: stesso modal, stessi permessi,
stesso evento `wi:quick-create:created` con `input` vuoto e la riga creata
nell'`item`.

**Più valori per le caratteristiche a elenco.** Un attributo a valori
(Elenco, Colore, Fantasia, Icona) con uso Scheda tecnica si compila con le
pillole invece che con una select. Le pillole mostrano pallino, fantasia o
immagine e accettano più valori per articolo, per esempio «Lavaggio: 30°, non
candeggiare, non asciugare». C'è anche il «+» per un valore nuovo, come nelle
opzioni in vendita.

`ProductAttributes` salva una riga per valore e ne legge una lista, e
`describe()` unisce i valori con la virgola. Le opzioni in vendita restano a
un valore per attributo: una combinazione è una taglia sola.

**Dati di prova.** `CatalogDemo` aggiunge due attributi di scheda tecnica,
compilati su un articolo:
- «Composizione», di tipo Testo;
- «Lavaggio», di tipo Elenco con tre valori.

«Materiale» resta un'opzione, perché serve alla riga «S / Gomma».

**Non in questo giro.** Non ci sono ancora:
- i gruppi dentro il riquadro (`group_name`, `Attributes::grouped()`);
- la lettura della scheda tecnica in vetrina (`describe()` è pronto, lo userà
  il modulo e-commerce);
- un Elenco creato dal bottone del riquadro.

| # | Decisione | Perché |
|---|-----------|--------|
| P64 | Icona = solo un'immagine caricata per valore; via la colonna `icon` dal modulo; `icon()` resta nel core come input generico. Corregge P62 | Chi vende ha già i suoi simboli, e una raccolta generica non li ha |
| P65 | Numero, prezzo e percentuale del core in formato italiano; il prezzo con « €»; prezzi del modulo con `->price()` | «12.50€» si legge male, e un prezzo senza valuta non si distingue da una quantità |
| P66 | Giacenza con l'unità dell'articolo e i decimali dell'unità, aggiornata dal vivo; una giacenza frazionaria non si arrotonda; `integer()` e `suffix()` nel core | «20.000» si leggeva ventimila, e arrotondare scriverebbe movimenti che nessuno ha chiesto |
| P67 | Descrizione breve su una riga, massimo 255 caratteri; `maxLength()` nello schema del core | È la frase sotto il nome |
| P68 | Descrizione con grassetto, corsivo, sottolineato, barrato e link; HTML a lista bianca sul server con `Field::richText()` | Una scheda ha bisogno di poco formato, e il server non si fida del browser |
| P69 | Riquadro «Scheda tecnica» sempre presente, con «Nuova caratteristica» (Testo o Numero) che fa comparire il campo subito; `QuickCreateButton` nel core | Un posto che compare solo dopo averlo preparato altrove non si trova |
| P70 | Attributi a valori di scheda tecnica con più valori per articolo, a pillole e con il «+» | I simboli di lavaggio sono più d'uno |

### Lavori dell'ottavo giro

- [x] core: formato italiano di numero, prezzo e percentuale; `integer()`, `suffix()`; il prezzo senza `data-wi-percentige`
- [x] core: `maxLength()` nello schema dell'input di testo, emesso dai due renderer
- [x] core: `Field::richText()` con la pulizia a lista bianca
- [x] core: `QuickCreateButton`; AutoNumeric dopo `wiRepeaterAddRow`; guide
- [x] lib: AutoNumeric in `setInput()`; `atob` in UTF-8; dist ricostruito
- [x] modulo: Icona = immagine (colonna, campo, repeater, `valueVisual`, test)
- [x] modulo: prezzi con `->price()`, giacenza con unità e decimali, aggiornamento al cambio di unità
- [x] modulo: descrizione breve e descrizione
- [x] modulo: riquadro «Scheda tecnica» sempre presente, «Nuova caratteristica», più valori
- [x] modulo: dati di prova, guide utente e dev, spec d'architettura
- [x] prova nel browser (1600×950: € sui prezzi e *pz* sulla giacenza in creazione, descrizione breve su una riga, editor della descrizione, Icona con immagine png/jpeg, «Nuova caratteristica» Testo e Numero con il campo al suo posto, vuoto e con il cursore dentro, pillole e «Aggiungi valore»), memoria, push dei tre repo

Nota della prova: chiudendosi, il modale di Bootstrap rimette il cursore sul bottone che l'ha aperto, quindi il campo nuovo lo prende su `hidden.bs.modal`. I testi del riquadro usano `->tag('div')`: nel `p` di default di un RichText un `div` o un altro `p` lascerebbero due paragrafi vuoti.

## 18. Nono giro: le opzioni dopo il prodotto, un bottone per aggiungere, una conferma per togliere

*Richiesta dell'utente, con lo screenshot del riquadro.*

**«Opzioni in vendita» subito dopo «Prodotto».** Il riquadro lascia il fondo
della pagina e va nella colonna larga, dopo «Prodotto»: risponde alla domanda
«ha varianti?», che sta lì. Il motivo che l'aveva spinto sotto le due colonne
(§12, sette caselle per riga che andavano a capo) non c'è più: con la griglia
raggruppata la riga ha quattro caselle e il resto è nei dettagli avanzati.
Provato a 1600×950, con i dettagli aperti: ci sta.

**«Aggiungi un attributo» è un bottone largo quanto il riquadro, sotto
l'ultimo attributo.** Via la select accanto al titolo (P53): si notava poco, e
l'attributo scelto compariva lontano dal clic. Il bottone apre un menu con gli
attributi non ancora aggiunti; al terzo lascia il posto alla riga «Tre
attributi sono il massimo». Il `Dropdown` del core non allarga toggle e menu,
quindi resta HTML scritto a mano in un `RichText`.

**Togliere un attributo chiede conferma.** La × apre la finestra del repeater
(`wiRepeaterConfirmDelete`, «Annulla» / «Togli» in rosso); il testo dice cosa
si perde: le spunte e le righe nuove, oppure solo il blocco se non c'era
niente di spuntato.

**«Prodotto» senza il titolo «Descrizione».** Le etichette dei due campi lo
dicono già.

**Il tipo fiscale in un riquadro suo**, nella colonna stretta, con la select
sola ed etichetta «IVA» (una select *floating* senza etichetta mostrerebbe
solo l'asterisco).

**«Vendita» diventa «Come si vende»,** con tre interruttori dai nomi corti e
una riga sotto che li spiega:

| Prima | Adesso | Riga sotto |
|---|---|---|
| Si vende online | Acquistabile online | Spento, resta per il negozio e per i documenti. |
| Si può rendere | Accetta resi | Il cliente può restituirlo dopo l'acquisto. |
| Si spedisce | Da spedire | Spento per servizi, buoni regalo e prodotti digitali. |

| # | Decisione | Perché |
|---|-----------|--------|
| P71 | «Opzioni in vendita» nella colonna larga, subito dopo «Prodotto». Corregge §12 | Risponde alla domanda sulle varianti; la griglia raggruppata ci sta |
| P72 | «Aggiungi un attributo» è un bottone a tutta larghezza sotto l'ultimo attributo, con menu. Corregge P53 | Si trova, e l'attributo compare dove si è cliccato |
| P73 | Togliere un attributo chiede sempre conferma | Un clic storto perdeva spunte e righe |
| P74 | Tipo fiscale in un riquadro suo, etichetta «IVA»; «Vendita» diventa «Come si vende» con tre interruttori e una riga di spiegazione ciascuno. Estende P58 | Tre domande sì/no si leggono meglio con un nome corto e una riga sotto |

### Lavori del nono giro

- [x] modulo: layout a due colonne con le opzioni dopo «Prodotto»; via il titolo «Descrizione»
- [x] modulo: bottone largo con menu, conferma sulla ×
- [x] modulo: riquadri «Come si vende» e «Tipo fiscale», etichette e righe sotto
- [x] modulo: test, guide utente e dev, `composer.lock` allineato a `^2.4.0-beta.1`
- [x] prova nel browser (1600×950: riquadro dopo «Prodotto», menu largo senza l'attributo già attivo, attributo nuovo sopra il bottone, finestra di conferma e rimozione, limite di tre, «IVA» sulla select)

## 19. Decimo giro: foto in cima a destra, l'imballaggio sotto «Da spedire», i codici sotto il prezzo

*Richiesta dell'utente.*

**«Foto e video» in cima alla colonna stretta,** sopra «Come si vende». Le
aree dei colori (con «Taglia, poi Colore») restano nello stesso riquadro.

**L'imballaggio sotto «Da spedire».** Il riquadro «Spedizione» non c'è più:
aveva un campo solo, e il server lo includeva o no guardando l'articolo salvato
(`shipsFrom()`), quindi spegnere l'interruttore non lo toglieva fino al
salvataggio. Ora `package_id` sta in «Come si vende» con
`visibleWhen('requires_shipping', 'true')` e segue l'interruttore al volo. Da
spento il campo viene postato lo stesso, con il valore di prima: riaccendendo,
la scatola torna.

**«Misure» a destra,** in fondo alla colonna stretta, due caselle per riga: in
un terzo di schermo quattro affiancate non si leggono. La colonna larga resta
con «Prodotto», «Opzioni in vendita» e «Scheda tecnica».

**SKU ed EAN sotto Prezzo e Prezzo scontato,** nel riquadro «Prodotto», larghi
come le due caselle sopra (4 dodicesimi, 3 con la scorta minima). Hanno già
`hiddenWhen('has_variants', 'true')`: con le varianti spariscono con il
prezzo, e lo SKU resta nel modulo a proporre i codici delle righe. Il riquadro
«Codici» non c'è più; il suo suggerimento sullo SKU di famiglia passa nel
tooltip di «Prodotto».

| # | Decisione | Perché |
|---|-----------|--------|
| P75 | «Foto e video» in cima alla colonna stretta; «Misure» in fondo, due per riga. Corregge le due colonne di G2a-bis | Le foto si vedono subito accanto al nome; la colonna larga resta per quello che si compone |
| P76 | L'imballaggio sotto «Da spedire», con visibilità legata all'interruttore; via il riquadro «Spedizione» e `shipsFrom()` | Un campo solo non vale un riquadro, e deve sparire quando si spegne l'interruttore, non al salvataggio |
| P77 | Senza varianti SKU ed EAN sotto prezzo e scontato, nel riquadro «Prodotto»; via il riquadro «Codici» | Sono dell'unico articolo, come il prezzo: stanno dove si scrive il prezzo |

### Lavori del decimo giro

- [x] modulo: `sideColumn()` con foto, «Come si vende» + imballaggio, tipo fiscale, dove si trova, misure; `mainColumn()` con prodotto, opzioni, scheda tecnica
- [x] modulo: SKU ed EAN nella riga sotto il prezzo; via «Codici», «Spedizione» e `shipsFrom()`
- [x] modulo: test, guide utente e dev
- [x] prova nel browser (1600×950: foto sopra «Come si vende», imballaggio che sparisce e torna con «Da spedire», misure due per riga in fondo a destra, SKU sotto Prezzo ed EAN sotto Prezzo scontato in creazione, spariti accendendo le varianti)
- [x] review: con «Da spedire» spento `mutateRequestValues()` toglie `package_id` — nascosto viene postato lo stesso, e una scatola «Ferma», che non è fra le scelte, si sarebbe persa; test più stretto su SKU ed EAN nel riquadro «Prodotto»; tooltip, commenti e guide che parlavano ancora del prezzo «comando», del riquadro «Spedizione» e della griglia sotto le colonne

## 20. Undicesimo giro: la stella che torna cartella, la scheda tecnica che mostra solo il necessario, la scorta minima fra i codici

*Richiesta dell'utente.*

**La categoria principale.** La prima categoria spuntata prende la stella, le
altre no; tolta la spunta alla principale, torna cartella e si può rispuntare.
Il difetto era nella lib: con `tie_selection`, il valore di partenza di
jstree, spuntare passa per `changed.jstree` e non per `check_node`, e
`setJsTreePrimary` non se ne accorgeva (lib `28e4f83`, con un test su un
albero finto).

**La scheda tecnica mostra solo quello che è compilato.** Ogni caratteristica
è un blocco con id e nome; lo script nasconde i vuoti e li mette nel menu di
un bottone tratteggiato «Aggiungi caratteristica», come «Opzioni in vendita».
Scelta una voce, il blocco compare con il cursore dentro. La × in alto a destra
del campo lo toglie: lo svuota, perché nascosto verrebbe postato lo stesso, e
chiede conferma solo se c'era scritto qualcosa. «Nuova caratteristica…» e il
link a Catalogo → Attributi stanno in fondo al menu; il bottone del quick
create resta nella pagina, nascosto, e il menu lo clicca.

**«Compila le informazioni avanzate» anche senza varianti.** SKU ed EAN
lasciano la riga sotto il prezzo per una tendina chiusa, la stessa delle righe
della griglia, e con gli avvisi di scorta minima accanto c'è la **Scorta
minima** (la «giacenza minima» della richiesta): la riga del prezzo torna di
tre caselle. Con le varianti la scorta minima sta nella tendina di ogni riga,
fra EAN e Stato. Il core ha la variante a link dell'`Accordion`
(`Accordion::link()`), e il layout dei form sposta sulla colonna le regole di
visibilità di un accordion, come per il `Container`.

| # | Decisione | Perché |
|---|-----------|--------|
| P78 | La prima categoria spuntata è la principale; tolta la spunta, la vecchia principale torna cartella | Era già l'intenzione di P49: il difetto era un evento di jstree mai ascoltato |
| P79 | La scheda tecnica mostra solo i campi compilati; gli altri stanno nel menu «Aggiungi caratteristica», con «Nuova caratteristica…» in fondo. Corregge il riquadro con tutti i campi di §17 | Con dieci caratteristiche il riquadro era una lista da scorrere; ogni articolo ne usa poche |
| P80 | Togliere una caratteristica la svuota e al salvataggio la cancella; conferma solo se c'era un valore | Nascosto un campo viene postato lo stesso; un campo vuoto non ha niente da perdere |
| P81 | Senza varianti SKU, EAN e scorta minima in «Compila le informazioni avanzate» sotto il prezzo; con le varianti la scorta minima in ogni riga della griglia. Corregge P77 | Sono codici e soglie che si toccano di rado, come nelle righe della griglia; la soglia è di ogni opzione |
| P82 | `Accordion::link()` nel core: bottone di testo con la freccia, niente cornice, corpo a griglia | Dentro un riquadro già incorniciato un secondo bordo pesa; è la forma del repeater |
| P83 | Al salvataggio prima le soglie, poi i pezzi; accendendo le varianti la riga della prima combinazione, dove è scritta, vince su quella dello scheletro che il generatore riprende, e i suoi pezzi sono una rettifica; su un articolo appena creato è tutto carico iniziale; la griglia vale anche quando l'interruttore disabilitato manda «no» | Con la soglia vecchia un movimento apriva e chiudeva avvisi finti, che arrivano per email; lo scheletro ha già i suoi pezzi, e sommarli raddoppiava la giacenza; una riga nuova nasce vuota e non deve cancellare quello che c'era |

### Lavori dell'undicesimo giro

- [x] lib: `setJsTreePrimary` ascolta `changed.jstree`; test `backend-tree-primary`
- [x] core: `Accordion::link()` e `isLink()`, `renderLink()` nel tema Bootstrap, regole di visibilità sulla colonna in `ResourceFormLayoutRenderer`; test `AccordionGridTest`; CSS della freccia nella lib
- [x] modulo: tendina «Compila le informazioni avanzate» sotto il prezzo; colonna `min_stock` nella griglia, `assertMinStocks()`, `saveMinStocks()`, rilettura in `mutateFormValues`, AutoNumeric con l'unità
- [x] modulo: `technicalBlock()`, menu «Aggiungi caratteristica», × con conferma, blocco nuovo dal `<template>` al quick create
- [x] modulo: test, guide utente e dev
- [x] modulo, dopo la review: `saveMinStocks()` prima dei movimenti, `adjustStock()`, scheletro ripreso che si rettifica (solo se c'era prima della richiesta, e la riga vuota non cancella la vecchia), `hasVariants()` in `saveExtras()`; test in `CombinazioniTest`
- [x] prova nel browser (1600×950: stella alla prima spunta e cartella togliendola; scheda tecnica vuota con il solo bottone, voce del menu che accende il blocco, × con e senza conferma, salvataggio che cancella il valore tolto, caratteristica nuova dal quick create; tendina con SKU, EAN e Scorta minima sotto Prezzo, Prezzo scontato e Giacenza; nella griglia SKU, EAN, Scorta minima e Stato)

## 21. Dodicesimo giro: vendere senza giacenza è una scelta dell'articolo, il costo d'acquisto sta fra i codici

*Richiesta dell'utente.*

**«Vendita senza giacenza» è un interruttore dell'articolo.** Con la
funzionalità `backorders` accesa, «Come si vende» ha un quarto interruttore
sotto «Da spedire»: **Vendita senza giacenza**, Sì/No. Acceso, compare
**Giorni di attesa** (i giorni che servono per riavere la merce); spento, i
giorni tornano a zero, come l'imballaggio sotto «Da spedire». È una scelta di
tutto l'articolo: al salvataggio si scrive su ogni opzione, anche su quelle che
nascono in quel momento; riaprendo la scheda l'interruttore è acceso solo se
lo è su tutte. Senza `backorders` l'interruttore non c'è e quello che arriva
non si scrive. Un'impostazione di partenza del negozio non c'è ancora: le
impostazioni sono di un altro lavoro.

**La giacenza scende sotto zero solo se l'opzione lo permette.** Finora
bastava la funzionalità accesa; da qui serve anche l'interruttore
sull'opzione (`allow_backorder`). Con la funzionalità accesa e l'interruttore
spento, per quell'opzione la giacenza resta un muro: `stock.insufficient`,
come senza funzionalità. Il muro è solo per i movimenti che tolgono: un carico
entra sempre, anche se la giacenza resta sotto zero (da -5, un carico di 2
porta a -3). La regola sta in `Stock::apply()`, e vale anche per i documenti
di carico e scarico di G2b-bis, che passano da lì.

**Il costo d'acquisto è per fornitore.** Con `purchasing` acceso ogni opzione
può avere più fornitori, ognuno con il suo codice e il suo costo, e uno è il
preferito: è quello che si mostra e che servirà agli ordini ai fornitori. La
tabella nuova è `gst_product_suppliers` (opzione, fornitore, codice del
fornitore, costo, preferito, posizione). I fornitori sono i contatti con
`is_supplier`; un costo senza fornitore non si salva.

**Con al più un fornitore in anagrafica, tre campi.** In «Compila le
informazioni avanzate», dopo SKU, EAN e scorta minima: **Fornitore**
(tendina), **Codice fornitore** e **Costo d'acquisto** (prezzo con la
valuta). Valgono per il fornitore preferito. Svuotare il fornitore lo
stacca dall'opzione. Vale per l'articolo senza varianti, nella tendina sotto il
prezzo, e per ogni riga della griglia.

**Con due o più fornitori, il bottone «Costo».** Al posto dei tre campi c'è un
bottone **Costo** con accanto il preferito e il suo costo («Filati Nord ·
12,00 €»), o «Nessun fornitore». Il bottone apre una finestra sola per tutta la
pagina, **Costo · <nome dell'opzione>**, con tutti i fornitori del
negozio, uno per riga: **Preferito** (radio), nome, **Codice fornitore**,
**Costo** e una «x» che lo stacca; in fondo [Annulla] [Salva]. Sono legati
all'opzione i fornitori che aveva già, anche senza codice né costo (le tre
caselle e la scheda dell'opzione salvano il fornitore da solo), e quelli
toccati nella finestra: un codice, un costo, il preferito. Per staccarne uno
c'è la «x», che ne svuota anche i campi. Salva scrive i dati nella riga e
aggiorna il testo accanto al bottone; la scheda si salva con il suo Salva. Se
il preferito scelto è vuoto, o non ce n'è uno, diventa preferito il primo
legato.

**La scheda dell'opzione ha l'elenco intero.** In Catalogo → Prodotti, aprendo
un'opzione, con `purchasing` c'è un riquadro **Fornitori** con tutte le righe:
fornitore, codice, costo, preferito.

**Costo vuoto vuol dire «non lo so».** Un costo non scritto si salva vuoto
(`NULL`), non zero: uno zero farebbe del fornitore il più conveniente e
abbasserebbe il valore del magazzino. Nella scheda il costo ha due decimali,
nella tabella quattro, come il costo dei movimenti.

**Cosa ferma la scheda.** Prima di scrivere l'articolo: due righe per lo stesso
fornitore, un fornitore che l'opzione non può avere, un costo negativo, non
numerico (`product.supplier_cost_invalid`) od oltre quello che la colonna tiene
(`product.supplier_cost_too_high`), un codice fornitore oltre cento caratteri
(`product.supplier_sku_too_long`). Il core scrive l'articolo prima dei
fornitori, e un rifiuto del database a metà lascerebbe i legami salvati a
metà.

**Quando si mostra il bottone.** Contano i fornitori che la pagina può
proporre: quelli attivi più quelli già legati alle opzioni dell'articolo. Un
fornitore messo su «Non attivo» resta nella scelta delle opzioni che lo usano,
con «(non attivo)» accanto al nome, e non compare per le altre: senza, la
tendina posterebbe un valore vuoto e staccherebbe il fornitore. Le scelte sono
quindi **per opzione**: gli attivi più i fornitori che lei ha già. Una riga
nuova della griglia ha solo gli attivi. La scheda rifiuta un fornitore non
attivo su un'opzione che non lo aveva, e nel browser la finestra e le tendine
non lo propongono. Il bottone o i tre campi si decidono per articolo; al
salvataggio però conta quello che arriva: se nel frattempo il modo è cambiato,
un JSON o tre caselle postati si leggono lo stesso, e le modifiche non
spariscono.

**Cancellare.** Un fornitore legato a opzioni in vendita non si elimina e non
perde il ruolo di fornitore (`contact.supplier_in_use`,
`contact.supplier_role_in_use`): si mette su «Non attivo». Il legame sparisce
con l'opzione, quando la si toglie dalla griglia o si elimina l'articolo: è il
costo di oggi, lo storico sta nei movimenti. Due righe per lo stesso fornitore
sulla stessa opzione le rifiuta la scheda (`product.supplier_duplicate`), non
un indice unico: il repeater prima scrive e poi toglie, e uno scambio di righe
lo farebbe inciampare a metà salvataggio.

**Nel core.** `FormField::button()` (`InputButton`): un bottone fra i campi,
anche come colonna di un repeater, senza `name`, che apre una finestra con
`opensModal()`. Il componente `Modal` (titolo, corpo a griglia, bottoni in
fondo): si scrive nel layout come un `Accordion` e lo script lo sposta in fondo
al `body`, così i suoi campi non partono con la scheda. `InputRadio::pills()`,
come per le spunte, per il «Preferito» di ogni riga della finestra.

**Anticipa G4.** L'architettura (D60) teneva `allow_backorder` e
`backorder_lead_days` nascosti fino a G4; questo giro li mostra, sempre solo
con `backorders` acceso. Il resto di G4 (date di consegna, messaggi al
cliente) resta lì.

**Fuori da questo giro.** «+ Nuovo fornitore» dalla tendina (serve un'API dei
fornitori), il valore del magazzino, l'aggiornamento del costo dal documento di
carico (è di G2b-bis).

| # | Decisione | Perché |
|---|-----------|--------|
| P84 | Con `backorders`, «Vendita senza giacenza» in «Come si vende» sotto «Da spedire», con «Giorni di attesa» che compare con l'interruttore; spento, i giorni tornano a zero | Chi vende decide articolo per articolo, e un'attesa senza vendita scoperta non vuol dire niente |
| P85 | L'interruttore è dell'articolo: si scrive su tutte le opzioni, anche le nuove; riaprendo è acceso solo se lo è su tutte | Chi vende pensa all'articolo; un'opzione rimasta indietro deve vedersi |
| P86 | Sotto zero solo con `backorders` e `allow_backorder` sull'opzione; altrimenti `stock.insufficient`, ma solo per i movimenti che tolgono: un carico entra sempre. Corregge la regola di G2b | La funzionalità dice che si può, l'articolo dice se lo vuole; rifiutare un carico lascerebbe la giacenza più in basso di quello che c'è |
| P87 | Costo per fornitore in `gst_product_suppliers`, un preferito per opzione; solo con `purchasing` | Lo stesso articolo si compra da più parti a prezzi diversi |
| P88 | Con al più un fornitore, Fornitore, Codice fornitore e Costo d'acquisto nella tendina dei codici; svuotare il fornitore lo stacca | Stanno con SKU ed EAN, e con un fornitore solo una finestra non serve |
| P89 | Con due o più fornitori, bottone «Costo» con il preferito accanto e una finestra con tutti i fornitori, preferito a radio | Una tabella dentro una riga della griglia non ci sta; il riassunto dice quello che serve senza aprire |
| P90 | La scheda dell'opzione ha il riquadro «Fornitori» con l'elenco intero | È il posto dove si guarda un'opzione sola |
| P91 | Costo vuoto = sconosciuto (`NULL`); due decimali nella scheda, quattro nella tabella | Uno zero finto sposterebbe la scelta del fornitore e il valore del magazzino |
| P92 | Il bottone compare con due o più fornitori proponibili: gli attivi più quelli già legati all'articolo; un fornitore non attivo resta nella scelta delle opzioni che lo usano, e solo di quelle (server e browser) | Altrimenti un salvataggio staccherebbe un fornitore solo perché è stato disattivato, o lo legherebbe a un'opzione nuova |
| P93 | Un fornitore legato a opzioni in vendita non si elimina e non perde il ruolo; il legame sparisce con l'opzione; i doppioni li rifiuta la scheda | La chiave esterna fermerebbe l'eliminazione con un errore del database; il legame è il costo di oggi, non storia |
| P94 | Nel core `FormField::button()`, il componente `Modal` spostato nel `body`, `InputRadio::pills()` | I campi passano sempre dal core; una finestra dentro il form ne posterebbe i campi |

### Lavori del dodicesimo giro

- [ ] core: `InputButton` e `FormField::button()`, componente `Modal`, `InputRadio::pills()`; test e documentazione
- [ ] modulo: interruttore «Vendita senza giacenza» e «Giorni di attesa» in «Come si vende», scrittura su tutte le opzioni, rilettura «acceso se lo è su tutte»
- [ ] modulo: `Stock::apply()` con `allow_backorder`; test in `LowStockTest`
- [ ] modulo: modello `ProductSupplier` e tabella `gst_product_suppliers`; elenco dei fornitori per le tendine
- [ ] modulo: campi Fornitore, Codice fornitore, Costo d'acquisto; bottone «Costo» e finestra; salvataggio e rilettura
- [ ] modulo: riquadro «Fornitori» nella scheda dell'opzione
- [ ] modulo: pulizia quando si cancellano opzioni e fornitori; codici d'errore; dati di prova
- [ ] modulo: test, guide utente e dev
- [ ] prova nel browser (1600×950)
- [x] modulo e core, dopo la review: controlli di costo e codice prima dell'insert, forma dei fornitori dal post, legami della finestra tenuti e «x» per staccare, P92 per opzione (server e browser), id di riga della scheda dell'opzione limitati all'opzione, carico sempre ammesso nei documenti, fornitori della riga nuova che riprende lo scheletro aggiunti ai suoi (`mergeSupplierRows()`: la riga nuova vince fornitore per fornitore), conto della pulizia di prova con i legami delle opzioni nel cestino; nel core id e `for` delle righe aggiunte dal repeater, pillole senza titolo con `label('')`

## 22. Tredicesimo giro: i fornitori sono dell'articolo, la giacenza ha una sede, lo sconto resta, le personalizzazioni prendono forma

*Richiesta dell'utente, in quattro punti: il costo per fornitore va bene ma
meglio a righe, come le opzioni di vendita, e il preferito forse non serve;
con più sedi che cosa sono la giacenza e la scorta minima della scheda; il
«Prezzo scontato» si tiene o no, e se si tiene va anche nelle opzioni; manca
ancora la personalizzazione.*

Dipende da G2b-bis, piano 2: `Locations::shown()` è la regola che dice quali
sedi contano, e la scrive quel piano. Questo giro la usa e non la ridefinisce.

### 22.1 I fornitori sono dell'articolo

*Superata dal quattordicesimo giro (§23): i fornitori tornano all'opzione.*

**Le righe stanno nella scheda dell'articolo, non fra i codici.** Il
dodicesimo giro aveva messo fornitore, codice e costo nella tendina dei codici
dell'opzione, con una finestra da due fornitori in su (P88, P89). Chi vende
però compra l'articolo, non l'opzione: la maglia si prende da Filati Nord in
tutte le taglie. Con la funzionalità `purchasing` accesa la scheda
dell'articolo ha il riquadro **Fornitori** dopo «Come si vende», con o senza
varianti: un repeater del core con le colonne **Fornitore** (tendina),
**Codice fornitore** e **Costo** (input prezzo, due decimali), il bottone
«Aggiungi fornitore», le righe che si trascinano e la conferma prima di
toglierne una, come per le opzioni (§18). Le righe si scrivono nella tabella
nuova `gst_product_model_suppliers` (`product_model_id` → `gst_product_models`,
`supplier_id` → `gst_contacts`, `supplier_sku` a 100 caratteri, `cost`
`DECIMAL(12,4)` a `NULL`, `position`), tramite `RepeaterRelation` come i
fornitori della scheda dell'opzione. La tendina propone i fornitori attivi più
quelli già legati all'articolo (P92, spostato dall'opzione all'articolo);
senza nessuno da proporre il riquadro dice «Nessun fornitore da proporre:
aggiungilo da Anagrafiche → Fornitori». Lo stesso fornitore due volte, un
costo oltre `MAX_COST` e un codice più lungo di 100 caratteri li rifiuta la
scheda con i codici d'errore di oggi (`ProductSuppliers::assertValid()`); una
riga senza fornitore non si scrive. Nessun indice unico articolo×fornitore,
per la stessa ragione di `gst_product_suppliers` (il repeater prima scrive e
poi toglie).

**La griglia perde tre campi, un bottone e una finestra.** Fornitore, Codice
fornitore e Costo escono dalla tendina dei codici, e con loro il bottone
«Costo», il campo nascosto con il JSON, la finestra `supplierCostModal()` e il
suo script. P87–P90 sono superate; resta P91 (costo vuoto = sconosciuto, due
decimali nella scheda e quattro nella tabella) e P93 esteso: un fornitore
legato ad articoli **o** a opzioni non si elimina e non perde il ruolo
(`countForSupplier()` conta le due tabelle), il legame sparisce con
l'articolo o con l'opzione (`dropForRemovedModels()` accanto a
`dropForRemovedProducts()`; l'eliminazione dell'articolo si coordina con il
lavoro sulle eliminazioni, che ha `tests/integrazione/EliminazioniTest.php`).

**L'opzione può fare eccezione.** `gst_product_suppliers` resta, senza la
colonna `is_preferred` (il core la toglie da sé perché sparisce dallo schema;
le righe esistenti restano come eccezioni). La scheda dell'opzione tiene il
riquadro «Fornitori» senza la colonna «Preferito», con il titolo che spiega,
in tooltip, «Vale solo per questa opzione e vince sui fornitori
dell'articolo», e sopra le righe una riga di contesto: «Dall'articolo: Filati
Nord · 12,00 € · Lana Sud · 11,50 €», oppure «L'articolo non ha fornitori».
Il costo che vale per un'opzione lo dice `ProductSuppliers::effective(array
$modelRows, array $optionRows)`, pura: fornitore per fornitore vince la riga
dell'opzione, i fornitori dell'articolo senza riga nell'opzione valgono lo
stesso, nell'ordine dell'articolo e poi quelli soli dell'opzione. Nessun
preferito: `preferOne()`, `preferred()` e `summary()` spariscono; quando un
lavoro futuro avrà bisogno di *un* costo (il valore del magazzino di una riga
senza fornitore, G3) prenderà il primo per posizione, e lo dirà la sua spec.

**Dati di prova.** `CatalogDemo` lega i fornitori agli articoli
(`gst_product_model_suppliers`) e lascia una sola eccezione su un'opzione, per
vedere la riga di contesto e la vittoria dell'eccezione.

### 22.2 La giacenza ha una sede

**Oggi la scheda parla del totale.** I pezzi stanno già per sede in
`gst_stock`; la scheda mostra la somma, con due o più sedi la casella è in
sola lettura (`stockIsWritable()`), un articolo nuovo carica la giacenza sulla
sede principale senza dirlo, e la scorta minima è del prodotto sul totale
(architettura 4.3, G2b-bis §2). Con più sedi chi compila non sa di quale sede
sta parlando: la risposta è che ogni riga dice la sua.

**Più sedi vuol dire due o più sedi da mostrare** (`Locations::shown()`,
G2b-bis §2). Con una sola sede **niente cambia sullo schermo**: Giacenza e
Scorta minima restano dove sono, nella tendina dei codici dell'articolo senza
varianti, nelle colonne della griglia e nella scheda dell'opzione, e parlano
della sede principale (`Locations::mainId()`).

**La scorta minima ha la sua tabella.** `gst_stock_thresholds`: `product_id`
→ `gst_products`, `location_id` → `gst_locations`, `quantity`
`DECIMAL(12,3)`, un indice unico prodotto×sede (qui il salvataggio non
scambia righe: scrive per sede). La colonna `gst_products.min_stock_quantity`
esce **subito** dallo schema, senza copia: il core la toglie al primo
`forge update`, e i valori di oggi (solo dati di prova) si perdono; copiarli
non si può, perché il core allinea le tabelle prima di dare la parola al
modulo. Il servizio `Support\Stock\Thresholds` legge e scrive le soglie
(`forProduct()`, `forProducts()`, `save($productId, $byLocation)` che
inserisce, aggiorna e cancella, `dropFor()`); una soglia vuota o a zero è una
riga in meno.

**Articolo senza varianti, più sedi: righe per sede.** Al posto delle due
caselle c'è il riquadro **Magazzino** dopo i codici, con il repeater
**Giacenza per sede**: colonne **Sede** (tendina sulle sedi da mostrare),
**Giacenza** e **Scorta minima**, il bottone «Aggiungi sede», la conferma
prima di togliere una riga. Il repeater non ha relazione: le righe le compone
`Support\Stock\LocationRows` (pura) da `Levels::byLocation()` e dalle soglie,
una riga per ogni sede che ha pezzi o una soglia, nell'ordine di `shown()`;
un articolo nuovo parte con una riga sulla sede principale, vuota. Al
salvataggio ogni riga si legge con `Stocktake`: la Giacenza è la quantità
che si vuole (casella vuota = non toccare, zero scritto = zero) e diventa una
rettifica su quella sede con `Stock::apply()` e la causale predefinita, o il
carico iniziale se l'articolo nasce ora; la Scorta minima va nella soglia di
quella sede. Una sede tolta dalle righe perde la soglia e **non** i pezzi: la
riga ricompare finché ha giacenza. La stessa sede due volte e una sede fuori
da `shown()` sono errori (`stock.location_duplicate`,
`stock.location_unknown`); una riga senza sede non si scrive.

**Griglia con più sedi: il totale e un bottone.** La colonna *Giacenza*
mostra il totale in sola lettura, come oggi; la colonna *Scorta minima* lascia
il posto al bottone **Giacenza** con accanto il riassunto «Milano 12 · Roma
3». Il bottone apre una finestra con le stesse righe [Sede · Giacenza ·
Scorta minima], «Aggiungi sede» e una «x» per riga: campi del core dentro
`Modal`, un campo nascosto `product_locations` con il JSON per ogni riga della
griglia, «Salva» che riscrive JSON e riassunto, come faceva la finestra dei
costi. Il server legge il JSON con `LocationRows::fromForm()` e salva con lo
stesso codice della scheda dell'articolo. Con una sede sola le colonne restano
quelle di oggi.

**Scheda dell'opzione, più sedi.** La casella «Scorta minima» del riquadro
*Magazzino* lascia il posto allo stesso repeater «Giacenza per sede»; la riga
«Giacenza 15 · Rettifica» e gli ultimi movimenti restano.

**Gli avvisi sono per prodotto e sede.** `Levels::byLocation($productId)`
dà giacenza, impegnati e disponibili per sede (le prenotazioni hanno già
`location_id`). `Alerts::refresh($productId)` passa ogni sede con una soglia,
confronta il disponibile della sede con la sua soglia (`LowStock::decide()`
non cambia) e tiene aperto al più un avviso per prodotto e sede, con
`gst_stock_alerts.location_id` compilato; un avviso senza sede (`0`, di
prima) o di una sede che non ha più soglia si chiude al primo giro, come gli
avvisi orfani. `LowStockReport`, l'email, il riquadro *Sotto scorta* e
`gestionale:stock-alerts` ragionano per prodotto e sede e mostrano la colonna
**Sede** solo con più sedi. `LevelsSql::openAlert()` continua a dire «almeno
un avviso aperto» e non cambia.

**Le spec.** L'architettura 4.3 ora dice «soglia per prodotto e sede» e non
rimanda più la soglia per sede a dopo; la tabella `stock_thresholds` sta con
quelle del magazzino. In G2b-bis §2 la frase «La scorta minima è della
versione, non della sede: il rosso e il badge stanno sul *Totale*» è superata:
il rosso sta sulla sede sotto soglia, e con una sede sola coincide con il
totale. Quella spec non la tocca questo giro.

**Dati di prova.** Le soglie di prova stanno sulla sede principale, una
versione per articolo sotto soglia, come prima.

### 22.3 Il prezzo scontato resta

**Serve per uno sconto su un prodotto solo.** Le campagne scontano categorie,
tag, marchi e articoli interi, mai una singola opzione (architettura 4.6), e
D19 lascia al commerciante «Prezzo» e «Prezzo scontato». Il campo resta, senza
date: per una promozione con le date ci sono le campagne. Nella scheda
dell'articolo senza varianti non cambia niente; nella griglia arriva la
colonna **Scontato** dopo *Prezzo*, con il nome dell'opzione un po' più
stretto; la scheda dell'opzione usa l'input prezzo del core (`price()`) per
tutti e due i campi, come l'articolo (§17); accendere le varianti copia anche
`sale_price` nello scheletro, come il prezzo.

### 22.4 Le personalizzazioni prendono forma sulla carta

**Solo spec: il codice è di G5**, perché la funzionalità `customizations`
richiede `orders` (architettura 4.2, D23; 4.7). Il disegno, perché la scheda
non lo dimentichi:

- **Catalogo → Personalizzazioni**: `gst_customizations` (`name`, `kind`
  `text`/`choice`, `label` per il cliente, `max_length`, `surcharge`
  `DECIMAL(12,2)`, `active`) e `gst_customization_options` (`customization_id`,
  `label`, `surcharge`, `position`) per le scelte; le tabelle e la pagina si
  disegnano come gli attributi (G2a, piano 2).
- **Nella scheda dell'articolo**, sotto «Scheda tecnica», il riquadro
  **Personalizzazioni**: righe [Personalizzazione (tendina) · Obbligatoria],
  «Aggiungi» e «Nuova personalizzazione…» con il `quickCreate()` del core;
  ponte `gst_product_model_customizations` (`product_model_id`,
  `customization_id`, `is_required`, `position`). È dell'articolo, come i
  fornitori.
- **In vetrina** i campi compaiono sulla pagina del prodotto e viaggiano nel
  carrello; l'ordine tiene `order_items.customization` (JSON) e
  `customization_surcharge` (architettura 4.7).

### Fuori da questo giro

- Il codice delle personalizzazioni (G5).
- Il valore del magazzino con il costo del fornitore, e quale costo prendere
  senza un preferito (G3).
- Il rosso per sede nell'elenco *Giacenze* e il suo filtro «Scorta»: è di
  G2b-bis, piano 2, che lavora in parallelo; si allinea a questo giro quando
  arriva.

| # | Decisione | Perché |
|---|-----------|--------|
| P95 | Fornitori dell'articolo in `gst_product_model_suppliers`; `gst_product_suppliers` resta per le eccezioni di un'opzione, senza `is_preferred` | Si compra l'articolo, non la taglia; l'eccezione serve quando una taglia si compra altrove |
| P96 | Fornitore per fornitore vince la riga dell'opzione; i fornitori dell'articolo senza eccezione valgono lo stesso (`effective()`) | Un'eccezione su un fornitore non deve far sparire gli altri |
| P97 | Riquadro «Fornitori» a righe nella scheda dell'articolo con il repeater del core; via i tre campi, il bottone «Costo» e la finestra dalla griglia (supera P87–P90; P92 sull'articolo) | Le righe si leggono d'un colpo; una finestra dentro la griglia era il ripiego di un posto sbagliato |
| P98 | Nessun preferito | Nessuno lo usa oggi; quando servirà *un* costo lo dirà quella spec |
| P99 | Un fornitore legato ad articoli o opzioni non si elimina e non perde il ruolo; il legame sparisce con l'articolo o l'opzione (estende P93) | La chiave esterna fermerebbe l'eliminazione con un errore del database |
| P100 | Scorta minima per prodotto e sede in `gst_stock_thresholds`; via `gst_products.min_stock_quantity` subito, senza copia | Con più sedi una soglia sul totale non dice dove manca la merce; copiare non si può prima dell'allineamento del core, e i valori sono di prova |
| P101 | Con una sede sola niente cambia sullo schermo: le caselle parlano della sede principale | Chi ha un magazzino non deve vedere una sede |
| P102 | Con più sedi (`Locations::shown()` ≥ 2) righe «Giacenza per sede» [Sede · Giacenza · Scorta minima] con «Aggiungi sede»; Giacenza come `Stocktake` → rettifica sulla sede, soglia per sede; riga tolta = soglia via, pezzi restano | Ogni riga dice di quale sede parla; i pezzi non spariscono da un form |
| P103 | Griglia con più sedi: *Giacenza* totale in sola lettura, bottone «Giacenza» con riassunto e finestra a righe, JSON per riga, stesso codice del server | Una tabella dentro una riga della griglia non ci sta; il pattern è quello della finestra dei costi |
| P104 | Avvisi per prodotto e sede: `Levels::byLocation()`, un avviso aperto per sede, `location_id` compilato; colonna *Sede* in report, email e riquadro solo con più sedi; avvisi senza sede chiusi al primo giro | La soglia è della sede, l'avviso deve dire quale |
| P105 | Prezzo scontato resta senza date: articolo, colonna «Scontato» nella griglia, `price()` nella scheda dell'opzione, copia nello scheletro | Le campagne non scontano una singola opzione (4.6, D19) |
| P106 | Personalizzazioni: tabelle, riquadro sotto «Scheda tecnica» con `quickCreate()`, vetrina e ordine scritti qui; codice in G5 | `customizations` richiede `orders` |

### Lavori del tredicesimo giro

- [x] modulo: modello `ProductModelSupplier` (`gst_product_model_suppliers`); via `is_preferred` da `ProductSupplier`; `ProductSuppliers` con `modelLinksFor()`, `effective()`, `dropForRemovedModels()`, `countForSupplier()` sulle due tabelle; via `preferOne()`, `preferred()`, `summary()`
- [x] modulo: riquadro «Fornitori» nella scheda dell'articolo (repeater, scelte P92, nota senza fornitori, controlli); via campi, bottone, JSON, finestra e script dei costi dalla griglia
- [x] modulo: scheda dell'opzione senza «Preferito», con tooltip e riga di contesto
- [x] modulo: modello `StockThreshold` (`gst_stock_thresholds`), servizio `Thresholds`; via `min_stock_quantity` da `Product` e da chi lo legge (`saveMinStocks()`, `writeMinStock()`, `minStockInput()`, colonna della griglia, `ProductResource`, `LowStockReport`, dati di prova)
- [x] modulo: `Levels::byLocation()`; `LocationRows` pura; repeater «Giacenza per sede» nella scheda dell'articolo senza varianti e in quella dell'opzione; bottone «Giacenza», finestra e script nella griglia; salvataggio per sede con `Stocktake` e `Stock::apply()`
- [x] modulo: `Alerts::refresh()` per sede, `openRow()` con la sede, chiusura degli avvisi senza sede; `LowStockReport`, `LowStockEmail`, `LowStockNotifier`, riquadro e comando con la colonna *Sede*
- [x] modulo: colonna «Scontato» nella griglia, `price()` nella scheda dell'opzione, `sale_price` nello scheletro
- [x] modulo: `CustomerResource` con i conti sulle due tabelle; `CatalogDemo` con fornitori sull'articolo, un'eccezione e le soglie; lang; codici d'errore
- [x] modulo: test unitari (`ProductSuppliers`, `LocationRows`, `Thresholds`, `LowStock*`) e d'integrazione (`tests/integrazione`)
- [x] modulo: guide `catalogo-prodotti`, `anagrafiche`, `magazzino-giacenze`, `magazzino-avvisi`, `funzionalita`, `da-controllare`; `docs/dev/concetti/{acquisti,magazzino,catalogo}`
- [x] modulo: revisione del giro, due difetti corretti. Una giacenza per sede sotto zero si rifiuta prima di scrivere (`LocationStock::assertNotNegative()`, da `assertLocationRows()` e dal `mutateRequestValues()` della scheda dell'opzione; `product.stock_negative`, con l'eccezione del numero già negativo per gli arretrati). Con più sedi la colonna *Giacenza* e la casella `product_stock` sono il totale da leggere **anche in creazione**: i pezzi si scrivono solo nelle righe per sede (supera «in creazione si scrive anche con più sedi, sulla sede principale»)
- [ ] spec: architettura 4.3 e tabelle aggiornate (fatto con questa sezione); G2b-bis §2 da allineare da chi la tiene
- [x] modulo: difetti trovati con la prova nel browser. Dopo un salvataggio rifiutato la griglia tiene bottone e didascalia (`withFormStockButtons()`, `LocationRows::summaryOfRows()`). Le righe per sede si scrivono con l'unità dell'articolo anche nella scheda dell'opzione, e i decimali si decidono sede per sede (`LocationRows::format()`, `locationFormat()`, `optionFormat()`). Le prove d'integrazione con una sede sola spengono «Più sedi» da sé
- [x] prova nel browser (1600×950) con «Più sedi», «Acquisti» e «Avvisi di scorta minima» accese e una seconda sede con magazzino, create dall'utente. Fatta il 2026-09-28 in due tempi. Primo: righe per sede dell'articolo e dell'opzione, finestra «Giacenza» della griglia, rifiuto del negativo, colonna «Scontato». Secondo: riquadro «Fornitori» dell'articolo e dell'opzione (eccezione, riga di contesto, doppione rifiutato), «Scorta minima» per sede nelle righe e nella finestra della griglia, avviso per sede nel riquadro «Sotto scorta» e nell'elenco delle giacenze, chiuso togliendo la soglia. Dati di prova tolti a fine prova
- [x] modulo e core: difetti trovati con la seconda prova. Nella scheda dell'opzione la riga «Giacenza per sede» occupava dodici dodicesimi e il cestino andava a capo: ora *Sede* è larga 5 con la scorta minima e 7 senza, come nella scheda dell'articolo (`ProductResource::locationRowsField()`). Tolto l'unico fornitore il salvataggio era rifiutato con `product.supplier_missing`: il repeater del core svuota l'ultima riga invece di toglierla, ma AutoNumeric teneva il costo vecchio e lo riscriveva all'invio. Corretto nel core (`wiRepeaterRemoveRow` svuota anche AutoNumeric, `tests/Themes/RepeaterAutonumericTest.php`): vale per ogni repeater con un campo numerico, e arriva sul sito con il prossimo rilascio del core

## 23. Quattordicesimo giro: i fornitori tornano all'opzione, in una finestra; con più sedi la griglia non ha la giacenza

*Richiesta dell'utente, dopo aver visto il tredicesimo giro: con le varianti
i fornitori si compilano uno per opzione, come la giacenza; con più sedi la
casella «Giacenza» non si deve vedere nelle opzioni; i fornitori si compilano
in una finestra, e come per le sedi la finestra c'è solo quando serve: con
più fornitori il bottone che la apre, con un fornitore solo subito i campi.*

Supera P95–P97 (i fornitori dell'articolo, le eccezioni dell'opzione, il
riquadro nella colonna di destra) e la parte di P103 sul totale in sola
lettura. Restano P91 (costo vuoto = sconosciuto, due decimali nella scheda e
quattro nella tabella), P92 (si propongono i fornitori attivi più quelli già
legati), P98 (nessun preferito) e P99 (un fornitore legato non si elimina e
non perde il ruolo).

### 23.1 I fornitori sono dell'opzione

**Una tabella sola.** I fornitori stanno in `gst_product_suppliers`, prodotto
per prodotto: l'articolo senza varianti li ha sul suo unico prodotto, quello
con le varianti su ogni opzione. `gst_product_model_suppliers` e il modello
`ProductModelSupplier` spariscono, e con loro `syncModel()`,
`modelLinksFor()`, `dropForModels()`, `dropForRemovedModels()` ed
`effective()`: non c'è più niente da sommare, quello che vale per un'opzione
è quello che ha scritto. `countForSupplier()` torna a contare una tabella. La
tabella dell'articolo non è mai uscita da questo computer (il tredicesimo
giro non è pushato): non c'è niente da copiare, e sul sito di prova le sue
righe erano dati di prova.

**Quanti fornitori ci sono decide che cosa si vede.** Conta i fornitori da
proporre (P92: quelli attivi, più quelli già legati a un prodotto
dell'articolo):

| Fornitori da proporre | Che cosa si vede |
|---|---|
| nessuno | niente: né campi né bottone |
| uno | due campi, **Codice fornitore** e **Costo d'acquisto** |
| due o più | il bottone **Fornitori** con il riassunto accanto, che apre la finestra |

Con un fornitore solo la tendina non c'è: il fornitore è quello, e il suo
nome sta nel tooltip dei due campi («Filati Nord, l'unico fornitore»). I due
campi vuoti vogliono dire che l'opzione non si compra da lui: il legame non
si scrive, e se c'era si toglie. Basta uno dei due compilato perché il legame
ci sia.

**Dove stanno.** Dentro «Compila le informazioni avanzate», dopo i codici:
nella riga della griglia per l'articolo con le varianti, nel riquadro
«Prodotto» per quello senza. Il riquadro «Fornitori» della colonna di destra
non c'è più. Nella griglia il bottone «Fornitori» sta accanto al bottone
«Giacenza» quando ci sono tutti e due.

### 23.2 La finestra dei fornitori

**A righe, come quella della giacenza** (P103): una finestra sola per la
pagina, con le righe [Fornitore (tendina) · Codice fornitore · Costo
d'acquisto · ×] e «Aggiungi fornitore». Ogni riga della griglia porta un
campo nascosto `suppliers` con il JSON delle sue righe; il bottone lo legge
aprendo la finestra e «Salva» lo riscrive insieme al riassunto accanto al
bottone: «Filati Nord 12,00 € · Lana Sud», con il costo solo dove c'è, e
«Nessun fornitore» senza righe. Niente si scrive nel database finché non si
salva la scheda.

**In fondo tre bottoni**: «Annulla», «Salva» e **«Salva per tutte le
opzioni»**, che scrive le stesse righe e lo stesso riassunto su tutte le
righe della griglia, anche quelle chiuse in un gruppo. È il modo di dire «la
maglia si compra da Filati Nord in tutte le taglie» senza ripeterlo taglia
per taglia. Senza conferma: finché la scheda non si salva si torna indietro
ricaricando. Nella finestra dell'articolo senza varianti e in quella della
scheda dell'opzione il terzo bottone non c'è.

**La tendina di una riga** propone i fornitori attivi più quelli non attivi
che *quell'opzione* ha già (P92): un fornitore spento resta dove c'era e non
si può dare a un'altra opzione. Lo stesso fornitore due volte, un costo oltre
`MAX_COST`, un codice oltre i 100 caratteri e una riga con codice o costo ma
senza fornitore li rifiuta la scheda prima di scrivere, con i codici d'errore
di oggi (`ProductSuppliers::assertValid()`); la finestra li segnala già su
«Salva», senza chiudersi.

### 23.3 La scheda dell'opzione

**Stessa regola.** Il riquadro «Fornitori» della scheda dell'opzione resta,
ma senza il repeater, la riga «Dall'articolo: …» e il tooltip
sull'eccezione: con un fornitore solo ha i due campi, con due o più il
bottone «Fornitori» con il riassunto e la stessa finestra, senza nessuno la
nota «Nessun fornitore da proporre: aggiungilo da Anagrafiche → Fornitori».
Finestra, script e lettura di quello che arriva sono gli stessi della scheda
dell'articolo: `ProductResource` li eredita.

### 23.4 Con più sedi la griglia non ha la giacenza

**La colonna «Giacenza» sparisce.** Con più sedi (`Locations::shown()` ≥ 2)
la riga principale della griglia ha *Opzione*, *Prezzo* e *Scontato*;
*Opzione* si allarga da cinque a sette dodicesimi. Il totale in sola lettura
di P103 non c'è più, e la griglia non manda nessuna giacenza totale: i pezzi
si scrivono solo dalla finestra. Il bottone **Giacenza** resta dov'è, nelle
informazioni avanzate, con il riassunto per sede («Milano 12 · Roma 3»).
Con una sede sola non cambia niente, e nemmeno per l'articolo senza varianti,
che con più sedi ha già il riquadro «Magazzino» al posto della casella.

### 23.5 Quello che fa il server

- **Legge quello che arriva**, non il modo in cui crede di essere: il JSON se
  c'è, altrimenti i due campi. Fra l'apertura della scheda e il salvataggio
  qualcuno può aver aggiunto il secondo fornitore.
- **I due campi sono del fornitore unico.** Compilati, scrivono il legame con
  lui; vuoti, lo tolgono. Gli altri legami dell'opzione non si toccano: se ce
  ne fossero, i fornitori da proporre sarebbero due e ci sarebbe la finestra.
- **Il JSON sostituisce** i legami dell'opzione: prima scrive, poi toglie
  quelli spariti (`ProductSuppliers::sync()`). Il costo non toccato resta
  quello salvato, con i suoi quattro decimali (P91).
- **Le opzioni che nascono accendendo le varianti** prendono i fornitori
  dell'articolo singolo, come il prezzo e lo sconto. Quelle aggiunte dopo
  nascono con quello che ha la loro riga nella griglia.
- **Con `purchasing` spenta** campi, bottone e finestra non ci sono, e quello
  che arriva non si scrive; i legami già scritti restano.
- **Con le varianti i campi del riquadro «Prodotto» non si guardano**: sono
  nascosti, ma arrivano lo stesso. Fa eccezione l'articolo con un prodotto
  solo a cui si stanno accendendo le varianti (P115): vanno al suo prodotto,
  e da lì alle opzioni che nascono.
- **I due campi compilati senza un fornitore unico** si rifiutano
  (`product.supplier_invalid`): fra l'apertura e il salvataggio il fornitore
  è sparito o ne è arrivato un secondo, e non si sa di chi siano. Vuoti
  passano, e non toccano niente.
- **Nella scheda dell'opzione il modo è dell'opzione**: conta i fornitori
  attivi più quelli già legati a lei. Una sorella con un fornitore non più
  attivo può avere la finestra dove questa ha i due campi.

### Fuori da questo giro

- Il costo del gruppo (un «Costo del gruppo» come «Prezzo del gruppo»): c'è
  «Salva per tutte le opzioni».
- Il valore del magazzino e quale costo prendere senza un preferito (G3).

| # | Decisione | Perché |
|---|-----------|--------|
| P107 | I fornitori sono del prodotto, in `gst_product_suppliers`; via `gst_product_model_suppliers`, `ProductModelSupplier` ed `effective()` (supera P95, P96) | L'utente li vuole uno per opzione, come la giacenza; due livelli da sommare non servono più |
| P108 | Si compilano nelle informazioni avanzate: riga della griglia con le varianti, riquadro «Prodotto» senza; via il riquadro «Fornitori» dell'articolo (supera P97) | Stanno con i codici dell'opzione a cui appartengono |
| P109 | Nessun fornitore da proporre: niente; uno: «Codice fornitore» e «Costo d'acquisto» senza tendina; due o più: bottone «Fornitori» con riassunto e finestra | Stessa regola delle sedi: la finestra c'è solo quando serve |
| P110 | Finestra a righe [Fornitore · Codice · Costo · ×] con «Aggiungi fornitore», JSON nascosto per riga, una finestra per pagina | È la finestra della giacenza (P103): un solo modo di fare le cose |
| P111 | «Salva per tutte le opzioni» accanto a «Salva», senza conferma | Di solito l'articolo si compra da uno in tutte le taglie; niente si scrive finché la scheda non si salva |
| P112 | Scheda dell'opzione con la stessa regola: due campi o bottone e finestra; via repeater, riga di contesto e tooltip dell'eccezione | Chiesto dall'utente; stesso codice, ereditato |
| P113 | Con più sedi la griglia non ha la colonna «Giacenza»; *Opzione* a sette dodicesimi; il bottone «Giacenza» resta nelle informazioni avanzate (supera il totale di P103) | Un totale che non si può scrivere confonde; il riassunto per sede dice di più |
| P114 | Il server legge il JSON se c'è, altrimenti i due campi; i due campi scrivono o tolgono solo il fornitore unico | Il modo può cambiare fra apertura e salvataggio |
| P115 | Accendere le varianti copia i fornitori dell'articolo singolo su ogni opzione che nasce | Come prezzo e sconto: chi passa alle taglie non ricompila |

### Lavori del quattordicesimo giro

- [x] modulo: `ProductSuppliers` su una tabella sola (via `syncModel()`, `modelLinksFor()`, `dropForModels()`, `dropForRemovedModels()`, `effective()`; `countForSupplier()` su `gst_product_suppliers`), con `summary()` per il riassunto e le righe dai due campi; via il modello `ProductModelSupplier`
- [x] modulo: scheda dell'articolo senza il riquadro «Fornitori»; modo per numero di fornitori; due campi o bottone nelle informazioni avanzate del riquadro «Prodotto» e della griglia; colonna nascosta `suppliers`
- [x] modulo: finestra dei fornitori e script (righe, «Aggiungi fornitore», «Salva», «Salva per tutte le opzioni», controlli, fornitori non attivi riga per riga)
- [x] modulo: salvataggio (JSON o due campi, controlli prima di scrivere, costo non toccato, griglia tenuta dopo un rifiuto), fornitori copiati accendendo le varianti
- [x] modulo: scheda dell'opzione con i due campi o bottone e finestra; via repeater, riga di contesto e tooltip
- [x] modulo: griglia con più sedi senza colonna «Giacenza», *Opzione* a sette dodicesimi, script dell'unità e del totale che non la cercano
- [x] modulo: `CustomerResource` e `Contact` con i conti su una tabella; `CatalogDemo` e `ContactsDemo` con i fornitori per opzione
- [x] modulo: test unitari e d'integrazione allineati (`ProductSuppliersTest`, `ProductSupplierModelsTest`, `ProductModelResourceTest`, `CatalogDemoTest`, `CombinazioniTest`, `ContactsTest`, `LocationStockTest`)
- [x] modulo: guide `catalogo-prodotti`, `anagrafiche`, `funzionalita`, `magazzino-giacenze`; `docs/dev/concetti/{acquisti,catalogo,errori}` (`magazzino` non parla dei due livelli); `CHANGELOG.md`. `TODO.md` non toccato: la voce del tredicesimo giro che nomina `gst_product_model_suppliers` va aggiornata insieme a chi lo tiene
- [ ] prova nel browser (1600×950): un fornitore e più fornitori, con e senza varianti, scheda dell'opzione, «Salva per tutte le opzioni», griglia con una sede e con più sedi
- [ ] memoria e commit con percorsi espliciti; niente push senza OK

## Piani

Da scrivere dopo l'approvazione.
