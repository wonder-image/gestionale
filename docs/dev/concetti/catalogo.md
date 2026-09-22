---
icon: box-seam
---

# Catalogo

## I tre livelli

| Livello | Cos'è | Esempio |
|---|---|---|
| Modello (`gst_product_models`) | la scheda: nome, descrizione, marchio, categorie | "T-shirt girocollo" |
| Variante (`gst_product_variants`) | ciò che cambia l'aspetto, con immagini proprie | "Blu" |
| Prodotto (`gst_products`) | ciò che si vende e sta a magazzino: SKU, EAN, prezzo | "Blu / M" |

Un articolo senza varianti è **un modello con una variante e un prodotto**: la
variante esiste lo stesso, ma il pannello non la nomina finché resta una sola.
È la regola "semplice per chi è piccolo, completo per chi cresce".

**Nel pannello queste tre parole non compaiono.** Le tabelle restano tre, ma chi
compila legge una parola sola — *prodotto* — e ne incontra due nuove:

| Parola del pannello | Cos'è davvero |
|---|---|
| **attributo** | una riga di `gst_attributes` che crea righe da vendere: Colore, Taglia, Gusto. Al massimo **tre** per articolo, e uno solo di livello `variant` |
| **opzione in vendita** | una riga di `gst_products`: SKU, prezzo, giacenza, foto |

*Versione* non si legge più da nessuna parte del pannello, e *variante* nemmeno:
il colore si chiama con il nome del suo attributo (`pageOptionName()`).

La pagina dei modelli è `app/gestionale/prodotti`; quella della singola riga è
`app/gestionale/versioni`, fuori dal menu e raggiunta da un pulsante nella
scheda. Quando rinomini quella pagina ricordati di `ProductImages::DIR`: il
repeater scrive i file nella cartella del Model e li rilegge in quella della
Resource, e se le due non coincidono le anteprime spariscono.

Il nome di una riga da vendere sta nella colonna `name` di `gst_products`
("Blu / M"), **e non lo scrive chi compila**: nasce dai collegamenti agli
attributi e `realignNames()` lo riscrive a ogni salvataggio, insieme al nome
della variante, che prende l'etichetta del suo valore. Rinominare "Blu" in
anagrafica rinomina il colore e i prodotti ovunque: è l'unico modo perché
quello che si legge nella griglia e quello che legge il cliente siano la stessa
cosa.

## Il catalogo non si sincronizza

Aliquote, tipi fiscali e impostazioni sono configurazione: si scrivono in locale
e arrivano in produzione con il deploy. Il catalogo no — è il lavoro di chi usa
il gestionale, si scrive dove si lavora. Tutti i Model del catalogo hanno
`syncSchema(): null`.

## Tassonomie

| Tabella | A cosa serve |
|---|---|
| `gst_brands` | il marchio dell'articolo |
| `gst_categories` | l'albero con cui si naviga il catalogo |
| `gst_tags` | etichette trasversali ("novità", "saldi") |

Ognuna ha il suo `code` con prefisso (`bra_`, `cat_`, `tag_`) e uno `slug`
generato dal nome alla creazione, poi fisso: è l'indirizzo della pagina e non
deve cambiare sotto i piedi di chi ha messo un link.

### L'albero delle categorie

L'albero è solo `parent_id`. Tutto il resto sta in `Support\Catalog\CategoryTree`,
che è pura e si prova senza database:

```php
CategoryTree::sorted($righe);        // ordine, con depth e path
CategoryTree::descendants($righe, 3);
CategoryTree::options($righe, 3);    // select del padre, senza sé stessa e i figli
CategoryTree::wouldLoop($righe, 3, 7);
```

Il select del padre non propone la categoria stessa né le sue discendenti, **e
il salvataggio ricontrolla**: un select è una comodità, non una difesa.

Una riga il cui padre non esiste più viene trattata come radice: sparire
dall'elenco sarebbe il modo peggiore di raccontare il problema.

## Attributi

Due tabelle, nessuna sincronizzazione come il resto del catalogo:

| Tabella | Cosa tiene |
|---|---|
| `gst_attributes` | `code` (`att_`), `slug`, `name`, `type`, `level`, `unit`, `group_name`, `is_filterable`, `is_visible`, `position` |
| `gst_attribute_values` | `attribute_id`, `label`, `color`, `image`, `position` |

