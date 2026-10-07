# Checkout a passi — piano 1: componenti (lib + app)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** dare al checkout a passi i mattoni grafici che mancano: `.wi-choice`, `.wi-steps` e `.wi-thumb` nella lib e gli Element `Choice`, `ChoiceGroup` e `Steps` in app, con i renderer Wonder e Bootstrap.

**Architecture:** la lib ha un file CSS per componente, importato da `src/export/frontend/head.js` e registrato in `MANIFEST.json`. In app ogni Element è una classe `Wonder\Elements\Components\X` che scrive solo lo schema. Ogni tema ha il suo renderer: `Wonder\Themes\Wonder\Components\X` e `Wonder\Themes\Bootstrap\Components\X`. Il Resolver non ripiega su un altro tema, quindi servono tutti e due. Il markup Wonder usa le classi della lib; quello Bootstrap usa `card`, `form-check` e `breadcrumb`.

**Tech Stack:** CSS puro e test node (`node:assert`) nella lib; PHP 8.2 con l'harness `tests/harness.php` (`check()` e `summary()`) in app.

**Spec:** `docs/superpowers/specs/2026-10-06-checkout-a-passi-design.md` (gestionale), §7 «Componenti nuovi» e §9 «Prove».

## Global Constraints

- **Repository**:
  - lib = `/Users/andreamarinoni/Developer/packages/lib`;
  - app = `/Users/andreamarinoni/Developer/packages/app`.
  - Tutti e due partono da `main` pulito. Si lavora sul ramo nuovo `checkout-a-passi-componenti`, uno per repository.
- Niente push, PR o merge senza la conferma esplicita dell'utente.
- **Lib**:
  - si modificano solo i sorgenti, mai `dist/`: lo rifà `npm run release`, che in questo piano **non** si lancia;
  - file CSS solo ASCII. Il separatore «›» si scrive `"\203A"`;
  - stile dei file come `spinner.css`: dichiarazioni senza rientro, commenti rari;
  - token da usare: `--spacer`, `--button-border-radius`, `--dropdown-border-color`, `--dropdown-border-width`, `--input-border-focus`, `--text-small-font-size`, `--text-small-line-height`;
  - breakpoint telefono: `@media (max-width: 768px)`.
- **App**:
  - i testi dei componenti passano da `escape()`: nessun HTML grezzo nei parametri;
  - nessuna vista e nessun FormField forza `render('wonder')`. Dentro un renderer del tema Wonder i figli si rendono con `renderComponents()`, come fa il resto del tema.
- **Ganci per il JS del piano 2**, uguali nei due temi:
  - `data-choice-input` sull'`input`;
  - `data-choice-title`, `data-choice-text` e `data-choice-aside` sulle tre parti del `Choice`;
  - `data-choice-list` sul contenitore dei `Choice` dentro `ChoiceGroup`.
- Una parte vuota del `Choice` si stampa comunque, con l'attributo `hidden`. Così il `<template>` del piano 2 ha sempre tutte le parti da riempire.
- **Commit**: in italiano, con la riga `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **Testo con caratteri HTML** nel titolo, nel testo, nell'aside, nella legend o in un'etichetta dei passi, per esempio il nome di un corriere «Rossi & <Figli>». Ci si aspetta di vederlo scritto tale e quale, senza markup iniettato. I test escapano in tutti e due i temi.
2. **`Steps` con uno stato sconosciuto** (`'active'`), oppure un `todo` o un `current` con un `href`: nessuno dei due diventa un link. Uno stato sconosciuto vale `todo` (Task 5).
3. **`Choice` con `->class('x')` o `->attr('data-x', …)`**: le classi date si aggiungono a quella base, non la sostituiscono (Task 4, test «le classi date si aggiungono»).
4. **`ChoiceGroup` senza legend** (`ChoiceGroup::make()`): niente `<legend>` vuota (Task 4).
5. **Valore numerico del `Choice`** (`Choice::make('location_id', 3)`): esce come `value="3"`. `checked` arriva dal confronto che fa la vista (Task 4).

---

## Mappa dei file

| Repository | File | Responsabilità |
|---|---|---|
| lib | `src/build/frontend/css/components/choice.css` (nuovo) | `.wi-choice`, `.wi-choice-group` |
| lib | `src/build/frontend/css/components/steps.css` (nuovo) | `.wi-steps` |
| lib | `src/build/frontend/css/components/thumb.css` (nuovo) | `.wi-thumb` |
| lib | `src/export/frontend/head.js` | import dei tre CSS |
| lib | `MANIFEST.json` | voci `choice`, `steps`, `thumb` |
| lib | `test/checkout-components.test.cjs` (nuovo), `package.json` | test dei file, degli import, del MANIFEST e della documentazione |
| lib | `docs/styles/components.md`, `CHANGELOG.md` | documentazione |
| app | `class/Themes/Concerns/MergesClasses.php` (nuovo) | unisce le classi base a quelle date con `class()`/`addClass()` |
| app | `class/Elements/Components/Choice.php`, `ChoiceGroup.php`, `Steps.php` (nuovi) | API e schema |
| app | `class/Themes/Wonder/Components/Choice.php`, `ChoiceGroup.php`, `Steps.php` (nuovi) | markup della lib |
| app | `class/Themes/Bootstrap/Components/Choice.php`, `ChoiceGroup.php`, `Steps.php` (nuovi) | markup Bootstrap |
| app | `tests/Themes/ChoiceTest.php`, `tests/Themes/StepsTest.php` (nuovi) | prove nei due temi |
| app | `docs/app/concetti/componenti/README.md`, `CHANGELOG.md` | documentazione |

---

### Task 1: lib — `choice.css`, `steps.css`, `thumb.css` registrati e importati

**Files:**
- Create: `lib/src/build/frontend/css/components/choice.css`
- Create: `lib/src/build/frontend/css/components/steps.css`
- Create: `lib/src/build/frontend/css/components/thumb.css`
- Modify: `lib/src/export/frontend/head.js` (blocco degli import dei componenti, dopo `spinner.css`)
- Modify: `lib/MANIFEST.json` (`components`, dopo la voce `spinner`)
- Create: `lib/test/checkout-components.test.cjs`
- Modify: `lib/package.json` (script `test`)

**Interfaces:**
- Consumes: nessuna.
- Produces, usate dai renderer Wonder del Task 4 e del Task 5 e dalle viste del piano 2:
  - `.wi-choice`, con `.wi-choice__body`, `.wi-choice__title`, `.wi-choice__text` e `.wi-choice__aside`;
  - `.wi-choice-group`, con `.wi-choice-group__legend` e `.wi-choice-group__list`;
  - `.wi-steps` con `.wi-steps__item`, e gli stati `.is-done`, `.is-disabled` e `[aria-current="step"]`;
  - `.wi-thumb`, con la variabile `--wi-thumb-size` (64px) e un `.badge` figlio.

- [ ] **Step 1: crea il ramo**

```bash
cd /Users/andreamarinoni/Developer/packages/lib && git switch -c checkout-a-passi-componenti
```

Expected: `Switched to a new branch 'checkout-a-passi-componenti'`.

- [ ] **Step 2: scrivi il test che fallisce**

`lib/test/checkout-components.test.cjs`:

```js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const manifest = JSON.parse(read('MANIFEST.json'));
const head = read('src/export/frontend/head.js');

