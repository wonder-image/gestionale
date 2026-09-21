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
compila legge una parola sola — *prodotto* — e le righe da vendere si chiamano
*versioni*. La pagina dei modelli è `app/gestionale/prodotti`; quella della
singola riga è `app/gestionale/versioni`, fuori dal menu e raggiunta da un
pulsante nella scheda. Quando rinomini quella pagina ricordati di
`ProductImages::DIR`: il repeater scrive i file nella cartella del Model e li
rilegge in quella della Resource, e se le due non coincidono le anteprime
spariscono.

Il nome di una riga da vendere sta nella colonna `name` di `gst_products`
("Blu / M"): lo scrive il generatore e lo può correggere chi vende. È una
fotografia, non un calcolo — rinominare un valore non riscrive i nomi già
generati.

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

**Il livello decide tutto.** Un attributo dichiara dove vive, e il piano 3 userà
quel livello per scegliere la tabella di collegamento:

| `level` | A cosa serve | Esempio |
|---|---|---|
| `model` | descrive l'articolo | Materiale: cotone |
| `variant` | distingue le varianti | Colore: blu |
| `product` | distingue i prodotti dentro una variante | Taglia: M |

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
crea insieme al primo prodotto quando nasce un modello, e la scheda si adatta:

| Quando | Cosa si vede |
|---|---|
| una variante sola | il riquadro "Varianti" non c'è |
| un prodotto solo | SKU, EAN e prezzo stanno nel riquadro "Prodotto" |
| più di uno | i due repeater |

Quei riquadri non sono estetica: **un campo che non viene stampato non viene
postato**, e `syncRepeaterRelations()` del core cancella le righe che non
ritrova. Per questo `formSchema()` dichiara i repeater solo quando servono.

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

### Attributi appesi alle righe

`Support\Catalog\ProductAttributes` scrive e legge i collegamenti dei tre
livelli: `save($level, $parentId, $attributi, $input)`, `read()`, `describe()`.
Il livello sceglie la tabella; il tipo sceglie la colonna.

## Immagini, con il resize in differita

`gst_product_images`: `product_model_id`, `product_variant_id` (vuoto = vale per
tutto l'articolo), `file`, `alt`, `position`, `status`, `attempts`,
`processed_at`, `error`.

**Il salvataggio non ridimensiona niente** (G2a.8). Un campo immagine, se non
dice niente, prende da sé le misure responsive del sito: venti foto vogliono
dire centinaia di file generati mentre qualcuno aspetta. Il campo dichiara
`deferResize()` del core, l'upload scrive solo l'originale e la riga nasce
`pending`.

```php
ProductImages::for($immagini, $varianteId);  // puro: le sue, se ne ha; altrimenti quelle del modello
ProductImages::path($immagine);              // dove sta il file
ImageQueue::work(20);                        // ['done' => …, 'failed' => …, 'left' => …, 'blocked' => '']
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

## La scheda su due colonne

`formLayoutSchema()` torna due `Container`, `columnSpan(8)` e `columnSpan(4)`.
Perché funzioni serve `columns(12)` **sul Form**: il renderer calcola la
larghezza di un figlio sulle colonne del padre, e un Form senza colonne ne ha
una sola, quindi qualunque span diventa piena larghezza.

## Prezzi: uno per tutte le versioni

Il prezzo del riquadro in alto vale per ogni riga: `savePrices()` lo scrive su
tutte. La casella **vuota non tocca niente**, ed è l'unico modo di tenere prezzi
diversi senza che un salvataggio distratto li riallinei;
`commonValue()` la riempie solo quando le versioni costano uguale.

Con una versione sola la casella mostra il prezzo di quella versione. Con più
versioni **resta vuota**, e non è una dimenticanza: il riquadro in alto si salva
**dopo** la griglia, quindi un prezzo rimasto lì dentro riscriverebbe la riga
appena corretta. Vuota, il salvataggio non tocca i prezzi.

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
tag, due attributi con i loro valori — "Colore" sulla variante e "Taglia" sul
prodotto — e **tre articoli**: uno semplice, uno con due colori e tre taglie,
uno con molti prodotti, ciascuno con la sua foto finta (un rettangolo colorato
scritto sul disco, che nasce `pending` come una foto vera). Tutte le righe hanno
il nome che inizia per `Prova `. `--fresh` toglie quelle di prima e le rifà. Le classi stanno in `src/Seeding/`,
registrate in `Seeding\Demo`.
