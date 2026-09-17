# Architettura `wonder-image/gestionale` + `wonder-image/ecommerce`

- **Stato:** approvata il 2026-09-16
- **Brainstorming:** dal 2026-09-11 al 2026-09-16, decisioni D1–D59
- **Avanzamento:** [TODO.md](../../../TODO.md)
- **Storia:** il registro cronologico delle decisioni è nel commit `4fa9852`

> Ogni regola riporta tra parentesi la decisione da cui nasce (es. D27); l'indice
> delle decisioni è nell'appendice A. I dettagli lasciati alle spec dei singoli
> sotto-progetti sono elencati nel capitolo 11. **Ciò che non compare in questo
> documento non è deciso.**

## Indice

1. Obiettivo e principi
2. Pacchetti e confini
3. Funzionalità e accessi
4. Modello dati
5. Flussi
6. Integrazioni e provider
7. Estensibilità per sito
8. Installazione, dati iniziali e ambienti
9. Errori, test e documentazione
10. Roadmap
11. Rimandato alle spec dei sotto-progetti

Appendici: A. Indice delle decisioni · B. Vincoli del framework · C. Riferimento
funzionale dai progetti analizzati · D. Convenzioni dei moduli esistenti ·
E. Correzioni della revisione finale

## 1. Obiettivo e principi

### 1.1 Obiettivo

Due moduli per `wonder-image/app` che permettano di avviare e gestire gestionali
ed ecommerce dei commercianti con il minimo lavoro per progetto: predisposti al
massimo, con le funzionalità sbloccabili dal pannello e la massima
personalizzazione per sito.

### 1.2 Vocabolario

| Termine | Significato |
|---|---|
| Commerciante | Il cliente di Wonder Image, proprietario del sito. I dati aziendali sono già nel core (`Society`, `SocietyLegalAddress`). |
| Anagrafica | Scheda unica con ruolo cliente e/o fornitore: un fornitore può essere anche cliente e viceversa. |
| Cliente | Chi acquista e/o si iscrive presso il commerciante, con o senza account sul sito. |
| Fornitore | Chi vende i prodotti al commerciante. |
| Brand | Marchio del prodotto, indipendente dal fornitore. |
| Sede | Luogo del commerciante con magazzino proprio e/o punto di ritiro o vendita. |
| `admin` | Ruolo backend di Wonder Image. |
| `administrator` | Ruolo backend del commerciante. |
| Tabella sincronizzata | Configurazione che si modifica in locale e arriva in produzione con il deploy (D54). |
| Nucleo | L'insieme dei sotto-progetti del primo rilascio `1.0.0` (D59). |

### 1.3 Principi

- **Semplice per chi è piccolo, completo per chi cresce (D20):** la complessità
  compare solo con le funzionalità sbloccate; un campo con una sola scelta
  possibile non si mostra; le scelte fiscali e tecniche le fa `admin` una volta in
  configurazione e il commerciante vede solo il lavoro di tutti i giorni. Una
  bottega con pochi ordini a settimana e un distributore B2B usano lo stesso
  gestionale, con due pannelli molto diversi.
- **Predisposto al massimo, sbloccabile dal pannello (D3, D13):** capitolo 3.
- **Tabelle condivise (D9):** il gestionale possiede le tabelle, l'ecommerce usa
  direttamente Model e classi `Support` del gestionale, senza eventi tra i
  pacchetti; i controlli delle funzionalità stanno dove servono.
  *Scartati:* domini con servizi, eventi e controllo centralizzato (più struttura
  iniziale); un pacchetto Composer per funzionalità (contraddice lo sblocco dal
  pannello e moltiplica i pacchetti da versionare).
- **Nessuna migrazione (D5):** solo nuovi progetti, senza vincoli di compatibilità
  con i progetti analizzati, che servono solo come elenco di funzionalità
  (appendice C).
- **Lingue e valute (D4):** contenuti del catalogo in una sola lingua per ora
  (nome, descrizione, attributi, slug, personalizzazioni); eventuali traduzioni
  future con tabelle `*_translations`, senza cambiare le tabelle principali;
  interfaccia traducibile con i file `lang/`; prezzi solo in EUR, con la valuta
  salvata sugli importi.
- **Nomi in inglese (D22)** per tabelle, colonne e classi (2.5).
- **Ordine dei lavori (D8, D58):** dipendenze tecniche, priorità all'online,
  rilascio quando il nucleo è completo (capitolo 10).

### 1.4 Canali di vendita (D7)

| Canale | Pacchetto | Rilascio |
|---|---|---|
| Online B2C | ecommerce | nucleo (E1) |
| Vendita da ufficio: preventivi e ordini | gestionale | dopo il primo rilascio (G9) |
| Portale B2B online: clienti aziendali con listino e condizioni proprie, sui dati del gestionale | ecommerce | dopo il primo rilascio (E4) |
| Banco in sede: scarica la giacenza della sede; documento commerciale e corrispettivi telematici con registratore telematico o servizio abilitato | gestionale | funzionalità futura (D43) |
| Marketplace e feed | — | funzionalità futura |

### 1.5 Requisiti nuovi

Assenti, o presenti solo in forma base, nei progetti analizzati:

| # | Requisito | Dove |
|---|---|---|
| 1 | Multiprodotto: un prodotto composto da più prodotti; lo scarico dal magazzino scarica tutti i componenti | 4.2 (D23) |
| 2 | Sconto massivo: in blocco per categoria o tag, con data e ora di inizio e fine, in € o %, attivabile e disattivabile | 4.6 (D34) |
| 3 | Personalizzazione all'acquisto di prodotti e multiprodotti: nome, scelta del componente, altro | 4.2 (D23) |
| 4 | Funzionalità sbloccabili dal pannello: multiprodotto, fatturazione, spedizioniere, sconto massivo e le altre | 3 (D13) |
| 5 | Gestione IVA come spingy, per la fatturazione elettronica | 4.5 (D18, D21) |
| 6 | DDT e fattura differita, lotti e scadenze | 4.9, 4.3 |
| 7 | Codici con prefisso per tutte le entità principali (`pay_…`, `inv_…`, `pro_…`, `con_…`) | 4.1 (D25) |
| 8 | Log degli stati di ordini e magazzino | 4.1 (D26) |
| 9 | Avvisi email di scorta minima definita dal commerciante | 4.3 (D27) |
| 10 | Pagamenti tracciati: ogni pagamento in una tabella, con le fatture | 4.8, 4.12 (D28) |
| 11 | Codici EAN e MPN sui prodotti, oltre allo SKU | 4.2 (D23) |
| 12 | Etichette con codice a barre dei prodotti | funzionalità futura (D30, D59) |
| 13 | Guide per sviluppatori e per commercianti, con rimandi dal backend e indicazione "inclusa" o "da attivare" | 9.5 (D32) |

### 1.6 Fuori perimetro (D16)

- Annunci e popup: servono a qualsiasi sito, non solo agli ecommerce.
- Agenti, commissioni e payout, giochi, analytics e impersonificazione di spingy:
  specifici di quel progetto.

## 2. Pacchetti e confini

### 2.1 Due pacchetti (D1, D2)

`wonder-image/gestionale` funziona da solo; `wonder-image/ecommerce` lo richiede
(`dependencies.modules: ["gestionale"]`). Gli usi senza vendita (noleggio,
produzione, inventario interno) installano il gestionale e lasciano bloccate le
funzionalità di vendita.

| | gestionale | ecommerce |
|---|---|---|
| Composer | `wonder-image/gestionale` | `wonder-image/ecommerce` |
| Slug | `gestionale` | `ecommerce` |
| Namespace | `Wonder\Plugin\Gestionale\` | `Wonder\Plugin\Ecommerce\` |
| Dipendenze | — | `gestionale` |
| Contenuto | dati e backend | frontend e flussi online |

**Perimetro del gestionale (D2):** catalogo; magazzino multi-sede; anagrafiche
clienti e fornitori; listini per cliente; preventivi da cui nasce la vendita;
ordini e resi; fatturazione; spedizioni; vendita al banco (funzionalità futura).

*Scartato:* tre pacchetti (magazzino → gestionale → ecommerce). Passare da solo
magazzino a vendite richiederebbe `composer require` e deploy, e le modifiche
trasversali (es. multiprodotto) rilasci coordinati su tre pacchetti.

### 2.2 Proprietà di dati e backend (D10)

**Gestionale = dati + backend.**

- Tutte le tabelle di entrambi i pacchetti, comprese quelle che servono solo
  online: carrello, account clienti, pagamenti online, campagne di sconto,
  personalizzazioni, impostazioni del negozio.
- Tutte le pagine di backend, comprese quelle della vendita online (ordini
  online, carrelli abbandonati, impostazioni del negozio, portale B2B). Queste
  pagine e i campi online (es. "visibile online" sul prodotto) compaiono solo con
  l'ecommerce abilitato e la funzionalità attiva.

**Ecommerce = frontend + flussi online.**

- Nessuna tabella e nessuna pagina di backend.
- Pagine e componenti del negozio, area cliente, portale B2B lato cliente, API
  del frontend, checkout, gateway di pagamento online.

**Beneficio:** un commerciante che ha il gestionale aggiunge l'ecommerce senza
migrazioni né import: prodotti, giacenze, clienti e listini sono subito vendibili
online.

**Struttura interna** (come immobili: radice trasversale, sottocartella = area):

```
gestionale/src/Models/{Catalog,Inventory,Locations,Contacts,Pricing,Tax,Sales,Promotions,
                       Invoicing,Shipping,Pos,OnlineStore,System}/
gestionale/src/Resources/…   stesse aree
gestionale/src/Support/      logica condivisa (prezzi e IVA, importi, codici documento)
gestionale/src/Providers/    contratti e adattatori delle integrazioni (6.1)