const expected = {
    choice: ['.wi-choice', '.wi-choice__body', '.wi-choice__title', '.wi-choice__text', '.wi-choice__aside', '.wi-choice-group', '.wi-choice-group__legend', '.wi-choice-group__list'],
    steps: ['.wi-steps', '.wi-steps__item'],
    thumb: ['.wi-thumb'],
};

for (const [key, classes] of Object.entries(expected)) {
    const entry = manifest.components[key];
    const file = 'src/build/frontend/css/components/' + key + '.css';

    assert.ok(entry, 'MANIFEST senza components.' + key);
    assert.equal(entry.file, file);
    assert.deepEqual(entry.classes, classes);
    assert.ok(head.includes("import '/" + file + "';"), 'head.js non importa ' + file);

    const css = read(file);

    assert.ok(/^[\x00-\x7F]*$/.test(css), file + ' contiene caratteri non ASCII');

    for (const name of classes) {
        assert.ok(css.includes(name + ' ') || css.includes(name + ',') || css.includes(name + ':') || css.includes(name + '>') || css.includes(name + '['), file + ' senza ' + name);
    }
}

const choice = read('src/build/frontend/css/components/choice.css');
for (const state of [':has(input:checked)', ':has(input:focus-visible)', ':has(input:disabled)', '@media (max-width: 768px)']) {
    assert.ok(choice.includes(state), 'choice.css senza ' + state);
}

const steps = read('src/build/frontend/css/components/steps.css');
for (const rule of ['[aria-current="step"]', '.is-disabled', '"\\203A"', 'overflow-x: auto']) {
    assert.ok(steps.includes(rule), 'steps.css senza ' + rule);
}

const thumb = read('src/build/frontend/css/components/thumb.css');
for (const rule of ['--wi-thumb-size', '.wi-thumb > .badge', 'object-fit: cover']) {
    assert.ok(thumb.includes(rule), 'thumb.css senza ' + rule);
}

console.log('checkout-components: ok');
```

In `lib/package.json`, alla fine dello script `test`, aggiungi ` && node test/checkout-components.test.cjs`. Lo script diventa `"... && node test/backend-save-bar.test.cjs && node test/checkout-components.test.cjs"`.

- [ ] **Step 3: lancia il test e guardalo fallire**

Run: `cd /Users/andreamarinoni/Developer/packages/lib && node test/checkout-components.test.cjs`
Expected: FAIL con `AssertionError [ERR_ASSERTION]: MANIFEST senza components.choice`.

- [ ] **Step 4: scrivi `choice.css`**

`lib/src/build/frontend/css/components/choice.css`:

```css
.wi-choice-group {
min-width: 0;
margin: 0;
padding: 0;
border: 0;
}
.wi-choice-group__legend {
width: 100%;
padding: 0;
margin-bottom: calc(var(--spacer) * 3);
font-weight: 600;
}
.wi-choice-group__list {
display: grid;
gap: calc(var(--spacer) * 2);
}

.wi-choice {
display: flex;
align-items: center;
gap: calc(var(--spacer) * 3);
width: 100%;
padding: 16px;
box-sizing: border-box;
background: #ffffff;
border-color: var(--dropdown-border-color);
border-style: solid;
border-width: var(--dropdown-border-width);
border-radius: var(--button-border-radius);
cursor: pointer;
transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.wi-choice > input {
flex-shrink: 0;
width: 18px;
height: 18px;
margin: 0;
accent-color: var(--input-border-focus);
cursor: inherit;
}
.wi-choice__body {
display: flex;
flex: 1 1 auto;
flex-direction: column;
gap: var(--spacer);
min-width: 0;
}
.wi-choice__title {
font-weight: 600;
}
.wi-choice__text {
font-size: var(--text-small-font-size);
line-height: var(--text-small-line-height);
opacity: 0.7;
}
.wi-choice__aside {
flex-shrink: 0;
margin-left: auto;
font-weight: 600;
text-align: right;
}
.wi-choice [hidden] {
display: none !important;
}

.wi-choice:has(input:checked) {
border-color: var(--input-border-focus);
box-shadow: inset 0 0 0 1px var(--input-border-focus);
}
.wi-choice:has(input:focus-visible) {
outline: 2px solid var(--input-border-focus);
outline-offset: 2px;
}
.wi-choice:has(input:disabled) {
opacity: 0.5;
cursor: not-allowed;
}

@media (max-width: 768px) {
.wi-choice {
flex-wrap: wrap;
align-items: flex-start;
}
.wi-choice__aside {
flex: 1 0 100%;
margin-left: 0;
padding-left: calc(18px + var(--spacer) * 3);
box-sizing: border-box;
text-align: left;
}
}
```

- [ ] **Step 5: scrivi `steps.css`**

`lib/src/build/frontend/css/components/steps.css`:

```css
.wi-steps {
display: flex;
flex-wrap: nowrap;
align-items: center;
gap: calc(var(--spacer) * 2);
margin: 0;
padding: 0;
list-style: none;
font-size: var(--text-small-font-size);
line-height: var(--text-small-line-height);
white-space: nowrap;
overflow-x: auto;
scrollbar-width: none;
}
.wi-steps::-webkit-scrollbar {
display: none;
}
.wi-steps__item {
display: flex;
flex-shrink: 0;
align-items: center;
gap: calc(var(--spacer) * 2);
}
.wi-steps__item + .wi-steps__item::before {
content: "\203A";
font-weight: 400;
opacity: 0.5;
}
.wi-steps__item a {
color: inherit;
text-decoration: none;
}
.wi-steps__item a:hover {
text-decoration: underline;
}
.wi-steps__item[aria-current="step"] {
font-weight: 600;
}
.wi-steps__item.is-disabled {
opacity: 0.5;
}
```

- [ ] **Step 6: scrivi `thumb.css`**

`lib/src/build/frontend/css/components/thumb.css`:

```css
.wi-thumb {
--wi-thumb-size: 64px;
position: relative;
flex-shrink: 0;
width: var(--wi-thumb-size);
height: var(--wi-thumb-size);
box-sizing: border-box;
background: #f5f5f5;
border-color: var(--dropdown-border-color);
border-style: solid;
border-width: var(--dropdown-border-width);
border-radius: var(--button-border-radius);
}
.wi-thumb > img {
display: block;
width: 100%;
height: 100%;
object-fit: cover;
border-radius: inherit;
}
.wi-thumb > .badge {
position: absolute;
top: 0;
right: 0;
z-index: 1;
float: none;
transform: translate(40%, -40%);
}
```

- [ ] **Step 7: importa i tre file in `head.js`**

In `lib/src/export/frontend/head.js`, subito dopo `import '/src/build/frontend/css/components/spinner.css';`:

```js
import '/src/build/frontend/css/components/choice.css';
import '/src/build/frontend/css/components/steps.css';
import '/src/build/frontend/css/components/thumb.css';
```

- [ ] **Step 8: registra i tre componenti nel MANIFEST**

In `lib/MANIFEST.json`, subito dopo la voce `"spinner": { … },`, con lo stesso rientro di 4 spazi:

```json
    "choice": {
      "file": "src/build/frontend/css/components/choice.css",
      "classes": [".wi-choice", ".wi-choice__body", ".wi-choice__title", ".wi-choice__text", ".wi-choice__aside", ".wi-choice-group", ".wi-choice-group__legend", ".wi-choice-group__list"],
      "states": [":has(input:checked)", ":has(input:focus-visible)", ":has(input:disabled)"],
      "markup": "label.wi-choice > input[type=radio|checkbox] + .wi-choice__body(.wi-choice__title, .wi-choice__text) + .wi-choice__aside; fieldset.wi-choice-group > legend.wi-choice-group__legend + .wi-choice-group__list"
    },
    "steps": {
      "file": "src/build/frontend/css/components/steps.css",
      "classes": [".wi-steps", ".wi-steps__item"],
      "states": ["[aria-current=\"step\"]", ".is-done", ".is-disabled"],
      "markup": "nav > ol.wi-steps > li.wi-steps__item (done: a[href]; current: aria-current=step; todo: .is-disabled)"
    },
    "thumb": {
      "file": "src/build/frontend/css/components/thumb.css",
      "classes": [".wi-thumb"],
      "tokens": ["--wi-thumb-size"],
      "markup": ".wi-thumb > img + .badge (quantity, top right)"
    },
```

- [ ] **Step 9: lancia il test e guardalo passare**

Run: `cd /Users/andreamarinoni/Developer/packages/lib && node test/checkout-components.test.cjs && node test/manifest.test.cjs`
Expected: `checkout-components: ok` e `manifest: ok`.

- [ ] **Step 10: build di controllo, senza toccare `dist/`**

Run: `cd /Users/andreamarinoni/Developer/packages/lib && OUT="$(mktemp -d)" && npx webpack --output-path "$OUT" >/dev/null && grep -rl "wi-choice" "$OUT" | head -3 && grep -rl "wi-steps" "$OUT" | head -1 && grep -rl "wi-thumb" "$OUT" | head -1 && git status --short dist | wc -l`
Expected: almeno un file per ognuna delle tre classi, e `0` come ultima riga (`dist/` intatto).

- [ ] **Step 11: lancia tutta la suite**

Run: `cd /Users/andreamarinoni/Developer/packages/lib && npm test 2>&1 | tail -3`
Expected: l'ultima riga è `checkout-components: ok`, senza `AssertionError`.

- [ ] **Step 12: commit**

```bash
cd /Users/andreamarinoni/Developer/packages/lib && git add src/build/frontend/css/components/choice.css src/build/frontend/css/components/steps.css src/build/frontend/css/components/thumb.css src/export/frontend/head.js MANIFEST.json test/checkout-components.test.cjs package.json && git commit -m "Componenti wi-choice, wi-steps e wi-thumb per il checkout a passi

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: lib — documentazione e CHANGELOG

**Files:**
- Modify: `lib/docs/styles/components.md` (nuove sezioni dopo «## Spinner»)
- Modify: `lib/CHANGELOG.md` (`## Unreleased` → `### Added`)
- Modify: `lib/test/checkout-components.test.cjs` (controllo della documentazione)

**Interfaces:**
- Consumes: le classi del Task 1.
- Produces: nessuna interfaccia di codice.

- [ ] **Step 1: aggiungi al test il controllo della documentazione**

In `lib/test/checkout-components.test.cjs`, prima di `console.log('checkout-components: ok');`:

```js
const docs = read('docs/styles/components.md');
for (const heading of ['## Choice', '## Steps', '## Thumb']) {
    assert.ok(docs.includes(heading), 'docs/styles/components.md senza ' + heading);
}
for (const classes of Object.values(expected)) {
    for (const name of classes) {
        assert.ok(docs.includes('`' + name), 'docs/styles/components.md senza ' + name);
    }
}
assert.ok(read('CHANGELOG.md').includes('`.wi-choice`'), 'CHANGELOG senza .wi-choice');
```

- [ ] **Step 2: lancia il test e guardalo fallire**

Run: `cd /Users/andreamarinoni/Developer/packages/lib && node test/checkout-components.test.cjs`
Expected: FAIL con `docs/styles/components.md senza ## Choice`.

