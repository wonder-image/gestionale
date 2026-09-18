# Piano 3 di 4 — Fondamenta dei documenti, IVA, impostazioni e sedi

> **Per chi esegue:** SKILL RICHIESTA: usare superpowers:subagent-driven-development
> (consigliata) o superpowers:executing-plans, un task alla volta. I passi usano le
> caselle `- [ ]` per il tracciamento.

**Obiettivo:** mettere sotto i piedi dei prossimi sotto-progetti le parti comuni a
tutti i documenti (codici, numerazione, log degli stati, riferimenti esterni), il
calcolo dell'IVA con le sue tabelle, le due righe di impostazioni e la sede
principale del magazzino.

**Architettura:** ogni pezzo è diviso in due: una classe pura che fa il conto o la
regola (testabile senza database) e un Model con la sua tabella. Le tabelle di
configurazione (`taxes`, `tax_categories`, `tax_rules`, `settings`, `locations`) si
modificano in locale e viaggiano con il deploy (`keepIds()` + `localOnly()`); quelle
operative (`document_sequences`, `external_references`, `merchant_settings`) non si
sincronizzano mai. Le pagine sono Resource del modulo, in Set Up per `admin` e nella
sezione Gestionale per `administrator`.

**Stack:** PHP 8.2, `wonder-image/app` `^2.2.8` (`SyncSchema`, `ModuleDefaults`/
`DefaultRows`, `Transaction::run()`, `findByIdForUpdate()`, `SingletonResource`,
`Resource::editableWhenReadonly()`), MySQL, test come script PHP con l'harness del
modulo.

**Spec:** `/Users/andreamarinoni/Developer/packages/gestionale/docs/superpowers/specs/2026-09-18-fondamenta-gestionale-design.md`
(sezioni 4, 5, 6 e 9; spec di architettura 4.1, 4.3, 4.5, 6.2, 8.2, 8.3, 8.4).

## Vincoli globali

- **Lingua:** testi, commenti e messaggi in italiano; nomi in inglese nel codice.
- **Prefisso delle tabelle:** `gst_` (D60). Nella spec sono scritte senza.
- **Sincronizzazione:** `taxes`, `tax_categories`, `tax_rules`, `locations` con
  `SyncSchema::multiRow()->keepIds()->localOnly()`; `settings` con
  `SyncSchema::singleton()->localOnly()`; `document_sequences`,
  `external_references` e `merchant_settings` restituiscono `null` (mai
  sincronizzate).
- **Righe precaricate:** solo con `DefaultRows`, che non tocca mai le righe
  esistenti. Nomi in italiano (8.3).
- **Codici parlanti** nelle tabelle di configurazione (`22`, `italia-privato-ordinaria`),
  `uniqueCode()` con prefisso solo nelle entità operative (4.1).
- **Test d'integrazione:** girano sul database del sito di prova (`ecommerce_site`)
  dentro `Transaction::run()` e annullano sempre, anche quando falliscono (G1.11).
  Le righe che servono se le creano da sé: non dipendono da quello che c'è nel sito.
- **Decimali:** le colonne di valore passano da `Field::key(...)->number()->decimals(2)`,
  che nel framework è `DECIMAL(10,2)`. La spec diceva 5,2 per `rate`: si usa il tipo
  del framework, che copre gli stessi valori.
