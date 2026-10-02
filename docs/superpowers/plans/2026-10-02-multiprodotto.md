# Multiprodotto — piano di realizzazione (G5, piano 2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** un articolo *Multiprodotto* composto da prodotti fissi, da gruppi a scelta del cliente o da entrambi, che nel carrello e nell'ordine è **una riga madre col prezzo** più **righe figlie senza prezzo** (una per componente), e che prenota, scarica, rimette in magazzino e si rende passando dai componenti.

**Architecture:** tre tabelle (componenti, gruppi, opzioni dei gruppi) e tre colonne nuove; una classe `Support\Catalog\Bundles` con la parte pura (controllo della composizione, scelte, pezzi richiesti, valore) separata dalla lettura dal database (`forModel`, `available`, `resolve`); una classe `Support\Orders\OrderLines` che dice in un punto solo quali righe muovono merce e quali sono «vendute». `Cart` riscrive le figlie a ogni `add`/`recalculate` partendo dalle scelte salvate, così nulla deriva dal client. `Allocation` non cambia: vede solo le figlie. La scheda dell'articolo segue il pattern già provato del riquadro Personalizzazioni e della finestra Fornitori (JSON nella riga).

**Tech Stack:** PHP 8.2+, harness `tests/harness.php` (`php tests/run.php`), sito di prova `boilerplates/ecommerce-site` (tabelle con `php forge update --local`), helper `tests/integrazione/supporto/compra.php`.

**Spec:** `docs/superpowers/specs/2026-10-01-multiprodotto-e-personalizzazione-design.md` (§1 *Multiprodotto* e *Righe d'ordine*, §2 *Scheda dell'articolo* e *Controlli al salvataggio*, §3 `Bundles`, `Cart`, `OrderLines`, §4 *Magazzino*, *Resi*, *Dati di prova*, *Test*, *Piani* punto 2). Le Personalizzazioni sono del piano 1 (già in `main`).

## Global Constraints

