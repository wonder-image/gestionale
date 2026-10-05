# Campagne di sconto — piano di realizzazione (G6, piano 1)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** le campagne di sconto massivo: un prezzo che cambia da solo, in un periodo, per tutto il catalogo o per categorie, tag, marchi e articoli. Il carrello le applica (`price_source` `campaign`, `discount_campaign_id` sulla riga), il backend le gestisce con stato, anteprima e avviso di sovrapposizione, e la vetrina (E1b) ne legge prezzo da barrare, percentuale e fine.

**Architecture:** due classi pure (`ScopeMatcher`: un selettore e i fatti di un prodotto → sì/no; `CampaignPrice`: campagne valide e prezzo base → il prezzo che vince) e due servizi in `src/Support/Promotions/` (`ProductScope` legge dal database selettore e fatti di un prodotto; `Campaigns` è l'unica porta: campagne in corso, stato, anteprima, sovrapposizioni, dati per la vetrina). `Cart::recalculate`, già punto unico del prezzo, chiede a `Campaigns` il `campaign_price` e lo passa a `LinePrice`. Il selettore nel backend è un solo componente (`ScopeForm`) che il piano 2 riusa per i coupon.

**Tech Stack:** PHP 8.2+, harness `tests/harness.php` (`php tests/run.php`), sito di prova `boilerplates/ecommerce-site` (tabelle con `php forge update --local`), helper `tests/integrazione/supporto/compra.php`.

**Spec:** `docs/superpowers/specs/2026-10-05-sconti-e-coupon-design.md` (sezioni 1–3, «I due piani» punto 1); regole di fondo in `2026-09-11-gestionale-ecommerce-architettura-design.md` §4.6.

## Global Constraints

- Lingua: commenti, testi utente e nomi dei test in **italiano** con gli accenti; classi, tabelle, colonne e chiavi in inglese.
- Funzionalità `discount_campaigns` (già in `config/features.php`): spenta, il carrello ignora le campagne, la pagina e il menu spariscono, **i dati restano**; gli ordini già fatti non cambiano mai (le righe scritte non si rileggono).
- Tabelle col prefisso `gst_`; soft delete `deleted='true'`; chiavi esterne `ON DELETE RESTRICT`; codice `dsc_` (`Codes::DISCOUNT_CAMPAIGN`, già definito) con `Field::key('code')->text()->uniqueCode(Codes::DISCOUNT_CAMPAIGN)`.
- Le classi pure non toccano il database, non lanciano, non leggono `date()`: l'ora arriva dal chiamante.
- Priorità del prezzo (non cambia): listino → **campagna** → prezzo scontato → base. Lo sconto scritto a mano sulla riga vince su tutto (`price_source` `manual`).
- Tra più campagne valide vince lo **sconto maggiore** (il prezzo più basso); a parità la più vecchia (`id` minore). Mai sotto zero.
- `LinePrice`: `campaign_price` vuoto/assente = nessuna campagna; `0` = omaggio (campagna al 100 %), come il prezzo a mano.
- Una campagna vale per l'articolo intero e quindi per tutte le sue opzioni. Sulle confezioni sconta il prezzo della confezione; i sovrapprezzi restano a prezzo pieno (già così: `LinePrice` somma `customization_surcharge` dopo lo sconto). Le righe figlie non si prezzano.
- Canale: si valuta quello dell'ordine (`gst_orders.channel`, oggi solo `online`); `applies_online/office/pos` esistono e il motore li rispetta.
- Rifiuti prevedibili: `UserError::make('campaign.<chiave>', [...])`, frase in `lang/it/gestionale.json` sotto `gestionale.errors.campaign` (il JSON si riscrive con python `json.dumps(ensure_ascii=False, indent=4) + "\n"`); `ErrorKeysTest` cade se manca una chiave.
- Form compatti: tooltip al posto dei testi d'aiuto, campi correlati sulla stessa riga, niente campi automatici. Tutto ciò che viene dal database e finisce in HTML passa da `escape()`.
- Test d'integrazione dentro `prova()` (transazione annullata), `Gestionale::reset()` dopo aver toccato le funzionalità; ogni task chiude con `php tests/run.php` verde per intero.
- Branch `g6-sconti-e-coupon` (già creato, con la spec); commit con trailer `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. **Non toccare né committare** i file che l'utente ha modificato e non committato (`composer.lock`, `docs/dev/concetti/*`, `docs/user/anagrafiche.md`, `src/Models/Contacts/*`, `src/Models/System/ExternalReference.php`, `src/Resources/Contacts/CustomerResource.php`, `src/Support/Catalog/Code.php`, `src/Support/Contacts/CustomerSheet.php`, i test già modificati, `tests/integrazione/supporto/dns-fixture.php`): ogni `git add` nomina i file uno per uno.

## Decisioni di perimetro

- **Lo sconto di riga a mano non si tocca.** Già calcolato da `LinePrice`, scritto da `Cart` e provato in parte: qui si aggiunge solo un test d'integrazione che lo verifica insieme alla campagna (la campagna non vince mai su `manual`). L'inserimento da schermata è G9.
- **Campagna e prezzo scontato.** Con la campagna valida vince la campagna anche se il `sale_price` fosse più basso (priorità di §4.6). `exclude_sale_products` toglie dal gioco i prodotti che hanno un prezzo scontato valido (`sale_price` > 0 e < `price`).
- **Il selettore.** `applies_to_all` = tutto il catalogo (i ponti non si leggono). Altrimenti un prodotto è dentro se è in una categoria scelta **o in un suo discendente** (`CategoryTree::descendants`), o ha un tag scelto, o è di un marchio scelto, o il suo articolo (`product_model_id`) è tra quelli inclusi; è fuori comunque se il suo articolo è tra gli esclusi (`is_excluded` = `true`). Selezione vuota senza «tutto» = nessun prodotto.
- **Stato ricavato**, mai scritto: `disattivata` (`active` falso), `programmata` (adesso < `starts_at`), `terminata` (`ends_at` pieno e adesso > `ends_at`), altrimenti `in corso`. `ends_at` vuoto = senza fine.
- **Cosa legge la vetrina.** `Campaigns::display($productId)` dà `{campaign_id, name, base_price, price, percent, ends_at}` o `null`: E1b non fa conti.
- **Sovrapposizione.** Due campagne attive che si intersecano nel tempo **e** coprono almeno un prodotto in comune. L'avviso non blocca il salvataggio.

## Review Focus

- **Il confine del periodo.** Una campagna che inizia o finisce esattamente adesso, una senza fine, una con `ends_at` già passato, `starts_at` > `ends_at`: valida solo nell'intervallo, rifiutata a salvataggio se l'ordine delle date è invertito. *(Task 3, Task 5)*
- **Il prezzo non va mai storto.** Sconto in € più grande del prezzo, percentuale oltre 100, prezzo base zero, campagna al 100 % (omaggio), due campagne uguali: nessun prezzo negativo, nessun omaggio per sbaglio, vince la più vecchia. *(Task 2)*
- **Il selettore.** Una sottocategoria sotto una categoria scelta, un prodotto in due categorie di cui una esclusa per articolo, selezione vuota, marchio senza prodotti. *(Task 2, Task 3)*
- **Il carrello non si sporca.** Campagna scaduta o spenta mentre l'articolo è nel carrello: il ricalcolo toglie `price_source` `campaign` e `discount_campaign_id` (torna a 0); un prezzo a mano non viene mai sovrascritto; le figlie di una confezione restano senza prezzo; funzionalità spenta = nessuna campagna. *(Task 4)*
- **Il form.** Sconto percentuale oltre 100, importo negativo, date invertite, selettore senza nulla e senza «tutto», richiesta modificata a mano: rifiutati con una frase, senza lasciare la campagna scritta a metà. *(Task 5)*

---

### Task 1: Tabelle e Model

**Files:**
- Create: `src/Models/Promotions/DiscountCampaign.php`, `DiscountCampaignCategory.php`, `DiscountCampaignTag.php`, `DiscountCampaignBrand.php`, `DiscountCampaignProductModel.php`, `tests/PromotionModelsTest.php`

**Interfaces:**
- Produces: `DiscountCampaign::$table = 'gst_discount_campaigns'` con `code`, `name`, `discount_type` enum `percent|amount`, `discount_value` (`Columns::decimal('discount_value', '12,2')`), `starts_at` e `ends_at` (come le altre date-ora delle tabelle, `ends_at` facoltativa), `active` enum `true|false` default `true`, `applies_to_all` enum default `false`, `exclude_sale_products` enum default `false`, `applies_online` enum default `true`, `applies_office` e `applies_pos` default `false`, `note` testo. Ponti: `DiscountCampaignCategory` (`discount_campaign_id`, `category_id`), `…Tag` (`tag_id`), `…Brand` (`brand_id`), `DiscountCampaignProductModel` (`discount_campaign_id`, `product_model_id`, `is_excluded` enum default `false`). `$folder = 'gestionale/models'`, `syncSchema()` → `null`, indici `ind_campaign` e uno per l'altra chiave.

Ricalcati su `Tag` (tabella con codice) e `ProductModelTag` (ponte), per le chiavi esterne `Column::key(...)->int()->null(false)->foreign(Altra::$table)`.

- [ ] **Step 1: scrivere i test** in `PromotionModelsTest.php` (stile di `CustomizationModelsTest.php`): i cinque modelli hanno tabella e colonne attese con tipo e chiave esterna, `syncSchema()` è `null`, `discount_value` a 2 decimali, i default (`active` `true`, `applies_online` `true`, `applies_to_all` `false`), il codice usa il prefisso `dsc_`.
- [ ] **Step 2: eseguire** `php tests/PromotionModelsTest.php` — atteso: rosso (classi mancanti).
- [ ] **Step 3: implementare** i cinque modelli.
- [ ] **Step 4: eseguire** il test, poi `cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php forge update --local` (atteso: cinque tabelle create, nessun errore) e `php tests/run.php` dal pacchetto — atteso: verde.
- [ ] **Step 5: commit** «Campagne di sconto: tabelle e Model».

### Task 2: Le classi pure — `ScopeMatcher`, `CampaignPrice`, omaggio in `LinePrice`

**Files:**
- Create: `src/Support/Promotions/ScopeMatcher.php`, `src/Support/Promotions/CampaignPrice.php`, `tests/ScopeMatcherTest.php`, `tests/CampaignPriceTest.php`
- Modify: `src/Support/Pricing/LinePrice.php` (metodo `source()`, ramo `campaign_price`), `tests/LinePriceTest.php`

**Interfaces:**
- Produces: `ScopeMatcher::matches(array $scope, array $facts): bool` con `$scope = ['all' => bool, 'categories' => list<int>, 'tags' => list<int>, 'brands' => list<int>, 'models' => list<int>, 'excluded_models' => list<int>]` e `$facts = ['categories' => list<int> (già con gli antenati), 'tags' => list<int>, 'brand_id' => int, 'model_id' => int]`.
- Produces: `CampaignPrice::best(array $campaigns, float $price, float $salePrice): ?array{campaign_id: int, price: float, percent: float}` con `$campaigns` lista di `['id', 'discount_type', 'discount_value', 'exclude_sale_products']`; `price` è il prezzo base. Restituisce `null` se nessuna si applica.
- Modifica: in `LinePrice::source()` `campaign_price` conta se la stringa tagliata **non è vuota e numerica**, anche `0`.

`percent` restituito = `round((price − campaign price) / price × 100, 2)` (0 se il prezzo è 0). Sconto `percent` > 100 si ferma a 100; `amount` > prezzo si ferma al prezzo. `exclude_sale_products` salta la campagna se `0 < salePrice < price`. Vince il prezzo più basso, a parità l'`id` minore.

- [ ] **Step 1: scrivere i test.** `ScopeMatcherTest`: tutto → sì per qualunque fatto; categoria scelta con antenati nei fatti → sì; tag, marchio, articolo incluso → sì; articolo escluso batte tutto, anche «tutto»; selezione vuota senza «tutto» → no; marchio 0 non combacia con un marchio scelto. `CampaignPriceTest`: 20 % su 50 → 40,00 e `percent` 20; € 10 su 50 → 40; € 80 su 50 → 0,00 (omaggio, non negativo); 150 % → 0,00; due campagne, vince il prezzo più basso; a parità l'`id` minore; `exclude_sale_products` con prezzo scontato valido → `null`, con `sale_price` ≥ `price` → si applica; prezzo base 0 → `null`; lista vuota → `null`. `LinePriceTest`: `campaign_price` `'0'` → `price_source` `campaign`, `unit_price` `0.00`; `''` e assente → nessuna campagna (vince `sale_price`/base); con `manual_unit_price` vince il manuale.
- [ ] **Step 2: eseguire** i tre file — atteso: rossi i nuovi (classi mancanti; omaggio ancora scambiato per «nessuna campagna»).
- [ ] **Step 3: implementare** le due classi e il cambio in `LinePrice`.
- [ ] **Step 4: eseguire** i tre file, poi `php tests/run.php` — atteso: verde.
- [ ] **Step 5: commit** «Campagne di sconto: selettore e prezzo puri, omaggio in LinePrice».

### Task 3: `ProductScope` e `Campaigns`

**Files:**
- Create: `src/Support/Promotions/ProductScope.php`, `src/Support/Promotions/Campaigns.php`, `tests/integrazione/CampaignsTest.php`
- Modify: `lang/it/gestionale.json` (`gestionale.errors.campaign`: `ends_before_starts`, `percent_out_of_range`, `amount_negative`, `scope_empty`), `tests/integrazione/supporto/compra.php` (solo se serve un aiuto per creare una campagna)

**Interfaces:**
- Consumes: `ScopeMatcher::matches`, `CampaignPrice::best`, `CategoryTree::descendants($rows, $id)`, `Gestionale::feature('discount_campaigns')`.
- Produces: `ProductScope::of(string $owner, int $ownerId): array` (il selettore di una campagna o di un coupon, nella forma di `ScopeMatcher`; `$owner` è `'campaign'` o `'coupon'`, la classe conosce i Model dei ponti di ciascuno — vedi nota); `ProductScope::facts(int $productId): array` (categorie del suo articolo **più gli antenati**, tag, `brand_id`, `model_id`); `Campaigns::status(array $row, string $now): string` (`inactive|scheduled|running|ended`); `Campaigns::running(string $now, string $channel = 'online'): list<array>`; `Campaigns::forProduct(int $productId, string $now, string $channel = 'online'): ?array` (= `CampaignPrice::best` sulle campagne in corso il cui selettore contiene il prodotto; `null` con funzionalità spenta); `Campaigns::display(int $productId, string $now): ?array`; `Campaigns::preview(array $draft, string $now, int $examples = 3): array{count: int, examples: list<array{name: string, before: string, after: string}>}`; `Campaigns::overlaps(array $draft, string $now, int $exceptId = 0): list<string>` (nomi delle campagne in conflitto); `Campaigns::validate(array $draft): void` (lancia `UserError` `campaign.*`).

Nota per il piano 2: `ProductScope` tiene una piccola mappa `owner → Model dei ponti e colonna di proprietà` (qui solo `campaign`; il piano 2 aggiunge `coupon` senza duplicare la lettura). Il test qui la prova solo sulle campagne.

- [ ] **Step 1: scrivere i test** in `CampaignsTest.php` (con `prova()`): una categoria padre con una figlia e un articolo nella figlia → la campagna sul padre lo copre (`facts` include l'antenato); `status` ai quattro stati e sul confine esatto di `starts_at` e `ends_at`; `running` rispetta `active`, canale e `deleted`; `forProduct` dà il prezzo e `campaign_id` giusti, `null` con la funzionalità spenta e con campagna scaduta; due campagne sullo stesso prodotto → sconto maggiore, a parità la più vecchia; `display` dà percentuale e `ends_at`; `preview` conta i prodotti coperti e dà fino a tre esempi prima/dopo; `overlaps` trova la campagna che si interseca nel tempo e nei prodotti, ignora quella disattivata, quella con prodotti diversi e quella che si ferma il giorno prima; `validate` rifiuta date invertite, percentuale > 100 o ≤ 0, importo ≤ 0, nessun selettore e nessun «tutto».
- [ ] **Step 2: eseguire** `php tests/integrazione/CampaignsTest.php` — atteso: rosso.
- [ ] **Step 3: implementare** `ProductScope`, `Campaigns` e le frasi di lingua.
- [ ] **Step 4: eseguire** il test, `php tests/ErrorKeysTest.php`, `php tests/run.php` — atteso: verde.
- [ ] **Step 5: commit** «Campagne di sconto: servizio delle campagne e lettura del selettore».

### Task 4: L'innesto in `Cart::recalculate`

**Files:**
- Modify: `src/Support/Orders/Cart.php` (la chiamata a `LinePrice::of`, ~righe 286–297, e la scrittura delle righe, ~314–326), `tests/integrazione/CartTest.php` (o un file nuovo `tests/integrazione/CartCampaignsTest.php` se `CartTest` diventa lungo)

**Interfaces:**
- Consumes: `Campaigns::forProduct($productId, $now, $channel)`.
- Produce: per ogni riga **madre** `product` non manuale, `campaign_price` = prezzo della campagna o stringa vuota; sulla riga si scrive `discount_campaign_id` (id o 0) accanto a `price_source`.

La chiamata si salta per le righe `manual`, per le figlie (non entrano nel ciclo) e per le righe non `product`. `$now` è `date('Y-m-d H:i:s')` letto una volta all'inizio di `recalculate`; il canale è `$cart['channel']`.

- [ ] **Step 1: scrivere i test** (con `prova()`, campagna creata con i Model, carrello con `compra.php`): una campagna 20 % in corso → riga a `price_source` `campaign`, `unit_price` scontato, `discount_campaign_id` valorizzato, `list_price` invariato, totali coerenti; campagna scaduta (o `Gestionale` con la funzionalità spenta) → al ricalcolo la riga torna a `base`/`sale_price` e `discount_campaign_id` 0; articolo con prezzo scontato → la campagna vince, con `exclude_sale_products` vince il `sale_price`; sconto di riga a mano (`discount_type` `percent`) → `price_source` `manual` e la campagna non conta; prezzo a mano → non sovrascritto; confezione con sovrapprezzo → campagna sul prezzo, sovrapprezzo pieno, figlie a `0.00`; campagna al 100 % → riga a `0.00` e totale coerente; ordine già fatto (`stage` `order`) non cambia se la campagna viene spenta dopo.
- [ ] **Step 2: eseguire** il file — atteso: rossi i nuovi.
- [ ] **Step 3: implementare** l'innesto.
- [ ] **Step 4: eseguire** il file, poi `php tests/run.php` — atteso: verde.
- [ ] **Step 5: commit** «Campagne di sconto: il carrello chiede il prezzo alla campagna».

### Task 5: Il selettore del backend e la pagina «Campagne di sconto»

**Files:**
- Create: `src/Resources/Promotions/ScopeForm.php` (trait), `src/Resources/Promotions/DiscountCampaignResource.php`, `tests/DiscountCampaignResourceTest.php`, `tests/integrazione/DiscountCampaignBackendTest.php`
- Modify: la voce di menu dell'area Promozioni dove la dichiara `navigationSchema()` (stesso posto in cui lo fa `CustomizationResource`), `lang/it/gestionale.json` se servono frasi

**Interfaces:**
- Produces: `ScopeForm::scopeFields(): list<FormField>` (un select `applies_to_all` «Tutto il catalogo / Solo la selezione», poi `categories`, `tags`, `brands`, `models`, `excluded_models` come `selectSearch(..., true)`, visibili solo con «Solo la selezione»; stesse opzioni di `ProductModelResource` per categorie con `CategoryTree::treeOptions` e per i tag); `ScopeForm::readScope(array $post): array` (forma di `ScopeMatcher`, id interi, doppioni e zeri tolti); `ScopeForm::saveScope(string $owner, int $ownerId, array $scope): void` (cancella e riscrive i ponti dentro `Transaction::run`); `ScopeForm::loadScope(string $owner, int $ownerId): array` per riempire il form.
- Produces: `DiscountCampaignResource` (`$feature = 'discount_campaigns'`, `$model = DiscountCampaign::class`, `$docsPage = 'promozioni/promozioni-campagne'`, `path()` `app/gestionale/campagne-sconto`), elenco con nome, sconto (`20 %` / `10,00 €`), periodo, canali e **stato ricavato** (`Campaigns::status`); modulo con nome, tipo e valore sulla stessa riga, date, `exclude_sale_products`, canali, selettore (`ScopeForm`), note; **bottone Anteprima** che apre una finestra col numero di prodotti e gli esempi di `Campaigns::preview` (stesso meccanismo di `Modal::form()` / `Button::post()` visto in rettifica giacenza e resi, commit `f39ea0e`); al salvataggio `Campaigns::validate` e, se `Campaigns::overlaps` non è vuoto, un **avviso** con i nomi (non blocca). Eliminare = soft delete.

La pagina segue `CustomizationResource` per struttura e `TagResource` per la gestione dei ponti; **non** ricopiare da `ProductModelResource` (file enorme): se serve un aiuto lo si estrae in `ScopeForm`.

- [ ] **Step 1: scrivere i test.** `DiscountCampaignResourceTest` (senza database): il modulo ha i campi attesi, il selettore è nascosto con «Tutto il catalogo», `labelSchema()` e il menu sotto Promozioni, la pagina sparisce con la funzionalità spenta. `DiscountCampaignBackendTest` (con `prova()`): salvare una campagna scrive riga e ponti; modificare la selezione riscrive i ponti senza orfani; date invertite, percentuale 120, importo negativo, selettore vuoto senza «tutto» → `UserError` e **nessuna riga** né ponte scritti; l'avviso di sovrapposizione compare e il salvataggio riesce; l'anteprima da bozza non scrive nulla; l'elenco mostra lo stato giusto; un id che non esiste o una richiesta con tipo cambiato a mano non rompono la pagina.
- [ ] **Step 2: eseguire** i due file — atteso: rossi.
- [ ] **Step 3: implementare** `ScopeForm` e la Resource.
- [ ] **Step 4: eseguire** i due file, poi `php tests/run.php` — atteso: verde.
- [ ] **Step 5: commit** «Campagne di sconto: selettore dei prodotti e pagina del backend».

### Task 6: Dati di prova, guide, chiusura

**Files:**
- Create: `src/Seeding/PromotionsDemo.php`, `docs/user/promozioni/promozioni-campagne.md`, `docs/dev/concetti/promozioni.md`, `tests/integrazione/PromotionsDemoTest.php`
- Modify: `src/Seeding/Demo.php` (aggiungere `PromotionsDemo::class` **dopo** `CatalogDemo` e **prima** di `OrdersDemo`), `gitbook-docs.yaml` e l'indice delle guide dove stanno le altre pagine, `CHANGELOG.md`, `TODO.md`, `tests/DocsPagesTest.php` se elenca le pagine

**Interfaces:**
- Produces: `PromotionsDemo::register()` (come le altre classi di `Demo`, riconoscibili e rimovibili con `gestionale:demo --clear`): tre campagne di prova — una **in corso** (20 % su una categoria del catalogo di prova), una **programmata** (importo su un tag, parte tra qualche giorno), una **terminata** (10 % sul marchio, finita il mese scorso); eliminarle non lascia ponti orfani.

- [ ] **Step 1: scrivere il test** `PromotionsDemoTest.php`: dopo `register()` le tre campagne esistono con gli stati `running`, `scheduled`, `ended`; `Campaigns::forProduct` dà un prezzo su un articolo della categoria e `null` su uno fuori; `--clear` le toglie con i ponti; due `register()` di fila non duplicano.
- [ ] **Step 2: eseguire** — atteso: rosso.
- [ ] **Step 3: implementare** la classe e registrarla; scrivere le due guide (utente: cos'è una campagna, i quattro stati, l'anteprima, l'avviso di sovrapposizione, cosa vince quando ce n'è più d'una e il caso dell'omaggio; sviluppatori: `Campaigns::display` per la vetrina, `forProduct`, l'innesto in `Cart::recalculate`, come aggiungere una sorgente di prezzo); voce nel `CHANGELOG.md`; in `TODO.md` G6 → «piano 1 (Campagne) fatto», le decisioni della spec e il piano 2 da fare.
- [ ] **Step 4: eseguire** `php tests/run.php` per intero — atteso: verde. Lanciare `php forge gestionale:demo` sul sito di prova e guardare le tre campagne nell'elenco.
- [ ] **Step 5: commit** «Campagne di sconto: dati di prova, guide e chiusura del piano 1».

### Task 7: Prova nel browser (serve il tuo accesso)

Con il sito di prova avviato e l'utente collegato al backend: (1) creare una campagna dal modulo, provare **Anteprima** e l'avviso di sovrapposizione; (2) aprire il carrello di prova con un articolo coperto e vedere il prezzo scontato e `discount_campaign_id` sulla riga; (3) cambiare `ends_at` nel passato e ricalcolare: il prezzo torna normale; (4) elenco con i quattro stati; (5) funzionalità spenta dalle impostazioni: voce di menu sparita, carrello a prezzo pieno. Riportare cosa si è visto; niente merge senza il via dell'utente.