**Il livello decide tutto.** Un attributo dichiara dove vive, e da quel livello
`ProductAttributes` sceglie la tabella di collegamento:

| `level` | A cosa serve | Esempio |
|---|---|---|
| `model` | descrive l'articolo: finisce nella *Scheda tecnica* | Materiale: cotone |
| `variant` | è il colore, quello con pagina e foto proprie | Colore: blu |
| `product` | distingue le opzioni dentro un colore | Taglia: M |

`Attributes::createsVersions()` tiene insieme gli ultimi due: sono quelli che la
scheda offre da spuntare, e solo se il tipo pesca da `gst_attribute_values` e
qualche valore c'è (`optionAttributes()`). **Uno solo di livello `variant` per
articolo** — due sarebbero due pagine diverse per la stessa riga, e il rifiuto è
`product.one_page_option` — e **tre attributi in tutto**, un muro del selettore,
non del salvataggio.

**Il tipo decide dove finisce il valore.** `select` e `color` pescano da
`gst_attribute_values`; `text` e `number` scrivono direttamente sul
collegamento, con `unit` a fianco.

Le regole stanno in `Support\Catalog\Attributes`, pura come `CategoryTree`:

```php
Attributes::levels();                       // model/variant/product => nome
Attributes::types();                        // select/color/text/number => nome
Attributes::usesValues('color');            // true
Attributes::byLevel($attributi, 'variant');
Attributes::grouped($attributi);            // gruppo => attributi, '' => 'Generale'
Attributes::assignment($attributo, '7');    // ['attribute_value_id' => 7, 'value_text' => '', 'value_number' => null]
Attributes::format($attributo, $collegamento, $valori); // '1,5 g', 'M', 'Cotone'
```

`assignment()` riempie **una sola** colonna e lascia vuote le altre: chi scrive
un collegamento — pannello, vetrina, import — passa di qui e le righe restano
tutte uguali.

### Due nomi di colonna che non sono quelli della spec

`key` e `group` sono parole riservate di MySQL, e il costruttore di query del
core mette le virgolette ai nomi solo in `INSERT`, `UPDATE` e `WHERE`: un
`ORDER BY group` arriverebbe al database così com'è. Le colonne si chiamano
`slug` e `group_name`.

### Il riquadro dei valori

`AttributeResource` dichiara i valori come repeater collegato a
`gst_attribute_values`. Il riquadro c'è quando il tipo li usa: in creazione vale
il tipo predefinito (`AttributeResource::DEFAULT_TYPE`, cioè `select`), così chi
crea un attributo scrive subito i suoi valori; modificando un attributo "Testo"
il riquadro non compare, perché non ha niente da elencare. Il layout legge la
riga aperta con `currentId()` di `GestionaleResource`.

Il tipo non si può cambiare mentre ci sono dei valori: `mutateRequestValues()`
si ferma, perché il salvataggio li cancellerebbe in silenzio.

### Il pulsante "Guida"

`$docsPage` è il percorso della pagina come lo pubblica GitBook —
`gruppo/nome-del-file`, dove il gruppo è il titolo `##` del SUMMARY a trattini —
e `$docsSpace` sceglie la guida: `user` per il commerciante, `dev` per le pagine
che non deve toccare (aliquote, tipi fiscali, regole). `DocsPagesTest` controlla
che ogni pagina dichiarata esista: un link rotto si vede nei test, non quando
qualcuno ci clicca.

## Modelli, varianti e prodotti

Tre livelli, cinque tabelle:

| Tabella | Cos'è |
|---|---|
| `gst_product_models` | la scheda che legge il cliente |
| `gst_product_model_categories` / `gst_product_model_tags` | dove sta nel negozio |
| `gst_product_variants` | quello che cambia l'aspetto |
| `gst_products` | quello che si vende e sta a magazzino |

**La variante c'è sempre** (G2a.2). `Support\Catalog\Skeleton::forModel()` la
crea insieme al primo prodotto quando nasce un modello.

### Una griglia sola

Il repeater delle varianti **non esiste più**: la scheda ha una griglia sola,
`products`, dichiarata da `productsField()` e messa da `optionsCard()` a piena
larghezza **sotto** le due colonne — sette caselle per riga dentro due terzi di
schermo vanno a capo, ed era il disallineamento che si vedeva.