ecommerce/src/{Storefront,Account,Cart,Checkout,B2B}/   flussi online
ecommerce/src/Providers/     adattatori dei gateway di pagamento online
ecommerce/http/  view/  lang/  config/
```

### 2.3 Frontend dell'ecommerce (D6, D11, D12)

- **Pagine complete e componenti** su `wonder-image/lib`: catalogo, scheda
  prodotto, carrello, checkout, area cliente con ordini, indirizzi, resi e
  abbonamenti; API JSON per le parti dinamiche. Login, registrazione e area
  cliente sono dell'ecommerce: il core ha pagine account solo per il backend e
  `new-site` non ne fornisce.
- **Modulo, non boilerplate:** la logica resta nel pacchetto e si aggiorna con
  `composer update` (checkout, pagamenti e webhook, prenotazione delle giacenze,
  sconti e listini, creazione dell'ordine, area cliente). La libertà grafica passa
  dal publish delle view (capitolo 7).
- **Regole delle view:** nessuna logica, ricevono dati pronti (prezzo finale,
  disponibilità, errori); contratto dei dati documentato per ogni view, con i cambi
  segnalati nel changelog.
- **Starter (D12):** dopo il primo rilascio, uno starter basato su
  `boilerplates/ecommerce-site` (D58) con gestionale ed ecommerce abilitati e view
  pubblicate; solo configurazione, nessuna logica.

### 2.4 Credenziali nel core (D14)

Tutte le credenziali delle integrazioni stanno in `wonder-image/app`:
`Credentials::api()`, tabella `security`, `.env` che sovrascrive in locale, pagina
backend di `admin`. Possono servire ad altri moduli e le classi di integrazione
vivono nel core; nei moduli resta solo l'adattamento al dominio.

- **Già presenti:** Stripe, Fatture in Cloud.
- **Da aggiungere con E2:** PayPal e Nexi (le classi esistono, ma le chiavi le
  passa il chiamante), con alias e chiave MAC di Nexi (D50).
- **Integrazioni future** (corrieri, registratore telematico): classe e credenziali
  nel core.

*Scartato:* tabella delle credenziali nel gestionale, che duplicherebbe il core e
non servirebbe agli altri moduli.

### 2.5 Nomi (D22)

**Invariati:** pacchetto `wonder-image/gestionale`, slug `gestionale`, namespace
`Wonder\Plugin\Gestionale\` (identità del modulo, come immobili). Prefisso delle
tabelle `gestionale_`; in questo documento i nomi delle tabelle sono scritti senza
prefisso.

**Convenzioni:**

- tabelle al plurale in snake_case, classi al singolare in PascalCase
  (`gestionale_product_models` → `ProductModel`);
- chiavi esterne `<entità>_id`, con indici;
- chiavi verso `contacts` con il nome del ruolo: `customer_id`, `supplier_id`;
- colonne tecniche del framework (`deleted`, `creation`, …) e colonne delle
  estensioni del core (es. `AddressExtension`: `cap`, `cf`, `pi`, `sdi`, `pec`)
  invariate.

**Nomi di spingy riusati:** `taxes`, `tax_rules`, `payment_methods`,
`payment_accounts`, `payments`, `invoices`, `subscriptions`, `plans`,
`plan_prices`, `coupons`. Corretti: `client` → `customer`; `product_type` →
`tax_category_id`; `plan_futures` → `plan_features`; `subscription_plan_history` →
`subscription_plan_changes`.

**Nomi obbligati:** `ProductModel` e non `Model` (classe base
`Wonder\App\Model`); `SalesReturn` e non `Return` (parola riservata di PHP).

**Aree:** gestionale `Catalog`, `Inventory`, `Locations`, `Contacts`, `Pricing`,
`Tax`, `Sales`, `Promotions`, `Invoicing`, `Shipping`, `Pos`, `OnlineStore`,
`System`; ecommerce `Storefront`, `Account`, `Cart`, `Checkout`, `B2B`.

**Glossario.**

| Termine | Inglese | Tabelle |
|---|---|---|
| Modello, variante, prodotto | product model, product variant, product | `product_models`, `product_variants`, `products` |
| Brand, categoria, tag | brand, category, tag | `brands`, `categories`, `tags` |
| Attributo | attribute | `attributes`, `attribute_values` |
| Multiprodotto | bundle | `bundle_components`, `bundle_groups`, `bundle_group_options` |
| Personalizzazione | customization | `customization_fields`, `customization_field_options` |
| Formato etichetta | label format | `label_formats` |
| Anagrafica, cliente, fornitore | contact, customer, supplier | `contacts`, `contact_addresses` |
| Sede | location | `locations` (estende `society_locations` del core; orari e chiusure nel core) |
| Documento di magazzino | stock document | `stock_documents`, `stock_document_items` |
| Fornitore del prodotto | product supplier | `product_suppliers` |
| Giacenza, movimento, prenotazione | stock, stock movement, stock reservation | `stock`, `stock_movements`, `stock_reservations` |
| Avviso di scorta minima | low stock alert | `stock_alerts` |
| Lotto | batch | `batches` |
| Listino | price list | `price_lists`, `price_list_items` |
| Sconto massivo | discount campaign | `discount_campaigns`, `discount_campaign_categories`, `discount_campaign_tags`, `discount_campaign_brands`, `discount_campaign_product_models` |
| Coupon, buono a scalare | coupon, store credit | `coupons`, `coupon_categories`, `coupon_tags`, `coupon_brands`, `coupon_product_models`, `coupon_customers`, `coupon_redemptions` |
| Carrello, preventivo, ordine | cart, quote, order | `orders` (fasi `cart`, `quote`, `order`), `order_items`, `order_tax_summaries`, `order_quote_revisions` |
| DDT, reso | delivery note, sales return | `delivery_notes`, `delivery_note_items`, `sales_returns`, `sales_return_items` |
| Pagamento, fattura | payment, invoice | `payments`, `invoices`, `invoice_items`, `invoice_tax_summaries`, `invoice_delivery_notes` |
| Metodo, conto e condizione di pagamento | payment method, payment account, payment term | `payment_methods`, `payment_accounts`, `payment_terms`, `payment_term_installments` |
| Aliquota, regola IVA, tipo fiscale | tax, tax rule, tax category | `taxes`, `tax_rules`, `tax_categories` |
| Spedizione, corriere | shipment, carrier | `shipments`, `shipment_items`, `carriers`, `shipping_methods`, `shipping_zones`, `shipping_zone_areas`, `shipping_rates`, `shipping_rate_brackets` |
| Abbonamento, piano | subscription, plan | `subscriptions`, `subscription_plan_changes`, `plans`, `plan_features`, `plan_prices` |
| Banco | point of sale | prefisso `pos_`, `fiscal_receipts` (bozza, 10.5) |
| Funzionalità | feature | `features`, `feature_logs` |
| Impostazioni | settings | due righe uniche, tecniche e del commerciante (8.2) |
| Numerazione dei documenti | document sequence | `document_sequences` |
| Riferimento esterno | external reference | `external_references` |
| Evento del provider | provider event | `provider_events` |
| Segnalazione di errore | error report | `error_reports` |
| Log degli stati | status log | `<entità>_status_logs` |

## 3. Funzionalità e accessi

### 3.1 Funzionamento (D13, D53, D54)

**Catalogo nel codice.** Ogni pacchetto elenca le proprie funzionalità in
`config/features.php`: chiave, nome e descrizione; dipendenze; modulo richiesto
(es. `ecommerce`); comportamento dei dati quando la funzionalità viene bloccata. Il
sito può aggiungere voci proprie dalla configurazione (D52).

**Stato su database.**

- `features`: una riga per funzionalità, sbloccata sì/no, chi l'ha modificata e
  quando; `feature_logs`: storico dei cambi.
- Bloccare non cancella mai i dati: risbloccando si ritrova tutto.
- Le righe nascono bloccate; il sito può elencare in configurazione le
  funzionalità da sbloccare quando la riga nasce, mai dopo (D53).
- Tabelle sincronizzate: lo sblocco si fa in locale e arriva in produzione con il
  deploy (D54).

**Stato effettivo.** Una funzionalità è attiva solo se è sbloccata, tutte le sue
dipendenze sono attive e il modulo richiesto è abilitato. Si legge sempre da
`Gestionale::feature('<chiave>')`, calcolato una volta per richiesta e usato da
entrambi i pacchetti.

**Una funzionalità bloccata sparisce ovunque:**

- **backend:** voce di menu, pagine, API, campi e colonne collegati nei form
  condivisi (es. i componenti nella scheda prodotto se il multiprodotto è bloccato);
- **frontend dell'ecommerce:** route non registrate, componenti non mostrati;
- **processi automatici:** i cron della funzionalità non fanno nulla.

Verifica tecnica: non risulta una cache delle route; il registrar legge
`pageSchema()` e `permissionSchema()` quando registra le route; il menu backend
chiama `navigationSchema()` (con `enabled()`) di ogni Resource mentre lo costruisce.

**Convenzione contro le dimenticanze:**

- ogni Resource dei moduli dichiara la propria funzionalità con
  `public static string $feature`;
- una Resource base del gestionale applica quel valore a menu, pagine e API;
- un test verifica che nessuna Resource ne sia priva, tranne quelle sempre attive
  (9.4).

I controlli in view, flussi e cron restano dove servono.

### 3.2 Pannello Funzionalità (D13, D54)

- Pagina della sezione Sistema, solo per `admin`.
- Funzionalità raggruppate per area, ciascuna con interruttore, dipendenze e
  ultimo cambio.
- Badge "richiede ecommerce", con interruttore disattivato se il modulo non è
  abilitato.
- Lo sblocco propone di sbloccare anche le dipendenze; il blocco mostra quali
  funzionalità smetteranno di funzionare e chiede conferma.
- Si usa in locale; in produzione è in sola lettura.
- Nel backend le funzionalità bloccate restano nascoste: è la guida commercianti a
  mostrare cosa si può attivare (D32).

### 3.3 Ruoli (D3, D13, D54, D56)

| Ruolo | Chi | Cosa vede e fa |
|---|---|---|
| `admin` | Wonder Image | tutto; sblocco delle funzionalità; configurazione tecnica (metodi di pagamento, spedizioni e corrieri, sedi e orari, credenziali delle integrazioni, dati fiscali e numerazione dei documenti); pagina "Errori" |
| `administrator` | commerciante | solo le funzionalità attive, per il lavoro quotidiano: catalogo, giacenze, anagrafiche, preventivi, ordini, campagne di sconto, chiusure delle sedi, errori da risolvere dal commerciante |

Eventuali ruoli operativi (es. addetto al banco) si valutano nei sotto-progetti
che li richiedono.

### 3.4 Mappa delle funzionalità (D16, D22, D59)

**Sempre attive nel gestionale** (servono anche agli usi senza vendita):

- **Catalogo:** brand, categorie, tag, attributi, modelli, varianti, prodotti,
  immagini.
- **Magazzino:** giacenze della sede principale, movimenti con causale,
  rettifiche.
- **Anagrafiche:** clienti e fornitori, indirizzi, dati di fatturazione.
- **Sistema:** pannello Funzionalità, impostazioni, configurazione tecnica,
  riquadri "Primi passi" e "Da controllare".

**Sbloccabili nel gestionale:**

| Area | Funzionalità | Chiave | Cosa aggiunge | Richiede | Rilascio |
|---|---|---|---|---|---|
| Vendite | Ordini | `orders` | gestione degli ordini con stati e scarico del magazzino; base dei canali di vendita; creazione da ufficio con G9 | — | G4 |
| Vendite | Preventivi | `quotes` | preventivi con PDF e revisioni, convertibili in ordine | Ordini | G9 |
| Vendite | Resi | `returns` | richiesta, approvazione, motivo e ricarico a magazzino | Ordini | G4 |
| Vendite | DDT | `delivery_notes` | documento di trasporto per le consegne | Ordini | G9 |
| Vendite | Abbonamenti | `subscriptions` | piani, rinnovi, cambio piano; entità separata dagli ordini (D17) | — | G10 |
| Catalogo | Multiprodotto | `bundles` | prodotti composti da altri prodotti: vendendoli si scaricano i componenti | — | G5 |
| Catalogo | Personalizzazione | `customizations` | campi compilati al momento della vendita (testo, scelta, scelta del componente) con eventuale sovrapprezzo, salvati sulla riga | Ordini (scelta del componente: anche Multiprodotto) | G5 |
| Catalogo | Etichette | `barcode_labels` | etichette con codice a barre e prezzo in PDF, su foglio A4 o rotolo termico | — | futura |
| Magazzino | Più sedi | `multi_location` | altre sedi con giacenze proprie e trasferimenti | — | G3 |
| Magazzino | Acquisti | `purchasing` | costi d'acquisto per fornitore, documenti di carico, valore del magazzino | — | G3 |
| Magazzino | Lotti e scadenze | `batch_tracking` | lotto e data di scadenza su carichi e scarichi | — | G3 |
| Magazzino | Avvisi di scorta minima | `low_stock_alerts` | email e riquadro della dashboard per i prodotti sotto la propria scorta minima | — | G2 |
| Listini | Listini cliente | `customer_price_lists` | prezzi personalizzati per cliente, con scaglioni | Ordini | G6 |
| Promozioni | Sconto massivo | `discount_campaigns` | campagne per categoria, tag, brand o modello, con data e ora, in € o %, attivabili e disattivabili | Ordini | G6 |
| Promozioni | Coupon | `coupons` | importo, percentuale, buono a scalare, spedizione gratuita, con limiti | Ordini | G6 |
| Fatturazione | Fatturazione elettronica | `e_invoicing` | fatture, invio SDI, coda, stati, notifiche; fattura ciò che producono le funzionalità di vendita attive | — | G8 |
| Fatturazione | Fattura differita | `deferred_invoicing` | fattura riepilogativa dei DDT del periodo | DDT, Fatturazione elettronica | G9 |
| Spedizioni | Spedizioni | `shipping` | listini per zona e peso, spedizioni e tracking manuali, ritiro in sede | Ordini | G7 |
| Spedizioni | Corrieri | `carriers` | etichette e tracking dal corriere | Spedizioni | futura |
| Banco | Banco | `pos` | vendita in sede con documento commerciale e corrispettivi | Ordini | futura |

**Dichiarate dall'ecommerce** (richiedono il modulo abilitato):

| Funzionalità | Chiave | Cosa aggiunge | Richiede | Rilascio |
|---|---|---|---|---|
| Negozio online | `online_store` | vetrina, carrello, checkout, area cliente, pagamenti online; nel backend del gestionale: ordini online, carrelli abbandonati, impostazioni del negozio | Ordini | E1 |
| Portale B2B | `b2b_portal` | accesso dei clienti aziendali con listino e condizioni proprie | Negozio online, Listini cliente | E4 |
| Abbonamenti online | `online_subscriptions` | sottoscrizione e gestione dall'area cliente, addebito ricorrente | Negozio online, Abbonamenti | E3 |

- Spedizioni non è richiesta dal negozio online: si possono vendere solo servizi.
- Multiprodotto, personalizzazione e sconto massivo stanno nel gestionale e valgono
  su tutti i canali (online, ufficio, banco).
- **Statistiche:** ogni funzionalità porta i propri indicatori nella dashboard (es.
  valore del magazzino con Acquisti, preventivi per stato con Preventivi).

## 4. Modello dati

Nomi senza il prefisso `gestionale_` (2.5). Le tabelle riportano le colonne
principali; tipi, indici e vincoli si definiscono nelle spec dei sotto-progetti.

### 4.1 Regole comuni

**Relazioni** in tabelle ponte con chiavi esterne e indici, mai in array JSON
(D23).

**Codici con prefisso (D25).** Ogni entità operativa (catalogo, anagrafiche,
documenti, movimenti) ha una colonna `code` con prefisso e 7 caratteri casuali
(es. `pay_k3x9d2a`), generata dal framework con
`Field::key('code')->text()->uniqueCode('<prefisso>')`: unica, in minuscolo, creata
all'inserimento e non più modificabile (il Model `Popup` la usa già con `pop_`).

- È il riferimento tecnico: link inviati al cliente, metadati dei gateway, log. Non
  sostituisce SKU, numero d'ordine o numero di fattura.
- **Eccezioni:** le tabelle di configurazione (`taxes`, `payment_methods`, …) hanno
  codici parlanti come in spingy (`bank-transfer`); nei coupon `code` è il codice
  digitato dal cliente (`SALDI20`).

| Entità | Prefisso | Entità | Prefisso |
|---|---|---|---|
| Modello | `mod_` | Ordine, carrello, preventivo | `ord_` |
| Variante | `var_` | DDT | `del_` |
| Prodotto | `pro_` | Reso | `ret_` |
| Brand | `bra_` | Pagamento | `pay_` |
| Categoria | `cat_` | Fattura, nota di credito | `inv_` |
| Tag | `tag_` | Spedizione | `shp_` |
| Anagrafica (cliente e/o fornitore) | `con_` | Abbonamento | `sub_` |
| Sede | `loc_` | Piano | `pln_` |
| Lotto | `bat_` | Listino | `prl_` |
| Movimento di magazzino | `mov_` | Campagna di sconto | `dsc_` |
| Documento di magazzino | `stk_` | | |

*Scartato:* `cli_` per le anagrafiche, perché la stessa scheda può essere anche
fornitore.

**Log degli stati (D26).** Ogni documento con stati ha la propria tabella di log,
sempre con le stesse colonne, come in spingy:

| Colonna | Contenuto |
|---|---|
| `<entità>_id` | documento |
| `field` | stato cambiato: negli ordini `stage`, `status`, `payment_status` o `fulfillment_status`; altrove `status` |
| `from_value`, `to_value` | valore precedente e nuovo |
| `source` | `user`, `system`, `cron`, `webhook`, `api` |
| `user_id` | autore, se `source = user` |
| `message` | nota o esito (es. `sent_to_sdi`) |
| `response` | JSON della risposta esterna (gateway, Fatture in Cloud, corriere) |

Tabelle: `order_status_logs`, `payment_status_logs`, `invoice_status_logs`,
`delivery_note_status_logs`, `sales_return_status_logs`, `shipment_status_logs`,
`subscription_status_logs`, `stock_document_status_logs`. Il log del magazzino è
`stock_movements` (4.3): ogni variazione di quantità è una riga.

**Numerazione dei documenti (D37, D47, D49, D56).**

- Formato unico `{YYYY}/{mm}{nnnn}` (es. `2026/090001`) per preventivi, ordini,
  DDT, resi e documenti di magazzino, con `document_sequences` (document_type,
  year, month, last_number).
- Il progressivo riparte ogni mese e ogni tipo di documento ha la propria
  sequenza; oltre 9999 documenti nel mese continua con una cifra in più.
- Il numero si assegna leggendo la sequenza con `FOR UPDATE`, così due documenti
  contemporanei non prendono lo stesso numero.
- Ordini online: numero all'invio, anche con il pagamento in attesa (5.2).
- Fatture: progressivo annuale con sezionale (6.2).
- `document_sequences` non si sincronizza mai tra ambienti (8.2).

**Copie sui documenti (D24, D31).** Ordini e fatture copiano i dati di fatturazione
e di spedizione con `AddressExtension` del core (prefissi `billing_` e `shipping_`)
e i dati del prodotto sulle righe: modificare anagrafica o catalogo non cambia i
documenti emessi.

**Riferimenti esterni (D49, D50).**

- Entità copiate nei sistemi esterni (clienti, aliquote, metodi e conti di
  pagamento, piani, prezzi, coupon): tabella unica `external_references` con
  entity_type, entity_id, provider, environment (`live`, `test`), object_type (es.
  `customer`, `price`, `tax_rate`), external_id, synced_at, sync_error; indice unico
  su provider + environment + object_type + external_id. Nessuna colonna
  `fatture_in_cloud_id` o `stripe_*_id` nelle tabelle: nuovi provider senza
  modificare le tabelle, ID di test e di produzione separati.
- Documenti nati da un provider (pagamenti, fatture, spedizioni, abbonamenti):
  `provider` e riferimento esterno restano sul documento (`provider_reference`,
  `provider_document_id`), perché ogni documento ha un solo provider e le notifiche
  lo cercano di continuo.

*Scartato:* colonne con gli ID esterni in ogni tabella.

**Dati in più del sito (D52).** Colonna JSON `custom_data` su `orders` e
`contacts`: contiene valori, non relazioni.

### 4.2 Catalogo, multiprodotto e personalizzazione (D23)

Contenuti in una sola lingua (D4).

| Tabella | Colonne principali |
|---|---|
| `brands` | name, slug, logo, description, position, visible |
| `categories` | parent_id, name, slug, image, description, position, visible |
| `tags` | name, slug, image, visible |
| `product_models` | brand_id, type (`simple`, `bundle`), tax_category_id, code, sku, unit (predefinita `pz`), name, slug, short_description, description, peso e misure predefiniti, seo_title, seo_description, returnable (4.10), requires_shipping (4.11), visible, visible_online |
| `product_model_categories`, `product_model_tags` | tabelle ponte; per le categorie anche `is_main` e `position` |
| `product_variants` | product_model_id, name, slug, position, visible |
| `products` | product_model_id, product_variant_id, sku, ean, mpn, price, sale_price, min_stock_quantity (4.3), peso e misure (se vuoti valgono quelli del modello), position, active |
| `product_images` | product_model_id, product_variant_id (vuoto = immagine del modello), file, alt, position |
| `attributes` | key, name, type, level (`model`, `variant`, `product`), unit, is_filterable, is_visible, group, position |
| `attribute_values` | attribute_id, label, color, image, position |
| `product_model_attributes`, `product_variant_attributes`, `product_attributes` | attribute_id + attribute_value_id oppure value_text / value_number |
| `bundle_components` | bundle_product_id, product_id, quantity, position |
| `bundle_groups` | bundle_product_id, name, min_choices, max_choices, position |
| `bundle_group_options` | bundle_group_id, product_id, surcharge, position |
| `customization_fields` | product_model_id, label, help_text, type (`text`, `textarea`, `number`, `select`, `date`, `file`), is_required, max_length, allowed_extensions, max_file_size, surcharge, position |
| `customization_field_options` | customization_field_id, label, surcharge, position |

**Livelli.** Il modello è la scheda; la variante è ciò che cambia l'aspetto
(immagini, attributi di variante come il colore); il prodotto è ciò che si vende
e sta a magazzino (SKU, prezzo, attributi di prodotto come la taglia). Un articolo
senza varianti è un modello con una variante e un prodotto: il pannello nasconde i
livelli superflui (D20).

**Codici.**

- `code` è il codice tecnico (4.1). `sku` è il codice interno scelto dal
  commerciante, su due livelli come in WooCommerce e Magento: SKU padre del
  modello, facoltativo (es. `TSH-1234`), e SKU del prodotto (es. `TSH-1234-BLU-M`),
  copiato sulle righe d'ordine.
- Se il modello ha un solo prodotto, il pannello mostra solo lo SKU del prodotto
  (D20); creando un prodotto in un modello con SKU, il pannello propone SKU del
  modello più i valori di variante e prodotto, modificabile.
- `ean`: codice a barre del prodotto, inserito dal commerciante e mai generato in
  automatico; accetta solo 8 o 13 cifre numeriche.
- `mpn`: codice del produttore; il codice del fornitore sta in
  `product_suppliers.supplier_sku` (4.3).
- Unicità: SKU unico nel proprio livello, EAN unico se compilato, MPN senza
  vincolo.
- Nel futuro feed Google Merchant lo SKU del modello diventa `item_group_id` e
  l'EAN diventa `gtin`.

**Peso e misure:** solo di spedizione; le misure descrittive sono attributi.

**Immagini:** se una variante non ne ha, valgono quelle del modello.

**Unità di misura (D39):** `product_models.unit` (predefinita `pz`), copiata su
righe d'ordine, DDT e fattura.

**Multiprodotto (`bundles`).** `type = bundle` sul modello, con il proprio
`tax_category_id` e quindi la propria aliquota. Componenti fissi e gruppi di scelta
(la "scelta del componente"). Nessuna giacenza propria: la disponibilità si calcola
dai componenti e alla vendita si scaricano componenti e opzioni scelte (4.3, 4.7).

**Personalizzazione (`customizations`).** Campi definiti sul modello, validi per
prodotti e multiprodotti. Il tipo `file` salva in una cartella non pubblica, con
estensioni e dimensione massima per campo. Alla vendita valori, etichette, file e
sovrapprezzi vengono copiati sulla riga del documento (4.7).

*Scartati:* `reference` come nome del codice interno del modello; colonna `gtin`
(basta `ean`); EAN interni generati dal pannello; validatore con cifra di controllo
in `wonder-image/app`.

### 4.3 Sedi e magazzino (D27, D29, D39)

**Sedi.**

| Tabella | Colonne principali |
|---|---|
| `locations` | code `loc_`, society_location_id (unico, sede del core), has_stock, is_pickup_point, is_pos, active |

- **Le sedi sono quelle del core** ("Dati aziendali", `society_locations`): nome,
  indirizzo, contatti, dati legali, Place ID, orari e chiusure stanno lì (8.4). Il
  gestionale aggiunge solo ciò che serve al magazzino e alla vendita.
- La sede principale è la sede predefinita del core. Con `multi_location` bloccata è
  l'unica sede del gestionale e la scelta della sede non compare da nessuna parte
  (D20).
- "Punto di ritiro" compare solo con `shipping`, "Banco" solo con `pos`.
- Orari e chiusure effettivi dal core (`SocietyLocations::hoursFor()` e `isOpen()`):
  durante una chiusura il ritiro non si può scegliere.
- Sedi del core e `locations` sono configurazione di `admin`, sincronizzata tra
  ambienti; orari e chiusure del core si modificano in produzione da `admin` e
  `administrator` (8.2).

**Giacenze, prenotazioni e movimenti.**

| Tabella | Colonne principali |
|---|---|
| `stock` | product_id, location_id, batch_id (vuoto senza lotti), supplier_id (vuoto senza acquisti), quantity |
| `stock_reservations` | order_id, order_item_id, product_id, location_id, quantity, expires_at, released_at |
| `stock_movements` | code `mov_`, product_id, location_id, batch_id, supplier_id, type, reason, quantity (con segno), quantity_before, quantity_after, unit_cost, reference_type + reference_id (ordine, DDT, reso, documento), source, user_id, note |
| `stock_alerts` | product_id, location_id (vuoto: soglia sul totale del prodotto), threshold, quantity_at_alert, notified_at, resolved_at |

- **Tipi di movimento:** `sale`, `sale_cancel`, `return`, `purchase`, `adjustment`,
  `transfer_in`, `transfer_out`.
- **Disponibile** = giacenza meno prenotazioni attive. Il checkout prenota; se il
  pagamento non arriva, la prenotazione scade (5.2). Evita l'overselling dei vecchi
  shop.
- **Multiprodotto:** i movimenti riguardano i componenti.
- **Contemporaneità:** le righe di `stock` si leggono con `FOR UPDATE` dentro la
  transazione, così due ordini non prendono l'ultimo pezzo (9.2).

**Scarico alla conferma dell'ordine (D39).** La merce venduta online può essere
ancora fisicamente in magazzino: scaricandola subito, l'operatore sa che non è più
disponibile.

- Carrello e checkout prenotano; alla conferma la prenotazione diventa un movimento
  `sale`. Al banco lo scarico è immediato.
- Lotto e fornitore si scelgono alla conferma: in automatico (vedi sotto), a mano
  negli ordini da ufficio.
- Ordine annullato dopo la conferma: movimenti `sale_cancel`.
- DDT e spedizioni non muovono la giacenza, salvo la correzione del lotto nel DDT
  in bozza (4.9).

*Scartato:* scarico all'evasione (emissione del DDT, spedizione o ritiro).

**Documenti di magazzino.** Carichi, scarichi e trasferimenti in una sola tabella,
come i documenti di carico e scarico di cco:

| Tabella | Colonne principali |
|---|---|
| `stock_documents` | code `stk_`, type (`receipt`, `issue`, `transfer`), number (4.1), date, location_id, to_location_id, supplier_id, supplier_document_number, supplier_document_date, reason, status, total_cost, note, user_id |
| `stock_document_items` | stock_document_id, product_id, batch_id, supplier_id (vuoti: scelta automatica), quantity, unit_cost, line_total, position |

- **Stati:** `draft`, `completed`, `cancelled`; i trasferimenti anche `in_transit`.
  Log in `stock_document_status_logs`.
- **Movimenti alla conferma:** carico `purchase`; scarico `adjustment` con la
  causale del documento; trasferimento `transfer_out` alla partenza e `transfer_in`
  all'arrivo.
- **Annullamento:** un documento confermato si annulla con movimenti di storno
  (stesso tipo, segno opposto), mai cancellando.
- **Funzionalità:** carico e scarico richiedono `purchasing`, il trasferimento
  `multi_location`.

**Rettifica rapida** dalla scheda prodotto, sempre disponibile: movimento
`adjustment` senza documento, con causale (`reason`): `damaged`, `gift`,
`internal_use`, `expired`, `inventory`, `initial_stock` (giacenza iniziale, anche
senza `purchasing`, D55), `other`.

**Lotti e scadenze (`batch_tracking`).**

- `batches`: code `bat_`, product_id, number, expiry_date, supplier_id, note.
- Il lotto nasce al carico.
- I lotti scaduti non sono disponibili online né per lo scarico automatico.
- Dai movimenti si risale ai clienti che hanno ricevuto un lotto (richiami).
- Riquadro della dashboard con i lotti in scadenza entro N giorni (impostazione del
  commerciante, predefinita 30).

**Acquisti (`purchasing`).**

- `product_suppliers`: product_id, supplier_id, supplier_sku, cost, is_preferred,
  position.
- Il carico aggiorna il costo del fornitore; lo storico dei costi resta nei
  movimenti (`unit_cost`).
- Riquadro della dashboard con il valore del magazzino.

**Giacenza per fornitore, come cco.**

- `supplier_id` su `stock`, `stock_movements` e righe dei documenti di magazzino;
  vuoto con `purchasing` bloccata.
- Disponibilità per fornitore; la disponibilità del prodotto è la somma.
- **Scarico automatico:** prima il lotto che scade prima (con `batch_tracking`),
  poi il fornitore con il costo più basso. Negli ordini da ufficio e nei documenti
  di magazzino lotto e fornitore si possono scegliere a mano; nel DDT in bozza il
  lotto si può correggere.
- **Valore del magazzino** = quantità × costo del fornitore.

**Avvisi di scorta minima (`low_stock_alerts`).**

- Campo "Scorta minima" sul prodotto (`products.min_stock_quantity`); se è vuoto
  non ci sono avvisi.
- Soglia sul disponibile totale del prodotto, somma di tutte le sedi.
- Sotto soglia parte un'email ai destinatari scelti dal commerciante (impostazioni
  del commerciante, 8.2); se una sola operazione porta sotto soglia più prodotti,
  arriva un'unica email con l'elenco.
- L'avviso non si ripete finché il prodotto non torna sopra soglia (`resolved_at`).
- Riquadro della dashboard con i prodotti sotto soglia.

*Scartati:* costo medio ponderato (più semplice, ma perde la disponibilità per
fornitore); strati di carico FIFO (valore esatto, ma rettifiche, resi e
trasferimenti devono spezzare gli strati); orari delle sedi modificabili dal
commerciante.

**Più avanti:** ordini a fornitore, inventario con conteggio guidato, soglia di
scorta minima per sede (10.5).

### 4.4 Anagrafiche e account (D31)

| Tabella | Colonne principali |
|---|---|
| `contacts` | code `con_`, is_customer, is_supplier, dati di fatturazione con `AddressExtension::billing()` del core (type `private`/`business`, name, surname, business_name, cf, pi, sdi, pec, indirizzo, phone_prefix, phone), email, user_id, price_list_id (4.6), payment_method_id e payment_term_id (4.8), color, note, custom_data, active |
| `contact_addresses` | contact_id, label, indirizzo con `AddressExtension::simple()` più destinatario e telefono, is_default, position |

- **Una scheda = una sola identità fiscale**, con al massimo un account. Tipo e
  paese servono alle regole IVA (4.5); partita IVA e codice fiscale sono validati
  dall'estensione del core.
- **Documenti:** usano i dati copiati (4.1).
- **D20:** il ruolo fornitore compare solo con `purchasing`; i campi aziendali solo
  per il tipo `business`.
- **Dashboard:** numero di clienti e fornitori.

**Account sul sito (ecommerce).**

- L'ecommerce configura il permesso `frontend.client` del core con i suoi hook: la
  registrazione crea l'utente del core e la scheda collegata (`contacts.user_id`);
  `$USER->client` restituisce la scheda.
- Se esiste già una scheda con la stessa email (creata dall'ufficio o da un ordine
  da ospite), si collega solo dopo la verifica dell'email del core.
- Newsletter e consensi con le tabelle dei consensi del core, nessuna tabella nel
  gestionale.
- Accesso con Google e Apple: dopo il primo rilascio, basato su `AuthFederated` del
  core, oggi non funzionante e da completare (10.4).

**Portale B2B (E4):** l'account lo crea l'azienda cliente.

**Più avanti:** più utenti della stessa azienda sul portale B2B, referenti
aziendali, gruppi di clienti (10.5).

### 4.5 IVA e prezzi (D18, D19, D21, D37)

Struttura di spingy, con il gestionale come fonte di verità: funziona anche senza
Fatture in Cloud.

**Tabelle.**

- `taxes` (aliquote): nome (`<valore>% - <descrizione>`), descrizione, descrizione
  in fattura, valore, natura, visibile. Codici parlanti (4.1).
- `tax_categories` (tipi fiscali dei prodotti, es. beni 22%, alimentari 10% o 4%,
  libri, servizi): ogni modello ne ha uno (`tax_category_id`).
- `tax_rules`: paese (ISO) × tipo cliente (`private`, `business`) × tipo fiscale →
  aliquota; codice della regola `paese tipo-cliente tipo-prodotto`.

**Aliquote.**

- Tabella locale precaricata da una nuova classe di `wonder-image/app`, accanto a
  `Custom\Fattura\Valori\Natura`, con le aliquote italiane (22%, 10%, 5%, 4%) e le
  nature valide per le operazioni a 0% (8.3).
- `admin` può aggiungerne altre (es. aliquote estere per le vendite a privati UE);
  le aliquote non si cancellano, si mostrano o nascondono.
- Con Fatture in Cloud ogni aliquota viene abbinata al tipo IVA con lo stesso
  valore e la stessa natura, oppure creata; l'ID va in `external_references` (6.2).
- Aliquote Stripe create solo quando servono (es. abbonamenti online).

**Risoluzione.** Tipo fiscale dal prodotto venduto; tipo e paese dal cliente;
regola con corrispondenza esatta. Senza regola si usa l'aliquota di ripiego scelta
nelle impostazioni fiscali (6.2).

**Prezzi IVA inclusa o esclusa (D19).**

- Impostazione fiscale del catalogo scelta una volta da `admin` (precaricata IVA
  inclusa, da confermare nei Primi passi, 8.6).
- I listini cliente hanno ciascuno la propria scelta (4.6).
- Scheda prodotto: il commerciante vede solo "Prezzo" e "Prezzo scontato", con
  l'indicazione IVA inclusa o esclusa. Il tipo fiscale è preimpostato e nascosto se
  ne esiste uno solo.

**Calcolo dei totali.**

- Ogni riga risolve la propria aliquota.
- L'imposta si calcola sul totale imponibile di ogni aliquota, come nei riepiloghi
  FatturaPA e in `Custom\Fattura` (`order_tax_summaries`, `invoice_tax_summaries`),
  arrotondata a 2 decimali; non riga per riga.
- Con prezzi IVA inclusa l'imposta si scorpora: il cliente paga sempre la cifra
  esposta, anche se l'aliquota cambia (es. privato tedesco), e varia solo
  l'imponibile.
- Sul documento restano fotografati aliquota, natura, paese, imposta e totale.
- Differenze di un centesimo con Fatture in Cloud: controllo dei totali prima
  dell'invio (6.2).
- Senza fatturazione elettronica ordini, IVA e totali funzionano lo stesso.

**Spedizione e commissioni (D37):** aliquota fissa scelta da `admin` nelle
impostazioni fiscali (verifica con il commercialista, 10.6).

*Scartati:* aliquote importate solo da Fatture in Cloud, come in spingy (senza
Fatture in Cloud il sistema non funzionerebbe); prezzi sempre IVA esclusa; un'unica
scelta IVA per sito anche per i listini cliente; IVA di spedizione e commissioni
ripartita secondo quella dei prodotti (art. 12 DPR 633/72).

### 4.6 Listini, sconto massivo e coupon (D33, D34, D35)

**Listini cliente (`customer_price_lists`).**

| Tabella | Colonne principali |
|---|---|
| `price_lists` | code `prl_`, name, prices_include_tax, discount_percent (per i prodotti non elencati; vuoto = il listino non li copre), note, active |
| `price_list_items` | price_list_id, product_id, min_quantity (1 = prezzo normale, di più = scaglione), price |

- Assegnazione con `contacts.price_list_id`, dalla scheda cliente o dalla pagina
  del listino. Con la funzionalità bloccata nessun listino compare da nessuna parte.
- Da ufficio e al banco l'ordine prende il listino del cliente, modificabile per il
  singolo documento.
- **Scaglioni:** vale la riga con la quantità minima più alta che non supera la
  quantità della riga d'ordine.
- Il prezzo scontato del prodotto non ha date: le promozioni programmate si fanno
  con lo sconto massivo.

**Priorità del prezzo.** Per ogni riga vale il primo prezzo disponibile:

1. listino del cliente, se copre il prodotto (prezzo o scaglione del prodotto,
   oppure `discount_percent` sul prezzo base);
2. campagna di sconto attiva sul prodotto, calcolata sul prezzo base;
3. prezzo scontato del prodotto;
4. prezzo base.

Conseguenza accettata: durante una campagna un cliente con listino può pagare più
di un privato. Un prodotto con prezzo scontato, durante una campagna, prende il
prezzo della campagna, salvo l'opzione `exclude_sale_products`.

**Calcolo della riga**, in un'unica classe del gestionale usata da ufficio, banco,
ecommerce e portale B2B:

1. prezzo secondo la priorità;
2. sconto manuale sulla riga, solo da ufficio e banco;
3. sovrapprezzi di personalizzazione e opzioni del multiprodotto, sempre a prezzo
   pieno: listino, campagna e prezzo scontato non li toccano;
4. sconti sul totale (coupon, sconto manuale sull'ordine) divisi tra le righe in
   proporzione all'importo (`order_discount_amount`): riepiloghi IVA per aliquota
   corretti e rimborsi dei resi calcolabili riga per riga.

Sulla riga d'ordine restano `list_price`, `unit_price`, `price_source` (`base`,
`sale`, `price_list`, `campaign`) e `order_discount_amount` (4.7). Nessun hook del
sito modifica i prezzi (7).

*Scartati:* vince il prezzo più basso (la priorità fissa è più comoda e pratica);
sconti a cascata (margini a rischio, difficili da spiegare); sovrapprezzi scontati
insieme al prodotto.

**Sconto massivo (`discount_campaigns`).**

| Tabella | Colonne principali |
|---|---|
| `discount_campaigns` | code `dsc_`, name, discount_type (`percent`, `amount`), discount_value, starts_at, ends_at (vuoto = senza fine), active, applies_to_all, exclude_sale_products, applies_online, applies_office, applies_pos, note |
| `discount_campaign_categories` | discount_campaign_id, category_id (sottocategorie comprese) |
| `discount_campaign_tags` | discount_campaign_id, tag_id |
| `discount_campaign_brands` | discount_campaign_id, brand_id |
| `discount_campaign_product_models` | discount_campaign_id, product_model_id, is_excluded |

- **Prodotti coinvolti:** tutto il catalogo, oppure i prodotti in almeno una delle
  categorie (sottocategorie comprese), dei tag, dei brand o dei modelli scelti; in
  entrambi i casi meno i modelli esclusi.
- **Sconto:** in percentuale o in euro per pezzo, sul prezzo base; mai sotto zero,
  arrotondato al centesimo.
- **Validità:** tra inizio e fine e con l'interruttore acceso; senza fine resta
  attiva finché non si spegne. Nessun cron: il prezzo si calcola quando serve. Stato
  ricavato: programmata, in corso, terminata, disattivata.
- **Prodotti con prezzo scontato:** con `exclude_sale_products` mantengono il
  proprio prezzo scontato.
- **Canali:** di default tutti; limitabili (es. solo online). I clienti con listino
  non ricevono campagne.
- **Sovrapposizioni:** se più campagne attive riguardano lo stesso prodotto vince lo
  sconto maggiore; il pannello avvisa della sovrapposizione al salvataggio.
- **Anteprima** prima di salvare: numero di prodotti coinvolti ed esempi di prezzo
  prima e dopo.
- **Negozio online:** la view riceve prezzo base da barrare, percentuale e fine
  della campagna.

**Coupon (`coupons`).**

| Tabella | Colonne principali |
|---|---|
| `coupons` | code, name, discount_type (`percent`, `amount`, `free_shipping`, `store_credit`), discount_value, min_order_amount, applies_to_all, exclude_discounted_products, first_order_only, usage_limit, usage_limit_per_customer, starts_at, ends_at, applies_online, applies_office, applies_pos, duration, duration_months (4.13), active, note |
| `coupon_categories`, `coupon_tags`, `coupon_brands`, `coupon_product_models` | come le tabelle ponte delle campagne; `coupon_product_models` con `is_excluded` |
| `coupon_customers` | coupon_id, customer_id (nessuna riga = tutti i clienti) |
| `coupon_redemptions` | coupon_id, order_id, customer_id, email, discount_amount, redeemed_at, released_at |

- **Codice:** quello digitato dal cliente, unico, senza distinzione tra maiuscole e
  minuscole.
- **Tipi:**
  - `percent`: percentuale sulle righe a cui si applica;
  - `amount`: importo fisso, la parte non usata si perde;
  - `free_shipping`: azzera la riga di spedizione (4.11);
  - `store_credit` (buono a scalare gratuito): importo fisso con residuo per gli
    ordini successivi, calcolato dagli utilizzi. Fiscalmente è uno sconto; è diverso
    dalla carta regalo, che è stata pagata e si usa come pagamento (10.5).
- **Prodotti:** stesso selettore delle campagne: tutto il catalogo (predefinito)
  oppure categorie, tag, brand e modelli, con modelli esclusi.
- **Un solo coupon per ordine.** Si applica dopo il prezzo delle righe, sovrapprezzi
  compresi, diviso tra le righe in proporzione (`order_discount_amount`).
- **Prodotti già scontati:** righe con prezzo da listino, campagna o prezzo
  scontato, o con sconto manuale; `exclude_discounted_products` le salta.
- **Spesa minima** calcolata sulle righe a cui il coupon si applica.
- **Solo primo ordine** (`first_order_only`): es. coupon di benvenuto della
  newsletter.
- **Utilizzi** contati alla conferma dell'ordine; se l'ordine viene annullato,
  utilizzo e residuo tornano disponibili.
- **Limite per cliente, primo ordine e buono a scalare** riconoscono il cliente
  dall'account o, nel checkout da ospite, dall'email (5.1).
- **Messaggi di rifiuto** tradotti, uno per motivo, come le notifiche di spingy.
- **Sull'ordine:** coupon, codice e importo dello sconto nella testata (4.7).
- **Abbonamenti:** durata del coupon e sincronizzazione con Stripe (4.13).

**Più avanti:** importazione dei listini da file, listini per categoria,
generazione in blocco di codici usa e getta, più coupon nello stesso ordine (10.5).

### 4.7 Carrello, preventivi e ordini (D24, D37)

**Una sola tabella `orders`**, con una fase (`stage`) che avanza sulla stessa riga:

```
cart ──── checkout ─────────────────► order
  └── richiesta di preventivo ──► quote ── accettato ──► order
                                  (anche creato da backend)