- **Ramo:** `feature/documenti-iva-sedi` in `packages/gestionale`, creato da `main`.
- **Comandi:** test del modulo con `php tests/run.php` (lancia anche i test
  d'integrazione quando il sito di prova esiste); `php forge update` dal sito di
  prova per tabelle e righe precaricate.
- **Commit:** uno per task, messaggio in inglese, con la riga
  `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`.

## Struttura dei file

| File | Responsabilità |
|---|---|
| `src/Support/Codes.php` | prefissi dei codici di tutte le entità (4.1) |
| `src/Support/Documents/DocumentNumber.php` | formato `{YYYY}/{mm}{nnnn}`, pura |
| `src/Support/Documents/DocumentSequences.php` | assegna il numero con la riga bloccata |
| `src/Models/Documents/DocumentSequence.php` | tabella `gst_document_sequences` |
| `src/Models/System/StatusLog.php` | base astratta dei log degli stati |
| `src/Support/Status/StatusLogger.php` | scrive la riga di log |
| `src/Models/System/ExternalReference.php` | tabella `gst_external_references` |
| `src/Support/Providers/ExternalReferences.php` | `find()`, `save()`, `forget()` |
| `src/Models/Tax/Tax.php` | tabella `gst_taxes` |
| `src/Models/Tax/TaxCategory.php` | tabella `gst_tax_categories` |
| `src/Models/Tax/TaxRule.php` | tabella `gst_tax_rules` |
| `src/Support/Tax/TaxResolver.php` | regola paese × cliente × tipo fiscale → aliquota |
| `src/Support/Tax/TaxTotals.php` | riepiloghi IVA per aliquota e natura |
| `src/Resources/Tax/TaxResource.php` | pagina "Aliquote IVA" |
| `src/Resources/Tax/TaxCategoryResource.php` | pagina "Tipi fiscali" |
| `src/Resources/Tax/TaxRuleResource.php` | pagina "Regole IVA" |
| `src/Models/System/Setting.php` | tabella `gst_settings` (riga unica) |
| `src/Models/System/MerchantSetting.php` | tabella `gst_merchant_settings` (riga unica) |
| `src/Resources/System/SettingResource.php` | "Impostazioni" in Set Up (`admin`) |
| `src/Resources/System/MerchantSettingResource.php` | "Impostazioni" del commerciante |
| `src/Models/Locations/Location.php` | tabella `gst_locations` |
| `src/Resources/Locations/LocationResource.php` | pagina "Sedi" che sostituisce quella del core |
| `src/Seeding/Defaults.php` | righe precaricate: aliquote, tipi, regole, impostazioni, sede |

---

### Task 1: Codici con prefisso

**File:**
- Creare: `src/Support/Codes.php`
- Test: `tests/CodesTest.php`

**Interfacce:**
- Produce: `Codes::MODEL`, `::VARIANT`, `::PRODUCT`, `::BRAND`, `::CATEGORY`, `::TAG`,
  `::CONTACT`, `::LOCATION`, `::BATCH`, `::STOCK_MOVEMENT`, `::STOCK_DOCUMENT`,
  `::ORDER`, `::DELIVERY_NOTE`, `::SALES_RETURN`, `::PAYMENT`, `::INVOICE`,
  `::SHIPMENT`, `::SUBSCRIPTION`, `::PLAN`, `::PRICE_LIST`, `::DISCOUNT_CAMPAIGN`;
  `Codes::all(): array<string, string>`.

- [ ] **Passo 1: scrivere il test che fallisce** — `tests/CodesTest.php` con i casi:
  `Codes::PRODUCT === 'pro_'`; `Codes::all()` ha una voce per ogni costante della
  classe (`(new ReflectionClass(Codes::class))->getConstants()`); tutti i prefissi
  sono diversi tra loro; ognuno rispetta `/^[a-z]{3}_$/`.
- [ ] **Passo 2: eseguirlo** — `php tests/CodesTest.php`, deve fallire con "Class not found".
- [ ] **Passo 3: scrivere la classe** — `final class Codes` con le costanti della
  tabella di 4.1 (`mod_`, `var_`, `pro_`, `bra_`, `cat_`, `tag_`, `con_`, `loc_`,
  `bat_`, `mov_`, `stk_`, `ord_`, `del_`, `ret_`, `pay_`, `inv_`, `shp_`, `sub_`,
  `pln_`, `prl_`, `dsc_`) e `all()` che le restituisce da `ReflectionClass`.
  Nel docblock: il codice è il riferimento tecnico, non sostituisce SKU, numero
  d'ordine o numero di fattura; le tabelle di configurazione usano codici parlanti.
- [ ] **Passo 4: rieseguire** — verde.
- [ ] **Passo 5: commit** — `git add src/Support/Codes.php tests/CodesTest.php`.

---

### Task 2: Numerazione dei documenti

**File:**
- Creare: `src/Support/Documents/DocumentNumber.php`,
  `src/Models/Documents/DocumentSequence.php`,
  `src/Support/Documents/DocumentSequences.php`
- Test: `tests/DocumentNumberTest.php`, `tests/integrazione/DocumentSequencesTest.php`

**Interfacce:**
- Consuma: `Wonder\Sql\Transaction::run()`, `Model::findByIdForUpdate()`, `sqlInsert()`,
  `sqlModify()`, `sqlSelect()`.
- Produce:
  - `DocumentNumber::format(int $year, int $month, int $number): string`
  - `DocumentNumber::parse(string $number): ?array{year:int, month:int, number:int}`
  - `DocumentSequences::next(string $type, ?DateTimeInterface $at = null): string`

**Tabella `gst_document_sequences`:** `document_type` (100), `year` INT, `month` INT,
`last_number` INT default `0`; unica su (`document_type`, `year`, `month`);
`syncSchema()` restituisce `null`.

- [ ] **Passo 1: test del formato** — `tests/DocumentNumberTest.php`:
  `format(2026, 9, 1) === '2026/090001'`; `format(2026, 12, 9999) === '2026/129999'`;
  oltre i 9999 la cifra in più: `format(2026, 9, 10000) === '2026/0910000'`;
  `parse('2026/090001')` torna `['year' => 2026, 'month' => 9, 'number' => 1]`;
  `parse('2026/0910000')` legge `10000`; `parse('ciao')` è `null`.
- [ ] **Passo 2: eseguirlo** — fallisce.
- [ ] **Passo 3: scrivere `DocumentNumber`** — `sprintf('%04d/%02d%04d', ...)` con
  `str_pad` a quattro cifre minime per il progressivo; `parse()` con
  `preg_match('#^(\d{4})/(\d{2})(\d{4,})$#', ...)`.
- [ ] **Passo 4: rieseguire** — verde.
- [ ] **Passo 5: scrivere il Model** `DocumentSequence` (tabella, `syncSchema(): null`,
  `tableSchema()`, `dataSchema()`), senza test propri: lo prova il task d'integrazione.
- [ ] **Passo 6: test d'integrazione** — `tests/integrazione/DocumentSequencesTest.php`
  dentro `Transaction::run()` che annulla sempre:
  - due chiamate di seguito su un tipo finto (`test_doc`) danno `...0001` e `...0002`;
  - un tipo diverso riparte da `0001` nello stesso mese;
  - un mese diverso riparte da `0001` (si passa una data con `new DateTimeImmutable('2026-10-05')`);
  - la riga in tabella ha `last_number` uguale all'ultimo numero dato.
- [ ] **Passo 7: eseguirlo** — fallisce.
- [ ] **Passo 8: scrivere `DocumentSequences::next()`** — dentro `Transaction::run()`
  (se una transazione è già aperta il core la riusa): cerca la riga con
  `SelectForUpdate` su (`document_type`, `year`, `month`), la crea a `0` se manca,
  incrementa `last_number`, salva e restituisce `DocumentNumber::format()`.
  Nel docblock: `FOR UPDATE` perché due documenti insieme non prendano lo stesso numero;
  le fatture avranno numerazione annuale con sezionale (G8) ma il tipo è già un parametro.
- [ ] **Passo 9: rieseguire** — verde; poi `php tests/run.php` tutto verde.
- [ ] **Passo 10: commit.**

---

### Task 3: Log degli stati

**File:**
- Creare: `src/Models/System/StatusLog.php`, `src/Support/Status/StatusLogger.php`
- Test: `tests/StatusLogTest.php`, `tests/integrazione/StatusLoggerTest.php`

**Interfacce:**
- Produce:
  - `abstract class StatusLog extends Model` con `abstract public static function entityColumn(): string`
    e `public static function commonColumns(): array` (le colonne di 4.1 pronte da
    mettere in `tableSchema()` della sottoclasse) e `commonFields(): array` per il
    `dataSchema()`.
  - `StatusLogger::record(string $logClass, int $entityId, string $field, string $from, string $to, string $source = 'user', ?int $userId = null, string $message = '', ?array $response = null): bool`
  - `StatusLogger::SOURCES = ['user', 'system', 'cron', 'webhook', 'api']`

**Colonne comuni:** `<entità>_id` INT (chiave esterna dichiarata dalla sottoclasse),
`field` (100), `from_value` (100), `to_value` (100), `source` enum di `SOURCES`,
`user_id` INT, `message` TEXT, `response` JSON.

- [ ] **Passo 1: test unitario** — `tests/StatusLogTest.php` con una sottoclasse finta
  (`class ProvaStatusLog extends StatusLog` con `$table = 'gst_test_status_logs'` e
  `entityColumn() === 'test_id'`): `commonColumns()` contiene i nomi previsti;
  `tableSchema()` della sottoclasse include la colonna dell'entità; una `source`
  fuori da `SOURCES` viene riportata a `system` da `StatusLogger::normalizeSource()`.
- [ ] **Passo 2: eseguirlo** — fallisce.
- [ ] **Passo 3: scrivere `StatusLog`** — astratta, con `commonColumns()`/`commonFields()`
  e un docblock che elenca dove nasceranno le tabelle vere (`order_status_logs`, …) e
  che non si sincronizzano mai.
- [ ] **Passo 4: scrivere `StatusLogger`** — `record()` valida la classe
  (`is_subclass_of($logClass, StatusLog::class)`), normalizza `source`, scrive la riga
  con `sqlInsert()` e poi richiama l'hook `onStatusChanged` delle estensioni **solo se
  la classe degli hook esiste già** (arriva nel piano 4): per ora una costante privata
  con il nome della classe e un `class_exists()`, con un commento che rimanda al piano 4.
- [ ] **Passo 5: rieseguire** — verde.
- [ ] **Passo 6: test d'integrazione** — `tests/integrazione/StatusLoggerTest.php`:
  si crea la tabella finta con `sqlTable()` (le colonne di `gst_feature_logs` sono
  diverse, non si può riusare), si scrive una riga con
  `StatusLogger::record()`, si rilegge e si controllano `field`, `from_value`,
  `to_value`, `source`, `user_id`, `message` e `response` (JSON decodificato).
  Alla fine `DROP TABLE` prima dell'annullamento (MySQL non annulla le DDL).
- [ ] **Passo 7: eseguirlo, poi `php tests/run.php`** — verde.
- [ ] **Passo 8: commit.**

---

### Task 4: Riferimenti esterni

**File:**
- Creare: `src/Models/System/ExternalReference.php`,
  `src/Support/Providers/ExternalReferences.php`
- Test: `tests/integrazione/ExternalReferencesTest.php`

**Tabella `gst_external_references`:** `entity_type` (100), `entity_id` INT,
`provider` (100), `environment` enum (`live`, `test`), `object_type` (100),
`external_id` (191), `synced_at` DATETIME, `sync_error` TEXT; unica su
(`provider`, `environment`, `object_type`, `external_id`), indice su
(`entity_type`, `entity_id`); `syncSchema(): null`.

**Interfacce:**
- `ExternalReferences::find(string $entityType, int $entityId, string $provider, string $objectType, string $environment = 'live'): ?array`
- `ExternalReferences::save(string $entityType, int $entityId, string $provider, string $objectType, string $externalId, string $environment = 'live'): bool`
- `ExternalReferences::forget(string $entityType, int $entityId, string $provider, string $objectType, string $environment = 'live'): bool`
- `ExternalReferences::fail(string $entityType, int $entityId, string $provider, string $objectType, string $error, string $environment = 'live'): bool`

- [ ] **Passo 1: test d'integrazione** — dentro la transazione:
  `save()` crea la riga con `synced_at` valorizzato e `sync_error` vuoto;
  `find()` la ritrova; un secondo `save()` con un altro `external_id` aggiorna la riga
  invece di crearne una seconda; `fail()` scrive l'errore **senza toccare**
  `external_id` (4.1); `forget()` la elimina e `find()` torna `null`;
  lo stesso `external_id` in `environment` diverso convive.
- [ ] **Passo 2: eseguirlo** — fallisce.
- [ ] **Passo 3: scrivere Model e classe.** Docblock: un errore temporaneo non deve far
  perdere l'ID già salvato, per questo `fail()` è separato da `save()`.
- [ ] **Passo 4: rieseguire, poi `php tests/run.php`** — verde.
- [ ] **Passo 5: commit.**

---

### Task 5: Tabelle fiscali

**File:**
- Creare: `src/Models/Tax/Tax.php`, `src/Models/Tax/TaxCategory.php`,
  `src/Models/Tax/TaxRule.php`
- Test: `tests/TaxModelsTest.php`

**Tabelle** (tutte `multiRow()->keepIds()->localOnly()`):

| Tabella | Colonne |
|---|---|
| `gst_taxes` | `code` (100, unica), `name`, `description` TEXT, `invoice_description`, `rate` decimale, `nature` (10), `visible` enum |
| `gst_tax_categories` | `code` (100, unica), `name`, `description` TEXT, `position` INT, `visible` enum |
| `gst_tax_rules` | `code` (100, unica), `country` (2), `customer_type` enum (`private`, `business`), `tax_category_id` INT chiave verso `gst_tax_categories`, `tax_id` INT chiave verso `gst_taxes`; unica su (`country`, `customer_type`, `tax_category_id`) |

- [ ] **Passo 1: test** — `tests/TaxModelsTest.php` sul solo schema (senza database,
  come `FeatureModelsTest`): nomi delle tabelle con il prefisso `gst_`; `Tax` ha la
  colonna `rate` e `nature`; le tre classi dichiarano `keepIds()` e `localOnly()`;
  `TaxRule` ha l'indice unico su (`country`, `customer_type`, `tax_category_id`) e le
  due chiavi esterne; `code` è immutabile dopo l'inserimento
  (`readonlyOnUpdate()->immutableOnUpdate()`).
- [ ] **Passo 2: eseguirlo** — fallisce.
- [ ] **Passo 3: scrivere i tre Model.** Nel docblock di `Tax`: le aliquote non si
  eliminano, si mostrano o si nascondono; `nature` è vuota quando `rate` è maggiore di
  zero.
- [ ] **Passo 4: rieseguire** — verde.
- [ ] **Passo 5: commit.**

---

### Task 6: Calcolo dell'IVA

**File:**
- Creare: `src/Support/Tax/TaxResolver.php`, `src/Support/Tax/TaxTotals.php`
- Test: `tests/TaxResolverTest.php`, `tests/TaxTotalsTest.php`

**Interfacce:**
- `TaxResolver::resolve(array $rules, string $country, string $customerType, int $taxCategoryId, int $fallbackTaxId): int`
  — `$rules` è la lista di righe `gst_tax_rules` (array associativi).
- `TaxTotals::summaries(array $lines, bool $pricesIncludeTax): array`
  — `$lines`: `['total' => float, 'rate' => float, 'nature' => string]`;
  ritorna una lista ordinata per aliquota decrescente con
  `['rate', 'nature', 'taxable', 'tax', 'total']`.

- [ ] **Passo 1: test di `TaxResolver`** — corrispondenza esatta paese + tipo cliente +
  tipo fiscale; maiuscole del paese ignorate (`it` e `IT` sono lo stesso);
  senza regola torna il ripiego; una regola con `tax_category_id` diverso non vale;
  con due regole buone vince la prima trovata (non deve succedere, ma il
  comportamento è definito).
- [ ] **Passo 2: eseguirlo** — fallisce.
- [ ] **Passo 3: scrivere `TaxResolver`.**
- [ ] **Passo 4: rieseguire** — verde.
- [ ] **Passo 5: test di `TaxTotals`** con i casi di 4.5:
  - prezzi IVA esclusa: due righe al 22% da 100 → imponibile 200, imposta 44, totale 244;
  - prezzi IVA inclusa: riga da 122 al 22% → imponibile 100, imposta 22, totale 122;
  - **l'imposta si calcola sul totale dell'aliquota, non riga per riga:** tre righe da
    0.33 al 22% danno imposta 0.22 (0.33 × 3 = 0.99 → 0.2178 → 0.22), non 0.24 (0.08 × 3);
  - aliquote diverse: un riepilogo per aliquota, ordinati dal più alto;
  - riga a 0% con natura: imposta 0, la natura resta nel riepilogo, e due nature
    diverse allo 0% restano separate;
  - arrotondamento a due decimali su imponibile e imposta.
- [ ] **Passo 6: eseguirlo** — fallisce.
- [ ] **Passo 7: scrivere `TaxTotals`.** Docblock: i riepiloghi sono quelli che G4
  salverà in `order_tax_summaries` e G8 in `invoice_tax_summaries`; con i prezzi IVA
  inclusa il cliente paga sempre la cifra esposta e cambia solo l'imponibile.
- [ ] **Passo 8: rieseguire, poi `php tests/run.php`** — verde.
- [ ] **Passo 9: commit.**

---

### Task 7: Pagine delle tabelle fiscali

**File:**
- Creare: `src/Resources/Tax/TaxResource.php`, `src/Resources/Tax/TaxCategoryResource.php`,
  `src/Resources/Tax/TaxRuleResource.php`
- Modificare: `config/routes/route.backend.php` se le Resource vanno registrate a mano
  (controllare come sono registrate quelle esistenti)
- Test: `tests/TaxResourcesTest.php`

**Regole comuni:** estendono `GestionaleResource`; percorsi `app/gestionale/aliquote-iva`,
`app/gestionale/tipi-fiscali`, `app/gestionale/regole-iva`; sezione `set-up`;
`permissionSchema()` solo `admin`; niente eliminazione (`pageSchema()->disable(['delete'])`
e `assertDeletable()` che spiega perché); in produzione sono in sola lettura da sole,
perché il Model è `localOnly()`.

- [ ] **Passo 1: test** — `tests/TaxResourcesTest.php`: le tre Resource dichiarano il
  Model giusto, il percorso giusto e la sezione `set-up`; `permissionSchema()` dà i
  permessi solo ad `admin`; `assertDeletable()` lancia `RuntimeException` con un
  messaggio che parla di documenti già emessi; la colonna `code` non è nel form di
  modifica (è immutabile) ma c'è nella tabella.
- [ ] **Passo 2: eseguirlo** — fallisce.
- [ ] **Passo 3: scrivere le tre Resource** — `tableSchema()` con le colonne utili
  (aliquota con il `%`, natura, visibile come badge), `formSchema()` con i campi e i
  select di `Natura::valide()` per la natura; nelle regole due select che leggono
  `TaxCategory` e `Tax`.
- [ ] **Passo 4: rieseguire** — verde.
- [ ] **Passo 5: commit.**

---

### Task 8: Impostazioni

**File:**
- Creare: `src/Models/System/Setting.php`, `src/Models/System/MerchantSetting.php`,
  `src/Resources/System/SettingResource.php`,
  `src/Resources/System/MerchantSettingResource.php`
- Test: `tests/SettingsTest.php`

**`gst_settings`** (`SyncSchema::singleton()->localOnly()`): `invoice_provider`,
`tax_regime` (10), `vat_collectability` (5), `transmitter_country` (2),
`transmitter_fiscal_code` (50), `catalog_prices_include_tax` enum,
`fallback_tax_id` INT, `shipping_tax_id` INT, `invoice_numeration` (20),
`stamp_duty_auto` enum, `fiscal_confirmed_at` DATETIME, `developer_error_emails` TEXT.

**`gst_merchant_settings`** (`syncSchema(): null`): `merchant_error_emails` TEXT.

**Interfacce:**
- `Setting::current(): array` e `MerchantSetting::current(): array` — la riga unica,
  array vuoto se non c'è.

**Pagine:** `SettingResource` estende `SingletonResource` (Set Up, `admin`, riquadri
Fiscale, Documenti, Errori); `MerchantSettingResource` estende `SingletonResource`
(sezione Gestionale, `administrator`, riquadro Errori). Il salvataggio della pagina
fiscale scrive `fiscal_confirmed_at` con `afterUpdate()`: serve ai Primi passi (8.6).

- [ ] **Passo 1: test** — `tests/SettingsTest.php`: `Setting` è `singleton()` e
  `localOnly()`, `MerchantSetting` non si sincronizza; le colonne di G1 ci sono tutte;
  `SettingResource` è `admin` e in Set Up, `MerchantSettingResource` è `administrator`
  e nella sezione del gestionale; `SettingResource::mutateRequestValues()` scrive
  `fiscal_confirmed_at` quando manca e lo lascia stare quando c'è già.
- [ ] **Passo 2: eseguirlo** — fallisce.
- [ ] **Passo 3: scrivere Model e Resource.** Nel docblock la regola di 8.2:
  configurazione tecnica di `admin` in `settings`, scelte quotidiane del commerciante
  in `merchant_settings`; ogni sotto-progetto aggiunge le sue colonne alla riga giusta.
- [ ] **Passo 4: rieseguire** — verde.
- [ ] **Passo 5: commit.**

---

### Task 9: Sede principale

**File:**
- Creare: `src/Models/Locations/Location.php`, `src/Resources/Locations/LocationResource.php`
- Test: `tests/LocationResourceTest.php`, `tests/integrazione/LocationTest.php`

**`gst_locations`** (`multiRow()->keepIds()->localOnly()`): `code` con
`uniqueCode(Codes::LOCATION)`, `society_location_id` INT unica con chiave verso
`society_locations` del core, `has_stock`, `is_pickup_point`, `is_pos`, `active`
(enum `true`/`false`).

**`LocationResource`:** stesso percorso e stesso titolo della Resource del core
(`app/config/locations`, "Sedi"), così il `ResourceRegistry` la sostituisce; aggiunge
i campi del magazzino; "Punto di ritiro" compare solo con la funzionalità `shipping`,
"Banco" solo con `pos` (D20); `editableWhenReadonly()` restituisce i due repeater degli
orari e delle chiusure, così in produzione si cambiano solo quelli; `isReadonly()` è
vera per `administrator` anche in locale; `assertDeletable()` vieta di eliminare una
sede del core che ha una riga `gst_locations` collegata.

- [ ] **Passo 1: verificare la sostituzione** — leggere `ResourceRegistry` e la
  Resource del core (`Wonder\App\Resources\Config\SocietyLocationResource`) per capire
  come si dichiara la priorità: **se il registro non prevede la sostituzione, fermarsi
  e chiedere** invece di duplicare la pagina.
- [ ] **Passo 2: test** — `tests/LocationResourceTest.php`: il percorso è quello del
  core; con `pos` bloccata il campo "Banco" non c'è nel form, con `pos` attiva sì
  (si forza lo stato con il doppio del catalogo, come in `FeatureGateTest`);
  `editableWhenReadonly()` elenca i due repeater; `isReadonly()` è vera per
  `administrator`.
- [ ] **Passo 3: eseguirlo** — fallisce.
- [ ] **Passo 4: scrivere Model e Resource.**
- [ ] **Passo 5: test d'integrazione** — `tests/integrazione/LocationTest.php`: dentro
  la transazione, una riga `gst_locations` collegata alla sede predefinita impedisce
  l'eliminazione della sede del core (`assertDeletable()` lancia), e senza riga
  collegata l'eliminazione resta permessa.
- [ ] **Passo 6: eseguirlo, scrivere il codice mancante, rieseguire** — verde.
- [ ] **Passo 7: commit.**

---

### Task 10: Righe precaricate e verifica sul sito di prova

**File:**
- Modificare: `src/Seeding/Defaults.php`
- Test: `tests/integrazione/DefaultsTest.php` (esteso)

**Righe da creare** (8.3, nomi in italiano):

| Dati | Cosa |
|---|---|
| Aliquote | 22%, 10%, 5%, 4% da `AliquoteIva::Valori` del core, visibili, nome `<valore>% - <descrizione>`; una riga per ogni natura di `Natura::valide()` con `rate` 0 e `visible` `false` |
| Tipi fiscali | solo "Aliquota ordinaria" (`ordinaria`), posizione 1, visibile |
| Regole IVA | `italia-privato-ordinaria` e `italia-azienda-ordinaria`: paese `IT`, tipo ordinario, aliquota 22% |
| Impostazioni tecniche | riga unica: regime `RF01`, esigibilità `I`, prezzi IVA inclusa, ripiego e spedizione al 22%, sezionale `WEB`, bollo automatico, provider vuoto, conferma vuota |
| Impostazioni commerciante | riga unica con `merchant_error_emails` uguale all'email della società, se c'è |
| Sede principale | riga di `gst_locations` collegata alla sede predefinita del core, con `has_stock` a `true` |

- [ ] **Passo 1: estendere il test d'integrazione** — dentro la transazione, dopo aver
  svuotato le tabelle interessate: il seed crea 4 aliquote visibili più una per ogni
  natura valida; un solo tipo fiscale; due regole; una riga di impostazioni con
  `fallback_tax_id` che punta davvero alla riga del 22%; una riga `gst_locations`
  legata alla sede predefinita; una seconda esecuzione non aggiunge niente.
- [ ] **Passo 2: eseguirlo** — fallisce.
- [ ] **Passo 3: scrivere il seed** — in `Defaults::seed()`, un metodo privato per
  gruppo (`taxes()`, `taxCategories()`, `taxRules()`, `settings()`, `location()`), con
  `DefaultRows::ensure()` e `ensureSingleton()`. Le regole e le impostazioni hanno
  bisogno degli `id` delle aliquote appena create: si rileggono con `Tax::find()`
  dopo l'inserimento, e se manca la riga il gruppo si salta senza errori.
- [ ] **Passo 4: rieseguire, poi `php tests/run.php`** — verde.
- [ ] **Passo 5: verifica sul sito di prova** —
  `cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php forge update`:
  crea le tabelle nuove e le righe, e una seconda esecuzione dice `defaults: 0`.
  Controllare che `shared/sync-data.json` contenga le tabelle sincronizzate e **non**
  `gst_document_sequences`, `gst_external_references`, `gst_merchant_settings`.
- [ ] **Passo 6: verifica nel browser** — aliquote, tipi fiscali, regole, le due
  pagine di impostazioni e la scheda della sede: si aprono, si salvano e mostrano il
  toast; con `APP_ENV=production` le pagine sincronizzate non si salvano e nella sede
  restano modificabili solo orari e chiusure. Rimettere `APP_ENV=local` alla fine.
- [ ] **Passo 7: aggiornare la documentazione** — `docs/dev/concetti/` con una pagina
  su codici, numerazione e log degli stati, e una su IVA e impostazioni; aggiungere le
  voci al `SUMMARY.md`.
- [ ] **Passo 8: commit e merge del ramo in `main`**, poi push.

## Verifica finale del piano

1. `php tests/run.php` verde, unitari e integrazione.
2. `php forge update` sul sito di prova: tabelle e righe create, seconda esecuzione a zero.
3. Backend nel browser: tutte le pagine nuove si aprono e si salvano.
4. `shared/sync-data.json` contiene solo le tabelle sincronizzate.
5. Spec aggiornata con le decisioni prese durante l'esecuzione, `TODO.md` allineato.
