# Piano 1 di 4 — Aggiunte al core e scheletro del modulo

> **Per chi esegue:** SKILL RICHIESTA: usare superpowers:subagent-driven-development
> (consigliata) o superpowers:executing-plans, un task alla volta. I passi usano le
> caselle `- [ ]` per il tracciamento.

**Obiettivo:** portare in `wonder-image/app` le tre aggiunte che G1 richiede
(campi modificabili quando la pagina è in sola lettura, riquadri della home
registrati dai moduli, comandi `forge` dichiarati dai moduli), rilasciarle come
`2.2.2`, e creare lo scheletro di `wonder-image/gestionale` collegato al sito di
prova `boilerplates/ecommerce-site`.

**Architettura:** ogni aggiunta al core nasce come classe pura testabile senza
database (`ReadonlyFields`, `HomeWidgets`, `ModuleCommands`), che il codice
esistente richiama nei punti di raccordo (route registrar, presenter, view del
form, controller, home del backend, `Forge`). Il modulo segue le convenzioni di
`immobili`: manifest, entrypoint, configurazione, Resource base, test come script
PHP con l'harness del core.

**Stack:** PHP 8.2, `wonder-image/app` (framework), Symfony Console (comandi
`forge`), MySQL, test come script PHP con `tests/harness.php`.

**Spec:** `/Users/andreamarinoni/Developer/packages/gestionale/docs/superpowers/specs/2026-09-18-fondamenta-gestionale-design.md`
(sezioni 1, 2, 10; spec di architettura per i capitoli 3, 7, 8).

## Vincoli globali

- **Lingua:** testi, commenti e messaggi in italiano; nomi di classi, metodi,
  tabelle e colonne in inglese.
- **Prefisso delle tabelle del modulo:** `gst_` (D60).
- **Versione del core richiesta dal modulo:** `^2.2.2 || dev-main`; il manifest
  dichiara `frameworkCompatibility.wonder-app: ^2.2.2` e `php: ^8.2`.
- **Versione del modulo:** `0.1.0`, semver, `CHANGELOG.md` come immobili.
- **Test del core:** sono in `.gitignore`, si aggiungono con `git add -f`.
- **Esecuzione dei test del core:** `php tests/<percorso>Test.php` (l'harness
  stampa il riepilogo e restituisce un codice di uscita diverso da zero se
  qualcosa fallisce).
- **Rami:** in `packages/app` si lavora su `feature/gestionale-core-additions`
  creato da `main`; in `packages/gestionale` su `feature/scheletro-modulo` creato
  da `main`. Nessun push automatico: i rilasci e i push li decide l'utente.
- **Sito di prova:** `/Users/andreamarinoni/Developer/boilerplates/ecommerce-site`,
  `https://ecommerce.test`, database `ecommerce_site`. Non si creano database né
  utenti MySQL: se serve un database nuovo lo crea l'utente.
- **Percorsi assoluti:** `packages/app` = `/Users/andreamarinoni/Developer/packages/app`,
  `packages/gestionale` = `/Users/andreamarinoni/Developer/packages/gestionale`.

## Struttura dei file

**Nel core (`packages/app`):**

| File | Responsabilità |
|---|---|
| `class/Backend/Support/ReadonlyFields.php` (nuovo) | regole pure sui campi modificabili in sola lettura: normalizzazione, filtro dei valori, se la route `update` va registrata, se un campo va disabilitato |
| `class/App/Resource.php` (modifica) | nuovo hook `editableWhenReadonly()` |
| `class/App/ResourceRouteRegistrar.php` (modifica) | registra `update` anche in sola lettura quando ci sono campi modificabili |
| `class/Backend/Support/ResourcePagePresenter.php` (modifica) | non disabilita i campi modificabili e passa l'elenco alla view |
| `app/view/pages/backend/resource/form.php` (modifica) | mostra insieme avviso e pulsante "Salva" quando la pagina è parzialmente modificabile |
| `class/Backend/Support/ResourcePageController.php` (modifica) | in sola lettura accetta l'aggiornamento solo dei campi dichiarati |
| `class/Backend/Contracts/HomeWidget.php` (nuovo) | contratto dei riquadri della home |
| `class/Backend/Support/HomeWidgets.php` (nuovo) | raccoglie i riquadri dalla configurazione dei moduli, filtra per ruolo, ordina e li disegna |
| `app/view/pages/backend/home.php` (modifica) | disegna i riquadri sopra il contenuto attuale |
| `class/Console/ModuleCommands.php` (nuovo) | ricava i comandi `forge` dai manifest dei moduli abilitati |
| `class/App/Module/Manifest.php` (modifica) | `consoleCommands(): array` |
| `class/App/Module/ManifestValidator.php` (modifica) | valida `console.commands` |
| `class/Console/Forge.php` (modifica) | registra i comandi dei moduli |
| `docs/app/concetti/risorse/resource.md`, `docs/app/concetti/moduli.md` (modifica) | documentazione delle tre aggiunte |

**Nel modulo (`packages/gestionale`):**

| File | Responsabilità |
|---|---|
| `composer.json`, `module.json` | pacchetto e manifest |
| `src/Gestionale.php` | entrypoint: percorsi, configurazione, indirizzo delle guide |
| `src/Resources/GestionaleResource.php` | Resource base del modulo: funzionalità dichiarata e pulsante "Guida" |
| `config/module.php` | configurazione predefinita (indirizzo delle guide, estensioni, riquadri della home, funzionalità del sito) |
| `config/permissions.php`, `config/routes/route.backend.php` | permessi e route del backend (vuote in questo piano) |
| `lang/it/gestionale.json` | testi dell'interfaccia |
| `tests/run.php` + `tests/*Test.php` | test del modulo |
| `README.md`, `CHANGELOG.md`, `.gitignore` | contorno del pacchetto |

---

### Task 1: Regole pure dei campi modificabili in sola lettura

**File:**
- Crea: `packages/app/class/Backend/Support/ReadonlyFields.php`
- Modifica: `packages/app/class/App/Resource.php` (accanto a `isReadonly()`, riga ~614)
- Test: `packages/app/tests/Backend/Support/ReadonlyFieldsTest.php`

**Interfacce:**
- Consuma: `Wonder\App\Resource::isReadonly(): bool` (già presente).
- Produce: `Wonder\Backend\Support\ReadonlyFields::normalize(array $fields): array`,
  `::allowsUpdate(bool $readonly, array $editable): bool`,
  `::shouldDisable(string $field, bool $readonly, array $editable): bool`,
  `::filter(array $values, array $editable): array`;
  `Wonder\App\Resource::editableWhenReadonly(): array`.

- [ ] **Passo 1: crea il ramo**

```bash
cd /Users/andreamarinoni/Developer/packages/app && git switch -c feature/gestionale-core-additions
```

- [ ] **Passo 2: scrivi il test che fallisce**

File `packages/app/tests/Backend/Support/ReadonlyFieldsTest.php`:

