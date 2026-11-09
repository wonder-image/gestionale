# Anagrafica a due tabelle e rete di commerciali

Data: 2026-11-09. Stato: **da approvare** (D1 e D2 decise), nessun codice scritto.
Repo coinvolti: app (solo se serve), customer-account, gestionale, ecommerce.
Integra l'issue wonder-image/gestionale#77 (Commerciali) e la estende con la rete di sub-commerciali.

## 1. Perché cambiamo

Oggi `contacts` contiene sia persone sia aziende. Le distingue `type` (`private`/`business`), che però è un dato di fatturazione dell'`AddressExtension`. Una persona e un'azienda sono legate in `contact_relations`, dove entrambe le colonne puntano a `contacts`. Il risultato è confuso: non si capisce chi è il cliente, chi ha l'account e chi intesta i documenti.

Il modello che vogliamo:

- **Contatto** = una persona. Può avere un account (`user_id`) e dati di fatturazione da privato. Non ha nessuna classificazione «Privato/Società».
- **Azienda** = un soggetto a sé. Può esistere senza persone e può essere cliente e/o fornitore. Ha i propri dati fiscali, il listino, il metodo e i termini di pagamento e l'esigibilità IVA.
- Il legame è **molti-a-molti**: una persona può avere più aziende e un'azienda più persone. Ogni legame ha un ruolo e un livello di accesso.
- Nei documenti il **cliente** è la persona stessa oppure un'azienda. Se è un'azienda, la persona compare come **referente**.
- Nell'ordine online resta lo snapshot di fatturazione: chi vuole la fattura sceglie se intestarla da privato o da società.

Una precisazione sulla scelta delle due tabelle. I dati di un'azienda stanno in una sola riga anche con la tabella unica, quindi con 5 contatti non si aggiornano 5 righe. Le due tabelle convengono per altri motivi: un'azienda può esistere da sola; la persona può scegliere con quale azienda comprare; un referente amministratore vede i documenti di tutta la sua azienda; e non c'è più un `type` da tenere coerente.

## 2. Schema dati (customer-account)

### 2.1 `contacts` (persone)
- Si tolgono `type` e `business_name` e i dati da società (P.IVA, SDI, PEC). Restano i dati da privato: nome, cognome, CF e indirizzo di fatturazione.
- `AddressExtension::billing()->withType(false)` esiste già. I dati da società, invece, dipendono da `withCompanyData`, una proprietà privata che il costruttore accende sempre per `billing` e che non ha un setter pubblico. Serve quindi una piccola PR in app (fase 0): `withCompanyData(bool)` pubblico, e `AccountAddressValidation` deve accettare una fatturazione senza `type`.
- Restano `is_customer` e `user_id`, insieme a codice `con_`, email e telefono. Anche `is_supplier` resta solo se la decisione D1 è «no»; se è «sì», `is_supplier` passa a `companies` e «Fornitori» diventa una vista delle aziende.

### 2.2 `companies` (nuova)
- Codice `cmp_` (`Codes::COMPANY`), ragione sociale, P.IVA, CF, SDI, PEC, email e telefono.
- Indirizzo di fatturazione con `AddressExtension::billing()` e i dati da società sempre presenti.
- `is_customer`, `is_supplier`, `active`, note.
- Gestionale estende il modello con `price_list_id`, `payment_method_id`, `payment_term_id` e `vat_collectability`, come fa oggi con `Contact`.
- Le colonne comuni a contatti e aziende (ruoli cliente/fornitore, condizioni commerciali) vanno in un trait o in un'estensione condivisa, per non scriverle due volte.

### 2.3 `contact_relations` → legame persona ↔ azienda
Teniamo la tabella, che ha già `job_title`, `is_primary`, `receives_documents`, `position` e la chiave unica. Cambiano tre cose:
- `company_id` punta a `companies`, non più a `contacts`;
- si aggiunge **`access`**, un enum con tre valori:
  - `none`: solo anagrafica, nessun accesso dall'area cliente;
  - `buyer`: può comprare a nome dell'azienda e vede i propri ordini;
  - `admin`: vede tutti i preventivi, ordini, DDT e abbonamenti dell'azienda, con chi li ha fatti, e gestisce i dati dell'azienda;
