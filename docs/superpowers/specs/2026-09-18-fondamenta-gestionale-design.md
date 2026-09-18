# G1 Fondamenta di `wonder-image/gestionale`

- **Stato:** da rivedere (2026-09-18)
- **Sotto-progetto:** G1 della [spec di architettura](2026-09-11-gestionale-ecommerce-architettura-design.md) (10.3), capitoli 3, 4.1, 4.5, 4.14, 7, 8, 9
- **Repository:** `wonder-image/gestionale` (privato), `packages/gestionale`
- **Avanzamento:** [TODO.md](../../../TODO.md)

> I riferimenti tra parentesi rimandano ai capitoli della spec di architettura.
> Le decisioni nuove di questo documento sono numerate G1.1, G1.2, … e riassunte
> in fondo. Ciò che non compare qui non è deciso.

## Contesto

- **Core.** `wonder-image/app` ha in `main` i lavori preparatori: `APP_ENV` e
  `Environment`, sync con `id` stabili (`keepIds()`), tabelle modificabili solo in
  locale (`localOnly()`, `Resource::isReadonly()`), righe precaricate dei moduli
  (`ModuleDefaults`, `DefaultRows`, `ModuleDependencySorter`), transazioni
  (`Transaction::run()`, `SelectForUpdate`, `NamedLock`), pulsante "Guida"
  (`PageSchema::docs()`), classi fiscali (`AliquoteIva`, `Natura::valide()`,
  `EsigibilitaIva`) e le sedi della società con orari e chiusure. Diventeranno la
  `2.3.0`, ancora da rilasciare; le aggiunte del piano 1 di G1 saranno la `2.4.0`,
  che il modulo richiede.
- **Moduli esistenti.** `immobili` e `rsvp` danno le convenzioni: `module.json`,
  `composer.json` con repository `path`, entrypoint, `config/module.php`,
  impostazioni a riga unica, test come script PHP, GitBook in `docs/`.
- **Repository del gestionale.** Contiene la spec di architettura e la TODO; il
  codice nasce con questo sotto-progetto.

## Obiettivo

Le fondamenta su cui poggiano tutti i sotto-progetti successivi: il modulo
installabile, le funzionalità sbloccabili, i pezzi comuni dei documenti (codici,
numerazioni, log degli stati, riferimenti esterni), IVA e impostazioni, la sede
principale, la gestione degli errori, gli hook per il sito, i test con
l'integrazione continua, le due guide e il sito di prova.

Alla fine di G1 un sito installa il gestionale, `forge update` crea tabelle e righe
precaricate, `admin` configura IVA, impostazioni e sedi, e la home del backend dice
cosa manca e cosa non va. Non si vende ancora niente: catalogo, magazzino e ordini
arrivano da G2 in poi.

## Non obiettivi

- Catalogo, magazzino, anagrafiche, ordini, pagamenti, fatture, spedizioni: dai
  sotto-progetti successivi (10.3).
- Provider di pagamento, fatturazione e corrieri: i contratti e il registro nascono
  con il primo provider vero (G8, E1). In G1 c'è solo la tabella degli eventi.
- Pacchetto `wonder-image/ecommerce`: E1.
- Ruoli operativi oltre ad `admin` e `administrator` (3.3).

## Design

### 1. Pacchetto e scheletro

**Composer** (`composer.json`): nome `wonder-image/gestionale`, tipo `library`,
licenza MIT, `require` `php: ^8.2` e `wonder-image/app: ^2.4 || dev-main`,
repository `path` verso `../app` con symlink in sviluppo, autoload PSR-4
`Wonder\Plugin\Gestionale\` su `src/` più `src/helpers.php`,
`extra.wonder.module: true`.

**Manifest** (`module.json`): nome "Wonder Gestionale", slug `gestionale`, versione
`0.1.0`, namespace `Wonder\Plugin\Gestionale\`, entrypoint
`Wonder\Plugin\Gestionale\Gestionale`, compatibilità `wonder-app: ^2.4` e
`php: ^8.2`, nessuna dipendenza da altri moduli, `paths` (src, http, view, assets,
lang, tests), `routes.backend`, `permissions.definitions`,
`database.models: "src/Models"`, `database.defaults:
"Wonder\\Plugin\\Gestionale\\Seeding\\Defaults"`, `console.commands` (2.3).

**Cartelle.**

```
src/Models/{System,Tax,Locations}/      Model del modulo, per area
src/Resources/{System,Tax,Locations}/   pagine del backend, stesse aree
src/Support/                            classi pure e servizi (codici, numerazioni,
                                        IVA, errori, log degli stati)
