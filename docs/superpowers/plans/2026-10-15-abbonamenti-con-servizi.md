# G10b — Abbonamenti con più servizi: piano di lavoro

> **Per chi esegue:** si usa superpowers:executing-plans (metodo nativo: lavoro in
> questa sessione, revisione finale di tutto il ramo). I passi sono caselle (`- [ ]`).

**Obiettivo:** un abbonamento diventa un contenitore di righe (dominio, hosting,
assistenza…), con personalizzazioni condivise col catalogo, righe legate fra loro,
aggiunte, tolte e cambi con conguaglio a scelta, sia da ufficio sia online (un
solo Stripe Checkout con più righe).

**Architettura:** `gst_subscription_items` porta il prezzo, la quantità e le
personalizzazioni; `gst_subscriptions` tiene cliente, date, pagamento e totali del
periodo. Un servizio nuovo, `SubscriptionItems`, decide le regole delle operazioni
sulle righe e passa al canale: `OfficeSubscriptions` (rate e proforma) o
`OnlineSubscriptions` (Stripe). Calcoli puri a parte (`PlanPriceRules`,
`SubscriptionAmounts`, `SubscriptionProration`, `PlanFeatureMerge`), provati
senza database.

**Tecnologie:** PHP 8.3, Wonder Image (`Model::tableSchema()`/`dataSchema()`,
Resource, `FormField`, `Card`), MySQL via forge update, Stripe API
`2025-08-27.basil` (billing mode *flexible*, Subscription Items, Subscription
Schedules), ecommerce Wonder.

**Spec:** [`docs/superpowers/specs/2026-10-15-abbonamenti-con-servizi-design.md`](../specs/2026-10-15-abbonamenti-con-servizi-design.md)
(decisioni G10b.1–G10b.16). Chi esegue legge spec e piano insieme.

## Vincoli di tutto il lavoro

- Tutto dietro le funzionalità `subscriptions` e `online_subscriptions`, spente di
  serie: la consegna del 29/10 non cambia (G10b.16).
- Il gestionale è `0.1.0`, non rilasciato: niente migrazioni per dati veri; le
  tabelle le crea forge update; i dati di prova si spostano con uno script.
- Prima di ogni `php forge update` sul sito di prova: esportare o confrontare il
  sync (forge update importa il sync e sovrascrive ciò che è acceso dal backend).
- Sito di prova proprio, `ecommerce-site-servizi`, con un **database suo**: suite di
  worktree diversi sullo stesso DB danno «SAVEPOINT wi_sp_1 does not exist».
- `WONDER_NO_MAIL` acceso in ogni harness: il DB di prova ha indirizzi veri.
- Ogni input passa da `FormField` / `RepeaterColumn`; i riquadri sono `Card`, mai
  `<wi-card>`. Form compatti: tooltip al posto dei testi d'aiuto, campi correlati
  vicini.
- Mai toccare `vendor/`. Una mancanza del core si segnala, non si corregge lì.
- Lavoro in worktree (`../worktrees/abbonamenti-con-servizi`, e
  `../worktrees/abbonamenti-con-servizi-ecommerce` per la parte 3): mai cambiare
  ramo nella cartella condivisa.
- Niente push né merge senza il via dell'utente; l'unione è una PR con merge
  commit, poi si eliminano ramo remoto, ramo locale e worktree.
- Errori per l'utente: `UserError::make('chiave', [...])`, con il testo in
  `lang/it/gestionale.json`; ogni chiave nuova entra in `ErrorKeysTest`.
