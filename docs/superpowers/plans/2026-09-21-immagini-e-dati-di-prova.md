# Piano 4 di 4 (G2a) — Immagini in differita, dati di prova e guide

> **Per chi esegue:** SKILL RICHIESTA: usare superpowers:subagent-driven-development
> (consigliata) o superpowers:executing-plans, un task alla volta. I passi usano le
> caselle `- [ ]` per il tracciamento.

**Obiettivo:** il commerciante carica venti foto e la scheda si salva subito; le
misure arrivano dopo, da sole. E chi apre un sito vuoto può vedere un catalogo
vero con `php forge gestionale:demo`.

**Architettura:** il campo delle immagini si dichiara **senza** `responsive()`,
quindi `uploadFiles()` scrive solo l'originale e non ci mette minuti. Ogni riga di
`gst_product_images` nasce `pending`; una coda (`Support\Catalog\ImageQueue`)
prende le righe a blocchi, chiama `imageResize()` del core e segna `ready` o
`failed`. Finché non è pronta si mostra l'originale: nessuno aspetta.

**Stack:** PHP 8.2, `wonder-image/app` `^2.2.12`, MySQL, test come script PHP con
l'harness del modulo.

**Spec:** `docs/superpowers/specs/2026-09-21-catalogo-design.md` (§5, §8, G2a.8).

## Vincoli globali

- **Lingua:** testi, commenti e messaggi in italiano; nomi in inglese nel codice.
- **Tabelle:** prefisso `gst_`, `syncSchema(): null`.
- **Le immagini non si ridimensionano al salvataggio** (G2a.8): il campo non
  dichiara `responsive()`, e chi legge mostra l'originale finché non c'è di meglio.
- **Tre tentativi:** una riga che fallisce tre volte resta `failed` e non riprova
  da sola; l'errore si vede nella scheda e finisce in `error_reports` del core.
- **Rifiutare un salvataggio:** `throw UserError::make('chiave')`.
- **Test d'integrazione:** database `ecommerce_site` dentro `Transaction::run()` che
  annulla sempre.
- **Ramo:** `feature/immagini-e-demo` in `packages/gestionale`.
- **Commit:** uno per task, messaggio in inglese, con la riga
  `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`.

## Struttura dei file

| File | Responsabilità |
|---|---|
| `src/Models/Catalog/ProductImage.php` | tabella `gst_product_images` |
| `src/Support/Catalog/ProductImages.php` | quali immagini valgono per una variante, e dove sta il file |
| `src/Support/Catalog/ImageQueue.php` | la coda: prendi, ridimensiona, segna |
| `src/Console/ImagesCommand.php` | `php forge gestionale:images` |
| `src/Scheduler/ImagesTask.php` | la stessa coda, come attività del core |
| `src/Gestionale.php` | dichiara le attività (`ModuleTasks`) |
| `src/Resources/Catalog/ProductModelResource.php` | il riquadro "Immagini" |
| `src/Seeding/CatalogDemo.php` | tre modelli di prova con le loro immagini |
| `docs/user/catalogo-modelli.md` | guida del commerciante alla scheda |
| `docs/user/catalogo-immagini.md` | guida del commerciante alle immagini |

---

### Task 1: La tabella delle immagini e le sue regole

**File:**
- Creare: `src/Models/Catalog/ProductImage.php`, `src/Support/Catalog/ProductImages.php`
- Test: `tests/ProductImagesTest.php`

**Colonne**

| Tabella | Colonne |
|---|---|
| `gst_product_images` | `product_model_id` INT→`gst_product_models`, `product_variant_id` INT→`gst_product_variants` (vuoto = vale per tutte), `file` JSON, `alt`, `position` INT, `status` enum(`pending`,`ready`,`failed`) default `pending`, `attempts` INT, `processed_at` DATETIME, `error` |

`attempts` non è nella tabella della spec ma serve alla regola dei tre tentativi:
va aggiunto alla spec nello stesso commit.

**Interfacce prodotte:**

```php
ProductImages::for(array $images, ?int $variantId): list<array>;  // puro
ProductImages::fileName(array $image): string;
ProductImages::path(array $image): string;   // percorso sul disco
ProductImages::url(array $image): string;    // indirizzo pubblico
ProductImages::DIR = '/catalogo/prodotti/';
```