- [ ] **Step 3: scrivi la documentazione**

In `lib/docs/styles/components.md`, subito prima di `## Tooltip`, aggiungi:

````markdown
## Choice

Source: `components/choice.css`. A selectable box for a radio or a checkbox: shipping methods, pickup locations, payment methods.

| Class | Use |
|---|---|
| `.wi-choice` | The `<label>` box that wraps the `input` and the content |
| `.wi-choice__body` | Column with title and text, takes the free width |
| `.wi-choice__title` | Bold first line |
| `.wi-choice__text` | Small secondary line |
| `.wi-choice__aside` | Right column (price, fee); goes under the title on phones |
| `.wi-choice-group` | `fieldset` that holds a list of choices |
| `.wi-choice-group__legend` | Group title |
| `.wi-choice-group__list` | Stacked choices with `gap-2` |

States: `:has(input:checked)` dark border (`--input-border-focus`), `:has(input:focus-visible)` focus ring, `:has(input:disabled)` dimmed. Empty parts carry `hidden`.

```html
<fieldset class="wi-choice-group">
  <legend class="wi-choice-group__legend">Shipping method</legend>
  <div class="wi-choice-group__list">
    <label class="wi-choice">
      <input type="radio" name="shipping_method_id" value="1" checked>
      <span class="wi-choice__body">
        <span class="wi-choice__title">Courier</span>
        <span class="wi-choice__text">2-3 working days</span>
      </span>
      <span class="wi-choice__aside">4,90 EUR</span>
    </label>
  </div>
</fieldset>
```

## Steps

Source: `components/steps.css`. A one-line path of steps separated by a chevron; it scrolls sideways when it does not fit.

| Class | Use |
|---|---|
| `.wi-steps` | The `<ol>` |
| `.wi-steps__item` | One step; a done step holds an `<a>` |
| `.wi-steps__item[aria-current="step"]` | Current step, bold |
| `.wi-steps__item.is-disabled` | Future step, dimmed and not a link |

```html
<nav aria-label="Checkout">
  <ol class="wi-steps">
    <li class="wi-steps__item is-done"><a href="/cart/">Cart</a></li>
    <li class="wi-steps__item" aria-current="step">Shipping</li>
    <li class="wi-steps__item is-disabled" aria-disabled="true">Payment</li>
  </ol>
</nav>
```

## Thumb

Source: `components/thumb.css`. A square product thumbnail with an optional `.badge` on the top-right corner (quantity).

| Class | Use |
|---|---|
| `.wi-thumb` | Square box, size from `--wi-thumb-size` (64px) |
| `.wi-thumb > img` | Cover image with the box radius |
| `.wi-thumb > .badge` | Badge pinned to the top-right corner |

```html
<span class="wi-thumb" style="--wi-thumb-size: 56px">
  <img src="/img/product.jpg" alt="">
  <span class="badge badge-dark">2</span>
</span>
```
````

In `lib/CHANGELOG.md`, sotto `## Unreleased` → `### Added`, come prime voci:

```markdown
- `.wi-choice` and `.wi-choice-group` (`components/choice.css`): selectable
  boxes for radios and checkboxes, with title, text and an aside column;
  checked, focus and disabled states via `:has()`. See
  `docs/styles/components.md`.
- `.wi-steps` (`components/steps.css`): one-line path of steps with a
  chevron separator, `aria-current="step"` for the current one.
- `.wi-thumb` (`components/thumb.css`): square thumbnail with a corner
  `.badge`, size from `--wi-thumb-size`.
```

