# bindCommerce (marketplace): piano di lavoro

> **Per chi esegue:** usare superpowers:subagent-driven-development (consigliato) o
> superpowers:executing-plans, un task alla volta. I passi sono caselle (`- [ ]`).

**Obiettivo:** pubblicare il catalogo sui marketplace attraverso bindCommerce e
ricevere da lì gli ordini, con tracking che torna indietro.

**Architettura:** due attività dello scheduler, tutte e due partono da noi:
`gestionale.bindcommerce_products` manda il catalogo in XML al connettore
«import prodotti»; `gestionale.bindcommerce_orders` legge l'export ordini XML e
li trasforma in ordini del canale `marketplace`, già pagati. Quando una spedizione
parte, il tracking va a bindCommerce con un `StatusUpdate`. Niente endpoint
pubblici. Tutto ciò che trasforma XML è puro e si prova senza rete.

**Tecnologie:** PHP 8.2, XMLWriter (scrittura, come `XmlFeed`), SimpleXML
(lettura, nuovo nel codice), `Wonder\Api\Call` per HTTP, scheduler del core.

**Spec:** questo documento. Fonti: guide bindCommerce
[import-prodotti](https://www.bindcommerce.com/it/guide/api/import-prodotti),
[export-documenti-xml](https://www.bindcommerce.com/it/guide/api/export-ordini/export-documenti-xml)
e risposte dell'assistenza del 2026-10-26 (riassunte in «Cosa sappiamo»).

## Cosa sappiamo da bindCommerce

- L'import prodotti si elabora subito, a prescindere dal piano; l'invio ai
  canali segue la frequenza del piano.
- Nessun limite di chiamate né di dimensione dell'XML.
- «Esporta una sola volta» va **spenta**, se no gli aggiornamenti di un ordine
  (annullo, righe a zero) non arrivano: i doppioni li scartiamo noi.
- Derivati e listini generati da un listino base sono Premium; non ci servono
  perché mandiamo un prezzo già pronto.
- IVA: basta `Vat` su ogni `Price`.

## Ipotesi aperte (domande senza risposta)

- **A1 (Q1):** il nodo API conta come «integrazione gestionale» del piano e non
  come canale in più. Se conta come canale: +9–18 €/mese, nessun codice cambia.
- **A2 (Q4):** mentre un connettore lavora, `connector_status` risponde 201 e un
  secondo POST va evitato. Il task salta il giro (Task 3). Se invece il POST
  viene accodato, il controllo resta innocuo.
- **A3 (Q7):** non c'è sandbox. Le prove vere si fanno su un account di prova
  con un canale non pubblicato; i test automatici usano solo fixture.
- **A4:** `Mode="full"` disattiva in bindCommerce gli articoli che non sono nel
  file. Da verificare sull'account di prova prima di accendere il task in
  produzione; se non è così, Task 3 aggiunge il blocco `Delete` (vedi lì).
- **A5:** i nomi esatti dei tag dell'export ordini si prendono da un export reale
  (fixture del Task 4); quelli qui sotto vengono dalla guida.

## Vincoli

- Pacchetto `packages/gestionale`, namespace `Wonder\Plugin\Gestionale\`. Classi
  nuove in `src/Support/Marketplace/BindCommerce/`; task in `src/Scheduler/`.
- Commenti, nomi dei test e testi per l'utente in italiano; commenti brevi e
  solo dove servono. Nomi di classi, metodi, tabelle in inglese.
- Errori per l'utente con `UserError::make('bindcommerce.<chiave>')` e testo in
  `lang/it/gestionale.json` (e `lang/en/` se il file c'è).
- Credenziali solo da env, mai da DB né nel codice:
  `BINDCOMMERCE_URL` (es. `https://negozio.bindcommerce.com`),
  `BINDCOMMERCE_TOKEN`, `BINDCOMMERCE_PRODUCTS_CONNECTOR`,
  `BINDCOMMERCE_ORDERS_CONNECTOR`, `BINDCOMMERCE_DOCUMENTS_CONNECTOR`,
  `BINDCOMMERCE_PRICE_LIST` (il `ListCode`).
- Lettura XML: `simplexml_load_string($xml, options: LIBXML_NONET)`, niente
  entità esterne; un XML rotto è un errore del giro, non un'eccezione che
  ferma lo scheduler.
- Provider e oggetti nei registri: provider `bindcommerce`, `object_type`
  `order`; ambiente `live`.
- Test: `php tests/run.php` verde dopo ogni task. I test con DB stanno dentro
  `prova()` (transazione annullata). Tabelle/enum nuovi: `php forge update --local`
  sul sito `boilerplates/ecommerce-site`.
- `git add` file per file. Commit con
  `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Decisioni di perimetro

- **Un prezzo solo** per tutti i marketplace: quello che paga il cliente del
  sito (scontato se c'è, campagna compresa), IVA compresa. Ricarichi per
  marketplace: dopo, se servono (Premium lato bindCommerce o listino nostro).
- **Entrano** le righe che entrano nei feed (`ProductFeed::eligible`) con un
  codice (`sku`, se no `code`) e di tipo prodotto semplice. **Restano fuori**
  personalizzazioni con sovrapprezzo, bundle a scelta, servizi, abbonamenti.
- **Quantità:** la disponibile della sede principale; illimitata → 99; vendibile
  senza giacenza → si manda la giacenza vera (anche 0). Sui marketplace non si
  vende allo scoperto.
- **Cliente:** un contatto per marketplace («Cliente Amazon», «Cliente eBay»,
  creato al primo ordine) come `customer_id`; nome, indirizzi, email e telefono
  del compratore stanno sull'ordine. Niente account sul sito.
- **IVA degli ordini:** non ci fidiamo dell'aliquota del marketplace (eBay manda
  quella dell'inserzione, Amazon niente). Il lordo pagato per riga è il dato;
  l'aliquota è quella del nostro articolo; il netto si ricava.
- **Spedizioni:** un evento di spedizione per riga (bindCommerce non regge più
  spedizioni parziali della stessa riga). Una seconda spedizione che tocca una
  riga già spedita non si manda: resta l'errore nel registro.
- **Fatture:** fuori (G8 non c'è). Il `CreateModify` con PDF verrà con G8.
- **Lingua:** una sola, quella del catalogo.

## Review Focus

1. **Ordine che arriva due volte** (stesso `bindNumber`, stessa `LastUpdate`, o
   due giri sovrapposti): un ordine solo, la seconda volta `skipped`. → Task 5.
2. **SKU sconosciuto o versione spenta** in un ordine: l'ordine non si crea a
   metà; l'evento resta `failed` con lo SKU nel messaggio e il giro prosegue con
   gli altri ordini. → Task 5.
3. **Giacenza insufficiente** su un ordine già pagato sul marketplace: si importa
   lo stesso (la merce è venduta), l'ordine compare con l'avviso di mancanza.
   → Task 5.
4. **Lordo con centesimi che non tornano** (netto ricavato e riarrotondato): il
   totale dell'ordine è uguale al pagato del marketplace al centesimo. → Task 5.
5. **Testo con `&`, `<`, emoji, HTML nella descrizione** e categorie con `|` nel
   nome: l'XML resta valido, il `|` nel nome si sostituisce con `/`. → Task 2.

---

### Task 1: Funzionalità, configurazione e client

**File:**
- Modifica: `config/features.php` (voce `marketplace_bindcommerce`)
- Crea: `src/Support/Marketplace/BindCommerce/Config.php`
- Crea: `src/Support/Marketplace/BindCommerce/Client.php`
- Modifica: `lang/it/gestionale.json`
- Test: `tests/BindCommerceClientTest.php`

**Interfacce:**
- Produce:
  - `Config::fromEnv(): Config` con proprietà readonly `url`, `token`,
    `productsConnector`, `ordersConnector`, `documentsConnector`, `priceList`;
    `Config::complete(): bool` (tutti valorizzati).
  - `Client::__construct(Config $config, ?callable $transport = null)`;
    `$transport(string $method, string $url, array $headers, ?string $body): array{status: int, body: string}`
    — di serie usa `Wonder\Api\Call`, nei test una closure.
  - `Client::running(int $connector): bool` — GET
    `{url}/connector_status.php?id={connector}`: 201 → true, 200 → false,
    406 → `UserError bindcommerce.auth`, altro → `RuntimeException`.
  - `Client::pushProducts(string $xml): void` — POST al connettore prodotti,
    header `token`, `Content-Type: application/xml`.
  - `Client::orders(): string` — GET `{url}/export_orders.php?connector={ordersConnector}`.
  - `Client::documents(string $xml): void` — POST `{url}/documents.php?connector={documentsConnector}`.

- [ ] **Passo 1: voce di funzionalità** — `'marketplace_bindcommerce' => ['name' => 'bindCommerce', 'description' => 'Pubblicazione del catalogo sui marketplace e ricezione degli ordini attraverso bindCommerce.', 'area' => 'Esportazioni', 'release' => 'futura', 'created' => true]`.
- [ ] **Passo 2: test che falliscono**
  - `'Config: incompleta senza token'` → `complete()` false.
  - `'Client: 201 vuol dire connettore al lavoro'`, `'Client: 200 libero'`.
  - `'Client: 406 è un errore di credenziali'` → `UserError` con chiave `bindcommerce.auth`.
  - `'Client: il token va nell\'header, non nell\'indirizzo'` → la closure riceve
    `headers['token']` e l'URL non contiene il token.
- [ ] **Passo 3:** `php tests/BindCommerceClientTest.php` → FAIL (classi assenti).
- [ ] **Passo 4:** implementare `Config` e `Client`; chiave `bindcommerce.auth` in lang («bindCommerce ha rifiutato le credenziali: controlla token e connettori.»).
- [ ] **Passo 5:** `php tests/run.php` → tutto verde.
- [ ] **Passo 6:** commit «bindCommerce: funzionalità, configurazione e client».

### Task 2: XML dei prodotti

**File:**
- Modifica: `src/Support/Catalog/ProductExport.php` (chiavi nuove nelle righe)
- Crea: `src/Support/Marketplace/BindCommerce/ProductsXml.php`
- Test: `tests/BindCommerceProductsXmlTest.php`

**Interfacce:**
- Consuma: righe di `ProductExport::of()`, `ProductFeed::eligible/price/salePrice/gtin`.
- Produce:
  - righe di `ProductExport` con in più `code` (del prodotto), `model_code`,
    `attributes` (`array<string, string>`, nome attributo → valore) e
    `kind` (`product`, `bundle`, `service`, … il tipo grezzo, non l'etichetta).
    Le chiavi nuove **non** entrano nelle colonne dell'Esporta: controllare che
    `ProductExports` scriva solo le colonne dichiarate, e provarlo.
  - `ProductsXml::eligible(array $row): bool`
  - `ProductsXml::build(array $rows, bool $pricesIncludeTax, string $priceList): string`

**Forma dell'XML** (un `Product` per modello; le versioni come `Variant`; un
modello con una versione sola è un `Product` senza `Variants`):

```xml
<bindCommerceProducts Mode="full">
  <Products>
    <Product>
      <Code>MAG-1</Code><Title>Maglia</Title><DescriptionHtml>…</DescriptionHtml>
      <Manufacturer>Aurora</Manufacturer><Category>Abbigliamento|Maglie</Category>
      <Pictures><Picture><URL>https://…/a.jpg</URL></Picture>…</Pictures>
      <Variants>
        <Variant>
          <Code>MAG-1-BLU-S</Code><Barcode>0012345678905</Barcode><MPN>X-1</MPN>
          <Qty>4</Qty><Weight>0.4</Weight>
          <Prices><Price><ListCode>1</ListCode><Vat>22</Vat><NetPrice>24.51</NetPrice><GrossPrice>29.90</GrossPrice></Price></Prices>
          <Attributes><Attribute><Name>Colore</Name><Value>Blu</Value></Attribute>…</Attributes>
        </Variant>
      </Variants>
    </Product>
  </Products>
</bindCommerceProducts>
```

- [ ] **Passo 1: test che falliscono** (righe costruite come in `ProductFeedsTest`):
  - `'Prodotti: due versioni dello stesso modello sono un Product con due Variant'`.
  - `'Prodotti: senza sku si usa il codice'` → `Code` = `code`; modello senza
    `group` → `model_code`.
  - `'Prodotti: il prezzo è quello scontato, IVA compresa, con netto e aliquota'`
    → riga `price 29.9`, `sale_price 24.9`, `tax_rate 22`, catalogo IVA inclusa:
    `GrossPrice 24.90`, `NetPrice 20.41`, `Vat 22`; catalogo senza IVA,
    `price 20` → `GrossPrice 24.40`, `NetPrice 20.00`.
  - `'Prodotti: illimitata vale 99, vendibile senza giacenza vale la giacenza'`
    → `available null` → `99`; `available -2`, backorder `Sì` → `0`.
  - `'Prodotti: restano fuori bundle, servizi e personalizzazioni a pagamento'`.
  - `'Prodotti: categorie col pipe, la prima immagine è la principale'` →
    `'Abbigliamento › Maglie | Regali'` dà `Category` = `Abbigliamento|Maglie`
    (si prende il primo percorso; `›` diventa `|`; un `|` dentro un nome diventa `/`).
  - `'Prodotti: testo con & < emoji e HTML resta XML valido'` →
    `simplexml_load_string` non dà errori e il testo torna uguale.
  - `'Prodotti: le chiavi nuove non cambiano le colonne dell\'Esporta'`.
- [ ] **Passo 2:** `php tests/BindCommerceProductsXmlTest.php` → FAIL.
- [ ] **Passo 3:** chiavi nuove in `ProductExport`; `ProductsXml` con XMLWriter,
  stesso stile di `XmlFeed`. Netto = lordo / (1 + aliquota/100), arrotondato a 2.
- [ ] **Passo 4:** `php tests/run.php` → verde (anche `ProductFeedsTest` e i test
  dell'Esporta: non devono cambiare).
- [ ] **Passo 5:** commit «bindCommerce: XML dei prodotti».

### Task 3: Attività che manda il catalogo

**File:**
- Crea: `src/Support/Marketplace/BindCommerce/ProductsPush.php`
- Crea: `src/Scheduler/BindCommerceProductsTask.php`
- Modifica: `src/Gestionale.php:69` (aggiunta a `tasks()`)
- Test: `tests/BindCommerceProductsPushTest.php`

**Interfacce:**
- Consuma: `Client`, `ProductsXml::build`, `ProductFeed::of()`.
- Produce:
  - `ProductsPush::EVERY = [1 => 'Ogni ora', 3 => 'Ogni 3 ore', 6 => 'Ogni 6 ore']`, `DEFAULT_EVERY = 1`
    (frequenza nelle impostazioni della funzionalità, come `ProductFeeds::every`).
  - `ProductsPush::run(?callable $enabled = null, ?Client $client = null, ?callable $rows = null, ?int $lastAt = null, ?int $now = null): array{status: 'sent'|'busy'|'skipped'|'disabled', count: int}`
  - L'ora dell'ultimo invio riuscito sta in `gst_settings`, chiave
    `bindcommerce_products_sent_at` (stesso modo di `catalog_prices_include_tax`).
  - Task: chiave `gestionale.bindcommerce_products`, etichetta
    «Gestionale: catalogo verso bindCommerce», `'*/15 * * * *'`, nasce **accesa**
    come `ProductFeedsTask` (l'interruttore è la funzionalità).

- [ ] **Passo 1: test che falliscono**
  - `'Invio: funzionalità spenta non chiama nessuno'` → `disabled`, transport mai chiamato.
  - `'Invio: connettore al lavoro, si salta il giro'` → `busy`, nessun POST, ora non aggiornata.
  - `'Invio: non scaduto non manda'` → `skipped`.
  - `'Invio: scaduto manda e conta i prodotti'` → `sent`, un POST col body di `ProductsXml`.
  - `'Invio: credenziali mancanti, nessuna chiamata'` → `disabled` se `Config::complete()` è falso.
- [ ] **Passo 2:** FAIL.
- [ ] **Passo 3:** implementare; il task restituisce l'array di `run()`. Se A4
  risulta falsa, qui si aggiunge `Delete` coi codici mandati l'ultima volta e
  spariti (lista in `gst_settings`): non farlo prima della verifica.
- [ ] **Passo 4:** `php tests/run.php` → verde.
- [ ] **Passo 5:** commit «bindCommerce: attività che manda il catalogo».

### Task 4: Lettura dell'export ordini

**File:**
- Crea: `tests/fixtures/bindcommerce/ordini.xml` (da un export reale, dati
  personali sostituiti; almeno: ordine nuovo con due righe, ordine annullato,
  ordine con una riga a quantità 0, cliente con P.IVA/SDI)
- Crea: `src/Support/Marketplace/BindCommerce/OrdersXml.php`
- Test: `tests/BindCommerceOrdersXmlTest.php`

**Interfacce:**
- Produce: `OrdersXml::parse(string $xml): list<array>` con, per documento:
  ```
  bind_number, order_number, marketplace, updated_at (Y-m-d H:i:s), cancelled (bool),
  email, phone,
  billing: {name, company, vat_number, tax_code, sdi, pec, street, zip, city, province, country},
  shipping: {name, street, zip, city, province, country},
  rows: list<{code, title, quantity (float), gross_total (float)}>,
  shipping_gross (float), total_gross (float), payment_method
  ```
  Tag di partenza (guida; confermare sulla fixture, A5): `Document/General`
  (`bindNumber`, `Number`, `Source`, `LastUpdate`, `State`), `Customer`,
  `Delivery`, `Rows/Row` (`Code`, `Description`, `Qty`, `Price`/`Total`),
  `Amounts`, `Shipping`, `Payments`.
  `parse` lancia `UserError bindcommerce.bad_xml` su XML rotto.

- [ ] **Passo 1: test che falliscono** sulla fixture:
  - `'Ordini XML: legge numero, marketplace, righe e totali'`.
  - `'Ordini XML: lo stato annullato diventa cancelled'`.
  - `'Ordini XML: la riga a zero resta, con quantità 0'`.
  - `'Ordini XML: SDI e PEC finiscono nella fatturazione'`.
  - `'Ordini XML: XML rotto è bad_xml'`; `'Ordini XML: nessun documento è lista vuota'`.
- [ ] **Passo 2:** FAIL. **Passo 3:** implementare. **Passo 4:** verde.
- [ ] **Passo 5:** commit «bindCommerce: lettura dell'export ordini».

### Task 5: Ordini del marketplace nel gestionale

**File:**
- Modifica: `src/Models/Sales/Order.php` (`CHANNELS` + `marketplace`)
- Modifica: `src/Models/Payments/PaymentMethod.php` (`PROVIDERS` + `marketplace`)
- Modifica: `src/Support/Sales/OfficeOrders.php` (`save` con canale; `confirm`
  con `check_stock` e `payment`; `cancelLines` accetta anche `marketplace`)
- Modifica: `src/Support/Providers/ExternalReferences.php` (`findByExternal`)
- Modifica: etichette del canale e del provider dove stanno le altre
  (`StatusLabels` / lang) e `lang/it/gestionale.json`
- Crea: `src/Support/Marketplace/BindCommerce/MarketplaceOrders.php`
- Test: `tests/integrazione/BindCommerceOrdersTest.php`

**Interfacce:**
- Consuma: `OrdersXml::parse` (forma del Task 4), `ProviderEvents`, `Lifecycle::cancel`.
- Produce:
  - `OfficeOrders::save(int $orderId, array $data, int $userId = 0, string $stage = 'order', string $channel = 'office'): int`
    — `$channel` deve stare in `Order::CHANNELS`, se no `InvalidArgumentException`.
  - `OfficeOrders::confirm(..., $options)` con in più `check_stock` (bool, di
    serie true) e `payment` (array `{provider, provider_reference, amount}` o
    assente): con `payment` passa `payment => true` e quei campi a
    `Lifecycle::confirm`. Il comportamento di serie non cambia.
  - `ExternalReferences::findByExternal(string $provider, string $objectType, string $externalId, string $environment = 'live'): ?array`
  - `MarketplaceOrders::import(array $document): array{action: 'created'|'updated'|'cancelled'|'skipped'|'failed', order_id: ?int, error?: string}`
  - `MarketplaceOrders::customerFor(string $marketplace): int` (contatto «Cliente <Marketplace>», creato una volta).

**Regole di `import`:**
- Evento `bind_number.'@'.updated_at` in `ProviderEvents::receive('bindcommerce', …, 'order', $document)`;
  `false` → `skipped`.
- Ordine non ancora nostro (`findByExternal` vuoto) e non annullato → bozza con
  `save(..., 'order', 'marketplace')`: righe trovate per `sku`, se no per
  `code` del prodotto; `manual_unit_price` = lordo riga / quantità, portato al
  netto con l'aliquota del nostro articolo se il catalogo è senza IVA;
  `shipping_amount` allo stesso modo; `customer_note` = «<Marketplace> n. <order_number>».
  Poi `confirm(..., ['check_stock' => false, 'notify' => false, 'source' => 'bindcommerce', 'payment' => ['provider' => 'marketplace', 'provider_reference' => bind_number, 'amount' => total_gross]])`
  e `ExternalReferences::save('order', $id, 'bindcommerce', 'order', bind_number)`.
  Tutto in una `Transaction::run`: o c'è l'ordine intero o niente.
- Se il totale della bozza non è uguale al `total_gross` al centesimo → la
  differenza si mette sull'ultima riga (prezzo a mano) prima della conferma; se
  resta diverso, `failed` con `bindcommerce.total_mismatch`.
- `check_stock => false` salta solo `assertStock`: verificare che anche
  `Allocation::commit` accetti una giacenza che va sotto zero; se no, il test
  di RF 3 lo mostra e `commit` riceve la stessa opzione.
- Ordine già nostro e `cancelled` → `Lifecycle::cancel($id, ['reason' => 'marketplace', 'source' => 'bindcommerce', 'notify' => false])` → `cancelled`.
- Ordine già nostro con righe a quantità 0 → `cancelLines` di quelle righe → `updated`.
- Altro aggiornamento di un ordine già nostro → nessuna modifica, `updated`.
- Errore → `markFailed` con il messaggio (SKU sconosciuto: `final = true`), `failed`;
  riuscito → `markProcessed`.

- [ ] **Passo 1:** canale e provider negli enum; `php forge update --local` sul
  sito di prova; etichette («Marketplace»).
- [ ] **Passo 2: test che falliscono** (dentro `prova()`, documenti come quelli
  di `parse`):
  - `'Marketplace: ordine nuovo confermato, pagato, canale marketplace'` → stato
    confermato, pagamento registrato col `bind_number`, riferimento esterno salvato.
  - `'Marketplace: lo stesso aggiornamento due volte è skipped'` (Review Focus 1).
  - `'Marketplace: due giri con LastUpdate diverse non creano un secondo ordine'`.
  - `'Marketplace: SKU sconosciuto non lascia bozze e fallisce definitivo'` (RF 2).
  - `'Marketplace: senza giacenza si importa lo stesso'` (RF 3).
  - `'Marketplace: il totale è il pagato al centesimo'` → righe `3 × 9.99` lordo,
    IVA 22, catalogo senza IVA, spedizione 4.90 → totale `34.87` (RF 4).
  - `'Marketplace: annullo totale annulla e libera la merce'`.
  - `'Marketplace: riga a zero annulla solo quella riga'`.
  - `'Marketplace: un contatto per marketplace, non uno per ordine'`.
  - `'Ufficio: confirm di serie controlla ancora la giacenza'` (non regressione).
- [ ] **Passo 3:** FAIL. **Passo 4:** implementare. **Passo 5:** `php tests/run.php` → verde
  (anche tutti i test degli ordini da ufficio).
- [ ] **Passo 6:** commit «bindCommerce: ordini del marketplace».

### Task 6: Attività che legge gli ordini

**File:**
- Crea: `src/Scheduler/BindCommerceOrdersTask.php`
- Crea: `src/Support/Marketplace/BindCommerce/OrdersPull.php`
- Modifica: `src/Gestionale.php:69`
- Test: `tests/BindCommerceOrdersPullTest.php`

**Interfacce:**
- Consuma: `Client::orders`, `OrdersXml::parse`, `MarketplaceOrders::import`.
- Produce: `OrdersPull::run(?callable $enabled = null, ?Client $client = null, ?callable $import = null): array{created: int, updated: int, cancelled: int, skipped: int, failed: list<string>}`;
  task `gestionale.bindcommerce_orders`, «Gestionale: ordini da bindCommerce»,
  `'*/10 * * * *'`, nasce accesa.

- [ ] **Passo 1: test che falliscono**
  - `'Lettura: un ordine che fallisce non ferma gli altri'` → tre documenti, il
    secondo `failed`: gli altri due importati, `failed` col `bind_number`.
  - `'Lettura: XML rotto finisce nel risultato, non come eccezione'`.
  - `'Lettura: funzionalità spenta non chiama nessuno'`.
- [ ] **Passo 2:** FAIL. **Passo 3:** implementare. **Passo 4:** verde.
- [ ] **Passo 5:** nota in `docs/` per chi configura bindCommerce: filtro
  ordini per intervallo di tempo (es. ultimi 7 giorni), «Esporta una sola volta»
  **spenta**, stato «Pagato»; tabella «Conversione stati ordine» con
  `shipped` → stato spedito.
- [ ] **Passo 6:** commit «bindCommerce: attività che legge gli ordini».

### Task 7: Tracking verso bindCommerce

**File:**
- Crea: `src/Support/Marketplace/BindCommerce/StatusUpdates.php`
- Modifica: `src/Support/Shipping/Shipments.php` (dopo `ship` riuscito)
- Test: `tests/BindCommerceStatusUpdateTest.php`, `tests/integrazione/BindCommerceShipTest.php`

**Interfacce:**
- Consuma: `ExternalReferences::find('order', $orderId, 'bindcommerce', 'order')`, `Client::documents`.
- Produce:
  - `StatusUpdates::xml(string $bindNumber, string $carrier, string $tracking, string $shippedAt): string`
    → `<bindCommerceDocuments mode="StatusUpdate"><Document><bindNumber>…</bindNumber><State>shipped</State><Shipping><Carrier>…</Carrier><TrackingNumber>…</TrackingNumber><ShippedTime>…</ShippedTime></Shipping></Document></bindCommerceDocuments>`
  - `StatusUpdates::shipped(int $shipmentId, ?Client $client = null): bool` —
    false (senza chiamate) se l'ordine non viene da bindCommerce.
- Il richiamo sta in `Shipments::ship` **e** in `advance` (che usa `shipInside`
  e manda la sua email), **fuori** dalla transazione, accanto a `notify`, dentro `try/catch`: un guasto di bindCommerce non annulla la
  spedizione; l'errore va in `ExternalReferences::fail(..., 'shipment', ...)` e
  nel log (`Errors::internal`). Un secondo invio per lo stesso ordine con righe
  già spedite: non si manda, si scrive l'errore (decisione di perimetro).

- [ ] **Passo 1: test che falliscono**
  - `'Tracking: XML con bindNumber, corriere, numero e data'`.
  - `'Tracking: ordine del sito non chiama bindCommerce'`.
  - `'Tracking: bindCommerce giù, la spedizione resta partita'` (integrazione).
- [ ] **Passo 2:** FAIL. **Passo 3:** implementare. **Passo 4:** `php tests/run.php` → verde.
- [ ] **Passo 5:** commit «bindCommerce: tracking delle spedizioni».

### Dopo i task (a mano, sull'account di prova bindCommerce)

1. Verificare A4 (`Mode="full"`) e A2 (201 durante l'elaborazione).
2. Un ordine finto da un canale di prova fino alla spedizione: tracking visibile su bindCommerce.
3. Solo dopo: funzionalità accesa in produzione.
