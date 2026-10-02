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
| `has_variants` a `false` | nel riquadro «Prodotto» la riga del prezzo ha anche la giacenza, scrivibile con una sede sola (in creazione è un carico iniziale), e sotto l'`Accordion` a link «Compila le informazioni avanzate» con SKU, EAN, con `low_stock_alerts` e una sede sola la scorta minima e, con `purchasing`, i fornitori del prodotto (vedi [I fornitori delle opzioni](#i-fornitori-delle-opzioni)); con il modello già salvato compare il link alla rettifica. Con più sedi giacenza e scorta minima stanno invece nel riquadro «Magazzino» (`stockCard()`), righe «Giacenza per sede», vedi [Con più sedi](#con-piu-sedi) |
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

Colonne della griglia: `option` (finta, `readonly`), `price`, `sale_price`
(«Scontato», P105) e, con una sede sola, `stock` nella riga; `sku`, `ean`,
`min_stock` (solo con `low_stock_alerts` e una sede sola), `active`, i
fornitori (con `purchasing`), con più sedi il bottone `stock_button`
(«Giacenza») e `photo` dietro `repeaterAdvanced()`; `id`, `group`,
`group_value`, `combination` e, quando servono, `locations` e `suppliers`
nascoste. Quelle della riga stanno **dentro undici**: la dodicesima è la
colonna dei bottoni, e quello che sfora va a capo. **Con più sedi la colonna
«Giacenza» non c'è** (P113): un totale che non si può scrivere confonde, e i
pezzi si scrivono sede per sede dalla finestra; *Opzione* passa da 5 a 7 e si
prende il suo posto.