| Quando | Cosa si vede |
|---|---|
| sempre, se il negozio ha un attributo con dei valori | il riquadro "Opzioni in vendita": selettore, spunte e griglia — identico in `create` e in `edit` |
| un prodotto solo | l'EAN passa nei riquadri in alto, e con il modello già salvato ci compare anche la giacenza, `readonly()`, con il link alla rettifica. Prezzo e prezzo scontato stanno lì sempre |
| nessun attributo con valori | `optionsCard()` torna `[]` e il riquadro non c'è |

Colonne della griglia: `option` (finta, `readonly`), `sku`, `ean`, `price`,
`stock`, `photo`, `active`, più `id` e `variant` nascoste. Stanno **dentro
undici**: la dodicesima è la colonna dei bottoni, e quello che sfora va a capo.

- `option` non è la colonna `name`: una casella di sola lettura viene postata lo
  stesso, e avrebbe scritto "S" al posto di "Blu / S". La riempie
  `optionLabels()` con quello che resta del nome tolto il colore.
- `variant` è **calcolata e nascosta**, non `product_variant_id`: una select
  scrivibile sposterebbe un prodotto da un colore all'altro senza spostarne i
  collegamenti agli attributi. In chiaro il colore lo dice la testata del
  gruppo.
- Niente `repeaterAddButton`: le righe nascono dalle spunte, e un bottone
  "Aggiungi" darebbe una riga senza nessuna combinazione dietro. Niente
  riordino: l'ordine lo decide il generatore.

**Il raggruppamento non si sceglie.** `repeaterGroupFixed('variant')` più
`repeaterGroupCommand('price', 'Prezzo del gruppo')`: nessuna tendina "Raggruppa
per". `groupsByVariant()` lo accende quando il negozio ha un attributo `variant`
(in creazione) o quando qualche riga porta davvero un colore (in modifica): un
articolo venduto solo per taglia resta piatto.

**Le righe senza id non arrivano al sync del core.** Le tiene fuori
`prepareRepeaterRows()`, perché il colore a cui appartengono lo crea
`Generator::run()` in `afterStore`/`afterUpdate`, e `syncRepeaterRelations()`
gira prima: arriverebbero al database con una variante che non c'è. Per lo
stesso motivo serve `repeaterStartEmpty()` del core — il repeater stampa una
riga vuota di cortesia quando non ne ha, e quella riga, con la sua select di
stato che posta sempre un valore, sarebbe diventata un prodotto senza colore.

Vale sempre la regola di fondo: **un campo che non viene stampato non viene
postato**, e `syncRepeaterRelations()` cancella le righe che non ritrova.

### Codici degli articoli

- `Support\Catalog\Sku::propose($skuDelModello, ['Blu', 'M'])` → `TSH-1-BLU-M`.
  Senza SKU del modello non propone niente: un codice a caso è peggio di un
  campo vuoto.
- `Support\Catalog\Ean::isValid()` guarda **la forma** (8 o 13 cifre), non la
  cifra di controllo: i negozi stampano codici interni, e rifiutare un codice
  che il fornitore usa davvero sarebbe peggio.
- SKU ed EAN **non hanno un indice UNIQUE**: il framework scrive stringhe vuote
  e non NULL, quindi due articoli senza codice si scontrerebbero. L'unicità la
  controllano `Sku::isFree()` ed `Ean::isFree()`, che possono spiegarsi.

### Le combinazioni

Nella scheda c'è **un gruppo di caselle per opzione** (`option_<attributeId>`,
uno per attributo che crea versioni: li costruisce `optionFields()`). Era un
albero solo, ma le opzioni non sono una gerarchia e la lib nasconde i quadratini
di jsTree (`.jstree-checkbox` sta a `display:none`), quindi si spuntava
cliccando righe che non sembravano cliccabili.
`ProductModelResource::chosenAxes($post)` li smista leggendo il livello
dell'attributo: l'asse con pagina propria da una parte, gli altri in un
elenco di assi. Se le spunte toccano **due** attributi con pagina propria è un
rifiuto (`product.one_page_option`): non si saprebbe quale valore è la pagina.

