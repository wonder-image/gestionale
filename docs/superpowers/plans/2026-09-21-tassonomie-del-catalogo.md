# Piano 1 di 4 (G2a) — Marchi, categorie e tag

> **Per chi esegue:** SKILL RICHIESTA: usare superpowers:subagent-driven-development
> (consigliata) o superpowers:executing-plans, un task alla volta. I passi usano le
> caselle `- [ ]` per il tracciamento.

**Obiettivo:** le tre tassonomie del catalogo — marchi, categorie ad albero e tag —
con le loro pagine, pronte a essere collegate ai modelli nel piano 3.

**Architettura:** tre Model con le loro Resource, tutte sempre attive e senza
sincronizzazione (il catalogo è lavoro del commerciante, G2a.1). L'albero delle
categorie sta in una classe pura (`Catalog\CategoryTree`) che sa ordinare, indentare
e dire quali nodi non possono diventare padre di chi: il Model non fa ricorsione.

**Stack:** PHP 8.2, `wonder-image/app` `^2.2.12`, MySQL, test come script PHP con
l'harness del modulo.

**Spec:** `docs/superpowers/specs/2026-09-21-catalogo-design.md` (§2, §6, G2a.1, G2a.7).

## Vincoli globali

- **Lingua:** testi, commenti e messaggi in italiano; nomi in inglese nel codice.
- **Tabelle:** prefisso `gst_`, `syncSchema(): null` (il catalogo non si sincronizza).
- **Codici:** `code` con `uniqueCode()` e i prefissi di `Support\Codes` (`bra_`,
  `cat_`, `tag_`); `slug` generato dal nome alla creazione e poi fisso.
- **Niente eliminazione quando si è usati** (G2a.7): `assertDeletable()` spiega e
  propone di nascondere.
- **Menu:** sezione `catalogo` del backend, per `admin` e `administrator`.
- **Test d'integrazione:** database `ecommerce_site` dentro `Transaction::run()` che
  annulla sempre.
- **Ramo:** `feature/tassonomie` in `packages/gestionale`.
- **Commit:** uno per task, messaggio in inglese, con la riga
  `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`.

## Struttura dei file

| File | Responsabilità |
|---|---|
| `src/Models/Catalog/Brand.php` | tabella `gst_brands` |
| `src/Models/Catalog/Category.php` | tabella `gst_categories` |
| `src/Models/Catalog/Tag.php` | tabella `gst_tags` |
| `src/Support/Catalog/CategoryTree.php` | ordinamento, indentazione, cicli |
| `src/Resources/Catalog/BrandResource.php` | pagina "Marchi" |
| `src/Resources/Catalog/CategoryResource.php` | pagina "Categorie" |
| `src/Resources/Catalog/TagResource.php` | pagina "Tag" |

---

### Task 1: I tre Model

**File:**
- Creare: `src/Models/Catalog/Brand.php`, `src/Models/Catalog/Category.php`,
  `src/Models/Catalog/Tag.php`
- Test: `tests/CatalogModelsTest.php`

**Colonne**

| Tabella | Colonne |
|---|---|
| `gst_brands` | `code` (`bra_`), `name`, `slug` (unico), `logo` (immagine), `description` TEXT, `position` INT, `visible` enum |
| `gst_categories` | `code` (`cat_`), `parent_id` INT (chiave verso sé stessa), `name`, `slug` (unico), `image`, `description` TEXT, `position` INT, `visible` enum |
| `gst_tags` | `code` (`tag_`), `name`, `slug` (unico), `image`, `visible` enum |

- [ ] **Passo 1: scrivere il test** — `tests/CatalogModelsTest.php`: i nomi delle
  tabelle hanno il prefisso `gst_`; nessuna delle tre si sincronizza
  (`syncSchema() === null`); `code` usa il prefisso giusto e non si modifica
  (`immutable_on_update`); `slug` è unico e immutabile; `categories.parent_id` punta
  a `gst_categories`.
- [ ] **Passo 2: eseguirlo** — fallisce con "Class not found".
- [ ] **Passo 3: scrivere i tre Model**, con il docblock che dice perché non si
  sincronizzano.
- [ ] **Passo 4: rieseguire** — verde.
- [ ] **Passo 5: `php forge update`** dal sito di prova: le tre tabelle nascono.
- [ ] **Passo 6: commit.**

---

### Task 2: L'albero delle categorie

**File:**
- Creare: `src/Support/Catalog/CategoryTree.php`
- Test: `tests/CategoryTreeTest.php`

