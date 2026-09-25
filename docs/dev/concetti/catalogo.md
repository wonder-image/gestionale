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
| `gst_attribute_values` | `attribute_id`, `label`, `description`, `color`, `image`, `position` |

**Il livello decide tutto.** Un attributo dichiara dove vive, e da quel livello
`ProductAttributes` sceglie la tabella di collegamento. Nella scheda il campo
si chiama **Uso**, e le voci dipendono dal tipo (`Attributes::levelsFor()`,
P61):

| `level` | Tipi a valori (`LEVELS`) | Testo e Numero (`FREE_LEVELS`) | Esempio |
|---|---|---|---|
| `model` | Scheda tecnica dell'articolo | Scheda tecnica dell'articolo | Materiale: cotone |
| `variant` | Opzione con foto proprie | — | Colore: blu |
| `product` | Opzione da scegliere | Scheda tecnica di ogni opzione | Taglia: M · Peso: 250 g |

Un Testo di livello `variant` non ha senso — non ci sono valori da spuntare —
e il server lo rifiuta con `attribute.text_level` (`Attributes::acceptsLevel()`).
Nel form sono **due caselle**, `level` e `level_text`, una per famiglia di
tipi, con `visibleWhen('type', …)`: il tipo si cambia senza ricaricare, e le
voci di un select non si riscrivono dal lato del server. Quale conta lo decide
il tipo in `mutateRequestValues()`.

**L'uso non cambia su un attributo in uso.** `ProductAttributes::isUsed($id)`
guarda le tre tabelle di collegamento; se l'attributo sta su almeno una riga le
due caselle sono `disabled()`: una casella spenta non si posta, e
`mutateRequestValues()` tiene il livello di prima; un POST che ne porta un
altro si rifiuta con `attribute.level_locked`. Spostarlo lascerebbe i collegamenti
in una tabella che quel livello non legge più.

`Attributes::createsVersions()` tiene insieme gli ultimi due: sono quelli che la
scheda offre da spuntare, e solo se il tipo pesca da `gst_attribute_values` e
qualche valore c'è (`optionAttributes()`). **Uno solo di livello `variant` per
articolo** — due sarebbero due pagine diverse per la stessa riga, e il rifiuto è
`product.one_page_option` — e **tre attributi in tutto**, un muro del selettore,
non del salvataggio.

**Il tipo decide dove finisce il valore.** `select`, `color`, `pattern`
(Fantasia) e `icon` pescano da `gst_attribute_values` — `Attributes::VALUE_TYPES` —;
`text` e `number` scrivono direttamente sul collegamento, con `unit` a fianco —
`Attributes::UNIT_TYPES`. Un tipo è sempre in uno dei due elenchi, mai in
tutti e due.

Le regole stanno in `Support\Catalog\Attributes`, pura come `CategoryTree`:

```php
Attributes::levels();                       // model/variant/product => nome
Attributes::levelsFor('number');            // model/product, con le parole di Testo e Numero
Attributes::acceptsLevel('text', 'variant'); // false
Attributes::types();                        // select/color/pattern/icon/text/number => nome
Attributes::usesValues('pattern');          // true
Attributes::usesUnit('number');             // true: solo number e text
Attributes::byLevel($attributi, 'variant');
Attributes::grouped($attributi);            // gruppo => attributi, '' => 'Generale'
Attributes::assignment($attributo, '7');    // ['attribute_value_id' => 7, 'value_text' => '', 'value_number' => null]
Attributes::format($attributo, $collegamento, $valori); // '1,5 g', 'M', 'Cotone'
```

`assignment()` riempie **una sola** colonna e lascia vuote le altre: chi scrive
un collegamento — pannello, vetrina, import — passa di qui e le righe restano
tutte uguali.

**Il segno accanto al nome.** `Attributes::valueVisual($tipo, $valore, $url)`
restituisce `['color' => …]` o `['image' => …]`, la forma che
le opzioni dei select e delle pillole del core sanno mostrare. Lo decide il
**tipo**, non le colonne piene: un Elenco che è stato un Colore tiene il codice
nel database ma non mostra pallini. L'indirizzo dell'immagine lo dà
`AttributeValue::imageUrl()`.

**Il tipo Icona** (P64) è un'immagine per valore, in `image`, come la
Fantasia: un PNG, JPG o WebP, meglio quadrato e trasparente perché si mostra a
16×16. SVG no: è un documento che può portare script. La colonna `icon` con il
nome di un'icona del font non c'è più.

### La scheda tecnica

Gli attributi di livello `model` stanno nel riquadro *Scheda tecnica* di
`ProductModelResource` (`technicalSheetCard()`), che c'è sempre, anche vuoto.

- **Più valori per attributo.** Un attributo a valori di livello `model` è un
  gruppo di caselle a pillole (`technicalField()`): il POST porta
  `attribute_<id>` come lista, e `ProductAttributes::save()` scrive una riga
  per valore, toglie quelle non più spuntate e ignora doppioni e id non validi.
  `ProductAttributes::rows($level, $id)` rilegge tutte le righe per attributo;
  `read()` resta a una riga per attributo, la prima. Un gruppo senza spunte non
  si posta, e `null` svuota. Le opzioni in vendita restano a un valore.
- **Un elenco senza valori non compare**: un `InputCheckbox` senza opzioni
  sarebbe una casella sola, sì/no.
- **Il «+» dei valori** è il `quickCreate()` del core su
  `AttributeValueResource`, con l'`attribute_id` nascosto nel layout del
  modal (`newValueLayout()`).