- [ ] **Step 4: lancia il test e guardalo passare**

Run: `cd /Users/andreamarinoni/Developer/packages/lib && npm test 2>&1 | tail -2`
Expected: l'ultima riga è `checkout-components: ok`.

- [ ] **Step 5: commit**

```bash
cd /Users/andreamarinoni/Developer/packages/lib && git add docs/styles/components.md CHANGELOG.md test/checkout-components.test.cjs && git commit -m "Documentazione di wi-choice, wi-steps e wi-thumb

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: app — trait `MergesClasses`

**Files:**
- Create: `app/class/Themes/Concerns/MergesClasses.php`
- Test: `app/tests/Themes/ChoiceTest.php` (il file nasce qui; il Task 4 lo allarga)

**Interfaces:**
- Consumes: nessuna.
- Produces: il trait `Wonder\Themes\Concerns\MergesClasses` con il metodo
  `protected function mergeClasses(array $base, array $attributes): array`.
  - Restituisce le classi base seguite da quelle di `$attributes['class']`, senza vuoti e senza doppioni.
  - `$attributes['class']` può essere una stringa con le classi separate da spazi oppure un array.

- [ ] **Step 1: crea il ramo**

```bash
cd /Users/andreamarinoni/Developer/packages/app && git switch -c checkout-a-passi-componenti
```

Expected: `Switched to a new branch 'checkout-a-passi-componenti'`.

- [ ] **Step 2: scrivi il test che fallisce**

`app/tests/Themes/ChoiceTest.php`:

```php
<?php
/** php tests/Themes/ChoiceTest.php */
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../harness.php';

use Wonder\Themes\Concerns\MergesClasses;

$merge = new class {
    use MergesClasses;

    public function run(array $base, array $attributes): array
    {
        return $this->mergeClasses($base, $attributes);
    }
};

check('le classi date si aggiungono a quelle base, senza vuoti né doppioni', fn () =>
    $merge->run(['wi-choice'], ['class' => ['mt-2', 'wi-choice', '']]) === ['wi-choice', 'mt-2']
    && $merge->run(['card'], ['class' => ' a  b ']) === ['card', 'a', 'b']
    && $merge->run(['wi-steps'], []) === ['wi-steps']
);

summary();
```

- [ ] **Step 3: lancia il test e guardalo fallire**

Run: `cd /Users/andreamarinoni/Developer/packages/app && php tests/Themes/ChoiceTest.php`
Expected: errore fatale `Trait "Wonder\Themes\Concerns\MergesClasses" not found`.

- [ ] **Step 4: scrivi il trait**

`app/class/Themes/Concerns/MergesClasses.php`:

```php
<?php

namespace Wonder\Themes\Concerns;

trait MergesClasses
{
    /**
     * Le classi del componente prima, poi quelle date con class()/addClass().
     *
     * @param string[] $base
     * @return string[]
     */
    protected function mergeClasses(array $base, array $attributes): array
    {
        $extra = $attributes['class'] ?? [];
        $extra = is_array($extra) ? $extra : explode(' ', (string) $extra);
        $classes = array_map(static fn ($class): string => trim((string) $class), [...$base, ...$extra]);

        return array_values(array_unique(array_filter($classes, static fn (string $class): bool => $class !== '')));
    }
}
```

- [ ] **Step 5: lancia il test e guardalo passare**

Run: `cd /Users/andreamarinoni/Developer/packages/app && php tests/Themes/ChoiceTest.php`
Expected: `1 test, 0 falliti`.

- [ ] **Step 6: commit**

```bash
cd /Users/andreamarinoni/Developer/packages/app && git add class/Themes/Concerns/MergesClasses.php tests/Themes/ChoiceTest.php && git commit -m "MergesClasses: classi base e classi date in un solo punto per i renderer

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: app — `Choice` e `ChoiceGroup` nei due temi

**Files:**
- Create: `app/class/Elements/Components/Choice.php`
- Create: `app/class/Elements/Components/ChoiceGroup.php`
- Create: `app/class/Themes/Wonder/Components/Choice.php`
- Create: `app/class/Themes/Wonder/Components/ChoiceGroup.php`
- Create: `app/class/Themes/Bootstrap/Components/Choice.php`
- Create: `app/class/Themes/Bootstrap/Components/ChoiceGroup.php`
- Test: `app/tests/Themes/ChoiceTest.php`

**Interfaces:**
- Consumes:
  - `MergesClasses::mergeClasses(array $base, array $attributes): array` (Task 3);
  - le classi `.wi-choice*` e `.wi-choice-group*` (Task 1).
- Produces, usate dalle viste e da `checkout.js` nel piano 2:
  - `Choice::make(string $name, string|int $value): Choice`, con `type(string $type)` (`'radio'`, il default, o `'checkbox'`; altri valori diventano `'radio'`), `title(string)`, `text(string)`, `aside(string)`, `checked(bool $checked = true)` e `disabled(bool $disabled = true)`. Ogni metodo restituisce `Choice`.
  - Da `Component` arrivano anche `attr()`, `class()` e `addClass()`. Gli attributi vanno sul `<label>` esterno.
  - `ChoiceGroup::make(string $legend = ''): ChoiceGroup`, con `choices(Choice ...$choices)`. Gli attributi vanno sul `<fieldset>`.
  - Markup Wonder:
    - `label.wi-choice > input[data-choice-input]`;
    - poi `span.wi-choice__body` con `span.wi-choice__title[data-choice-title]` e `span.wi-choice__text[data-choice-text]`;
    - poi `span.wi-choice__aside[data-choice-aside]`;
    - le parti vuote hanno `hidden`.
  - `ChoiceGroup` in Wonder: `fieldset.wi-choice-group > legend.wi-choice-group__legend + div.wi-choice-group__list[data-choice-list]`.
  - Markup Bootstrap, con gli stessi attributi `data-choice-*`:
    - `label.card > span.card-body.d-flex > span.form-check > input.form-check-input`;
    - le tre parti dentro la `card-body`;
    - `ChoiceGroup`: `fieldset > legend.form-label + div.d-grid.gap-2[data-choice-list]`.

- [ ] **Step 1: scrivi i test che falliscono**

In `app/tests/Themes/ChoiceTest.php`:
- aggiungi `use Wonder\Elements\Components\Choice;` e `use Wonder\Elements\Components\ChoiceGroup;` sotto l'altro `use`;
- prima di `summary();` aggiungi:

