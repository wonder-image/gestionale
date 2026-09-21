# Piano 4 di 4 — Errori, eventi, riquadri della home, hook, comandi e guide

> **Per chi esegue:** SKILL RICHIESTA: usare superpowers:subagent-driven-development
> (consigliata) o superpowers:executing-plans, un task alla volta. I passi usano le
> caselle `- [ ]` per il tracciamento.

**Obiettivo:** chiudere G1. Gli errori ripetuti hanno una casa e un destinatario, i
webhook non elaborano due volte lo stesso evento, la home dice cosa manca e cosa non
va, il sito può agganciarsi con gli hook, ci sono i comandi, la CI e le due guide.

**Architettura:** `error_reports` **va nel core** (`wonder-image/app`), non nel
modulo: raccogliere gli errori ripetuti serve a qualsiasi sito, anche senza
gestionale. Il core tiene tabella, impronta, contatore, pagina "Errori" e invio
dell'email; chi segnala decide i destinatari passando un risolutore, perché il core
non sa cosa sia un "commerciante". Il gestionale ci aggancia i propri tre tipi di
errore e i destinatari presi dalle due righe di impostazioni.

**Stack:** PHP 8.2, `wonder-image/app` (`Logger`, `sendMail()`, `HomeWidget`,
`ModuleCommands`, `Resource`), MySQL, test come script PHP con l'harness.

**Spec:** `/Users/andreamarinoni/Developer/packages/gestionale/docs/superpowers/specs/2026-09-18-fondamenta-gestionale-design.md`
(sezioni 7, 8, 9, 11, 12; spec di architettura 8.6, 9.1, 9.3, 9.4, 9.5).

## Vincoli globali

- **Lingua:** testi, commenti e messaggi in italiano; nomi in inglese nel codice.
- **Versione del core:** il rilascio di questo piano è `2.2.11`. La `2.3.0` resta
  all'utente, con la gestione dei cron.
- **Tabelle del modulo:** prefisso `gst_`. La tabella del core (`error_reports`) non
  ha prefisso, come tutte le sue.
- **Niente sincronizzazione** per `error_reports`, `gst_provider_events`: sono la
  storia di un ambiente.
- **Log che non fermano niente:** in webhook, cron e comandi si registra con
  `Logger::log(..., renderDebug: false)`.
- **Test d'integrazione:** database `ecommerce_site` dentro `Transaction::run()` che
  annulla sempre.
- **Rami:** `feature/error-reports` in `packages/app`, `feature/errori-e-contorno` in
  `packages/gestionale`.
- **Commit:** uno per task, messaggio in inglese, con la riga
  `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`.

## Struttura dei file

| File | Responsabilità |
|---|---|
| **core** `class/App/Models/System/ErrorReport.php` | tabella `error_reports` |
| **core** `class/App/Support/Errors/ErrorReporter.php` | impronta, contatore, risolto, email |
| **core** `class/App/Resources/System/ErrorReportResource.php` | pagina "Errori" in Set Up |
| `src/Support/Errors/UserError.php` | errore da mostrare all'utente, tradotto |
| `src/Support/Errors/ProviderError.php` | errore di un servizio esterno |
| `src/Support/Errors/Errors.php` | scorciatoie del modulo: log + segnalazione con i destinatari giusti |
| `src/Models/System/ProviderEvent.php` | tabella `gst_provider_events` |
| `src/Support/Providers/ProviderEvents.php` | `receive()`, `markProcessed()`, `markFailed()` |
| `src/Extensions/GestionaleExtension.php` | classe base degli hook del sito |
| `src/Extensions/Extensions.php` | esecuzione degli hook |
| `src/Support/Setup/SetupChecks.php` | controlli dei Primi passi, puri |
| `src/Backend/Widgets/SetupWidget.php` | riquadro "Primi passi" |
| `src/Backend/Widgets/AttentionWidget.php` | riquadro "Da controllare" |
| `src/Console/DemoCommand.php`, `src/Console/Demo/DemoData.php` | `gestionale:demo` |
| `src/Console/FeaturesDocCommand.php` | `gestionale:features-doc` |
| `.github/workflows/tests.yml` | unitari e convenzioni a ogni push |
| `docs/dev/…`, `docs/user/…` | le due guide |

---

### Task 1: Tabella e reporter nel core

**File:**
- Creare: `class/App/Models/System/ErrorReport.php`, `class/App/Support/Errors/ErrorReporter.php`
- Test: `tests/App/Support/ErrorReporterTest.php` (unitario, impronta e regole),
  `tests/App/Support/ErrorReportIntegrationTest.php` non serve: il giro con database
  lo prova il modulo.