```php
<?php
/** php tests/Backend/Support/ReadonlyFieldsTest.php */
declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/../../harness.php';

use Wonder\App\Resource;
use Wonder\Backend\Support\ReadonlyFields;

check('normalize: toglie vuoti, spazi e doppioni', fn () =>
    ReadonlyFields::normalize([' hours ', 'hours', '', 'special_hours', 0]) === ['hours', 'special_hours']
);

check('allowsUpdate: sempre vero se la pagina non è in sola lettura', fn () =>
    ReadonlyFields::allowsUpdate(false, []) === true
    && ReadonlyFields::allowsUpdate(false, ['hours']) === true
);

check('allowsUpdate: in sola lettura solo con campi dichiarati', fn () =>
    ReadonlyFields::allowsUpdate(true, []) === false
    && ReadonlyFields::allowsUpdate(true, ['hours']) === true
);

check('shouldDisable: in sola lettura si disabilita tutto tranne i campi dichiarati', fn () =>
    ReadonlyFields::shouldDisable('label', true, ['hours']) === true
    && ReadonlyFields::shouldDisable('hours', true, ['hours']) === false
    && ReadonlyFields::shouldDisable('label', false, []) === false
);

check('filter: tiene solo i campi dichiarati', fn () =>
    ReadonlyFields::filter(['label' => 'MC Nembro', 'hours' => [['id' => '1']]], ['hours'])
        === ['hours' => [['id' => '1']]]
);

check('filter senza campi dichiarati non lascia passare niente', fn () =>
    ReadonlyFields::filter(['label' => 'MC Nembro'], []) === []
);

check('Resource: nessun campo modificabile per impostazione predefinita', fn () =>
    Resource::editableWhenReadonly() === []
);

summary();
```

- [ ] **Passo 3: esegui il test e verifica che fallisca**

```bash
cd /Users/andreamarinoni/Developer/packages/app && php tests/Backend/Support/ReadonlyFieldsTest.php
```

Atteso: errore `Class "Wonder\Backend\Support\ReadonlyFields" not found`.

- [ ] **Passo 4: scrivi la classe**

File `packages/app/class/Backend/Support/ReadonlyFields.php`:

```php
<?php

namespace Wonder\Backend\Support;

/**
 * Campi che restano modificabili quando una pagina è in sola lettura
 * (`Resource::editableWhenReadonly()`): es. orari e chiusure della sede, che si
 * cambiano anche in produzione mentre il resto della scheda arriva dal deploy.
 */
final class ReadonlyFields
{
    /** @return list<string> */
    public static function normalize(array $fields): array
    {
        $normalized = [];

        foreach ($fields as $field) {
            if (!is_string($field)) {
                continue;
            }

            $name = trim($field);

            if ($name !== '' && !in_array($name, $normalized, true)) {
                $normalized[] = $name;
            }
        }

        return $normalized;
    }

    public static function allowsUpdate(bool $readonly, array $editable): bool
    {
        return !$readonly || self::normalize($editable) !== [];
    }

    public static function shouldDisable(string $field, bool $readonly, array $editable): bool
    {
        return $readonly && !in_array(trim($field), self::normalize($editable), true);
    }

    /** @return array<string, mixed> */
    public static function filter(array $values, array $editable): array
    {
        return array_intersect_key($values, array_flip(self::normalize($editable)));
    }
}
```

- [ ] **Passo 5: aggiungi l'hook alla Resource**

In `packages/app/class/App/Resource.php`, subito dopo `readonlyNotice()`:

```php
    /**
     * Campi che restano modificabili quando la pagina è in sola lettura.
     * Vuoto: la pagina non si modifica per niente.
     *
     * @return list<string>
     */
    public static function editableWhenReadonly(): array
    {
        return [];
    }
```

- [ ] **Passo 6: esegui il test e verifica che passi**

```bash
cd /Users/andreamarinoni/Developer/packages/app && php tests/Backend/Support/ReadonlyFieldsTest.php
```

Atteso: `7 test, 0 falliti`.

- [ ] **Passo 7: commit**

```bash
cd /Users/andreamarinoni/Developer/packages/app && git add class/Backend/Support/ReadonlyFields.php class/App/Resource.php && git add -f tests/Backend/Support/ReadonlyFieldsTest.php && git commit -m "Add the rules for fields editable on a readonly page

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 2: Route, form e salvataggio rispettano i campi modificabili

**File:**
- Modifica: `packages/app/class/App/ResourceRouteRegistrar.php` (righe 17 e 65)
- Modifica: `packages/app/class/Backend/Support/ResourcePagePresenter.php`
  (`form()` righe 32–61, `applyModelFieldState()` righe 222–250)
- Modifica: `packages/app/app/view/pages/backend/resource/form.php`
- Modifica: `packages/app/class/Backend/Support/ResourcePageController.php`
  (`requestValues()` riga ~218, `update()` righe 151–189)
- Verifica: `/private/tmp/claude-501/.../scratchpad/g1-verifica/sola-lettura.php`
  (script di prova, non committato)

**Interfacce:**
- Consuma: `ReadonlyFields::normalize()`, `::allowsUpdate()`, `::shouldDisable()`,
  `::filter()`, `Resource::editableWhenReadonly()` (Task 1).
- Produce: chiave `READONLY_EDITABLE` (lista di nomi di campo) nell'array della
  view del form; route `update` registrata anche in sola lettura quando ci sono
  campi modificabili.

- [ ] **Passo 1: registra la route `update` quando ci sono campi modificabili**

In `ResourceRouteRegistrar::registerBackend()`, dopo `$readonly = $resourceClass::isReadonly();`:

```php
                    $updatable = ReadonlyFields::allowsUpdate(
                        $readonly,
                        $resourceClass::editableWhenReadonly()
                    );