- nome della tabella: si valuta in fase 1 se rinominarla in `company_contacts`. Si rinomina solo se non rompe le estensioni dei siti.

### 2.4 Indirizzi
- Nasce `company_addresses`, con lo stesso schema di `contact_addresses` tramite un trait o un'estensione condivisa. `contact_addresses.contact_id` ha una FK dura e `AccountAddresses` presuppone una persona.
- Il gestionale usa gli indirizzi dell'azienda quando il cliente del documento è un'azienda (`OfficeOrders::defaultAddress`, scheda cliente, checkout).

### 2.5 Identità esterne ed export CRM
- In `external_references` le entità sono `contact` e `company`, al singolare.
- **Bug già presente da correggere**: `ContactRecord` (righe 175-178) cerca `entity_type = 'contacts'`, mentre Stripe scrive `'contact'`, quindi gli id esterni non vengono mai trovati.
- Per Stripe le aziende usano l'entità `company`, i metadata `company_id` e la chiave di idempotenza `company-{id}-{env}`. Così un'azienda non collide con la persona che ha lo stesso id numerico.
- `ContactRecord` passa allo schema 2 e si aggiunge `CompanyRecord`. Le relazioni sono esportate da entrambi i lati.
- `CompanyEvents` si affianca a `ContactEvents`, con lo stesso registro estendibile di listener per CRM e newsletter.
- Gli export (`ContactExport` e quelli del gestionale) diventano due: contatti e aziende.

## 3. Documenti e riferimenti (gestionale)

### 3.1 Cliente dei documenti
Su Order, che include preventivi e ordini da ufficio, DeliveryNote, SalesReturn, Payment, Subscription, CouponCustomer e CouponRedemption:
- **`customer_id`** punta solo a persone: è chi compra oppure il referente;
- si aggiunge **`company_id`**, nullable, verso `companies`;
- il cliente mostrato è `company_id ? azienda : persona`;
- «nessuno» si scrive `0`, come fa già il codice: in locale 157 ordini hanno `customer_id = 0` e nessuno ha NULL. Anche `company_id` usa `0`;
- `CouponCustomer.customer_id` oggi è NOT NULL. Un coupon riservato a un'azienda usa `company_id` con `customer_id = 0`, e la chiave unica comprende entrambe le colonne. Lo stesso controllo va fatto su Payment e Subscription;
- `referent_name` resta come snapshot sul PDF («Alla c.a.»);
- `referent_id` diventa ridondante perché coincide con `customer_id` (vedi decisione D2).

Con questa forma si evita di rinominare `customer_id` nei circa 20 punti che lo usano e si possono avere vere FK. La coppia polimorfica `customer_type` + `customer_id` è scartata: non regge le FK ed è più fragile.

L'`user_id` di un ordine intestato a un'azienda è quello dell'account del referente, se ce l'ha, altrimenti null. Oggi invece `OfficeOrders` (784-785) copia l'`user_id` del cliente.

### 3.2 Fornitori
Oggi `supplier_id` compare in 8 tabelle (OrderItem, DeliveryNoteItem, Batch, Stock, StockDocument, StockDocumentItem, StockMovement, ProductSupplier). Fa anche parte della chiave unica `uni_stock`.
- **Proposta (D1)**: il fornitore è sempre un'azienda e `supplier_id` punta a `companies`. Chi fattura ha una P.IVA, quindi una ditta individuale è un'azienda con un referente. In questo caso acquisti e magazzino richiedono solo di ripuntare la colonna e migrare i dati.
- Se invece servono anche fornitori persona, bisogna gestire due colonne in 8 tabelle e cambiare la chiave `uni_stock` usando 0 al posto di NULL. È circa il triplo del lavoro in quell'area.