```php
$corriere = static fn (): Choice => Choice::make('shipping_method_id', 3)
    ->title('Rossi & <Figli>')
    ->text('2-3 giorni "lavorativi"')
    ->aside('4,90 €');

foreach (['wonder', 'bootstrap'] as $theme) {
    check("{$theme}: il Choice è un label con l'input radio, nome e valore", function () use ($corriere, $theme) {
        $html = $corriere()->render($theme);

        return str_starts_with($html, '<label ')
            && str_contains($html, 'type="radio"')
            && str_contains($html, 'name="shipping_method_id"')
            && str_contains($html, 'value="3"')
            && str_contains($html, 'data-choice-input')
            && !str_contains($html, ' checked')
            && !str_contains($html, ' disabled');
    });

    check("{$theme}: titolo, testo e aside escono escapati nelle tre parti", function () use ($corriere, $theme) {
        $html = $corriere()->render($theme);

        return str_contains($html, 'data-choice-title>Rossi &amp; &lt;Figli&gt;</span>')
            && str_contains($html, 'data-choice-text>2-3 giorni &quot;lavorativi&quot;</span>')
            && str_contains($html, 'data-choice-aside>4,90 €</span>')
            && !str_contains($html, '<Figli>');
    });

    check("{$theme}: checked, disabled e checkbox arrivano sull'input", function () use ($theme) {
        $html = Choice::make('terms', 'yes')->type('checkbox')->title('Accetto')->checked()->disabled()->render($theme);

        return str_contains($html, 'type="checkbox"')
            && str_contains($html, ' checked')
            && str_contains($html, ' disabled')
            && Choice::make('x', 1)->type('select')->getSchema('type') === 'radio';
    });

    check("{$theme}: le parti vuote ci sono lo stesso, nascoste", function () use ($theme) {
        $html = Choice::make('location_id', 1)->title('Sede')->render($theme);

        return str_contains($html, 'data-choice-text hidden></span>')
            && str_contains($html, 'data-choice-aside hidden></span>')
            && str_contains($html, 'data-choice-title>Sede</span>');
    });

    check("{$theme}: classi e attributi dati vanno sul label", function () use ($corriere, $theme) {
        $html = $corriere()->class('mt-2')->attr('data-x', 'a"b')->render($theme);
        $base = $theme === 'wonder' ? 'wi-choice' : 'card';

        return str_starts_with($html, '<label class="'.$base.' mt-2')
            && str_contains($html, 'data-x="a&quot;b"');
    });

    check("{$theme}: il gruppo è un fieldset con la legend escapata e i Choice nella lista", function () use ($corriere, $theme) {
        $html = ChoiceGroup::make('Metodo <di> spedizione')
            ->choices($corriere(), Choice::make('shipping_method_id', 4)->title('Posta')->checked())
            ->attr('data-checkout-shipping-methods', true)
            ->render($theme);

        return str_starts_with($html, '<fieldset ')
            && str_contains($html, 'data-checkout-shipping-methods')
            && str_contains($html, 'Metodo &lt;di&gt; spedizione</legend>')
            && str_contains($html, 'data-choice-list>')
            && substr_count($html, '<label ') === 2
            && substr_count($html, ' checked') === 1
            && str_ends_with($html, '</div></fieldset>');
    });

    check("{$theme}: senza legend non c'è una legend vuota", fn () =>
        !str_contains(ChoiceGroup::make()->choices(Choice::make('a', 1)->title('A'))->render($theme), '<legend')
    );
}

check('wonder: le classi della lib', function () use ($corriere) {
    $choice = $corriere()->render('wonder');
    $group = ChoiceGroup::make('Metodo')->choices($corriere())->render('wonder');

    return str_contains($choice, '<span class="wi-choice__body">')
        && str_contains($choice, 'class="wi-choice__title"')
        && str_contains($choice, 'class="wi-choice__text"')
        && str_contains($choice, 'class="wi-choice__aside"')
        && str_contains($group, 'class="wi-choice-group"')
        && str_contains($group, '<legend class="wi-choice-group__legend">')
        && str_contains($group, 'class="wi-choice-group__list"');
});

check('bootstrap: form-check dentro una card', function () use ($corriere) {
    $html = $corriere()->render('bootstrap');

    return str_contains($html, '<span class="card-body d-flex align-items-start gap-3">')
        && str_contains($html, '<span class="form-check m-0">')
        && str_contains($html, 'class="form-check-input"');
});
```

- [ ] **Step 2: lancia i test e guardali fallire**

Run: `cd /Users/andreamarinoni/Developer/packages/app && php tests/Themes/ChoiceTest.php`
Expected: FAIL. Le nuove `check` stampano `✗ … Class "Wonder\Elements\Components\Choice" not found`, e la riga finale riporta falliti diversi da 0.

- [ ] **Step 3: scrivi gli Element**

`app/class/Elements/Components/Choice.php`:

```php
<?php

namespace Wonder\Elements\Components;

use Wonder\Elements\Component;
use Wonder\Elements\Concerns\Renderer;

/**
 * Un radio o un checkbox dentro un riquadro cliccabile: titolo, testo
 * sotto e una colonna a destra per il prezzo.
 */
class Choice extends Component
{
    use Renderer;

    public function __construct(string $name, string|int $value)
    {
        $this->schema('name', $name)
            ->schema('value', (string) $value)
            ->schema('type', 'radio')
            ->schema('title', '')
            ->schema('text', '')
            ->schema('aside', '')
            ->schema('checked', false)
            ->schema('disabled', false);
    }

    public static function make(string $name, string|int $value): self
    {
        return new self($name, $value);
    }

    public function type(string $type): self
    {
        return $this->schema('type', $type === 'checkbox' ? 'checkbox' : 'radio');
    }

    public function title(string $title): self
    {
        return $this->schema('title', $title);
    }

    public function text(string $text): self
    {
        return $this->schema('text', $text);
    }

    public function aside(string $aside): self
    {
        return $this->schema('aside', $aside);
    }

    public function checked(bool $checked = true): self
    {
        return $this->schema('checked', $checked);
    }

    public function disabled(bool $disabled = true): self
    {
        return $this->schema('disabled', $disabled);
    }
}
```

`app/class/Elements/Components/ChoiceGroup.php`:

```php
<?php

namespace Wonder\Elements\Components;

use Wonder\Elements\Component;
use Wonder\Elements\Concerns\Renderer;

class ChoiceGroup extends Component
{
    use Renderer;

    public function __construct(string $legend = '')
    {
        $this->schema('legend', $legend)->schema('choices', []);
    }

    public static function make(string $legend = ''): self
    {
        return new self($legend);
    }

    public function choices(Choice ...$choices): self
    {
        return $this->schema('choices', $choices);
    }
}
```

- [ ] **Step 4: scrivi i renderer Wonder**

`app/class/Themes/Wonder/Components/Choice.php`:

```php
<?php

namespace Wonder\Themes\Wonder\Components;

use Wonder\Themes\Concerns\MergesClasses;
use Wonder\Themes\Wonder\Component;

class Choice extends Component
{
    use MergesClasses;

    public function render($class): string
    {
        $schema = $class->getSchema();
        $attributes = is_array($schema['attributes'] ?? null) ? $schema['attributes'] : [];
        $attributes['class'] = $this->mergeClasses(['wi-choice'], $attributes);

        $input = $this->renderAttributes([
            'type' => (string) $schema['type'],
            'name' => (string) $schema['name'],
            'value' => (string) $schema['value'],
            'checked' => (bool) $schema['checked'],
            'disabled' => (bool) $schema['disabled'],
            'data-choice-input' => true,
        ]);

        return '<label '.$this->renderAttributes($attributes).'>'
            .'<input '.$input.'>'
            .'<span class="wi-choice__body">'
            .$this->part('wi-choice__title', 'data-choice-title', (string) $schema['title'])
            .$this->part('wi-choice__text', 'data-choice-text', (string) $schema['text'])
            .'</span>'
            .$this->part('wi-choice__aside', 'data-choice-aside', (string) $schema['aside'])
            .'</label>';
    }

    private function part(string $class, string $hook, string $value): string
    {
        return '<span class="'.$class.'" '.$hook.($value === '' ? ' hidden' : '').'>'.$this->escape($value).'</span>';
    }
}
```