**Interfacce:**
- `CategoryTree::sorted(array $rows): list<array>` — righe in ordine di albero, con
  in più `depth` e `path` (i nomi dei padri separati da ` › `).
- `CategoryTree::descendants(array $rows, int $id): list<int>` — id di tutti i figli,
  a qualunque profondità.
- `CategoryTree::options(array $rows, ?int $exclude = null): array<string, string>` —
  le voci per il select del padre, con l'indentazione, senza il nodo escluso e senza
  i suoi discendenti.
- `CategoryTree::wouldLoop(array $rows, int $id, int $parentId): bool`

- [ ] **Passo 1: scrivere il test** con i casi: due radici e un figlio danno l'ordine
  radice, figlio, radice; `depth` vale 0 e 1; `path` del figlio contiene il padre;
  `descendants()` prende anche i nipoti; `options()` esclude il nodo e i suoi
  discendenti; `wouldLoop()` è vero mettendo un nodo sotto sé stesso e sotto un suo
  nipote, falso altrimenti; una riga con un `parent_id` che non esiste viene trattata
  come radice invece di sparire.
- [ ] **Passo 2: eseguirlo** — fallisce.
- [ ] **Passo 3: scrivere la classe**, pura e senza database.
- [ ] **Passo 4: rieseguire** — verde.
- [ ] **Passo 5: commit.**

---

### Task 3: Le tre pagine

**File:**
- Creare: `src/Resources/Catalog/BrandResource.php`,
  `src/Resources/Catalog/CategoryResource.php`, `src/Resources/Catalog/TagResource.php`
- Modificare: `tests/ConventionsTest.php` (le pagine del catalogo sono sempre attive)
- Test: `tests/CatalogResourcesTest.php`

**Regole comuni:** estendono `GestionaleResource`; percorsi `app/gestionale/marchi`,
`app/gestionale/categorie`, `app/gestionale/tag`; sezione `catalogo` (la registra la
prima, come fa il core con `set-up`); `admin` e `administrator`; slug generato in
`mutateRequestValues()` alla creazione; `visible` come badge nell'elenco.

**Categorie in più:** il select del padre usa `CategoryTree::options()` senza sé
stessa; salvando si rifiuta un ciclo con un `UserError`; l'elenco mostra il percorso
(`path`) invece del solo nome, con un formatter.

- [ ] **Passo 1: scrivere il test** — percorsi, sezione, permessi; il campo `slug` non
  è nel form (si genera); `assertDeletable()` di un marchio usato lancia con un
  messaggio che parla di nascondere; il select del padre non contiene la categoria
  stessa; salvando un ciclo si ottiene un errore.
- [ ] **Passo 2: eseguirlo** — fallisce.
- [ ] **Passo 3: scrivere le tre Resource.**
- [ ] **Passo 4: rieseguire** — verde; poi `php tests/run.php`.
- [ ] **Passo 5: guardare le tre pagine nel browser** sul sito di prova: creare un
  marchio, due categorie annidate e un tag; provare a mettere una categoria sotto sé
  stessa e vedere il rifiuto.
- [ ] **Passo 6: commit.**

---

### Task 4: Righe di prova e documentazione

**File:**
- Modificare: `src/Console/Demo/DemoData.php` (registrazione), `docs/dev/…`, `docs/user/…`
- Test: `tests/integrazione/CatalogDemoTest.php`

- [ ] **Passo 1: test d'integrazione** — dentro la transazione, la voce del registro
  crea un marchio, tre categorie (due radici e una figlia) e due tag; la funzione di
  pulizia le toglie; una seconda esecuzione non duplica.
- [ ] **Passo 2: eseguirlo** — fallisce.
- [ ] **Passo 3: registrare i dati di prova** in `DemoData` dal modulo, con chiave
  `catalogo-tassonomie`.
- [ ] **Passo 4: provare `php forge gestionale:demo`** dal sito di prova, poi
  `--fresh`.
- [ ] **Passo 5: documentazione** — guida sviluppatori: una pagina "Catalogo" con i
  tre livelli e le tassonomie (per ora tassonomie); guida commercianti: "Marchi,
  categorie e tag" con il passo passo.
- [ ] **Passo 6: commit, merge del ramo in `main`, push.**

## Verifica finale del piano

1. `php tests/run.php` verde; CI verde.
2. `php forge update` crea le tre tabelle; la seconda esecuzione non cambia niente.
3. Le tre pagine funzionano nel browser, con il toast al salvataggio.
4. Un ciclo tra categorie viene rifiutato con un messaggio comprensibile.
5. `gestionale:demo` riempie e `--fresh` ripulisce.
