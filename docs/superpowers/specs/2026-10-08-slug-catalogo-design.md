# Slug del catalogo — design

Data: 2026-10-08 · Branch per il lavoro: `slug-catalogo` (gestionale, ecommerce)

## 1. Obiettivo

Gli indirizzi delle schede del negozio diventano leggibili e annidati:

```
/prodotto/maglietta-girocollo/
/prodotto/maglietta-girocollo/blu/
/prodotto/maglietta-girocollo/blu/?taglia=s&materiale=cotone
```

Oggi la scheda vive a `/prodotto/{slug-della-variante}/`. Lo slug della
variante è `nome-idModello-idValore` (`Generator.php:79`), oppure
`nomeModello-idModello` per la variante scheletro (`Skeleton.php:41`). Ne
escono indirizzi come `/prodotto/maglietta-girocollo-51167/`.

Criteri di riuscita:

- il modello ha il suo indirizzo, e la variante ha il suo sotto quello del modello;
- lo slug della variante è il suo valore («blu») ed è unico solo dentro il modello;
- la query decide quale opzione è già scelta quando si apre la scheda;
- nell'ecommerce c'è un solo costruttore di URL, al posto dei due `productUrl`
  privati di `ProductCatalog` e `ProductListing`;
- un comando rifà gli slug delle varianti già salvate.

## 2. Decisioni

| Tema | Decisione |
|------|-----------|
| Slug del modello | Non cambia: unico nella tabella, nasce dal nome alla creazione e poi resta quello. |
| Slug della variante | Nasce dal solo valore della variante (`Blu` → `blu`) ed è unico tra le varianti dello stesso modello (`blu`, `blu-2`). Si scrive alla creazione e poi non cambia più, come oggi: se nell'anagrafica «Blu» diventa «Blu notte», cambia il nome ma non l'indirizzo. |
| Variante scheletro | Slug vuoto: non ha un valore e non ha una pagina sua. |
| Opzione (`products`) | Nessuno slug in tabella. L'opzione si sceglie con la query: la chiave è lo slug dell'attributo, il valore è lo slug dell'etichetta del valore (`?taglia=s&materiale=cotone`). |
| Slug dei valori d'attributo | Nessuna colonna nuova: si calcola dall'etichetta al momento, con la stessa funzione degli altri slug. Si accetta anche l'id del valore, come già fa `CatalogFilter`. |
| Modello con una sola variante visibile | Ha un solo indirizzo, quello del modello: `/prodotto/modello/blu/` rimanda con un 301 a `/prodotto/modello/`. |
| Modello con più varianti | `/prodotto/modello/` mostra la prima variante visibile e dichiara come canonical l'indirizzo di quella variante. Ogni variante è canonical di sé stessa. |
| Query e canonical | La query non entra mai nel canonical: la pagina con `?taglia=…` ha lo stesso canonical di quella senza. |
| Variante sconosciuta o nascosta | 301 all'indirizzo del modello. Un modello sconosciuto o non in vetrina dà 404, come oggi. |
| Query sconosciuta o impossibile | Si ignora: chiavi che non sono attributi della scheda, valori che non esistono, combinazioni senza un'opzione. Niente 404 e niente rimandi. |
| Query parziale | Conta quello che c'è: `?taglia=s` sceglie la prima opzione disponibile con la taglia S. |
| URL nella barra | Quando il cliente cambia opzione nella scheda, la query si aggiorna con `history.replaceState`, senza aggiungere voci alla cronologia. |
| Vecchi indirizzi | Nessun rimando da `/prodotto/{slug-vecchio}/`: il negozio non è ancora in produzione (manca la `1.0.0`). |
| Nomi delle route | `ecommerce.catalog.product` resta `/prodotto/{slug}/`, così il «Vedi sul sito» del backend continua a funzionare. Si aggiunge `ecommerce.catalog.product.variant` su `/prodotto/{slug}/{variante}/`. |

## 3. Architettura

### 3.1 Gestionale

- **`Slug::uniqueWithin(string $name, string $modelClass, array $scope)`**:
  fa come `unique()`, ma cerca i doppioni solo tra le righe che rispettano
  `$scope` (qui `['product_model_id' => $modelId]`). Come `unique()`, conta
  anche le righe cancellate.
- **`Generator`**: una variante nuova prende come slug
  `Slug::uniqueWithin($variant['label'], ProductVariant::class, ['product_model_id' => $modelId])`.
  Quando riusa la variante scheletro (oggi ne aggiorna solo il nome), le scrive
  anche lo slug, perché lo scheletro non ne aveva uno vero.
- **`Skeleton`**: la variante scheletro nasce con lo slug vuoto.
- **`Support/Catalog/VariantSlugs.php`** contiene la regola:
  - `plan(int $modelId)` restituisce `[variantId => slugNuovo]`: rifà gli slug
    dai valori delle varianti, seguendo `position`, e lascia vuoto quello
    delle varianti senza valore;
  - `apply()` scrive gli slug.
  Così la regola si prova senza console.
- **Comando `gestionale:variant-slugs`** (`src/Console/VariantSlugsCommand.php`,
  registrato in `module.json`): applica `VariantSlugs` a tutti i modelli.
  - `--dry-run` elenca i cambi senza scriverli.
  - È idempotente: lanciato una seconda volta non cambia nulla.
  - È l'unico punto che riscrive uno slug già salvato.