```

- **Numerazione separata:** `quote_number` all'invio del preventivo, `order_number`
  alla conferma dell'ordine (online all'invio, 5.2); i carrelli non hanno numero.
- **Preventivo inviato:** ogni versione inviata viene archiviata in PDF; una
  modifica dopo l'invio aumenta la revisione.
- **Canale (`channel`):** `office`, `online`, `b2b_portal`, `pos`. Il banco crea
  direttamente un ordine confermato e pagato.
- **Carrelli abbandonati:** carrelli fermi da X giorni, senza stato dedicato; un
  cron elimina quelli anonimi vecchi.
- **Funzionalità:** la fase `quote` richiede `quotes`, la fase `cart` richiede
  `online_store`.

*Scartato:* tabelle separate per carrello, preventivi e ordini, come nei vecchi
progetti.

**Stati su tre assi**, invece dell'elenco unico dei vecchi progetti che mescolava
ordine, pagamento e consegna:

| Colonna | Valori |
|---|---|
| `status` del preventivo | `draft`, `sent`, `rejected`, `expired`; se accettato diventa ordine |
| `status` dell'ordine | `pending`, `confirmed`, `processing`, `completed`, `cancelled` |
| `payment_status` | `unpaid`, `pending`, `partially_paid`, `paid`, `partially_refunded`, `refunded` |
| `fulfillment_status` | `unfulfilled`, `ready_for_pickup`, `partially_fulfilled`, `fulfilled` |

Tracking del corriere su `shipments`; resi e fatture con stati propri. Log in
`order_status_logs` (4.1).

**Testata.**

| Gruppo | Colonne |
|---|---|
| Documento | code `ord_`, stage, channel, quote_number, quote_revision, quote_sent_at, quote_expires_at, order_number, ordered_at, completed_at, cancelled_at, user_id (operatore) |
| Stati | status, payment_status, fulfillment_status |
| Cliente | customer_id, email, phone, dati `billing_` e `shipping_` copiati, price_list_id, prices_include_tax (dal listino usato) |
| Consegna | fulfillment_type (`shipping`, `pickup`, `none`), location_id (sede che evade o di ritiro), shipping_method_id (4.11) |
| Pagamento | payment_method_id, payment_term_id (4.8) |
| Sconti sul totale | coupon_id, coupon_code, manual_discount_type, manual_discount_value |
| Totali | currency, products_total, discount_total, shipping_total, fees_total, taxable_total, tax_total, total, total_weight |
| Note | customer_note (scritta dal cliente), internal_note (solo backend), document_note (stampata sul PDF) |
| Carrello | cart_token (carrello senza account), last_activity_at (carrelli abbandonati) |
| Sito | custom_data (4.1) |

- **Totali** salvati per elenchi e statistiche, sempre ricalcolati dalla classe dei
  prezzi (4.6). Il "da pagare" non si salva: è il totale meno i pagamenti riusciti.
- **Revisioni del preventivo:** `order_quote_revisions` (order_id, revision,
  pdf_file, sent_to, sent_at, user_id), archivio dei PDF inviati.
- **Dati di trasporto** (a cura di, vettore, aspetto dei beni, colli): nel DDT
  (4.9).

**Righe (`order_items`).**

| Gruppo | Colonne |
|---|---|
| Riferimenti | order_id, type, product_id (vuoto per le righe non di prodotto), parent_item_id, position |
| Copia del prodotto | sku, name, description, unit |
| Prezzo | quantity, list_price, unit_price, price_source, discount_type, discount_value, order_discount_amount, price_list_id, discount_campaign_id |
| IVA | tax_category_id, tax_id, tax_rate, tax_nature |
| Personalizzazione | customization (JSON: campo, etichetta, valore, file, sovrapprezzo), customization_surcharge |
| Totale | line_total |

- **Tipi di riga (`type`):** `product`, `custom` (riga libera con prezzo), `text`
  (solo descrizione), `shipping`, `fee` (commissione del metodo di pagamento, es.
  contrassegno). Spedizione e commissioni entrano così nei riepiloghi IVA, in
  fattura e nei rimborsi come le altre righe.
- **Multiprodotto:** componenti e opzioni scelte sono righe figlie
  (`parent_item_id`) a prezzo zero, per lo scarico del magazzino e il DDT.
- `order_tax_summaries`: imponibile e imposta per aliquota (4.5).
- **Documenti collegati all'ordine:** `delivery_notes`, `sales_returns`,
  `payments`, `invoices`, `shipments`.

### 4.8 Pagamenti (D28, D38)

| Tabella | Colonne principali |
|---|---|
| `payments` | code `pay_`, type (`payment`, `refund`), order_id, subscription_id, customer_id, payment_method_id, payment_account_id, amount, currency, status (`pending`, `paid`, `failed`, `cancelled`), provider (`stripe`, `paypal`, `nexi`, `manual`), provider_reference, due_date, paid_at, note |
| `payment_methods` | code (es. `bank-transfer`), name, sdi_code (MP01–MP23), provider, payment_account_id, fee_type (`amount`, `percent`), fee_value, available_for (`all`, `shipping`, `pickup`), applies_online, applies_office, applies_pos, instructions, stripe_payment_method_types, active, position |
| `payment_accounts` | code, name, bank_name, iban, bic, active |
| `payment_terms` | code, name, sdi_condition (`TP01` a rate, `TP02` completo, `TP03` anticipo), active, position |
| `payment_term_installments` | payment_term_id, days, end_of_month, percentage, position |

**Pagamenti.**

- Ogni movimento di denaro è una riga, rimborsi compresi: pagamento del checkout,
  bonifico registrato, contanti al banco, rate. Log in `payment_status_logs`.
- Il `payment_status` dell'ordine si calcola dalla somma delle righe.

**Metodi e conti.**

- **Costo aggiuntivo** (es. contrassegno): `fee_type` e `fee_value` generano la riga
  `fee` dell'ordine, con l'aliquota fissa di spedizione e commissioni (4.5); per il
  contrassegno prevale `cod_fee` del listino di spedizione, se impostato (4.11).
- `available_for`: contrassegno solo con spedizione, pagamento al ritiro solo con
  ritiro.
- `instructions`: testo per il cliente e per il PDF (es. IBAN).
- Codici SDI da `Custom\Fattura\Valori\Pagamento` del core.
- **Conti:** banca e IBAN su preventivi, fatture e fattura elettronica.
- Metodi, conti e condizioni sono configurazione di `admin`, sincronizzata tra
  ambienti (8.2); ID di Fatture in Cloud in `external_references`.

**Condizioni a rate e scadenzario (G9).**

- Esempi: "Rimessa diretta" (una rata al 100%, subito, TP02); "Bonifico 30 gg fine
  mese" (TP02); "RiBa 30/60 gg fine mese" (due rate al 50%, TP01).
- Alla conferma dell'ordine una riga di `payments` in attesa per ogni rata, con
  scadenza e importo; se l'ordine cambia prima di un pagamento, le rate si
  ricalcolano. Online il pagamento è immediato: una sola riga.
- Riquadro della dashboard con pagamenti in scadenza e scaduti.
- **Predefiniti sulla scheda cliente:** `contacts.payment_method_id` e
  `contacts.payment_term_id`, proposti negli ordini da ufficio.
- **D20:** con una sola condizione, o nessuna, il campo non compare.

### 4.9 DDT (D39)

Con `delivery_notes` (G9).

| Tabella | Colonne principali |
|---|---|
| `delivery_notes` | code `del_`, number (4.1), date, status (`draft`, `issued`, `cancelled`), reason (`sale`, `transfer`), order_id, stock_document_id, customer_id, from_location_id, dati `billing_` e `shipping_` copiati, transport_by (`sender`, `recipient`, `carrier`), carrier_id e dati del vettore copiati (4.11), transport_started_at, goods_appearance, packages_count, gross_weight, net_weight, freight_terms (`prepaid` porto franco, `collect` porto assegnato), document_note, pdf_file, user_id |
| `delivery_note_items` | delivery_note_id, order_item_id, product_id, sku, description, quantity, unit, batch_id, supplier_id, position |

- **Da ordine** (causale vendita): propone le righe non ancora consegnate; più DDT per
  lo stesso ordine per le consegne parziali; aggiorna `fulfillment_status`.
- **Da trasferimento tra sedi** (4.3), con causale trasferimento.
- **Emissione:** in bozza si modificano righe e dati di trasporto; all'emissione
  prende numero e PDF e non si modifica più; si annulla con lo stato `cancelled`;
  log in `delivery_note_status_logs`.
- **Lotti** stampati sul DDT. Nel DDT in bozza il lotto si può correggere: la
  correzione sposta lo scarico da un lotto all'altro con due movimenti.
- **Prezzi** non mostrati: la fattura li prende dalle righe d'ordine.
- **Valori predefiniti** nelle impostazioni, come in cco: a cura di, vettore,
  aspetto dei beni, porto.
- **Fattura differita** (`deferred_invoicing`): una fattura TD24 per cliente con i
  DDT emessi nel periodo e non ancora fatturati (`invoice_delivery_notes`, 4.12);
  riquadro della dashboard con i DDT da fatturare; regole di emissione in 5.3.

### 4.10 Resi (D40)

| Tabella | Colonne principali |
|---|---|
| `sales_returns` | code `ret_`, number (4.1), order_id, customer_id, channel (`online`, `office`, `pos`), status, location_id (sede che riceve la merce), requested_at, approved_at, received_at, completed_at, customer_note, internal_note, user_id |
| `sales_return_items` | sales_return_id, order_item_id, quantity, reason (`damaged`, `defective`, `wrong_item`, `not_as_described`, `changed_mind`, `wrong_size`, `other`), restock, note |

- **Stati:** `requested` (richiesta online) → `approved` o `rejected` → `received`
  (merce arrivata e controllata) → `completed` (reso chiuso); `cancelled`. Da
  ufficio e banco si parte da `approved` o `received`. Log in
  `sales_return_status_logs`.
- **Magazzino:** `restock` per riga; al passaggio a `received` la merce rientra con
  un movimento `return` nella sede che la riceve, sullo stesso lotto e fornitore
  della vendita. Proposto spento per `damaged` e `defective`.
- **Richiesta online** (con `online_store`): dall'area cliente, entro il termine
  impostato da `admin` con due voci del pannello (impostazioni tecniche, 8.2):
  - "Giorni di reso" (predefinito 14);
  - "Da quando contare i giorni di reso": inserimento del tracking (`shipped_at`,
    predefinito), consegna registrata (`delivered_at`) o data dell'ordine. Se la
    data scelta manca: dalla consegna si passa al tracking, dal tracking all'ordine.
- Escluse dalla richiesta online le righe personalizzate (art. 59 Codice del
  Consumo) e i modelli non rendibili (`product_models.returnable`, es. alimentari
  deperibili).
- Email al commerciante alla richiesta e al cliente a ogni cambio di stato.
- **Rimborsi: funzionalità futura** (10.5). Per ora il commerciante rimborsa fuori
  dal gestionale e chiude il reso.

### 4.11 Spedizioni (D41, D42)

Con `shipping` (G7).

**Metodi, zone e listini.**

| Tabella | Colonne principali |
|---|---|
| `shipping_methods` | code, name (es. "Standard", "Espresso"), description (tempi di consegna), carrier_id, provider_service_code (6.4), applies_online, applies_office, active, position |
| `shipping_zones` | code, name (es. "Italia", "Isole", "UE") |
| `shipping_zone_areas` | shipping_zone_id, country, province (vuota = tutto il paese) |
| `shipping_rates` | shipping_method_id, shipping_zone_id, volumetric_divisor, excess_mode (`total_weight`, `excess_only`), fuel_surcharge_percent, markup_percent (positivo o negativo), rounding_step, min_price, free_over_amount, free_under_weight, cod_fee, active |
| `shipping_rate_brackets` | shipping_rate_id, type (`price` scaglione, `excess` €/kg), max_weight, amount |

**Calcolo** (come vallis-serinae, con gli scaglioni in tabella invece che in JSON):

1. peso tassabile: per prodotto il maggiore tra peso reale e volume ÷
   `volumetric_divisor`, sommato sulle righe;
2. prezzo dello scaglione; oltre l'ultimo, tariffa al kg su tutto il peso
   (`total_weight`) o solo sulla parte in più (`excess_only`);
3. + supplemento carburante %, poi ± margine %;
4. arrotondamento per eccesso al gradino scelto; mai sotto `min_price`;
5. gratuita se il totale dei prodotti dopo gli sconti supera `free_over_amount`
   e/o il peso è sotto `free_under_weight` (se impostate entrambe, devono valere
   tutte e due).

- **Metodi:** un listino per coppia metodo-zona; al checkout compaiono i metodi con
  un listino per la zona, con prezzo e tempi di consegna.
- **Zona:** vale la più specifica (provincia prima del paese).
- **IVA:** prezzi con l'impostazione del catalogo, aliquota fissa di spedizione e
  commissioni (4.5).
- **Contrassegno:** `cod_fee` del listino, se impostato, prevale sul costo del
  metodo di pagamento.
- **Coupon di spedizione gratuita:** azzera la riga di spedizione.
- **Servizi e prodotti digitali:** `product_models.requires_shipping` (predefinito
  sì); senza righe da spedire l'ordine ha consegna `none` e il checkout salta la
  spedizione.
- **Ordini da ufficio:** costo calcolato allo stesso modo, modificabile a mano.

**Spedizioni e tracking.**

| Tabella | Colonne principali |
|---|---|
| `carriers` | code, name, dati aziendali con `AddressExtension::billing()` (per il DDT), tracking_url_template (es. `https://…?code={tracking}`), provider (`manual` o integrazione, 6.4), active, position |
| `shipments` | code `shp_`, type (`delivery`, `pickup`), order_id, delivery_note_id, shipping_method_id, carrier_id, location_id (ritiro), status, tracking_number, tracking_url, label_file, packages_count, weight, cod_amount, shipping_cost, provider_reference, shipped_at, delivered_at, customer_notified_at, note, user_id |
| `shipment_items` | shipment_id, order_item_id, quantity |

