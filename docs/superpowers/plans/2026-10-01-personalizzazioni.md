# Personalizzazioni — piano di realizzazione (G5, piano 1)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** campi compilati al momento della vendita (testo o scelta, con sovrapprezzo) definiti in *Catalogo → Personalizzazioni*, collegati agli articoli, controllati e prezzati dal server in `Cart`, e mostrati sotto il nome della riga in scheda dell'ordine, email e reso.

**Architecture:** tre tabelle nuove (personalizzazioni, opzioni, collegamento all'articolo); una classe `Support\Catalog\Customizations` con la parte pura (controllo dei valori, sovrapprezzo, codifica per la connessione latin1, firma, righe da mostrare) separata dalla lettura dal database (`forModel`); `Cart` che chiama `resolve()` in `add()` e in `recalculate()`, e scrive la riga con `encode()`. Una Resource ricalcata su `AttributeResource` e un riquadro nella scheda dell'articolo col repeater del core e il `quickCreate()`. La vista legge sempre da `Customizations::lines()`.

**Tech Stack:** PHP 8.2+, harness `tests/harness.php` (`php tests/run.php`), sito di prova `boilerplates/ecommerce-site` (tabelle con `php forge update --local`), helper `tests/integrazione/supporto/compra.php`.

**Spec:** `docs/superpowers/specs/2026-10-01-multiprodotto-e-personalizzazione-design.md` (§1 *Personalizzazioni* e *Righe d'ordine*, §2 *Catalogo → Personalizzazioni* e *Riquadro «Personalizzazioni»*, §3 `Customizations` e `Cart`, §4 *Resi*, *Dati di prova*, *Test*, *Piani* punto 1). Il multiprodotto è del piano 2.

## Global Constraints

- Lingua: commenti, testi utente e nomi dei test in **italiano** con gli accenti; classi, tabelle, colonne e chiavi in inglese.
- Funzionalità `customizations` (già in `config/features.php`, richiede `orders`): spenta, pagina, voce di menu, riquadro nell'articolo e API spariscono; **i dati restano** (D20).
- Solo due tipi: `text` e `choice` (P106). `max_length` solo per `text`, fra 1 e 1000; una `choice` ha almeno due opzioni.
- Sovrapprezzo di una scelta = sovrapprezzo della personalizzazione **più** quello dell'opzione. Il `customization_surcharge` che arriva a `Cart::add()` **non si legge più**: lo calcola il server.
- `gst_order_items.customization` è una lista JSON di `{customization_id, label, value, option_id, surcharge}`; `option_id` 0 per un testo, `value` = etichetta dell'opzione per una scelta. Si scrive **solo** con `Customizations::encode()` e si legge **solo** con `Customizations::decode()`.
- Rifiuti prevedibili: `UserError::make('customization.<chiave>', [...])`, frase in `lang/it/gestionale.json` sotto `gestionale.errors.customization` (il JSON si riscrive con python `json.dumps(ensure_ascii=False, indent=4) + "\n"`); `ErrorKeysTest` cade se ne manca una. Gli errori di un campo portano l'id della personalizzazione con `->withField($id)`.
- Form compatti: tooltip al posto dei testi d'aiuto, campi correlati sulla stessa riga, niente campi automatici.
- Tutto ciò che viene dal database o dal cliente e finisce in HTML passa da `escape()` (Resource) o `htmlspecialchars` (`$e` delle email).
- Test d'integrazione dentro `prova()` (transazione annullata) e con `Gestionale::reset()` dopo aver toccato le funzionalità; ogni task chiude con `php tests/run.php` verde per intero (il rosso già noto di `CatalogDemoTest` «il sito resta con foto vere sul disco» si riporta, non si nasconde).
- Branch `piano-1-personalizzazioni`; commit con trailer `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`; PR con merge commit su `main`, poi pulizia di branch e worktree.

## Decisioni di perimetro

- **Connessione latin1.** Il sito parla latin1 con MySQL: un'emoji o una lettera fuori da latin1 scritta così com'è torna come `?`. `encode()` converte ogni carattere oltre `0x7F` in entità numerica (`&#128512;`), dopo aver scritto `&` come `&amp;`, e `decode()` fa `html_entity_decode(…, ENT_QUOTES | ENT_HTML5, 'UTF-8')`. Così il testo torna identico, `&` compreso. Le etichette in `gst_customizations` e `gst_customization_options` passano dal `sanitize` del core, che le salva già con le entità: `forModel()` le decodifica.
- **Repeater senza cancellazione logica.** Opzioni e collegamenti all'articolo usano `->softDelete(false)`: una riga tolta dal form si cancella davvero. Nessuno le referenzia con una chiave esterna (la riga d'ordine copia etichette e id nel JSON), e una riga «cancellata» rimasta in tabella bloccherebbe il `DELETE` della personalizzazione. Le letture filtrano comunque `deleted = 'false'`.
- **Funzionalità spenta, righe già nel carrello.** `recalculate()` le lascia come sono, con il loro sovrapprezzo: spegnere la funzionalità non cambia in silenzio i carrelli. `add()` ignora i valori e rifiuta (`customization.unavailable`) un articolo che ne ha di obbligatorie.
- **Ricalcolo di tutte le righe prodotto.** Con la funzionalità accesa `recalculate()` rilegge le personalizzazioni di **ogni** riga prodotto, anche di quelle che non ne hanno: un'obbligatoria aggiunta all'articolo dopo, una disattivata, un'opzione tolta o un id che non è più dell'articolo fanno uscire la riga, con il nome in `removed`.
- **Eliminare.** Una personalizzazione collegata ad almeno un articolo non si elimina (`customization.in_use` con il numero); una mai usata si elimina con le sue opzioni. Le righe d'ordine non contano: hanno la loro copia.
- **Quick-create.** «Nuova personalizzazione…» dalla scheda dell'articolo crea solo un testo da 100 caratteri, senza sovrapprezzo, attivo, con etichetta = nome; il resto si rifinisce dalla sua pagina.
- **Reso online.** `ReturnRules::onlineReturnable()` dice di no a una riga con personalizzazioni (art. 59 Codice del Consumo); il backend continua a registrarle sempre.

## Review Focus

- **Testo esotico attraverso latin1.** «Café ☕ 😀», `ß`, un byte UTF-8 rotto, `\r\n`, una tabulazione: si salva e si rilegge identico (il rotto ripulito, l'a capo come `\n`, gli altri controlli tolti), e la lunghezza si conta in caratteri. *(Task 2 e Task 5)*
- **`&`, entità e HTML nei valori.** `A & B`, `&amp;`, `&#128512;`, `<b>x</b>` scritti dal cliente tornano letterali da `decode()` e arrivano in pagina ed email come testo, non come markup. *(Task 2 e Task 6)*
- **La stessa personalizzazione due volte.** Valori uguali con chiavi in ordine diverso, id come stringa o come intero, una facoltativa vuota in più: una riga sola nel carrello, con la quantità sommata; valori diversi fanno due righe. *(Task 5)*
- **L'anagrafica cambia sotto il carrello.** Obbligatoria aggiunta, personalizzazione disattivata o scollegata, opzione tolta, sovrapprezzo cambiato: la riga esce (con il nome in `removed`) o si riprezza; con la funzionalità spenta la riga resta com'era. *(Task 5)*
- **Il riquadro dell'articolo.** Due righe con la stessa personalizzazione, una riga vuota, l'ultima riga tolta, una personalizzazione disattivata già collegata: un collegamento solo, nessuno vuoto, togliere tutto toglie davvero, la disattivata resta scelta e marcata «(disattivata)». *(Task 4)*
- **Eliminazioni.** Una personalizzazione in uso non si elimina (e lo dice con il numero); un articolo eliminato porta via i suoi collegamenti senza errori di chiave. *(Task 3 e Task 4)*

---

### Task 1: Tabelle e prefisso del codice

**Files:**
- Create: `src/Models/Catalog/Customization.php`, `src/Models/Catalog/CustomizationOption.php`, `src/Models/Catalog/ProductModelCustomization.php`, `tests/CustomizationModelsTest.php`
- Modify: `src/Support/Codes.php` (`CUSTOMIZATION = 'cus_'` dopo `PACKAGE`)

**Interfaces:**
- Produces: `Customization::$table = 'gst_customizations'` (`$folder = 'gestionale/customizations'`), `CustomizationOption::$table = 'gst_customization_options'`, `ProductModelCustomization::$table = 'gst_product_model_customizations'` (`$folder = 'gestionale/models'`); `Codes::CUSTOMIZATION`.

Modelli ricalcati su `Attribute`, `AttributeValue` e `ProductSupplier` (stesse firme di `tableSchema()`, `tablePseudos()`, `dataSchema()`, `syncSchema()` che torna `null`):

| Model | Colonne (`tableSchema`) | `dataSchema` |
|---|---|---|
| `Customization` | `...sqlColumnsFromDataSchema(['code'])`, `name` VARCHAR, `label` VARCHAR, `help_text` TEXT, `kind` enum `text\|choice` default `text`, `max_length` int default 0, `surcharge` `Columns::decimal('surcharge', '12,2')`, `active` enum `true\|false` default `true`, `position` int | `code` `->uniqueCode(Codes::CUSTOMIZATION)`, `name` `->sanitizeFirst()`, `label`, `help_text`, `kind` e `active` con `->sanitize(false)`, `max_length` `->number()`, `surcharge` `->number()->decimals(2)`, `position` |
| `CustomizationOption` | `customization_id` int not null `->foreign(Customization::$table)`, `label`, `surcharge` decimal(12,2), `position`; indice `ind_customization` | `label` `->sanitizeFirst()`, `surcharge`, `position` |
| `ProductModelCustomization` | `product_model_id` `->foreign(ProductModel::$table)`, `customization_id` `->foreign(Customization::$table)`, `is_required` enum `true\|false` default `false`, `position`; indici `ind_model`, `ind_customization` | `is_required` `->sanitize(false)`, `position` |

- [ ] **Step 1: scrivere `tests/CustomizationModelsTest.php`** copiando le chiusure `$colonne`/`$campo` e il controllo delle parole riservate da `tests/CatalogAttributeModelsTest.php`, con `check()` per: tabelle e cartelle come sopra; colonne di ognuno; `kind` con le due voci e default `text`; `active` default `true`; `is_required` default `false`; `surcharge` decimale `12,2` su personalizzazione e opzione; chiavi esterne verso `gst_customizations` e `gst_product_models`; indici; `code` con prefisso `cus_`; nessuna colonna con nome riservato.
- [ ] **Step 2: eseguire** `php tests/CustomizationModelsTest.php` — atteso: errore «class not found».
- [ ] **Step 3: scrivere i tre Model e la costante.**
- [ ] **Step 4: eseguire** il test e `php tests/CodesTest.php` (prefissi unici e nel formato `^[a-z]{3}_$`) — atteso: verdi. Creare le tabelle: `cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php forge update --local` — atteso: le tre tabelle create senza errori. Poi `php tests/run.php` verde.
- [ ] **Step 5: commit** «Personalizzazioni: tabelle, opzioni e collegamento all'articolo».

### Task 2: `Customizations`, l'id del campo negli errori, le frasi

**Files:**
- Create: `src/Support/Catalog/Customizations.php`, `tests/CustomizationsTest.php` (puro), `tests/integrazione/CustomizationsTest.php`
- Modify: `src/Support/Errors/UserError.php` (`withField`, `field`), `lang/it/gestionale.json` (gruppo `customization`), `tests/integrazione/supporto/compra.php` (helper)

**Interfaces:**
- Consumes: i Model del Task 1.
- Produces:
  - `UserError::withField(int $id): self` e `UserError::field(): int` (0 se non impostato).
  - `Customizations::forModel(int $modelId): list<array{id:int, name:string, label:string, help_text:string, kind:string, max_length:int, surcharge:string, required:bool, options:list<array{id:int, label:string, surcharge:string}>}>` — solo personalizzazioni attive e collegamenti non cancellati, nell'ordine di `position` del collegamento; etichette decodificate.
  - `Customizations::check(array $definitions, array $values): array{fields: list<array{customization_id:int, label:string, value:string, option_id:int, surcharge:string}>, surcharge: string}` — pura.
  - `Customizations::resolve(int $modelId, array $values): array` = `check(forModel($modelId), $values)`.
  - `Customizations::assertDefinition(array $values, array $optionRows): void` — pura, per il form.
  - `Customizations::encode(array $fields): string`, `decode(mixed $stored): list<…>`, `signature(array $fields): string`, `valuesOf(array $fields): array<int, int|string>`, `lines(array $item): list<string>`.
  - Helper di test in `compra.php`: `personalizzazioneDiProva(array $valori = [], array $opzioni = []): int` (crea una `Customization` attiva, testo da 20 per default; `$opzioni` = `[['label'=>…, 'surcharge'=>…], …]`), `collegaPersonalizzazione(int $modello, int $personalizzazione, bool $obbligatoria = false, int $posizione = 1): int`, `modelloDi(int $prodotto): int`, `spegniFunzionalita(array $chiavi): void` (come `accendiFunzionalita`, con `'enabled' => 'false'`).
  - Chiavi d'errore `customization.*`: `required`, `too_long` (`{{max}}`), `bad_option`, `unknown`, `unavailable` (`{{name}}`), `max_length`, `few_options`, `surcharge`, `kind`, `in_use` (`{{count}}`), `quick_name`.

Il cuore puro, da scrivere così (le parti ovvie si completano nello stesso stile):

```php
/** Ogni carattere oltre l'ASCII come entità: la connessione è latin1. */
public static function encode(array $fields): string
{
    if ($fields === []) {
        return '';
    }

    $safe = static fn (string $s): string => mb_encode_numericentity(
        str_replace('&', '&amp;', $s),
        [0x80, 0x10FFFF, 0, 0x1FFFFF],
        'UTF-8'
    );

    return (string) json_encode(array_map(static fn (array $f): array => [
        'customization_id' => (int) $f['customization_id'],
        'label' => $safe((string) $f['label']),
        'value' => $safe((string) $f['value']),
        'option_id' => (int) ($f['option_id'] ?? 0),
        'surcharge' => (string) $f['surcharge'],
    ], array_values($fields)));
}

/** Una stringa salvata si decodifica; una lista arriva già letta e si normalizza soltanto. */
public static function decode(mixed $stored): array
{
    if (is_string($stored)) {
        $list = json_decode($stored, true);
        $plain = static fn (mixed $s): string => html_entity_decode((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    } else {
        $list = $stored;
        $plain = static fn (mixed $s): string => (string) $s;
    }
    // … per ogni elemento array con customization_id > 0: i cinque campi, label e value passati da $plain.
}

/** Uguale per gli stessi valori, qualunque sia l'ordine o il tipo degli id. */
public static function signature(array $fields): string
{
    $parts = array_map(static fn (array $f): array => [
        (int) $f['customization_id'], (int) ($f['option_id'] ?? 0), (string) $f['value'],
    ], $fields);
    usort($parts, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

    return $parts === [] ? '' : (string) json_encode($parts);
}
```

Regole di `check()`, nell'ordine: un id di `$values` che non è fra le definizioni → `unknown` (con `withField` di quell'id); per ogni definizione, il valore (`$values[$id]`, chiave intera o stringa) — **testo**: `mb_scrub`, `\r\n` → `\n`, via i caratteri di controllo tranne l'a capo (`/(?!\n)\p{Cc}/u`), `trim`; oltre `max_length` caratteri (`mb_strlen`) → `too_long` con `{{max}}`; **scelta**: `(int)` del valore fra gli id delle opzioni di quella definizione, altrimenti `bad_option`; vuoto e obbligatorio → `required`; vuoto e facoltativo → non entra. Sovrapprezzo del campo = `surcharge` della definizione + quello dell'opzione; il totale è una stringa a due decimali (`number_format(…, 2, '.', '')`). `value` di una scelta è l'etichetta dell'opzione.

`assertDefinition($values, $optionRows)`: `kind` fuori da `text|choice` → `kind`; testo con `max_length` non intero fra 1 e 1000 → `max_length`; sovrapprezzo negativo o non numerico (della personalizzazione o di un'opzione) → `surcharge`; scelta con meno di due opzioni dall'etichetta non vuota → `few_options`.

`lines($item)`: `decode($item['customization'] ?? '')` → `«{label}: {value}»` per ogni campo; niente HTML (lo escapa chi stampa).

- [ ] **Step 1: scrivere le frasi** in `gestionale.json` sotto `gestionale.errors.customization`, rivolte a chi compra o a chi usa il backend secondo la chiave (es. `required`: «Compila questo campo.», `too_long`: «Al massimo {{max}} caratteri.», `bad_option`: «Scegli una delle opzioni proposte.», `unknown`: «Questo campo non appartiene all'articolo.», `unavailable`: ««{{name}}» chiede una personalizzazione che al momento non si può scegliere.», `max_length`: «Scrivi quanti caratteri si possono usare, da 1 a 1000.», `few_options`: «Una scelta ha bisogno di almeno due opzioni.», `surcharge`: «Il sovrapprezzo non può essere negativo.», `kind`: «Scegli se è un testo o una scelta.», `in_use`: «È usata da {{count}} articoli: disattivala invece di eliminarla.», `quick_name`: «Scrivi il nome della personalizzazione.»).
- [ ] **Step 2: scrivere `tests/CustomizationsTest.php`** (puro, definizioni scritte a mano), con `check()` per: testo valido con sovrapprezzo `5.00`; `max_length` 5 con «àèìòù» passa e «àèìòùx» → `too_long` con `field()` uguale all'id; testo con spazi, `\r\n` e una tabulazione ripulito; byte rotto `"ab\xC3"` non fa esplodere niente; obbligatoria vuota (anche solo spazi) → `required`; facoltativa vuota non entra e non costa; scelta con l'id dell'opzione (come intero e come stringa) → valore = etichetta, sovrapprezzo 3 + 2 = `5.00`; opzione di un'altra personalizzazione → `bad_option`; id estraneo → `unknown`; totale di due campi; `encode`/`decode` andata e ritorno identica per «Café ☕ 😀», `A & B`, `&amp;`, `&#128512;`, `<b>x</b>`, `"virgolette"`; `encode([])` = `''`; `decode('')`, `decode('non json')`, `decode(null)` = `[]`; `decode` di una lista lascia i valori come sono (`&amp;` resta `&amp;`); l'output di `encode` è solo ASCII (`mb_check_encoding($s, 'ASCII')`); `signature` uguale con campi in ordine diverso e id stringa/intero, diversa con un valore diverso, `''` per nessun campo; `valuesOf` ridà `id => option_id` per la scelta e `id => testo` per il testo; `lines` ridà «Incisione: Marco»; `assertDefinition` per ognuna delle sue quattro chiavi e per una definizione valida di ciascun tipo; `UserError::make('x')->field()` = 0 e `->withField(7)->field()` = 7.
- [ ] **Step 3: eseguire** `php tests/CustomizationsTest.php` — atteso: errore «class not found».
- [ ] **Step 4: scrivere `withField`/`field` e la parte pura di `Customizations`.** Eseguire il test — atteso: verde.
- [ ] **Step 5: scrivere gli helper in `compra.php` e `tests/integrazione/CustomizationsTest.php`** (intestazione e `prova()` come `CartTest.php`), con `check()` per: `forModel` ridà le collegate attive nell'ordine del collegamento, con `required`, opzioni in ordine e sovrapprezzi; una disattivata non c'è; un collegamento con `deleted = 'true'` non c'è; un'etichetta salvata con `&` e `è` (passata dal `sanitize`) torna leggibile; `resolve` con una disattivata fra i valori → `unknown`; articolo senza collegamenti → `[]`.
- [ ] **Step 6: scrivere `forModel` e `resolve`.** Eseguire i due test, `php tests/ErrorKeysTest.php` e `php tests/run.php` — atteso: verdi. (`ErrorKeysTest` controlla che ogni chiave scritta nel codice abbia la sua frase, non il contrario: le frasi di `in_use`, `quick_name` e `unavailable` possono arrivare qui anche se il codice che le usa è dei Task 3 e 5.)
- [ ] **Step 7: commit** «Customizations: controllo dei valori, sovrapprezzo e codifica per latin1».

### Task 3: Pagina *Catalogo → Personalizzazioni*

**Files:**
- Create: `src/Resources/Catalog/CustomizationResource.php`, `tests/CustomizationResourceTest.php`, `tests/integrazione/CustomizationResourceTest.php`, `docs/user/catalogo-personalizzazioni.md`
- Modify: `docs/user/SUMMARY.md` (voce dopo *Attributi*)

**Interfaces:**
- Consumes: i Model (Task 1), `Customizations::assertDefinition` e le chiavi d'errore (Task 2), `Repeater::rowsFromRequest('options', $_POST)` del core.
- Produces: `CustomizationResource` (`$feature = 'customizations'`, `$model = Customization::class`, `$orderColumn = 'position'`, `$docsPage = 'catalogo/catalogo-personalizzazioni'`, path `app/gestionale/personalizzazioni`, voce in `catalogo` con `order(44)`), `CustomizationResource::usage(int $id): int` (articoli che la usano), `CustomizationResource::quickCreateValues(array $values): array`.

Ricalcata su `AttributeResource` (stessa forma di `formSchema`, `formLayoutSchema` con Card e `SectionTitle::make()->tooltip()`, `tableSchema` con i formatter, `pageSchema` con `disable(['view'])`, `permissionSchema` `backendCrud(['admin', 'administrator'])`, `apiSchema` `only(['store'])->fields(['name'])`, `mutateRequestValues`, `quickCreateFields`, `syncRepeaterRelations` che in `api` torna `[]`, `assertDeletable`). Disegno del form:

- riga 1: *Nome interno* (6) · *Etichetta per il cliente* (6);
- riga 2: *Testo d'aiuto* (12);
- riga 3: *Tipo* (3, `text` = «Testo», `choice` = «Scelta») · *Caratteri massimi* (3, `visibleWhen('kind', 'text')`) · *Sovrapprezzo* (3) · *Stato* (3, «Attiva»/«Disattivata»);
- Card *Opzioni* con `visibleWhen('kind', 'choice')`: repeater `options` [id nascosto · *Etichetta* · *Sovrapprezzo*] con `RepeaterRelation::make(CustomizationOption::$table, 'customization_id')->model(CustomizationOption::class)->positionKey('position')->softDelete(false)`, `->nested()->repeaterSortable()`, `->label('')`;
- le spiegazioni (il sovrapprezzo della scelta si somma a quello dell'opzione; una disattivata esce dai carrelli) nel tooltip dei `SectionTitle`.

Elenco: nome, tipo, sovrapprezzo, numero di articoli (`usage`), stato come badge. `mutateRequestValues` (backend): `assertDefinition($values, Repeater::rowsFromRequest('options', $_POST))`; con `kind = text` svuota le opzioni postate (`$_POST['options'] = []` prima del sync) così un cambio di tipo non lascia opzioni appese; in `store` la posizione con `Positions::next`; in `api` + `store` i valori di `quickCreateValues` (testo, `max_length` 100, `surcharge` 0, `active` true, `label` = nome; nome vuoto → `quick_name`). `assertDeletable`: `usage > 0` → `UserError::refusal('customization.in_use', ['count' => n])`. `deleteRecord`: in `Transaction::run`, `DELETE` delle opzioni e poi `Model::delete` della personalizzazione.

- [ ] **Step 1: test unitari** (`CustomizationResourceTest`, senza database come `AttributeValueResourceTest`): `$feature`, path, voce di menu e ordine; il form ha i campi nell'ordine e con le larghezze sopra, `max_length` visibile solo con `text` e le opzioni solo con `choice`; il repeater delle opzioni ha `softDelete` falso e `positionKey` `position`; l'API accetta solo `store` e solo `name`; `quickCreateValues(['name' => 'Incisione'])` dà testo, 100, `0.00`, attiva, etichetta «Incisione»; `quickCreateValues(['name' => ' '])` → `quick_name`; il formatter dello stato e del tipo non stampa HTML preso dal nome (`<b>x</b>` escapato).
- [ ] **Step 2: test d'integrazione** (`tests/integrazione/CustomizationResourceTest.php`, modello in `AttributeQuickCreateTest.php` ed `EliminazioniTest.php`): salvare una scelta con due opzioni scrive le opzioni nell'ordine; salvare di nuovo con una sola riga cancella davvero l'altra (nessuna riga `deleted = 'true'` rimasta); una scelta con un'opzione sola → `few_options` e niente scritto; un testo con `max_length` 0 o 1001 → `max_length`; sovrapprezzo `-1` → `surcharge`; passare da scelta a testo toglie le opzioni; eliminare una personalizzazione collegata a un articolo → `in_use` con «1»; eliminarne una mai usata con opzioni → sparisce con le opzioni, senza errori di chiave; lo store API con `options[...]` nella richiesta non crea opzioni.
- [ ] **Step 3: eseguire** i due test — atteso: rossi (classe mancante).
- [ ] **Step 4: scrivere `CustomizationResource`.**
- [ ] **Step 5: scrivere la guida** `docs/user/catalogo-personalizzazioni.md` (cosa sono, testo e scelta, sovrapprezzo sommato, attiva/disattivata e cosa succede ai carrelli, perché una in uso non si elimina, come si collegano a un articolo — rimando alla scheda dell'articolo, dove il cliente le vedrà — E1b) e la voce in `docs/user/SUMMARY.md`.
- [ ] **Step 6: eseguire** i test, `php tests/DocsPagesTest.php`, `php tests/ErrorKeysTest.php` e `php tests/run.php` — atteso: verdi.
- [ ] **Step 7: commit** «Catalogo → Personalizzazioni: elenco, form compatto con le opzioni, eliminazione protetta».

### Task 4: Il riquadro «Personalizzazioni» nella scheda dell'articolo

**Files:**
- Modify: `src/Resources/Catalog/ProductModelResource.php` (`formSchema` a riga ~199, le card a riga ~476, `prepareRepeaterRows` a riga ~2247, `deleteRecord` a riga ~3124, un metodo `customizationsCard()` accanto a `technicalSheetCard()` a riga ~4796), `tests/ProductModelResourceTest.php`
- Create: `tests/integrazione/ProductModelCustomizationsTest.php`

**Interfaces:**
- Consumes: `ProductModelCustomization`, `Customization` (Task 1), `CustomizationResource` (Task 3).
- Produces: `ProductModelResource::customizationOptions(int $modelId): array<string, string>` (`'' => '—'` + attive per nome + le disattivate già collegate a quell'articolo con « (disattivata)»); la costante `CUSTOMIZATION_BUTTON = 'wi-customization-new'`.

Solo con `Gestionale::feature('customizations')` acceso (come il ramo `purchasing` già nel file), in `formSchema`:

```php
FormField::key('customizations')
    ->repeater([
        RepeaterColumn::key('id')->hidden(),
        RepeaterColumn::key('customization_id')->select(static::customizationOptions((int) ($modelId ?? 0)))->label('Personalizzazione')->columnFill(),
        RepeaterColumn::key('is_required')->select(['false' => 'No', 'true' => 'Sì'])->label('Obbligatoria')->columnSpan(3),
    ])
    ->relation(
        RepeaterRelation::make(ProductModelCustomization::$table, 'product_model_id')
            ->model(ProductModelCustomization::class)
            ->positionKey('position')
            ->softDelete(false)
    )
    ->nested()
    ->repeaterSortable()
    ->repeaterStartEmpty()
    ->repeaterAddLabel('Aggiungi personalizzazione')
    ->label(''),
```

`customizationsCard()`: `static::foldable('Personalizzazioni', [static::getInput('customizations'), QuickCreateButton::make(CustomizationResource::class)->text('Nuova personalizzazione')->label('name')->size('sm')->id(static::CUSTOMIZATION_BUTTON)->columnSpan(12), static::customizationScript()->columnSpan(12)], 'Campi che il cliente compila comprando questo articolo, per tutte le sue varianti. Il sovrapprezzo si somma al prezzo.')`, aggiunta subito dopo `$cards[] = static::technicalSheetCard();` solo con la funzionalità accesa. Lo script (`RichText` con `<script>`, come `technicalScript`) ascolta `wi:quick-create:created` con `detail.family === 'button'` e `detail.resource` uguale alla classe di `CustomizationResource`, e aggiunge `<option value="{id}">{name}</option>` (testo con `textContent`, non `innerHTML`) a ogni select `customization_id` del riquadro **e** al contenuto del `<template>` del repeater, poi la seleziona nell'ultima riga vuota se c'è.

`prepareRepeaterRows`: un ramo per `$inputName === 'customizations'` che scarta le righe senza `customization_id` e quelle con un id già visto (resta la prima). `deleteRecord`: dentro la `Transaction::run` esistente, `DELETE` dei collegamenti dell'articolo prima dei prodotti.

- [ ] **Step 1: test unitari** in `ProductModelResourceTest.php` (con l'helper `$forza` che scrive `Gestionale::$features` per riflessione): funzionalità spenta → nessun campo `customizations` e nessuna card «Personalizzazioni»; accesa → il campo c'è, la relazione ha `softDelete` falso e `positionKey`, la card viene dopo «Scheda tecnica», lo script cita la Resource giusta e usa `textContent`; `prepareRepeaterRows('customizations', …)` con righe `[3, '', 3, 5]` ridà `[3, 5]`; le altre righe passano invariate.
- [ ] **Step 2: test d'integrazione** (`ProductModelCustomizationsTest`, funzionalità accesa con `accendiFunzionalita(['orders', 'customizations'])`): salvare l'articolo con due righe scrive due collegamenti nell'ordine e con l'obbligo; salvarlo con una riga sola cancella davvero l'altra; salvarlo senza righe li toglie tutti; con la funzionalità spenta salvare l'articolo **non** tocca i collegamenti; `customizationOptions` include una disattivata collegata a quell'articolo con «(disattivata)» e la esclude per un altro articolo; eliminare l'articolo toglie i collegamenti senza errori di chiave. Per salvare usare lo stesso percorso dei test esistenti di `ProductModelTest.php` (`syncRepeaterRelations` o il salvataggio completo della Resource).
- [ ] **Step 3: eseguire** — atteso: rossi.
- [ ] **Step 4: implementare** campo, card, script, ramo di `prepareRepeaterRows`, `customizationOptions`, pulizia in `deleteRecord`.
- [ ] **Step 5: eseguire** i test e `php tests/run.php` — atteso: verdi.
- [ ] **Step 6: commit** «Scheda dell'articolo: riquadro Personalizzazioni con creazione al volo».

### Task 5: `Cart` controlla, prezza e confronta le personalizzazioni

**Files:**
- Modify: `src/Support/Orders/Cart.php` (`add` 102–145, `recalculate` 156–247, `merge` ~302, `contents` ~357, `itemLike` ~476; via `signature` ~490), `tests/integrazione/CartTest.php`

**Interfaces:**
- Consumes: `Customizations::resolve/forModel/encode/decode/signature/valuesOf` (Task 2), `Gestionale::feature('customizations')`, helper di `compra.php` (Task 2).
- Produces: `Cart::add($cartId, ['product_id' => int, 'quantity' => float, 'customization' => [customization_id => testo|option_id]])`; `Cart::contents()` e tutto ciò che la chiama ridanno `items[].customization` come **lista** (`decode`), mai come stringa.

Cambi:

- **`add()`**: modello = `(int) $product['product_model_id']`. Funzionalità accesa → `$resolved = Customizations::resolve($model, (array) ($line['customization'] ?? []))`. Spenta → `$resolved = ['fields' => [], 'surcharge' => '0.00']`, e se `forModel($model)` ha un `required` → `UserError::make('customization.unavailable', ['name' => nome dell'articolo])`. `itemLike($cartId, $productId, Customizations::signature($resolved['fields']))`. Nella riga nuova `'customization' => Customizations::encode($resolved['fields'])` e `'customization_surcharge' => $resolved['surcharge']`; `$line['customization_surcharge']` non si legge più.
- **`itemLike()`**: fra le righe `product` di quel prodotto, quella con `Customizations::signature(Customizations::decode($row['customization'] ?? ''))` uguale alla firma cercata.
- **`recalculate()`**: per ogni riga `product` ancora attiva, con la funzionalità accesa, `try { $r = Customizations::resolve($modelId, Customizations::valuesOf(Customizations::decode($item['customization'] ?? ''))); } catch (UserError) { OrderItem::delete(…); $removed[] = nome; continue; }`; poi `$item['customization_surcharge'] = $r['surcharge']` prima di `LinePrice::of`, e nell'`OrderItem::update` della riga anche `customization` (`encode($r['fields'])`, così le etichette cambiate in anagrafica si copiano finché la riga è nel carrello) e `customization_surcharge`. Con la funzionalità spenta niente di tutto questo.
- **`merge()`**: la firma della riga dell'ospite con `signature(decode(…))`; la riga creata nel carrello di destinazione copia la stringa salvata così com'è.
- **`contents()`**: `items` con `customization` passata da `Customizations::decode()`.

- [ ] **Step 1: scrivere i test** in `CartTest.php` (prima delle chiamate `Cart`, `accendiFunzionalita(['orders', 'customizations'])`; dopo ogni `prova()` `Gestionale::reset()`; prodotto da `articoloConGiacenza`, modello da `modelloDi`):
  1. una riga con incisione «Marco» (testo, +5) su un prezzo di 20: `unit_price` `25.00`, `contents()['items'][0]['customization']` è una lista con `label`, `value` «Marco», `option_id` 0, `surcharge` `5.00`;
  2. il `customization_surcharge` in ingresso (`'999'`) è ignorato;
  3. «Café ☕ 😀» e `A & B` tornano identici da `contents()` e la colonna nel database è solo ASCII;
  4. stessi valori due volte (chiavi in ordine diverso, id stringa/intero) → una riga, quantità 2; valori diversi → due righe;
  5. scelta Rossa (+3 sulla personalizzazione, +2 sull'opzione) → sovrapprezzo `5.00`, valore «Rossa»;
  6. obbligatoria mancante → `UserError` con `key()` `customization.required` e `field()` uguale all'id, carrello vuoto;
  7. sovrapprezzo cambiato in anagrafica → `recalculate()` riprezza la riga;
  8. personalizzazione disattivata, collegamento tolto, opzione cancellata, obbligatoria aggiunta all'articolo dopo: ciascuno fa uscire la riga con il nome in `removed`;
  9. funzionalità spenta: una riga già personalizzata resta con il suo sovrapprezzo dopo `recalculate()`; `add()` con valori li ignora (riga senza personalizzazione); `add()` su un articolo con un'obbligatoria → `customization.unavailable`;
  10. `merge()` di due carrelli con la stessa personalizzazione → una riga sola, quantità sommata; con personalizzazioni diverse → due righe;
  11. un articolo senza personalizzazioni si aggiunge come prima (i test già presenti restano verdi).
- [ ] **Step 2: eseguire** `php tests/integrazione/CartTest.php` — atteso: rossi i nuovi, verdi i vecchi.
- [ ] **Step 3: implementare** i cambi in `Cart` e togliere `signature()` privata.
- [ ] **Step 4: eseguire** `CartTest`, `CheckoutTest`, `LifecycleTest`, `OrdersDemoTest`, poi `php tests/ErrorKeysTest.php` e `php tests/run.php` — atteso: verdi.
- [ ] **Step 5: commit** «Carrello: personalizzazioni controllate e prezzate dal server, uscita della riga quando l'anagrafica cambia».

### Task 6: Le personalizzazioni in scheda dell'ordine, email e reso

**Files:**
- Modify: `src/Resources/Sales/OrderItemTableResource.php` (`nameCell` 111–132), `view/emails/order.php` (~27), `view/emails/order-merchant.php` (~22), `src/Support/Returns/Returns.php` (`lines()` 65–83), `src/Support/Returns/ReturnRules.php`, `src/Resources/Sales/OrderReturnResource.php` (`linesHtml()` 174–211), `tests/OrderSectionResourcesTest.php`, `tests/integrazione/OrderEmailsTest.php`, `tests/ReturnRulesTest.php`, `tests/OrderReturnResourceTest.php`, `tests/integrazione/ReturnsTest.php`

**Interfaces:**
- Consumes: `Customizations::lines(array $item)` e `decode` (Task 2).
- Produces: `ReturnRules::onlineReturnable(array $item): bool` (falso se `decode($item['customization'] ?? '')` non è vuota); `Returns::lines()` con la chiave in più `customization` (lista decodificata).

In ogni punto, dopo lo SKU (o dopo il nome nelle email), un `<div class="text-muted small">` per riga di `Customizations::lines($item)`, passata da `static::escape()` nelle Resource e da `$e()` nelle email. Niente se la lista è vuota.

- [ ] **Step 1: scrivere i test:** `OrderSectionResourcesTest` — il formatter `name` (raggiunto come oggi: `array_values(array_filter(OrderItemTableResource::tableSchema(), fn ($c) => (string) $c->name === 'name'))[0]->schema['formatter']`) con una riga che ha `customization` = `encode([… 'value' => '<b>Marco</b> & co' …])` stampa «Incisione: &lt;b&gt;Marco&lt;/b&gt; &amp; co» e non `<b>`; senza personalizzazione l'HTML è quello di prima. `OrderEmailsTest` — `OrderEmail::compose('received', …)` con una riga personalizzata contiene «Incisione: Marco» in entrambe le viste (cliente e commerciante), con un `&` escapato una volta sola. `ReturnRulesTest` — `onlineReturnable` vero senza personalizzazione e con `customization` `''`, falso con una lista. `OrderReturnResourceTest` — `linesHtml` mostra la riga «Incisione: Marco» sotto il nome, escapata. `ReturnsTest` — `Returns::lines()` di un ordine con una riga personalizzata riporta la lista decodificata.
- [ ] **Step 2: eseguire** i cinque test — atteso: rossi i nuovi.
- [ ] **Step 3: implementare** i cinque punti.
- [ ] **Step 4: eseguire** i test e `php tests/run.php` — atteso: verdi.
- [ ] **Step 5: commit** «Personalizzazioni sotto il nome della riga: scheda dell'ordine, email, reso; reso online escluso».

### Task 7: Dati di prova, guida per sviluppatori, chiusura

**Files:**
- Modify: `src/Seeding/CatalogDemo.php` (`create()` ~167, `clear()` ~848), `src/Seeding/DemoCode.php` (`KINDS`: `Customization::class => 'personalizzazione'`), `src/Seeding/OrdersDemo.php` (`place()` ~141), `tests/DemoCodeTest.php`, `tests/integrazione/CatalogDemoTest.php`, `tests/integrazione/OrdersDemoTest.php`, `docs/dev/concetti/catalogo.md`, `docs/dev/concetti/vendite.md`, `CHANGELOG.md`, `TODO.md`

**Interfaces:**
- Consumes: tutto il resto del piano.

- **`CatalogDemo::create()`**: con `ensure()` due personalizzazioni con il codice `DemoCode::forModel(Customization::class, …)`: «Incisione» (testo, 20 caratteri, +5, etichetta «Incisione», aiuto «Fino a 20 caratteri») e «Confezione regalo» (scelta, +3, opzioni «Rossa» e «Blu» a 0); collegate come **facoltative** a due articoli demo (per riferimento, con `modelId()`), se il collegamento non c'è già.
- **`CatalogDemo::clear()`**: dopo gli articoli (che portano via i loro collegamenti grazie al Task 4), le personalizzazioni nostre (`ours(Customization::class)`) con le loro opzioni; una ancora collegata a un articolo vero resta e finisce in `$kept`.
- **`OrdersDemo::place()`**: con `customizations` accesa, nei turni pari passa a `Cart::add()` l'incisione «Auguri» quando l'articolo ce l'ha (lo si sa da `Customizations::forModel()`).
- **Guide per sviluppatori**: in `catalogo.md` una sezione *Personalizzazioni* (tabelle, `forModel()` come contratto per E1b, `resolve()` e gli errori con `field()` da mettere sotto il campo, la codifica latin1 e perché si legge solo con `decode()`); in `vendite.md`, sotto `Cart`, il parametro `customization`, il sovrapprezzo calcolato dal server, la forma di `contents()['items'][]['customization']`, l'uscita della riga in `removed`, `ReturnRules::onlineReturnable()` per E1c.

- [ ] **Step 1: test** — `DemoCodeTest`: `DemoCode::label(Customization::class, 'Incisione')` dice «personalizzazione»; `prefixOf(Customization::class)` = `cus_`. `CatalogDemoTest`: dopo `create()` ci sono le due personalizzazioni (la scelta con due opzioni) e i due collegamenti facoltativi; `clear()` le toglie senza errori di chiave; una personalizzazione demo collegata a un articolo vero resta. `OrdersDemoTest`: con `customizations` accesa almeno una riga d'ordine ha l'incisione «Auguri» e il sovrapprezzo `5.00`; spenta nessuna.
- [ ] **Step 2: eseguire** — atteso: rossi.
- [ ] **Step 3: implementare** demo e `KINDS`.
- [ ] **Step 4: scrivere** le due sezioni delle guide per sviluppatori; `php tests/DocsPagesTest.php` — atteso: verde.
- [ ] **Step 5: rigenerare** il sito di prova: `cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php forge gestionale:demo --fresh` — atteso: nessun errore, le due personalizzazioni in *Catalogo → Personalizzazioni*.
- [ ] **Step 6: CHANGELOG e TODO** — voce in `CHANGELOG.md` (pagina *Personalizzazioni*, riquadro nell'articolo, `Customizations`, `Cart`, righe d'ordine, email, reso, demo; **chi aggiorna deve lanciare `php forge update`** per le tre tabelle nuove); in `TODO.md` il Piano 1 di G5 spuntato con la data e il ramo.
- [ ] **Step 7: eseguire** `php tests/run.php` — atteso: tutta la suite verde (riportare i conteggi).
- [ ] **Step 8: commit**, push del branch, PR su `main` con la descrizione e il footer; CI verde; merge con merge commit e pulizia di branch remoto, locale e worktree.

### Task 8: Prova nel browser (serve il tuo accesso)

Dopo il merge dico cosa aprire su `https://ecommerce.test/backend/` con `customizations` accesa in *Set Up → Funzionalità*: *Catalogo → Personalizzazioni* (crea una scelta con due opzioni, passa a testo, prova a eliminare quella in uso), la scheda di un articolo (riquadro *Personalizzazioni*, «Nuova personalizzazione», riga doppia, salva), un ordine demo con l'incisione (scheda, email di prova), *Registra reso* su quella riga. I ritocchi finiscono in un giro di revisione.

- [ ] **Step 1: avvisare l'utente** che la prova serve il suo login e proporre una breve sessione guidata.
