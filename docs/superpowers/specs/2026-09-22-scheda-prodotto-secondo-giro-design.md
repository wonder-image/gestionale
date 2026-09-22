# G2c — La scheda prodotto, secondo giro

- **Sotto-progetto:** seguito di G2a-bis, prima di G2b
- **Stato:** da approvare
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

### 3. La creazione: cinque campi e un blocco chiuso

Sotto i cinque campi di oggi (nome, tipo fiscale, prezzo, categoria
principale, SKU) compare un blocco **chiuso**: *"Si vende in più versioni?
(colori, taglie…)"*, con dentro i gruppi di spunte e i loro "+".

Chi vende un cappello non lo apre e salva come adesso. Chi spunta Blu, Rosso e
S/M/L atterra sulla scheda con **sei versioni già fatte**, con nome e SKU
proposti: è lo stesso `Generator`, chiamato dallo stesso `afterStore`, e cambia
solo che i valori arrivano dalla creazione invece che dal salvataggio
successivo.

Il blocco è un **Accordion vero**, non la Card travestita di oggi: C1 corregge
il corpo dell'Accordion, e `GestionaleResource::foldable()` smette di essere un
ripiego e torna a usarlo. Gli altri due blocchi richiudibili della scheda —
*Si vende in più versioni?* e *Scheda tecnica* — si chiudono davvero anche
loro, senza altre modifiche.

### 4. La griglia delle versioni

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
->repeaterGroupBy('product_variant_id')
->repeaterGroupCommand('price', 'Prezzo del gruppo')
->repeaterGroupCountLabel('versione', 'versioni')
```

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

### 5. Spedizione e imballaggi

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

### 6. Dati di prova

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

## Piani

Da scrivere dopo l'approvazione.