**Stati**, separati da quelli dell'ordine; log in `shipment_status_logs` con la
risposta del corriere:

| Tipo | Stati |
|---|---|
| `delivery` | `pending`, `label_created`, `in_transit`, `out_for_delivery`, `delivered`, `failed_attempt`, `exception`, `returned`, `cancelled` |
| `pickup` | `pending`, `ready_for_pickup`, `picked_up`, `cancelled` |

- **Ritiro in sede** come spedizione di tipo `pickup`: sedi con `is_pickup_point`,
  gratuito, non selezionabile fuori orario o durante le chiusure della sede (4.3);
  evasione, log ed email in un solo
  punto ("pronto per il ritiro" con `ready_for_pickup` sull'ordine, conferma di
  ritiro).
- **Righe evase:** in un DDT emesso, in una spedizione partita o in un ritiro
  completato. La spedizione collegata a un DDT usa le righe del DDT. Da qui il
  `fulfillment_status` dell'ordine.
- **Modalità manuale:** spedizione creata dall'ordine (tutte le righe rimaste o una
  parte); corriere e tracking → `in_transit` e `shipped_at`; email al cliente con il
  link da `tracking_url_template`. La consegna raramente viene segnata a mano: il
  termine dei resi parte dal tracking (4.10).
- **Dashboard:** spedizioni in eccezione e non consegnate dopo N giorni.
- **Area cliente:** stato e link di tracking di ogni spedizione.
- **Corrieri collegati:** funzionalità futura (6.4, 10.5).

**Più avanti:** zone per CAP, orari di ritiro prenotabili, tariffe in tempo reale
dal corriere, prenotazione del ritiro del corriere, tracking per singolo collo
(10.5).

### 4.12 Fatture (D28, D45, D49)

Con `e_invoicing` (G8).

| Tabella | Colonne principali |
|---|---|
| `invoices` | code `inv_`, document_type (`TD01` fattura, `TD04` nota di credito, `TD24` fattura differita), number, numeration (sezionale copiato dalle impostazioni fiscali), date, customer_id, order_id, subscription_id, payment_id, related_invoice_id (fattura corretta dalla nota di credito), dati di fatturazione copiati (`billing_`), prices_include_tax, subtotal, tax_total, total, currency, provider (`fatture_in_cloud`, `xml`), provider_document_id, document_url, xml_file, status, message, sent_at, confirmed_at, customer_notified_at |
| `invoice_items` | invoice_id, order_item_id, description, quantity, unit, unit_price, tax_id, tax_rate, tax_nature, line_total, position |
| `invoice_tax_summaries` | invoice_id, tax_id, tax_rate, tax_nature, taxable_amount, tax_amount |
| `invoice_delivery_notes` | invoice_id, delivery_note_id (fattura differita) |

- **Stati:** quelli di spingy (`pending_send`, `retry_send`, `sent`, `confirmed`,
  `rejected`, `error`), più `generated` per il provider XML. Log in
  `invoice_status_logs`.
- **Origine:** ogni fattura nasce in automatico da un ordine o dal pagamento di un
  abbonamento; niente fatture create a mano né bozze da controllare (5.3).
- **Numerazione:** progressivo annuale con sezionale dedicato al sito (6.2).
- **Note di credito (TD04):** funzionalità futura, insieme ai rimborsi (10.5).

### 4.13 Abbonamenti (D17, D44)

Con `subscriptions` (G10).

**Separati dagli ordini (D17):** tutti gli abbonamenti in un'unica tabella; un
rinnovo non genera un ordine; la fattura di un abbonamento nasce dal pagamento, come
in spingy. *Scartato:* ogni rinnovo genera un ordine.

| Tabella | Colonne principali |
|---|---|
| `plans` | code `pln_`, slug, name, description, tax_category_id, visible_online, active, position |
| `plan_features` | plan_id, key, value (limiti del piano, es. `shops` = 3) |
| `plan_prices` | plan_id, name, billing_interval (`month`, `year`), term_months (mesi coperti da un addebito), commitment_months, trial_days, price, sale_price, renewal_plan_price_id (prezzo a fine vincolo), active, position |
| `subscriptions` | code `sub_`, customer_id, plan_price_id, channel (`online`, `office`), status, provider (`stripe`, `manual`), payment_method_id, payment_term_id, coupon_id, coupon_code, dati `billing_` copiati, prices_include_tax, subtotal, discount_total, tax_id, tax_country, tax_rate, tax_total, total, currency, started_at, trial_ends_at, commitment_ends_at, current_period_end, next_renewal_at, auto_renew, cancelled_at, ended_at, provider_reference, note |
| `subscription_plan_changes` | subscription_id, old_plan_price_id, new_plan_price_id, user_id, source, changed_at |

ID di prodotti, prezzi e codici promozionali Stripe in `external_references`.

**Stati:** `pending` (in attesa del primo pagamento), `trialing`, `active`,
`past_due` (rinnovo non riuscito, tentativi in corso), `unpaid` (tentativi
esauriti), `cancelled`. Il cambio piano resta sulla stessa riga: nessuno stato
`changed`. Log in `subscription_status_logs`.

**Regole (da spingy):**

- attivo al primo pagamento riuscito; con prova gratuita, primo addebito a fine
  prova; nessuna fattura per prova o importo zero;
- cambio piano immediato, nuovo importo dal rinnovo successivo, senza conguagli,
  promozioni mantenute;
- a fine vincolo si passa a `renewal_plan_price_id`;
- disdetta = rinnovo automatico spento; valido fino alla fine del periodo pagato;
- rinnovo non riuscito: `past_due` durante i tentativi, poi `unpaid` con email per
  aggiornare la carta;
- limiti del piano (`plan_features`) controllati dal sito solo alla creazione di
  nuovi elementi: nulla si cancella con un downgrade.

**Rinnovi e coupon:**

- **Online** (`online_subscriptions`): addebiti gestiti da Stripe; ogni fattura
  Stripe pagata → riga di `payments` → fattura; controllo orario di riallineamento
  se un webhook non arriva.
- **Da ufficio** (senza Stripe): a ogni periodo un cron crea il pagamento in attesa
  con la scadenza delle condizioni di pagamento; flusso in 5.5.
- **Coupon:** `coupons.duration` (`once`, `repeating`, `forever`) e
  `duration_months`; per gli abbonamenti online sincronizzati come coupon e codice
  promozionale Stripe.

**Funzionalità futura:** abbonamenti con prodotti fisici (10.5).

### 4.14 Tabelle di sistema

| Tabella | A cosa serve | Dove |
|---|---|---|
| `features`, `feature_logs` | stato e storico delle funzionalità | 3.1 |
| impostazioni (due righe uniche) | tecniche, sincronizzate; del commerciante, solo produzione | 8.2 |
| `document_sequences` | numerazione dei documenti | 4.1 |
| `external_references` | ID delle entità nei sistemi esterni | 4.1 |
| `provider_events` | eventi dei webhook, elaborati una sola volta | 9.3 |
| `error_reports` | errori ripetuti senza un documento | 9.1 |
| `label_formats` | formati delle etichette (funzionalità futura) | 10.5 |

## 5. Flussi

### 5.1 Checkout online (D46)

**Checkout dell'ecommerce con Stripe Elements e pulsanti rapidi.**

- Il checkout del sito raccoglie i dati di fatturazione
  (`AddressExtension::billing()`), calcola spedizione (4.11) e ritiro, applica il
  coupon (4.6), crea l'ordine e prenota la merce (4.3).
- Pagamento con il modulo carte di Stripe (Payment Element), con Link che compila
  email, indirizzo e carta; gli altri metodi attivi restano disponibili (4.8).
- **Pulsanti rapidi** (Express Checkout Element: Link, Apple Pay, Google Pay,
  Klarna, Amazon Pay, secondo la configurazione Stripe; PayPal diretto a parte, 6.3)
  su scheda prodotto, carrello e checkout. Il wallet fornisce email, telefono e
  indirizzo; le opzioni di spedizione si aggiornano con i listini di spedizione. Solo
  per chi non chiede fattura o ha già i dati salvati sulla scheda.
- Il checkout è bloccato finché mancano i requisiti del negozio (8.6).

**Ospite o account.**

- Checkout da ospite attivo di default, disattivabile da `admin`: l'ordine crea o
  aggiorna la scheda cliente tramite l'email; il cliente riceve un link sicuro per
  seguire l'ordine.
- Account facoltativo a fine ordine; la scheda si collega dopo la verifica
  dell'email (4.4).
- Coupon con limite per cliente e solo primo ordine: cliente riconosciuto
  dall'email.
- Accedendo durante l'acquisto, il carrello da ospite si unisce a quello
  dell'account.

**Fattura negli ordini online** (art. 22 DPR 633/72):

- aziende: sempre;
- privati: solo se spuntano "Voglio la fattura" e inseriscono il codice fiscale;
- ordini senza fattura: riepilogo mensile dei corrispettivi per aliquota per il
  commercialista;
- impostazione di `admin` per fatturare tutti gli ordini (predefinita spenta).

**Funzionalità future:** accesso al sito con Apple e Google (dopo `AuthFederated`
nel core); scontrino digitale per i privati, da valutare con la ricerca
sull'integrazione fiscale del banco (10.5).

*Scartati:*

- Stripe Checkout ospitato: raccoglie solo la partita IVA europea (niente codice
  fiscale dei privati, SDI, PEC), al massimo 3 campi personalizzati, spedizione non
  ricalcolabile in base all'indirizzo, bonifico, contrassegno, Nexi e ritiro fuori,
  coupon da duplicare su Stripe;