`app/class/Themes/Wonder/Components/ChoiceGroup.php`:

```php
<?php

namespace Wonder\Themes\Wonder\Components;

use Wonder\Themes\Concerns\MergesClasses;
use Wonder\Themes\Wonder\Component;

class ChoiceGroup extends Component
{
    use MergesClasses;

    public function render($class): string
    {
        $schema = $class->getSchema();
        $attributes = is_array($schema['attributes'] ?? null) ? $schema['attributes'] : [];
        $attributes['class'] = $this->mergeClasses(['wi-choice-group'], $attributes);
        $legend = (string) ($schema['legend'] ?? '');

        return '<fieldset '.$this->renderAttributes($attributes).'>'
            .($legend !== '' ? '<legend class="wi-choice-group__legend">'.$this->escape($legend).'</legend>' : '')
            .'<div class="wi-choice-group__list" data-choice-list>'
            .$this->renderComponents($schema['choices'] ?? [])
            .'</div></fieldset>';
    }
}
```

- [ ] **Step 5: scrivi i renderer Bootstrap**

`app/class/Themes/Bootstrap/Components/Choice.php`:

```php
<?php

namespace Wonder\Themes\Bootstrap\Components;

use Wonder\Themes\Bootstrap\Component;
use Wonder\Themes\Concerns\HasAttributes;
use Wonder\Themes\Concerns\MergesClasses;

class Choice extends Component
{
    use HasAttributes, MergesClasses;

    public function render($class): string
    {
        $schema = $class->getSchema();
        $attributes = is_array($schema['attributes'] ?? null) ? $schema['attributes'] : [];
        $attributes['class'] = $this->mergeClasses(['card'], $attributes);

        $input = $this->renderAttributes([
            'class' => 'form-check-input',
            'type' => (string) $schema['type'],
            'name' => (string) $schema['name'],
            'value' => (string) $schema['value'],
            'checked' => (bool) $schema['checked'],
            'disabled' => (bool) $schema['disabled'],
            'data-choice-input' => true,
        ]);

        return '<label '.$this->renderAttributes($attributes).'>'
            .'<span class="card-body d-flex align-items-start gap-3">'
            .'<span class="form-check m-0"><input '.$input.'></span>'
            .'<span class="flex-grow-1">'
            .$this->part('d-block fw-semibold', 'data-choice-title', (string) $schema['title'])
            .$this->part('d-block small text-body-secondary', 'data-choice-text', (string) $schema['text'])
            .'</span>'
            .$this->part('text-nowrap fw-semibold', 'data-choice-aside', (string) $schema['aside'])
            .'</span></label>';
    }

    private function part(string $class, string $hook, string $value): string
    {
        return '<span class="'.$class.'" '.$hook.($value === '' ? ' hidden' : '').'>'.$this->escape($value).'</span>';
    }
}
```

`app/class/Themes/Bootstrap/Components/ChoiceGroup.php`:

```php
<?php

namespace Wonder\Themes\Bootstrap\Components;

use Wonder\Themes\Bootstrap\Component;
use Wonder\Themes\Concerns\HasAttributes;
use Wonder\Themes\Concerns\MergesClasses;

class ChoiceGroup extends Component
{
    use HasAttributes, MergesClasses;

    public function render($class): string
    {
        $schema = $class->getSchema();
        $attributes = is_array($schema['attributes'] ?? null) ? $schema['attributes'] : [];
        $attributes['class'] = $this->mergeClasses(['border-0', 'p-0', 'm-0'], $attributes);
        $legend = (string) ($schema['legend'] ?? '');

        return '<fieldset '.$this->renderAttributes($attributes).'>'
            .($legend !== '' ? '<legend class="form-label fw-semibold fs-6">'.$this->escape($legend).'</legend>' : '')
            .'<div class="d-grid gap-2" data-choice-list>'
            .$this->renderComponents($schema['choices'] ?? [])
            .'</div></fieldset>';
    }
}
```

- [ ] **Step 6: lancia i test e guardali passare**

Run: `cd /Users/andreamarinoni/Developer/packages/app && php tests/Themes/ChoiceTest.php`
Expected: `17 test, 0 falliti`, cioè 1 del Task 3, 7 per ognuno dei due temi e i 2 specifici.

- [ ] **Step 7: controlla che i test vicini restino verdi**

Run: `cd /Users/andreamarinoni/Developer/packages/app && for f in tests/Themes/ModalTest.php tests/Themes/RadioPillsTest.php tests/Themes/OptionVisualTest.php; do php "$f" | tail -1; done`
Expected: tre righe `N test, 0 falliti`.

- [ ] **Step 8: commit**

```bash
cd /Users/andreamarinoni/Developer/packages/app && git add class/Elements/Components/Choice.php class/Elements/Components/ChoiceGroup.php class/Themes/Wonder/Components/Choice.php class/Themes/Wonder/Components/ChoiceGroup.php class/Themes/Bootstrap/Components/Choice.php class/Themes/Bootstrap/Components/ChoiceGroup.php tests/Themes/ChoiceTest.php && git commit -m "Choice e ChoiceGroup: riquadri selezionabili nei temi Wonder e Bootstrap

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: app — `Steps` nei due temi

**Files:**
- Create: `app/class/Elements/Components/Steps.php`
- Create: `app/class/Themes/Wonder/Components/Steps.php`
- Create: `app/class/Themes/Bootstrap/Components/Steps.php`
- Test: `app/tests/Themes/StepsTest.php`

**Interfaces:**
- Consumes:
  - `MergesClasses::mergeClasses()` (Task 3);
  - `.wi-steps` e `.wi-steps__item` (Task 1).
- Produces, usate dal layout `ecommerce.checkout` nel piano 2:
  - `Steps::make(string $label = ''): Steps`, dove `$label` diventa l'`aria-label` del `<nav>`;
  - `step(string $label, ?string $href = null, string $state = 'todo'): Steps`. `$state` vale `done`, `current` o `todo`; ogni altro valore diventa `todo`. Un `href` vuoto vale `null`.
  - Gli attributi di `attr()`, `class()` e `addClass()` vanno sull'`<ol>`.
  - Markup Wonder:
    - `nav > ol.wi-steps`;
    - `li.wi-steps__item.is-done` con `<a href>` se c'è un href, altrimenti solo testo;
    - `li.wi-steps__item[aria-current="step"]` per il passo in corso;
    - `li.wi-steps__item.is-disabled[aria-disabled="true"]` per i passi da fare.
  - Markup Bootstrap:
    - `nav > ol.breadcrumb.mb-0`;
    - `li.breadcrumb-item` per i passi fatti;
    - `li.breadcrumb-item.active[aria-current="step"]` per il passo in corso;
    - `li.breadcrumb-item.text-body-tertiary[aria-disabled="true"]` per i passi da fare.

- [ ] **Step 1: scrivi il test che fallisce**

`app/tests/Themes/StepsTest.php`:

```php
<?php
/** php tests/Themes/StepsTest.php */
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../harness.php';

use Wonder\Elements\Components\Steps;

$percorso = static fn (): Steps => Steps::make('Passi <checkout>')
    ->step('Carrello', '/cart/', 'done')
    ->step('Spedizione & ritiro', '/checkout/', 'current')
    ->step('Pagamento', '/checkout/payment/');