### 3.3 Logiche che oggi leggono `type`
Sono circa 45 punti. Si rimpiazzano con un solo helper `CustomerKind` che restituisce `business` se c'è `company_id`, altrimenti `business` se lo snapshot ha la P.IVA, altrimenti `private`. Restano coerenti:
- `TaxRule.customer_type` e `TaxResolver`;
- `VatCollectability`;
- `PriceLists::forContact`, che diventa `forCustomer`;
- i tre punti che oggi si basano sulla P.IVA: `Cart` 219, `CustomerPriceList` 64 e `OnlineSubscriptions` 853.

Il listino, il pagamento e l'esigibilità IVA si prendono dall'azienda se il cliente è un'azienda, altrimenti dalla persona.

### 3.4 Backend
- **Menu Anagrafica**: «Contatti» (persone) e «Aziende», ciascuna con i filtri cliente e fornitore. Una Resource del framework gestisce un solo modello, quindi non si costruisce un elenco unico che unisca persone e aziende. Le voci «Clienti» e «Fornitori» aprono le due liste già filtrate, in due schede.
- Nella scheda di una persona ci sono le sue aziende; nella scheda di un'azienda ci sono le persone, con ruolo e livello di accesso.
- La ricerca cliente in ufficio (`OfficeSearch`, preventivo e ordine) cerca sia persone sia aziende. Se si sceglie un'azienda compare il selettore del referente (`DocumentReferents`); se si sceglie una persona che ha aziende, si chiede «intesta a te o a una delle sue aziende».
- Da aggiornare inoltre:
  - PDF, notifiche e copie email ai referenti con `receives_documents`;
  - widget e statistiche;
  - tool MCP (`ContactTools` e `QuoteTools`, con nuovi tool per le aziende);
  - API REST `/clienti/`;
  - coupon riservati, abbonamenti, pagamenti e resi.

## 4. Area cliente e checkout (ecommerce + customer-account)

Nuova funzionalità **`company_accounts`** («Account aziendali»), area Clienti, che richiede `online_sales`. Senza questa funzionalità il sito funziona come oggi: la persona compra e la fattura da società resta uno snapshot libero sull'ordine.

Con `company_accounts` attiva:
- **Checkout**: il campo «Acquista come» propone me stesso e le aziende in cui ho accesso `buyer` o `admin`. Scegliendo un'azienda si scrive `company_id` sull'ordine, la fatturazione si compila dall'azienda e si applicano il suo listino e le sue condizioni. Resta l'opzione «fattura a un'altra società», solo come snapshot.
- **Area account**: gli ordini visibili sono quelli con `customer_id` uguale al mio, più quelli con `company_id` di un'azienda in cui ho accesso `admin`, con il nome di chi li ha fatti. Lo stesso vale per gli abbonamenti. Si aggiunge la scheda «La mia azienda» per i dati e le persone, riservata agli `admin`.
- Gli **ordini** si filtrano con `AccountOrder::condition` e con i controlli del dettaglio in `EcommerceAccountController`.
- **Da correggere**: `AccountAddress::rememberBilling` oggi scrive i dati da società dello snapshot sulla persona. Dovrà aggiornare l'azienda scelta, solo se l'utente ne è `admin`, oppure non salvare nulla.
- I **preventivi nell'area cliente** oggi non esistono. Sono fuori da questo piano: si potranno aggiungere sopra il livello `admin`.

## 5. Commerciali (#77) e rete di sub-commerciali

Questa sezione **supera la decisione D16** della spec di architettura (`2026-09-11-gestionale-ecommerce-architettura-design.md`, righe 116 e 2532), che lasciava «Agenti, commissioni e payout» fuori perimetro.

### 5.1 Modello
- La tabella `sales_agents` contiene:
  - `user_id`: utente backend, obbligatorio per avere il pannello;
  - `contact_id`: opzionale, per i dati anagrafici e di fatturazione delle provvigioni;
  - `parent_id`: il commerciale sopra di lui, null se è al primo livello;
  - commissione predefinita, sconto massimo, `active`.