**Tabella `error_reports`:** `fingerprint` (191, unica), `audience` (50),
`service` (100), `action` (100), `message` TEXT, `context` JSON, `occurrences` INT
default 1, `first_seen_at` DATETIME, `last_seen_at` DATETIME, `notified_at` DATETIME,
`resolved_at` DATETIME, `resolved_by` INT; `syncSchema(): null`.

**Interfacce:**
- `ErrorReporter::fingerprint(string $service, string $action, Throwable|string $error): string`
  — sha1 di servizio, azione, classe, file e riga; per una stringa, del testo.
- `ErrorReporter::report(string $audience, string $service, string $action, Throwable|string $error, array $context = []): bool`
  — `true` quando ha mandato l'email (prima occorrenza o riapertura).
- `ErrorReporter::resolve(int $id, int $userId): bool`
- `ErrorReporter::open(?string $audience = null): list<array>`
- `ErrorReporter::recipientsUsing(?callable $resolver): void` — chi segnala decide i
  destinatari; senza risolutore non parte nessuna email e resta solo la riga.

- [ ] **Passo 1: test dell'impronta e delle regole** — casi: la stessa eccezione dà la
  stessa impronta, un'azione diversa no; due `report()` di fila lasciano una riga sola
  con `occurrences` a 2 e mandano una sola email; `resolve()` chiude; un `report()`
  dopo la chiusura riapre e manda di nuovo; senza risolutore nessuna email.
  I test dell'impronta girano senza database; quelli con le righe stanno nel modulo
  (task 3), dove c'è il sito di prova.
- [ ] **Passo 2: eseguirlo** — fallisce.
- [ ] **Passo 3: scrivere Model e reporter.** L'email usa `sendMail()` del core, oggetto
  `[<servizio>] <azione>` e corpo con messaggio, contesto e numero di occorrenze.
- [ ] **Passo 4: rieseguire** — verde.
- [ ] **Passo 5: documentare** in `docs/app/concetti/errori.md` + voce nel `SUMMARY.md`.
- [ ] **Passo 6: commit.**

---

### Task 2: Pagina "Errori" nel core e rilascio

**File:**
- Creare: `class/App/Resources/System/ErrorReportResource.php`
- Test: `tests/App/Resources/ErrorReportResourceTest.php`

**Regole:** Set Up, solo `admin`, percorso `app/config/errors`; elenco con servizio,
azione, destinatario, occorrenze, prima e ultima volta, stato; nessuna creazione;
azione "Segna risolto" dall'elenco; niente API.

- [ ] **Passo 1: test** — percorso e sezione, permessi solo `admin`, `pageSchema()`
  senza `create`/`store`, l'elenco ha le colonne previste.
- [ ] **Passo 2: eseguirlo** — fallisce.
- [ ] **Passo 3: scrivere la Resource** con l'azione che chiama `ErrorReporter::resolve()`.
- [ ] **Passo 4: rieseguire** — verde; poi tutta la suite del core.
- [ ] **Passo 5: rilascio `2.2.11`** — versione in `composer.json`, `CHANGELOG.md`,
  commit, tag `v.2.2.11`, push; poi `composer update wonder-image/app` sul sito di
  prova e `php forge update` per creare la tabella.
- [ ] **Passo 6: commit.**

---

### Task 3: I tre tipi di errore del gestionale

**File:**
- Creare: `src/Support/Errors/UserError.php`, `src/Support/Errors/ProviderError.php`,
  `src/Support/Errors/Errors.php`
- Test: `tests/ErrorsTest.php`, `tests/integrazione/ErrorReportsTest.php`

**Interfacce:**
- `UserError::make(string $key, array $replacements = []): self` — messaggio dai
  `lang/` del modulo, `message()` già tradotto.
- `ProviderError::make(string $provider, string $action, string $message, array $context = [], ?Throwable $previous = null): self`
- `Errors::provider(ProviderError $error, string $audience = 'developer'): void` —
  scrive `storage/logs/error/<provider>.log` e segnala al core con i destinatari del
  modulo.
- `Errors::internal(Throwable $error, string $action, array $context = []): void` —
  log `gestionale.log`, nessuna email.
- `Errors::recipients(string $audience): array` — `developer_error_emails` di
  `gst_settings`, `merchant_error_emails` di `gst_merchant_settings`.

- [ ] **Passo 1: test unitario** — `UserError` traduce e non logga; `ProviderError`
  porta provider, azione e contesto; `Errors::recipients()` divide la stringa sulle
  virgole e scarta gli indirizzi vuoti.