foreach (['wonder', 'bootstrap'] as $theme) {
    check("{$theme}: nav con aria-label escapata e un elenco ordinato", function () use ($percorso, $theme) {
        $html = $percorso()->render($theme);

        return str_starts_with($html, '<nav aria-label="Passi &lt;checkout&gt;"><ol ')
            && str_ends_with($html, '</ol></nav>')
            && substr_count($html, '<li ') === 3;
    });

    check("{$theme}: link solo sul passo fatto", function () use ($percorso, $theme) {
        $html = $percorso()->render($theme);

        return substr_count($html, '<a ') === 1
            && str_contains($html, '<a href="/cart/">Carrello</a>')
            && !str_contains($html, 'href="/checkout/"')
            && !str_contains($html, 'href="/checkout/payment/"');
    });

    check("{$theme}: aria-current solo sul passo in corso, testo escapato", function () use ($percorso, $theme) {
        $html = $percorso()->render($theme);

        return substr_count($html, 'aria-current="step"') === 1
            && preg_match('/aria-current="step">Spedizione &amp; ritiro<\/li>/', $html) === 1;
    });

    check("{$theme}: il passo da fare è spento e non cliccabile", fn () =>
        preg_match('/aria-disabled="true">Pagamento<\/li>/', $percorso()->render($theme)) === 1
    );

    check("{$theme}: stato sconosciuto vale todo, done senza href è solo testo", function () use ($theme) {
        $html = Steps::make()->step('Uno', null, 'done')->step('Due', '/due/', 'active')->render($theme);

        return !str_contains($html, '<a ')
            && !str_contains($html, 'aria-label')
            && preg_match('/aria-disabled="true">Due<\/li>/', $html) === 1
            && str_contains($html, '>Uno</li>');
    });

    check("{$theme}: classi e attributi dati vanno sull'ol", function () use ($percorso, $theme) {
        $html = $percorso()->class('mb-4')->attr('data-x', '1')->render($theme);
        $base = $theme === 'wonder' ? 'wi-steps' : 'breadcrumb mb-0';

        return str_contains($html, '<ol class="'.$base.' mb-4" data-x="1">');
    });
}

check('wonder: le classi della lib', function () use ($percorso) {
    $html = $percorso()->render('wonder');

    return str_contains($html, '<li class="wi-steps__item is-done"><a href="/cart/">')
        && str_contains($html, '<li class="wi-steps__item" aria-current="step">')
        && str_contains($html, '<li class="wi-steps__item is-disabled" aria-disabled="true">');
});

check('bootstrap: il breadcrumb', function () use ($percorso) {
    $html = $percorso()->render('bootstrap');

    return str_contains($html, '<li class="breadcrumb-item"><a href="/cart/">')
        && str_contains($html, '<li class="breadcrumb-item active" aria-current="step">')
        && str_contains($html, '<li class="breadcrumb-item text-body-tertiary" aria-disabled="true">');
});

summary();
```

- [ ] **Step 2: lancia il test e guardalo fallire**

Run: `cd /Users/andreamarinoni/Developer/packages/app && php tests/Themes/StepsTest.php`
Expected: FAIL, con `✗ … Class "Wonder\Elements\Components\Steps" not found` e la riga finale `14 test, 14 falliti`.

- [ ] **Step 3: scrivi l'Element**

`app/class/Elements/Components/Steps.php`:

```php
<?php

namespace Wonder\Elements\Components;

use Wonder\Elements\Component;
use Wonder\Elements\Concerns\Renderer;

/**
 * Un percorso a passi (Carrello › Spedizione › Pagamento): i passi fatti
 * sono link, quello in corso è evidenziato, i successivi sono spenti.
 */
class Steps extends Component
{
    use Renderer;

    public const STATES = ['done', 'current', 'todo'];

    public function __construct(string $label = '')
    {
        $this->schema('label', $label)->schema('steps', []);
    }

    public static function make(string $label = ''): self
    {
        return new self($label);
    }

    public function step(string $label, ?string $href = null, string $state = 'todo'): self
    {
        $href = $href !== null && trim($href) !== '' ? $href : null;

        return $this->schemaPush('steps', [
            'label' => $label,
            'href' => $href,
            'state' => in_array($state, self::STATES, true) ? $state : 'todo',
        ]);
    }
}
```

- [ ] **Step 4: scrivi i renderer**

`app/class/Themes/Wonder/Components/Steps.php`:

```php
<?php

namespace Wonder\Themes\Wonder\Components;

use Wonder\Themes\Concerns\MergesClasses;
use Wonder\Themes\Wonder\Component;

class Steps extends Component
{
    use MergesClasses;

    public function render($class): string
    {
        $schema = $class->getSchema();
        $attributes = is_array($schema['attributes'] ?? null) ? $schema['attributes'] : [];
        $attributes['class'] = $this->mergeClasses(['wi-steps'], $attributes);
        $label = (string) ($schema['label'] ?? '');
        $items = '';

        foreach ($schema['steps'] ?? [] as $step) {
            $text = $this->escape((string) $step['label']);

            $items .= match ($step['state']) {
                'done' => '<li class="wi-steps__item is-done">'
                    .($step['href'] !== null ? '<a href="'.$this->escape($step['href']).'">'.$text.'</a>' : $text)
                    .'</li>',
                'current' => '<li class="wi-steps__item" aria-current="step">'.$text.'</li>',
                default => '<li class="wi-steps__item is-disabled" aria-disabled="true">'.$text.'</li>',
            };
        }

        return '<nav'.($label !== '' ? ' aria-label="'.$this->escape($label).'"' : '').'>'
            .'<ol '.$this->renderAttributes($attributes).'>'.$items.'</ol></nav>';
    }
}
```

`app/class/Themes/Bootstrap/Components/Steps.php`:

```php
<?php

namespace Wonder\Themes\Bootstrap\Components;

use Wonder\Themes\Bootstrap\Component;
use Wonder\Themes\Concerns\HasAttributes;
use Wonder\Themes\Concerns\MergesClasses;

class Steps extends Component
{
    use HasAttributes, MergesClasses;

    public function render($class): string
    {
        $schema = $class->getSchema();
        $attributes = is_array($schema['attributes'] ?? null) ? $schema['attributes'] : [];
        $attributes['class'] = $this->mergeClasses(['breadcrumb', 'mb-0'], $attributes);
        $label = (string) ($schema['label'] ?? '');
        $items = '';

        foreach ($schema['steps'] ?? [] as $step) {
            $text = $this->escape((string) $step['label']);

            $items .= match ($step['state']) {
                'done' => '<li class="breadcrumb-item">'
                    .($step['href'] !== null ? '<a href="'.$this->escape($step['href']).'">'.$text.'</a>' : $text)
                    .'</li>',
                'current' => '<li class="breadcrumb-item active" aria-current="step">'.$text.'</li>',
                default => '<li class="breadcrumb-item text-body-tertiary" aria-disabled="true">'.$text.'</li>',
            };
        }

        return '<nav'.($label !== '' ? ' aria-label="'.$this->escape($label).'"' : '').'>'
            .'<ol '.$this->renderAttributes($attributes).'>'.$items.'</ol></nav>';
    }
}
```

- [ ] **Step 5: lancia il test e guardalo passare**

Run: `cd /Users/andreamarinoni/Developer/packages/app && php tests/Themes/StepsTest.php`
Expected: `14 test, 0 falliti`.

- [ ] **Step 6: commit**

```bash
cd /Users/andreamarinoni/Developer/packages/app && git add class/Elements/Components/Steps.php class/Themes/Wonder/Components/Steps.php class/Themes/Bootstrap/Components/Steps.php tests/Themes/StepsTest.php && git commit -m "Steps: percorso a passi con link sui passi fatti, nei temi Wonder e Bootstrap

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: app — documentazione e CHANGELOG