- La tabella `sales_agent_paths` è una closure table con `ancestor_id`, `descendant_id` e `depth`. Permette una **profondità infinita** senza query ricorsive: la downline di un commerciale è `SELECT descendant_id … WHERE ancestor_id = ?`. Si aggiorna quando si crea un commerciale o se ne cambia il `parent_id`; un commerciale non può diventare figlio di un proprio discendente.
- Si aggiunge `agent_id` su `contacts` e su `companies` tramite l'estensione del modello del gestionale, come succede oggi per `price_list_id`: customer-account non conosce i commerciali. Se il cliente di un documento è un'azienda, vale il commerciale dell'azienda.
- I documenti (preventivi, ordini, DDT, abbonamenti, coupon) salvano `agent_id` al momento della creazione. Se poi cambia il commerciale assegnato al cliente, la storia non cambia.
- Nuovo ruolo backend **`sales_agent`**, distinto da `sales`. Se si usasse `sales`, la regola di visibilità nasconderebbe le righe anche agli operatori d'ufficio.

### 5.2 Visibilità
- Si usa `RowVisibility` di app#151 con regole registrate dal `boot()` del modulo. Le tabelle interessate sono `contacts`, `companies`, ordini e preventivi, DDT, abbonamenti e provvigioni.
- Base: il commerciale vede i clienti assegnati a lui e i documenti con `agent_id` uguale al suo.
- Sui documenti, `agent_id` salvato alla creazione serve all'**attribuzione delle provvigioni**. La **visibilità** usa invece l'OR delle due cose: il documento è visibile se il suo `agent_id` è nel perimetro del commerciale, oppure se il cliente del documento gli è assegnato oggi. Così chi eredita un cliente ne vede lo storico, e chi lo ha perso continua a vedere le proprie vendite.
- Con la rete: vede `agent_id IN (downline compreso sé stesso)`.
- Un contatto è visibile anche se è legato a un'azienda visibile.
- I tool MCP leggono attraverso `RowVisibility::for($user)`, perché il core non lo fa da solo.

### 5.3 Funzionalità (tutte in `config/features.php`, area Vendite)
Si dichiarano subito con `created => false` e si accendono man mano che vengono costruite.

| Chiave | Contenuto | Richiede |
|---|---|---|
| `sales_agents` | Anagrafica commerciali, ruolo, cliente assegnato, `agent_id` sui documenti, visibilità sulle proprie righe, pannello e dashboard base, filtri negli elenchi, il commerciale crea e modifica i propri clienti e fa preventivi e ordini da ufficio | `orders`, `office_sales` |
| `sales_agent_network` | Sub-commerciali con albero infinito, visibilità sulla downline, vista ad albero della rete | `sales_agents` |
| `sales_agent_commissions` | Percentuali per commerciale con eccezioni per categoria e prodotto, maturazione su pagato o evaso, storni sui resi, estratti conto, export | `sales_agents` |
| `sales_agent_network_commissions` | Provvigioni di rete sulle vendite della downline | `sales_agent_network`, `sales_agent_commissions` |
| `sales_agent_discount_limits` | Sconto massimo: sopra il limite l'ordine resta «da approvare» | `sales_agents` |
| `sales_agent_price_lists` | Listini assegnati dall'ufficio ai commerciali | `sales_agents`, `customer_price_lists` |
| `sales_agent_coupons` | Codici del commerciale: l'ordine online con quel codice gli viene attribuito | `sales_agents`, `coupons` |
| `sales_agent_targets` | Obiettivi e statistiche per periodo | `sales_agents` |

Le provvigioni di rete si calcolano con una **strategia da registro** (`CommissionStrategies`), estendibile dai siti. Sono previste due strategie:
- **per livello** (predefinita): il commerciale sopra prende una percentuale sulle vendite della downline, configurabile per profondità, per esempio 2% sul primo livello e 1% sul secondo;
- **differenziale**: il commerciale sopra prende la differenza tra la propria percentuale e quella di chi ha venduto.

Ogni provvigione è una riga di `sales_agent_commissions` con documento, commerciale, livello, base, percentuale, importo e stato (maturata, liquidata, stornata).

## 6. Migrazione dei dati