- Stripe Checkout incorporato: risolve la spedizione, ma restano i limiti su dati di
  fatturazione e metodi di pagamento.

### 5.2 Pagamenti online, annullamenti ed email (D47)

**Numero d'ordine online all'invio**, anche con il pagamento in attesa: il cliente
lo usa subito, anche come causale del bonifico. I buchi lasciati dagli ordini
annullati non contano (gli ordini non sono documenti fiscali).

| Metodo | All'invio dell'ordine | Conferma e scarico del magazzino |
|---|---|---|
| Carta, Link, wallet, PayPal | pagamento immediato | subito, alla notifica del gateway |
| Bonifico | email con IBAN e causale, merce prenotata | alla registrazione del bonifico |
| Contrassegno | ordine confermato subito | pagamento registrato quando il corriere versa l'incasso |
| Pagamento al ritiro | ordine confermato subito | pagamento registrato al ritiro |

- **Pagamento non riuscito:** ordine in attesa, il cliente riprova dal link;
  prenotazione durante il pagamento di 30 minuti (impostazione tecnica).
- **Bonifico non arrivato:** "Giorni di attesa del pagamento" (impostazione tecnica,
  predefinita 7): promemoria a metà periodo; alla scadenza ordine annullato in
  automatico, merce liberata, email a cliente e commerciante.

**Ordine pagato e poi annullato** (rimborsi dal gestionale: funzionalità futura):
movimenti `sale_cancel`; il pannello rimanda al rimborso dal gateway con il link al
pagamento; alla notifica del rimborso il gestionale registra una riga `refund` e
aggiorna `payment_status`; nota di credito fuori dal gestionale se la fattura c'era;
email al cliente.

**Email** con `sendMail()` del core (SMTP o Brevo, registro in `MailLog`), template
sovrascrivibili come le pagine del modulo (7), testi in `lang/`:

| Destinatario | Email |
|---|---|
| Cliente | ordine ricevuto (con istruzioni per il bonifico), ordine confermato, promemoria di pagamento, ordine annullato, spedito con tracking, pronto per il ritiro, consegnato (con corriere collegato), fattura disponibile, aggiornamenti del reso |
| Commerciante | nuovo ordine, ordine annullato per mancato pagamento, scorta minima (4.3), nuova richiesta di reso (4.10); errori come fatture rifiutate o spedizioni con problemi secondo 9.1 |

Destinatari delle email al commerciante nelle impostazioni del commerciante (8.2).

**Più avanti:** email di recupero dei carrelli abbandonati (10.5).

### 5.3 Emissione delle fatture (D45, D46)

**Fattura dopo il pagamento**, come in spingy, per ordini e abbonamenti, con
un'eccezione per la merce consegnata prima di essere pagata (art. 6 e 21 DPR
633/72):

1. pagamento completo prima dell'evasione → fattura al pagamento (è il caso degli
   ordini online);
2. merce consegnata prima del pagamento (condizioni posticipate, G9):
   - con `deferred_invoicing` e DDT: fattura al pagamento se arriva prima della
     fattura differita, altrimenti il DDT entra nella fattura differita del mese
     (TD24, entro il 15 del mese successivo);
   - senza: fattura all'evasione, entro 12 giorni;
3. servizi e abbonamenti: sempre al pagamento.

Il tipo di documento (TD01 o TD24) dipende dalle date.

- **Ogni fattura nasce da un ordine** o dal pagamento di un abbonamento, in
  automatico: niente fatture create a mano e niente bozze da controllare. Gli unici
  stati prima dell'invio sono quelli della coda (`pending_send`, `retry_send`).
- **Online:** fattura solo per gli ordini che la richiedono (5.1).
- **Note di credito:** funzionalità futura; per ora le correzioni si fanno fuori dal
  gestionale.
- Le regole vanno verificate con il commercialista (10.6).

*Scartati:* fattura all'evasione come regola generale; fattura all'inizio del
periodo per gli abbonamenti da ufficio; note di credito manuali nel primo rilascio;
fatture create a mano o in bozza da controllare; solo vendite pagate prima della
consegna (senza condizioni posticipate né fattura differita).

### 5.4 Vendita da ufficio (D45, G9)

1. **Preventivo:** bozza → inviato (numero, PDF archiviato, nuova revisione a ogni
   modifica dopo l'invio) → accettato, rifiutato o scaduto (un cron lo segna alla
   scadenza). Accetta l'operatore; con l'ecommerce anche il cliente, dal link
   nell'email o dall'area cliente.
2. **Conferma:** diventa ordine con il suo numero; scarico del magazzino (4.3); rate
   di pagamento in attesa (4.8); email di conferma con PDF.
3. **Evasione:** DDT, spedizione o ritiro (4.9, 4.11); ordine in `processing`,
   `fulfillment_status` aggiornato.
4. **Pagamenti** registrati sulle rate, scadenze in dashboard, `payment_status` dai
   pagamenti.
5. **Chiusura:** ordine evaso e pagato → `completed` in automatico.
6. **Fattura** secondo 5.3.

### 5.5 Abbonamenti (D45, D48)

**Online** (`online_subscriptions`, come spingy):

1. il cliente sceglie piano e prezzo nella pagina dei piani;
2. inserisce i dati di fatturazione sul sito; fattura come negli ordini online:
   aziende sempre, privati su richiesta;
3. paga con Stripe Checkout in modalità abbonamento (prova gratuita, codici
   promozionali, autenticazione della carta): i limiti visti per gli ordini qui non
   contano (dati di fatturazione già raccolti, nessuna spedizione, rinnovo solo con
   carta o SEPA);
4. dalle notifiche di Stripe: abbonamento → pagamento → fattura;
5. area cliente: piano, prossimo rinnovo, fatture, cambio piano, disdetta (rinnovo
   automatico spento), aggiornamento della carta con il portale clienti Stripe
   (`BillingPortal` del core);
6. rinnovo non riuscito, riallineamento e cambio piano come 4.13; l'email di
   pagamento non riuscito porta al portale per aggiornare la carta.

**Da ufficio:**

1. l'operatore crea l'abbonamento: prezzo del piano, data di inizio, metodo e
   condizioni di pagamento;
2. a ogni inizio periodo un cron crea il pagamento in attesa e invia una richiesta
   di pagamento: PDF non fiscale (proforma) con importo, scadenza e IBAN;
3. pagamento registrato → fattura → periodo successivo;
4. rata scaduta → `past_due` con promemoria email; dopo N giorni (impostazione
   tecnica, predefinita 15) → `unpaid`; l'annullamento lo decide il commerciante;
5. disdetta: rinnovo automatico spento, fine alla chiusura del periodo.

### 5.6 Annullamenti e resi da ufficio (D48)

- **Preventivo:** rifiutato o scaduto, senza effetti.
- **Ordine confermato non evaso:** movimenti `sale_cancel`, rate in attesa annullate,
  utilizzo del coupon restituito, email al cliente.
- **Ordine evaso in parte:** si annullano solo le righe non evase (la merce rientra,
  le rate si ricalcolano); le righe evase si gestiscono con un reso (4.10).
- **Ordine pagato:** come 5.2; ordine fatturato: nota di credito fuori dal
  gestionale.
- **Resi:** flusso di 4.10.

## 6. Integrazioni e provider

### 6.1 Architettura dei provider (D49, D50)

Schema di immobili (`FeedProvider` + `ProviderRegistry`):

- ogni integrazione implementa un contratto (chiave, etichetta, campi di
  configurazione, operazioni); un registro statico carica i provider di default da
  `config/module.php` e siti o moduli ne aggiungono con `register()` (7);
- contratti e adattatori in `src/Providers/` del gestionale; classi di integrazione
  e credenziali nel core (2.4).

| Contratto | Provider di default | Più avanti |
|---|---|---|
| `InvoiceProvider` | `fatture_in_cloud`, `xml` | intermediario SDI |
| `PaymentProvider` | `stripe`, `paypal`, `nexi`, `manual` | |
| `CarrierProvider` | `manual` | corrieri dopo la ricerca (6.4) |

Riferimenti esterni: tabella `external_references` per le entità copiate nei
sistemi esterni, `provider` e riferimento sul documento per i documenti nati da un
provider (4.1). Eventi dei webhook in `provider_events` (9.3).

### 6.2 Fatturazione elettronica (D18, D21, D49)

**Fattura sempre generata in locale** (numero, righe, riepiloghi IVA, pagamento) e
poi affidata al provider attivo scelto da `admin`.

**Fatture in Cloud** (coda di spingy):

- cron ogni 5 minuti che crea il documento, verifica l'XML e invia allo SDI;
  controllo dell'esito ogni 12 ore circa; stati e notifiche di 4.12;
- prima della creazione confronta i totali locali con `getNewIssuedDocumentTotals`:
  se differiscono anche di un centesimo, la fattura va in errore;
- documento con cliente, data del pagamento, oggetto con i codici di fattura e
  pagamento, metodo di pagamento (ID di Fatture in Cloud e codice SDI), righe con
  imponibile e ID dell'aliquota, pagamento con importo, stato pagato e conto;
- si blocca con errore se mancano pagamento, metodo o conto sincronizzati, o il
  cliente;
- cliente: azienda con ragione sociale, PEC, P.IVA con prefisso del paese, codice
  SDI e fattura elettronica attiva; privato con nome e cognome; sempre codice,
  codice fiscale, indirizzo, email, telefono, paese;
- aliquote, metodi e conti di pagamento e clienti sincronizzati riusando per nome o
  valore quelli già presenti; gli ID vanno in `external_references`; gli errori
  temporanei non toccano l'ID salvato;
- una fattura trasmessa non si reinvia; prima di ripetere una creazione si controlla
  se il documento esiste già (9.3); il comportamento completo della coda di spingy è
  nell'appendice C.

**XML:** FatturaPA generata con `Custom\Fattura`, stato `generated`, download
singolo o zip mensile per il commercialista o per il portale dell'Agenzia; in futuro
invio tramite intermediario SDI.

**Numerazione delle fatture:** progressivo annuale assegnato dal gestionale con un
sezionale dedicato al sito (es. `125/WEB`); a Fatture in Cloud si passano numero e
sezionale (`number`, `numeration`), così le fatture fatte a mano su Fatture in Cloud
non entrano in conflitto; identico nell'XML.

**Impostazioni fiscali** (`admin`, sincronizzate): provider attivo; regime fiscale
(codici RF); esigibilità IVA; dati del trasmittente (XML); prezzi del catalogo IVA
inclusa o esclusa (4.5); aliquota di ripiego; aliquota di spedizione e commissioni;
sezionale; bollo da 2 € automatico sulle fatture senza IVA sopra 77,47 €. Gli errori
delle fatture seguono 9.1.

*Scartati:* numero delle fatture nel formato `{YYYY}/{mm}{nnnn}` (su Fatture in
Cloud il numero è un intero); numerazione assegnata da Fatture in Cloud (funziona
solo con quel provider).

### 6.3 Pagamenti (D50)

- **Stripe** (`stripe`): modulo carte con Link e pulsanti rapidi (5.1), Checkout in
  modalità abbonamento e portale clienti (5.5), rimborsi registrati alla notifica
  (5.2). Notifiche: pagamento riuscito o fallito, checkout completato, fattura
  dell'abbonamento pagata o non pagata, abbonamento modificato o chiuso, rimborso;
  controllo orario di riallineamento.
- **PayPal diretto** (`paypal`, classe `PayPal` del core): provider separato, non
  tramite Stripe; pulsante PayPal a parte nel checkout; notifiche e rimborsi come per
  gli altri provider.
- **Nexi** (`nexi`): contratti con alias e chiave MAC (XPay classico) o con API key
  (XPay Web, XPay Global), a seconda della banca e del servizio; la classe `Nexi` del
  core si estende con alias e chiave MAC. Pagamento sulla pagina di Nexi, notifica
  che crea la riga di pagamento, rimborsi dal pannello Nexi.
- **Manuale** (`manual`): bonifico, contrassegno, contanti, pagamento al ritiro e
  RiBa registrati dall'operatore.

**Contratto `PaymentProvider`:**

- avvio del pagamento (indirizzo di reindirizzamento o codice per il modulo carte);
- ricezione della notifica: crea o aggiorna la riga di `payments` e salva la
  risposta nel log degli stati;
- controllo dello stato di un pagamento, per il riallineamento;
- registrazione dei rimborsi comunicati dal provider.

Avviare un rimborso dal gestionale resta una funzionalità futura.

*Scartato:* PayPal tramite Stripe (commissioni doppie).

### 6.4 Corrieri (D51)

**Primo rilascio senza corrieri collegati:** solo modalità manuale (4.11), con il
provider `manual`.

**Strategia per quando si realizzano:** prima un aggregatore (una sola integrazione
per i corrieri italiani principali, stati di tracking già uniformati), poi corrieri
diretti come BRT per chi non vuole abbonamenti. L'interfaccia unica permette
entrambi.

**Scelta del servizio** nel sotto-progetto dei corrieri, confrontando costi e
contratti di 2 o 3 commercianti reali. Ricerca iniziale (2026-09-15):

| Servizio | Cosa offre | Contratto |
|---|---|---|
| Sendcloud | API unica per Poste Delivery Business, BRT, GLS, DHL, DPD; tariffe, etichette, tracking, resi, ritiri; API in tutti i piani | tariffe negoziate per BRT e GLS dal piano Lite; contratto proprio in Italia da verificare |
| ShippyPro | oltre 190 corrieri (BRT, GLS, Poste, Crono, SDA) con API REST; etichette PDF A6 o ZPL, tracking unificato | contratto proprio da verificare |
| Qapla' | etichette, tracking con 9 stati standard, notifiche email/SMS/WhatsApp, resi, giacenze; API e webhook | contratto del commerciante; 1 o 2 corrieri inclusi a seconda del piano |
| BRT diretto | API REST Shipment: etichetta, tracking e segnacolli | contratto BRT, nessun abbonamento |

**Contratto `CarrierProvider`:**

- crea l'etichetta dalla spedizione (peso, colli, indirizzo, contrassegno) con PDF,
  tracking e costo reale, e la annulla;
- tracking da webhook o controllo periodico, con gli stati del servizio tradotti in
  quelli di 4.11 e registrati nel log con la risposta;
- email al cliente per `in_transit`, `out_for_delivery` e `delivered`; errori al
  commerciante secondo 9.1;
- servizio del corriere per metodo di spedizione in
  `shipping_methods.provider_service_code`; credenziali del servizio nel core.

## 7. Estensibilità per sito (D11, D52)

**Presentazione.**

- **Pagine dell'ecommerce** (vetrina, carrello, checkout, area cliente): pubblicate
  con `php forge publish:module ecommerce` e modificate in
  `custom/modules/ecommerce/view/`, come in immobili. I dati che ricevono sono
  documentati; ogni cambiamento è segnalato nel changelog del modulo.
- **Email:** stesso meccanismo delle pagine.
- **PDF** (preventivo, ordine, DDT, proforma, etichette): una classe per layout,
  indicata nella configurazione del modulo (es. `pdf.quote`); il sito la sostituisce
  con una propria classe che estende quella base.
- **Testi:** il sito sovrascrive le chiavi dei `lang/` del modulo; il core registra
  la lingua del sito per ultima.
- **Backend del gestionale:** le sue pagine non si personalizzano per sito; un sito
  con esigenze proprie aggiunge Resource sue.

**File di configurazione o database.** Regola: ciò che `admin` o il commerciante
cambiano senza pubblicare codice sta nel database; ciò che richiede codice sta nel
file.

| Dove | Cosa |
|---|---|
| File (`config/module.php` → `custom/config/modules/<slug>.php`) | classi di estensione, classi dei PDF, provider aggiunti, funzionalità del sito, funzionalità da sbloccare alla prima installazione |
| Database (pannello) | funzionalità sbloccate, impostazioni fiscali, tecniche e del commerciante (8.2) |

**Hook per il codice del sito.**

- **Registrazione:** elenco `extensions` di classi nella configurazione, così
  possono estendere sia il sito sia altri moduli (in rsvp è una sola classe).
- **Classe base:** ogni classe estende una base astratta con metodi vuoti e
  riscrive solo quelli che le servono.
- **Esecuzione:** uno dopo l'altro, in ordine di elenco.

| Pacchetto | Hook | Quando e a cosa serve |
|---|---|---|
| ecommerce | `checkoutFields()` | campi in più nel checkout, con la loro validazione |
| ecommerce | `beforeOrderCreate($payload)` | controlla o modifica i dati prima di creare l'ordine; può bloccarlo con un messaggio |
| ecommerce | `productViewData($product, $data)` | dati in più per la scheda prodotto |
| ecommerce | `seo($page, $state)` | meta tag e dati SEO della pagina |
| gestionale | `afterOrderConfirmed($order)` | ordine confermato |
| gestionale | `afterPaymentRecorded($payment)` | pagamento registrato |
| gestionale | `afterInvoiceIssued($invoice)` | fattura emessa |
| gestionale | `afterShipmentUpdated($shipment)` | spedizione aggiornata |
| gestionale | `onStatusChanged($entity, $field, $from, $to)` | ogni cambio di stato registrato nei log (4.1) |
| gestionale | `beforeEmailSend($key, $message)` | prima di ogni email, per cambiare destinatari o contenuto |
| gestionale | `lowStock($products)` | prodotti sotto la scorta minima, es. avvisi su altri canali |

- **Dati in più del sito:** colonna JSON `custom_data` su `orders` e `contacts`
  (4.1).
- **Prezzi:** nessun hook li modifica. Si cambiano solo con listini, campagne e
  coupon (4.6), così totali, IVA e fatture restano coerenti.
- **Errori:** gli hook `before…` e la validazione dei campi del checkout possono
  bloccare l'operazione; negli altri hook l'errore finisce nel log e il flusso
  prosegue, perché l'operazione è già salvata.

**Provider e funzionalità del sito.**

- **Provider aggiuntivi:** il sito li registra nei registri (6.1); gli ID esterni
  vanno in `external_references`, senza modifiche alle tabelle.
- **Funzionalità del sito:** voci proprie nel pannello Funzionalità dalla
  configurazione (chiave, nome, dipendenze), lette con `Gestionale::feature()` come
  quelle dei pacchetti (3.1).

## 8. Installazione, dati iniziali e ambienti

### 8.1 Installazione (D53)

Le tabelle nascono dai Model dei moduli. Nelle nuove versioni di `wonder-image/app`
non esistono più `build/row` e `build/update`, e i moduli non li usano (appendice B).

*Scartati:* `build/row` e `build/update` per modulo, non più presenti nel core.

### 8.2 Sincronizzazione tra locale e produzione (D53, D54)

**Flusso.** Dati iniziali e configurazione si preparano in locale e arrivano in
produzione con il sync del core:

1. modifiche nel backend locale;
2. `php forge export` scrive `shared/sync-data.json`, committato in git;
3. al deploy `forge update` lo importa nel database di produzione.

Le tabelle sincronizzate dichiarano `syncSchema()` nel Model.

**Id stabili (lavoro preparatorio nel core).** Oggi l'import delle tabelle con più
righe svuota la tabella e rinumera gli `id` (appendice B), rompendo le chiavi
esterne dei dati di produzione.

- Nuova opzione del sync (es. `SyncSchema::multiRow()->keepIds()`): esporta anche
  `id` e `deleted`.