- [ ] **Passo 2: eseguirlo** — fallisce.
- [ ] **Passo 3: scrivere le tre classi.**
- [ ] **Passo 4: test d'integrazione** — dentro la transazione: due `Errors::provider()`
  uguali lasciano una riga sola con `occurrences` a 2; `ErrorReporter::open()` la
  elenca; dopo `resolve()` non c'è più tra le aperte; un terzo errore uguale la riapre.
- [ ] **Passo 5: eseguire, correggere, rieseguire** — verde.
- [ ] **Passo 6: commit.**

---

### Task 4: Eventi dei provider

**File:**
- Creare: `src/Models/System/ProviderEvent.php`, `src/Support/Providers/ProviderEvents.php`
- Test: `tests/integrazione/ProviderEventsTest.php`

**Tabella `gst_provider_events`:** `provider` (100), `environment` enum
(`live`/`test`), `event_id` (191, unica con provider e ambiente), `type` (100),
`payload` JSON, `status` enum (`received`/`processed`/`failed`) default `received`,
`attempts` INT, `error` TEXT, `processed_at` DATETIME; `syncSchema(): null`.

**Interfacce:**
- `ProviderEvents::receive(string $provider, string $eventId, string $type, array $payload, string $environment = 'live'): bool`
  — `false` quando l'evento c'era già (va ignorato).
- `ProviderEvents::markProcessed(string $provider, string $eventId, string $environment = 'live'): void`
- `ProviderEvents::markFailed(string $provider, string $eventId, string $error, string $environment = 'live'): void`
  — alza `attempts`.

- [ ] **Passo 1: test d'integrazione** — il primo `receive()` è `true` e crea la riga
  `received`; il secondo con lo stesso id è `false` e non duplica; `markProcessed()`
  scrive stato e data; `markFailed()` alza `attempts` e salva l'errore; lo stesso id in
  `test` e in `live` convive.
- [ ] **Passo 2: eseguirlo** — fallisce.
- [ ] **Passo 3: scrivere Model e classe** (`forge update` per la tabella).
- [ ] **Passo 4: rieseguire** — verde.
- [ ] **Passo 5: commit.**

---

### Task 5: Hook per il sito

**File:**
- Creare: `src/Extensions/GestionaleExtension.php`, `src/Extensions/Extensions.php`
- Modificare: `src/Support/Status/StatusLogger.php` (richiama `onStatusChanged`)
- Test: `tests/ExtensionsTest.php`

**Interfacce:**
- `abstract class GestionaleExtension` con `onStatusChanged(string $entity, int $entityId, string $field, string $from, string $to): void`
  e `beforeEmailSend(string $key, array $message): array` (ritorna il messaggio, anche
  cambiato); entrambi vuoti nella base.
- `Extensions::run(string $hook, mixed ...$arguments): mixed` — esegue le classi di
  `extensions` nella configurazione, in ordine; un errore finisce nel log e non ferma
  il flusso; per `beforeEmailSend` passa il valore di ritorno alla successiva.
- `Extensions::reset(): void` per i test.

- [ ] **Passo 1: test** — una classe finta registrata viene eseguita; due classi vanno
  in ordine; una classe che non estende la base viene saltata; un'eccezione dentro un
  hook non ferma le altre; `beforeEmailSend` incatena le modifiche.
- [ ] **Passo 2: eseguirlo** — fallisce.
- [ ] **Passo 3: scrivere le due classi e agganciare `StatusLogger`.**
- [ ] **Passo 4: rieseguire** — verde.
- [ ] **Passo 5: commit.**

---

### Task 6: Riquadri della home

**File:**
- Creare: `src/Support/Setup/SetupChecks.php`, `src/Backend/Widgets/SetupWidget.php`,
  `src/Backend/Widgets/AttentionWidget.php`
- Modificare: `config/module.php` (`backend.home_widgets`)
- Test: `tests/SetupChecksTest.php`, `tests/WidgetsTest.php`

**Interfacce:**
- `SetupChecks::pending(array $society, array $location, array $settings): list<array{key,title,description,url,done}>`
  — classe pura: dati della società (ragione sociale, P.IVA o codice fiscale, email),
  indirizzo della sede principale, impostazioni fiscali confermate.
- `SetupWidget` (`HomeWidget`): titolo "Primi passi", solo `admin`, non si disegna
  quando non manca niente.
- `AttentionWidget` (`HomeWidget`): titolo "Da controllare", errori aperti;
  `administrator` vede solo i propri, `admin` tutti.