### 3.2 Ecommerce

- **`Frontend/Catalog/ProductUrl.php`** è il costruttore unico.
  - `ProductUrl::make(string $modelSlug, string $variantSlug = '', array $query = []): string`
    restituisce il percorso relativo: quello del modello se `$variantSlug` è
    vuoto, e la query solo se non è vuota.
  - Usa le route con nome (`__r`); se mancano, ricade sui percorsi fissi, come
    fanno oggi i due `productUrl`.
  - `ProductUrl::variantSlugFor(array $variant, int $visibleVariants): string`
    dice quale slug usare: vuoto se c'è una sola variante visibile o se lo slug
    della variante è vuoto.
- **`ProductCatalog::resolve(string $modelSlug, string $variantSlug = '', array $query = [])`**
  restituisce `['detail' => ?ProductDetail, 'redirect' => ?string]`.
  `find()` resta, per chi vuole solo la scheda.
  1. Cerca il modello per slug, visibile e in vetrina.
  2. Se c'è `$variantSlug`, cerca la variante dentro il modello. Rimanda al
     modello se la variante manca, se è nascosta o se il modello ha una sola
     variante visibile.
  3. Senza `$variantSlug` prende la prima variante visibile per `position`,
     come oggi.
  4. Costruisce con `ProductUrl` l'`url` del dettaglio, che è anche il canonical.
  5. Passa la query a `ProductDetail`, che sceglie l'opzione (§3.3).
- **`ProductController::show(string $slug, string $variante = '')`** legge la
  query da `$_GET`. Se c'è un rimando risponde 301 e si ferma; se non c'è la
  scheda risponde 404, come oggi.
- **`ProductListing`**, sempre con `ProductUrl`:
  - la card del modello punta al modello;
  - la card della variante, quando l'elenco mostra le varianti separate,
    punta alla variante;
  - anche i pallini delle varianti puntano alla variante.
- **`config/routes/route.frontend.php`**: si aggiunge la route
  `/prodotto/{slug}/{variante}/`, con nome `ecommerce.catalog.product.variant`
  e lo stesso handler.

### 3.3 Scelta dell'opzione dalla query

- `optionGroups()` aggiunge a ogni valore il suo `slug`, calcolato
  dall'etichetta. I gruppi hanno già lo slug dell'attributo.
- `OptionQuery::match(array $groups, array $offers, array $query): ?int` è una
  funzione pura.
  1. Traduce la query in `[attributeId => valueId]` e scarta ciò che non
     combacia.
  2. Restituisce il `product_id` della prima offerta disponibile che ha tutti
     quei valori; se nessuna è disponibile, la prima che li ha.
  3. Restituisce `null` se la query è vuota o non combacia con nessuna offerta.
- `ProductDetail` usa quel `product_id` come `selected_product_id`. Se non c'è,
  vale la regola di oggi: la prima offerta disponibile.
- Nella vista `pages/frontend/product.php`, a ogni cambio di opzione lo script
  riscrive la query con gli slug dei gruppi e dei valori scelti
  (`history.replaceState`). Il percorso non cambia.

## 4. Errori e casi limite

- Due valori con la stessa etichetta in due attributi di variante diversi non
  si scontrano: lo slug è unico nel modello, quindi il secondo diventa `-2`.
- Se lo slug di un valore esce vuoto (solo simboli), la variante resta
  raggiungibile: `uniqueWithin` usa `variante`, poi `variante-2`.
- Se una chiave della query è ripetuta o è un array (`?taglia[]=s`), conta il
  primo valore.
- La query non entra mai in SQL: si confronta con gli slug che la scheda ha
  già letto.

## 5. Prove

- Gestionale, unitarie:
  - `Slug::uniqueWithin`: doppioni nello stesso modello, nessuno scontro tra
    modelli diversi, righe cancellate contate;
  - `VariantSlugs::plan`: ordine per posizione, scheletro vuoto, idempotenza.
- Ecommerce, unitarie:
  - `ProductUrl::make` e `variantSlugFor`;
  - `OptionQuery::match`: query completa, parziale, chiavi sconosciute, valori
    sconosciuti, combinazione impossibile, id al posto dello slug, preferenza
    per l'offerta disponibile.
- Ecommerce, integrazione sul sito di prova:
  - modello con più varianti: 200 sul modello e sulla variante, canonical giusti;
  - modello con una sola variante: 301 dalla variante;
  - variante sconosciuta: 301;
  - modello sconosciuto: 404;
  - query che sceglie l'opzione.
- Prova nel browser, dopo aver lanciato `gestionale:variant-slugs`:
  - i link dell'elenco e i pallini delle varianti;
  - il cambio d'opzione che aggiorna la barra;
  - il «Vedi sul sito» del backend.

## 6. Fuori da qui

- Rimandi dai vecchi indirizzi: non servono prima della `1.0.0`.
- Slug modificabili a mano dal backend.
- Feed per Google Merchant: userà `ProductUrl::make` con la query
  dell'opzione, ma è un lavoro a parte.