src/Extensions/                         classe base degli hook e loro esecuzione
src/Seeding/Defaults.php                righe precaricate
src/Console/                            comandi forge del modulo
src/Gestionale.php                      entrypoint
config/                                 module.php, features.php, permissions.php,
                                        routes/route.backend.php
lang/it/                                testi dell'interfaccia e degli errori
docs/  guide/                           guida sviluppatori e guida commercianti
tests/                                  script di test e run.php
```

**Entrypoint `Gestionale`** (oltre ai metodi di `ModuleInterface`):

| Metodo | Cosa fa |
|---|---|
| `config(?string $key, mixed $default)` | configurazione del modulo con l'override del sito |
| `feature(string $key): bool` | stato effettivo di una funzionalità (3) |
| `settings(): object` | riga unica delle impostazioni tecniche (5) |
| `merchantSettings(): object` | riga unica delle impostazioni del commerciante (5) |
| `docsUrl(string $page): string` | indirizzo di una pagina della guida commercianti (12) |

Il contesto (funzionalità, impostazioni) si calcola una volta per richiesta e si
azzera con `reset()` nei test.

**Resource base `GestionaleResource extends Wonder\App\Resource`:**

- `public static string $feature = '';` — chiave della funzionalità della pagina;
  vuoto significa "sempre attiva" ed è ammesso solo per le pagine dell'elenco in 3.4.
- applica la funzionalità a navigazione, pagine e API: se non è attiva la Resource è
  come se non esistesse;
- aggiunge il pulsante "Guida" con `PageSchema::docs()`, componendo l'indirizzo con
  `Gestionale::docsUrl()` da `public static string $docsPage`;
- `isReadonly()` resta quello del core (tabelle `localOnly()` fuori dal locale).

**Menu.** Le pagine di configurazione di `admin` entrano nella sezione "Set Up" del
core, che è la "sezione Sistema" della spec di architettura (**G1.1**): Funzionalità,
Aliquote IVA, Tipi fiscali, Regole IVA, Impostazioni, Errori, Sedi. Una sezione nuova
"Gestionale" (`bi-shop`, ordine 400, `admin` e `administrator`) raccoglie le pagine
del commerciante: in G1 solo "Impostazioni".

### 2. Aggiunte a `wonder-image/app`

Tre aggiunte al core, rilasciate insieme in una versione minore prima del resto di G1.

**2.1 Campi modificabili in una pagina in sola lettura (G1.2).**

- `Resource::editableWhenReadonly(): array` restituisce i nomi dei campi che restano
  modificabili quando `isReadonly()` è vera; di default è vuoto.
- Con un elenco non vuoto: `ResourceRouteRegistrar` registra di nuovo la route
  `update` (non `create`, `store` né `delete`); il form mostra il pulsante di
  salvataggio e disabilita tutti i campi tranne quelli dichiarati; l'avviso della
  pagina lo dice ("In produzione si modificano solo orari e chiusure").
- Il controllo vale anche sul server: in aggiornamento il controller tiene solo i
  campi dichiarati e i loro repeater, e ignora il resto della richiesta.
- Gli endpoint generici `api/backend/*` restano vietati come oggi.

**2.2 Riquadri della home del backend (G1.3).**

- Contratto `Wonder\Backend\Contracts\HomeWidget`: `title(): string`,
  `render(): string`, `authorities(): array`, `order(): int`.
- I moduli li dichiarano nella propria configurazione
  (`backend.home_widgets: [ClasseA::class, …]`), letta con
  `Module\ConfigRepository::all()`.
- `Wonder\Backend\Support\HomeWidgets::all()` valida le classi, filtra per ruolo
  dell'utente, ordina e restituisce i riquadri; `app/http/backend/home.php` li mostra
  sopra il contenuto attuale. Un riquadro che solleva un'eccezione viene saltato e
  registrato nel log: la home non si rompe mai.

**2.3 Comandi `forge` dei moduli (G1.4).**

- Chiave `console.commands` del manifest con le classi dei comandi.
- `Forge` le registra dopo i comandi del core, solo per i moduli abilitati, saltando
  con un errore leggibile le classi che non estendono `Wonder\Console\Command`.
- Così il gestionale espone `php forge gestionale:demo` senza un eseguibile proprio.

### 3. Funzionalità (3.1, 3.2)

**Catalogo nel codice.** `config/features.php` del pacchetto elenca le funzionalità
di 3.4: chiave, nome, descrizione, area, dipendenze, modulo richiesto, cosa succede
ai dati quando si blocca. Il sito aggiunge le proprie voci dalla configurazione (8).

**Stato su database.**

| Tabella | Colonne | Sync |
|---|---|---|
| `features` | `feature_key` (unica; `key` è parola riservata in MySQL), `enabled` (`true`/`false`), `changed_at`, `changed_by` (utente) | `multiRow()->keepIds()->localOnly()` |
| `feature_logs` | `feature_id`, `from_value`, `to_value`, `source`, `user_id`, `note` | come sopra |

- Le righe nascono bloccate dai `Defaults` (9); bloccare non cancella mai dati.
- Una chiave presente nel database ma non più nel codice si ignora e la pagina la
  segnala, così una funzionalità tolta da una versione non sparisce dallo storico.

**Stato effettivo.** `Gestionale::feature('orders')` è vero se la riga è sbloccata,
tutte le dipendenze (ricorsive) sono attive e il modulo richiesto è abilitato. Il
calcolo sta in una classe pura `Support\Features\FeatureState`, che riceve catalogo e
righe e non legge il database: dipendenze circolari o mancanti danno errore in fase di
test, non in produzione.

**Pannello "Funzionalità"** (Set Up, `admin`): elenco raggruppato per area con
interruttore, dipendenze, modulo richiesto e ultimo cambio; lo sblocco propone di
sbloccare anche le dipendenze, il blocco elenca cosa smetterà di funzionare e chiede
conferma. Ogni cambio scrive `feature_logs` e richiama `TableSync::autoExport()`. In
produzione è in sola lettura, come tutte le pagine sincronizzate.

**Contro le dimenticanze.** Un test di convenzione verifica che ogni Resource dei
pacchetti dichiari `$feature` o compaia nell'elenco delle pagine sempre attive.

### 4. Fondamenta dei documenti (4.1)

**Codici.** Si usa il campo del core:
`Field::key('code')->text()->uniqueCode('<prefisso>')`. La classe
`Support\Codes` raccoglie i prefissi di 4.1 come costanti (`Codes::PRODUCT = 'pro_'`,
…), unica fonte per tutti i sotto-progetti; un test unitario verifica che siano tutti
diversi e nel formato `xxx_`.

**Numerazione dei documenti.**

| Tabella | Colonne | Sync |
|---|---|---|
| `document_sequences` | `document_type`, `year`, `month`, `last_number`; unica su (`document_type`, `year`, `month`) | mai |

- `Support\Documents\DocumentNumber` (pura): compone `{YYYY}/{mm}{nnnn}`, con più di
  quattro cifre oltre i 9999 documenti nel mese.
- `Support\Documents\DocumentSequences::next(string $type, DateTimeInterface $at):
  string` gira dentro `Transaction::run()`, legge o crea la riga con
  `findByIdForUpdate()`/`SelectForUpdate`, incrementa e restituisce il numero
  formattato. Chiamata fuori da una transazione, ne apre una propria.
- Le fatture avranno una numerazione annuale con sezionale (G8): la classe accetta già
  il tipo di documento, quindi non cambia.

**Log degli stati.**

- `Models\System\StatusLog` è la classe base astratta con le colonne comuni di 4.1
  (`field`, `from_value`, `to_value`, `source`, `user_id`, `message`, `response`) e la
  chiave verso l'entità dichiarata dalla sottoclasse.
- `Support\Status\StatusLogger::record(Model $entity, string $field, string $from,
  string $to, string $source = 'user', ?string $message = null, ?array $response =
  null): void` scrive la riga e richiama l'hook `onStatusChanged` (8).
- In G1 nessuna entità ha stati: la base si prova con un'entità finta nei test
  d'integrazione. Le tabelle vere (`order_status_logs`, …) nascono con i loro
  sotto-progetti e non si sincronizzano.

**Riferimenti esterni.**

| Tabella | Colonne | Sync |
|---|---|---|
| `external_references` | `entity_type`, `entity_id`, `provider`, `environment` (`live`/`test`), `object_type`, `external_id`, `synced_at`, `sync_error`; unica su (`provider`, `environment`, `object_type`, `external_id`), indice su (`entity_type`, `entity_id`) | mai |

`Support\Providers\ExternalReferences::find()`, `::save()` e `::forget()`: un errore
temporaneo non tocca l'ID già salvato (4.1).

### 5. IVA e impostazioni (4.5, 6.2, 8.2)

**Tabelle fiscali** (tutte `multiRow()->keepIds()->localOnly()`, codici parlanti).

| Tabella | Colonne |
|---|---|
| `taxes` | `code`, `name`, `description`, `invoice_description`, `rate` (decimale 5,2), `nature` (codice N, vuoto se l'aliquota è maggiore di zero), `visible` |
| `tax_categories` | `code`, `name`, `description`, `position`, `visible` |
| `tax_rules` | `code`, `country` (ISO 3166-1 alpha-2), `customer_type` (`private`/`business`), `tax_category_id`, `tax_id`; unica su (`country`, `customer_type`, `tax_category_id`) |

Le aliquote non si eliminano: si mostrano o si nascondono. Le pagine stanno in Set Up
e sono in sola lettura in produzione.

**Due classi pure** in `src/Support/Tax/`, senza database, provate con i casi di 4.5:

- `TaxResolver::resolve(array $rules, string $country, string $customerType, int
  $taxCategoryId, int $fallbackTaxId): int` — corrispondenza esatta, altrimenti
  l'aliquota di ripiego delle impostazioni.
- `TaxTotals::summaries(array $lines, bool $pricesIncludeTax): array` — raggruppa per
  aliquota e natura, calcola imponibile e imposta sul totale di ogni aliquota (non riga
  per riga), arrotonda a due decimali e scorpora quando i prezzi sono IVA inclusa.
  Restituisce le righe dei riepiloghi che G4 salverà in `order_tax_summaries` e G8 in
  `invoice_tax_summaries`.

**Impostazioni in due righe uniche (G1.5).**

| Tabella | Dove si modifica | Sync |
|---|---|---|
| `settings` | in locale, da `admin` | `singleton()->localOnly()` |
| `merchant_settings` | in produzione, da `administrator` | mai |

Colonne di G1 in `settings`: `invoice_provider` (vuoto in partenza), `tax_regime`
(codice RF), `vat_collectability` (codice di `EsigibilitaIva`), `transmitter_country`
e `transmitter_fiscal_code` (dati del trasmittente per l'XML), `catalog_prices_include_tax`,
`fallback_tax_id`, `shipping_tax_id`, `invoice_numeration`, `stamp_duty_auto`,
`fiscal_confirmed_at` (data della conferma nei Primi passi), `developer_error_emails`
(uno o più indirizzi separati da virgola).

Colonne di G1 in `merchant_settings`: `merchant_error_emails`.

Ogni sotto-progetto aggiunge le proprie colonne alla riga giusta seguendo la regola di
8.2: configurazione di `admin` nelle tecniche, scelte quotidiane del commerciante nelle
sue. La regola sta nella guida sviluppatori.

**Pagine.** "Impostazioni" in Set Up (`admin`) con i riquadri Fiscale, Documenti ed
Errori; "Impostazioni" nella sezione Gestionale (`administrator`) per la riga del
commerciante. Il salvataggio della pagina fiscale scrive `fiscal_confirmed_at`: serve
ai Primi passi, perché i valori precaricati vanno confermati da una persona (8.6).

### 6. Sede principale (4.3, 8.4)

| Tabella | Colonne | Sync |
|---|---|---|
| `locations` | `code` (`loc_`), `society_location_id` (unica, chiave verso `society_locations` del core), `has_stock`, `is_pickup_point`, `is_pos`, `active` | `multiRow()->keepIds()->localOnly()` |

- I `Defaults` creano la riga collegata alla sede predefinita del core, con giacenza
  attiva (8.4).
- La Resource `Locations\LocationResource` del gestionale **sostituisce** quella del
  core grazie alla priorità del `ResourceRegistry`: stesso percorso e stesso titolo
  "Sedi", con in più i campi del magazzino. "Punto di ritiro" compare solo con
  `shipping`, "Banco" solo con `pos` (D20).
- **Modifica fuori dal locale (G1.6):** la pagina dichiara
  `editableWhenReadonly()` con i due repeater degli orari e delle chiusure (2.1), così
  in produzione si cambiano solo quelli. `administrator` vede la pagina sempre in sola
  lettura tranne quei due riquadri, anche in locale: la Resource lo ottiene
  sovrascrivendo `isReadonly()` in base al ruolo.
- Nessuna copia di dati tra core e gestionale: nome, indirizzo, contatti, dati legali,
  Place ID, orari e chiusure restano nelle tabelle del core.
- Eliminare una sede del core con una riga `locations` collegata è vietato
  (`assertDeletable()`), oltre al divieto sulla sede predefinita che il core ha già.

### 7. Errori e controlli (9.1, 9.3, 8.6)

**Tre tipi di errore** (9.1).

| Tipo | Come si solleva | Dove finisce |
|---|---|---|
| Dell'utente | `Support\Errors\UserError::make('stock.insufficient', ['product' => …])` | messaggio tradotto dai `lang/`; nessun log |
| Di un servizio esterno | `Support\Errors\ProviderError` | log degli stati del documento, `storage/logs/error/<provider>.log` e, senza documento, `error_reports` |
| Interno | qualunque `Throwable` non gestito | messaggio generico all'utente, `storage/logs/error/gestionale.log` con il contesto |

In webhook, cron e comandi il log non interrompe mai l'esecuzione (`renderDebug:
false`).

**Errori ripetuti senza documento.**

| Tabella | Colonne | Sync |
|---|---|---|
| `error_reports` | `fingerprint` (unica), `audience` (`developer`/`merchant`), `service`, `action`, `message`, `context` (JSON), `occurrences`, `first_seen_at`, `last_seen_at`, `notified_at`, `resolved_at`, `resolved_by` | mai |

- `Support\Errors\ErrorReporter::report(string $audience, string $service, string
  $action, Throwable|string $error, array $context = []): void`. L'impronta nasce da
  servizio, azione, classe dell'eccezione, file e riga: lo stesso errore resta una riga
  sola.
- Prima occorrenza: email ai destinatari del proprio gruppo (5). Le successive alzano
  solo il contatore, finché l'errore non viene segnato risolto; se si ripresenta dopo,
  l'email riparte.
- Pagina "Errori" (Set Up, `admin`): elenco con servizio, destinatario, occorrenze,
  prima e ultima volta; si segna risolto dall'elenco.

**Eventi dei provider.**

| Tabella | Colonne | Sync |
|---|---|---|
| `provider_events` | `provider`, `environment`, `event_id` (unica con provider e ambiente), `type`, `payload` (JSON), `status` (`received`/`processed`/`failed`), `attempts`, `error`, `processed_at` | mai |

`Support\Providers\ProviderEvents::receive()` registra l'evento e dice se è nuovo;
`::markProcessed()` e `::markFailed()` chiudono il giro. Un evento già elaborato si
ignora, così un pagamento non si registra due volte (9.3). La usano E1 e G8.

**Riquadri della home** (2.2), registrati dal gestionale:

- **"Primi passi"**, per `admin`, finché resta qualcosa da fare. Controlli calcolati al
  momento, ognuno con il link alla pagina e alla guida: dati della società (ragione
  sociale, P.IVA o codice fiscale, email), indirizzo della sede principale,
  impostazioni fiscali confermate. Ogni sotto-progetto aggiunge i propri controlli
  all'elenco di 8.6, dichiarandoli in una classe pura `Support\Setup\SetupChecks` che
  riceve i dati e restituisce le voci ancora aperte.
- **"Da controllare"**: errori aperti di `error_reports`; `administrator` vede solo
  quelli del proprio gruppo, `admin` tutti. Da G4 si aggiungono i documenti in errore.

**Email.** Un solo invio per gruppo di destinatari, con gli errori raggruppati; passano
dall'hook `beforeEmailSend` (8) e usano il sistema di posta del core.

### 8. Estensibilità per sito (7)

- **Registrazione:** chiave `extensions` nella configurazione del modulo, con l'elenco
  delle classi del sito; si eseguono in ordine.
- **Classe base:** `Extensions\GestionaleExtension`, astratta, con i metodi vuoti; il
  sito riscrive solo quelli che gli servono.
- **Hook di G1:** `onStatusChanged($entity, $field, $from, $to)` e
  `beforeEmailSend($key, $message)`. Gli altri hook di 7 nascono con il sotto-progetto
  che li usa.
- **Esecuzione:** `Extensions\Extensions::run(string $hook, mixed ...$arguments)`. Un
  errore in un hook che gira dopo l'operazione finisce nel log e non ferma il flusso;
  i futuri hook `before…` potranno bloccare con un `UserError`.
- **Funzionalità del sito:** `features.extra` nella configurazione aggiunge voci proprie
  al pannello (chiave, nome, descrizione, area, dipendenze), lette come quelle dei
  pacchetti (3); `features.unlock` elenca le chiavi che nascono sbloccate (9).
- **Provider:** rimandati al primo provider vero (G8, E1).

### 9. Righe precaricate e dati di prova (8.3, 8.7)

**`Seeding\Defaults`** implementa `ModuleDefaults` e usa solo `DefaultRows`, quindi non
tocca mai righe esistenti. In G1 crea:

| Dati | Cosa |
|---|---|
| Funzionalità | una riga bloccata per ogni chiave del catalogo del pacchetto; le chiavi elencate in `features.unlock` della configurazione del sito nascono sbloccate |
| Aliquote | 22%, 10%, 5% e 4% da `AliquoteIva` del core, visibili; le operazioni a 0% con le nature valide (`Natura::valide()`), nascoste |
| Tipi fiscali | solo "Aliquota ordinaria" |
| Regole IVA | Italia privato e Italia azienda, tipo ordinario, al 22% |
| Impostazioni tecniche | riga unica con regime RF01, esigibilità immediata, prezzi IVA inclusa, ripiego e spedizione al 22%, sezionale `WEB`, bollo automatico, nessun provider, conferma vuota |
| Impostazioni del commerciante | riga unica con i destinatari uguali all'email della società, se compilata |
| Sede principale | riga di `locations` collegata alla sede predefinita del core, con giacenza |

I nomi delle righe precaricate sono in italiano (8.3). `forge update` in locale le crea
e riscrive `shared/sync-data.json`; in produzione arrivano solo dal file.

**Comando dei dati di prova.** `php forge gestionale:demo` (2.3) si rifiuta di partire
fuori dal locale e scrive solo in tabelle non sincronizzate. I dati sono dichiarati in
un registro a cui ogni sotto-progetto aggiunge i propri (`Console\Demo\DemoData`); in
G1 il registro è vuoto e il comando lo dice, perché catalogo e ordini non esistono
ancora. L'opzione `--fresh` cancella i dati di prova già creati prima di rifarli.

**Comandi del modulo in G1:** `gestionale:demo` e `gestionale:features-doc`, che scrive
la tabella "Funzionalità incluse e da attivare" della guida commercianti da
`config/features.php` dei pacchetti (12).

### 10. Sito di prova e ambienti (10.1)

- `boilerplates/ecommerce-site` nasce da `new-site`, con `wonder-image/gestionale`
  collegato da repository `path` e `APP_ENV=local` nel `.env`.
- Due database, creati dall'utente: quello di sviluppo del sito e quello dei test,
  scelto con le variabili `DB_*` di un file dedicato, mai quello di sviluppo (9.4). Il
  database dei test fa anche da "produzione" nelle prove di sincronizzazione e di sola
  lettura, con `APP_ENV=production`.
- Il sito ospita i test d'integrazione, le prove nel browser e gli screenshot della
  guida commercianti; dopo il primo rilascio diventa la base dello starter (2.3).

### 11. Test e integrazione continua (9.4)

`php tests/run.php` esegue tutti gli script, come il core; l'harness è quello di
`wonder-image/app`.

| Livello | Cosa copre in G1 | Database |
|---|---|---|
| Unitari | prefissi dei codici, formato e progressivo dei numeri, stato delle funzionalità con dipendenze e moduli, risoluzione dell'aliquota, riepiloghi IVA con prezzi inclusi ed esclusi, impronta degli errori, controlli dei Primi passi | no |
| Convenzioni | ogni Resource dichiara la sua funzionalità, le tabelle sincronizzate usano `keepIds()`, ogni chiave usata nei `lang/` esiste in `lang/it`, i prefissi dei codici sono unici | no |
| Integrazione | righe precaricate che non si duplicano a due esecuzioni, numerazione con due processi in parallelo, log degli stati su un'entità di prova, export e import del sync senza perdere gli `id`, sola lettura fuori dal locale con orari e chiusure ancora modificabili | database di test |

I livelli "provider" ed "end-to-end" di 9.4 non hanno nulla da coprire in G1: non ci
sono ancora provider né vetrina. Nascono con G8 ed E1.

**GitHub Actions** (`.github/workflows/tests.yml`): a ogni push e pull request, PHP 8.2,
checkout del modulo e di `wonder-image/app` (pubblico) in `../app`, `composer install`,
poi test unitari e di convenzione. Quelli d'integrazione restano locali, perché
richiedono il database del sito di prova.

### 12. Documentazione (9.5)

Due guide, entrambe aggiornate dentro G1: un sotto-progetto non è finito se ne manca una.

**Guida sviluppatori** (`docs/`, GitBook "Wonder Gestionale", `.gitbook.yaml` con
`root: ./docs/`). Pagine di G1: installazione del modulo; struttura del modulo;
funzionalità e ruoli; sincronizzazione tra ambienti; codici, numerazioni e log degli
stati; prezzi, IVA e totali; impostazioni e regola su dove aggiungere le colonne; errori
e log; hook; comandi; sviluppo, test e sito di prova.

**Guida commercianti** (`guide/`, GitBook "Guida commercianti", `.gitbook.yaml` proprio,
immagini nella stessa cartella). Pagine di G1, nell'ordine del menu: accedere al
pannello; Primi passi; funzionalità incluse e da attivare, con la tabella generata da
`php forge gestionale:features-doc` da `config/features.php` dei pacchetti; il riquadro
"Da controllare"; sedi, orari e chiusure; impostazioni del commerciante. Ogni pagina ha
il riquadro iniziale ("Inclusa", "Da attivare su richiesta", "Configurata da Wonder
Image"), a cosa serve, il passo passo con gli screenshot presi dal sito di prova e i
casi particolari.

Gli spazi GitBook li collega l'utente al repository, uno per cartella (9.5).
`docs/superpowers/` resta fuori dai sommari.

## Tabelle di G1

Tutte con il prefisso `gestionale_`; qui sono scritte senza, come nella spec di
architettura.

| Tabella | A cosa serve | Sync |
|---|---|---|
| `features` | stato delle funzionalità | `keepIds()` + `localOnly()` |
| `feature_logs` | storico dei cambi | `keepIds()` + `localOnly()` |
| `settings` | impostazioni tecniche e fiscali | `singleton()` + `localOnly()` |
| `merchant_settings` | impostazioni del commerciante | mai |
| `taxes` | aliquote IVA | `keepIds()` + `localOnly()` |
| `tax_categories` | tipi fiscali dei prodotti | `keepIds()` + `localOnly()` |
| `tax_rules` | paese × tipo cliente × tipo fiscale → aliquota | `keepIds()` + `localOnly()` |
| `locations` | sedi del gestionale, legate a quelle del core | `keepIds()` + `localOnly()` |
| `document_sequences` | numerazione dei documenti | mai |
| `external_references` | ID delle entità nei sistemi esterni | mai |
| `provider_events` | eventi dei webhook | mai |
| `error_reports` | errori ripetuti senza documento | mai |

## Validazione

1. **Test del modulo:** `php tests/run.php` verde; unitari e convenzioni anche su
   GitHub Actions.
2. **Installazione da zero** sul sito di prova: `forge update` in locale crea tabelle e
   righe precaricate, scrive `shared/sync-data.json`, e una seconda esecuzione non
   duplica niente.
3. **Sincronizzazione:** una modifica in locale (una funzionalità sbloccata, un'aliquota
   nascosta) arriva nel database che fa da produzione (10) con `forge update`,
   mantenendo gli `id`; una modifica fatta a mano in produzione su una tabella
   sincronizzata viene riportata al valore del file.
4. **Sola lettura:** con `APP_ENV=production` le pagine sincronizzate non si salvano e
   non mostrano "Aggiungi"; nella scheda della sede restano modificabili solo orari e
   chiusure, e il salvataggio dal browser lo conferma.
5. **Funzionalità:** bloccando una funzionalità la sua pagina sparisce da menu, route e
   API; sbloccandola torna con i dati di prima.
6. **Backend nel browser** sul sito di prova: home con i due riquadri, pannello
   Funzionalità, pagine fiscali, impostazioni, errori e sedi.
7. **Errori:** un errore ripetuto genera una sola riga e una sola email; segnandolo
   risolto e ripresentandolo, l'email riparte.

## Piani

1. **Core e scheletro:** le tre aggiunte a `wonder-image/app` con i loro test e il
   rilascio minore; pacchetto, manifest, entrypoint, Resource base, menu; sito di prova
   `boilerplates/ecommerce-site` con il modulo collegato.
2. **Funzionalità:** catalogo nel codice, tabelle, stato effettivo, pannello, righe
   precaricate e sincronizzazione.
3. **Documenti, IVA e sedi:** codici, numerazioni, log degli stati, riferimenti esterni,
   tabelle e classi fiscali, impostazioni, sede principale.
4. **Errori e contorno:** errori e `error_reports`, eventi dei provider, riquadri della
   home, hook, comando dei dati di prova, GitHub Actions, guida sviluppatori e guida
   commercianti.

## Rimandato ai prossimi sotto-progetti

| Dettaglio | Dove |
|---|---|
| Contratti e registro dei provider | G8 (fatturazione), E1 (pagamenti) |
| Tabelle dei log degli stati delle singole entità | il sotto-progetto dell'entità |
| Colonne delle impostazioni oltre a quelle di G1 | il sotto-progetto che le usa |
| Controlli dei Primi passi su pagamenti, spedizioni, fatturazione e condizioni di vendita | G4, G7, G8, E1 |
| Dati del comando `gestionale:demo` | da G2 in poi |
| Sezionale e numerazione annuale delle fatture | G8 |

## Decisioni di questa spec

| # | Decisione |
|---|---|
| G1.1 | Le pagine di configurazione di `admin` stanno nella sezione "Set Up" del core, che è la "sezione Sistema" della spec di architettura; le pagine del commerciante in una nuova sezione "Gestionale" |
| G1.2 | Nel core, `Resource::editableWhenReadonly()`: in sola lettura restano modificabili i campi dichiarati, con il controllo anche sul server |
| G1.3 | Nel core, riquadri della home del backend registrati dai moduli dalla propria configurazione |
| G1.4 | Nel core, comandi `forge` dichiarati dai moduli nel manifest |
| G1.5 | Le due righe di impostazioni sono le tabelle `settings` (tecniche e fiscali, sincronizzata) e `merchant_settings` (del commerciante, solo produzione) |
| G1.6 | In produzione la scheda della sede resta in sola lettura tranne orari e chiusure; `administrator` può modificare solo quelli, anche in locale |
| G1.7 | Le classi pure dell'IVA (risoluzione dell'aliquota e riepiloghi) nascono in G1, non in G4 |
| G1.8 | Il repository `wonder-image/gestionale` è privato, a differenza di `app` e `immobili` |
| G1.9 | Nomi di colonna che evitano le parole riservate di MySQL: `features.feature_key`; `taxes.rate` invece di `value` |