`for()` è la regola dell'ereditarietà: le immagini di quella variante; se non ne ha,
quelle senza variante (del modello). È pura perché la useranno vetrina e backend, e
devono rispondere uguale.

- [ ] **Passo 1: scrivere il test** — `tests/ProductImagesTest.php`: la tabella ha il
  prefisso e non si sincronizza; `status` è l'enum giusto con default `pending`;
  `for()` con una variante che ha immagini torna le sue; senza, torna quelle del
  modello; con `null` torna solo quelle del modello; l'ordine è `position`;
  `fileName()` legge il JSON dell'upload (`["foto.jpg"]`) e regge una stringa
  semplice; `path()` mette insieme radice, cartella e nome.
- [ ] **Passo 2: eseguirlo** — fallisce.
- [ ] **Passo 3: scrivere il Model e il supporto**, con il docblock che spiega perché
  il campo non dichiara `responsive()`.
- [ ] **Passo 4: rieseguire** — verde.
- [ ] **Passo 5: `php forge update`** e aggiornare la spec (colonna `attempts`).
- [ ] **Passo 6: commit.**

---

### Task 2: La coda

**File:**
- Creare: `src/Support/Catalog/ImageQueue.php`
- Test: `tests/ImageQueueTest.php`, `tests/integrazione/ImageQueueTest.php`

**Interfacce prodotte:**

```php
ImageQueue::MAX_ATTEMPTS = 3;
ImageQueue::pending(int $limit = 20): list<array>;
ImageQueue::work(int $limit = 20): array{done: int, failed: int, left: int};
ImageQueue::process(array $image): bool;
ImageQueue::nextStatus(int $attempts): string;   // puro: pending o failed
```

`work()` prende un blocco, per ciascuna riga chiama `imageResize()` del core con le
misure del sito e poi guarda `$ALERT` (il core non lancia eccezioni: scrive lì).
Va bene → `ready` e `processed_at`; va male → `attempts + 1`, `error`, e lo stato
che dice `nextStatus()`. Al terzo fallimento la riga resta `failed` e non riprova:
l'errore va in `error_reports` con `Errors::report()`.

- [ ] **Passo 1: scrivere il test puro** — `nextStatus(1)` e `nextStatus(2)` sono
  `pending`, `nextStatus(3)` è `failed`; il blocco predefinito è 20.
- [ ] **Passo 2: eseguirlo** — fallisce.
- [ ] **Passo 3: scrivere `ImageQueue`.**
- [ ] **Passo 4: rieseguire** — verde.
- [ ] **Passo 5: il test d'integrazione** — dentro `Transaction::run()` che annulla:
  una riga `pending` con un file finto che non esiste diventa `pending` con
  `attempts = 1` e un `error` scritto; ripetendo tre volte diventa `failed`;
  `work()` su una coda vuota non fa niente e dice `left: 0`.
- [ ] **Passo 6: commit.**

---

### Task 3: Il comando e l'attività

**File:**
- Creare: `src/Console/ImagesCommand.php`, `src/Scheduler/ImagesTask.php`
- Modificare: `src/Gestionale.php` (implementa `ModuleTasks`), `module.json`
- Test: `tests/CommandsTest.php` (si aggiunge), `tests/TasksTest.php`

`php forge gestionale:images` lavora **a blocchi** (predefinito 20, `--limit`), così
un cron ogni minuto non si accavalla con sé stesso, e stampa quante ne ha fatte e
quante ne restano.

`Scheduler\ImagesTask` fa la stessa cosa come attività del core: chiave
`gestionale.images`, ogni cinque minuti, spenta finché qualcuno non la accende.
`Gestionale::tasks()` la dichiara. Il core installato ha già `ModuleTasks` e lo
Scheduler: la nota della spec («quando arriva la 2.3.0») è superata.

- [ ] **Passo 1: scrivere i test** — il comando è dichiarato in `module.json`; la
  classe esiste ed estende il comando base del modulo; `ImagesTask::key()` è
  `gestionale.images`, `expression()` è valida, `enabled()` è falso,
  `Gestionale::tasks()` la contiene.