- **Si vede solo quello che è compilato.** Ogni caratteristica sta in un
  blocco (`technicalBlock()`): un `Container` con `data-wi-technical="<id>"` e
  `data-wi-technical-name`, che il core mette sul nodo interno — da nascondere
  è il genitore, la colonna. Lo script (`technicalScript($voci)`,
  `window.wiTechnicalSheet`) al caricamento nasconde i blocchi senza valore e
  li mette nel menu del bottone tratteggiato «Aggiungi caratteristica»; una
  voce del menu riaccende il blocco e ci porta il cursore.
- **La × svuota, non nasconde e basta**: un campo nascosto viene postato lo
  stesso. Toglierla azzera le caselle (anche AutoNumeric) e al salvataggio
  `ProductAttributes::save()` cancella la riga. Con un valore chiede conferma
  con `window.wiRepeaterConfirmDelete()`, con `window.confirm()` di riserva.
  La × va nel wrapper del campo (`.form-floating` o
  `.wi-container-checkbox`), cercato fuori dai `.modal`: quando lo script gira
  il modal di «Aggiungi valore» di un elenco è ancora dentro il blocco — lo
  sposta in fondo al `body` il `DOMContentLoaded`.
- **«Nuova caratteristica…»**, in fondo al menu, clicca un
  `QuickCreateButton` del core su `AttributeResource` la cui colonna lo
  script nasconde: dall'API nasce solo un Testo o un Numero di livello
  `model` (`quickCreateFields()`, `QUICK_TYPES`, `QUICK_LEVEL`). Allo
  `wi:quick-create:created` lo script copia un `<template>` con il blocco vero
  renderizzato dal core (id segnaposto `__WI_ID__`), ci scrive id, nome e
  unità, lo mette prima del bottone, aggiunge la voce al menu (nascosta) e ci
  porta il cursore. Al salvataggio il campo si posta come gli altri, perché
  `attributes()` rilegge il catalogo.

### Due nomi di colonna che non sono quelli della spec

`key` e `group` sono parole riservate di MySQL, e il costruttore di query del
core mette le virgolette ai nomi solo in `INSERT`, `UPDATE` e `WHERE`: un
`ORDER BY group` arriverebbe al database così com'è. Le colonne si chiamano
`slug` e `group_name`.

### Il riquadro dei valori

`AttributeResource` dichiara i valori come repeater collegato a
`gst_attribute_values`. La scheda chiede solo quello che serve al tipo, e
cambia mentre lo si sceglie, senza salvare: niente decide il server, tutto sta
in `visibleWhen('type', ...)`.

- Il riquadro *Valori* si stampa sempre, con
  `visibleWhen('type', Attributes::VALUE_TYPES)`.
- Nella riga, l'immagine (`image`) si vede sulla Fantasia e sull'Icona e il
  codice (`color`) solo sul Colore: le
  `RepeaterColumn` hanno il loro
  `visibleWhen()`, che il repeater del core ripete sul contenitore della
  colonna. Il valore (`label`) ha `columnFill()` e si prende lo spazio che le
  altre lasciano: su un Elenco è l'unica casella.
- L'unità ha `visibleWhen('type', Attributes::UNIT_TYPES)` ed è l'ultima della
  prima riga, così quando sparisce il vuoto resta in coda.

Le caselle nascoste arrivano lo stesso con il POST. `mutateRequestValues()`
svuota `unit` quando il tipo non la usa; colori e immagini dei valori invece
restano nel database, e tornando al tipo di prima si ritrovano.

Il tipo non può passare a Testo o Numero mentre ci sono dei valori:
`mutateRequestValues()` si ferma, perché il salvataggio li cancellerebbe in
silenzio. Fra Elenco, Colore, Fantasia e Icona si passa liberamente.

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
`products`, dichiarata da `productsField()` e messa da `optionsCard()` nella
colonna larga, **subito dopo «Prodotto»**: risponde alla domanda «ha varianti?»
che sta lì sopra. Con la griglia raggruppata (G2c) la riga ha quattro caselle e
il resto è nei dettagli avanzati, e a 1600 px ci sta; le sette caselle che
andavano a capo, e avevano spinto il riquadro sotto le due colonne, non ci sono
più.