`Support\Catalog\Combinations::plan($variantValues, $axes, $existing)` — pura —
moltiplica quanti assi vuoi e dice quali varianti e quali prodotti mancano;
`Support\Catalog\Generator::run()` li crea, con il nome
(`Support\Catalog\VersionName::from()`) e **un collegamento di attributo per
asse**. Rifarlo non duplica niente: la chiave di una combinazione
(`Combinations::key()`) ordina gli id, così l'ordine delle spunte non conta. Un
modello che ha ancora solo lo scheletro lo riusa per la prima combinazione,
invece di lasciare in giro una variante vuota.

**Prima l'attributo, poi i valori.** I blocchi stavano tutti aperti: chi vende
cappelli si trovava davanti colori, taglie e gusti senza averne chiesto nessuno.
`optionsPicker()` ne mostra zero e chiede quale serve; `optionBlocks()` marca
ogni blocco con `data-wi-option`, e il JS lo mostra quando lo si sceglie. Il
muro dei **tre attributi** è nel selettore, non nel salvataggio: un articolo che
ne ha di più resta salvabile, o non gli si potrebbe più correggere nemmeno il
prezzo.

**La griglia si costruisce nel browser.** `optionsGridScript()` rifà in JS il
prodotto cartesiano delle spunte e aggiunge le righe che mancano con
`wiRepeaterAddRow()` del core, usando **la stessa chiave** del server
(`Combinations::key()`), e salta quelle già esistenti
(`Generator::existingClientKeys()`). Il browser propone, il server dispone:
`Generator::run()` ricalcola il piano e accetta solo le combinazioni che
tornano, prendendo da `newRows()` quello che era stato scritto in quelle righe —
prezzo, codice, giacenza, foto — invece di inventarlo e farlo correggere dopo.

### Attributi appesi alle righe

`Support\Catalog\ProductAttributes` scrive e legge i collegamenti dei tre
livelli: `save($level, $parentId, $attributi, $input)`, `read()`, `describe()`.
Il livello sceglie la tabella; il tipo sceglie la colonna.

## Immagini, con il resize in differita

`gst_product_images`: `product_model_id`, `product_variant_id`, `product_id`,
`file`, `alt`, `position`, `status`, `attempts`, `processed_at`, `error`.

**Tre livelli, e si legge dal più preciso.** Le due colonne di collegamento sono
nullable, e quale delle due è piena dice a chi appartiene la foto:

| `product_id` | `product_variant_id` | Di chi è |
|---|---|---|
| pieno | (copia quello del prodotto) | di quella singola opzione in vendita |
| vuoto | pieno | di quel colore |
| vuoto | vuoto | di tutto l'articolo |

`ProductImages::for()` applica la regola, e **il primo livello che ha qualcosa
vince intero**: non si mescolano, o una maglietta blu mostrerebbe in mezzo la
foto di quella rossa. Chi passa due soli argomenti continua a vedere quello di
prima — le foto del colore, senza quelle delle singole opzioni — ed è la
risposta giusta per chi sta guardando un colore, non una taglia.

**Niente colonna "Vale per".** Al suo posto un'area di caricamento per posto
(`imageFields()`, una per `imageTargets()`: l'articolo e, quando le varianti
sono più di una, ogni colore). Ogni area è un repeater sulla **stessa** tabella
ristretto alla sua fetta con `condition()`, e quella condizione guida **anche la
cancellazione**: senza `'product_id' => null` dentro, il primo salvataggio
porterebbe via le foto delle singole opzioni, che l'area non mostra e quindi non
riposta. "Vale per tutto l'articolo" è `NULL` e non zero, perché la colonna ha
una chiave esterna.

**La foto della riga la scrive il modulo, non il repeater.** La colonna `photo`
della griglia non è una colonna di `gst_products`: `saveOptionImage()` legge il
file da `Repeater::filesFromRequest('products', $files)` e inserisce la riga a
mano. `Model::create()` non sa caricare niente — scriverebbe nel database la
busta di `$_FILES` — quindi si passa da `Table::prepare()` del core con
`LegacyGlobals::set('NAME', …)` puntato alla cartella del Model, e si rimette a
posto in un `finally`.

**Foto e video insieme:** il campo è `fileDragDrop('gallery')` e `ProductImage`
accetta `png, jpg, jpeg, webp, mp4`, 8 MB, un file per riga. `ImageQueue` non
prova a ridimensionare un video (`isVideo()`): lo segna `ready` e va avanti.