- [ ] **Passo 2: eseguirli** — falliscono.
- [ ] **Passo 3: scrivere comando e attività.**
- [ ] **Passo 4: rieseguire** — verdi.
- [ ] **Passo 5: provare dal sito** — `php forge gestionale:images` su una coda vuota
  dice che non c'è niente da fare.
- [ ] **Passo 6: commit.**

---

### Task 4: La galleria nella scheda

**File:**
- Modificare: `src/Resources/Catalog/ProductModelResource.php`
- Test: `tests/ProductModelResourceTest.php` (si aggiunge)

Un solo riquadro **"Immagini"**, come repeater collegato a `gst_product_images`:
immagine, testo alternativo e **la variante a cui appartiene** (vuoto = vale per
tutte). Niente repeater dentro repeater: la variante si sceglie da un select, e la
regola dell'ereditarietà la applica `ProductImages::for()`.

Ogni riga mostra il suo stato: "in lavorazione" finché è `pending`, l'errore se è
`failed`. La colonna non si scrive a mano.

- [ ] **Passo 1: scrivere il test** — il campo `images` esiste e la sua relazione
  punta a `gst_product_images` con `positionKey('position')`; una riga nuova nasce
  `pending` (`prepareRepeaterRelationRow`); il select della variante ha la voce
  "Tutte le varianti"; il campo **non** dichiara `responsive()`.
- [ ] **Passo 2: eseguirlo** — fallisce.
- [ ] **Passo 3: scrivere il riquadro.**
- [ ] **Passo 4: rieseguire** — verde.
- [ ] **Passo 5: provare nel browser** — caricare due immagini su un modello,
  salvare (deve essere immediato), vedere le righe `pending`, far girare
  `php forge gestionale:images` e ricaricare: sono `ready`.
- [ ] **Passo 6: commit.**

---

### Task 5: Dati di prova e guide

**File:**
- Modificare: `src/Seeding/CatalogDemo.php`, `tests/integrazione/CatalogDemoTest.php`,
  `docs/user/SUMMARY.md`, `docs/dev/concetti/catalogo.md`
- Creare: `docs/user/catalogo-modelli.md`, `docs/user/catalogo-immagini.md`

**I tre modelli di prova** (§8): uno semplice (nessuna variante), uno con due colori
e tre taglie, uno con molti prodotti. Le immagini finte sono file generati dal
comando stesso (un rettangolo colorato per modello), così `gestionale:demo` non
dipende da niente di esterno.

- [ ] **Passo 1: allargare il test d'integrazione** — `create()` fa tre modelli, le
  loro varianti e i loro prodotti; `clear()` li toglie tutti insieme al resto.
- [ ] **Passo 2: eseguirlo** — fallisce.
- [ ] **Passo 3: scrivere i dati di prova.**
- [ ] **Passo 4: rieseguire** — verde, e `php forge gestionale:demo --fresh` sul sito
  fa e disfa senza errori.
- [ ] **Passo 5: la guida del commerciante** — `catalogo-modelli.md` (creare un
  articolo semplice in un minuto, quando compaiono varianti e prodotti, il
  generatore delle combinazioni, SKU ed EAN) e `catalogo-immagini.md` (carica e
  salva subito, "in lavorazione" vuol dire che le misure stanno arrivando, cosa fare
  se una foto resta indietro). Aggiungere le due voci in `docs/user/SUMMARY.md` e
  collegare il pulsante "Guida" delle due pagine (`$docsPage`).
- [ ] **Passo 6: la guida dello sviluppatore** — in `docs/dev/concetti/catalogo.md`,
  sezione "Immagini": la tabella, la regola dell'ereditarietà, la coda con i tre
  tentativi, il comando e l'attività.
- [ ] **Passo 7: commit.**

---

## Chiusura

- [ ] `php tests/run.php` verde, test d'integrazione verdi.
- [ ] Ramo unito in `main` e spinto; CI verde sul commit unito.
- [ ] Sito di prova ripulito dalle righe di prova.
- [ ] `TODO.md`: spuntato il piano 4 e **chiuso G2a**.