**Files:**
- Modify: `app/docs/app/concetti/componenti/README.md`: tabella «Dove si trova nel codice» e una sezione nuova prima di «## Layout dei media»
- Modify: `app/CHANGELOG.md` (`## Unreleased` → `### Added`)
- Modify: `app/tests/Themes/StepsTest.php`: controllo della documentazione

**Interfaces:**
- Consumes: le API dei Task 4 e 5.
- Produces: nessuna interfaccia di codice.

- [ ] **Step 1: aggiungi il controllo della documentazione al test**

In `app/tests/Themes/StepsTest.php`, prima di `summary();`:

```php
check('la guida dei componenti e il CHANGELOG raccontano Choice, ChoiceGroup e Steps', function () {
    $root = dirname(__DIR__, 2);
    $docs = (string) file_get_contents($root.'/docs/app/concetti/componenti/README.md');
    $changelog = (string) file_get_contents($root.'/CHANGELOG.md');

    return str_contains($docs, '| `Choice` | `Elements/Components/Choice.php`')
        && str_contains($docs, '| `ChoiceGroup` | `Elements/Components/ChoiceGroup.php`')
        && str_contains($docs, '| `Steps` | `Elements/Components/Steps.php`')
        && str_contains($docs, '## Choice, ChoiceGroup e Steps')
        && str_contains($docs, 'data-choice-list')
        && str_contains($changelog, '`Choice`')
        && str_contains($changelog, '`Steps`');
});
```

- [ ] **Step 2: lancia il test e guardalo fallire**

Run: `cd /Users/andreamarinoni/Developer/packages/app && php tests/Themes/StepsTest.php | tail -2`
Expected: `✗ la guida dei componenti e il CHANGELOG raccontano Choice, ChoiceGroup e Steps`, poi `15 test, 1 falliti`.

- [ ] **Step 3: scrivi la documentazione**

In `app/docs/app/concetti/componenti/README.md`, nella tabella «Dove si trova nel codice», subito dopo la riga di `Dropdown`:

```markdown
| `Choice` | `Elements/Components/Choice.php` | radio o checkbox in un riquadro cliccabile, con titolo, testo e prezzo |
| `ChoiceGroup` | `Elements/Components/ChoiceGroup.php` | `fieldset` con legend e una lista di `Choice` |
| `Steps` | `Elements/Components/Steps.php` | percorso a passi (fatto, in corso, da fare) |
```

Subito prima di `## Layout dei media`:

````markdown
## Choice, ChoiceGroup e Steps

Nati per il checkout a passi del modulo ecommerce, servono a qualunque scelta fra
poche opzioni da raccontare: metodi di spedizione, sedi, metodi di pagamento.

```php
use Wonder\Elements\Components\Choice;
use Wonder\Elements\Components\ChoiceGroup;
use Wonder\Elements\Components\Steps;

ChoiceGroup::make('Metodo di spedizione')
    ->attr('data-checkout-shipping-methods', true)
    ->choices(
        Choice::make('shipping_method_id', 1)->title('Corriere')->text('2-3 giorni')->aside('4,90 €')->checked(),
        Choice::make('shipping_method_id', 2)->title('Posta')->aside('2,50 €'),
    );

Steps::make('Checkout')
    ->step('Carrello', '/cart/', 'done')
    ->step('Spedizione', '/checkout/', 'current')
    ->step('Pagamento');
```

- `Choice::type('checkbox')` cambia il tipo dell'input; il default è `radio`.
  `checked()` e `disabled()` vanno sull'input. `attr()`, `class()` e `addClass()`
  vanno sul `<label>` esterno.
- Titolo, testo e aside si escapano. Una parte vuota si stampa lo stesso con
  `hidden`: un `<template>` reso da `Choice` ha sempre tutte le parti, e il JS lo
  clona e lo riempie con `textContent` senza ricostruire il markup.
- **Ganci uguali nei due temi**:
  - `data-choice-input` sull'input;
  - `data-choice-title`, `data-choice-text` e `data-choice-aside` sulle parti;
  - `data-choice-list` sul contenitore dei `Choice` dentro `ChoiceGroup`.

  Il JS sostituisce i figli di `data-choice-list`, non quelli del `fieldset`, così la `legend` resta.
- **`Steps::step($label, $href, $state)`**:
  - `$state` vale `done`, `current` o `todo`; ogni altro valore vale `todo`;
  - solo un passo `done` con un `href` diventa un link;
  - `current` ha `aria-current="step"`;
  - `todo` ha `aria-disabled="true"` e non è cliccabile.

  L'etichetta di `make()` diventa l'`aria-label` del `<nav>`.
- **Tema `wonder`**: usa `.wi-choice`, `.wi-choice-group` e `.wi-steps` della lib, che bisogna aggiornare insieme.
- **Tema `bootstrap`**: rende un `form-check` dentro una `card` e un `breadcrumb`.
````

In `app/CHANGELOG.md`, sotto `## Unreleased` → `### Added`, come prime voci:

```markdown
- `Choice` e `ChoiceGroup`: un radio o un checkbox in un riquadro cliccabile,
  con titolo, testo e una colonna per il prezzo, e il `fieldset` che li
  raccoglie. Testi escapati, parti vuote stampate con `hidden` e ganci
  `data-choice-*` uguali nei temi Wonder (`.wi-choice` della lib) e Bootstrap
  (`form-check` in una `card`).
- `Steps`: percorso a passi con link solo sui passi fatti, `aria-current="step"`
  sul passo in corso e passi da fare spenti; `.wi-steps` della lib nel tema
  Wonder, `breadcrumb` in Bootstrap.
- `Wonder\Themes\Concerns\MergesClasses`: unisce le classi base di un renderer a
  quelle date con `class()`/`addClass()`.
```

- [ ] **Step 4: lancia i test e guardali passare**

Run: `cd /Users/andreamarinoni/Developer/packages/app && php tests/Themes/StepsTest.php | tail -1 && php tests/Themes/ChoiceTest.php | tail -1`
Expected: `15 test, 0 falliti` e `17 test, 0 falliti`.

- [ ] **Step 5: commit**

```bash
cd /Users/andreamarinoni/Developer/packages/app && git add docs/app/concetti/componenti/README.md CHANGELOG.md tests/Themes/StepsTest.php && git commit -m "Guida e CHANGELOG di Choice, ChoiceGroup e Steps

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Dopo il piano

- **Rami**: lib e app restano sui rami `checkout-a-passi-componenti`. Push, PR e merge solo con la conferma dell'utente.
- **Piano 2** (Passi) parte da questi componenti. Per vederli su `ecommerce.test` il sito deve ricevere la lib nuova: va deciso nel piano 2 se lanciare `npm run release` con l'aggiornamento di `extra.wonder.lib`, oppure usare un collegamento locale.