**Il salvataggio non ridimensiona niente** (G2a.8). Un campo immagine, se non
dice niente, prende da sé le misure responsive del sito: venti foto vogliono
dire centinaia di file generati mentre qualcuno aspetta. Il campo dichiara
`deferResize()` del core, l'upload scrive solo l'originale e la riga nasce
`pending`.

```php
ProductImages::for($immagini, $varianteId);              // le sue, se ne ha; altrimenti quelle del modello
ProductImages::for($immagini, $varianteId, $productId);  // e prima ancora quelle di quella riga
ProductImages::path($immagine);                          // dove sta il file
ImageQueue::work(20);                                    // ['done' => …, 'failed' => …, 'left' => …, 'blocked' => '']
```

Chi fa girare la coda:

| Come | Quando |
|---|---|
| `php forge gestionale:images` | a mano, o da un cron ogni minuto (`--limit`) |
| attività `gestionale.images` | dallo scheduler del core, ogni cinque minuti, da accendere |

Tre cose imparate facendola:

1. **La cartella deve essere una sola.** Il repeater **scrive** i file nella
   cartella del Model e li **rilegge** in quella della Resource che ospita il
   form: finché le due non coincidono, l'anteprima di una foto caricata non si
   vede. Per questo `ProductImage::$folder` è il percorso della pagina dei
   modelli e il campo non aggiunge nessun `dir()`.