- L'import inserisce o aggiorna per `id`, senza svuotare la tabella; le righe
  assenti dal file vengono segnate come cancellate, mai eliminate.
- Le righe di configurazione nascono solo in locale, quindi hanno lo stesso `id` in
  ogni ambiente.

**Cosa si sincronizza.** Regola: la configurazione di `admin` si sincronizza e si
modifica solo in locale; i dati del commerciante e quelli operativi vivono solo in
produzione.

| Sincronizzate (locale → produzione) | Solo produzione |
|---|---|
| `features`, `feature_logs` | catalogo, anagrafiche, listini, campagne, coupon, piani |
| `taxes`, `tax_categories`, `tax_rules` | ordini, magazzino, pagamenti, fatture, DDT, resi, spedizioni, abbonamenti |
| `payment_methods`, `payment_accounts`, `payment_terms`, `payment_term_installments` | log degli stati, `provider_events`, `error_reports` |
| `locations`; sedi del core (`society_locations`, con `keepIds()`) | orari e chiusure del core (`society_location_hours`, `society_location_special_hours`) |
| metodi, zone e tariffe di spedizione; `carriers` | impostazioni del commerciante |
| `label_formats`; impostazioni tecniche e fiscali | **mai sincronizzate:** `document_sequences` (contatori) ed `external_references` (ID di produzione) |

**Impostazioni in due righe uniche** (`SingletonResource`, come immobili e rsvp):

| Riga | Dove si modifica | Contenuto |
|---|---|---|
| Tecniche | in locale da `admin`, sincronizzata | impostazioni fiscali (6.2); impostazioni del negozio (8.5); giorni di reso e da quando contarli (4.10); giorni di attesa del pagamento e minuti di prenotazione (5.2); giorni prima di `unpaid` (5.5); valori predefiniti del DDT (4.9); email per gli errori da risolvere nel codice (9.1) |
| Del commerciante | in produzione da `administrator` | destinatari delle email al commerciante (5.2) e degli avvisi di scorta minima (4.3); email per gli errori da risolvere dal commerciante (9.1); giorni dei lotti in scadenza (4.3) |

**Pagine delle tabelle sincronizzate:** in produzione in sola lettura, con l'avviso
"Si modifica in locale e si pubblica con il deploy" (opzione del core); in locale
chiamano `TableSync::autoExport()` dopo il salvataggio, come le pagine CSS.

**Righe precaricate.**

- Le crea in automatico `forge update` in locale: tabelle dai Model, import di
  `shared/sync-data.json`, poi la classe `Defaults` di ogni modulo abilitato (prima
  il gestionale, poi l'ecommerce) aggiunge le righe mancanti con le regole di 8.3.
  Se ha aggiunto righe, aggiorna il file da committare.
- In produzione non crea righe: arrivano solo dal file. Righe create in produzione
  avrebbero `id` diversi da quelli locali e al deploy successivo verrebbero segnate
  come cancellate perché assenti dal file.

### 8.3 Dati precaricati (D53)

**Regole.**

- **Codice come chiave:** una riga si inserisce solo se il suo codice non esiste,
  contando anche le righe cancellate; ciò che viene modificato o eliminato non viene
  mai sovrascritto né ricreato.
- **Nuove versioni del modulo:** possono aggiungere righe con la stessa regola.
- **Lingua:** nomi in italiano.
- **Funzionalità:** una riga in `features` per ogni chiave dei pacchetti e del sito,
  bloccata di default; il sito può elencare in configurazione le funzionalità da
  sbloccare quando la riga nasce (starter), mai dopo.
- **D20:** si precarica solo ciò che non fa comparire campi o scelte inutili a chi è
  piccolo.

| Dati | Cosa si precarica | Stato |
|---|---|---|
| Aliquote | 22%, 10%, 5%, 4% e le operazioni a 0% con le nature valide, dalla nuova classe del core | aliquote attive, nature nascoste |
| Tipi fiscali e regole | solo "Aliquota ordinaria", con le regole Italia privato e azienda al 22% | con un solo tipo il campo non compare |
| Impostazioni fiscali | nessun provider, regime RF01, esigibilità immediata, ripiego e spedizione al 22%, sezionale `WEB`, bollo automatico, prezzi IVA inclusa | da confermare nei Primi passi (8.6) |
| Metodi di pagamento | bonifico (MP05), carta con Stripe (MP08), PayPal (MP08), contrassegno (MP01, solo spedizione), pagamento al ritiro (MP01, solo ritiro), contanti (MP01, ufficio), Ri.Ba. (MP12, ufficio) | tutti disattivati: si attivano dopo aver inserito IBAN o credenziali |
| Conti di pagamento | Stripe e PayPal; il conto bancario lo crea `admin` con l'IBAN | attivi |
| Condizioni di pagamento | solo "Rimessa diretta" (TP02, 100% subito) | con una sola condizione il campo non compare |
| Impostazioni tecniche | reso entro 14 giorni dal tracking; attesa del pagamento 7 giorni e prenotazione 30 minuti; `unpaid` dopo 15 giorni | modificabili |
| Impostazioni del commerciante | lotti in scadenza entro 30 giorni; destinatari delle email = email della società, se compilata | modificabili |
| Nessun dato | spedizioni, listini, corrieri; le numerazioni nascono al primo numero | — |

I formati delle etichette si precaricano con il sotto-progetto delle etichette
(10.5).

### 8.4 Sede principale (D53, D54)

- **Sedi del core:** "Dati aziendali" di `wonder-image/app` gestisce più sedi della
  società, una predefinita, con dati propri o ereditati dalla predefinita, Place ID,
  orari e chiusure sul modello di Google (spec dei prerequisiti del core, parte E).
- **Sede principale** = sede predefinita del core. All'installazione i `Defaults` del
  gestionale creano la riga di `locations` collegata, con giacenza.
- **Modifica solo in locale:** il gestionale sostituisce la Resource "Dati aziendali"
  del core con la propria (priorità dei moduli nel `ResourceRegistry`), in sola
  lettura fuori dal locale; "Orari e chiusure" resta modificabile in produzione.
- Nessuna copia di dati tra gestionale e core.

*Scartati:* sede principale che copia o legge i dati dalla società; sedi del
gestionale con indirizzo e orari propri copiati nel core con un blocco delle sezioni
(`CorporateData::lock()`).

### 8.5 Ecommerce: impostazioni e condizioni di vendita (D55)

L'ecommerce non ha tabelle: la sua classe `Defaults` crea le righe nelle tabelle del
gestionale.

- **Impostazioni del negozio** (nelle impostazioni tecniche): checkout da ospite
  attivo, fattura su tutti gli ordini disattivata (5.1).
- **Condizioni generali di vendita:** tipo `terms_of_sale` registrato con
  `LegalDocumentTypeContext::addType()` del core; accettazione obbligatoria al
  checkout, registrata con i consensi del core. Il documento nasce con lo stesso
  meccanismo di privacy e cookie (`legal_documents` è versionata e non
  sincronizzata).
- **Testo:** bozza nei `lang/` del modulo con le informazioni del Codice del Consumo
  (recesso di 14 giorni, garanzia legale, spese e tempi di consegna), segnata come da
  far verificare al legale del commerciante (10.6).

### 8.6 Primi passi e blocco del checkout (D55)

- Riquadro in cima alla dashboard del gestionale, visibile ad `admin` finché resta
  qualcosa da fare; ogni voce porta alla pagina da completare e alla guida.
- Controlli calcolati al momento, senza stati salvati. In locale guidano la
  configurazione; in produzione verificano anche le credenziali di produzione.

| Controllo | Quando serve |
|---|---|
| Dati della società: ragione sociale, P.IVA o codice fiscale, email | sempre |
| Indirizzo della sede principale | sempre |
| Impostazioni fiscali confermate: regime e prezzi IVA inclusa o esclusa, con un salvataggio esplicito perché hanno valori precaricati | sempre |
| Almeno un metodo di pagamento attivo, con le credenziali se è online | per ogni canale in uso |
| Almeno un metodo di spedizione con tariffa, oppure un punto di ritiro | con l'ecommerce |
| Provider della fatturazione configurato: Fatture in Cloud oppure dati del trasmittente per l'XML | con la fatturazione elettronica |
| Condizioni generali di vendita pubblicate, invio delle email configurato | con l'ecommerce |

**Blocco del checkout:** gli stessi controlli bloccano gli ordini online finché
mancano pagamento, spedizione o ritiro, condizioni di vendita. La vetrina resta
visibile; il checkout mostra "Il negozio non accetta ordini in questo momento"; il
motivo compare solo ad `admin`. Gli ordini da ufficio non vengono bloccati.

### 8.7 Dati di prova e giacenza iniziale (D55)

**Dati di prova.**

- Comando `php forge gestionale:demo`, solo in locale: in produzione si rifiuta di
  partire.
- Crea prodotti semplici, con varianti, multiprodotti e personalizzazioni; clienti
  privati e aziende; giacenze e lotti; ordini in stati diversi e, con l'ecommerce,
  carrelli. Cresce con ogni sotto-progetto (10.1).
- Scrive solo nelle tabelle non sincronizzate e usa la configurazione precaricata:
  `forge export` non li porta mai in produzione.
- Servono a sviluppo, schermate della guida commercianti e test (9.4, 9.5).

**Giacenza iniziale:** causale `initial_stock` nella rettifica rapida (4.3), usabile
anche senza `purchasing`. L'importazione da file è una funzionalità futura (10.5).

## 9. Errori, test e documentazione

Base del core (verificata il 2026-09-16): `Logger::log()` scrive righe JSON in
`storage/logs/<file>.log` e in debug interrompe l'esecuzione; tabelle `SqlError`,
`MailLog`, `AuthLog`; nessun helper per le transazioni (`ConsentService` usa
direttamente mysqli); `UpdateLock` con `GET_LOCK`; test come script PHP con
`tests/harness.php`, senza PHPUnit né database.

### 9.1 Errori (D56, D57)

**Tre tipi di errore.**

| Tipo | Esempi | Chi lo vede | Dove finisce |
|---|---|---|---|
| Dell'utente | giacenza insufficiente, coupon scaduto, P.IVA non valida | operatore o cliente, con un messaggio tradotto | nessun log |
| Di un servizio esterno | Stripe rifiuta, fattura scartata dallo SDI, Fatture in Cloud non risponde | nel documento e nel riquadro "Da controllare" | log degli stati del documento e `storage/logs/error/<provider>.log` |
| Interno | bug, dati incoerenti | messaggio generico all'utente | `storage/logs/error/gestionale.log`, con il contesto |

- **Errori dell'utente:** eccezione di dominio con chiave di traduzione e parametri
  (es. `stock.insufficient`), così il messaggio è identico in backend, checkout e API.
- **Esecuzioni in background:** in webhook, cron e code il log non interrompe mai
  l'esecuzione (`renderDebug: false`).

**Chi deve intervenire.** Ogni errore dichiara nel codice chi deve risolverlo.

| Chi interviene | Errori | Indirizzi email |
|---|---|---|
| Sviluppatore | interni; credenziali non valide o scadute; webhook con firma non valida; provider che non rispondono dopo tutti i tentativi; fatture scartate per XML non valido | uno o più indirizzi nelle impostazioni tecniche (8.2) |
| Commerciante | fatture scartate per i dati del cliente (es. codice destinatario, P.IVA); pagamenti falliti; spedizioni in eccezione; email non recapitate | uno o più indirizzi nelle impostazioni del commerciante (8.2) |

- **Riquadro "Da controllare"** nella dashboard: `administrator` vede gli errori del
  commerciante, `admin` tutti. Ogni voce porta al documento e alla guida.
- **Email riepilogative** per ciascun gruppo di destinatari: raggruppate e senza
  ripetere lo stesso errore finché non si risolve, come la scorta minima.

**Errori ripetuti senza documento.** Gli errori legati a un documento (fattura,
pagamento, spedizione) si risolvono con il cambio di stato del documento. Per bug,
credenziali scadute, provider irraggiungibili e webhook con firma non valida:

- **`error_reports`:** fingerprint (unico, da servizio, azione, classe
  dell'eccezione, file e riga), audience (`developer`, `merchant`), service, action,
  message, context, occurrences, first_seen_at, last_seen_at, notified_at,
  resolved_at, resolved_by.
- **Email** alla prima occorrenza; le successive aumentano solo il contatore.
- **Pagina "Errori"** nella sezione Sistema, solo per `admin`: si segna un errore
  come risolto; se si ripresenta dopo, riparte l'email.

### 9.2 Dati sempre coerenti (D56)

- **Transazioni** per le operazioni su più tabelle: conferma dell'ordine (righe,
  scarico, prenotazioni, rate, numero), annullamento, reso ricevuto, carico. Se un
  passaggio fallisce non resta nulla a metà. Helper `transaction(fn)` nel core, sul
  modello di `ConsentService` (lavoro preparatorio).
- **Contemporaneità:** `FOR UPDATE` sulle righe di `document_sequences` e `stock`,
  così due ordini contemporanei non prendono lo stesso numero né l'ultimo pezzo.
- **Cron:** lock con `GET_LOCK`, come `UpdateLock`, perché lo stesso cron non parta
  due volte in parallelo.

### 9.3 Servizi esterni (D56)

- **`provider_events`:** provider, environment, event_id (unico), type, payload,
  status (`received`, `processed`, `failed`), attempts, error, processed_at. Un
  evento già elaborato si ignora: Stripe, PayPal e Nexi ripetono i webhook e un
  pagamento non si registra mai due volte.
- **Tentativi automatici** con attesa crescente (5 min, 30 min, 2 h, 12 h) per
  fatture, tracking ed eventi falliti; poi stato di errore e avviso (9.1).
- **Nessun tentativo alla cieca:** prima di ripetere una creazione si controlla se il
  documento esiste già sul provider (es. fattura su Fatture in Cloud con numero e
  sezionale).

### 9.4 Test (D56)

Convenzione del core: script PHP con `tests/harness.php`, lanciati tutti da
`php tests/run.php`.

| Livello | Cosa copre | Database |
|---|---|---|
| Unitari | prezzi e priorità, campagne, coupon e ripartizione degli sconti, IVA e totali per aliquota, rate, tariffe di spedizione, finestra di reso, scelta di lotto e fornitore, stato delle funzionalità, numerazioni | no |
| Integrazione | conferma e annullamento dell'ordine con scarico e prenotazioni, pagamento → fattura, reso ricevuto → carico, trasferimenti, sync con `id` stabili | database locale dedicato, creato dai Model e riempito con i dati di prova |
| Provider | provider finti nei registri per i flussi; risposte reali salvate su file (webhook Stripe, Fatture in Cloud); XML FatturaPA validato con lo schema XSD ufficiale | no |
| Convenzioni | ogni Resource dichiara la sua funzionalità; le tabelle sincronizzate usano gli `id` stabili; ogni chiave di traduzione esiste in `lang/it` | no |
| End-to-end | pochi percorsi nel browser sul sito di prova: carrello, checkout con Stripe in modalità test, area cliente | sito locale |

- **Classi di calcolo senza database:** in `src/Support` ricevono i dati e non
  leggono il database.
- **Database dei test:** scelto con le variabili `DB_*`, mai quello di sviluppo.
- **Esecuzione automatica:** unitari, provider e convenzioni su GitHub Actions a ogni
  push.
- **Prima di ogni rilascio:** unitari e integrazione superati; prova manuale con gli
  account di prova di Stripe e Fatture in Cloud, secondo una checklist nella guida
  sviluppatori.

### 9.5 Documentazione (D15, D32, D57)

**Due guide per ogni funzionalità.**

| | Guida sviluppatori | Guida commercianti |
|---|---|---|
| Per chi | Wonder Image e chi sviluppa i siti | i commercianti; Wonder Image per spiegare e verificare |
| Dove | `docs/` di ciascun pacchetto | una sola guida per gestionale ed ecommerce, in `gestionale/guide/` (tutto il backend è nel gestionale) |
| Contenuti | installazione, configurazione, tabelle, hook, pagine e contratti dei dati, provider | procedure passo passo per pagina, casi particolari (es. fattura scartata dallo SDI), glossario |
| Linguaggio | tecnico | senza termini tecnici, con screenshot |

- **Scrittura:** nel repository del modulo, insieme a ogni sotto-progetto; un
  sotto-progetto è finito solo quando entrambe le guide sono aggiornate. Solo
  italiano; solo le funzionalità esistenti.
- **Spec e piani** in `docs/superpowers/`, fuori dal sommario e quindi non
  pubblicati.

**Spazi GitBook.** Git Sync collega più spazi allo stesso repository, ognuno con la
propria "Project directory" e il proprio `.gitbook.yaml`; le immagini non si
condividono tra spazi (verificato il 2026-09-16).

| Guida | Cartella | Spazio GitBook |
|---|---|---|
| Sviluppatori del gestionale | `gestionale/docs/`, con `.gitbook.yaml` nella radice (`root: ./docs/`, `readme: README.md`, `summary: SUMMARY.md`) come immobili e rsvp | Wonder Gestionale |
| Sviluppatori dell'ecommerce | `ecommerce/docs/` | Wonder Ecommerce |
| Commercianti | `gestionale/guide/`, con `.gitbook.yaml` e immagini proprie | Guida commercianti (Project directory `guide`) |

**Sommario della guida sviluppatori del gestionale:**

```
## Guida introduttiva   Installazione · Struttura del modulo · Sito di prova e dati di prova
## Concetti            Funzionalità e ruoli · Sincronizzazione tra ambienti ·
                       Codici, numerazioni e log degli stati · Prezzi, IVA e totali
## Aree                Catalogo · Magazzino e sedi · Anagrafiche · Listini e promozioni ·
                       Vendite (preventivi, ordini, DDT, resi) · Pagamenti · Fatturazione ·
                       Spedizioni · Abbonamenti
## Estendere il modulo Hook · Provider (pagamento, fatturazione, corrieri) ·
                       Funzionalità del sito · PDF ed email
## Riferimento         Tabelle · Impostazioni · Comandi forge · Errori e log · Traduzioni ·
                       Sviluppo e test · Checklist di rilascio
```

Ogni pagina delle aree spiega tabelle, stati, flussi, funzionalità coinvolte e hook
disponibili.

**Sommario della guida sviluppatori dell'ecommerce:**

```
## Guida introduttiva    Installazione (richiede il gestionale) · Struttura del modulo
## Frontend             Route e flusso · Pagine e dati ricevuti · Personalizzare pagine ed email · SEO
## Checkout e pagamenti Flusso del checkout · Stripe (Payment Element, Express Checkout) ·
                        PayPal e Nexi · Webhook
## Area cliente         Account, ordini, resi, abbonamenti
## Estendere il modulo  Hook del checkout e della vetrina
## Riferimento          API del frontend · Traduzioni e URL · Sviluppo e test
```

**Sommario della guida commercianti** (ordine del menu del backend):

```
## Primi passi          Accedere al pannello · Primi passi · Funzionalità incluse e da attivare ·
                        Il riquadro "Da controllare"
## Catalogo             Prodotti e varianti · Categorie, tag e marchi · Multiprodotto ·
                        Personalizzazioni · Etichette con codice a barre
## Magazzino            Giacenze e rettifiche · Carichi, scarichi e trasferimenti · Lotti e scadenze ·
                        Sedi, orari e chiusure · Avvisi di scorta minima
## Clienti e fornitori  Anagrafiche · Account dei clienti
## Prezzi e promozioni  Listini · Sconto massivo · Coupon
## Vendite              Preventivi · Ordini · Pagamenti e scadenze · DDT · Resi
## Negozio online       Ordini online e carrelli · Impostazioni del negozio · Condizioni di vendita
## Fatturazione         Fatture · Fattura differita · Casi particolari (fattura scartata, bollo)
## Spedizioni           Metodi e tariffe · Spedizioni e tracking · Ritiro in sede
## Abbonamenti          Piani · Abbonamenti dei clienti
## Glossario
```

- **Schema di ogni pagina della guida commercianti:** riquadro iniziale ("Inclusa";
  "Da attivare su richiesta", con i requisiti; "Configurata da Wonder Image", per le
  impostazioni sincronizzate in sola lettura in produzione); a cosa serve; passo
  passo con screenshot; casi particolari.
- **Screenshot** dal sito locale con i dati di prova, mai con dati reali.
- **"Funzionalità incluse e da attivare":** tabella generata con un comando da
  `config/features.php` di entrambi i pacchetti.
- **Rimandi dal backend:** nuova opzione `docs()` nel `PageSchema` del core (lavoro
  preparatorio): ogni pagina mostra il pulsante "Guida" verso la pagina giusta, e la
  possono usare anche gli altri moduli. Messaggi di errore, stati vuoti e voci di "Da
  controllare" rimandano a una sezione precisa, es. "Casi particolari". L'indirizzo
  base della guida sta nella configurazione del modulo.

## 10. Roadmap

### 10.1 Regole (D8, D58)

- **Ordine:** dipendenze tecniche, con priorità all'online; rilascio quando il nucleo
  è completo.
- **Ciclo di ogni sotto-progetto:** spec, piano, implementazione, test, entrambe le
  guide aggiornate, dati di prova estesi. Se manca un pezzo, il sotto-progetto non è
  finito.
- **Versioni:** entrambi i pacchetti in `0.x` durante il nucleo, `1.0.0` al termine,
  con semver e `CHANGELOG.md` come immobili e rsvp.
- **Sito di prova:** `boilerplates/ecommerce-site`, sul modello di `immobili-site`
  (da `new-site`, gestionale ed ecommerce collegati via path repository). Nasce in
  G1, ospita test d'integrazione, end-to-end e screenshot; dopo il rilascio diventa
  la base dello starter.

### 10.2 Lavori preparatori in `wonder-image/app` (D58)

| Quando | Lavoro | Dove nella spec |
|---|---|---|
| Prima del gestionale | sync con `id` stabili | 8.2 |
| Prima del gestionale | `Defaults` dei moduli in `forge update` locale | 8.2 |
| Prima del gestionale | pagine delle tabelle sincronizzate in sola lettura in produzione | 8.2 |
| Prima del gestionale | sedi della società in "Dati aziendali", con orari e chiusure sul modello di Google e migrazione dei dati esistenti | 8.4 |
| Prima del gestionale | helper `transaction(fn)` | 9.2 |
| Prima del gestionale | opzione `docs()` nel `PageSchema` | 9.5 |
| Prima del gestionale | classe delle aliquote IVA italiane | 4.5 |
| Con E2 | credenziali PayPal e Nexi in `Credentials::api()`, tabella `security` e pagina backend; alias e chiave MAC nella classe `Nexi` | 2.4, 6.3 |
| Dopo il primo rilascio | `AuthFederated` (accesso con Google e Apple) | 10.4 |
| Con le etichette | EAN-13 ed EAN-8 in `createBarcode()` | 10.5 |

Design dei lavori "prima del gestionale": `packages/app/docs/superpowers/specs/2026-09-16-prerequisiti-moduli-gestionale-design.md`.

### 10.3 Nucleo del primo rilascio (D59)

In ordine di dipendenza; `1.0.0` al termine.

| # | Sotto-progetto | Contenuto | Capitoli |
|---|---|---|---|
| — | Lavori preparatori nel core | quelli "prima del gestionale" | 10.2 |
| G1 | Fondamenta | repository `wonder-image/gestionale`; scheletro del modulo; funzionalità e pannello; codici; log degli stati; numerazioni; impostazioni; sync e `Defaults`; errori (`error_reports`, `provider_events`, "Da controllare"); IVA e impostazioni fiscali; sede principale; base di "Primi passi"; hook; test e GitHub Actions; spazi GitBook; comando dei dati di prova; sito di prova | 3, 4.1, 4.5, 4.14, 7, 8, 9 |
| G2 | Catalogo, magazzino base, anagrafiche | modelli, varianti, prodotti, attributi, brand, categorie, tag, immagini, SKU, EAN e MPN; giacenze, movimenti, rettifiche, prenotazioni; clienti e fornitori; avvisi di scorta minima | 4.2, 4.3, 4.4 |
| G3 | Magazzino avanzato | più sedi e trasferimenti; acquisti e documenti di carico; lotti e scadenze; giacenza per fornitore | 4.3 |
| G4 | Ordini e pagamenti | classe dei prezzi (prezzo base e scontato); ordini e righe (fasi carrello e ordine, totali e IVA, numerazione); gestione degli ordini nel backend (elenco, dettaglio, stati, evasione, annullamento); metodi e conti di pagamento; pagamenti; prenotazioni e scarico alla conferma con sedi, lotti e fornitori; resi con ricarico | 4.6 (priorità), 4.7, 4.8, 4.10 |
| G5 | Multiprodotto e personalizzazione | componenti e loro scarico; campi di personalizzazione sulle righe | 4.2, 4.7 |
| G6 | Listini e promozioni | listini cliente con scaglioni; sconto massivo; coupon; ripartizione degli sconti sulle righe | 4.6 |
| G7 | Spedizioni | metodi, zone e tariffe; spedizioni e tracking manuali; ritiro in sede | 4.11 |
| G8 | Fatturazione elettronica | fatture dopo il pagamento; provider XML e Fatture in Cloud con coda; bollo | 4.12, 5.3, 6.2 |
| E1 | Negozio online (`wonder-image/ecommerce`) | vetrina, carrello, checkout con Stripe Payment Element ed Express Checkout, acquisto da ospite, area cliente, webhook, richiesta di reso, email, condizioni di vendita, blocco del checkout, SEO | 2.3, 5.1, 5.2, 6.3, 7, 8.5, 8.6 |

- **G3 subito dopo G2:** lo scarico degli ordini (G4) nasce già con sedi, lotti e
  fornitori.
- **G6 prima di spedizioni e fatture:** righe e totali nascono già con listini e
  sconti.

### 10.4 Dopo il primo rilascio (D59)

1. **Starter** da `boilerplates/ecommerce-site` (2.3).
2. **E2 PayPal e Nexi** (6.3), con i lavori nel core (10.2).
3. **G9 Vendite da ufficio:** creazione degli ordini da ufficio, preventivi con
   revisioni e PDF, condizioni di pagamento a rate e scadenzario, DDT e fattura
   differita TD24, fattura della merce consegnata prima del pagamento (4.7–4.9, 5.3,
   5.4, 5.6).
4. **G10 + E3 Abbonamenti** da ufficio e online (4.13, 5.5).
5. **E4 Portale B2B**, con account creato dall'azienda cliente (4.4).
6. **`AuthFederated`** nel core e accesso al sito con Apple e Google (4.4, 5.1).
7. **Funzionalità future** (10.5).

### 10.5 Funzionalità future

Fuori dal primo rilascio e dalla sequenza di 10.4; ciascuna con la propria spec
quando si realizza.

- **Etichette con codice a barre (D30, `barcode_labels`):**
  - stampa dall'elenco prodotti (scelta dei prodotti e numero di etichette), dalla
    scheda prodotto e dal documento di carico con un'etichetta per pezzo ricevuto
    (con `purchasing`);
  - formati (`label_formats`, gestiti da `admin`, sincronizzati): pagina, righe e
    colonne, misure, margini e contenuto (nome, variante, prezzo al pubblico IVA
    inclusa, SKU, codice a barre); precaricati i più comuni su foglio A4 e rotolo
    termico (es. A4 3×8 70×37 mm, A4 3×7 70×42,3 mm, A4 5×13 38,1×21,2 mm, rotolo
    50×30 mm, rotolo 62×29 mm), elenco finale nella spec;
  - codice a barre EAN-13 o EAN-8 dal campo `ean`; senza EAN, lo SKU in Code128;
  - PDF con FPDF, già nel core; EAN-13 ed EAN-8 da aggiungere a `createBarcode()`
    (oggi Code128, Code39, Code25, Codabar).
- **Carte regalo vendute (D36):** principi decisi:
  - separate dai coupon: tabelle, pagina del backend (area `Sales`, accanto ai
    pagamenti) e campo al checkout diversi;
  - metodo di pagamento: la carta è una riga di `payment_methods` e ogni utilizzo una
    riga di `payments`, con un nuovo provider `gift_card`; scala il totale da pagare,
    non l'imponibile (es. prodotti 80 €, coupon −10 € → totale 70 € con IVA su 70 €;
    carta con saldo 50 € → restano 20 € da pagare con un altro metodo; in fattura
    totale 70 € e due pagamenti);
  - fiscale: buono multiuso (vendita senza IVA, IVA quando si spende), da confermare
    con il commercialista;
  - proposta iniziale **non approvata**: modello di tipo `gift_card` con tagli fissi,
    solo digitale, email al destinatario con codice e PDF; carta creata a ordine
    pagato; tabelle `gift_cards` e `gift_card_transactions`; coupon e campagne non si
    applicano alle carte; niente carte comprate con carte; rimborsi che tornano sulla
    carta; codice visibile solo nelle ultime 4 cifre e tentativi limitati; funzionalità
    `gift_cards` nell'area Vendite.