- Commit piccoli, in italiano, chiusi da
  `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Testi in italiano, nel tono delle guide; numeri e date all'italiana.

## Da guardare in revisione

Casi che la spec implica e che nessun test di task proverebbe da solo; ognuno ha
il suo test nel task indicato.

1. **Due righe con lo stesso prezzo nello stesso abbonamento** (due hosting uguali
   su due domini): Stripe rifiuta lo stesso prezzo due volte. Ci si aspetta due
   prezzi Stripe distinti, uno per riga → test in Task 15 (`slot`).
2. **Togliere la riga madre** mentre ha figlie in corso: le figlie vanno via
   insieme, con la stessa scelta; mai una figlia orfana in corso → Task 10.
3. **Credito più grande del periodo dopo** (tolgo subito un annuale e resta un
   mensile): il proforma non va mai sotto zero e il credito avanzato passa al
   periodo dopo; se l'abbonamento finisce, il resto va in *Da controllare* →
   Task 11.
4. **Aggiunta di una riga annuale a un abbonamento mensile a metà mese**: la riga
   annuale rinnova alle date dell'abbonamento (stesso giorno), la quota pro-rata
   va fino alla fine del **suo** periodo, non di quello mensile → Task 8 e Task 10.
5. **Limite di abbonamenti con un `pending` vecchio** (un Checkout abbandonato): il
   `pending` conta, quindi chi abbandona il pagamento resta bloccato; un `pending`
   online più vecchio di 24 ore non deve contare → Task 7 (regola) e Task 16
   (online).

---

## Mappa dei file

**Nuovi (gestionale):**

| File | Compito |
|---|---|
| `src/Models/Catalog/CustomizationLink.php` | tabella `gst_customization_links` (sostituisce `ProductModelCustomization`) |
| `src/Models/Subscriptions/PlanLink.php` | tabella `gst_plan_links` |
| `src/Models/Subscriptions/SubscriptionItem.php` | tabella `gst_subscription_items` |
| `src/Models/Subscriptions/SubscriptionItemChange.php` | tabella `gst_subscription_item_changes` (sostituisce `SubscriptionPlanChange`) |
| `src/Support/Subscriptions/SubscriptionProration.php` | date di periodo per riga, quote pro-rata, righe addebitate nel periodo (puro) |
| `src/Support/Subscriptions/PlanFeatureMerge.php` | somma dei limiti dei piani (puro) |
| `src/Support/Subscriptions/PlanLinks.php` | legami fra piani: lettura e regole |
| `src/Support/Subscriptions/SubscriptionItems.php` | regole e operazioni sulle righe (aggiungi, togli, cambia, correggi) |
| `tests/SubscriptionProrationTest.php`, `tests/PlanFeatureMergeTest.php`, `tests/CustomizationLinkRulesTest.php` | unitari |
| `tests/integrazione/SubscriptionItemsTest.php` | integrazione delle operazioni da ufficio |
| `tests/supporto/migra-abbonamenti-con-servizi.php` | sposta i dati di prova nei legami e nelle righe |

**Cambiati (gestionale):** `config/features.php`, `src/Support/Features/*`,
`src/Models/Catalog/Customization.php`, `src/Support/Catalog/Customizations.php`,
`src/Resources/Catalog/CustomizationResource.php`,
`src/Resources/Catalog/ProductModelResource.php`, `src/Seeding/*Demo.php`,
`src/Models/Subscriptions/Subscription.php`, `src/Models/System/Setting.php`,
`src/Resources/System/SettingResource.php`,
`src/Support/Subscriptions/{PlanPriceRules,SubscriptionAmounts,Subscriptions,OfficeSubscriptions,OnlineSubscriptions,SubscriptionEvents,StripeCatalog,StripeSubscriptionData,SubscriptionNotifier}.php`,
`src/Support/Documents/DocumentData.php`,
`src/Resources/Subscriptions/{PlanResource,SubscriptionResource,SubscriptionActionResource,OfficeSubscriptionResource}.php`,
`src/Providers/Payments/{SubscriptionGateway,StripeBilling}.php`,
`tests/supporto/FakeSubscriptionGateway.php`, `lang/it/gestionale.json`, guide in
`docs/user/`, `CHANGELOG.md`, `TODO.md`.

**Ecommerce (parte 3):** `src/Frontend/Subscriptions/{SubscriptionSelection (nuovo),SubscriptionsController,PlanCatalog,SubscriptionTracking,SubscriptionView}.php`,
`src/Frontend/Account/{AccountSubscriptions,EcommerceAccountController}.php`, le
viste e `lang/it/*.json` dell'ecommerce, `tests/SubscriptionsPageTest.php`,
`tests/SubscriptionSelectionTest.php` (nuovo).

---

# Parte 1 — Dati e ufficio

### Task 0: worktree e sito di prova

- [ ] Creare il worktree dal `main` locale (che ha la spec 8ae4f35):
  `git worktree add ../worktrees/abbonamenti-con-servizi -b abbonamenti-con-servizi main`.
- [ ] Copiare il piano nel worktree e committarlo lì (`docs: piano G10b`).
- [ ] Sito di prova: copiare `ecommerce-site` in `ecommerce-site-servizi`, creare il
  database `ecommerce_site_servizi` (dump di `ecommerce_site`), puntare `.env` e il
  `composer.json` (path repository) al worktree; `composer update
  wonder-image/gestionale`.
- [ ] Esportare il sync del sito nuovo prima del primo `php forge update --local`.
- [ ] Lanciare `php tests/run.php` nel worktree: la suite parte verde (base).

### Task 1: «Richiede Ordini oppure Abbonamenti» (`requires_any`)

**File:**
- Cambia: `config/features.php:51-57` (`customizations`: `'requires' => []`,
  `'requires_any' => ['orders', 'subscriptions']`)
- Cambia: `src/Support/Features/FeatureCatalog.php` (normalizza e controlla
  `requires_any`), `FeatureState.php` (attiva solo se ne ha una accesa),
  `FeatureRules.php` (sblocca e blocca), `FeaturePanel.php:36` (etichetta)
- Test: `tests/FeatureRulesTest.php`, `tests/FeatureCatalogTest.php`,
  `tests/FeatureStateTest.php`

**Interfacce:**
- Produce: ogni voce del catalogo ha `requires_any: list<string>` (vuota di
  serie). `FeaturePanel::byArea()` aggiunge alle `requires` una stringa «Ordini o
  Abbonamenti» (nomi uniti con « o »).

**Regole:**
- `FeatureCatalog::fromArray()` normalizza `requires_any` come `requires`;
  `assertValid()` rifiuta chiavi sconosciute e cicli anche attraverso
  `requires_any`.
- `FeatureState::active()`: una funzionalità con `requires_any` non vuota è
  attiva solo se almeno una di quelle è attiva (oltre alle `requires`).
- `FeatureRules::apply()`:
  - accendere una funzionalità con `requires_any` e nessuna accesa accende la
    **prima** della lista;
  - spegnere una funzionalità spegne un dipendente via `requires_any` **solo se**
    non resta accesa un'altra della lista;
  - la chiusura finale ricontrolla le `requires_any` come le `requires`.
- `dependencies()`/`dependents()` includono le `requires_any`.

- [ ] **Test che falliscono** (FeatureRulesTest):
  - accendere `customizations` con tutto spento accende `orders` (la prima);
  - con `subscriptions` accesa e `orders` spenta, accendere `customizations` non
    accende `orders`;
  - con `orders` e `subscriptions` accese, spegnere `orders` lascia accesa
    `customizations`; spegnere poi `subscriptions` la spegne;
  - FeatureCatalogTest: `requires_any` con una chiave che non esiste è rifiutata;
  - FeatureStateTest: `customizations` sbloccata con `orders` spenta e
    `subscriptions` accesa è attiva; con entrambe spente no.
- [ ] Lanciarli: falliscono.
- [ ] Implementare le regole sopra.
- [ ] `php tests/FeatureRulesTest.php && php tests/FeatureCatalogTest.php && php tests/FeatureStateTest.php && php tests/FeatureGateTest.php`: verdi.
- [ ] Commit `Funzionalità: requires_any, Personalizzazione con Ordini o Abbonamenti`.

### Task 2: legami delle personalizzazioni condivisi e interruttori

**File:**
- Crea: `src/Models/Catalog/CustomizationLink.php`; elimina
  `src/Models/Catalog/ProductModelCustomization.php`
- Cambia: `src/Models/Catalog/Customization.php` (`for_products`,
  `for_subscriptions`), `src/Resources/Catalog/CustomizationResource.php`
  (interruttori, controlli, conta dei legami), `ProductModelResource.php` (legge
  e scrive `CustomizationLink`, propone solo `for_products`),
  `src/Support/Catalog/Customizations.php` (helper comune + `forPlan()`),
  `src/Seeding/CatalogDemo.php`, `src/Seeding/ShowcaseDemo.php`, ogni altro uso di
  `ProductModelCustomization` (`grep -rn ProductModelCustomization src tests`)
- Test: `tests/CustomizationLinkRulesTest.php` (nuovo),
  `tests/CustomizationModelsTest.php`, `tests/CustomizationResourceTest.php`,
  `tests/CustomizationsTest.php`, `tests/integrazione/ProductModelCustomizationsTest.php`,
  `tests/integrazione/CustomizationResourceTest.php`, `tests/integrazione/CartTest.php`,
  `tests/integrazione/supporto/compra.php`, `tests/integrazione/CatalogDemoTest.php`

**Interfacce:**
- `CustomizationLink::$table = 'gst_customization_links'`; colonne
  `product_model_id` int **null** foreign `ProductModel::$table`, `plan_id` int
  **null** foreign `Plan::$table`, `customization_id` int not null foreign,
  `is_required` enum default `false`, `surcharge` decimal 12,2, `position`;
  pseudo `ind_model`, `ind_plan`, `ind_customization`.
- `CustomizationLink::assertTarget(array $values): void` — esattamente uno tra
  `product_model_id` e `plan_id` > 0, altrimenti
  `UserError 'customization.link_target'`. La chiamano ProductModelResource,
  PlanResource e i dati di prova prima di scrivere.
- `Customization`: colonne `for_products` enum default `true`,
  `for_subscriptions` enum default `false`.
- `CustomizationResource::assertScopes(array $values, int $products, int $plans): void`
  (pura): nessuno dei due → `customization.scope_required`; `for_products`
  spento con `$products > 0` → `customization.linked_products` (`count`);
  `for_subscriptions` spento con `$plans > 0` → `customization.linked_plans`.
- `Customizations::forPlan(int $planId): list<array>` — stessa forma di
  `forModel()` (id, name, label, help_text, kind, max_length, decimals,
  surcharge, required, options); legge i legami per `plan_id` e solo
  personalizzazioni con `for_subscriptions = true`. `forModel()` filtra
  `for_products = true`. Il corpo comune è `private static function linked(string $column, int $id, string $scope): array`.
- `Customizations::resolvePlan(int $planId, array $values): array` — come
  `resolve()` ma su `forPlan()`; ritorna `{fields, surcharge}` di `check()`.

**Regole:**
- Nel form della personalizzazione gli interruttori *Per i prodotti* (visibile con
  `Gestionale::feature('orders')`) e *Per gli abbonamenti* (visibile con
  `subscriptions`); un interruttore nascosto mantiene il valore salvato.
- L'eliminazione di una personalizzazione in uso conta **tutti** i legami (articoli
  e piani).
- La tabella vecchia non si tocca: la sposta lo script (Task 12).

- [ ] **Test che falliscono:**
  - CustomizationLinkRulesTest: articolo solo → passa; piano solo → passa;
    entrambi → `customization.link_target`; nessuno → `customization.link_target`;
  - `assertScopes`: entrambi spenti → `scope_required`; spengo *prodotti* con 2
    legami → `linked_products` con `count` 2; spengo *abbonamenti* con 1 piano →
    `linked_plans`; spengo con 0 legami → passa;
  - CustomizationModelsTest: `CustomizationLink::tableSchema()` ha `plan_id`
    foreign su `gst_plans` e `product_model_id` nullable; `Customization` ha i due
    interruttori coi default;
  - integrazione ProductModelCustomizationsTest: un legame scritto da
    ProductModelResource finisce in `gst_customization_links`; `forModel()` non
    restituisce una personalizzazione con `for_products = false`;
  - integrazione: `forPlan()` su un piano con un legame restituisce la
    personalizzazione con `required` e `surcharge` del legame.
- [ ] Lanciarli: falliscono.
- [ ] Implementare; rinominare ogni uso (`grep -rn "ProductModelCustomization\|gst_product_model_customizations" src tests docs/user`).
- [ ] Le chiavi nuove in `lang/it/gestionale.json` e in `ErrorKeysTest`.
- [ ] `php -l` sui file toccati; `composer dump-autoload`; esportare il sync;
  `php forge update --local` sul sito di prova; suite unitaria e di integrazione
  delle personalizzazioni verdi.
- [ ] Commit `Personalizzazioni: legami condivisi con i piani e interruttori`.

### Task 3: legami fra piani e personalizzazioni del piano (scheda del piano)

**File:**
- Crea: `src/Models/Subscriptions/PlanLink.php`, `src/Support/Subscriptions/PlanLinks.php`
- Cambia: `src/Resources/Subscriptions/PlanResource.php` (formSchema 101,
  formLayoutSchema 171, mutateRequestValues 301, prepareRepeaterRows 366,
  assertDeletable 418)
- Test: `tests/integrazione/PlansTest.php`, `tests/SubscriptionModelsTest.php`

**Interfacce:**
- `PlanLink::$table = 'gst_plan_links'`: `plan_id` foreign `Plan::$table`,
  `parent_plan_id` foreign `Plan::$table`; pseudo `ind_plan`, `ind_parent`;
  `syncSchema()` come `Plan` (si sincronizza con il catalogo).
- `PlanLinks::parentsOf(int $planId): list<int>` — i piani a cui una riga deve
  appoggiarsi (vuota = nessun legame).
- `PlanLinks::assert(int $planId, list<int> $parents, array<int, list<int>> $existing): void`
  (pura; `$existing` = piano → madri, dal DB):
  - madre uguale al piano → `plan.link_self`;
  - una madre che ha a sua volta madri, o il piano che è già madre di altri e
    riceve madri → `plan.link_depth` (un livello solo).
- `PlanResource` aggiunge due campi:
  - `parent_plans` `FormField::select(...)->multiple()` «Si lega a» (tooltip:
    «Una riga di questo piano si appoggia a una riga di uno di questi piani nello
    stesso abbonamento, es. Hosting → Dominio»);
  - repeater `customizations` (come in ProductModelResource: personalizzazione,
    obbligatoria, sovrapprezzo, posizione) con le sole `for_subscriptions`,
    visibile con la funzionalità `customizations` attiva.
- `assertDeletable()` di PlanResource: un piano madre di un altro piano con righe
  in corso si elimina solo dopo aver tolto il legame (`plan.has_children`).

- [ ] **Test che falliscono** (PlansTest): salvo «Hosting» con «Si lega a:
  Dominio» → una riga `gst_plan_links`; `parentsOf(hosting)` = `[dominio]`;
  «Dominio» che si lega a sé → `plan.link_self`; «Hosting» madre di «Backup» e
  figlio di «Dominio» → `plan.link_depth`; il repeater salva le personalizzazioni
  in `gst_customization_links` con `plan_id`; il form non propone le
  personalizzazioni senza `for_subscriptions`.
- [ ] Lanciarli: falliscono. Implementare. Chiavi in lang ed ErrorKeysTest.
- [ ] Esportare il sync, `forge update`, test verdi.
- [ ] Commit `Piani: «Si lega a» e personalizzazioni del piano`.

### Task 4: righe dell'abbonamento, storia delle righe, `plan_price_id` tolto

**File:**
- Crea: `src/Models/Subscriptions/SubscriptionItem.php`,
  `src/Models/Subscriptions/SubscriptionItemChange.php`; elimina
  `SubscriptionPlanChange.php`
- Cambia: `src/Models/Subscriptions/Subscription.php:59,93,104` (via
  `plan_price_id` e il suo indice), tutti gli usi di `plan_price_id` di un
  abbonamento (elenco: OfficeSubscriptions 52/63/91/145/182/223/271/283,
  OnlineSubscriptions, SubscriptionEvents 94/109/119/120, StripeCatalog:206,
  SubscriptionNotifier:40, CustomerSheet:232, SubscriptionResource
  87/135/137/224/335/336/418, SubscriptionActionResource 94/110,
  OfficeSubscriptionResource, OfficeSubscriptionsDemo:78, PlansDemo,
  `Subscriptions::liveCount()`:69 e `usedPrices()`). In questo task gli usi si
  spostano sulle righe **senza** cambiare il comportamento: un abbonamento creato
  con un prezzo ha una riga; i task dopo aggiungono il resto.
- Test: `tests/SubscriptionModelsTest.php`, `tests/CustomerSheetTest.php`,
  `tests/StripeCatalogTest.php`, `tests/supporto/adotta-prove-del-browser.php`,
  integrazione `PlansTest`, `OnlineSubscriptionsTest`, `OfficeSubscriptionsTest`

**Interfacce:**
- `SubscriptionItem::$table = 'gst_subscription_items'`;
  `STATUSES = ['pending','active','ending','ended']`; `LIVE = ['active','ending']`;
  colonne: `subscription_id` foreign, `plan_price_id` foreign `PlanPrice::$table`,
  `parent_item_id` int default 0, `quantity` int default 1, `status` enum default
  `pending`, `customization` TEXT (JSON codificato con
  `Customizations::encode()`), `unit_price`, `customization_total`, `subtotal`,
  `tax_total`, `total` decimal 12,2 (periodo pieno, senza coupon), `tax_id` int,
  `tax_rate` decimal 5,2, `starts_at`, `commitment_ends_at`, `ends_at` datetime,
  `provider_reference` (`si_…`), `provider_price` (`price_…`), `position`;
  pseudo `ind_subscription`, `ind_plan_price`, `ind_parent`,
  `ind_provider_reference`; `syncSchema()` null.
- `SubscriptionItemChange::$table = 'gst_subscription_item_changes'`;
  `ACTIONS = ['added','removed','changed','customized']`;
  `BILLINGS = ['prorate','free','next_period','period_end','credit','']`;
  colonne `subscription_id`, `item_id`, `action`, `old_plan_price_id`,
  `new_plan_price_id`, `billing`, `amount` decimal 12,2, `credit_left` decimal
  12,2 (credito ancora da usare; 0 se non è un credito), `effective_at`,
  `user_id`, `source`, `changed_at`, `note` TEXT; `syncSchema()` null.
- `SubscriptionItems::forSubscription(int $subscriptionId, ?array $statuses = null): list<array>`
  (in `SubscriptionItems.php`, nato qui con solo le letture) — righe ordinate per
  `position`.
- `SubscriptionItems::label(array $item): string` — «Piano - Prezzo» più, se ci
  sono, le personalizzazioni (`Customizations::lines()` unite con « · »).
- `Subscriptions::liveCount(?array $priceIds)` e `usedPrices()` contano le
  **righe** in `SubscriptionItem::LIVE` di abbonamenti in `Subscription::LIVE`.

- [ ] **Test che falliscono:** SubscriptionModelsTest: `Subscription::tableSchema()`
  non ha `plan_price_id`; `SubscriptionItem` ha le colonne e gli stati sopra;
  `SubscriptionItemChange` ha azioni e scelte sopra; le prove esistenti di
  OfficeSubscriptionsTest e OnlineSubscriptionsTest riscritte per leggere il
  prezzo dalla riga (`SubscriptionItems::forSubscription($id)[0]['plan_price_id']`).
- [ ] Lanciarli: falliscono.
- [ ] Implementare lo spostamento (nessun comportamento nuovo). `changePlan()` resta
  per ora, ma scrive `SubscriptionItemChange` (`action = changed`, `billing =
  next_period`) e cambia la riga.
- [ ] Esportare il sync, `forge update`, suite completa verde.
- [ ] Commit `Abbonamenti: righe e storia delle righe al posto di plan_price_id`.

### Task 5: impostazione «Abbonamenti in corso per cliente»

**File:**
- Cambia: `src/Resources/System/SettingResource.php` (etichetta ~86, FormField
  ~147, layout ~250 accanto a `subscription_unpaid_days`, `localOnly` ~308,
  errore ~373, normalizzazione ~466), `src/Models/System/Setting.php:39`
  (exclude del sync), `src/Support/Subscriptions/Subscriptions.php`
- Test: `tests/SettingsTest.php`, `tests/SettingsSectionsTest.php`,
  integrazione `OfficeSubscriptionsTest`

**Interfacce:**
- Colonna `subscriptions_per_customer` int default 1 in `Setting`.
- `Subscriptions::perCustomer(): int` — l'impostazione (≥ 0; 0 = senza limite;
  senza riga 1).
- `Subscriptions::assertRoom(int $customerId): void` — conta gli abbonamenti del
  cliente in `Subscription::LIVE` più i `pending` (esclusi i `pending` online
  creati da più di 24 ore: un Checkout abbandonato non blocca); al limite
  `UserError::make('subscription.limit_reached', ['code' => <codice del più recente>])`.
- `Subscriptions::countsFor(list<array> $rows, DateTimeImmutable $now): int`
  (pura, la usa `assertRoom()`).

- [ ] **Test che falliscono:** SettingsTest: valore negativo →
  `settings.subscriptions_per_customer_invalid`; vuoto → 1; `countsFor()` conta
  `active`, `trialing`, `past_due`, `pending` di ufficio, `pending` online di 2
  ore, e **non** conta `pending` online di 30 ore, `unpaid`, `cancelled`.
- [ ] Implementare. Chiavi in lang ed ErrorKeysTest.
- [ ] Commit `Impostazioni: abbonamenti in corso per cliente`.

### Task 6: regola degli intervalli e importi a N righe

**File:**
- Cambia: `src/Support/Subscriptions/PlanPriceRules.php`,
  `src/Support/Subscriptions/SubscriptionAmounts.php`
- Test: `tests/PlanPriceRulesTest.php`, `tests/SubscriptionAmountsTest.php`

**Interfacce:**
- `PlanPriceRules::assertIntervals(list<int> $terms): void` — ogni `term_months`
  è multiplo del più piccolo, altrimenti
  `UserError::make('subscription.interval_mismatch')`. Lista vuota o di un solo
  elemento passa.
- `SubscriptionAmounts::ofItems(list<array{price: array, quantity: int, surcharge: float, tax_rate: float}> $lines, ?array $coupon, bool $pricesIncludeTax, int $charge, int $termMonths): array`
  — ogni riga è `LinePrice::of(['quantity', 'price', 'sale_price',
  'customization_surcharge'])` con la sua aliquota; i totali da `OrderTotals::of()`
  con lo stesso contesto di oggi; il coupon vale se
  `couponApplies($coupon, $charge, $termMonths)` (term = il più corto
  dell'abbonamento). Ritorna `subtotal`, `discount_total`, `taxable_total`,
  `tax_total`, `total`, `coupon_applied`, `tax_summaries` (di OrderTotals) e
  `lines` (per riga: `unit_price`, `customization_total` = surcharge × quantity,
  `line_total`, `tax_rate`).
- `SubscriptionAmounts::of()` resta e chiama `ofItems()` con una riga
  (quantity 1, surcharge 0): i chiamanti di oggi non cambiano.

- [ ] **Test che falliscono:**
  - PlanPriceRulesTest: `[1, 3, 12]` passa; `[2, 3]` → `interval_mismatch`;
    `[12, 1]` passa (ordine qualsiasi); `[12, 24]` passa; `[]` e `[6]` passano;
  - SubscriptionAmountsTest: due righe (dominio 12,00 annuale al 22% +
    sovrapprezzo 2,00 × 1; hosting 60,00 annuale × 2 al 22%) prezzi IVA inclusa →
    totale 134,00, imposta = somma delle due righe come OrderTotals; due aliquote
    (22% e 4%) → due `tax_summaries`; coupon 10% `forever` su due righe →
    `discount_total` sul totale delle righe; coupon `once` con `$charge = 1` non
    vale; `of()` dà gli stessi numeri di prima su una riga (le prove esistenti
    restano verdi).
- [ ] Implementare.
- [ ] Commit `Abbonamenti: regola degli intervalli e importi a più righe`.

### Task 7: limiti dei piani sommati

**File:**
- Crea: `src/Support/Subscriptions/PlanFeatureMerge.php`
- Cambia: `src/Support/Subscriptions/Subscriptions.php` (`limit()` 47)
- Test: `tests/PlanFeatureMergeTest.php`, integrazione `PlansTest`

**Interfacce:**
- `PlanFeatureMerge::merge(list<array{value: string, quantity: int, at: string}> $values): ?string`
  — lista vuota → null; un valore «illimitato» (confronto senza maiuscole, anche
  `unlimited`) vince; tutti numerici (`is_numeric`) → somma di `value × quantity`
  (intera se tutti interi); altrimenti il valore con `at` più recente.
- `Subscriptions::limit(int $customerId, string $key): ?string` — legge **tutte**
  le righe `SubscriptionItem::LIVE` degli abbonamenti `Subscription::LIVE` del
  cliente, il `PlanFeature` del piano di ogni riga (con `at` = `starts_at` della
  riga) e passa a `merge()`.
- `Subscriptions::live()` resta (l'abbonamento più recente), lo usa ancora l'area
  cliente.

- [ ] **Test che falliscono** (PlanFeatureMergeTest): `[5×1, 3×2]` → `"11"`;
  `[5, illimitato]` → `"illimitato"`; `["Base" at 2026-01, "Pro" at 2026-05]` →
  `"Pro"`; `[1.5×2]` → `"3"`; `[]` → null. PlansTest: cliente con due righe di
  piani che danno `progetti = 5` e `progetti = 10` → `limit()` = `"15"`.
- [ ] Implementare. Commit `Abbonamenti: limiti sommati su tutte le righe`.

### Task 8: date di periodo per riga e pro-rata

**File:**
- Crea: `src/Support/Subscriptions/SubscriptionProration.php`
- Test: `tests/SubscriptionProrationTest.php`

**Interfacce (tutte pure, date `DateTimeImmutable` a mezzanotte):**
- `monthsBetween(DateTimeImmutable $from, DateTimeImmutable $to): int` — quella di
  OfficeSubscriptions:482, spostata qui e usata da lì.
- `charges(DateTimeImmutable $anchor, int $term, DateTimeImmutable $periodStart): bool`
  — la riga con quell'intervallo si addebita nel periodo che parte in
  `$periodStart` se `monthsBetween($anchor, $periodStart) % $term === 0`.
- `chargeIndex(DateTimeImmutable $anchor, int $term, DateTimeImmutable $periodStart): int`
  — `intdiv(monthsBetween(...), $term)`.
- `rowPeriod(DateTimeImmutable $anchor, int $term, DateTimeImmutable $at): array{0: DateTimeImmutable, 1: DateTimeImmutable}`
  — inizio e fine del periodo della riga che contiene `$at` (fine esclusa),
  contando `+n*term months` dall'ancora.
- `quota(float $amount, DateTimeImmutable $effective, DateTimeImmutable $start, DateTimeImmutable $end): float`
  — `round($amount × giorni($effective, $end) / giorni($start, $end), 2)`; 0 se
  `$effective >= $end`; `$amount` se `$effective <= $start`.

- [ ] **Test che falliscono:**
  - ancora 2026-01-15, term 1: `charges` vero il 2026-03-15; term 12: falso il
    2026-03-15, vero il 2027-01-15; `chargeIndex(…, 12, 2028-01-15)` = 2;
  - `rowPeriod(2026-01-15, 12, 2026-06-01)` = [2026-01-15, 2027-01-15];
    `rowPeriod(2026-01-31, 1, 2026-02-10)` = [2026-01-31, 2026-02-28] e
    `rowPeriod(2026-01-31, 1, 2026-03-05)` = [2026-02-28, 2026-03-31]: i mesi si
    aggiungono **sempre dall'ancora** (`+n months`) e, se il giorno dell'ancora
    non esiste nel mese, si prende l'ultimo giorno del mese (mai lo sconfinamento
    di `modify('+1 month')` al 3 marzo);
  - `quota(120.00, 2026-07-15, 2026-01-15, 2027-01-15)` = 60,49 (184/365);
    `quota(10, start, start, end)` = 10; `quota(10, end, start, end)` = 0.
- [ ] Implementare (un helper privato `addMonths()` con la regola dell'ultimo
  giorno, usato anche da OfficeSubscriptions al posto di `modify('+N months')`).
- [ ] Commit `Abbonamenti: date di periodo per riga e pro-rata`.

### Task 9: «Nuovo abbonamento» da ufficio con le righe

**File:**
- Cambia: `src/Support/Subscriptions/OfficeSubscriptions.php` (`create()`,
  `openPeriod()`, `proforma()`, `planLabel()`), `src/Support/Subscriptions/SubscriptionItems.php`,
  `src/Support/Documents/DocumentData.php:91` (`forSubscription()`),
  `src/Resources/Subscriptions/OfficeSubscriptionResource.php`
- Test: integrazione `OfficeSubscriptionsTest`, `tests/SubscriptionPagesTest.php`,
  `tests/DocumentPdfTest.php`

**Interfacce:**
- `OfficeSubscriptions::create(array $input): int` — `$input['items']` è
  `list<array{plan_price_id: int, quantity?: int, customization?: array<int|string, mixed>, parent?: int}>`
  (`parent` = indice di un'altra riga della stessa lista, -1 se nessuna);
  `plan_price_id` in alto non c'è più.
- `SubscriptionItems::prepare(list<array> $items, array $billing, int $termMinimo = 0): list<array>`
  — per ogni riga: prezzo attivo di piano attivo (`subscription.price_unavailable`),
  `Customizations::resolvePlan()` (errori di `check()` con `withField('items')`),
  aliquota con `OnlineSubscriptions::taxFor()` sul `tax_category_id` del piano,
  `quantity ≥ 1`, madre obbligatoria se `PlanLinks::parentsOf()` non è vuota
  (`subscription.parent_required`) e madre di uno dei piani ammessi, nessuna
  catena (la madre non ha madre); `PlanPriceRules::assertIntervals()` su tutti i
  `term_months`. Ritorna le righe pronte da scrivere (importi del periodo pieno
  da `SubscriptionAmounts::ofItems()` senza coupon).
- `create()`: `Subscriptions::assertRoom()`; prova = la più lunga fra i prezzi;
  vincolo di ogni riga dal suo prezzo, `commitment_ends_at` dell'abbonamento = il
  più lontano; righe scritte `active` (o `pending` se l'abbonamento è in prova:
  diventano `active` all'apertura del primo periodo); `parent_item_id` risolto
  dopo l'inserimento; una `SubscriptionItemChange` `added` per riga (`billing`
  vuota, `source` `user`).
- `openPeriod()`: periodo = `term_months` più corto fra le righe in corso;
  ancora = `trial_ends_at ?: started_at`; righe addebitate =
  `SubscriptionProration::charges()`; `charge` del coupon =
  `chargeIndex(ancora, term più corto, inizio)`; totali dell'abbonamento =
  `ofItems()` delle righe addebitate; crediti aperti sottratti (Task 11); rate su
  quello che resta.
- `DocumentData::forSubscription(array $subscription, list<array{name: string, quantity: int, unit_price: float, line_total: float, tax_rate: float}> $lines, list<array{name: string, amount: float}> $credits, string $periodStart, string $periodEnd, array $installments): array`
  — una voce per riga, una voce di sconto (`type` `discount`, importo negativo) per
  credito, `taxes` da `tax_summaries`.
- `OfficeSubscriptionResource::formSchema()`: al posto di `plan_price_id` un
  `FormField::key('items')->repeater([...])` con `RepeaterColumn`
  `plan_price_id` (select «Piano — Prezzo»), `quantity` (number, 1), `parent`
  (select «Si appoggia a», le altre righe per indice; vuoto = nessuna) e, per
  le personalizzazioni, un campo testo per ogni personalizzazione dei piani attivi
  (`customization_<id>`), mostrato solo per i prezzi dei piani che la usano
  (attributo `data-wi-show-for` con gli id dei prezzi; se il core non ha un modo
  per mostrare un campo del repeater a seconda di un altro, i campi restano tutti
  visibili con il tooltip «Solo per i piani che la chiedono» e si annota in TODO).
  Card «Servizi» sotto la card «Abbonamento».

- [ ] **Test che falliscono** (OfficeSubscriptionsTest):
  - dominio annuale (personalizzazione «Nome del dominio» obbligatoria) + hosting
    annuale legato al dominio + assistenza mensile: nascono 3 righe `active`,
    `parent_item_id` dell'hosting = id del dominio, prima rata = somma dei tre
    periodi; il proforma ha tre voci e il nome del dominio nella voce;
  - senza il nome del dominio → errore di `check()`; hosting senza madre →
    `subscription.parent_required`; bimestrale + trimestrale →
    `subscription.interval_mismatch`;
  - secondo mese: `openPeriod()` addebita **solo** l'assistenza; al tredicesimo
    mese tutte e tre;
  - prova: prezzi con 0 e 14 giorni → `trial_ends_at` = +14 giorni, righe
    `pending` fino all'apertura del primo periodo;
  - limite 1: un secondo `create()` per lo stesso cliente →
    `subscription.limit_reached` con il codice del primo;
  - SubscriptionPagesTest: il form di *Nuovo abbonamento* ha il repeater `items`.
- [ ] Implementare; `changePlan()` e la sua azione restano fino al Task 10.
- [ ] Suite verde. Commit `Abbonamenti da ufficio: nuovo abbonamento con più righe`.

### Task 10: aggiungere, togliere, cambiare, correggere (ufficio)

**File:**
- Cambia: `src/Support/Subscriptions/SubscriptionItems.php`,
  `OfficeSubscriptions.php` (`daily()`, `cancel()`, `terminate()`; via
  `changePlan()`), `src/Resources/Subscriptions/SubscriptionActionResource.php`
  (azioni `add_item`, `change_item`, `remove_item`, `customize_item`; via
  `change_plan`), `SubscriptionResource.php` (riquadro *Servizi*, storia dalle
  `SubscriptionItemChange`, azioni sulla riga)
- Crea: `tests/integrazione/SubscriptionItemsTest.php`

**Interfacce:**
- `SubscriptionItems::add(int $subscriptionId, array $item, string $billing, int $userId = 0, string $source = 'user'): int`
  — `$billing` ∈ `prorate|free|next_period`; controlli di §4.1 (abbonamento in
  `Subscription::LIVE`, altrimenti `subscription.not_live`; intervalli con le righe
  in corso; madre in corso); passa al canale (`OfficeSubscriptions::itemAdded()`
  o, nella parte 2, `OnlineSubscriptions::itemAdded()`). Ritorna l'id della riga.
- `SubscriptionItems::remove(int $itemId, string $billing, int $userId = 0, string $source = 'user'): array{ends_at: string, removed: list<int>}`
  — `$billing` ∈ `period_end|credit`; in vincolo vale sempre `period_end` con
  `ends_at` = fine del vincolo (una richiesta `credit` in vincolo →
  `subscription.item_committed`); le figlie in corso si tolgono insieme con la
  stessa scelta; se restano zero righe in corso è una disdetta
  (`OfficeSubscriptions::cancel()` / `terminate()` con `credit`).
- `SubscriptionItems::change(int $itemId, int $newPriceId, string $billing, array $customization = [], int $userId = 0, string $source = 'user'): void`
  — stessa riga (stessa `position`, madre e figlie se i legami del piano nuovo le
  ammettono, altrimenti `subscription.link_broken`); `prorate` = credito della
  vecchia e quota della nuova nello stesso giorno; `next_period` = la riga tiene il
  prezzo nuovo in `SubscriptionItemChange` con `effective_at` = fine del periodo
  della riga e il giro giornaliero lo applica.
- `SubscriptionItems::customize(int $itemId, array $values, int $userId = 0): void`
  — `Customizations::resolvePlan()`; storia `customized` con il valore di prima
  nella nota; il sovrapprezzo nuovo vale dal periodo dopo (`unit_price` e totali
  della riga si ricalcolano, i totali dell'abbonamento al prossimo `openPeriod()`).
- `OfficeSubscriptions::itemAdded(array $subscription, array $item, string $billing): void`
  — `prorate`: rata con `quota()` del totale della riga dal giorno d'effetto alla
  fine del **suo** periodo (`rowPeriod()`), scadenza dalla condizione di
  pagamento, proforma della quota (un file `proformas/<id>-<data>-<item>.pdf`);
  `free`: niente; `next_period`: riga `pending`, `starts_at` = prossimo inizio del
  periodo della riga.
- `daily()`: prima di aprire un periodo, le righe `pending` con `starts_at ≤ oggi`
  diventano `active`, le `ending` con `ends_at ≤ oggi` diventano `ended`, i
  cambi `next_period` con `effective_at ≤ oggi` si applicano; se dopo questo non
  resta una riga in corso, l'abbonamento è `cancelled`.
- Il prezzo di rinnovo a fine vincolo (`renewal_plan_price_id` del prezzo): alla
  fine del vincolo di una riga, `daily()` chiama `change(..., 'next_period')` con
  `source` `system`.
- Scheda: card «Servizi» con una tabella (servizio con personalizzazioni, stato,
  quantità, importo del periodo pieno, prossimo rinnovo della riga, fine
  vincolo, «si appoggia a») e per riga un `Dropdown` con *Cambia*, *Togli*,
  *Correggi personalizzazioni*; sopra la tabella il pulsante *Aggiungi servizio*.
  Le finestre sono `Modal::form()` verso `SubscriptionActionResource` con la
  scelta del conguaglio in una select (in vincolo la select di *Togli* ha solo «A
  fine vincolo» e il testo lo dice; con figlie la finestra le elenca).

- [ ] **Test che falliscono** (SubscriptionItemsTest; date fisse, ancora
  2026-01-15, assistenza mensile 30,00 + dominio annuale 12,00):
  - aggiungo un hosting annuale 120,00 il 2026-07-15 con `prorate` → riga
    `active`, una rata di 60,49 (184/365) e un proforma della quota;
  - con `free` → nessuna rata; al 2027-01-15 l'hosting è nel periodo;
  - con `next_period` → riga `pending`, `starts_at` 2026-08-15 (il prossimo inizio
    di periodo **dell'abbonamento**); il 2026-08-15 `daily()` la porta `active` e
    addebita la quota dal 2026-08-15 all'anniversario 2027-01-15 (153/365 di
    120,00 = 50,30), poi rinnova con le altre. Regola: *una riga che parte al
    rinnovo parte al prossimo periodo dell'abbonamento e il primo addebito è la
    quota fino alla sua prossima data d'ancora*;
  - tolgo il dominio con figlie (hosting) a fine periodo → entrambe `ending` con
    `ends_at` 2027-01-15; il giro del 2027-01-15 le chiude e non le addebita;
  - tolgo l'assistenza con `credit` il 2026-07-30 → `ended`, credito 15,48
    (16/31 di 30,00) in `credit_left`;
  - riga in vincolo (12 mesi) tolta con `credit` → `subscription.item_committed`;
    con `period_end` → `ends_at` = fine del vincolo;
  - cambio l'assistenza da 30,00 a 50,00 con `prorate` il 2026-07-30 → credito
    15,48 + rata di 25,81, storia `changed`;
  - correggo il nome del dominio → storia `customized` con il valore vecchio in
    nota;
  - tolgo l'ultima riga a fine periodo → al giro l'abbonamento è `cancelled`;
  - abbonamento `unpaid` → `add()` dà `subscription.not_live`;
  - SubscriptionPagesTest: la scheda ha la card «Servizi» con una riga per servizio.
- [ ] Implementare; togliere `changePlan()`, l'azione `change_plan` e `changesHtml`
  sulla vecchia tabella. Chiavi in lang ed ErrorKeysTest (`item_committed`,
  `link_broken`, `parent_required`, `limit_reached`, `interval_mismatch`,
  `plan.has_children`, `plan.link_self`, `plan.link_depth`,
  `customization.link_target`, `customization.scope_required`,
  `customization.linked_products`, `customization.linked_plans`).
- [ ] Suite verde. Commit `Abbonamenti da ufficio: aggiungi, togli, cambia e correggi i servizi`.

### Task 11: crediti nel proforma e credito avanzato

**File:**
- Cambia: `OfficeSubscriptions.php` (`openPeriod()`, `terminate()`, `daily()`
  alla chiusura), `DocumentData::forSubscription()`
- Test: `tests/integrazione/SubscriptionItemsTest.php`

**Regole:**
- `openPeriod()` legge i `SubscriptionItemChange` con `credit_left > 0`
  dell'abbonamento, li usa in ordine di data fino al totale del periodo, scala
  `credit_left`, mette nel proforma una voce di sconto per credito usato e crea le
  rate sul totale meno i crediti (mai sotto zero; a zero nessuna rata e il
  proforma parte lo stesso con «Niente da pagare»).
- Quando l'abbonamento diventa `cancelled` con crediti ancora aperti:
  `Errors::report('subscriptions', 'credit_left', 'Credito di '.$importo.' non usato', ['subscription' => $id])`
  → compare in *Da controllare*.

- [ ] **Test che falliscono:** credito 15,48 e periodo successivo di 12,00 → nessuna
  rata, proforma con sconto 12,00, `credit_left` 3,48; il periodo dopo usa i 3,48;
  annullo l'abbonamento con 3,48 aperti → una segnalazione `credit_left`.
- [ ] Implementare. Commit `Abbonamenti da ufficio: crediti nel proforma`.

### Task 12: dati di prova, script e chiusura della parte 1

**File:**
- Crea: `tests/supporto/migra-abbonamenti-con-servizi.php`
- Cambia: `src/Seeding/PlansDemo.php`, `src/Seeding/OfficeSubscriptionsDemo.php`,
  `CatalogDemo.php`, `ShowcaseDemo.php`; guide `docs/user/catalogo-personalizzazioni.md`
  e la guida *Abbonamenti* in `docs/user/`; `CHANGELOG.md`; `TODO.md`
- Test: `tests/integrazione/CatalogDemoTest.php` e i test dei dati di prova degli
  abbonamenti

**Regole:**
- Lo script (si lancia una volta sul sito di prova, `php <percorso> <root del sito>`):
  copia `gst_product_model_customizations` in `gst_customization_links` se
  quest'ultima è vuota; per ogni abbonamento senza righe con un `plan_price_id`
  ancora nella tabella crea la riga `active` con gli importi dell'abbonamento;
  copia `gst_subscription_plan_changes` in `gst_subscription_item_changes`
  (`action` `changed`). Idempotente; stampa cosa ha fatto.
- Dati di prova: piano «Dominio .it» annuale con la personalizzazione «Nome del
  dominio» (testo, obbligatoria), piano «Hosting» annuale che si lega a
  «Dominio .it», piano «Assistenza» mensile; un abbonamento da ufficio con le tre
  righe.
- Guide: come si compone un abbonamento con più servizi, «Si lega a», le scelte
  di aggiunta/tolta/cambio, l'impostazione del limite, gli interruttori delle
  personalizzazioni.

- [ ] Script e dati di prova; lanciare lo script sul sito di prova.
- [ ] Guide, CHANGELOG (sezione non rilasciata), TODO (G10b parte 1 fatta).
- [ ] `php tests/run.php` e la suite d'integrazione: **verdi**. Punto di arrivo
  della parte 1: si può aprire la PR (con il via dell'utente).
- [ ] Commit `G10b parte 1: dati di prova, script, guide`.

---

# Parte 2 — Stripe

### Task 13: gateway con item e schedules

**File:**
- Cambia: `src/Providers/Payments/SubscriptionGateway.php`,
  `src/Providers/Payments/StripeBilling.php`, `tests/supporto/FakeSubscriptionGateway.php`
- Test: `tests/StripeMethodsTest.php` (o il test di StripeBilling che c'è)

**Interfacce (nuovi metodi, `array` in e out come gli altri):**
- `createSubscriptionItem(array $params, string $idempotencyKey): array` →
  `POST /v1/subscription_items`
- `updateSubscriptionItem(string $id, array $params): array` →
  `POST /v1/subscription_items/{id}`
- `deleteSubscriptionItem(string $id, array $params): array` →
  `DELETE /v1/subscription_items/{id}`
- `createSubscriptionSchedule(array $params, string $idempotencyKey): array`,
  `updateSubscriptionSchedule(string $id, array $params): array`,
  `retrieveSubscriptionSchedule(string $id): array`
- `previewInvoice(array $params): array` → `POST /v1/invoices/create_preview`
- Il fake registra ogni chiamata (`count()`, `last()`) e risponde con oggetti
  plausibili (`si_fake_N`, `sub_sched_fake_N`, fattura d'anteprima con
  `amount_due`).

- [ ] Test che falliscono (il fake registra metodo e parametri; StripeBilling
  costruisce percorso e verbo giusti con un client finto), implementazione, commit
  `Stripe: item e schedules degli abbonamenti nel gateway`.

### Task 14: prezzi Stripe con i sovrapprezzi

**File:** `src/Support/Subscriptions/StripeCatalog.php` (`priceFor()` 34,
`fingerprint()` 178, `priceParams()` 196); test `tests/StripeCatalogTest.php`

**Interfacce:**
- `StripeCatalog::priceFor(int $planPriceId, float $surcharge = 0.0, int $slot = 0): string`
- `StripeCatalog::fingerprint(array $price, float $surcharge = 0.0, int $slot = 0): string`
  — oggi `2490-year-12-eur`; con sovrapprezzo l'importo è prezzo + sovrapprezzo;
  con `slot > 0` in coda `-s<slot>`. Senza sovrapprezzo e slot l'impronta è
  quella di oggi (i prezzi già creati si riusano).
- `StripeCatalog::cents(array $price, float $surcharge = 0.0): int`.
- `StripeCatalog::slots(list<array{plan_price_id: int, surcharge: float}> $rows): list<int>`
  (pura): per ogni riga il numero di righe precedenti con la stessa impronta.

- [ ] **Test che falliscono:** impronta senza sovrapprezzo invariata; 24,90 + 2,00
  → `2690-year-12-eur`; due righe uguali → slot `[0, 1]` e impronte diverse;
  `priceParams()` con sovrapprezzo ha `unit_amount` 2690 e i metadati
  `plan_price_id`, `surcharge`.
- [ ] Implementare. Commit `Stripe: prezzi con i sovrapprezzi delle personalizzazioni`.

### Task 15: Checkout con più righe

**File:** `src/Support/Subscriptions/OnlineSubscriptions.php` (`start()` 54-131,
`checkoutSession()` 488-522, via `changePlan()` 133-180), test integrazione
`OnlineSubscriptionsTest`

**Interfacce:**
- `OnlineSubscriptions::start(int $customerId, list<array{price_code: string, quantity?: int, customization?: array, parent?: int}> $items, array $billing, string $couponCode, array $urls): array`
  (stesso ritorno di oggi: `url`, `code`). Al posto di `already_active`:
  `Subscriptions::assertRoom()`; righe preparate con `SubscriptionItems::prepare()`;
  abbonamento e righe `pending`.
- `checkoutSession()`: un `line_items[]` per riga con `price` =
  `priceFor(plan_price_id, surcharge, slot)`, `quantity`, `tax_rates`,
  `metadata[item_id]`; `subscription_data[billing_mode][type] = flexible`;
  `subscription_data[metadata]` come oggi; `trial_period_days` = la prova più
  lunga; al più 20 righe (`subscription.too_many_items`); la riga scrive il suo
  `provider_price`.

- [ ] **Test che falliscono:** con il fake, tre righe → tre `line_items` con le
  quantità, `billing_mode` flexible, `metadata[item_id]` per riga; due hosting
  uguali → due prezzi diversi; 21 righe → `too_many_items`; limite 1 con un
  abbonamento attivo → `limit_reached`; `pending` online di 30 ore non blocca.
- [ ] Implementare. Commit `Stripe: Checkout con più servizi`.

### Task 16: notifiche e riallineamento sulle righe

**File:** `StripeSubscriptionData.php` (`subscription()`), `SubscriptionEvents.php`
(`synced()` 65, `planPriceOf()`), `OnlineSubscriptions::reconcile()` 234; test
`tests/StripeSubscriptionDataTest.php`, integrazione `OnlineSubscriptionsTest`

**Interfacce:**
- `StripeSubscriptionData::subscription(object $object, bool $deleted): array` —
  in più `items: list<array{id: string, price: string, quantity: int, item_id: int, period_start: string, period_end: string}>`
  da `items.data[]` (`metadata.item_id`, `current_period_start/_end`); le date in
  alto = quelle dell'item col periodo più corto.
- `SubscriptionEvents::synced()`: abbina ogni item a una riga per
  `metadata.item_id`, poi per `provider_price`; scrive `provider_reference`
  (`si_…`); righe `pending` → `active` al primo pagamento; item spariti →
  righe `ended`; item nuovi creati fuori dal gestionale (Portale, Dashboard) →
  riga nuova `active` con il prezzo trovato da `planPriceOf()`.
- `reconcile()` confronta anche le righe (stato e `provider_reference`).

- [ ] **Test che falliscono:** un `customer.subscription.created` con tre item
  scrive i tre `si_`; un `updated` senza un item chiude quella riga; un item senza
  metadati si abbina per prezzo; `invoice.paid` porta le righe `pending` ad
  `active`.
- [ ] Implementare. Commit `Stripe: notifiche e riallineamento sulle righe`.

### Task 17: operazioni sulle righe online

**File:** `OnlineSubscriptions.php` (`itemAdded()`, `itemRemoved()`,
`itemChanged()`, `quote()`), `SubscriptionItems.php` (passa al canale online);
test integrazione `OnlineSubscriptionsTest`

**Interfacce:**
- `OnlineSubscriptions::itemAdded(array $subscription, array $item, string $billing): void`
  — `prorate` → `createSubscriptionItem` con `proration_behavior=always_invoice`,
  `payment_behavior=error_if_incomplete`; `free` → `proration_behavior=none`;
  `next_period` → schedule (sotto).
- `itemRemoved(array $subscription, array $item, string $billing): void` —
  `credit` → `deleteSubscriptionItem` con `proration_behavior=create_prorations`;
  `period_end` → schedule.
- `itemChanged(array $subscription, array $item, int $newPriceId, string $billing): void`
  — `updateSubscriptionItem` con il prezzo nuovo e il `proration_behavior`
  della scelta; `next_period` → schedule.
- Schedule: se la subscription ha già `schedule`, si aggiorna; altrimenti
  `createSubscriptionSchedule(['from_subscription' => sub_…])` e poi
  `updateSubscriptionSchedule` con due fasi: la corrente (item di oggi, `end_date`
  = fine del periodo) e la prossima (item modificati); `end_behavior=release`.
  L'id dello schedule va in `ExternalReferences` (`subscription_schedule`).
- `OnlineSubscriptions::quote(int $subscriptionId, array $item): array{amount: float, currency: string}`
  — anteprima della quota pro-rata con `previewInvoice` (per l'area cliente).

- [ ] **Test che falliscono:** con il fake: `prorate` chiama
  `createSubscriptionItem` con `always_invoice`; `credit` chiama
  `deleteSubscriptionItem` con `create_prorations`; due `next_period` di fila
  creano **uno** schedule e lo aggiornano; `quote()` restituisce `amount_due`
  del fake / 100.
- [ ] Implementare. Commit `Stripe: aggiungi, togli e cambia servizi online`.

### Task 18: chiusura della parte 2

- [ ] Guide (pagamento online con più servizi), CHANGELOG, TODO.
- [ ] Suite completa verde; prova a mano su Stripe in modalità test (dominio +
  hosting + assistenza mensile) **la fa l'utente** nel browser; scrivere i passi
  in TODO. Punto di arrivo della parte 2: si può aprire la PR.
- [ ] Commit `G10b parte 2: guide e TODO`.

---

# Parte 3 — Ecommerce

### Task 19: selezione dei servizi

**File:** crea `src/Frontend/Subscriptions/SubscriptionSelection.php`; cambia
`SubscriptionsController.php` (azioni `add`, `remove`, `summary`, `start`; via
`subscribe` del prezzo singolo), `PlanCatalog.php` (`present()`: pulsante
«Aggiungi»), viste dei piani; test `tests/SubscriptionSelectionTest.php`,
`tests/SubscriptionsPageTest.php`

**Interfacce:**
- `SubscriptionSelection::items(): list<array{price_code: string, quantity: int, customization: array, parent: int}>`,
  `add(array $item): void` (controlla intervalli e personalizzazioni con
  `PlanPriceRules::assertIntervals()` e `Customizations::check()`, errori mostrati
  sulla pagina), `remove(int $index): void` (toglie anche le figlie), `clear(): void`;
  in sessione sotto `ecommerce.subscription_selection`.
- Al limite di abbonamenti (`Subscriptions::assertRoom()`), «Aggiungi» manda
  all'area cliente con un avviso.

- [ ] Test che falliscono (add/remove/figlie/intervalli/limite), implementazione,
  commit `Ecommerce: selezione dei servizi in abbonamento`.

### Task 20: riepilogo, Checkout e dataLayer

**File:** `SubscriptionsController.php` (`summary`, `start`, `confirm`),
`SubscriptionTracking.php`, `PlanCatalog::trackingItems()`, viste; test
`tests/SubscriptionsPageTest.php`

**Regole:** il riepilogo mostra righe, personalizzazioni, totale del primo
addebito (`SubscriptionAmounts::ofItems()`), fatturazione e codice sconto; «Paga»
chiama `OnlineSubscriptions::start()` con le righe della selezione e la svuota a
Checkout aperto. dataLayer: `view_item_list`, `select_item`, `add_to_cart`
(nuovo, all'«Aggiungi»), `begin_checkout` con un item per riga.

- [ ] Test che falliscono (riepilogo con tre righe; `begin_checkout` con tre item;
  selezione vuota → torna ai piani), implementazione, commit
  `Ecommerce: riepilogo e pagamento di più servizi`.

### Task 21: area cliente

**File:** `src/Frontend/Account/AccountSubscriptions.php`,
`EcommerceAccountController.php`, viste; test dell'area cliente

**Regole (G10b.11):** per ogni abbonamento le righe con personalizzazioni e
prossimo rinnovo; *Aggiungi servizio* solo `prorate`, con la quota di
`OnlineSubscriptions::quote()` mostrata prima di confermare; *Togli* solo
`period_end`; *Cambia* solo `prorate`; le figlie vanno via con la madre e la
pagina lo dice.

- [ ] Test che falliscono, implementazione, commit `Ecommerce: servizi nell'area cliente`.

### Task 22: chiusura

- [ ] Guide dell'ecommerce, CHANGELOG, TODO di entrambi i repo.
- [ ] Suite di gestionale ed ecommerce verdi; revisione finale di tutto il ramo
  (advisor); prove nel browser elencate in TODO per l'utente.
- [ ] Nessun push: si chiede il via all'utente per PR e merge.