Sotto l'ultimo attributo c'è `optionsPicker()`: un dropdown Bootstrap con il
bottone largo quanto il riquadro (`w-100`), scritto a mano in un `RichText`
perché il `Dropdown` del core non allarga toggle e menu. Le voci
(`data-wi-option-add`) si nascondono quando l'attributo è già acceso, e il
bottone sparisce al terzo. La **×** di un attributo non in uso passa da
`window.wiRepeaterConfirmDelete()` (la finestra del repeater, che nella pagina
c'è già), con `window.confirm()` di riserva.

| Quando | Cosa si vede |
|---|---|
| `has_variants` a `true` | il riquadro "Opzioni in vendita": selettore, spunte e griglia — identico in `create` e in `edit` |
| `has_variants` a `false` | nel riquadro «Prodotto» la riga del prezzo ha anche la giacenza, scrivibile (in creazione è un carico iniziale), e sotto l'`Accordion` a link «Compila le informazioni avanzate» con SKU, EAN, con `low_stock_alerts` la scorta minima e, con `purchasing`, il costo d'acquisto — tre caselle o il bottone «Costo», vedi [I fornitori di ogni opzione](#i-fornitori-di-ogni-opzione); con il modello già salvato compare il link alla rettifica |
| nessun attributo con valori | `optionsCard()` torna `[]` e il riquadro non c'è |

`has_variants` è una **colonna di `gst_product_models`**, non un conteggio: un
articolo appena creato ha già il suo prodotto figlio, e contare direbbe «no»
anche a chi le opzioni le sta per aggiungere. Il riquadro si nasconde da sé con
`visibleWhen('has_variants', 'true')` sulla Card, ma **le caselle nascoste
vengono postate lo stesso**: `mutateRequestValues()` e `saveExtras()` rileggono
la risposta dal POST e con un «no» non guardano nemmeno le spunte. Su un
articolo con più di un prodotto la risposta è forzata a `true` e l'interruttore
è disabilitato: far sparire dieci righe da una preferenza è una perdita di dati
travestita.

Colonne della griglia: `option` (finta, `readonly`), `price`, `stock` nella
riga; `sku`, `ean`, `min_stock` (solo con `low_stock_alerts`), `active`, le
colonne dei fornitori (solo con `purchasing`) e `photo` dietro
`repeaterAdvanced()`; `id`, `group` e `combination` nascoste. Quelle
della riga stanno **dentro undici**: la dodicesima è la colonna dei bottoni, e
quello che sfora va a capo. Quelle del blocco avanzato si contano su dodici,
perché lì bottoni non ce ne sono: con la scorta minima SKU, EAN, soglia e Stato
passano da 4 a 3 ciascuna. I fornitori vanno a capo sotto lo Stato:
`supplier_id`, `supplier_sku` e `cost` da 4 ciascuna, oppure il bottone
`cost_button` da 12 con la colonna nascosta `suppliers` (vedi
[I fornitori di ogni opzione](#i-fornitori-di-ogni-opzione)).

Al salvataggio `saveExtras()` scrive **prima le soglie e poi i pezzi**
(`saveMinStocks()`, poi carichi e rettifiche): ogni movimento rinfresca l'avviso
con la soglia che trova nel database, e con quella vecchia giacenza e soglia
cambiate insieme aprirebbero e chiuderebbero avvisi finti. Le soglie cambiate
senza un pezzo che si muove si rinfrescano alla fine. Accendendo le varianti il
generatore riprende lo scheletro per la prima combinazione: dove la riga nuova è
scritta vince su quella vecchia ancora nella griglia, dove è vuota vale la
vecchia, e i suoi pezzi sono una rettifica verso il numero scritto, non un
carico da sommare. Su un articolo appena creato lo scheletro è nato nella stessa
richiesta, e ogni combinazione ha il suo carico iniziale.

`saveExtras()` sceglie la strada della griglia anche quando il POST dice «no»
ma il modello dice «sì» (`hasVariants()`): eliminate le righe fino a una,
`mutateRequestValues()` ha già forzato la colonna, mentre l'interruttore
disabilitato manda «no» e le caselle in alto, nascoste, arrivano vuote.

- `option` non è la colonna `name`: una casella di sola lettura viene postata lo
  stesso, e avrebbe scritto "S" al posto di "Blu / S". La riempie
  `optionLabels()` con quello che resta del nome tolta la testata.
- `group` è **calcolata e nascosta**, non `product_variant_id`: una select
  scrivibile sposterebbe un prodotto da un colore all'altro senza spostarne i
  collegamenti agli attributi. In chiaro il valore lo dice la testata del
  gruppo.
- `stock` è scrivibile finché il magazzino ha **una sede sola**
  (`stockIsWritable()`): la casella mostra il totale di tutte le sedi e
  scriverebbe sulla principale, e con due sedi riscrivere il numero sposterebbe
  la merce senza dirlo.
- Niente `repeaterAddButton`: le righe nascono dalle spunte, e un bottone
  "Aggiungi" darebbe una riga senza nessuna combinazione dietro. Niente
  riordino: l'ordine lo decide il generatore.

**Il raggruppamento non si sceglie, l'ordine sì.** `repeaterGroupFixed('group')`
più `repeaterGroupCommand('price', 'Prezzo del gruppo')`: nessuna tendina
"Raggruppa per". Quale attributo faccia la testata lo dice `axes_order`, una
colonna di `gst_product_models` con gli id degli attributi scelti nell'ordine
voluto (`"509-508"`): il primo raggruppa, gli altri compongono il nome. Lo
scrive il selettore, con le frecce su ogni blocco acceso, e lo ripulisce
`axesFromPost()` tenendo solo quello che è davvero spuntato.

`groupsByAxis()` accende i gruppi da **due assi in su** — con uno solo ogni
testata ripeterebbe la riga — **oppure** quando l'unico asse è quello con foto
proprie (`variantAttributeId()`): la testata è il posto delle sue foto (P60).
Il browser fa lo stesso conto lato suo (`assi >= 2 || colore` in
`raggruppa()`). Cambiando l'ordine, il browser riscrive subito
`group` e `option` di ogni riga da `combination` (`riallinea()`), e al
salvataggio `realignNames()` riscrive i nomi veri: "Blu / S" diventa "S / Blu".
`Combinations::clientKey()` ordina gli id, quindi l'identità delle righe non
cambia e non nascono doppioni.

Attenzione al prefisso: `withoutExtras()` scarta ogni chiave che comincia per
`option_` — sono le spunte degli attributi — e per questo la colonna si chiama
`axes_order` e non `option_axes`, che sarebbe stata buttata via in silenzio.

**Le righe senza id non arrivano al sync del core.** Le tiene fuori
`prepareRepeaterRows()`, perché il colore a cui appartengono lo crea
`Generator::run()` in `afterStore`/`afterUpdate`, e `syncRepeaterRelations()`
gira prima: arriverebbero al database con una variante che non c'è. Per lo
stesso motivo serve `repeaterStartEmpty()` del core — il repeater stampa una
riga vuota di cortesia quando non ne ha, e quella riga, con la sua select di
stato che posta sempre un valore, sarebbe diventata un prodotto senza colore.

Vale sempre la regola di fondo: **un campo che non viene stampato non viene
postato**, e `syncRepeaterRelations()` cancella le righe che non ritrova.

**Le foto del colore stanno nella testata** (P60). Quando il primo asse è
l'attributo con foto proprie (`colorPhotosInGroups($modelId)`), la griglia
dichiara `repeaterGroupFiles($campo, 'group_value', 'Foto del colore')` del
core: accanto a *Prezzo del gruppo* c'è un bottone con il numero dei file che
apre un `fileDragDrop('gallery')`. Il gruppo si riconosce da `group_value`,
colonna nascosta con l'**id del valore** del colore — non il nome, che si
rinomina e si ripete fra articoli —, scritta da `optionLabels()` sul server e
da `chiaveFoto()` nel browser; resta vuota quando il primo asse non è il
colore, e senza chiave il core non mette il bottone. Il campo si posta come
`group_images[<id valore>]` più il manifesto `group_images[<id valore>__wi_files]`
(P42), e lo legge `Repeater::groupFilesFromRequest()`.

`saveGroupImages()` gira in `saveExtras()` **dopo** il generatore: un colore
appena spuntato ha già la sua variante, e `variantValues()` la trova. Un colore
che non nasce (in creazione, tutte le righe annullate) non ha variante, e le sue
foto cadono da sole. Le varianti scritte dalla testata passano a
`saveImages(…, $giaSalvate)`, che non le riscrive una seconda volta.

Con «Taglia, poi Colore» i gruppi sono taglie: `colorPhotosInGroups()` è falso
e le aree per colore restano nel riquadro «Foto e video» (in cima alla colonna
stretta), come prima.

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
sono più di una e il colore non è il primo asse, ogni colore; con il colore
davanti le sue foto stanno nella testata del gruppo, vedi sopra). Ogni area è un repeater sulla **stessa** tabella
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

## La scheda: due colonne

`formLayoutSchema()` torna due `Container`, `columnSpan(8)` e `columnSpan(4)`.
Perché funzioni serve `columns(12)` **sul Form**: il renderer
calcola la larghezza di un figlio sulle colonne del padre, e un Form senza
colonne ne ha una sola, quindi qualunque span diventa piena larghezza.

**La stessa in `create` e in `edit`.** La creazione era una schermata a sé con
cinque campi: si compilava, si salvava, si riapriva la scheda e si salvava
ancora. Ora no, e si può perché il core sincronizza i repeater con l'id appena
inserito (`syncRepeaterRelations($insertId, …)` subito dopo l'insert): foto,
righe e collegamenti nascono nello stesso salvataggio. Quello che non può
esistere prima del primo salvataggio semplicemente non compare — il link alla
rettifica, il pulsante "Dettagli delle opzioni". La giacenza del riquadro in
alto invece c'è (P59): `afterStore()` passa `$appenaNato` a `saveExtras()`, e
il numero diventa un carico iniziale.

**Categorie: un albero con la stella.** La principale non è un secondo campo:
`categories` è un `checkTree` con `->primaryField('main_category')`, e la lib
tiene l'id della voce con la stella nel campo nascosto `main_category`. Al
salvataggio `mainAmong($spuntate, $stella)` la riporta fra le spuntate (una
stella rimasta su una voce tolta cade sulla prima), e finisce in
`gst_product_model_categories.is_main`. Il `quickCreate` dell'albero chiede
`['name', 'parent_id']`: la risposta porta `item.parent_id`, e il nodo nasce
sotto il padre, già spuntato.

**La colonna larga** (`mainColumn()`): «Prodotto» — nome e stato, le due
descrizioni, la domanda sulle varianti, poi prezzo, scontato e giacenza, e
sotto «Compila le informazioni avanzate» con SKU, EAN, scorta minima e costo
d'acquisto —, poi «Opzioni in vendita»
(`optionsCard()`, con `visibleWhen('has_variants', 'true')`) e «Scheda
tecnica» in fondo. Con il bottone «Costo» dopo la scheda tecnica arrivano anche
`supplierCostModal()` e `supplierCostScript()`: una finestra sola per pagina,
fuori dal riquadro delle opzioni.

**La colonna stretta** (`sideColumn()`): «Foto e video» in cima, poi «Come si
vende» — i tre interruttori, ognuno con `InputToggle::description()`, sotto
«Da spedire» il `package_id` con `visibleWhen('requires_shipping', 'true')` e,
con `backorders`, un quarto interruttore, «Vendita senza giacenza», con i
«Giorni di attesa» sotto (`backorderInputs()`, vedi
[La vendita senza giacenza](#la-vendita-senza-giacenza))
—, «Tipo fiscale», «Dove si trova» e «Misure» in fondo, due caselle per riga.
Il riquadro «Spedizione», che il server includeva solo per un articolo che si
spedisce (`shipsFrom()`), non c'è più: la regola sul campo segue
l'interruttore senza ricaricare. Da spento `package_id` viene postato lo
stesso — la lib nasconde con `display:none`, non disabilita — e
`mutateRequestValues()` lo toglie quando `requires_shipping` è `false`: una
scatola «Ferma» non è fra le scelte di `Packages::options()`, la select
manderebbe vuoto e la scatola si perderebbe. Riaccendendo, torna.

**Il tipo fiscale in un riquadro suo.** Nella colonna stretta, sotto «Come si
vende», il
riquadro «Tipo fiscale» ha il solo select, con etichetta «IVA»: la select è
«floating» e un'etichetta vuota lascerebbe solo l'asterisco. `taxCategoryField($modelId)`
parte da `TaxCategories::defaultId()` (vedi [IVA e impostazioni](iva-e-impostazioni.md#il-tipo-predefinito))
e si vede sempre, anche con un tipo solo (P58): un campo nascosto non si trova
il giorno in cui serve. Su un articolo salvato passa a `options()` il tipo che
l'articolo usa, così un tipo nascosto resta in coda invece di essere
sostituito al primo salvataggio.

**Colonne che spariscono.** Prezzi e giacenza hanno
`hiddenWhen('has_variants', 'true')`, e così l'`Accordion::link()` «Compila le
informazioni avanzate» con SKU, EAN, scorta minima e costo d'acquisto: è la stessa tendina delle
righe della griglia, perché sono codici che servono di rado. Il riquadro
«Codici» non c'è più. Il core marca la loro colonna come contenitore
condizionale e la nasconde intera: in una `row g-3` una colonna vuota lascia
comunque il margine. Per l'accordion lo fa `ResourceFormLayoutRenderer`, che
sposta le regole di visibilità dal nodo interno alla colonna. E un campo senza `columnSpan()` in un
contenitore a 12 colonne ne prende una: nei layout dei `quickCreate` va
dichiarato `->columnSpan(12)`.

## Prezzi: quello dell'articolo e quelli delle opzioni

Con le varianti `product_price` e `product_sale_price` sono nascosti
(`hiddenWhen('has_variants', 'true')`): i prezzi si scrivono riga per riga
nella griglia. La casella nascosta però viene postata, e `savePrices()` la
scrive su tutte le righe quando è piena. La casella **vuota non tocca niente**.

Con un prodotto solo la casella mostra il prezzo di quello. Con più di uno
`mutateFormValues()` la lascia **vuota**, e non è una dimenticanza: la casella
si salva **dopo** la griglia, quindi un prezzo rimasto lì dentro riscriverebbe
la riga appena corretta. Resta piena solo quando le varianti si sono appena
accese su un articolo che aveva una versione: allora il prezzo che aveva va alle
opzioni nate senza il loro, e nessuna parte a zero.

Una riga **nata adesso con il suo prezzo** `savePrices()` la salta: è stata
appena scritta nella stessa schermata.

## La giacenza si scrive dalla scheda

La colonna `stock` non è una colonna di `gst_products`: il salvataggio la butta
via, e `saveRowExtras()` la ripesca da quello che è stato postato. Si scrive
**quanti pezzi ci sono**, non di quanto cambiarli: `Stocktake::quantity()` legge
il numero all'italiana, `Stocktake::changes()` lo confronta con
`Levels::forProducts()` e solo le differenze diventano `Stock::apply()` con
causale `Reasons::DEFAULT` (*Inventario*). Una casella riscritta uguale non
muove niente.

Per una riga appena nata la quantità è invece un carico: `saveNewVersions()` la
registra con causale `initial_stock` attraverso `loadInitialStock()`. Lo stesso
vale per la casella in alto di un articolo senza varianti appena creato:
`saveSingleStock(..., $appenaNato)` carica invece di rettificare, e lo fa
anche con più sedi — su un articolo che nasce non c'è niente da rendere
ambiguo, e `Stock::apply()` senza sede va sulla principale. Per la stessa
ragione in creazione la colonna `stock` della griglia si scrive sempre. Il
magazzino ha una porta sola, e resta quella.

Il rifiuto di un numero negativo sta in `assertStockWritable($id, $conVarianti)`,
chiamato da `mutateRequestValues()`: **dopo l'insert non c'è nessuna rete** —
il sync e `afterUpdate` girano fuori da qualunque `try` — e l'errore
diventerebbe una pagina di guasto su un articolo già scritto a metà. Guarda la
casella in alto o le righe, secondo la risposta a «ha varianti?», e lascia
passare un numero sotto zero uguale a quello che il prodotto ha già
(`negativeWritten()`): è una vendita in arretrato, non l'ha scritto nessuno.
Vale anche con la vendita senza giacenza accesa: la casella dice quanti pezzi
ci sono, e un numero negativo scritto a mano resta un errore di battitura. Sotto
zero porta solo un movimento che scarica, e solo su un'opzione che lo permette
(vedi [La vendita senza giacenza](#la-vendita-senza-giacenza)).

I decimali della griglia arrivano interi fino al database dalla **2.2.15** del
core: prima il suo `prepare()` li arrotondava (21,50 diventava 22,00). Non
c'era rimedio lato modulo, perché l'hook `prepareRepeaterRelationRow()` gira
**prima** di `preparePayload()`.

## La vendita senza giacenza

Funzionalità `backorders`, che richiede `orders`. Bloccata, «Come si vende» ha
i suoi tre interruttori e nessuno scrive le due colonne: `backorderFields()` e
`backorderInputs()` tornano `[]`, `backorderChoice()` torna `null`.

Sbloccata, sotto «Da spedire» c'è **«Vendita senza giacenza»** e, solo da
acceso, **«Giorni di attesa»** (`visibleWhen('allow_backorder', 'true')`), come
l'imballaggio segue «Da spedire».

**L'interruttore è dell'articolo, le colonne sono delle opzioni** (P85).
`allow_backorder` e `backorder_lead_days` stanno in `gst_products`, non in
`gst_product_models`: `withoutExtras()` le toglie dai valori del modello e
`saveBackorders()` le scrive su **tutte** le opzioni vive, anche su quelle nate
nella stessa richiesta, toccando solo le righe che cambiano. Cosa scrive lo
decide `backorderChoice()`, chiamata anche da `mutateRequestValues()` perché
il rifiuto arrivi prima dell'insert:

| Nel post | Cosa si scrive |
|---|---|
| niente `allow_backorder` | niente: le opzioni restano come sono |
| spento | `false` e zero giorni, anche se la casella nascosta ne porta ancora |
| acceso, giorni vuoti | `true` e zero giorni |
| acceso, un intero da 0 a 365 | `true` e quei giorni |
| acceso, altro | `product.backorder_lead_days_invalid` |

In `saveExtras()` gira **dopo il generatore**, perché vale anche per le opzioni
appena nate, e **prima di prezzi, soglie e pezzi**: `Stock::apply()` rilegge
l'opzione, e deve trovare l'interruttore già scritto.

Riaprendo, `backorderSummary()` riassume le opzioni: acceso **solo se lo è su
tutte** (e ce n'è almeno una), con i giorni più lunghi fra quelle accese. Così
un'opzione rimasta indietro si vede: la scheda riapre spenta, e il salvataggio
successivo spegne tutte. Dopo un salvataggio rifiutato il form torna con quello
scritto (`mutateFormValues()` aggiunge il riassunto con `+=`).

**Sotto zero ci vogliono due sì** (P86). `Stock::apply()` lascia scendere la
giacenza solo se `backorders` è attiva **e** l'opzione ha
`allow_backorder = 'true'`; altrimenti `stock.insufficient`. La funzionalità
dice che si può, l'articolo dice se lo vende scoperto. In G2b bastava la
funzionalità: ora un articolo con l'interruttore spento resta un muro anche con
`backorders` attiva. Il muro è solo per i movimenti che tolgono: un carico
entra sempre, anche se la giacenza resta sotto zero (vedi
[Magazzino](magazzino.md#i-rifiuti)). I documenti di magazzino passano dalla
stessa porta e seguono la stessa regola.

Un'impostazione del negozio che faccia nascere gli articoli già accesi non c'è:
un articolo nuovo parte spento.

## I fornitori di ogni opzione

Funzionalità `purchasing`. Da chi si compra un'opzione, con quale codice e a
quanto sta in `gst_product_suppliers`: la tabella, le regole e gli helper sono
in [Fornitori e costi d'acquisto](acquisti.md). Qui c'è come li scrive la
scheda dell'articolo. Con la funzionalità bloccata non si vede e non si scrive
niente, e i legami salvati restano dove sono.

**Tre caselle o una finestra** (P88, P89). Lo decide `supplierCostMode()`, e
schema, layout, controllo e salvataggio leggono la stessa risposta:

| Modo | Quando | Cosa c'è |
|---|---|---|
| `null` | `purchasing` bloccata | niente |
| `flat` | al più un fornitore proponibile | tre caselle: Fornitore, Codice fornitore, Costo d'acquisto |
| `modal` | due o più | il bottone «Costo» con il riassunto del preferito, e la finestra |

Senza varianti i campi stanno nella tendina del riquadro «Prodotto», con
`hiddenWhen('has_variants', 'true')`: `product_supplier_id`,
`product_supplier_sku` e `product_cost`, oppure `product_suppliers` (nascosto)
e `product_cost_button`. Con le varianti sono colonne di ogni riga della
griglia: `supplier_id`, `supplier_sku` e `cost`, oppure `suppliers` e
`cost_button`. `withoutExtras()` toglie dai valori del modello quelli con il
prefisso `product_`.

**I fornitori proponibili** li dà `supplierChoices($modelId)`: gli attivi, più
quelli già legati a un'opzione dell'articolo anche se nel frattempo sono stati
messi su «Non attiva» (`Contacts::supplierOptions($legati)`, con
«(non attivo)» accanto al nome). Senza, la tendina posterebbe vuoto e
staccherebbe un fornitore solo perché non è più attivo. Il loro numero, e
quindi la forma, è **per articolo**, e si legge una volta per richiesta:
`forgetCatalogCache()` la azzera.

**Un fornitore non attivo vale solo dove c'è già** (P92). Le scelte di
un'opzione sono quelle dell'articolo meno i non attivi (`inactiveSupplierIds()`)
che lei non ha: `supplierChoicesFor($modelId, $productId)`, su cui
`assertSupplierCosts()` controlla ogni riga. Una riga nuova, e l'opzione unica
di un articolo nuovo, hanno solo gli attivi; l'opzione unica di un articolo
salvato ha i suoi. Nel browser la finestra nasconde la riga di un fornitore non
attivo sulle opzioni che non lo hanno nel loro JSON, e con le tre caselle
`supplierInactiveScript()` lo toglie dalle tendine che non lo hanno scelto e
dal template da cui il repeater clona le righe nuove. Il controllo vero resta
quello del server.

**Le tre caselle** scrivono il preferito, e basta: con un fornitore solo non
c'è altro da scegliere. Un fornitore svuotato **stacca** (P88): l'opzione perde
il legame, e codice e costo rimasti accanto non si salvano e non fermano
niente. Se nel post non arriva nessuna delle tre chiavi, l'opzione non si
tocca.

**La finestra** (`supplierCostModal()`, id `wi-supplier-costs`) è una sola per
pagina, anche con dieci righe: una riga per fornitore proponibile, con
Preferito (radio `pills()`), nome, Codice fornitore, Costo e la «x» che lo
stacca, e i bottoni Annulla e Salva. Ogni riga è un blocco con
`data-wi-supplier-line`, che lo script nasconde per intero. Non è legata a nessuna opzione, e i suoi campi
(`wi_supplier_cost[...]`) non arrivano al salvataggio: il `Modal` del core si
sposta sotto `document.body`, fuori dal form. Il trasporto è il **campo
nascosto** della riga, un JSON:

```json
[{"supplier_id": 7, "supplier_sku": "FN-120", "cost": "12.00", "is_preferred": "true"}]
```

`supplierCostScript()` fa il resto nel browser. All'apertura
(`show.bs.modal`) cerca il campo della riga del bottone, o `product_suppliers`
senza varianti, mette nel titolo «Costo · Blu / S» (o il nome dell'articolo) e
riempie le righe. «Salva» riscrive il JSON con i fornitori **legati**: quelli
che l'opzione aveva già, anche senza codice né costo, e quelli toccati nella
finestra — un codice, un costo, il preferito. Le tre caselle e la scheda
dell'opzione salvano un legame con il solo fornitore, e la finestra non deve
perderlo in silenzio: per staccarlo c'è la «x» della riga, che ne svuota anche
i campi. Poi un preferito solo — quello scelto, o il primo legato — e il testo
accanto al bottone
(`span[data-wi-button-caption]`): «Filati Nord · 12,00 €», il solo nome se il
costo non si sa, «Nessun fornitore». La scheda si salva con il suo Salva.

| Campo nascosto | Cosa vuol dire |
|---|---|
| vuoto, o non un elenco | la finestra non l'ha toccato: i legami restano |
| `[]` | finestra salvata senza fornitori: i legami se ne vanno |
| un elenco | i legami dell'opzione, com'erano nella finestra |

Riaprendo la scheda `supplierFields()` riempie il campo dai legami salvati e
scrive il riassunto con `ProductSuppliers::summary()`; dopo un salvataggio
rifiutato tiene quello postato.

**Il controllo** è `assertSupplierCosts()`, chiamato da
`mutateRequestValues()` dopo le soglie: per ogni opzione legge i fornitori
postati (`postedSuppliers()`) e li passa a `ProductSuppliers::assertValid()`
contro i fornitori proponibili per quell'opzione. Un doppione, un fornitore
che la pagina non propone, un costo negativo, non numerico o oltre quello che
la colonna tiene, o un codice oltre cento caratteri si fermano prima
dell'insert: il core scrive l'articolo prima di `saveExtras()`, e un rifiuto di
MySQL a metà lascerebbe i legami salvati a metà.

La forma dei fornitori postati la dice quello che arriva, non il modo di
adesso: fra l'apertura della pagina e il salvataggio un fornitore può
diventare attivo, o smettere di esserlo, e il modo cambiare. `postedSuppliers()`
legge il JSON se arriva solo quello, le tre caselle se arrivano solo quelle, e
con tutte e due vince il modo. Guardando solo il modo, le modifiche della pagina
aperta sparirebbero senza un errore.

**Il salvataggio** è `saveSupplierCosts()`, in `saveExtras()` subito dopo
`saveMinStocks()`. Senza varianti scrive sul prodotto unico; con le varianti le
righe salvate per id (solo opzioni vive) e quelle nate per chiave della
combinazione. Una riga nata e lasciata vuota non stacca niente: il generatore
può averle dato lo scheletro, che i suoi fornitori li ha già. Scritta, sullo
scheletro ripreso (`$riprese`) **si aggiunge** (`mergeSupplierRows()`): la sua
finestra è nata vuota dal template e non ha mostrato i legami dello scheletro,
che quindi restano — quelli postati dalla sua riga vecchia ancora nella
griglia, o quelli salvati se quella non arriva. La riga nuova vince fornitore
per fornitore, e il suo preferito diventa quello dell'opzione; per staccare un
legame dello scheletro c'è la «x» sulla sua riga. Ogni opzione
passa da `ProductSuppliers::sync()`, dopo `keepStoredCosts()`: il costo si
scrive con due decimali e si tiene con quattro, e se la casella dice lo stesso
numero arrotondato resta quello salvato. Alla fine
`ProductSuppliers::dropRemovedOptions()` toglie i legami delle opzioni che la
griglia ha messo nel cestino.

**Eliminare l'articolo** (`deleteRecord()`) toglie per primi i legami di tutte
le sue opzioni, anche di quelle già nel cestino e anche con `purchasing`
bloccata: la chiave esterna non lascerebbe eliminare il prodotto. Lo stesso fa
`ProductResource::deleteRecord()` per una singola opzione.

**Nella scheda dell'opzione** (`ProductResource`) c'è il riquadro «Fornitori»,
dopo «Magazzino»: il repeater `suppliers` con Fornitore, Codice fornitore,
Costo d'acquisto e Preferito, legato a `gst_product_suppliers` con
`RepeaterRelation` (`positionKey('position')`, `softDelete(false)`),
riordinabile. Qui i fornitori sono **tutti**, non solo il preferito, e le
scelte sono quelle dell'opzione: gli attivi più quelli già legati a lei. Senza
fornitori da proporre il riquadro dice di aggiungerne uno da Anagrafiche →
Fornitori.

- `mutateRequestValues()` controlla le righe grezze
  (`Repeater::rowsFromRequest()`) con `assertValid()`: il core le ha già tolte
  da `$values`.
- `syncRepeaterRelations()` svuota l'id di una riga che non è di questa
  opzione, che così si salva come riga nuova. Il repeater del core aggiorna
  per id e riscrive l'opzione: l'id di un'altra opzione (un form copiato o
  ritoccato) le porterebbe via il legame, e uno che non c'è più — la finestra
  dell'articolo ha cambiato fornitore mentre la scheda era aperta — lascerebbe
  l'opzione senza fornitori. Vince chi salva per ultimo.
- `prepareRepeaterRows()` scarta le righe vuote e i doppioni, e tiene un
  preferito solo. Il preferito è una tendina per riga, e sceglierne un altro
  non spegne quello di prima: con due «Sì» vince quello che nel database non
  lo era (`supplierCardNewPreferred()`), poi `preferOne()`.
- `prepareRepeaterRelationRow()` tiene il costo a quattro decimali quando la
  casella dice lo stesso numero arrotondato; `mutateFormValues()` lo mostra
  con due, e vuoto resta vuoto.
- Nessun indice unico su opzione e fornitore (P93): il repeater del core prima
  scrive e poi toglie, e uno scambio di righe inciamperebbe a metà
  salvataggio. I doppioni li ferma la scheda, che può spiegarlo.

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
  slug. `Slug::unique($nome, $model)` lo rende libero anche dentro un comando
  (`accessori`, poi `accessori-2`), contando pure le righe cancellate, che
  l'indice unico vede ancora.

## Niente colonne SEO

Titolo e descrizione per i motori di ricerca li compone l'ecommerce da come è
organizzato il sito. Centinaia di campi SEO da riempire a mano resterebbero
vuoti o scritti male.

## Dati di prova

`php forge gestionale:demo` crea un marchio (Maglificio Aurora), tre categorie
(Abbigliamento › Magliette e felpe, e Accessori), due tag (Novità, Saldi), due
imballaggi, tre attributi con i loro valori — "Colore" sulla variante, "Taglia"
e "Materiale" sul prodotto — e **quattro articoli**, che sono i quattro casi che
la griglia deve reggere:

| Articolo | Perché c'è |
|---|---|
| Cappello di lana | nessun attributo: una riga sola, prezzo, SKU ed EAN nel riquadro «Prodotto» |
| Maglietta girocollo | tutti e tre gli attributi: dodici righe, e una riga si legge "S / Gomma" |
| Felpa con cappuccio | tre colori per quattro taglie: la griglia raggruppata, senza costruirla a mano |
| Calzini a costine | nessun colore: la griglia resta piatta, senza testate |

Ognuno ha la sua foto finta (un rettangolo colorato scritto sul disco, che nasce
`pending` come una foto vera). Sulla maglietta ci sono anche una foto di colore
e una di singola opzione, così l'eredità a tre livelli si legge tutta in una
scheda. Le classi stanno in `src/Seeding/`, registrate in `Seeding\Demo`.

I nomi sono quelli di un negozio vero. Una riga di prova si riconosce dal
**segno nel codice**: il prefisso dell'entità, `demo-` e un riferimento fisso,
come `cat_demo-accessori` (`Seeding\DemoCode`). Il codice non cambia più dopo
l'inserimento e un codice vero non ha mai il trattino, quindi rinominare una
riga dal gestionale non la fa sparire dai dati di prova, e una riga vera non ci
finisce dentro.

Marchio, categorie, tag, attributi e imballaggi si cercano così:

1. la riga col segno;
2. altrimenti una riga vera con lo stesso nome, senza badare alle maiuscole: si
   usa com'è, non si segna e non si cancella mai. Sotto un attributo vero si
   aggiungono i valori che mancano, e quei valori restano anche dopo la pulizia;
3. altrimenti la si crea col segno.

Gli articoli si cercano solo col segno. Il comando dice quali righe vere ha
usato.

`--fresh` toglie solo le righe col segno, più quelle con i vecchi nomi `Prova …`
di prima del segno (`DemoCode::LEGACY_NAMES`, da togliere quando nessun sito le
ha più). Prima gli articoli di prova, con varianti, prodotti, foto, collegamenti
e storia di magazzino; poi imballaggi, attributi, tag, categorie e marchio, ma
solo quelli che nessun articolo vero usa più. Quelli ancora in uso restano, e il
comando li elenca sotto la sua riga, per esempio
`Resta al suo posto 1 dato di prova ancora in uso: categoria «Accessori».` Le
note passano da `DemoData::note()`.

Le quattro schede di prova della rubrica (`Seeding\ContactsDemo`) seguono la
stessa regola: segno nel codice (`con_demo-bianchi`), nessuna scheda nuova se ce
n'è già una vera con lo stesso nome, e la pulizia che lascia un fornitore
nominato da un movimento di magazzino o dal costo d'acquisto di un'opzione. I
costi d'acquisto degli articoli di prova se ne vanno con loro
(`ProductModelResource::deleteRecord()`), e il conto di quello che la pulizia
ha tolto li comprende: anche quelli delle opzioni nel cestino, che se ne vanno
con le altre (`CatalogDemo::supplierLinksOf()` conta per `product_model_id`,
senza guardare `deleted`).