- **Rimborsi dei resi e note di credito (D40, D45):** proposta da riprendere: importo
  per riga dal prezzo pagato meno la quota di sconto sul totale; spedizione
  rimborsabile (art. 56 Codice del Consumo in caso di recesso); riga di `payments` di
  tipo `refund` tramite gateway o manuale; nota di credito TD04 se l'ordine è
  fatturato; note di credito anche manuali.
- **Resi:** cambio automatico con un altro prodotto, rimborso con carta regalo,
  etichetta di reso del corriere.
- **Banco con integrazione fiscale (D43):** si realizza insieme all'integrazione
  fiscale, perché il cassiere deve inserire ogni vendita una sola volta.
  - Già deciso: vendita = ordine del canale `pos` confermato e pagato; scarico
    immediato dalla sede; metodi di pagamento, campagne e coupon del canale banco;
    scanner su EAN o SKU; cliente facoltativo, con il suo listino e la fattura su
    richiesta.
  - Ricerca preliminare: documento commerciale e corrispettivi telematici con
    registratore telematico con API, server RT o procedura "documento commerciale
    online"; collegamento tra terminale POS e registratore richiesto dal 2026 (da
    verificare); scontrino digitale per i privati negli ordini online.
  - Bozza delle tabelle, da verificare: `pos_registers` (cassa, sede, dispositivo
    fiscale), `pos_sessions` (apertura e chiusura, fondo cassa, contanti attesi e
    contati, differenza), `pos_cash_movements` (versamenti e prelievi),
    `fiscal_receipts` (documenti commerciali di vendita, reso e annullo, con numero,
    dispositivo, codice lotteria e risposta), `orders.pos_session_id`.
  - Più avanti ancora: funzionamento senza connessione, stampante e cassetto,
    lotteria degli scontrini.
  - *Scartato:* banco nel primo rilascio senza documento fiscale (vendite inserite
    due volte, totali che possono non tornare).
- **Corrieri collegati (D51):** strategia, ricerca e contratto in 6.4.
- **Abbonamenti con prodotti fisici (D44):** `plan_items`, spedizione e scarico del
  magazzino a ogni rinnovo senza ordine.
- **Scontrino digitale** per i privati negli ordini online, da valutare con la
  ricerca del banco (D46).
- **Email di recupero dei carrelli abbandonati** (D47).
- **Importazione da file:** catalogo, clienti e giacenze iniziali (D55); listini
  (D33).
- **Listini per categoria** (D33).
- **Coupon:** generazione in blocco di codici usa e getta, più coupon nello stesso
  ordine (D35).
- **Magazzino:** ordini a fornitore, inventario con conteggio guidato, soglia di
  scorta minima per sede (D27, D29).
- **Anagrafiche:** più utenti della stessa azienda sul portale B2B, referenti
  aziendali, gruppi di clienti (D31).
- **Spedizioni:** zone per CAP, orari di ritiro prenotabili, tariffe in tempo reale
  dal corriere, prenotazione del ritiro del corriere, tracking per singolo collo
  (D41, D42).
- **Marketplace e feed**, compreso Google Merchant (D7, D23).
- **Dati aziendali da Google** (nel core): cron che verifica orari e chiusure sulla
  scheda Google tramite il Place ID, Place ID dall'autocomplete dell'indirizzo, embed
  automatico della mappa (spec dei prerequisiti del core, E7).

### 10.6 Verifiche esterne

**Con il commercialista:**

- IVA di spedizione e commissioni ad aliquota fissa, rispetto all'art. 12 DPR 633/72
  per chi vende prodotti al 10% o al 4% (4.5);
- regole di emissione delle fatture: dopo il pagamento, merce consegnata prima
  all'evasione o in differita, scelta TD01/TD24 dalle date (5.3);
- sezionale dedicato alle fatture emesse dal sito (6.2);
- carte regalo, quando si realizzano: buono multiuso, natura IVA della riga di
  vendita, codice del metodo di pagamento in fattura (10.5).

**Con un legale:** bozza delle condizioni generali di vendita con le informazioni
obbligatorie del Codice del Consumo (8.5).

## 11. Rimandato alle spec dei sotto-progetti

Dettagli volutamente lasciati alla spec del sotto-progetto indicato:

| Dettaglio | Sotto-progetto |
|---|---|
| Tipi, indici e vincoli delle colonne; colonne secondarie delle tabelle | ciascuno |
| Nomi di tabelle e colonne delle due righe di impostazioni | G1 |
| Implementazione dell'opzione del sync con `id` stabili e delle pagine in sola lettura | lavori preparatori nel core |
| Sequenza annuale delle fatture per sezionale | G8 |
| Campi della richiesta di fattura al checkout con la fatturazione elettronica bloccata | E1 |
| Route, URL e chiavi di traduzione del frontend | E1 |
| Ruoli operativi (es. addetto al banco) | chi li richiede |
| Elenco finale dei formati etichette; carte regalo; tabelle del banco; servizio dei corrieri | funzionalità future (10.5) |

## Appendice A — Indice delle decisioni

Il testo originale di ogni decisione è nel commit `4fa9852`.

| # | Decisione | Rivista da | Capitoli |
|---|---|---|---|
| D1 | Due pacchetti | | 2.1 |
| D2 | Perimetro del gestionale | | 2.1 |
| D3 | Accessi e sblocco delle funzionalità | | 3.3 |
| D4 | Lingue e valute (catalogo in una sola lingua) | rivista il 2026-09-11 | 1.3 |
| D5 | Nessuna migrazione | | 1.3 |
| D6 | Frontend | | 2.3 |
| D7 | Canali di vendita | D59 | 1.4 |
| D8 | Ordine dei lavori | D58 | 1.3, 10.1 |
| D9 | Approccio "tabelle condivise" | | 1.3 |
| D10 | Proprietà di dati e backend | | 2.2 |
| D11 | L'ecommerce è un modulo, non un boilerplate | D52 | 2.3, 7 |
| D12 | Starter | D58, D59 | 2.3, 10.4 |
| D13 | Funzionalità e accessi | D54 | 3.1, 3.2, 3.3 |
| D14 | Credenziali tutte in `wonder-image/app` | D58 | 2.4, 10.2 |
| D15 | Documentazione GitBook | D32, D57 | 9.5 |
| D16 | Mappa delle funzionalità | D51, D59 | 3.4 |
| D17 | Abbonamenti separati dagli ordini | | 4.13 |
| D18 | Gestione fiscale IVA come spingy | D19, D21, D49 | 4.5, 6.2 |
| D19 | Prezzi e listini | D33 | 4.5, 4.6 |
| D20 | Semplice per chi è piccolo, completo per chi cresce | | 1.3 |
| D21 | Modello fiscale locale, Fatture in Cloud come provider | D49 | 4.5, 6.2 |
| D22 | Nomi in inglese e glossario | | 2.5, 3.4 |
| D23 | Catalogo, multiprodotto e personalizzazione | | 4.2 |
| D24 | Carrello, preventivi e ordini | D37, D47 | 4.7 |
| D25 | Codici con prefisso | | 4.1 |
| D26 | Log degli stati | | 4.1 |
| D27 | Giacenze, prenotazioni e scorta minima | D54 | 4.3 |
| D28 | Pagamenti e fatture | D45, D49 | 4.8, 4.12 |
| D29 | Sedi, documenti di magazzino, lotti e acquisti | D54, D55 | 4.3 |
| D30 | Etichette con codice a barre | D59 (futura) | 10.5 |
| D31 | Anagrafiche e account | D58 | 4.4 |
| D32 | Documentazione per sviluppatori e per commercianti | D57 | 9.5 |
| D33 | Listini e combinazione dei prezzi | | 4.6 |
| D34 | Sconto massivo | | 4.6 |
| D35 | Coupon | D46 | 4.6 |
| D36 | Carte regalo (funzionalità futura) | | 10.5 |
| D37 | Testata e totali dell'ordine, numerazione | D49 | 4.1, 4.5, 4.7 |
| D38 | Metodi e condizioni di pagamento | D54 | 4.8 |
| D39 | DDT e scarico del magazzino | D59 | 4.3, 4.9 |
| D40 | Resi | | 4.10, 10.5 |
| D41 | Metodi, zone e listini di spedizione | | 4.11 |
| D42 | Spedizioni, tracking e corrieri | D51 | 4.11 |
| D43 | Banco (fase successiva) | | 10.5 |
| D44 | Abbonamenti | | 4.13 |
| D45 | Dal preventivo alla fattura | D59 | 5.3, 5.4 |
| D46 | Checkout online | | 5.1 |
| D47 | Pagamenti online, annullamenti ed email | D56 | 5.2 |
| D48 | Abbonamenti, annullamenti e resi | | 5.5, 5.6 |
| D49 | Provider e fatturazione elettronica | D56 | 6.1, 6.2 |
| D50 | Pagamenti | | 6.3 |
| D51 | Corrieri | | 6.4 |
| D52 | Estensibilità per sito | | 7 |
| D53 | Installazione, dati iniziali e sede principale | D54, D59 | 8.1, 8.3, 8.4 |
| D54 | Sincronizzazione tra locale e produzione | D56 | 8.2, 8.4 |
| D55 | Ecommerce, configurazione guidata e dati di prova | | 8.5, 8.6, 8.7 |
| D56 | Errori e test | D57 | 9.1–9.4 |
| D57 | Struttura delle guide ed errori ripetuti | | 9.1, 9.5 |
| D58 | Roadmap: lavori nel core, priorità all'online, sito di prova | D59 | 10.1, 10.2 |
| D59 | Sequenza dei sotto-progetti | | 10.3, 10.4 |

## Appendice B — Vincoli del framework (verificati il 2026-09-11 e il 2026-09-16)