- [ ] **Passo 1: test dei controlli** — senza P.IVA e senza conferma fiscale ci sono
  due voci; con tutto a posto la lista è vuota; ogni voce ha un link.
- [ ] **Passo 2: eseguirlo** — fallisce.
- [ ] **Passo 3: scrivere `SetupChecks`.**
- [ ] **Passo 4: test dei riquadri** — titoli, ruoli, ordine; il riquadro dei Primi
  passi non disegna niente quando la lista è vuota.
- [ ] **Passo 5: scrivere i due riquadri e registrarli in `config/module.php`.**
- [ ] **Passo 6: rieseguire e guardare la home nel browser.**
- [ ] **Passo 7: commit.**

---

### Task 7: Comandi del modulo

**File:**
- Creare: `src/Console/Demo/DemoData.php`, `src/Console/DemoCommand.php`,
  `src/Console/FeaturesDocCommand.php`
- Modificare: `module.json` (`console.commands`)
- Test: `tests/CommandsTest.php`

**Regole:**
- `gestionale:demo` si rifiuta di partire fuori dal locale e scrive solo in tabelle non
  sincronizzate; in G1 il registro è vuoto e il comando lo dice; `--fresh` cancella
  prima di rifare.
- `gestionale:features-doc` scrive la tabella delle funzionalità nella guida
  commercianti da `config/features.php`, tra due marcatori HTML nel file, così il resto
  della pagina resta scritto a mano.

- [ ] **Passo 1: test** — i comandi si istanziano e hanno il nome giusto;
  `DemoData::all()` è vuoto e il comando risponde che non c'è niente da creare;
  `FeaturesDocCommand::table()` genera una riga per funzionalità, con area e dipendenze.
- [ ] **Passo 2: eseguirlo** — fallisce.
- [ ] **Passo 3: scrivere i comandi e dichiararli nel manifest.**
- [ ] **Passo 4: provarli davvero** — `php forge gestionale:demo` e
  `php forge gestionale:features-doc` dal sito di prova.
- [ ] **Passo 5: commit.**

---

### Task 8: Integrazione continua

**File:**
- Creare: `.github/workflows/tests.yml`

**Regole:** a ogni push e pull request, PHP 8.2, checkout del modulo e di
`wonder-image/app` in `../app`, `composer install`, poi solo unitari e convenzioni
(niente integrazione: serve il database del sito di prova).

- [ ] **Passo 1: scrivere il workflow** con un passo che elenca i file `tests/*Test.php`
  ed esclude `tests/integrazione`.
- [ ] **Passo 2: provarlo in locale** con lo stesso comando dell'azione.
- [ ] **Passo 3: commit e push, poi controllare l'esito su GitHub.**

---

### Task 9: Le due guide

**File:**
- `docs/dev/concetti/errori.md`, `docs/dev/concetti/hook.md`,
  `docs/dev/guida-introduttiva/sviluppo-e-test.md`, `docs/dev/SUMMARY.md`
- `docs/user/primi-passi.md`, `docs/user/da-controllare.md`, `docs/user/sedi.md`,
  `docs/user/impostazioni.md`, `docs/user/accedere.md`, `docs/user/SUMMARY.md`

- [ ] **Passo 1: guida sviluppatori** — errori e log (i tre tipi, `error_reports` nel
  core, chi riceve le email), hook (classe base, registrazione, ordine, cosa può
  bloccare), sviluppo e test (sito di prova, transazioni annullate, comandi, CI).
- [ ] **Passo 2: guida commercianti** — accedere al pannello; Primi passi; funzionalità
  incluse e da attivare (tabella generata dal comando); il riquadro "Da controllare";
  sedi, orari e chiusure; impostazioni del negozio. Ogni pagina con il riquadro
  iniziale ("Inclusa" / "Da attivare su richiesta" / "Configurata da Wonder Image"), a
  cosa serve e il passo passo.
- [ ] **Passo 3: aggiornare i due `SUMMARY.md`.**
- [ ] **Passo 4: commit, merge del ramo in `main`, push.**

## Verifica finale del piano

1. `php tests/run.php` verde, unitari e integrazione; suite del core verde.
2. `php forge update` sul sito di prova crea `error_reports` e `gst_provider_events`.
3. Un errore ripetuto genera una riga sola e una sola email; segnandolo risolto e
   ripresentandolo, l'email riparte.
4. Home del backend: i due riquadri; "Primi passi" sparisce quando non manca niente.
5. GitHub Actions verde sul push.
6. Spec, `TODO.md` e memoria aggiornati; G1 chiuso.