Quelle del blocco avanzato si contano su dodici, perché lì bottoni di riga non
ce ne sono: con la scorta minima SKU, EAN, soglia e Stato passano da 4 a 3
ciascuna. Sotto vengono i fornitori (`supplierColumns()`, P108): con un
fornitore solo `supplier_sku` e `supplier_cost`, da 6 ciascuna; con due o più
il bottone `suppliers_button` («Fornitori»). Il bottone «Giacenza» ha accanto
il riassunto delle sedi («Milano 12 · Roma 3», o «Nessun pezzo» da
`emptyCaption()`; vedi [Con più sedi](#con-piu-sedi)), quello dei fornitori il
suo («Filati Nord 12,00 € · Lana Sud», o «Nessun fornitore»): da 6 e in fila
quando ci sono tutti e due, da 12 quando ce n'è uno solo (vedi [I fornitori
delle opzioni](#i-fornitori-delle-opzioni)).

Al salvataggio `saveExtras()` scrive **prima le soglie e poi i pezzi**
(`saveMinStocks()`, poi carichi e rettifiche, e con più sedi
`saveLocationStock()` in coda): ogni movimento rinfresca l'avviso
con la soglia che trova nel database, e con quella vecchia giacenza e soglia
cambiate insieme aprirebbero e chiuderebbero avvisi finti. Le soglie cambiate
senza un pezzo che si muove si rinfrescano alla fine. Con più sedi
`saveMinStocks()` non fa niente: soglie e pezzi viaggiano insieme nelle righe
per sede (vedi [Con più sedi](#con-piu-sedi)). Accendendo le varianti il
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
rettifica. La giacenza del riquadro in
alto invece c'è (P59): `afterStore()` passa `$appenaNato` a `saveExtras()`, e
il numero diventa un carico iniziale.

**La scheda in lettura** (P123). `pageSchema()` accende la pagina `view`, le
dà il titolo «Scheda prodotto» e la view del modulo
(`->view('show', Gestionale::viewPath('pages/product-model-show.php'))`); la
view non disegna niente, chiama `showLayoutSchema($ITEM)` e la passa a
`ResourceFormLayoutRenderer`. Il disegno è quello della modifica — due
`Container`, otto e quattro — con i riquadri «Prodotto» e «Opzioni in vendita»
a sinistra, «Foto e video», «Stato» e «Dove si trova» a destra; sotto la
colonna stretta resta lo spazio per le statistiche dell'articolo. Nell'elenco
la colonna `name` porta lì (`->link('view')`), e l'unica azione della pagina è
«Modifica», che apre `editUrlFor($id)`.

Le opzioni sono la tabella di `ProductResource` ristretta all'articolo
(`optionsTable()` con `backendTable(optionsColumns())`, P128), senza titolo e
senza filtri; lo stato di ogni riga si commuta dalla pillola
(`TableColumn::badgeClickable()`). Quello dell'articolo è una `BooleanBadge`
fuori tabella (`statusBadge()`): `ajaxRequest` con il solo indirizzo ricarica
la pagina, che qui è quello che serve.

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
sotto «Compila le informazioni avanzate» con SKU, EAN, scorta minima e
fornitori —, con
più sedi «Magazzino» (`stockCard()`, con `hiddenWhen('has_variants', 'true')`:
le righe «Giacenza per sede» e il link alla rettifica, al posto della giacenza
e della scorta minima del riquadro «Prodotto»), poi «Opzioni in vendita»
(`optionsCard()`, con `visibleWhen('has_variants', 'true')`) e «Scheda
tecnica» in fondo. Con più sedi dopo la scheda tecnica arrivano anche
`locationStockModal()` e `locationStockScript()`: una finestra sola per
pagina, fuori dal riquadro delle opzioni (vedi [Con più sedi](#con-piu-sedi)).
Con due o più fornitori da proporre li seguono `suppliersModal()` e
`suppliersScript()`, per la stessa ragione (vedi [I fornitori delle
opzioni](#i-fornitori-delle-opzioni)).

**La colonna stretta** (`sideColumn()`): «Foto e video» in cima, poi «Come si
vende» — i tre interruttori, ognuno con `InputToggle::description()`, sotto
«Da spedire» il `package_id` con `visibleWhen('requires_shipping', 'true')` e,
con `backorders`, un quarto interruttore, «Vendita senza giacenza», con i
«Giorni di attesa» sotto (`backorderInputs()`, vedi
[La vendita senza giacenza](#la-vendita-senza-giacenza))
—, «Tipo fiscale», «Dove si trova» e «Misure» in fondo, due caselle per riga.
Il riquadro «Fornitori» del tredicesimo giro non c'è più: i fornitori sono
delle opzioni, e stanno con i loro codici (P108).
Il riquadro «Spedizione», che il server includeva solo per un articolo che si
spedisce (`shipsFrom()`), non c'è più: la regola sul campo segue
l'interruttore senza ricaricare. Da spento `package_id` viene postato lo
stesso — la lib nasconde con `display:none`, non disabilita — e
`mutateRequestValues()` lo toglie quando `requires_shipping` è `false`: una
scatola «Ferma» non è fra le scelte di `Packages::options()`, la select
manderebbe vuoto e la scatola si perderebbe. Riaccendendo, torna.

**Il tipo fiscale in un riquadro suo.** Nella colonna stretta, sotto «Come si
vende», il riquadro «Tipo fiscale» ha il solo select, con etichetta «IVA»: la select è
«floating» e un'etichetta vuota lascerebbe solo l'asterisco. `taxCategoryField($modelId)`
parte da `TaxCategories::defaultId()` (vedi [IVA e impostazioni](iva-e-impostazioni.md#il-tipo-predefinito))
e si vede sempre, anche con un tipo solo (P58): un campo nascosto non si trova
il giorno in cui serve. Su un articolo salvato passa a `options()` il tipo che
l'articolo usa, così un tipo nascosto resta in coda invece di essere
sostituito al primo salvataggio.

**Colonne che spariscono.** Prezzi e giacenza hanno
`hiddenWhen('has_variants', 'true')`, e così il riquadro «Magazzino» delle
sedi e l'`Accordion::link()` «Compila le informazioni avanzate» con SKU, EAN,
scorta minima e fornitori: è la stessa tendina delle
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

Per una riga appena nata la quantità è invece un carico: con una sede sola
`saveNewVersions()` la registra con causale `initial_stock` attraverso
`loadInitialStock()`. Lo stesso vale per la casella in alto di un articolo
senza varianti appena creato: `saveSingleStock(..., $appenaNato)` carica invece
di rettificare.

Con più sedi né la colonna né la casella ci sono (P113), e un numero postato
lo stesso non si scrive, **nemmeno in creazione**: `saveNewVersions()` e
`saveSingleStock()` non lo leggono quando `stockIsWritable()` è falsa, e i
pezzi arrivano solo dalle righe per sede. L'articolo nuovo senza varianti parte con una riga vuota sulla sede
principale (`mutateFormValues()`), un'opzione che nasce si carica dalla finestra
«Giacenza»; `applyLocationRows(..., $appenaNato)` scrive le righe come carico.
Due porte sulla sede principale si contenderebbero lo stesso numero: il
magazzino ne ha una sola, e resta quella.

`stockIsWritable()` è `count(Locations::shown()) <= 1`. Con più sedi
`formLayoutSchema()` non mette `product_stock` nel riquadro «Prodotto» e
`productsField()` non dichiara la colonna `stock`: il totale si legge
nell'elenco degli articoli e in Giacenze, non in una casella che non si può
scrivere.

### Con più sedi

Quando il magazzino mostra almeno due sedi (`hasManyLocations()`, cioè
`count(Locations::shown()) >= 2`; P102, P103) pezzi e soglie si scrivono
**sede per sede**, con le stesse righe in tre posti:

| Dove | Campo | Come |
|---|---|---|
| articolo senza varianti | il repeater `locations` di `stockRowsField()` nel riquadro «Magazzino» (`stockCard()`), senza `RepeaterRelation` | `mutateFormValues()` lo compone con `LocationRows::compose(Locations::shown(), Levels::byLocation($productId), Thresholds::forProduct($productId))`: una riga per ogni sede che ha pezzi o una soglia |
| riga della griglia | la colonna nascosta `locations`, con le righe in JSON, e il bottone `stock_button` («Giacenza») che apre la finestra `wi-location-stock` (`locationStockModal()`: una per pagina, tante righe quante le sedi, nascoste finché non servono) | `mutateFormValues()` scrive il JSON con `compose()` (`Levels::byLocationForProducts()`, `Thresholds::forProducts()`) e la didascalia con `LocationRows::summary()` (dopo un salvataggio rifiutato la pagina torna senza `id` e con le righe scritte: `withFormStockButtons()` rimette il bottone e scrive la didascalia con `LocationRows::summaryOfRows()`, dal JSON del form); `locationStockScript()` legge il JSON, intitola la finestra «Giacenza · Blu / S» e con «Salva» riscrive JSON e didascalia. Le caselle `wi_location_stock[i][…]` stanno fuori dal form del repeater e `withoutExtras()` le butta via. Un JSON mai toccato non rettifica |
| scheda dell'opzione | il repeater `locations` di `ProductResource::locationRowsField()` nel riquadro «Magazzino» | come l'articolo senza varianti; `afterUpdate()` passa da `keepThresholds()`, che con gli avvisi spenti rimette le soglie salvate |

Le caselle di giacenza e scorta minima, nei tre posti, si scrivono con
`locationFormat()`, cioè `LocationRows::format($unita, $giacenze, $soglie)`:
l'unità dell'articolo in coda e i suoi decimali, tre se **una sede** ha già
dei decimali. Si guarda ogni sede e non la somma (`locationQuantities()`,
`locationThresholds()`): 1,5 + 1,5 fa 3, e una casella arrotondata salverebbe
un movimento che nessuno ha chiesto. La scheda dell'opzione passa da
`optionFormat()`, che legge l'articolo dell'opzione; con una sede sola la sua
casella «Scorta minima» usa lo stesso formato.

Ogni riga è `{location_id, stock, min_stock}`: `stock` vuoto non tocca niente,
zero scritto vale zero; `min_stock` c'è solo con `low_stock_alerts`. Il
controllo è `LocationRows::normalize()` (`Support\Stock`, pura), che
`assertLocationRows($post, $conVarianti, $modelId)` chiama da
`mutateRequestValues()` — prima dell'insert, come `assertStockWritable()`:
`stock.location_unknown` per una sede che il magazzino non mostra più,
`stock.location_duplicate` per la stessa sede due volte,
`product.min_stock_invalid` per una soglia che non è un numero da zero in su.
Una riga con la sede su «—» si scarta. Sulle righe buone passa poi
`LocationStock::assertNotNegative($productId, $rows)`: una giacenza sotto zero
è `product.stock_negative`, nel repeater come nel JSON della griglia; la scheda
dell'opzione fa lo stesso controllo nel suo `mutateRequestValues()`.

Il salvataggio è `saveLocationStock()`, in coda a `saveExtras()`: senza
varianti legge il repeater, con le varianti il JSON di ogni riga
(`LocationRows::fromForm()`); lo scheletro ripreso dalla prima combinazione
vale con il JSON della riga nuova, e una riga nata adesso è un carico.
`applyLocationRows()` chiama `LocationStock::apply($productId, $rows,
$appenaNato)` — con gli avvisi spenti tiene le soglie già salvate — e poi
`Alerts::refresh()`, che apre e chiude un avviso per sede. Una sede tolta
dalle righe perde la sua soglia (`Thresholds::save()` scrive tutte le sedi
insieme), non i pezzi: finché ne ha, `compose()` la ripropone.

Con una sede sola la soglia resta nella casella «Scorta minima» accanto a SKU
ed EAN (`minStockInput()`, `product_min_stock` e `products[row][min_stock]`),
ma vive nella stessa tabella: `saveMinStocks()` → `writeMinStock()` →
`Thresholds::save($productId, [Locations::mainId() => $soglia])`, e
`mainThresholds()` la rilegge per la scheda. `gst_products.min_stock_quantity`
non c'è più (vedi [Magazzino](magazzino.md#gli-avvisi-di-scorta-minima)).

Il rifiuto di un numero negativo sta in `assertStockWritable($id, $conVarianti)`,
chiamato da `mutateRequestValues()`: **dopo l'insert non c'è nessuna rete** —
il sync e `afterUpdate` girano fuori da qualunque `try` — e l'errore
diventerebbe una pagina di guasto su un articolo già scritto a metà. Guarda la
casella in alto o le righe, secondo la risposta a «ha varianti?», e lascia
passare un numero sotto zero uguale a quello che il prodotto ha già
(`negativeWritten()`): è una vendita in arretrato, non l'ha scritto nessuno.
Con più sedi `assertStockWritable()` non guarda niente, perché casella e
colonna non ci sono: lo stesso rifiuto, con la stessa eccezione sede per
sede, è di `LocationStock::assertNotNegative()` (`hasNegative()` evita la
lettura delle giacenze quando nessuna riga è sotto zero).
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

## I fornitori delle opzioni

Funzionalità `purchasing`. Da chi si compra un'opzione, con quale codice e a
quanto sta in `gst_product_suppliers`: la tabella, le regole e gli helper sono
in [Fornitori e costi d'acquisto](acquisti.md). Qui c'è come li fanno
compilare le due schede. Con la funzionalità bloccata non si vede e non si
scrive niente, e i legami salvati restano dove sono.

I fornitori sono **del prodotto, come la giacenza** (P107): l'articolo senza
varianti li ha sul suo unico prodotto, quello con le varianti su ogni riga
della griglia. E si compilano come le sedi (P109): la finestra c'è solo quando
serve. `supplierMode($modelId)` conta i fornitori che la scheda propone
(`supplierChoices()`, gli attivi più quelli già legati a una qualunque opzione
dell'articolo, P92):

| Modo | Quando | Cosa dichiara `supplierInputs()` / `supplierColumns()` |
|---|---|---|
| `null` | `purchasing` bloccata | niente |
| `none` | nessun fornitore da proporre | niente |
| `flat` | un fornitore solo | `supplier_sku` («Codice fornitore», `maxLength(100)`) e `supplier_cost` («Costo d'acquisto», `price()` a due decimali); niente tendina, il nome sta nel `title` (`soleSupplierTitle()`) |
| `modal` | due o più | `suppliers`, nascosto, con le righe in JSON, e `suppliers_button`: `->button('Fornitori')->opensModal(SUPPLIERS_MODAL)->emptyCaption('Nessun fornitore')` |

**Dove stanno** (P108): nelle informazioni avanzate, con i codici.

| Dove | Campi | Larghezza |
|---|---|---|
| articolo senza varianti | `product_supplier_sku`, `product_supplier_cost`, oppure `product_suppliers` e `product_suppliers_button`, nell'accordion del riquadro «Prodotto»; hanno `hiddenWhen('has_variants', 'true')` | 6 e 6, oppure 12 |
| riga della griglia | le colonne `supplier_sku`, `supplier_cost`, oppure `suppliers` e `suppliers_button`, dietro `repeaterAdvanced()` | 6 e 6; il bottone da 6 accanto a «Giacenza», da 12 con una sede sola |
| scheda dell'opzione | `supplier_sku`, `supplier_cost`, oppure `suppliers` e `suppliers_button`, nel riquadro «Fornitori» | 4 e 4, oppure 12 |

**La finestra** è una per pagina: `suppliersModal()`, con id
`wi-product-suppliers`, in fondo alla colonna larga dopo quella della
giacenza, e il bottone di ogni riga apre sempre lei. Ha una riga per
fornitore — «Fornitore», «Codice fornitore», «Costo d'acquisto» e la × —,
tante quante i fornitori da proporre, nascoste finché non servono, fino a
`SUPPLIER_ROWS` (dieci) o a quante ne ha l'opzione che ne ha di più. In fondo
«Aggiungi fornitore», «Annulla», «Salva per tutte le opzioni» e «Salva». Le
caselle `wi_product_supplier[i][…]` stanno fuori dal repeater, e
`withoutExtras()` le butta via: quello che conta è il JSON.

`suppliersScript()` (`window.wiProductSuppliers`) fa il resto nel browser:

- all'apertura legge il JSON dell'opzione del bottone, intitola la finestra
  «Fornitori · Blu / S» (o con il nome dell'articolo) e riempie una riga per
  legame; senza legami mostra una riga vuota;
- nasconde nella tendina i fornitori non attivi che quell'opzione non ha già
  (`data-wi-supplier-inactive`, P92);
- «Salva» fa gli **stessi controlli del server** — fornitore mancante,
  doppione, codice troppo lungo, costo che non è un numero, sotto zero o
  troppo alto, con le frasi di `UserError::make()` passate in
  `data-wi-supplier-messages` — e con un errore lo dice nella finestra senza
  chiuderla; altrimenti riscrive il JSON (`[{supplier_id, supplier_sku,
  cost}]`, `'[]'` quando si tolgono tutti), lancia `change` e aggiorna il
  riassunto accanto al bottone;
- «Salva per tutte le opzioni» (P111) scrive le stesse righe nel campo
  `suppliers` di **ogni riga della griglia**, senza chiedere conferma: è
  ancora solo la pagina, e si salva con la scheda. Un fornitore non attivo non
  va sulle righe che non lo avevano già. Il bottone c'è solo quando la
  finestra si apre da una riga: nell'articolo senza varianti e nella scheda
  dell'opzione non ha senso.

**I valori del form** li prepara `supplierFormValues()`, da
`mutateFormValues()`, con una lettura sola per tutte le opzioni
(`supplierLinks()` → `ProductSuppliers::linksFor()`): in `flat` i due campi
dal legame con il fornitore unico, il costo a due decimali; in `modal` il JSON
e il riassunto di `ProductSuppliers::summary()`. Dopo un salvataggio rifiutato
la pagina torna con quello che era stato scritto, e
`withFormSupplierButtons()` rifà il riassunto dal JSON del form.

**Il controllo** è `assertSupplierRows($modelId, $post, $conVarianti)`,
chiamato da `mutateRequestValues()` — prima dell'insert, come gli altri
`assert…()`: per ogni opzione `postedSuppliers()` e poi
`ProductSuppliers::assertValid()` sulle righe grezze, con le scelte di
`supplierChoicesFor($modelId, $productId)`, cioè senza i non attivi che non
sono già suoi. Le chiavi sono le `product.supplier_…` di [Errori](errori.md).

**Il salvataggio** è `saveSuppliers()`, in `saveExtras()`, dopo le righe e
dopo `ProductSuppliers::dropRemovedOptions()` e prima della giacenza. Il
server legge quello che arriva, non il modo in cui crede di essere (P114): il
JSON se c'è, altrimenti i due campi, altrimenti niente, e allora i legami
restano quelli che sono. Chi scrive cosa, e perché i due campi toccano solo il
legame del fornitore unico, è in [Chi scrive i
legami](acquisti.md#chi-scrive-i-legami).

**Accendendo le varianti** (P115) su un articolo che ha un prodotto solo, i
fornitori scritti nel riquadro «Prodotto» vanno al suo prodotto — lo
scheletro della prima combinazione — e le opzioni che nascono nello stesso
salvataggio li copiano, come il prezzo e lo sconto, a meno che la loro riga
non dica altro. Le opzioni aggiunte dopo nascono senza fornitori, o con quelli
scritti nella loro riga.

**Chi toglie i legami**: `deleteRecord()` chiama `ProductSuppliers::dropFor()`
e `Thresholds::dropFor()` su tutte le opzioni, anche quelle nel cestino;
un'opzione tolta dalla griglia li perde nello stesso salvataggio
(`dropRemovedOptions()`). Tutti e due anche a funzionalità bloccata.

**La scheda dell'opzione** (`ProductResource`) ha il riquadro «Fornitori» dopo
«Magazzino», con il tooltip che dice a cosa serve. La regola è la stessa
(P112), ma il conto è **dell'opzione**: `supplierChoices($productId)` propone
gli attivi più i fornitori già legati a lei, e una sorella con un fornitore
non più attivo può avere la finestra dove questa ha i due campi. Con `none`
mostra la nota «Nessun fornitore da proporre: aggiungilo da Anagrafiche →
Fornitori.»; con `modal` aggiunge `suppliersModal($productId, false)`, senza
«Salva per tutte le opzioni», e lo stesso script. `mutateRequestValues()`
controlla con `postedSuppliers()` e `assertValid()` e toglie i campi che non
sono colonne, `afterUpdate()` scrive con `writeSuppliers()`, `deleteRecord()`
chiama `dropFor()`.

Nessun indice unico su opzione e fornitore (P93): `sync()` prima scrive e poi
toglie, e uno scambio di righe inciamperebbe a metà salvataggio. I doppioni li
fermano la finestra e il server, che possono spiegarlo.

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

## Personalizzazioni

Un campo che il cliente compila comprando: un **testo** (l'incisione), un
**numero** (la larghezza) o una **scelta** fra opzioni (la confezione). Si accende con la funzionalità
`customizations` (richiede `orders`) e si gestisce da *Catalogo → Personalizzazioni*.

| Tabella | Cos'è |
|---|---|
| `gst_customizations` | la definizione: nome, etichetta, aiuto, `kind` (`text`, `number` o `choice`), `max_length` (solo testo), `decimals` (solo numero, da 0 a 6), sovrapprezzo, `active` |
| `gst_customization_options` | le opzioni di una scelta, ciascuna col suo sovrapprezzo |
| `gst_product_model_customizations` | il collegamento all'**articolo** (non alla variante), con `is_required`, la posizione e un `surcharge` facoltativo che, se c'è (anche zero), sostituisce su quell'articolo il sovrapprezzo della personalizzazione (lo strato dati lo supporta, ma la scheda dell'articolo oggi **non lo espone**: resta `NULL`) |

Una **scelta** non ha un sovrapprezzo suo: la risorsa lo salva sempre a `0.00`
(il campo è nascosto con `hiddenWhen('kind', 'choice')`) e il prezzo lo fanno le
opzioni. Per le altre, quello della personalizzazione lo sostituisce, su un
articolo, il
`surcharge` del collegamento (`Customizations::effectiveSurcharge()`, che
`forModel()` applica: chi legge da lì vede già il valore giusto). Un **numero**
si legge come lo scrive una persona («1.250,5» o «12,5», senza segno né unità),
si controlla sui `decimals` e si salva già formattato con la virgola e i suoi
decimali: `valuesOf()` lo ridà a `check()` e il valore resta lo stesso. Una personalizzazione collegata a qualche articolo non si
elimina (`customization.in_use`): si disattiva, e sparisce dalla vendita.
Nell'elenco la colonna `usage` è l'`isEmpty` del core (funzione `empty` su
`gst_product_model_customizations.customization_id`): l'icona della cartella e,
se è usata, niente pulsante «Elimina»; `assertDeletable()` resta la guardia sul
server. Il modal «Nuova personalizzazione» e lo store API accettano `name` e
`surcharge` (vuoto = zero, virgola ammessa, negativo = `customization.surcharge`).
Scollegare o disattivare non tocca le righe già vendute, che portano con sé una
copia di quello che il cliente ha scritto.

### Il contratto per la vetrina

`Customizations::forModel($modelId)` è l'unica lettura che la vetrina (E1b) deve
usare: ridà, in ordine, solo le personalizzazioni **attive** collegate
all'articolo, ciascuna con `id`, `name`, `label`, `help_text`, `kind`,
`max_length`, `decimals`, `surcharge`, `required` e le `options` (`id`, `label`,
`surcharge`). I testi sono già in chiaro: chi li stampa li escapa.

Il form della vetrina manda al carrello i valori come
`customization => [id della personalizzazione => testo | id dell'opzione]`
(vedi [Vendite](vendite.md)). Non manda mai un prezzo: lo calcola il server.

### Validare: `resolve()` e `field()`

`Customizations::resolve($modelId, $valori)` controlla i valori contro la
definizione di oggi e ridà `['fields' => [...], 'surcharge' => '5.00']`. Gli
errori sono `UserError` del gruppo `customization` (`required`, `too_long`,
`not_number`, `too_many_decimals`, `whole_number`, `bad_option`, `unknown`); **`$e->field()` è l'id della personalizzazione**:
la vetrina lo usa per mettere la frase sotto il campo giusto. Testo e opzioni
si ripuliscono: i caratteri di controllo (tranne l'a capo) e gli spazi ai
lati escono, e un testo vuoto su un campo facoltativo non diventa un valore.

### Come si salva: latin1 e `decode()`

La colonna `customization` delle righe d'ordine è latin1: un emoji o un `☕`
non ci stanno. `Customizations::encode()` scrive JSON **solo ASCII**, con le
entità numeriche (`&#9749;`) dopo aver trasformato `&` in `&amp;`; così quello
che torna è identico a quello che è entrato. Per questo la colonna **non si
legge mai a mano**: sempre `Customizations::decode()`, che ridà la lista
`customization_id`, `label`, `value`, `option_id`, `surcharge` già in
chiaro. `lines($riga)` ne fa le righe «Etichetta: valore» per le schede e le
email; `signature()` è l'impronta con cui il carrello capisce se due righe
sono la stessa.

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

Ci sono anche due personalizzazioni, entrambe facoltative: l'*Incisione* (testo,
20 caratteri, +5 €) sulla maglietta e la *Confezione regalo* (Rossa o Blu, +3 €)
sulla felpa. Con la funzionalità accesa, metà degli ordini di prova
porta l'incisione «Auguri».

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
nominato da un movimento di magazzino o dai fornitori di un'opzione.
`CatalogDemo::SUPPLIERS` dice da chi si comprano le opzioni di ogni articolo
di prova (Filati Nord per tutti, i calzini anche da Imballaggi Sud) e
`LAST_OPTION_SUPPLIERS` cosa cambia per l'ultima opzione dei calzini, che ha
un codice e un costo suoi; `suppliers()` li scrive con
`ProductSuppliers::sync()`, opzione per opzione, solo dove non c'è ancora
nessuna riga. La stessa opzione nasce sotto la sua scorta minima, scritta
sulla sede principale (`Thresholds::save()`). I fornitori delle opzioni di
prova se ne vanno con gli articoli (`ProductModelResource::deleteRecord()`), e
il conto di quello che la pulizia ha tolto li comprende, anche quelli delle
opzioni nel cestino: `CatalogDemo::supplierLinksOf()` conta le righe di
`gst_product_suppliers` delle opzioni dell'articolo, senza guardare `deleted`.