- Lingua: commenti, testi utente e nomi dei test in **italiano** con gli accenti; classi, tabelle, colonne e chiavi in inglese.
- Funzionalità `bundles` (già in `config/features.php`): spenta, il tipo in creazione, i riquadri e le pagine sparisce; **i dati restano** (D20). Un multiprodotto già creato resta com'è nei carrelli e negli ordini.
- Vendita scoperta (D60): un componente vendibile senza giacenza non limita né `available()` né il carrello (`Stock::allowsBackorder`).
- Una confezione = una riga **madre** (`type` `product`, `product_id` = il suo prodotto, `parent_item_id` 0, prezzo vero) più **figlie** (`type` `product`, `product_id` = componente, `parent_item_id` = id della madre, `bundle_option_id` = opzione scelta o 0 per un fisso, prezzo e totale `0.00`, stessa `tax_category_id` della madre, nome, SKU e unità del componente).
- Quantità di una figlia = pezzi per confezione × quantità della madre, con 3 decimali. Si riscrive **sempre** dal server a ogni `add`, `setQuantity`, `merge`, `recalculate`.
- `customization_surcharge` della madre = sovrapprezzo delle personalizzazioni **più** quello delle opzioni scelte. Quello in ingresso non si legge mai.
- Un multiprodotto non ha varianti, giacenza, soglie, fornitori né lotti: ha **un solo prodotto** (lo scheletro di `Skeleton::forModel`), che non ha mai movimenti, prenotazioni né livelli.
- Un multiprodotto non può contenere multiprodotti (né sé stesso): la tendina dei componenti e delle opzioni li esclude e il server li rifiuta.
- Rifiuti prevedibili: `UserError::make('bundle.<chiave>', [...])` / `UserError::refusal(...)`, frase in `lang/it/gestionale.json` sotto `gestionale.errors.bundle` (il JSON si riscrive con python `json.dumps(ensure_ascii=False, indent=4) + "\n"`); `ErrorKeysTest` cade se manca una chiave.
- Form compatti: tooltip al posto dei testi d'aiuto, campi correlati sulla stessa riga, niente campi automatici.
- Tutto ciò che viene dal database o dal cliente e finisce in HTML passa da `escape()` (Resource) o `htmlspecialchars` (`$e` delle email).
- Test d'integrazione dentro `prova()` (transazione annullata), `Gestionale::reset()` dopo aver toccato le funzionalità; ogni task chiude con `php tests/run.php` verde per intero (il rosso già noto di `CatalogDemoTest` «il sito resta con foto vere sul disco» si riporta, non si nasconde).
- Branch `piano-2-multiprodotto`; commit con trailer `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`; PR con merge commit su `main` e footer `🤖 Generated with [Claude Code](https://claude.com/claude-code)`, poi pulizia di branch remoto, locale e worktree. **Non toccare né committare** `src/Support/Contacts/CustomerSheet.php` (modifica dell'utente non committata).

## Decisioni di perimetro

- **Una sola definizione di «riga che muove merce».** `OrderLines::goods()` è una riga `product` con `product_id` > 0, quantità > 0 e **che non è madre di nessuna riga** nella stessa lista; `OrderLines::sold()` sono le righe con `parent_item_id` = 0. Accettano sia la lista piatta di un ordine sia quella di `Cart::contents()` (madri con `children` annidate): `OrderLines::flat()` le appiattisce prima.
- **`Cart::contents()['items']`** resta a primo livello: le madri portano `children` (lista piatta). Tutto ciò che oggi la legge (Checkout, pagina del carrello) vede una confezione come una riga sola; `Checkout` prenota passando da `OrderLines::goods()`.
- **Scelte conservate.** La madre non ha un campo scelte: sono le sue figlie con `bundle_option_id` > 0. `recalculate()` rilegge `Bundles::resolve($productId, $optionIds)` partendo da quelle e riscrive le figlie (cancella e ricrea, mantenendo le posizioni subito dopo la madre). Se `resolve()` rifiuta (opzione tolta, gruppo con `min` cambiato, componente spento o cancellato, multiprodotto spento) la confezione intera esce con il nome della madre in `removed`.
- **Funzionalità `bundles` spenta.** `Cart::add()` rifiuta un multiprodotto (`bundle.feature_off`); `recalculate()` continua a mantenere quelli già nel carrello (`Bundles::resolve()` non guarda l'interruttore). Con `customizations` spenta il sovrapprezzo delle personalizzazioni di una madre resta la somma di quanto scritto nelle sue righe (`surcharge` di ogni campo decodificato), non si rilegge dall'anagrafica.
- **Disponibilità.** Una confezione richiede, per ogni prodotto, la somma dei pezzi (un prodotto può essere componente fisso e opzione): contro `Levels::of()['available']`, salvo D60. `Bundles::available()` e il carrello usano la stessa funzione pura `Bundles::shortfall()`.
- **Reso.** Il modulo mostra la confezione come una riga (confezioni e motivo) e sotto i componenti con «Rientra in magazzino» uno per uno. `Returns::register()` scrive una riga di reso per la madre (senza `Allocation`) e una per figlia con quantità proporzionale e il proprio `restock`; non si rende una figlia da sola. Il rimborso è manuale, come oggi.
- **Eliminare e spegnere.** Un prodotto che è componente o opzione di un multiprodotto non si elimina e non si spegne (`bundle.in_use` con i nomi dei multiprodotti); accanto a `product.has_movements`. Spegnere il **multiprodotto** è lecito: esce dai carrelli come ogni articolo spento.
- **Tipo.** `type` si sceglie solo in creazione e solo con `bundles` acceso; poi è in sola lettura e il server ignora ogni modifica. Con `bundles` spenta un articolo `bundle` esistente continua a mostrare i suoi riquadri (sola lettura non richiesta: i dati restano) ma non si può creare un nuovo.
- **Valore dei componenti.** `show_components_value` e `Bundles::componentsValue()` sono dati per E1b: la scheda dell'articolo li scrive/mostra, nient'altro li usa in G5.

## Review Focus

- **Lo stesso prodotto due volte.** Fisso e opzione insieme, due opzioni di gruppi diversi, lo stesso prodotto in due gruppi: disponibilità sommata per prodotto, figlie distinte per `bundle_option_id`, una confezione con scelte identiche in qualunque ordine è la stessa riga. *(Task 3 e Task 4)*
- **L'anagrafica cambia sotto il carrello.** Componente spento o cancellato, opzione tolta, gruppo con `min` alzato, multiprodotto spento, composizione svuotata: la confezione esce intera con il nome in `removed`, mai una madre orfana o figlie senza madre. *(Task 4)*
- **Quantità e decimali.** 3 confezioni di un fisso da 0,5 pezzi, `setQuantity` a 2 e a 0, `remove` della madre, `merge` di due carrelli con la stessa confezione e con scelte diverse: le figlie seguono sempre, nessuna riga a zero rimasta. *(Task 4)*
- **La madre non tocca mai il magazzino.** Prenotazione, conferma, annullamento (anche dopo un reso parziale) e scadenza guardano solo le figlie; i pezzi nel carrello e nel riepilogo non si contano due volte. *(Task 2, Task 4 e Task 5)*
- **Reso di una confezione.** Una confezione resa due volte (la seconda oltre il residuo), il motivo «difettoso» che non rimette a scaffale, un componente che rientra e uno no, la figlia chiesta da sola: tutto rifiutato o registrato giusto, e l'annullamento dell'ordine toglie solo quanto non è già stato reso. *(Task 5)*
- **Form del backend.** Cambiare composizione scarta i dati dei riquadri nascosti; JSON delle opzioni rotto o vuoto, stesso prodotto due volte, un multiprodotto come componente, `min` > `max`, tipo cambiato a mano nella richiesta: rifiutati con una frase, senza lasciare l'articolo scritto a metà. *(Task 6)*

---

### Task 1: Tabelle e colonne

**Files:**
- Create: `src/Models/Catalog/BundleComponent.php`, `src/Models/Catalog/BundleGroup.php`, `src/Models/Catalog/BundleGroupOption.php`, `tests/BundleModelsTest.php`
- Modify: `src/Models/Catalog/ProductModel.php` (colonne `bundle_mode`, `show_components_value`; commento riga 19 sul `type`), `src/Models/Sales/OrderItem.php` (colonna `bundle_option_id`), `tests/ProductModelsTest.php`, `tests/OrderModelsTest.php`

**Interfaces:**
- Produces: `BundleComponent::$table = 'gst_bundle_components'` (`product_model_id`, `product_id`, `quantity` `Columns::decimal('quantity', '12,3')`, `position`), `BundleGroup::$table = 'gst_bundle_groups'` (`product_model_id`, `name`, `min_choices` int, `max_choices` int, `position`), `BundleGroupOption::$table = 'gst_bundle_group_options'` (`bundle_group_id`, `product_id`, `surcharge` `Columns::decimal('surcharge', '12,2')`, `position`), tutti con `$folder = 'gestionale/models'`; `ProductModel.bundle_mode` enum `fixed|choice|mixed` default vuoto (`->null()` come gli altri facoltativi, un articolo semplice non lo ha), `ProductModel.show_components_value` enum `true|false` default `false`; `OrderItem.bundle_option_id` int default 0.

Modelli ricalcati su `ProductModelCustomization` (stesse firme di `tableSchema()`, `tablePseudos()`, `dataSchema()`, `syncSchema()` che torna `null`). Chiavi esterne: `product_model_id` → `ProductModel::$table`, `product_id` → `Product::$table`, `bundle_group_id` → `BundleGroup::$table`; indici `ind_model`, `ind_product`, `ind_group`. `dataSchema`: i numeri con `->number()->decimals(n)` (0 per gli id, 3 per `quantity`, 2 per `surcharge`), `name` `->sanitizeFirst()`.

- [ ] **Step 1: scrivere i test** in `BundleModelsTest.php` (stile di `CustomizationModelsTest.php`): per ciascuno dei tre modelli `tableSchema()` ha le colonne attese con tipo e chiave esterna, `syncSchema()` è `null`, `dataSchema()` ha `quantity` a 3 decimali e `surcharge` a 2. In `ProductModelsTest` `bundle_mode` e `show_components_value` sono in `tableSchema()` e `dataSchema()`; in `OrderModelsTest` `bundle_option_id` è un int con default 0.
- [ ] **Step 2: eseguire** `php tests/BundleModelsTest.php`, `php tests/ProductModelsTest.php`, `php tests/OrderModelsTest.php` — atteso: rossi i nuovi.
- [ ] **Step 3: implementare** i tre modelli e le tre colonne.
- [ ] **Step 4: eseguire** gli stessi test, poi `cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php forge update --local` (atteso: le tre tabelle create, le tre colonne aggiunte, nessun errore) e `php tests/run.php` dal pacchetto — atteso: verde.
- [ ] **Step 5: commit** «Multiprodotto: tabelle di componenti, gruppi e opzioni, colonne su articolo e riga d'ordine».

### Task 2: `OrderLines` come punto solo, usato da Checkout, Lifecycle e statistiche

**Files:**
- Create: `src/Support/Orders/OrderLines.php`, `tests/OrderLinesTest.php`
- Modify: `src/Support/Orders/Checkout.php` (`goods()` privato, righe ~379–388, usato a ~64 e ~72), `src/Support/Orders/Lifecycle.php` (conferma ~89, annullamento ~178, `items()` ~392), `src/Support/Contacts/CustomerStats.php` (~71–75), `src/Support/Orders/OrderActions.php` (`summary`, ~114), `tests/integrazione/CheckoutTest.php`, `tests/integrazione/LifecycleTest.php`, `tests/CustomerStatsTest.php`, `tests/OrderActionsTest.php`

**Interfaces:**
- Produces: `OrderLines::flat(array $items): list<array>` (le righe di primo livello, ciascuna seguita dalle sue `children` se ci sono, e senza la chiave `children`); `OrderLines::goods(array $items): list<array>`; `OrderLines::sold(array $items): list<array>`; `OrderLines::children(array $items, int $parentId): list<array>`. Tutte accettano sia la lista piatta (con `parent_item_id`) sia quella annidata.

Regole: `goods` = `type` `product`, `product_id` > 0, quantità > 0, e `id` che **non** compare come `parent_item_id` di altre righe della lista piatta. `sold` = righe con `(int) ($row['parent_item_id'] ?? 0) === 0`. Le righe `text` e le spese non sono mai `goods`. Questo task non cambia nessun comportamento: senza figlie `goods` fa quello che fanno oggi i filtri.

- [ ] **Step 1: scrivere i test** in `OrderLinesTest.php` (puri, senza database): lista piatta con una madre (id 10), due figlie (`parent_item_id` 10), una riga semplice e una `text` → `goods` dà le due figlie e la semplice, `sold` la madre, la semplice e la `text`, `children($items, 10)` le due figlie, `children($items, 99)` vuota; la stessa lista **annidata** (`children` dentro la madre) dà gli stessi id; una riga a quantità zero e una con `product_id` 0 non sono `goods`; una madre senza figlie nella lista (caso corrotto) resta `goods`. In `CheckoutTest`: ordine con una madre e due figlie costruite a mano nel carrello prenota **solo le figlie** (`Allocation` non vede mai l'id della madre). In `LifecycleTest`: conferma scarica le figlie e non la madre; annullamento dopo un reso parziale di una figlia rimette `quantità − reso` di quella figlia e il resto per le altre. In `CustomerStatsTest` e `OrderActionsTest`: i pezzi di un carrello/ordine con una confezione da 2 contano 2, non 2 + figlie.
- [ ] **Step 2: eseguire** i quattro test — atteso: rossi i nuovi.
- [ ] **Step 3: implementare** `OrderLines`, togliere il `goods()` privato di `Checkout`, usare `OrderLines::goods(self::items($orderId))` in `Lifecycle` (conferma e annullamento), `sold()` in `CustomerStats` e `OrderActions::summary` (che oggi conta i `type = product`).
- [ ] **Step 4: eseguire** i quattro test, `CartTest`, `OrdersDemoTest` e `php tests/run.php` — atteso: verdi.
- [ ] **Step 5: commit** «OrderLines: le righe che muovono merce e quelle vendute in un punto solo».

### Task 3: `Bundles` — composizione, scelte, disponibilità, valore

**Files:**
- Create: `src/Support/Catalog/Bundles.php`, `tests/BundlesTest.php`, `tests/integrazione/BundlesTest.php`
- Modify: `lang/it/gestionale.json` (gruppo `gestionale.errors.bundle`)

**Interfaces:**
- Consumes: modelli del Task 1; `Levels::of()`, `Stock::allowsBackorder()`.
- Produces (pure, senza database):
  - `Bundles::assertComposition(string $mode, array $components, array $groups): void` — `components` = `list<{product_id, quantity}>`, `groups` = `list<{name, min, max, options: list<{product_id, surcharge}>}>`; `UserError::make('bundle.<chiave>')` per: modalità sconosciuta (`bundle.unknown_mode`), `fixed` senza componenti, `choice` senza gruppi, `mixed` senza uno dei due (`bundle.no_components`, `bundle.no_groups`), quantità ≤ 0 (`bundle.zero_quantity`), componente ripetuto o prodotto ripetuto in un gruppo (`bundle.duplicate_product`), `min` < 0 o `max` < 1 o `min` > `max` o `max` > numero di opzioni (`bundle.group_range` con `name`), gruppo senza nome (`bundle.group_name`), sovrapprezzo negativo (`bundle.negative_surcharge`). I dati dei riquadri **nascosti** dalla modalità (componenti con `choice`, gruppi con `fixed`) si ignorano nel controllo: li scarta chi salva (Task 6).
  - `Bundles::choose(array $groups, array $optionIds): array` — `groups` come sopra ma con `id` e `options[].id`; controlla che ogni id sia un'opzione di uno dei gruppi (`bundle.unknown_option`), che ogni gruppo abbia fra `min` e `max` scelte (`bundle.too_few` / `bundle.too_many`, con `name`), e restituisce `list<{option_id, product_id, surcharge}>` nell'ordine dei gruppi. Id doppi (stringa e intero) contano una volta.
  - `Bundles::pieces(array $components, array $chosen, float $quantity): array<int, float>` — pezzi per prodotto di `quantity` confezioni (fissi più scelte, sommati per prodotto, 3 decimali).
  - `Bundles::shortfall(array $pieces, array $levels, array $backorder): ?array` — `levels` `product_id => disponibile`, `backorder` `product_id => bool`; torna `null` se tutto basta, altrimenti `['product_id' => int, 'available' => float]` del primo prodotto che non basta.
  - `Bundles::capacity(array $components, array $groups, array $levels, array $backorder): float` — le confezioni vendibili (formula della spec: minimo fra i fissi, disponibile / quantità per difetto, e per ogni gruppo con `min` > 0 la disponibilità della `min`-esima opzione più fornita; D60 non limita; quando nessun componente limita (tutti in vendita scoperta, o solo gruppi con `min` 0) torna il tetto `Bundles::UNLIMITED = 999999.0`; senza composizione torna `0.0`).
  - `Bundles::value(array $components, array $groups, array $prices): string` — somma dei prezzi dei fissi per quantità più, per ogni gruppo, le `min` opzioni più economiche (`prices` `product_id => prezzo corrente`, scontato se c'è), con due decimali.
- Produces (con database): `Bundles::forModel(int $modelId): array` → `['mode' => string, 'show_value' => bool, 'components' => list<{id, product_id, name, quantity}>, 'groups' => list<{id, name, min, max, options: list<{id, product_id, name, surcharge}>}>]` (solo righe `deleted = 'false'`, in ordine, nome = `Product::full` come negli altri punti); `Bundles::isBundle(int $productId): bool`; `Bundles::resolve(int $productId, array $optionIds): array` → `['modelId' => int, 'children' => list<{product_id, quantity, bundle_option_id}>, 'surcharge' => string]` (rifiuta un prodotto che non è un multiprodotto `bundle.not_bundle`, un multiprodotto senza composizione valida, un componente o un'opzione il cui prodotto è spento o cancellato `bundle.component_unavailable` con `name`, poi `choose()`); `Bundles::available(int $modelId): float`; `Bundles::componentsValue(int $modelId): string`; `Bundles::usedBy(int $productId): list<string>` — nomi dei multiprodotti (non cancellati) in cui il prodotto è componente o opzione, senza doppioni.

- [ ] **Step 1: scrivere i test puri** in `BundlesTest.php`: per ogni chiave di `assertComposition` il caso che la fa scattare e il caso valido vicino; `choose` con id doppi, opzione di un altro gruppo, `min` 0 con nessuna scelta, `min` 2 con una; `pieces` con lo stesso prodotto fisso e opzione (somma), quantità 3 con fisso 0,5 → 1,5; `shortfall` (un prodotto basta e uno no; D60 non limita); `capacity` (fisso esaurito → 0; fisso da 2 con 5 disponibili → 2; gruppo con `min` 2 e una sola opzione disponibile → 0; `min` 0 non limita; componente D60 non limita; stesso prodotto fisso e opzione); `value` (fissi, `min` 2 che prende le due più economiche, nessun gruppo).
- [ ] **Step 2: scrivere i test di integrazione** in `tests/integrazione/BundlesTest.php` con helper nuovi in `compra.php` (`multiprodottoDiProva(string $mode, array $componenti, array $gruppi): int` che crea un modello `bundle` con lo scheletro e le righe delle tre tabelle e torna l'id del suo **prodotto**; `modelloDi()` esiste già): `forModel` nell'ordine giusto con i nomi; `resolve` dà le figlie (fisso con `bundle_option_id` 0 più le opzioni) e il sovrapprezzo `2.50 + 1.00 = 3.50`; un prodotto semplice → `bundle.not_bundle`; un componente spento → `bundle.component_unavailable`; `available` con un fisso da 2 su 5 → 2, con un componente D60 (vendita scoperta accesa) non limita; `componentsValue`; `usedBy` nomina il multiprodotto e non dà doppioni per un prodotto che è fisso e opzione.
- [ ] **Step 3: eseguire** `php tests/BundlesTest.php` e `php tests/integrazione/BundlesTest.php` — atteso: rossi (classe mancante).
- [ ] **Step 4: implementare** `Bundles` e le frasi (`bundle.unknown_mode`, `no_components`, `no_groups`, `zero_quantity`, `duplicate_product`, `group_range`, `group_name`, `negative_surcharge`, `unknown_option`, `too_few`, `too_many`, `not_bundle`, `component_unavailable`, `feature_off`, `in_use`).
- [ ] **Step 5: eseguire** i due test, `php tests/ErrorKeysTest.php` e `php tests/run.php` — atteso: verdi.
- [ ] **Step 6: commit** «Bundles: composizione, scelte, disponibilità e valore dei multiprodotti».

### Task 4: Le righe figlie in `Cart`

**Files:**
- Modify: `src/Support/Orders/Cart.php` (`add` 107, `recalculate` 160, `setQuantity` 287, `remove` 315, `merge` 335, `contents` 390, `itemLike` 514), `tests/integrazione/CartTest.php`, `tests/integrazione/CheckoutTest.php`, `tests/integrazione/LifecycleTest.php`

**Interfaces:**
- Consumes: `Bundles::resolve/shortfall/pieces/isBundle` (Task 3), `OrderLines` (Task 2), `OrderItem.bundle_option_id`/`parent_item_id`, `Customizations::resolve/signature`.
- Produces: `Cart::add($cartId, ['product_id' => int, 'quantity' => float, 'customization' => [...], 'choices' => list<int>])`; `Cart::contents($cartId)['items'][]` con la chiave `children` (lista piatta delle figlie, vuota per una riga semplice); `setQuantity`/`remove` su una figlia → `UserError::make('cart.child_line')` (frase da aggiungere sotto `gestionale.errors.cart`).

Cambi:

- **`add()`**: se `Bundles::isBundle($productId)`: con `bundles` spenta → `bundle.feature_off`; `$bundle = Bundles::resolve($productId, $line['choices'] ?? [])`; il sovrapprezzo della madre = `bcadd` di quello delle personalizzazioni e di `$bundle['surcharge']`; disponibilità: `Bundles::pieces(...)` per `$wanted` confezioni contro `Levels::of()` e `Stock::allowsBackorder` di ogni prodotto, con `shortfall()` → `cart.not_enough_stock` con il nome del componente; **non** `assertAvailable($product, …)` sulla madre (non ha giacenza). La riga uguale si cerca per prodotto, firma delle personalizzazioni **e** insieme ordinato dei `bundle_option_id` delle figlie (`itemLike()` prende un terzo parametro `string $bundleKey`, `''` per un articolo semplice). Riga nuova: la madre e poi le figlie (`parent_item_id`, `bundle_option_id`, nome/SKU/unità/immagine del componente, `tax_category_id` della madre, `position` subito dopo la madre — se serve si spostano le successive con `nextPosition`).
- **`recalculate()`**: salta le righe con `parent_item_id` > 0 nel giro principale (non entrano in `LinePrice`, `OrderTotals` né nel peso). Per una madre: `Bundles::resolve()` con gli `bundle_option_id` delle sue figlie attuali; `UserError` → via madre **e figlie**, nome in `removed`; altrimenti `customization_surcharge` = personalizzazioni + opzioni (con `customizations` spenta: somma dei `surcharge` dei campi già salvati), e dopo il giro le figlie si riscrivono: stessi componenti e opzioni, `quantity` = pezzi per confezione × quantità della madre, `unit_price`, `list_price`, `line_total`, `tax_*` a zero e `price_source` come le altre righe a prezzo zero. Una madre senza figlie dopo il ricalcolo non esiste: `resolve()` ne ricrea l'insieme dalle scelte; se le figlie mancano del tutto e il gruppo ha `min` > 0, la madre esce.
- **`setQuantity()`** su una madre: la quantità passa dal ricalcolo, che riporta le figlie; con zero toglie anche loro (come `remove`). Su una figlia → `cart.child_line`. Con una quantità che non basta lascia l'errore di `add()` (stessa verifica).
- **`remove()`** sulla madre cancella anche le figlie (`OrderItem::delete` riga per riga come oggi).
- **`merge()`**: la riga dell'ospite si fonde con una riga uguale (stessa firma e stesse scelte) del carrello di destinazione sommando le confezioni, altrimenti si sposta la madre **con le sue figlie** (`order_id` aggiornato per tutte).
- **`contents()`**: `items` a primo livello (`parent_item_id` = 0) con `children`.

- [ ] **Step 1: scrivere i test** in `CartTest.php` (prima delle chiamate `Cart`, `accendiFunzionalita(['orders', 'bundles'])`; dopo ogni `prova()` `Gestionale::reset()`; `multiprodottoDiProva` da `compra.php`):
  1. un multiprodotto fisso (due componenti, uno con quantità 2) aggiunto in 3 confezioni: una madre a prezzo, due figlie con quantità 6 e 3 (o i valori dei dati), `unit_price` `0.00`, `line_total` `0.00`, `parent_item_id` = madre, `contents()['items']` ha **una** riga con `children` di due elementi; il totale è quello della madre;
  2. scelta (gruppo `min` 1 `max` 1, due opzioni a +1,00 e +2,50): con l'opzione da +2,50 il `unit_price` della madre è prezzo + 2,50; con `bundle_mode` `fixed` e `choices` non vuoto → `bundle.unknown_option`; il `customization_surcharge` in ingresso è ignorato; sovrapprezzo di personalizzazione e di opzione si sommano;
  3. scelte uguali in ordine diverso (`[a, b]`/`[b, a]`, id stringa/intero) → una riga, quantità sommata, figlie aggiornate; scelte diverse → due righe madre;
  4. `min` non raggiunto o `max` superato → `bundle.too_few`/`bundle.too_many` con `name`, carrello vuoto;
  5. disponibilità: fisso esaurito → `cart.not_enough_stock` con il nome del **componente**; con D60 (vendita scoperta accesa su quel prodotto) passa; lo stesso prodotto fisso e opzione si somma (`pieces`) e fallisce se la somma supera il disponibile pur bastando ciascuno;
  6. `setQuantity` sulla madre a 2 e a 0.5 (se l'unità ammette decimali) → figlie proporzionali; a 0 → via madre e figlie; su una figlia → `cart.child_line`; `remove` su una figlia → `cart.child_line`; `remove` sulla madre → via tutte;
  7. anagrafica: componente spento, componente cancellato, opzione tolta, `min` alzato oltre le scelte, composizione svuotata, multiprodotto spento: ciascuno fa uscire madre e figlie con il nome in `removed`, e nessuna figlia resta nel carrello;
  8. sovrapprezzo di un'opzione cambiato in anagrafica → `recalculate()` riprezza la madre; prezzo del multiprodotto cambiato → idem;
  9. `bundles` spenta: `add()` → `bundle.feature_off`; una confezione già nel carrello resta, con figlie e prezzo, dopo `recalculate()`;
  10. `merge()`: stessa confezione nei due carrelli → una madre, confezioni sommate, figlie riscritte; scelte diverse → due madri con le rispettive figlie, tutte nel carrello di destinazione;
  11. i test già presenti per articoli semplici e personalizzati restano verdi.
  In `CheckoutTest`: il checkout di un carrello con una confezione prenota **solo le figlie** (componenti), non la madre; il numero di pezzi nel riepilogo è quello delle confezioni. In `LifecycleTest`: conferma scarica i componenti, annullamento li rimette; annullamento dopo un movimento già reso a mano (preparato direttamente con `SalesReturnItem`) toglie il reso dalla figlia.
- [ ] **Step 2: eseguire** `php tests/integrazione/CartTest.php`, `CheckoutTest.php`, `LifecycleTest.php` — atteso: rossi i nuovi, verdi i vecchi.
- [ ] **Step 3: implementare** i cambi in `Cart` (spezzando in metodi privati piccoli: `addBundle`, `writeChildren`, `bundleKey`, `dropWithChildren`) e la frase `cart.child_line`.
- [ ] **Step 4: eseguire** i tre test, `CustomerStatsTest`, `OrdersDemoTest`, `php tests/ErrorKeysTest.php` e `php tests/run.php` — atteso: verdi.
- [ ] **Step 5: commit** «Carrello: confezioni con righe figlie, scelte controllate e riscritte dal server, uscita intera quando l'anagrafica cambia».

### Task 5: Il reso di una confezione

**Files:**
- Modify: `src/Support/Returns/Returns.php` (`lines` 68, `register` 97, `wanted` 224, `productItems` 283), `src/Support/Returns/ReturnRules.php`, `src/Resources/Sales/OrderReturnResource.php` (`pageHtml`, `linesHtml`, `run`), `tests/integrazione/ReturnsTest.php`, `tests/ReturnRulesTest.php`, `tests/OrderReturnResourceTest.php`, `tests/integrazione/LifecycleTest.php`

**Interfaces:**
- Consumes: `OrderLines::children/sold` (Task 2), righe d'ordine con figlie (Task 4).
- Produces: `Returns::lines($orderId)` torna **le righe di primo livello**, ciascuna con la chiave `children: list<{order_item_id, name, per_unit, returned, restock_default}>` (`per_unit` = quantità figlia / quantità madre, `returned` da `returned(childId)`); `Returns::register($orderId, $lines, $options)` accetta, su una riga madre, `children_restock: array<int, ?bool>` (id della figlia → `restock`; `null` o assente = quello proposto dal motivo). `ReturnRules::childQuantity(float $perUnit, float $returnedPacks): float` (3 decimali).

Comportamento:

- Si può rendere solo una riga di primo livello: una figlia chiesta da sola → `return.line_not_returnable`. Il massimo di una madre è `quantità − returned(madre)`; una madre resa a 2 di 3 ne ha 1 residuo.
- `register()` scrive una `SalesReturnItem` per la madre (`restock` `false`, nessun `Allocation`) e una per figlia con quantità `childQuantity(per_unit, confezioni rese)` e il proprio `restock`, e le figlie con `restock` passano da `Allocation::returnGoods()` come oggi le righe semplici. `restocked`/`kept` contano **le figlie** (la madre non conta).
- Il motivo «difettoso» propone `restock` falso per tutte le figlie (`ReturnRules::defaultRestock`), ma ognuna si può correggere.
- Reso online (`onlineReturnable`) invariato; per E1c una confezione con personalizzazioni resta esclusa dalle stesse regole della riga madre.
- Un reso della madre senza figlie (caso corrotto) si registra comunque, senza movimenti.

- [ ] **Step 1: scrivere i test.** `ReturnsTest`: un ordine con una confezione da 3 (due componenti, uno da 2 pezzi) e un articolo semplice; `Returns::lines()` ha due righe di primo livello e la confezione ha due `children` con `per_unit` giusti; `register` di 1 confezione con motivo «cambio idea» → tre `SalesReturnItem` (madre + due figlie, quantità 1 e 2) e i due componenti **rientrano** in magazzino (livelli +1 e +2); con `children_restock` che nega il secondo, solo il primo rientra e `restocked` 1, `kept` 1; con motivo «difettoso» nessuno rientra; la figlia chiesta da sola → `return.line_not_returnable`; un secondo reso di 3 confezioni dopo uno di 1 → `return.over_quantity` con `max` 2; due righe per la stessa madre nella stessa richiesta si fondono come oggi. `LifecycleTest`: annullare l'ordine dopo il reso di 1 confezione con un componente che non rientra rimette in magazzino `quantità − reso` per quel componente e tutto per gli altri. `ReturnRulesTest`: `childQuantity(2.0, 1.0)` = 2, `childQuantity(0.5, 3.0)` = 1,5. `OrderReturnResourceTest`: `linesHtml` per una confezione mostra la riga madre con il campo quantità e, sotto, una riga per componente con la casella «Rientra in magazzino» (nome del campo letto da `run()`) e il nome escapato; una riga semplice è identica a prima.
- [ ] **Step 2: eseguire** `ReturnsTest`, `ReturnRulesTest`, `OrderReturnResourceTest`, `LifecycleTest` — atteso: rossi i nuovi.
- [ ] **Step 3: implementare** i cambi in `Returns`, `ReturnRules` e `OrderReturnResource` (la riga madre e i suoi componenti nel `linesHtml`; `run()` legge `children_restock` dalle caselle dei componenti; la proposta della casella segue il motivo come oggi, con lo stesso script).
- [ ] **Step 4: eseguire** i test e `php tests/run.php` — atteso: verdi.
- [ ] **Step 5: commit** «Reso della confezione: una riga per la madre, una per componente col suo "rientra in magazzino"».

### Task 6: La scheda dell'articolo — tipo, composizione, controlli e salvataggio

**Files:**
- Modify: `src/Resources/Catalog/ProductModelResource.php` (`formSchema` 204, `mainColumn` 395, `mutateRequestValues` 1105, `afterStore`/`saveExtras` 2108–2240, `mutateFormValues` 2902, `withoutExtras` 7694), `tests/ProductModelResourceTest.php`, `tests/integrazione/ProductModelTest.php`
- Create: `tests/integrazione/ProductModelBundlesTest.php`

**Interfaces:**
- Consumes: `Bundles::assertComposition/forModel/available/isBundle` (Task 3).
- Produces: `ProductModelResource::isBundleModel(int $modelId): bool` (`type = 'bundle'`); `ProductModelResource::bundleComponentOptions(int $modelId): array` e `bundleOptionProducts(): array` (id → nome dei prodotti **non** multiprodotto, attivi, che la tendina propone; un componente già scelto e poi spento resta in elenco marcato «(disattivato)» come per le personalizzazioni); `ProductModelResource::saveBundle(int $modelId, array $post): void`.

Prima di scrivere codice, **leggere** `productsField()` (5586), `suppliersModal()`/`suppliersScript()` (7014/7102), `customizationsCard()` (4882) e `saveSuppliers`/`postedSuppliers` (1215, 1524) e **decidere e mettere in ledger** come si portano i gruppi: l'ipotesi è il pattern della finestra Fornitori (JSON nella riga del gruppo, riletto dal server con lo stesso codice, P103), i componenti col repeater del core con `RepeaterRelation::make(BundleComponent::$table, 'product_model_id')->model(BundleComponent::class)->positionKey('position')->softDelete(false)` come le personalizzazioni; se il repeater del core rifiuta la colonna JSON non-DB, i gruppi si scrivono a mano da `saveBundle()` senza `RepeaterRelation`. Qualunque via, i gruppi sono scritti **da `saveBundle()`** (cancella e ricrea le righe di `gst_bundle_groups` e delle loro opzioni, in transazione, posizioni dall'ordine arrivato) e le opzioni sono lette dal server dal JSON, mai credute.

Cambi nel form:

- **Tipo** (`FormField::key('type')->select(['simple' => 'Semplice', 'bundle' => 'Multiprodotto'])`): presente solo con `bundles` acceso oppure se l'articolo è già `bundle`; in modifica sola lettura (`readonly()->disabled()`); `mutateRequestValues` lo valida (`simple`|`bundle`, solo con la funzionalità, altrimenti `simple`) in creazione e lo toglie in modifica (`unset($values['type'])`).
- **Cosa sparisce** con `type = bundle` (`hiddenWhen('type', 'bundle')` sui riquadri esistenti): «ha varianti», griglia delle opzioni, giacenza e carico iniziale, giacenza per sede, scorta minima, fornitori, lotti, «Compila le informazioni avanzate» **tranne SKU e EAN** (che restano). Lato server: per un `bundle` `has_variants` è sempre `false`, `assertStockWritable`, `assertMinStocks`, `assertLocationRows`, `assertSupplierRows`, `saveBackorders`, `saveMinStocks`, `saveSingleStock`, `saveLocationStock`, `saveSuppliers`, `saveNewVersions` e `Generator::run` **non girano** (un solo punto, `isBundleModel($modelId) || ($post['type'] ?? '') === 'bundle'` in `saveExtras`/`mutateRequestValues`).
- **Cosa si aggiunge**, in un riquadro **Composizione** dopo «Prodotto» (solo con `type = bundle`):
  - `bundle_mode` (`Prodotti fissi` · `A scelta del cliente` · `Fissi e a scelta`), con il tooltip che dice cosa cambia; `show_components_value` come interruttore «Mostra il valore dei componenti» sulla stessa riga;
  - riquadro **Componenti** (visibile con `fixed` e `mixed`): repeater [Prodotto · Quantità], «Aggiungi componente»;
  - riquadro **Gruppi di scelta** (visibile con `choice` e `mixed`): righe [Nome · Min · Max · Opzioni] con il bottone «Opzioni» che mostra il riassunto («3 opzioni») e apre una finestra a righe [Prodotto · Sovrapprezzo], come la finestra Fornitori;
  - casella in sola lettura **«Confezioni disponibili»** da `Bundles::available()`, solo in modifica.
- **Salvataggio** (`mutateRequestValues` per i controlli, `saveExtras` → `saveBundle` per la scrittura): `Bundles::assertComposition` prima dell'insert (un rifiuto non lascia l'articolo scritto a metà); un prodotto che è un multiprodotto (o il multiprodotto stesso) come componente/opzione → `bundle.nested`; i dati dei riquadri nascosti dalla modalità si **scartano** (con `fixed` via i gruppi, con `choice` via i componenti) anche se arrivano nella richiesta; cambiare modalità su un articolo esistente li cancella davvero.
- `mutateFormValues` riempie la riga del repeater/JSON dal database; `withoutExtras` scarta le chiavi nuove che non sono colonne di `gst_product_models` (tutte tranne `type`, `bundle_mode`, `show_components_value`).

- [ ] **Step 1: scrivere i test** in `ProductModelBundlesTest.php` (integrazione, `accendiFunzionalita(['orders', 'bundles'])`, richieste costruite come negli altri test della Resource: chiamare `mutateRequestValues` e `afterStore`/`afterUpdate` con `$_POST` preparato) e in `ProductModelResourceTest.php` (unitari): il campo `type` c'è con `bundles` accesa e manca con spenta; in modifica è disabilitato; con `type = bundle` i riquadri di varianti/giacenza/fornitori hanno `hiddenWhen('type','bundle')`; **creazione** di un multiprodotto fisso con due componenti: nascono il modello `bundle`, un solo prodotto, nessun movimento di magazzino, le righe di `gst_bundle_components` in ordine; una `choice` con due gruppi e le opzioni da JSON; `mixed` con entrambi; `fixed` con gruppi arrivati: i gruppi **non** si scrivono; passare da `mixed` a `fixed` cancella i gruppi; i controlli: `fixed` senza componenti, `choice` senza gruppi, `mixed` con uno solo dei due, quantità 0, componente doppio, prodotto doppio in un gruppo, `min > max`, `max` 0, `max` oltre le opzioni, nome del gruppo vuoto, JSON delle opzioni rotto, un multiprodotto come componente (`bundle.nested`), un prodotto cancellato come componente, `type` cambiato a mano in una modifica (ignorato), `type = bundle` con la funzionalità spenta (diventa `simple`): ognuno rifiuta con la sua frase e **l'articolo non esiste a metà** (nessun modello nuovo in creazione); `Bundles::available` scritto in sola lettura nella `mutateFormValues`.
- [ ] **Step 2: eseguire** i due test — atteso: rossi.
- [ ] **Step 3: implementare** campi, riquadri, finestra delle opzioni (JSON, riassunto «N opzioni»), controlli e `saveBundle`. Per il bottone/finestra e il suo script seguire quelli dei fornitori senza copiarli: un'unica funzione che costruisce finestra e script, parametrizzata dai nomi dei campi.
- [ ] **Step 4: eseguire** `ProductModelBundlesTest`, `ProductModelResourceTest`, `ProductModelTest`, `ProductModelCustomizationsTest`, `php tests/ErrorKeysTest.php` e `php tests/run.php` — atteso: verdi.
- [ ] **Step 5: commit** «Scheda dell'articolo: tipo Multiprodotto, composizione, componenti, gruppi di scelta e controlli al salvataggio».

### Task 7: Blocchi su prodotti in uso, badge, ordine ed email con le figlie

**Files:**
- Modify: `src/Resources/Catalog/ProductModelResource.php` (`assertDeletable` 3168, `tableSchema` 678, `deleteRecord` 3179), `src/Resources/Catalog/ProductResource.php` (~1168, e il punto che spegne un prodotto), `src/Resources/Sales/OrderItemTableResource.php` (`nameCell`, formatter dei prezzi), `src/Support/Orders/OrderEmail.php` (`compose`), `view/emails/order.php`, `view/emails/order-merchant.php`, `tests/ProductModelResourceTest.php`, `tests/ProductResourceTest.php`, `tests/OrderSectionResourcesTest.php`, `tests/integrazione/OrderEmailsTest.php`, `tests/integrazione/EliminazioniTest.php`

**Interfaces:**
- Consumes: `Bundles::usedBy()` (Task 3), `OrderLines` (Task 2).
- Produces: `ProductModelResource::bundleBadge(int $modelId): string` (HTML escapato, «Multiprodotto», vuoto per un semplice).

Cambi:

- **Eliminare e spegnere.** `assertDeletable` (modello) e il blocco di `ProductResource` (riga ~1168) rifiutano, oltre a `product.has_movements`, un prodotto per cui `Bundles::usedBy()` non è vuoto: `UserError::refusal('bundle.in_use', ['name' => nome del prodotto, 'bundles' => nomi uniti da «, »])`. Lo stesso per **spegnere** un prodotto (`active` → `false`) dalla scheda o dall'interruttore: rifiuto con la stessa frase, senza cambiare il dato. Eliminare un **multiprodotto** porta via componenti, gruppi e opzioni (`deleteRecord`, anche a funzionalità spenta: la chiave esterna non guarda le funzionalità); a un multiprodotto già venduto si applica `product.has_movements` come oggi **solo se ha movimenti**, e un ordine che lo contiene lo ferma (`product.in_orders` se non esiste già un controllo equivalente: verificare e, se manca, aggiungerlo con la frase).
- **Badge** «Multiprodotto» nell'elenco articoli (cella del nome, accanto al nome come gli altri badge); giacenze, movimenti e avvisi di scorta non mostrano i multiprodotti (verificare con un test che non compaiano; se oggi li mostrano, filtrare per `type = 'simple'`).
- **Scheda dell'ordine.** La tabella delle righe mostra le figlie sotto la madre, rientrate, senza prezzo, quantità e totale vuoti: «Scelta: Vino rosso» per quelle con `bundle_option_id` > 0 (il nome del gruppo non è conservato: il testo è «Scelta: » + nome del prodotto), il solo nome per i fissi. Le personalizzazioni della madre restano sotto il nome come oggi. Le figlie non si ordinano a sé: seguono la madre.
- **Email** (cliente e commerciante): sotto la madre la lista dei componenti, indentata, senza prezzo, con `$e()`; i totali non cambiano.

- [ ] **Step 1: scrivere i test.** `ProductModelResourceTest`/`ProductResourceTest`: eliminare un prodotto usato come fisso, come opzione, e come fisso+opzione di due multiprodotti → rifiuto `bundle.in_use` con i nomi dei multiprodotti (una volta sola ciascuno); spegnerlo → stesso rifiuto, e il prodotto resta attivo; un prodotto non usato si elimina e si spegne come prima; eliminare un multiprodotto non venduto toglie le sue tre tabelle senza errori di chiave; `bundleBadge` dice «Multiprodotto» e non c'è per un semplice; l'elenco non mostra multiprodotti nelle giacenze. `OrderSectionResourcesTest`: la tabella delle righe di un ordine con una confezione ha una riga madre col prezzo e le figlie rientrate senza prezzo, «Scelta: » solo per le opzioni, nomi escapati (`<b>` non diventa markup). `OrderEmailsTest`: `compose('received', …)` per una confezione contiene i componenti sotto la madre in entrambe le viste, con un `&` escapato una volta sola, e nessun prezzo accanto alle figlie.
- [ ] **Step 2: eseguire** i quattro test — atteso: rossi.
- [ ] **Step 3: implementare** i cinque punti.
- [ ] **Step 4: eseguire** i test e `php tests/run.php` — atteso: verdi.
- [ ] **Step 5: commit** «Multiprodotto: prodotti in uso protetti, badge, righe figlie in scheda dell'ordine ed email».

### Task 8: Dati di prova, documentazione, chiusura

**Files:**
- Modify: `src/Seeding/CatalogDemo.php` (`create()`, `clear()`), `src/Seeding/OrdersDemo.php` (~252, ~388), `tests/integrazione/CatalogDemoTest.php`, `tests/integrazione/OrdersDemoTest.php`, `docs/dev/concetti/catalogo.md`, `docs/dev/concetti/vendite.md`, `docs/user/` (nuova pagina `catalogo-multiprodotti.md` come `catalogo-personalizzazioni.md`), `CHANGELOG.md`, `TODO.md`

**Interfaces:**
- Consumes: tutto il resto del piano.

- **`CatalogDemo::create()`**: con `ensure()` tre multiprodotti con codice `DemoCode` (tipo `bundle`, nome in italiano): *Cesto degustazione* (fisso, tre componenti demo con quantità 1, 2, 1), *Cesto componibile* (a scelta: un gruppo «Vino» min 1 max 1 con due opzioni +0 e +4 €, un gruppo «Dolce» min 0 max 2 con tre opzioni a +0) e *Cesto completo* (fisso e a scelta). I componenti sono articoli demo già esistenti (per riferimento). Prezzi plausibili; `show_components_value` acceso solo sul primo.
- **`CatalogDemo::clear()`**: i multiprodotti nostri prima degli altri articoli (portano via le loro tre tabelle); un componente ancora usato da un multiprodotto **vero** resta e finisce in `$kept`.
- **`OrdersDemo`**: ordini che usano i multiprodotti in stati diversi (uno in carrello/in attesa, uno confermato, uno annullato) e **un reso di una confezione** con un componente che non rientra (motivo «difettoso» su uno, rientro sull'altro), con `bundles` accesa; spenta nessuno.
- **Guide per sviluppatori**: in `catalogo.md` una sezione *Multiprodotto* (le tre tabelle, `Bundles::forModel()` come contratto per E1b, `resolve()` e i suoi errori, `available()`/`componentsValue()`, il blocco di eliminazione); in `vendite.md`, sotto `Cart`, il parametro `choices`, la forma di `contents()['items'][]['children']`, `OrderLines`, il reso della confezione e `children_restock`, e il perché la madre non entra mai in `Allocation`. **Guida utente** `catalogo-multiprodotti.md`: cos'è, fisso/a scelta/misto, min e max, il valore dei componenti, perché un componente non si elimina, cosa vede il cliente nell'ordine, come si rende una confezione; link dal sommario delle guide come le altre.

- [ ] **Step 1: test.** `CatalogDemoTest`: dopo `create()` ci sono i tre multiprodotti con la composizione attesa (la scelta con due gruppi, il misto con entrambi) e `Bundles::available()` > 0 per tutti; `clear()` li toglie senza errori di chiave; un componente demo usato da un multiprodotto vero resta. `OrdersDemoTest`: con `bundles` accesa almeno un ordine ha una madre con figlie, un reso di confezione ha una figlia con `restock` `false` e una con `true`; spenta nessuna. `DocsPagesTest` coglie la nuova pagina.
- [ ] **Step 2: eseguire** — atteso: rossi.
- [ ] **Step 3: implementare** demo e documentazione.
- [ ] **Step 4: rigenerare** il sito di prova: `cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php forge gestionale:demo --fresh` — atteso: nessun errore, i tre multiprodotti in *Catalogo → Articoli*.
- [ ] **Step 5: CHANGELOG e TODO.** `CHANGELOG.md`: voce Multiprodotto (tipo e composizione nella scheda, `Bundles`, righe figlie in `Cart`, `OrderLines`, reso della confezione, blocco dei componenti in uso, demo; **chi aggiorna deve lanciare `php forge update`** per le tre tabelle e le tre colonne nuove). `TODO.md`: Piano 1 di G5 da `[~]` a `[x]` (PR #7, #8, #9, core wonder-image/app#55, 2026-10-02), Piano 2 con il riferimento a questo file e la data; G5 resta aperto fino alla prova nel browser.
- [ ] **Step 6: eseguire** `php tests/run.php` — atteso: tutta la suite verde (riportare i conteggi).
- [ ] **Step 7: revisione finale** del branch come dice la skill `executing-plans` e **un solo giro di correzioni** su Critical e Important.
- [ ] **Step 8: commit**, push del branch, PR su `main` con la descrizione e il footer, poi `ccd_pr` `get_status` (e `bind_pr` se non lo riporta); CI verde; merge con merge commit e pulizia di branch remoto, locale e worktree.

### Task 9: Prova nel browser (serve il tuo accesso)

Dopo il merge dico cosa aprire su `https://ecommerce.test/backend/` con `bundles` (e `customizations`) accese in *Set Up → Funzionalità*: creare un multiprodotto (*Prodotti fissi*, poi cambiare composizione in modifica e vedere che i riquadri nascosti si svuotano), un gruppo con la finestra delle opzioni, provare a eliminare e a spegnere un componente in uso, un ordine demo con una confezione (scheda con le figlie rientrate, email di prova), *Registra reso* su una confezione con un componente che non rientra. I ritocchi finiscono in un giro di revisione.

- [ ] **Step 1: avvisare l'utente** che la prova serve il suo login e proporre una breve sessione guidata; a G5 chiuso aggiornare `TODO.md` (G5 `[x]`).