```

Aggiungi `use Wonder\Backend\Support\ReadonlyFields;` in cima al file, passa
`$updatable` nella lista `use (...)` della closure del gruppo e cambia la
condizione della route `update`:

```php
                            if (!empty($pages['update']) && $updatable && !$resourceClass::hasCustomBackendPage('update')) {
```

`create`, `store` e `delete` restano legate a `!$readonly`.

- [ ] **Passo 2: non disabilitare i campi modificabili**

In `ResourcePagePresenter::applyModelFieldState()` sostituisci:

```php
        if ($this->resourceClass::isReadonly() && method_exists($field, 'disabled')) {
            $field->disabled();
        }
```

con:

```php
        $name = property_exists($field, 'name') ? (string) ($field->name ?? '') : '';

        if (
            method_exists($field, 'disabled')
            && ReadonlyFields::shouldDisable(
                $name,
                $this->resourceClass::isReadonly(),
                $this->resourceClass::editableWhenReadonly()
            )
        ) {
            $field->disabled();
        }
```

Aggiungi `use Wonder\Backend\Support\ReadonlyFields;` in cima al file. Attenzione:
più avanti nello stesso metodo esiste già una variabile `$name`; usa il valore
appena calcolato invece di ricalcolarlo.

- [ ] **Passo 3: passa l'elenco alla view**

In `ResourcePagePresenter::form()`, accanto a `'READONLY' => ...`:

```php
            'READONLY_EDITABLE' => ReadonlyFields::normalize($this->resourceClass::editableWhenReadonly()),
```

- [ ] **Passo 4: mostra avviso e pulsante insieme**

In `app/view/pages/backend/resource/form.php`, dopo `$readonlyNotice = ...`:

```php
    $editableWhenReadonly = (array) ($READONLY_EDITABLE ?? []);
    $partial = $readonly && $editableWhenReadonly !== [];
```

Poi, nel ramo con `$FORM_LAYOUT`, sostituisci `action` e `footer`:

```php
                'action' => $readonly && !$partial ? '' : (string) ($FORM_ACTION ?? ''),
                'footer' => $readonly && !$partial
                    ? $noticeHtml
                    : ($partial ? $noticeHtml : '').'
                    <div class="col-12">
                        <wi-card class="col-12">
                            <div class="col-12">'.$submitHtml().'</div>
                        </wi-card>
                    </div>',
```

Nel ramo senza layout sostituisci le tre condizioni `$readonly` che governano
`action`, `onsubmit` e i pulsanti con `$readonly && !$partial`, lasciando
`<?php if ($readonly) { echo $noticeHtml; } ?>` come è: l'avviso si vede in
entrambi i casi.

- [ ] **Passo 5: accetta solo i campi dichiarati nel salvataggio**

In `ResourcePageController`, aggiungi `use Wonder\Backend\Support\ReadonlyFields;`
e il metodo:

```php
    /** @return list<string> */
    private function editableWhenReadonly(): array
    {
        return ReadonlyFields::normalize($this->resourceClass::editableWhenReadonly());
    }
```

Cambia `requestValues()`:

```php
    private function requestValues(): array
    {
        $values = array_merge($_POST, $_FILES);

        if (!$this->resourceClass::isReadonly()) {
            return $values;
        }

        return ReadonlyFields::filter($values, $this->editableWhenReadonly());
    }
```

In `update()` sostituisci `$this->guardWritable();` con:

```php
        $editable = $this->editableWhenReadonly();

        if (!ReadonlyFields::allowsUpdate($this->resourceClass::isReadonly(), $editable)) {
            throw new RuntimeException('Resource in sola lettura in questo ambiente: '.$this->resourceClass::slug());
        }
```

e, sempre in `update()`, filtra anche i repeater:

```php
            $readonly = $this->resourceClass::isReadonly();
            $this->resourceClass::syncRepeaterRelations(
                $id,
                $readonly ? ReadonlyFields::filter($_POST, $editable) : $_POST,
                $readonly ? ReadonlyFields::filter($_FILES, $editable) : $_FILES,
                'update',
                'backend'
            );
```

`store()` e `delete()` continuano a chiamare `guardWritable()`.

- [ ] **Passo 6: esegui tutti i test del core**

```bash
cd /Users/andreamarinoni/Developer/packages/app && for f in $(find tests -name '*Test.php' | sort); do php "$f" > /dev/null 2>&1 || echo "FALLITO $f"; done; echo fine
```

Atteso: nessuna riga `FALLITO`.

- [ ] **Passo 7: verifica sul sito, con una Resource di prova**

`SocietyLocationResource` del core è `final`, quindi la prova usa una Resource
temporanea di `new-site` (che vede il ramo del core tramite il collegamento in
`vendor/wonder-image/app`). Crea i due file, entrambi da cancellare alla fine.

File `/Users/andreamarinoni/Developer/boilerplates/new-site/app/Models/Site/ProvaSolaLettura.php`:

```php
<?php

namespace App\Models\Site;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\Fields\Field;
use Wonder\Sql\Schema\Column;

final class ProvaSolaLettura extends Model
{
    public static string $table = 'prova_sola_lettura';

    public static function syncSchema(): ?SyncSchema
    {
        return SyncSchema::multiRow()->keepIds()->localOnly();
    }

    public static function tableSchema(): array
    {
        return [Column::key('label'), Column::key('note')];
    }

    public static function dataSchema(): array
    {
        return [Field::key('label')->text(), Field::key('note')->text()];
    }
}
```

File `/Users/andreamarinoni/Developer/boilerplates/new-site/app/Resources/Site/ProvaSolaLetturaResource.php`:

```php
<?php

namespace App\Resources\Site;

use App\Models\Site\ProvaSolaLettura;
use Wonder\App\Resource;
use Wonder\App\ResourceSchema\FormField;

final class ProvaSolaLetturaResource extends Resource
{
    public static string $model = ProvaSolaLettura::class;

    public static function path(): string
    {
        return 'prova/sola-lettura';
    }

    public static function editableWhenReadonly(): array
    {
        return ['note'];
    }

    public static function formSchema(): array
    {
        return [
            FormField::key('label')->text(),
            FormField::key('note')->text(),
        ];
    }
}
```

Script di prova nella cartella scratchpad della sessione (percorso assoluto):

```php
<?php
const SITE = '/Users/andreamarinoni/Developer/boilerplates/new-site';
chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/wonder-image/app/wonder-image.php';

use App\Resources\Site\ProvaSolaLetturaResource as Prova;
use Wonder\App\Environment;
use Wonder\App\ResourceRouteRegistrar;
use Wonder\App\Theme;
use Wonder\Backend\Support\ResourceFormLayoutRenderer;
use Wonder\Backend\Support\ResourcePagePresenter;
use Wonder\Http\Route;

$_ENV['APP_ENV'] = 'production';
Environment::reset();
Theme::set('bootstrap');

Route::area('backend')->prefix('/backend')->name('backend.')->group(static function () use ($ROOT_APP): void {
    ResourceRouteRegistrar::registerBackend($ROOT_APP);
});

$nomi = array_column(Route::all(), 'name');
$prefisso = 'backend.resource.prova-sola-lettura.';

echo 'in sola lettura: '.(Prova::isReadonly() ? 'sì' : 'no')."\n";
echo 'route update: '.(in_array($prefisso.'update', $nomi, true) ? 'sì' : 'no')."\n";
echo 'route store: '.(in_array($prefisso.'store', $nomi, true) ? 'sì' : 'no')."\n";
echo 'route delete: '.(in_array($prefisso.'delete', $nomi, true) ? 'sì' : 'no')."\n";

$form = (new ResourcePagePresenter(Prova::class))->form('edit', ['id' => 1, 'label' => 'Prova', 'note' => 'Nota'], [], 1);
$html = ResourceFormLayoutRenderer::render($form['FORM_LAYOUT'] ?? null, ['action' => $form['FORM_ACTION']]);

if (trim($html) === '') {
    foreach ($form['FIELDS'] as $campo) {
        $html .= $campo->render();
    }
}

echo 'campi dichiarati modificabili: '.implode(', ', $form['READONLY_EDITABLE'])."\n";
echo 'label bloccato: '.(preg_match('/name="label"[^>]*disabled|disabled[^>]*name="label"/', $html) ? 'sì' : 'no')."\n";
echo 'note modificabile: '.(preg_match('/name="note"[^>]*disabled|disabled[^>]*name="note"/', $html) ? 'no' : 'sì')."\n";
```

Esegui `php <percorso>/sola-lettura.php`. Atteso: sola lettura sì, route update
sì, store no, delete no, campi dichiarati `note`, label bloccato sì, note
modificabile sì.

- [ ] **Passo 7b: rimuovi la Resource di prova**

```bash
rm /Users/andreamarinoni/Developer/boilerplates/new-site/app/Models/Site/ProvaSolaLettura.php /Users/andreamarinoni/Developer/boilerplates/new-site/app/Resources/Site/ProvaSolaLetturaResource.php
cd /Users/andreamarinoni/Developer/boilerplates/new-site && git status --short
```

Atteso: nessuna traccia dei due file. La tabella `prova_sola_lettura` non viene
creata, perché lo script non lancia `forge update`.

- [ ] **Passo 8: commit**

```bash
cd /Users/andreamarinoni/Developer/packages/app && git add class/App/ResourceRouteRegistrar.php class/Backend/Support/ResourcePagePresenter.php class/Backend/Support/ResourcePageController.php app/view/pages/backend/resource/form.php && git commit -m "Let a readonly page keep some fields editable

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 3: Riquadri della home del backend

**File:**
- Crea: `packages/app/class/Backend/Contracts/HomeWidget.php`
- Crea: `packages/app/class/Backend/Support/HomeWidgets.php`
- Modifica: `packages/app/app/view/pages/backend/home.php` (riga 3, dentro `<div class="row g-3">`)
- Test: `packages/app/tests/Backend/Support/HomeWidgetsTest.php`

**Interfacce:**
- Consuma: `Wonder\App\Module\ConfigRepository::all(): array` (configurazione dei
  moduli abilitati, indicizzata per slug).
- Produce: `Wonder\Backend\Contracts\HomeWidget` con `title(): string`,
  `render(): string`, `authorities(): array`, `order(): int`;
  `Wonder\Backend\Support\HomeWidgets::fromConfigs(array $configs, array $authorities): array`
  (istanze ordinate), `::all(array $authorities): array`,
  `::renderAll(array $authorities): string`.

- [ ] **Passo 1: scrivi il test che fallisce**

File `packages/app/tests/Backend/Support/HomeWidgetsTest.php`:

```php
<?php
/** php tests/Backend/Support/HomeWidgetsTest.php */
declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/../../harness.php';

use Wonder\Backend\Contracts\HomeWidget;
use Wonder\Backend\Support\HomeWidgets;

final class PrimiPassiWidgetDiProva implements HomeWidget
{
    public function title(): string { return 'Primi passi'; }
    public function render(): string { return '<div class="primi-passi">manca la P.IVA</div>'; }
    public function authorities(): array { return ['admin']; }
    public function order(): int { return 10; }
}

final class DaControllareWidgetDiProva implements HomeWidget
{
    public function title(): string { return 'Da controllare'; }
    public function render(): string { return '<div class="da-controllare">2 errori</div>'; }
    public function authorities(): array { return ['admin', 'administrator']; }
    public function order(): int { return 5; }
}

final class WidgetRottoDiProva implements HomeWidget
{
    public function title(): string { return 'Rotto'; }
    public function render(): string { throw new RuntimeException('boom'); }
    public function authorities(): array { return []; }
    public function order(): int { return 1; }
}

$configs = [
    'gestionale' => ['backend' => ['home_widgets' => [
        PrimiPassiWidgetDiProva::class,
        DaControllareWidgetDiProva::class,
    ]]],
    'altro' => ['backend' => ['home_widgets' => ['Classe\\Che\\Non\\Esiste', 42]]],
];

check('riquadri ordinati per order', function () use ($configs) {
    $widgets = HomeWidgets::fromConfigs($configs, ['admin']);

    return array_map(static fn (HomeWidget $w): string => $w->title(), $widgets)
        === ['Da controllare', 'Primi passi'];
});

check('filtro per ruolo', function () use ($configs) {
    $widgets = HomeWidgets::fromConfigs($configs, ['administrator']);

    return count($widgets) === 1 && $widgets[0]->title() === 'Da controllare';
});

check('senza ruoli dichiarati il riquadro si vede sempre', function () {
    $widgets = HomeWidgets::fromConfigs(
        ['x' => ['backend' => ['home_widgets' => [WidgetRottoDiProva::class]]]],
        ['administrator']
    );

    return count($widgets) === 1;
});

check('classi non valide ignorate senza eccezioni', function () use ($configs) {
    return count(HomeWidgets::fromConfigs($configs, ['admin'])) === 2;
});

check('renderAll unisce il markup dei riquadri', function () use ($configs) {
    $html = HomeWidgets::renderAll(['admin'], $configs);

    return str_contains($html, 'da-controllare')
        && str_contains($html, 'primi-passi')
        && strpos($html, 'da-controllare') < strpos($html, 'primi-passi');
});

check('un riquadro che solleva non rompe la home', function () {
    $html = HomeWidgets::renderAll(
        ['admin'],
        ['x' => ['backend' => ['home_widgets' => [WidgetRottoDiProva::class, PrimiPassiWidgetDiProva::class]]]]
    );

    return str_contains($html, 'primi-passi') && !str_contains($html, 'boom');
});

summary();
```

- [ ] **Passo 2: esegui il test e verifica che fallisca**

```bash
cd /Users/andreamarinoni/Developer/packages/app && php tests/Backend/Support/HomeWidgetsTest.php
```

Atteso: errore `Interface "Wonder\Backend\Contracts\HomeWidget" not found`.

- [ ] **Passo 3: scrivi il contratto**

File `packages/app/class/Backend/Contracts/HomeWidget.php`:

```php
<?php

namespace Wonder\Backend\Contracts;

/**
 * Riquadro della home del backend, dichiarato da un modulo nella propria
 * configurazione (`backend.home_widgets`).
 */
interface HomeWidget
{
    public function title(): string;

    /** Markup del riquadro; deve bastare a sé stesso. */
    public function render(): string;

    /** Ruoli che lo vedono; vuoto: tutti. @return list<string> */
    public function authorities(): array;

    /** Ordine crescente. */
    public function order(): int;
}
```

- [ ] **Passo 4: scrivi la classe**

File `packages/app/class/Backend/Support/HomeWidgets.php`:

```php
<?php

namespace Wonder\Backend\Support;

use Throwable;
use Wonder\App\Logger;
use Wonder\App\Module\ConfigRepository;
use Wonder\Backend\Contracts\HomeWidget;

/**
 * Riquadri della home del backend raccolti dalla configurazione dei moduli
 * abilitati (`backend.home_widgets`). Un riquadro non valido o che solleva
 * un'eccezione viene saltato: la home non si rompe mai.
 */
final class HomeWidgets
{
    /** @return list<HomeWidget> */
    public static function fromConfigs(array $configs, array $authorities): array
    {
        $widgets = [];

        foreach ($configs as $config) {
            foreach ((array) ($config['backend']['home_widgets'] ?? []) as $class) {
                if (!is_string($class) || !class_exists($class) || !is_subclass_of($class, HomeWidget::class)) {
                    continue;
                }

                try {
                    $widget = new $class();
                } catch (Throwable) {
                    continue;
                }

                $allowed = $widget->authorities();

                if ($allowed !== [] && array_intersect($allowed, $authorities) === []) {
                    continue;
                }

                $widgets[] = $widget;
            }
        }

        usort($widgets, static fn (HomeWidget $a, HomeWidget $b): int => $a->order() <=> $b->order());

        return $widgets;
    }

    /** @return list<HomeWidget> */
    public static function all(array $authorities): array
    {
        return self::fromConfigs(ConfigRepository::all(), $authorities);
    }

    public static function renderAll(array $authorities, ?array $configs = null): string
    {
        $html = '';

        foreach ($configs === null ? self::all($authorities) : self::fromConfigs($configs, $authorities) as $widget) {
            try {
                $html .= $widget->render();
            } catch (Throwable $exception) {
                Logger::log(
                    $exception,
                    'backend',
                    'home_widget',
                    'ERROR',
                    'error',
                    ['widget' => $widget::class],
                    false
                );
            }
        }

        return $html;
    }
}
```

`Wonder\App\Logger::log()` ha la firma
`log(Throwable $exception, string $service, string $action, string $level = 'ERROR',
string $file = 'error', array $context = [], bool $renderDebug = true)`: l'ultimo
argomento a `false` evita che il log interrompa la pagina.

- [ ] **Passo 5: esegui il test e verifica che passi**

```bash
cd /Users/andreamarinoni/Developer/packages/app && php tests/Backend/Support/HomeWidgetsTest.php
```

Atteso: `6 test, 0 falliti`.

- [ ] **Passo 6: disegna i riquadri nella home**

In `app/view/pages/backend/home.php`, subito dopo `<div class="row g-3">`:

```php
<?=\Wonder\Backend\Support\HomeWidgets::renderAll((array) ($USER->authority ?? []))?>
```

- [ ] **Passo 7: verifica che la home resti in piedi senza moduli**

```bash
cd /Users/andreamarinoni/Developer/packages/app && php -r 'require "/Users/andreamarinoni/Developer/boilerplates/new-site/vendor/autoload.php"; \Wonder\App\LegacyGlobals::share(["ROOT" => "/Users/andreamarinoni/Developer/boilerplates/new-site"]); var_dump(\Wonder\Backend\Support\HomeWidgets::renderAll(["admin"]));'
```

Atteso: stringa vuota, nessun errore (nessun modulo dichiara riquadri).

- [ ] **Passo 8: commit**

```bash
cd /Users/andreamarinoni/Developer/packages/app && git add class/Backend/Contracts/HomeWidget.php class/Backend/Support/HomeWidgets.php app/view/pages/backend/home.php && git add -f tests/Backend/Support/HomeWidgetsTest.php && git commit -m "Let modules add widgets to the backend home

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 4: Comandi `forge` dichiarati dai moduli

**File:**
- Crea: `packages/app/class/Console/ModuleCommands.php`
- Modifica: `packages/app/class/App/Module/Manifest.php` (accanto a `defaultsClass()`, riga ~238)
- Modifica: `packages/app/class/App/Module/ManifestValidator.php` (in `errors()`)
- Modifica: `packages/app/class/Console/Forge.php` (`run()`, riga ~42)
- Test: `packages/app/tests/Console/ModuleCommandsTest.php`

**Interfacce:**
- Consuma: `Wonder\App\Module\Discovery::discover()`, `Wonder\App\Module\StateRepository::isEnabled()`,
  `Wonder\App\LegacyGlobals::share()` (come fa `Wonder\Console\Commands\StatusModules`).
- Produce: `Wonder\App\Module\Manifest::consoleCommands(): array`;
  `Wonder\Console\ModuleCommands::fromManifests(iterable $manifests): array`
  (`['commands' => list<class-string>, 'errors' => list<string>]`),
  `::all(): array` (solo le classi dei moduli abilitati).

- [ ] **Passo 1: scrivi il test che fallisce**

File `packages/app/tests/Console/ModuleCommandsTest.php`:

```php
<?php
/** php tests/Console/ModuleCommandsTest.php */
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../harness.php';

use Symfony\Component\Console\Command\Command;
use Wonder\Console\ModuleCommands;

final class ComandoDiProva extends Command
{
    public $name = 'prova:uno';
}

final class NonUnComando
{
}

final class ManifestDiProva
{
    public function __construct(private string $slug, private array $commands)
    {
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function consoleCommands(): array
    {
        return $this->commands;
    }
}

check('classi valide raccolte in ordine di modulo', function () {
    $result = ModuleCommands::fromManifests([
        new ManifestDiProva('gestionale', [ComandoDiProva::class]),
    ]);

    return $result['commands'] === [ComandoDiProva::class] && $result['errors'] === [];
});

check('classe inesistente segnalata e saltata', function () {
    $result = ModuleCommands::fromManifests([
        new ManifestDiProva('gestionale', ['Classe\\Che\\Non\\Esiste']),
    ]);

    return $result['commands'] === []
        && count($result['errors']) === 1
        && str_contains($result['errors'][0], 'gestionale');
});

check('classe che non estende Command segnalata e saltata', function () {
    $result = ModuleCommands::fromManifests([
        new ManifestDiProva('gestionale', [NonUnComando::class]),
    ]);

    return $result['commands'] === [] && count($result['errors']) === 1;
});

check('doppioni tolti', function () {
    $result = ModuleCommands::fromManifests([
        new ManifestDiProva('gestionale', [ComandoDiProva::class]),
        new ManifestDiProva('ecommerce', [ComandoDiProva::class]),
    ]);

    return $result['commands'] === [ComandoDiProva::class];
});

check('manifest senza comandi: nessun errore', function () {
    $result = ModuleCommands::fromManifests([new ManifestDiProva('immobili', [])]);

    return $result['commands'] === [] && $result['errors'] === [];
});

summary();
```

- [ ] **Passo 2: esegui il test e verifica che fallisca**

```bash
cd /Users/andreamarinoni/Developer/packages/app && php tests/Console/ModuleCommandsTest.php
```

Atteso: errore `Class "Wonder\Console\ModuleCommands" not found`.

- [ ] **Passo 3: scrivi la classe**

File `packages/app/class/Console/ModuleCommands.php`:

```php
<?php

namespace Wonder\Console;

use Symfony\Component\Console\Command\Command;
use Wonder\App\LegacyGlobals;
use Wonder\App\Module\Discovery;
use Wonder\App\Module\StateRepository;

/**
 * Comandi `forge` dichiarati dai moduli in `console.commands` del manifest.
 * Le classi non valide vengono saltate con un messaggio: un modulo scritto male
 * non deve impedire l'uso di `forge`.
 */
final class ModuleCommands
{
    /**
     * @param iterable<object> $manifests oggetti con slug() e consoleCommands()
     * @return array{commands: list<class-string>, errors: list<string>}
     */
    public static function fromManifests(iterable $manifests): array
    {
        $commands = [];
        $errors = [];

        foreach ($manifests as $manifest) {
            foreach ($manifest->consoleCommands() as $class) {
                if (!is_string($class) || trim($class) === '') {
                    $errors[] = 'Comando non valido nel modulo '.$manifest->slug().': nome della classe mancante';
                    continue;
                }

                $class = trim($class);

                if (!class_exists($class) || !is_subclass_of($class, Command::class)) {
                    $errors[] = 'Comando non valido nel modulo '.$manifest->slug().': '.$class
                        .' non esiste o non estende '.Command::class;
                    continue;
                }

                if (!in_array($class, $commands, true)) {
                    $commands[] = $class;
                }
            }
        }

        return ['commands' => $commands, 'errors' => $errors];
    }

    /** @return array{commands: list<class-string>, errors: list<string>} */
    public static function all(): array
    {
        $root = getcwd() ?: '.';

        if (!is_file($root.'/vendor/autoload.php')) {
            return ['commands' => [], 'errors' => []];
        }

        LegacyGlobals::share(['ROOT' => $root]);

        $enabled = [];

        foreach (Discovery::discover() as $manifest) {
            if (StateRepository::isEnabled($manifest->slug())) {
                $enabled[] = $manifest;
            }
        }

        return self::fromManifests($enabled);
    }
}
```

- [ ] **Passo 4: esegui il test e verifica che passi**

```bash
cd /Users/andreamarinoni/Developer/packages/app && php tests/Console/ModuleCommandsTest.php
```

Atteso: `5 test, 0 falliti`.

- [ ] **Passo 5: leggi i comandi dal manifest**

In `class/App/Module/Manifest.php`, dopo `defaultsClass()`:

```php
    /** @return list<string> */
    public function consoleCommands(): array
    {
        $commands = $this->get('console.commands', []);

        return is_array($commands) ? array_values(array_filter($commands, 'is_string')) : [];
    }
```

In `class/App/Module/ManifestValidator::errors()`, prima del `return $errors;`:

```php
        $consoleCommands = $manifest->get('console.commands', []);

        if (!is_array($consoleCommands)) {
            $errors[] = 'console.commands deve essere una lista di classi';
        } else {
            foreach ($consoleCommands as $class) {
                if (!is_string($class) || trim($class) === '') {
                    $errors[] = 'console.commands: nome della classe non valido';
                }
            }
        }
```

- [ ] **Passo 6: registra i comandi in `Forge`**

In `class/Console/Forge.php`, all'inizio di `run()`:

```php
            $modules = ModuleCommands::all();

            foreach ($modules['errors'] as $error) {
                $output->writeln('<comment>⚠️  '.$error.'</comment>');
            }

            $this->commands = array_merge($this->commands, $modules['commands']);
```

- [ ] **Passo 7: prova `forge` su un sito**

```bash
cd /Users/andreamarinoni/Developer/boilerplates/new-site && php forge list | head -20
```

Atteso: l'elenco dei comandi del core, senza avvisi (nessun modulo di `new-site`
dichiara comandi).

- [ ] **Passo 8: commit**

```bash
cd /Users/andreamarinoni/Developer/packages/app && git add class/Console/ModuleCommands.php class/Console/Forge.php class/App/Module/Manifest.php class/App/Module/ManifestValidator.php && git add -f tests/Console/ModuleCommandsTest.php && git commit -m "Register forge commands declared by modules

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 5: Documentazione del core e versione `2.2.2`

**File:**
- Modifica: `packages/app/docs/app/concetti/risorse/resource.md` (tabella degli hook)
- Modifica: `packages/app/docs/app/concetti/moduli.md` (manifest e configurazione)
- Modifica: `packages/app/composer.json` (campo `version`)

**Interfacce:**
- Consuma: le tre aggiunte dei Task 1–4.
- Produce: la versione `2.2.2` che il manifest del modulo richiede (Task 6).

- [ ] **Passo 1: controlla come sono organizzate le pagine dei moduli**

```bash
cd /Users/andreamarinoni/Developer/packages/app && ls docs/app/concetti && grep -n "database.defaults\|module.json" docs/app/concetti/moduli.md | head
```

Se la pagina dei moduli ha un altro nome, usa quella: serve una sezione sul
manifest dove aggiungere `console.commands` e una sulla configurazione dove
aggiungere `backend.home_widgets`.

- [ ] **Passo 2: documenta l'hook della Resource**

In `docs/app/concetti/risorse/resource.md`, nella tabella degli hook accanto a
`readonlyNotice()`, aggiungi la riga:

```markdown
| `editableWhenReadonly(): array` | `[]` | campi che restano modificabili quando la pagina è in sola lettura: la route `update` resta registrata, gli altri campi sono disabilitati e il salvataggio accetta solo questi |
```

- [ ] **Passo 3: documenta manifest e riquadri**

Nella pagina dei moduli aggiungi due sezioni brevi:

```markdown
### Comandi `forge` del modulo

```json
"console": { "commands": ["Wonder\\Plugin\\Gestionale\\Console\\DemoCommand"] }
```

Le classi estendono `Symfony\Component\Console\Command\Command` e vengono
registrate solo per i moduli abilitati. Una classe che manca o non estende
`Command` viene saltata con un avviso.

### Riquadri della home del backend

```php
// config/module.php del modulo
'backend' => [ 'home_widgets' => [ \Wonder\Plugin\Gestionale\Backend\SetupWidget::class ] ],
```

Ogni classe implementa `Wonder\Backend\Contracts\HomeWidget` (`title()`,
`render()`, `authorities()`, `order()`). I riquadri si vedono in cima alla home,
filtrati per ruolo e ordinati per `order()`.
```

- [ ] **Passo 4: porta la versione a `2.2.2`**

```bash
cd /Users/andreamarinoni/Developer/packages/app && grep -n '"version"' composer.json
```

Sostituisci il valore con `2.2.2`. Il rilascio `2.2.1` (i lavori preparatori già
in `main`) lo tagga l'utente sul commit `223adf4b`, prima di questo.

- [ ] **Passo 5: esegui tutti i test del core**

```bash
cd /Users/andreamarinoni/Developer/packages/app && for f in $(find tests -name '*Test.php' | sort); do php "$f" > /dev/null 2>&1 || echo "FALLITO $f"; done; echo fine
```

Atteso: nessuna riga `FALLITO`.

- [ ] **Passo 6: commit**

```bash
cd /Users/andreamarinoni/Developer/packages/app && git add docs composer.json && git commit -m "Document the module additions and bump to 2.2.2

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

- [ ] **Passo 7: chiudi il ramo**

Usa la skill superpowers:finishing-a-development-branch: test verdi, poi merge in
`main` in locale. Il push e i tag `2.2.1` e `2.2.2` li fa l'utente.

---

### Task 6: Scheletro del modulo `wonder-image/gestionale`

**File (tutti in `packages/gestionale`):**
- Crea: `composer.json`, `module.json`, `.gitignore`, `README.md`, `CHANGELOG.md`
- Crea: `src/Gestionale.php`, `src/helpers.php`, `src/Resources/GestionaleResource.php`
- Crea: `config/module.php`, `config/permissions.php`, `config/routes/route.backend.php`
- Crea: `lang/it/gestionale.json`
- Crea: `tests/harness.php`, `tests/run.php`, `tests/ManifestTest.php`, `tests/GestionaleTest.php`
- Crea (cartelle vuote con `.gitkeep`): `src/Models`, `src/Support`, `src/Extensions`,
  `src/Console`, `src/Seeding`, `view`, `resources/assets`, `http`

**Interfacce:**
- Consuma: `Wonder\App\Module\Contracts\ModuleInterface`, `Wonder\App\Module\ConfigRepository`,
  `Wonder\App\Resource`, `Wonder\App\ResourceSchema\PageSchema`, la versione `2.2.2` del core (Task 5).
- Produce: `Wonder\Plugin\Gestionale\Gestionale` con `root()`, `manifestPath()`,
  `handlerPath(string $path)`, `viewPath(string $path)`, `langPath()`,
  `assetPath(string $path = '')`, `config(?string $key = null, mixed $default = null)`,
  `docsUrl(string $page): string`, `reset(): void`;
  `Wonder\Plugin\Gestionale\Resources\GestionaleResource` con `public static string $feature`,
  `public static string $docsPage` e `withDocs(PageSchema $schema): PageSchema`.

- [ ] **Passo 1: crea il ramo**

```bash
cd /Users/andreamarinoni/Developer/packages/gestionale && git switch -c feature/scheletro-modulo
```

- [ ] **Passo 2: scrivi `composer.json` e installa**

```json
{
    "name": "wonder-image/gestionale",
    "description": "Modulo gestionale per wonder-image/app: funzionalità sbloccabili, catalogo, magazzino, vendite, fatturazione e spedizioni.",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": "^8.2",
        "wonder-image/app": "^2.2.2 || dev-main"
    },
    "repositories": [
        {
            "type": "path",
            "url": "../app",
            "options": { "symlink": true }
        }
    ],
    "autoload": {
        "psr-4": { "Wonder\\Plugin\\Gestionale\\": "src/" },
        "files": [ "src/helpers.php" ]
    },
    "extra": { "wonder": { "module": true } },
    "minimum-stability": "stable",
    "prefer-stable": true
}
```

`.gitignore` (come immobili, che committa `composer.lock`):

```
/vendor/
.DS_Store
```

```bash
cd /Users/andreamarinoni/Developer/packages/gestionale && composer install
```

- [ ] **Passo 3: scrivi il manifest**

File `module.json`:

```json
{
    "name": "Wonder Gestionale",
    "slug": "gestionale",
    "version": "0.1.0",
    "description": "Gestionale per wonder-image/app: funzionalità sbloccabili, IVA e impostazioni, sedi, errori.",
    "namespace": "Wonder\\Plugin\\Gestionale\\",
    "entrypoint": "Wonder\\Plugin\\Gestionale\\Gestionale",
    "author": {
        "name": "Wonder Image",
        "email": "info@wonderimage.it"
    },
    "frameworkCompatibility": {
        "wonder-app": "^2.2.2",
        "php": "^8.2"
    },
    "dependencies": { "modules": [] },
    "paths": {
        "src": "src",
        "handlers": "http",
        "views": "view",
        "assets": "resources/assets",
        "lang": "lang",
        "tests": "tests"
    },
    "routes": { "backend": "config/routes/route.backend.php" },
    "permissions": { "definitions": "config/permissions.php" },
    "database": { "models": "src/Models" },
    "console": { "commands": [] }
}
```

`database.defaults` arriva con il piano 2, `console.commands` con il piano 4.

- [ ] **Passo 4: scrivi entrypoint, configurazione e Resource base**

`src/helpers.php` (vuoto per ora, serve all'autoload):

```php
<?php

// Funzioni globali del modulo: nessuna per ora.
```

`config/module.php`:

```php
<?php

return [
    // Indirizzo base della guida commercianti: ogni pagina aggiunge il proprio slug.
    'docs' => [
        'merchant_url' => 'https://guide.wonderimage.it/gestionale',
    ],
    // Riquadri della home del backend (Wonder\Backend\Contracts\HomeWidget).
    'backend' => [
        'home_widgets' => [],
    ],
    // Classi del sito che estendono Extensions\GestionaleExtension (piano 4).
    'extensions' => [],
    // Funzionalità aggiunte dal sito e chiavi da sbloccare alla prima installazione.
    'features' => [
        'extra' => [],
        'unlock' => [],
    ],
];
```

`config/permissions.php`:

```php
<?php

// I ruoli sono quelli del core: admin (Wonder Image) e administrator (commerciante).
return [];
```

`config/routes/route.backend.php`:

```php
<?php

// Le pagine del gestionale sono Resource: le route le registra il core.
// Qui restano le eventuali pagine fuori dallo schema delle Resource.
```

`src/Gestionale.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale;

use Wonder\App\Module\ConfigRepository;
use Wonder\App\Module\Contracts\ModuleInterface;

/**
 * Entrypoint del modulo: percorsi, configurazione e indirizzi della guida.
 */
final class Gestionale implements ModuleInterface
{
    public const SLUG = 'gestionale';

    private static ?array $config = null;

    public static function root(): string
    {
        return dirname(__DIR__);
    }

    public static function manifestPath(): string
    {
        return self::root().'/module.json';
    }

    public static function handlerPath(string $path): string
    {
        return self::root().'/http/'.ltrim($path, '/');
    }

    public static function viewPath(string $path): string
    {
        $custom = (string) ($GLOBALS['ROOT'] ?? '').'/custom/modules/'.self::SLUG.'/view/'.ltrim($path, '/');

        return is_file($custom) ? $custom : self::root().'/view/'.ltrim($path, '/');
    }

    public static function langPath(): string
    {
        return self::root().'/lang';
    }

    public static function assetPath(string $path = ''): string
    {
        return self::root().'/resources/assets/'.ltrim($path, '/');
    }

    /** Configurazione del modulo, con l'override del sito. */
    public static function config(?string $key = null, mixed $default = null): mixed
    {
        if (self::$config === null) {
            $defaults = require self::root().'/config/module.php';
            $site = class_exists(ConfigRepository::class) ? ConfigRepository::for(self::SLUG) : [];
            self::$config = array_replace_recursive((array) $defaults, (array) $site);
        }

        if ($key === null) {
            return self::$config;
        }

        $current = self::$config;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    /** Indirizzo di una pagina della guida commercianti; vuoto se non configurato. */
    public static function docsUrl(string $page): string
    {
        $base = trim((string) self::config('docs.merchant_url', ''));
        $page = trim($page, '/ ');

        if ($base === '' || $page === '') {
            return '';
        }

        return rtrim($base, '/').'/'.$page;
    }

    public static function reset(): void
    {
        self::$config = null;
    }
}
```

`src/Resources/GestionaleResource.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Resources;

use Wonder\App\Resource;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\Plugin\Gestionale\Gestionale;

/**
 * Resource base del gestionale. Ogni pagina dichiara la funzionalità che la
 * governa ($feature, vuoto = sempre attiva) e la pagina della guida
 * commercianti ($docsPage). Il controllo della funzionalità su menu, pagine e
 * API arriva con il piano 2, quando esiste lo stato delle funzionalità.
 */
abstract class GestionaleResource extends Resource
{
    public static string $feature = '';
    public static string $docsPage = '';

    public static function pageSchema(): PageSchema
    {
        return static::withDocs(PageSchema::for(static::class));
    }

    /** Aggiunge il pulsante "Guida" se la Resource dichiara una pagina. */
    protected static function withDocs(PageSchema $schema): PageSchema
    {
        $url = Gestionale::docsUrl(static::$docsPage);

        return $url === '' ? $schema : $schema->docs($url);
    }
}
```

`lang/it/gestionale.json`:

```json
{
    "gestionale": {
        "name": "Gestionale"
    }
}
```

- [ ] **Passo 5: scrivi harness e runner dei test**

`tests/harness.php` è la copia di quello del core (i test del core non vengono
pubblicati, quindi il modulo porta il proprio):

```php
<?php // tests/harness.php
declare(strict_types=1);

$GLOBALS['__tests'] = 0;
$GLOBALS['__failures'] = 0;

function check(string $name, callable $fn): void
{
    $GLOBALS['__tests']++;
    try {
        $ok = $fn();
        if ($ok === false) {
            $GLOBALS['__failures']++;
            echo "  ✗ {$name}\n";
        } else {
            echo "  ✓ {$name}\n";
        }
    } catch (\Throwable $e) {
        $GLOBALS['__failures']++;
        echo "  ✗ {$name} — {$e->getMessage()}\n";
    }
}

function summary(): void
{
    $t = $GLOBALS['__tests'];
    $f = $GLOBALS['__failures'];
    echo "\n{$t} test, {$f} falliti\n";
    exit($f === 0 ? 0 : 1);
}
```

`tests/run.php`:

```php
<?php
/** php tests/run.php — esegue tutti i test del modulo. */
declare(strict_types=1);

$failed = [];

foreach (glob(__DIR__.'/*Test.php') ?: [] as $file) {
    echo basename($file)."\n";
    passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg($file), $status);

    if ($status !== 0) {
        $failed[] = basename($file);
    }
}

echo $failed === []
    ? "\nTutti i test del gestionale passano.\n"
    : "\nFalliti: ".implode(', ', $failed)."\n";

exit($failed === [] ? 0 : 1);
```

- [ ] **Passo 6: scrivi i test del modulo**

`tests/ManifestTest.php`:

```php
<?php
/** php tests/ManifestTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\App\LegacyGlobals;
use Wonder\App\Module\Manifest;
use Wonder\App\Module\ManifestValidator;

LegacyGlobals::share(['ROOT' => dirname(__DIR__)]);

$manifest = Manifest::fromFile(dirname(__DIR__).'/module.json', 'local');

check('manifest valido per il core', function () use ($manifest) {
    $errors = ManifestValidator::errors($manifest);

    if ($errors !== []) {
        echo '    '.implode("\n    ", $errors)."\n";
    }

    return $errors === [];
});

check('slug, namespace e versione', fn () =>
    $manifest->slug() === 'gestionale'
    && $manifest->namespace() === 'Wonder\\Plugin\\Gestionale\\'
    && $manifest->version() === '0.1.0'
);

check('richiede il core 2.2.2 e PHP 8.2', fn () =>
    ($manifest->frameworkCompatibility()['wonder-app'] ?? '') === '^2.2.2'
    && ($manifest->frameworkCompatibility()['php'] ?? '') === '^8.2'
);

check('nessuna dipendenza da altri moduli', fn () =>
    $manifest->consoleCommands() === []
    && (array) $manifest->get('dependencies.modules', []) === []
);

summary();
```

`tests/GestionaleTest.php`:

```php
<?php
/** php tests/GestionaleTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;

check('percorsi del modulo', fn () =>
    Gestionale::root() === dirname(__DIR__)
    && Gestionale::manifestPath() === dirname(__DIR__).'/module.json'
    && Gestionale::langPath() === dirname(__DIR__).'/lang'
);

check('configurazione predefinita', fn () =>
    Gestionale::config('docs.merchant_url') === 'https://guide.wonderimage.it/gestionale'
    && Gestionale::config('extensions') === []
    && Gestionale::config('features.unlock') === []
    && Gestionale::config('chiave.inesistente', 'ripiego') === 'ripiego'
);

check('indirizzo della guida', fn () =>
    Gestionale::docsUrl('primi-passi') === 'https://guide.wonderimage.it/gestionale/primi-passi'
    && Gestionale::docsUrl('/sedi/') === 'https://guide.wonderimage.it/gestionale/sedi'
    && Gestionale::docsUrl('') === ''
);

check('Resource base senza funzionalità né guida dichiarate', fn () =>
    GestionaleResource::$feature === '' && GestionaleResource::$docsPage === ''
);

summary();
```

- [ ] **Passo 7: esegui i test del modulo**

```bash
cd /Users/andreamarinoni/Developer/packages/gestionale && php tests/run.php
```

Atteso: `ManifestTest.php` con 4 test verdi, `GestionaleTest.php` con 4 test
verdi, e "Tutti i test del gestionale passano.". Se il manifest risulta non
valido, l'elenco degli errori compare sotto il test.

- [ ] **Passo 8: README e CHANGELOG**

`README.md`:

```markdown
# Wonder Gestionale

Modulo per `wonder-image/app` con catalogo, magazzino, anagrafiche, vendite,
fatturazione e spedizioni. Le funzionalità si sbloccano dal pannello: un sito
installa il modulo e usa solo ciò che gli serve.

## Installazione in sviluppo

Nel `composer.json` del sito:

```json
"repositories": [ { "type": "path", "url": "../../packages/gestionale", "options": { "symlink": true } } ],
"require": { "wonder-image/gestionale": "@dev" }
```

Poi `composer update wonder-image/gestionale` e, in `custom/config/modules.php`:

```php
return [ 'gestionale' => [ 'enabled' => true ] ];
```

## Test

```bash
php tests/run.php
```

## Documentazione

- Guida sviluppatori: `docs/`
- Guida commercianti: `guide/`
- Architettura e spec dei sotto-progetti: `docs/superpowers/`
```

`CHANGELOG.md`:

```markdown
# Changelog

Il formato segue [Keep a Changelog](https://keepachangelog.com/it/1.1.0/) e il
versionamento semantico.

## 0.1.0 — non rilasciata

### Aggiunto
- Scheletro del modulo: manifest, entrypoint, configurazione, Resource base.
- Test del modulo con harness proprio e `php tests/run.php`.
```

- [ ] **Passo 9: commit**

```bash
cd /Users/andreamarinoni/Developer/packages/gestionale && git add -A && git commit -m "Add the module skeleton

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 7: Sito di prova con il modulo collegato

**File (tutti in `/Users/andreamarinoni/Developer/boilerplates/ecommerce-site`):**
- Modifica: `composer.json` (repository `path` e `require`)
- Crea: `custom/config/modules.php`
- Modifica: `.env` (`APP_ENV=local`)

**Interfacce:**
- Consuma: il pacchetto `wonder-image/gestionale` (Task 6) e il core `2.2.2` (Task 5).
- Produce: un sito locale con il modulo abilitato, su cui gireranno le verifiche
  dei piani successivi.

- [ ] **Passo 1: collega il pacchetto**

In `composer.json` del sito aggiungi il repository e la dipendenza:

```json
    "repositories": [
        {
            "type": "path",
            "url": "../../packages/gestionale",
            "options": { "symlink": true }
        }
    ],
    "require": {
        "php": "^8.2",
        "wonder-image/app": "dev-main",
        "wonder-image/gestionale": "@dev"
    },
```

Se `composer.json` ha già una sezione `repositories`, aggiungi l'elemento senza
toglierne altri. Poi:

```bash
cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && composer update wonder-image/gestionale
```

Atteso: `vendor/wonder-image/gestionale` come collegamento a `packages/gestionale`.

- [ ] **Passo 2: abilita il modulo**

File `custom/config/modules.php` (non esiste ancora):

```php
<?php

    return [
        'gestionale' => [
            'enabled' => true,
        ],
    ];
```

- [ ] **Passo 3: dichiara l'ambiente locale**

Aggiungi `APP_ENV=local` al `.env` del sito, accanto alle altre variabili
d'ambiente (non toccare le credenziali):

```bash
cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && grep -q '^APP_ENV=' .env || printf '\nAPP_ENV=local\n' >> .env
```

- [ ] **Passo 4: verifica che il core veda il modulo**

```bash
cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php forge status:modules
```

Atteso: riga `gestionale` con versione `0.1.0`, abilitato e valido. Se compare un
errore di manifest, correggilo nel modulo e rilancia.

- [ ] **Passo 5: esegui l'update del sito**

```bash
cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php forge update
```

Atteso: `"success": true`. Il modulo non ha ancora Model, quindi non crea tabelle:
serve a confermare che l'abilitazione non rompe l'update.

- [ ] **Passo 6: controlla il backend nel browser**

Apri `https://ecommerce.test/backend/` (l'accesso lo fa l'utente) e verifica che
la home si apra senza errori e che il menu sia quello del core: il gestionale non
ha ancora pagine. Controlla anche la console del browser: nessun errore JavaScript.

- [ ] **Passo 7: chiedi all'utente il database dei test**

I test d'integrazione dei piani 2 e 3 vogliono un database dedicato, che non posso
creare. Chiedi all'utente di crearlo e di comunicarne il nome, poi annotalo nella
TODO del gestionale. Finché non c'è, i piani successivi eseguono solo i test senza
database.

- [ ] **Passo 8: commit del sito**

```bash
cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && git add composer.json composer.lock custom/config/modules.php && git commit -m "Enable the gestionale module

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

Il `.env` non si committa.

- [ ] **Passo 9: chiudi il ramo del modulo**

Usa la skill superpowers:finishing-a-development-branch per
`feature/scheletro-modulo` in `packages/gestionale`: test verdi
(`php tests/run.php`), poi merge in `main` in locale e push, visto che il
repository è già su GitHub.

---

## Verifica finale del piano

1. `cd packages/app && for f in $(find tests -name '*Test.php' | sort); do php "$f" > /dev/null 2>&1 || echo "FALLITO $f"; done` — nessun fallimento.
2. `cd packages/gestionale && php tests/run.php` — tutti verdi.
3. `cd boilerplates/ecommerce-site && php forge status:modules` — `gestionale` abilitato e valido.
4. `cd boilerplates/ecommerce-site && php forge update` — `"success": true`.
5. Lo script `sola-lettura.php` del Task 2 mostra campi bloccati, orari modificabili e pulsante "Salva".
6. `cd boilerplates/new-site && php forge list` — nessun avviso sui comandi dei moduli.
7. Backend di `https://ecommerce.test` e di `https://new.test`: home e pagine delle sedi si aprono senza errori.

## Cosa resta all'utente

- Tag `2.2.1` sul commit `223adf4b` di `wonder-image/app` (lavori preparatori) e
  rilascio `2.2.2` dopo il merge di questo piano.
- Creazione del database dei test del sito di prova.
- Rilascio di `wonder-image/lib` dal ramo `fix/backend-error-messages`.

## Cosa arriva nei piani successivi

| Piano | Contenuto |
|---|---|
| 2 | funzionalità, pannello, righe precaricate, sincronizzazione |
| 3 | codici, numerazioni, log degli stati, riferimenti esterni, IVA, impostazioni, sede principale (con `editableWhenReadonly()` usato davvero) |
| 4 | errori, riquadri della home, hook, dati di prova, GitHub Actions, guida sviluppatori e guida commercianti |