2. **I comandi non hanno le funzioni globali** del framework: la coda chiama la
   classe `ResponsiveImage` del core invece di `imageResize()`, e una chiamata
   senza `\` dentro un namespace cercherebbe comunque
   `Wonder\Plugin\…\imageResize()`.
3. **Quando è il sito a non poter lavorare** — una costante che esiste solo
   durante una richiesta web — la coda si ferma e lo dice (`blocked`), invece di
   bruciare i tentativi delle righe una per una.

## La scheda: due colonne, più la griglia sotto

`formLayoutSchema()` torna due `Container` — `columnSpan(8)` e `columnSpan(4)` —
e sotto, allo stesso livello, la `Card` delle opzioni in vendita a
`columnSpan(12)`. Perché funzioni serve `columns(12)` **sul Form**: il renderer
calcola la larghezza di un figlio sulle colonne del padre, e un Form senza
colonne ne ha una sola, quindi qualunque span diventa piena larghezza.

**La stessa in `create` e in `edit`.** La creazione era una schermata a sé con
cinque campi: si compilava, si salvava, si riapriva la scheda e si salvava
ancora. Ora no, e si può perché il core sincronizza i repeater con l'id appena
inserito (`syncRepeaterRelations($insertId, …)` subito dopo l'insert): foto,
righe e collegamenti nascono nello stesso salvataggio. Quello che non può
esistere prima del primo salvataggio semplicemente non compare — la giacenza
del riquadro in alto, le aree foto dei singoli colori, il pulsante "Dettagli
delle opzioni".

## Prezzi: uno per tutte le opzioni

Il prezzo del riquadro in alto vale per ogni riga: `savePrices()` lo scrive su
tutte. La casella **vuota non tocca niente**, ed è l'unico modo di tenere prezzi
diversi senza che un salvataggio distratto li riallinei.

Con un prodotto solo la casella mostra il prezzo di quello. Con più di uno
`mutateFormValues()` la lascia **vuota**, e non è una dimenticanza: il riquadro
in alto si salva **dopo** la griglia, quindi un prezzo rimasto lì dentro
riscriverebbe la riga appena corretta. Vuota, il salvataggio non tocca i prezzi.

Una riga **nata adesso con il suo prezzo** `savePrices()` la salta: la casella
in alto è un comando per le altre, non per quella che è stata appena scritta
dieci centimetri più in basso nella stessa schermata.

## La giacenza si scrive dalla scheda

La colonna `stock` non è una colonna di `gst_products`: il salvataggio la butta
via, e `saveRowExtras()` la ripesca da quello che è stato postato. Si scrive
**quanti pezzi ci sono**, non di quanto cambiarli: `Stocktake::quantity()` legge
il numero all'italiana, `Stocktake::changes()` lo confronta con
`Levels::forProducts()` e solo le differenze diventano `Stock::apply()` con
causale `Reasons::DEFAULT` (*Inventario*). Una casella riscritta uguale non
muove niente.

Per una riga appena nata la quantità è invece un carico: `saveNewVersions()` la
registra con causale `initial_stock`. Il magazzino ha una porta sola, e resta
quella.

Il rifiuto di un numero negativo sta in `assertStockWritable()`, chiamato da
`mutateRequestValues()`: **dopo l'insert non c'è nessuna rete** — il sync e
`afterUpdate` girano fuori da qualunque `try` — e l'errore diventerebbe una
pagina di guasto su un articolo già scritto a metà.

I decimali della griglia arrivano interi fino al database dalla **2.2.15** del
core: prima il suo `prepare()` li arrotondava (21,50 diventava 22,00). Non
c'era rimedio lato modulo, perché l'hook `prepareRepeaterRelationRow()` gira
**prima** di `preparePayload()`.

## Il riquadro che non si chiude

`Elements\Components\Accordion` esiste nel core e funziona, ma **non dentro un
form**: il suo corpo lo disegna il tema Bootstrap, che si aspetta `col-span-6`
sui figli, mentre i campi del form portano il `col-6` del tema Wonder. I due non
si parlano e i campi finiscono ammassati. Per questo
`GestionaleResource::foldable()` torna una `Card` normale e la mette in fondo
alla pagina. Il giorno in cui il rendering del core saprà attraversare un
accordion, basta cambiare quel metodo.

## I numeri scritti da una persona

`Support\Numbers::fromForm()` porta `19,90` e `1.234,50` nella forma che MySQL
accetta. Serve **quando si scrive con `Model::update()`**, che non passa dal
`prepare()` dei form.

{% hint style="warning" %}
Fino a `wonder-image/app` 2.2.15 compreso il `prepare()` dei form arrotondava i
decimali (`24,50` diventava `25,00`, `19,90` diventava `1990,00`). La correzione
è in `app/function/sql.php` e vale dal rilascio successivo: un sito con una
versione più vecchia continua a perdere i decimali.
{% endhint %}

## Rifiutare un salvataggio

`UserError` estende `InvalidArgumentException` apposta: è il tipo che
`ResourcePageController::preparedValues()` intercetta e trasforma in `$ALERT`.
Il salvataggio non parte e la frase torna sul form. Un `RuntimeException`
qualunque, invece, diventa una pagina di errore 500.

```php
throw UserError::make('attribute.type_locked');
```

Il testo sta in `lang/it/gestionale.json` sotto `gestionale.errors`.

## Codici e indirizzi

- `Support\Catalog\Code::make($model, $prefisso)` genera il codice tecnico.
  Serve perché `Model::prepare()` non genera i codici unici (lo fa solo il flusso
  dei form) e perché `create_unique_code()` esiste solo a sito avviato: dentro un
  comando `forge` non c'è.
- `Support\Catalog\Slug::make($nome, $tabella)` fa lo stesso ragionamento per lo
  slug.

## Niente colonne SEO

Titolo e descrizione per i motori di ricerca li compone l'ecommerce da come è
organizzato il sito. Centinaia di campi SEO da riempire a mano resterebbero
vuoti o scritti male.

## Dati di prova

`php forge gestionale:demo` crea un marchio, tre categorie (una annidata), due
tag, due imballaggi, tre attributi con i loro valori — "Colore" sulla variante,
"Taglia" e "Materiale" sul prodotto — e **quattro articoli**, che sono i quattro
casi che la griglia deve reggere:

| Articolo | Perché c'è |
|---|---|
| Cappello di lana | nessun attributo: una riga sola, prezzo ed EAN nei riquadri in alto |
| Maglietta girocollo | tutti e tre gli attributi: dodici righe, e una riga si legge "S / Gomma" |
| Felpa con cappuccio | tre colori per quattro taglie: la griglia raggruppata, senza costruirla a mano |
| Calzini a costine | nessun colore: la griglia resta piatta, senza testate |

Ognuno ha la sua foto finta (un rettangolo colorato scritto sul disco, che nasce
`pending` come una foto vera). Sulla maglietta ci sono anche una foto di colore
e una di singola opzione, così l'eredità a tre livelli si legge tutta in una
scheda. Tutte le righe hanno il nome che inizia per `Prova `. `--fresh` toglie
quelle di prima e le rifà. Le classi stanno in `src/Seeding/`, registrate in
`Seeding\Demo`.