Il primo negozio è in produzione dal 2026-10-20, quindi la migrazione deve rispettare queste regole:
- gira dall'`UpdateRunner` sul modello di `SharedContactTablesMigration` e `LegacyContactTablesMigration`;
- è **idempotente**, ha un `--dry-run` con un riepilogo e tiene la tabella di mappatura `contact_company_map` (vecchio id contatto → nuovo id azienda).

Passi:
1. Le righe di `contacts` con `type = 'business'` si dividono in due casi:
   - **senza account** (`user_id` vuoto): diventano `companies`, con dati fiscali, condizioni commerciali, indirizzi (in `company_addresses`) e riferimenti esterni;
   - **con account**: sono clienti online in cui `rememberBilling` ha scritto i dati da società sulla persona. La riga resta un contatto, a cui si tolgono i dati da società. Con quei dati si crea un'azienda, e il contatto vi si collega con `access = 'admin'`. Così login e storico ordini restano intatti.
   - In locale: 7 righe `business`, nessuna con account (6 fornitori e 1 cliente). In produzione il conteggio esce dal `--dry-run`.
2. `contact_relations.company_id` viene rimappato. Le aziende senza persone restano senza persone.
3. Nei documenti in cui `customer_id` puntava a un'azienda si scrive `company_id = mappa[customer_id]` e `customer_id = referent_id`, oppure null. Dove serve si ripristina `user_id`.
4. `supplier_id` viene rimappato come previsto dalla decisione D1.
5. Una persona con `type = 'business'` ma senza ragione sociale, cioè un dato sporco, diventa un contatto e finisce nel report.
6. Infine si tolgono `type` e i dati da società da `contacts` e le FK si ripuntano.

In locale ci sono 7 aziende e nessuna relazione. La migrazione delle relazioni si prova con dati di test dedicati.

## 7. Fasi e PR

Ogni fase porta test, documentazione per sviluppatori e per utenti e CHANGELOG. Ogni merge avviene solo dopo l'approvazione.

| Fase | Repo | Contenuto |
|---|---|---|
| 0 | app | `withCompanyData(bool)` pubblico su `AddressExtension`; `AccountAddressValidation` senza `type` |
| 1 | customer-account | `companies`, `company_addresses`, `contact_relations.access`, Resource Contatti e Aziende, `ContactRelations`, `ContactRecord` v2 e `CompanyRecord`, `CompanyEvents`, migrazione, correzione del bug `'contacts'` contro `'contact'` |
| 2a | gestionale | Estensione `Company` (listino, pagamenti, IVA), `company_id` sui documenti, `CustomerKind`, migrazione dei documenti |
| 2b | gestionale | Vendite: ricerca cliente in ufficio, referenti, PDF, notifiche, coupon, abbonamenti, pagamenti, resi, Stripe |
| 2c | gestionale | Acquisti e magazzino: `supplier_id` verso `companies` |
| 2d | gestionale | MCP, REST, export, widget e statistiche |
| 3 | ecommerce | Funzionalità `company_accounts`: checkout «Acquista come», area account per gli `admin`, correzione di `rememberBilling` |
| 4 | gestionale | `sales_agents` (base #77) |
| 5 | gestionale | `sales_agent_network` |
| 6 | gestionale | `sales_agent_commissions` e `sales_agent_network_commissions` |
| 7 | gestionale | Limiti di sconto, listini assegnati, coupon del commerciale, obiettivi |

Le fasi da 1 a 3 vanno rilasciate insieme sul sito, perché la migrazione cambia il significato di `customer_id`. Dalla fase 4 si possono rilasciare una alla volta.

Il repository **customer-account mantiene il nome**: possiede ancora contatti, aziende e account, quindi non serve rinominarlo.

## 8. Decisioni prese

- **D1, fornitori**: il fornitore è sempre un'azienda. `supplier_id` punta a `companies` e `is_supplier` passa da `contacts` a `companies` (deciso il 2026-11-09).
- **D2, referente**: `referent_id` viene eliminato. Quando c'è `company_id`, la persona del documento è `customer_id`. `referent_name` resta come snapshot (deciso il 2026-11-09).

Le altre scelte usano il valore predefinito descritto sopra e si possono cambiare in revisione.