- **Moduli.** `module.json` validato. Le dipendenze sono verificate a runtime:
  `Registry::enabled()` lancia un'eccezione se la dipendenza non è abilitata.
  Model e Resource sono scoperti dalle cartelle del modulo.
- **Config.** `config/module.php` → `custom/config/modules/<slug>.php` → chiave
  `config` in `custom/config/modules.php`. Solo file: nessuno sblocco da pannello
  nativo.
- **Nessun sistema di eventi.** La proposta sul sistema moduli
  (`docs/app/proposte/module-system-project.md`) prevede hook sincroni
  (`module.boot`, `module.installed`, …), non ancora implementati.
- **Dati iniziali.** Nelle nuove versioni `build/row` e `build/update` non
  esistono più: le tabelle nascono dai Model dei moduli (indicazione del
  2026-09-16; nella copia locale 2.2.0 `UpdateRunner` esegue ancora `app/build/row`
  e `custom/build/row`). Immobili crea l'utente API al primo accesso al pannello.
- **Sync tra ambienti** (`TableSync`, verificato il 2026-09-16 sulla 2.2.0):
  - tabelle con `syncSchema()` nel Model (`singleton` o `multiRow`, colonne
    escludibili); nel core `society`, `society_address`, `society_timetable`,
    `society_legal_address`, `society_social`, `seo` e le tabelle CSS;
  - `forge export` scrive `shared/sync-data.json`, `forge update` lo importa;
    `SYNC_AUTO_EXPORT` riscrive il file dopo il salvataggio nelle Resource che
    chiamano `TableSync::autoExport()`; `SYNC_TABLES` limita le tabelle;
  - l'export toglie `id`, `creation`, `last_modified` e `deleted` e non ordina le
    righe; l'import delle tabelle `multiRow` le svuota (`TRUNCATE` con chiavi
    esterne disattivate) e reinserisce le righe: gli `id` vengono rinumerati e le
    righe cancellate tornano visibili; le tabelle sono importate in ordine di
    chiavi esterne (`SyncTableSorter`);
  - ciò che si modifica in produzione su una tabella sincronizzata viene
    sovrascritto al deploy successivo.
- **Ruoli.** `admin` e `administrator` definiti nel core. Permesso frontend
  `client` con hook `creation`, `modify`, `info`, `validate` e verifica email.
- **Traduzioni.** Il core traduce i testi dell'interfaccia (`lang/*.json`) e gli
  slug delle route (`urls.json`); nessun campo di contenuto traducibile. Immobili
  usa una tabella con una riga per lingua (`immobili_descrizioni`).
- **Schema.** `TableSchema` supporta chiavi esterne (con on update/delete),
  indici, unique, enum, JSON. `FormField` offre `selectSearch` multiplo,
  `checkTree`, `price`, `percentige`, `dateRange` e `repeater` con
  `RepeaterRelation` (righe in tabella collegata, posizione, soft delete).
- **Credenziali.** `Credentials::api()` legge con precedenza `.env` → riga
  `security` → default: Stripe (test/produzione, account), Fatture in Cloud (app,
  client, company, token), Klaviyo, Google, Apple, reCAPTCHA, ipinfo.
- **Plugin già presenti:**
  - Stripe (Checkout, Subscription, SubscriptionSchedule, Customer, Product,
    ProductPrice, Coupon, PromotionCode, TaxRate, BillingPortal);
  - PayPal (`PayPal(clientId, clientSecret, live)`) e Nexi (`Nexi(apiKey, prod)`,
    solo API key: il pagamento XPay con alias/MAC dei vecchi shop non è
    coperto);
  - Fatture in Cloud (Client, Document, EInvoice, Vat, PaymentMethod,
    PaymentAccount);
  - Klaviyo, Brevo, Google Merchant;
  - `Custom\Fattura`: costruisce l'XML FatturaPA (intestazione, righe,
    riepiloghi per aliquota e natura con riferimento normativo, bollo, sconti,
    pagamento) e lo esporta come stringa o file; non risulta usato nel
    framework;
  - `Custom\Fattura\Valori`: `Natura` (N1–N7 con descrizione, senza aliquota;
    N2, N3 e N6 non più validi dal 2021 ma ancora presenti), `Pagamento`
    (MP01–MP23), `CondizioniPagamento` (TP01–TP03), `RegimiFiscali` (RF01–RF19),
    `TipiDocumento` (TD01–TD28, con TD24 e TD25 per la fattura differita);
  - `Custom\Tax\Check` (P.IVA e codice fiscale);
  - `AddressExtension::billing()`.
- **Frontend.** `wonder-image/lib` non ha componenti da shop; `new-site` non ha
  pagine account.
- **GitBook.** In immobili e rsvp `.gitbook.yaml` punta a `./docs/` con
  `README.md` e `SUMMARY.md`; `docs/superpowers/` non compare nel sommario.

## Appendice C — Riferimento funzionale dai progetti analizzati

I progetti servono solo come elenco di funzionalità, non come codice da
riprendere.

### Shop: elenajossifov-com, textile-collection-it, shop-vallisserinae-com

- **Catalogo.**
  - Brand (logo, descrizione, rilevanza); categorie ad albero (`parent_id`,
    immagini); tag (titolo, immagini); attributi (textile, vallis; immagine).
  - Gerarchia model → variant → product.
  - Model: codice, descrizione, composizione, manutenzione, produzione, tempi di
    lavorazione, bullet list, pesi e misure del prodotto e di spedizione,
    rilevanza.
  - Variant: immagini, pesi e misure, posizione nel model.
  - Product: SKU, EAN/barcode, dettaglio, disponibilità, prezzo, saldo, aliquota
    IVA, immagini (textile, vallis), posizione nella variante.
  - Categorie, tag e attributi salvati come array JSON su model e variant.
- **Personalizzazione (elenajossifov).**
  - Sul model: flag `customizable` + definizione JSON dei campi
    `{label, type: text|number|select, price, options[{label, price}]}`.
  - Il server ricalcola i valori inviati e somma i sovrapprezzi.
  - Sulla riga di carrello e ordine salva lo snapshot `[{label, value}]` e
    `customization_price` per unità.
- **Magazzino.**
  - `availability` intero sul prodotto.
  - Log con azione (add/remove/update), disponibilità precedente e successiva,
    causale (ordine, utente).
  - `CHECK_AVAILABILITY` per aggiungere al carrello solo se disponibile.
- **Carrello.**
  - Per sessione o utente, con unione del carrello da ospite dopo il login.
  - Paese, metodo e indirizzo di spedizione, dati di fatturazione, metodo di
    pagamento.
  - Totali: peso, subtotale, offerta, scontabile, coupon, contrassegno,
    spedizione, totale.
- **Checkout e ordini.**
  - Verifica del checkout, creazione dell'ordine con copia dei dati di
    fatturazione e dell'indirizzo (con sede per il ritiro).
  - Righe con prezzo, saldo, aliquota e valore IVA.
  - Log degli stati dell'ordine e dei pagamenti, con la risposta JSON del gateway.
  - Corriere e numero di tracking inseriti a mano.
  - Stati: in attesa, confermato, in lavorazione, in attesa di ritiro, consegnato
    parzialmente, destinatario non disponibile, eccezione, ritirato, consegnato,
    rifiutato, reso.
  - `BILLING_TYPE`: privati, aziende o entrambi.
- **Pagamenti.**
  - Metodi: bonifico, Nexi XPay, Nexi API key, PayPal, Stripe, contrassegno
    (solo spedizione), pagamento al ritiro (solo ritiro).
  - Stati: successo, in attesa, errore.
  - Credenziali PayPal e Nexi salvate nella tabella `ecommerce`.
- **Spedizioni.**
  - Paesi abilitati → listino.
  - Listino: tariffe a scaglioni di peso (JSON), tariffa di eccedenza al kg (sul
    peso effettivo o sulla sola eccedenza), coefficiente del peso volumetrico,
    supplemento carburante %, margine +/- %, arrotondamento, gratuita sopra un
    importo e/o sotto un peso, importo minimo, costo del contrassegno, IVA
    inclusa o no.
  - Sedi con indirizzo, orari, chiusure programmate e ritiro abilitato.
- **Coupon.**
  - Tipi: fisso, percentuale, buono a scalare (con residuo), spedizione gratuita.
  - Vincoli: spesa minima, esclusione dei prodotti in saldo, utente specifico,
    limite per utente, limite totale, date di validità.
  - Richiedono l'accesso.
- **Resi.** Reso per ordine con righe, causale (danneggiato, difettoso, errato,
  acquistato per errore, taglia) e rimborso.
- **Altro.**
  - Annunci; popup con pagine di destinazione.
  - Sincronizzazione del cliente su Stripe; profilo Klaviyo (textile).
  - Feed Google Merchant (elenajossifov); sitemap.
  - Normalizzazione delle posizioni di varianti e prodotti (textile).
  - Aliquote IVA precaricate con i codici natura N1–N7.

### Gestionale: cco-beverage-it

- **Anagrafiche.** Contatti di tipo cliente o fornitore con codice, colore e dati
  personali; indirizzi multipli; dati di fatturazione (privato/azienda, P.IVA,
  CF, SDI, PEC).
- **Prodotti.** Codice univoco, nome, note, prezzo, saldo, disponibilità,
  aliquota IVA.
- **Costi e giacenze per fornitore.**
  - Costo e disponibilità per coppia prodotto-fornitore; disponibilità del
    prodotto = somma dei fornitori.
  - Storico dei costi con quantità e causale.
  - Nello scarico senza fornitore indicato si scala prima dal fornitore con il
    costo più basso.
- **Operazioni di magazzino.**
  - Documenti di carico e scarico (codice, contatto, utente, data, totale, note,
    stato salvato/caricato) con righe (azione, quantità, costo).
  - Log di magazzino con costo e causale (preventivo, utente, carico, scarico,
    reso, magazzino).
- **Listini per cliente.** Prezzo per coppia cliente-prodotto.
- **Preventivi.**
  - Numero e anno, cliente, ritiro o consegna, indirizzi e fatturazione copiati.
  - Pagamento: condizioni e metodo, scadenza, banca/IBAN.
  - Trasporto: a cura di, data, vettore, aspetto dei beni, colli, peso.
  - Importi: subtotale, offerta, scontabile, coupon, contrassegno, spedizione,
    imponibile, imposte, totale. Appunti e note.
  - Stati: in creazione, salvato, confermato, in attesa di ritiro, in attesa di
    consegna, ritirato, consegnato, rifiutato, reso; log degli stati.
  - PDF del preventivo e fattura PDF.
- **Resi.** Righe con quantità, motivo, rimborso e ricarico a magazzino.
- **Configurazione.** Aliquote, metodi di pagamento (con commissione), condizioni
  di pagamento, regimi fiscali, valori predefiniti dei preventivi (P.IVA
  trasmittente, regime fiscale, esigibilità IVA).
- **Statistiche.** Istantanee per periodo: valore e numero di pezzi in magazzino,
  valore ipotetico, preventivi per stato (numero e valore), numero di clienti e
  fornitori; dashboard del magazzino.

### Abbonamenti e fatturazione elettronica: spingy-it

- **Piani.** Tipo, descrizione, funzionalità e limiti per piano (es. numero di
  negozi), visibilità, prodotto Stripe.
- **Prezzi del piano.** Intervallo di addebito, mesi di servizio coperti (1 o
  12), vincolo minimo in mesi, giorni di prova, prezzo e saldo, valuta, prezzo
  Stripe.
- **Abbonamento.**
  - Totali: subtotale, offerta, coupon, netto, paese, aliquota e valore IVA,
    totale.
  - Abbonamento e promozione Stripe, inizio e fine del vincolo, prossimo
    rinnovo, rinnovo automatico, stato.
  - Storico dei cambi piano; log degli stati con risposta.
- **Regole degli abbonamenti.**
  - Diventa attivo al pagamento riuscito; con prova gratuita il primo addebito
    è a fine prova.
  - Il cambio piano aggiorna subito il piano, ma il nuovo importo parte dal
    rinnovo successivo, senza conguagli; le promozioni restano.
  - Finito il vincolo, il rinnovo diventa mensile e si disdice disattivando il
    rinnovo automatico; resta valido fino alla fine del periodo pagato.
  - Rinnovo fallito: "in attesa di pagamento", tentativi gestiti da Stripe
    (fino a 8 in 10 giorni), poi "non pagato" con email per aggiornare la carta.
  - L'accesso resta finché l'abbonamento non viene annullato.
  - I limiti del piano si controllano solo alla creazione di nuovi elementi:
    nulla viene cancellato in caso di downgrade.
  - Controllo orario per riallineare pagamenti e abbonamenti con Stripe se un
    webhook non arriva.
- **Pagamenti.** Uno per fattura Stripe, con metodo e conto di pagamento,
  importo, stato, data; log con risposta.
- **Fatturazione elettronica (Fatture in Cloud + SDI).**
  - Prima il pagamento, poi la fattura: nessuna fattura per prova gratuita,
    importo zero o pagamento fallito.
  - Coda asincrona con due processi CLI protetti da `flock`:
    - ogni 5 minuti crea su Fatture in Cloud, invia allo SDI, gestisce retry e
      rifiuti;
    - ogni ~12 ore controlla l'esito SDI.
  - Stati: in attesa di invio (`pending_send`), da reinviare (`retry_send`),
    inviata (`sent`), errore (`error`), rifiutata (`rejected`), confermata
    (`confirmed`: trasmessa al SDI o accettata).
  - Errori temporanei (es. 502): lo stato non cambia.
  - Una fattura trasmessa non si reinvia: si corregge con nota di credito.
  - Se risulta in errore ma su Fatture in Cloud è già trasmessa, si riallinea da
    sola.
  - Fattura rifiutata: torna in coda automaticamente quando si modificano i dati
    dopo il rifiuto; pulsante "Riprova invio" su errore e rifiutata.
  - Email all'amministrazione (impostazione `email_invoice_errors`) su rifiuto o
    errore; email al cliente con numero, importo e link del documento.
  - Log degli stati con origine (cron, utente) e risposta completa consultabile.
- **Fiscalità.** Vedi D18.
- **Fuori perimetro (D16):** agenti, commissioni e payout, giochi, analytics,
  impersonificazione.

### Limiti dei vecchi progetti da non ripetere

- Campi di settore fissi nel model (composizione, manutenzione, produzione):
  servono attributi configurabili.
- Categorie, tag e attributi in array JSON: niente indici, filtri lenti.
- Giacenza come intero unico, senza prenotazione durante il checkout e senza più
  magazzini.
- Nessuna fattura negli shop; IVA per prodotto senza regole per paese.
- Credenziali dei gateway nella tabella `ecommerce`.
- Tracking manuale; messaggi (es. dei coupon) scritti in italiano nel codice.

## Appendice D — Convenzioni dei moduli esistenti (immobili, rsvp)

- **`module.json`.** Nome, slug, versione, namespace `Wonder\Plugin\<Studly>\`,
  entrypoint che implementa `ModuleInterface`, compatibilità framework e PHP,
  dipendenze, path (src, http, view, resources/assets, lang, tests), route per
  area, permessi, cartella dei Model.
- **`composer.json`.** Tipo `library`, `extra.wonder.module: true`, autoload PSR-4
  + `src/helpers.php`, eventuale `bin/<slug>` per la CLI, repository `path`
  verso `../app` in sviluppo.
- **Entrypoint** (es. `Immobili.php`). `root()`, `viewPath()` con override in
  `custom/modules/<slug>/view/`, `component()`, `layout()`, `renderPage()`,
  `context()` memoizzato, `config()`, `asset()` e `styleOnce()`.
- **`config/module.php`.** Default e registrazione di lang e provider. Override
  del sito in `custom/config/modules/<slug>.php` e in `custom/config/modules.php`.
- **Impostazioni.** Model `Settings` a riga unica + `SingletonResource`.
- **Estensioni del sito (rsvp).** Classe indicata nella config del modulo che
  implementa un'interfaccia con hook (`beforeSubmit`, `afterSubmit`, `seo`, campi
  custom), con fallback a un'implementazione nulla.
- **Integrazioni (immobili).** Contratto `FeedProvider` + `ProviderRegistry` con
  provider di default estendibili dal sito.
- **Task.** Utente API dedicato con token Bearer creato al primo accesso;
  endpoint in `http/api/task/*`; CLI `bin/<slug>` per i cron.
- **Documentazione e test.** GitBook in `docs/` (`.gitbook.yaml` con
  `root: ./docs/`; sezioni getting-started, configurazione, riferimento,
  frontend) e test in `tests/`.

## Appendice E — Correzioni della revisione finale (2026-09-16)

Riscrivendo le decisioni D1–D59 in capitoli sono emerse piccole mancanze e
incoerenze, corrette così:

1. **Prefisso `stk_`** dei documenti di magazzino aggiunto alla tabella dei codici
   (usato in D29, mancava in D25).
2. **`contacts.price_list_id`** aggiunto alle colonne delle anagrafiche (citato in
   D33, mancava in D31).
3. **`products.min_stock_quantity`** aggiunto alle colonne dei prodotti (citato in
   D27, mancava in D23).
4. **`invoices.numeration`** aggiunto alle fatture: il sezionale va copiato sul
   documento, perché numero e sezionale si passano insieme al provider (D49).
5. **Numerazione `{YYYY}/{mm}{nnnn}`** estesa ai documenti di magazzino
   (`stock_documents.number` in D29, senza formato in D37).
6. **Chiusure delle sedi:** tolte dagli "scartati" di D29, in contrasto con D54 che
   le affida al commerciante.
7. **Impostazioni in due righe (D54)** con l'elenco completo dei campi raccolti da
   D27, D29, D39, D40, D47, D48, D49, D55 e D56: i valori predefiniti del DDT (D39) e
   le impostazioni del negozio (D55) stanno nelle tecniche; i destinatari degli
   avvisi di scorta minima (D27) in quelle del commerciante.
8. **Email al commerciante (D47):** fatture rifiutate e spedizioni con problemi
   seguono i destinatari degli errori di D56.
9. **Modello fiscale unico:** le parti di D18 superate da D19, D21 e D49 (prezzi
   solo IVA esclusa, aliquote importate da Fatture in Cloud, ID esterni sulle
   tabelle) non compaiono più; le aliquote a 0% precaricate sono "nascoste", come le
   aliquote che si mostrano o nascondono in D18.
10. **Tabelle solo di produzione (D54):** aggiunti abbonamenti, log degli stati,
    `provider_events` ed `error_reports`, che D54 non nominava.
11. **Mappa delle funzionalità:** D16, chiavi di D22 e rilascio di D59 in un'unica
    tabella; "Ordini" descritta come gestione degli ordini, con la creazione da
    ufficio in G9.
12. **Riferimenti superati eliminati:** "ancora da decidere" di D28, rimandi di D31,
    "se ci sarà il checkout da ospite" e "limiti nella 4.6" di D35, riferimenti a
    "sezione N" e "parte 4.x".
13. **Sedi (2026-09-17, design dei prerequisiti del core):** "Dati aziendali" del core
    gestisce più sedi con orari e chiusure sul modello di Google; `locations` del
    gestionale le estende (magazzino, ritiro, banco) senza copiare dati;
    `location_opening_hours` e `location_closures` eliminate; chiusure gestite dal
    core. Sostituisce la correzione del 2026-09-16 (copia di indirizzo e orari con
    `CorporateData::lock()`).
