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
`gst_attribute_values`, ma il riquadro compare solo quando il tipo li usa: in
creazione il tipo non è ancora scelto, e un attributo "Testo" non ha niente da
elencare. Il layout legge la riga aperta con `currentId()` di
`GestionaleResource`.

Il tipo non si può cambiare mentre ci sono dei valori: `mutateRequestValues()`
si ferma, perché il salvataggio li cancellerebbe in silenzio.

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
tag e due attributi con i loro valori — "Colore" sulla variante e "Taglia" sul
prodotto — per un totale di 15 righe, tutte con il nome che inizia per `Prova `.
`--fresh` toglie quelle di prima e le rifà. Le classi stanno in `src/Seeding/`,
registrate in `Seeding\Demo`.
