# Checkout a pagina unica — Piano 1: Componenti e font

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** dare a `Choice` e `ChoiceGroup` quello che serve alla pagina unica (segmenti, lista unita, icona, loghi, pannello) e aggiungere ad `app` il catalogo di 9 font web.

**Architecture:** tre task indipendenti su due repository già sul branch `checkout-a-passi-componenti` (piano 1 della spec a passi, non ancora unito). In `app` le nuove opzioni vanno nello schema degli Element e i renderer Wonder e Bootstrap le stampano con ganci `data-choice-*`; in `lib` il CSS di `choice.css` le disegna. `Wonder\View\WebFonts` è un catalogo statico che restituisce `@font-face` e variabili CSS per i file woff2 di `resources/assets/font/web/`.

**Tech Stack:** PHP 8.2 (prove con `tests/harness.php`), CSS della lib (prove `node test/*.test.cjs`), file woff2 di Fontsource.

**Spec:** `gestionale/docs/superpowers/specs/2026-10-07-checkout-pagina-unica-design.md` (§8 Componenti, §12 Prove). Le parti già fatte sono in `gestionale/docs/superpowers/plans/2026-10-06-checkout-a-passi-1-componenti.md`.

## Global Constraints

- Percorsi: `app` = `/Users/andreamarinoni/Developer/packages/app`, `lib` = `/Users/andreamarinoni/Developer/packages/lib`. Tutti e due sul branch `checkout-a-passi-componenti`: verificarlo con `git branch --show-current` prima di ogni commit.
- Testi, commenti, commit in italiano; commit che finiscono con `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Niente push.
- I test di `app` in `tests/` sono ignorati da git: aggiungerli con `git add -f`.
- Titolo, testo, aside, panel e `alt` si escapano; nessun HTML grezzo nei parametri.
- Ogni parte che il JS può riempire si stampa anche vuota, con `hidden` (`data-choice-icons`, `data-choice-panel`), come titolo, testo e aside.
- I ganci `data-choice-*` sono uguali nei due temi.
- `choice.css` contiene solo caratteri ASCII (lo controlla il test della lib).
- Nessun `render('wonder')` forzato nelle viste; nei test si passa il tema esplicito.
- Font: solo file nostri (niente Google Fonts), licenza OFL accanto a ogni famiglia.
- Non si ricostruisce né si rilascia la `dist` della lib in questo piano.

## Review Focus

1. **Un `<template>` dentro la lista** (il checkout clona i `Choice` da template): angoli e bordi uniti devono restare giusti → il CSS usa `:first-of-type` / `:last-of-type`, non `:first-child` (Task 2, controllato dal test).
2. **Un solo segmento o un solo metodo**: deve avere tutti e quattro gli angoli arrotondati → regole di angolo scritte per lati (Task 2).
3. **Icone senza `src` o con virgolette nel percorso**: le voci vuote si saltano, i percorsi si escapano (Task 1, test).
4. **Nome di icona Bootstrap sporco** (`'shop"><script>'`): resta solo `[a-z0-9-]` (Task 1, test). Nel tema Bootstrap le parti vuote con `hidden` devono sparire davvero: niente utility `d-*` `!important` su di loro (Task 1, test).
5. **Font chiamato con chiave sconosciuta o con spazi/maiuscole**: sconosciuta → `''`; `' Inter '` vale `inter` (Task 3, test).

---

### Task 1: `Choice` e `ChoiceGroup` in app (icona, loghi, pannello, varianti)

**Files:**
- Modify: `app/class/Elements/Components/Choice.php`
- Modify: `app/class/Elements/Components/ChoiceGroup.php`
- Modify: `app/class/Themes/Wonder/Components/Choice.php`
- Modify: `app/class/Themes/Wonder/Components/ChoiceGroup.php`
- Modify: `app/class/Themes/Bootstrap/Components/Choice.php`
- Modify: `app/class/Themes/Bootstrap/Components/ChoiceGroup.php`
- Modify: `app/docs/app/concetti/componenti/README.md` (sezione «Choice, ChoiceGroup e Steps»)
- Modify: `app/CHANGELOG.md` (`## Unreleased` → `### Added`)
- Test: `app/tests/Themes/ChoiceTest.php`

**Interfaces:**
- Consumes: nulla di nuovo.
- Produces (usati dal piano 2):
  - `Choice::icon(string $icon): self` — `truck` o `bi-truck`; schema `icon` senza il prefisso `bi-`.
  - `Choice::icons(array $icons, int $max = 3): self` — voci `['src' => string, 'alt' => string]`; schema `icons` (voci senza `src` tolte) e `icons_max` (minimo 1).
  - `Choice::panel(string $text): self` — schema `panel`.
  - `ChoiceGroup::variant(string $variant): self` — `'segmented'`, `'list'` o `''`; schema `variant`. Costante `ChoiceGroup::VARIANTS = ['segmented', 'list']`.
  - Markup Wonder: `<i class="wi-choice__icon bi bi-{icon}" aria-hidden="true"></i>` dopo l'input; `<span class="wi-choice__icons" data-choice-icons>` con `<img>` 38×24 e `<span class="wi-choice__more">+N</span>`, fra body e aside; `<span class="wi-choice__panel" data-choice-panel>` ultimo figlio del `label`; fieldset `wi-choice-group wi-choice-group--{variant}`.
  - Markup Bootstrap: lista `btn-group w-100` (segmented), `list-group` (list), `d-grid gap-2` (nessuna); pannello `<span class="card-footer small" style="display:block" data-choice-panel>` dopo `card-body`; loghi in `<span class="align-items-center gap-1" style="display:flex" data-choice-icons>`. Niente `d-block`/`d-flex`: sono `!important` e vincerebbero su `[hidden]`.

- [ ] **Step 1: Scrivere i test che falliscono**

In `app/tests/Themes/ChoiceTest.php`, dentro il `foreach (['wonder', 'bootstrap'] as $theme)`, dopo il check «id() arriva sul fieldset…», aggiungere:

```php
    check("{$theme}: icon() stampa l'icona di Bootstrap Icons, ripulita", function () use ($theme) {
        $html = Choice::make('fulfillment_type', 'pickup')->icon('bi-shop')->title('Ritiro')->render($theme);
        $dirty = Choice::make('a', 1)->icon('Shop"><script>')->render($theme);

        return str_contains($html, 'bi bi-shop"')
            && str_contains($html, 'aria-hidden="true"')
            && str_contains($dirty, 'bi bi-shopscript"')
            && !str_contains($dirty, '<script>')
            && !str_contains(Choice::make('a', 1)->title('A')->render($theme), 'bi bi-');
    });

    check("{$theme}: icons() mostra al massimo tre loghi e poi +N", function () use ($theme) {
        $logo = static fn (string $name): array => ['src' => "/icons/{$name}.svg", 'alt' => ucfirst($name)];
        $html = Choice::make('payment_method_id', 1)->title('Carta')
            ->icons([$logo('visa'), $logo('master'), ['alt' => 'senza src'], $logo('maestro'), $logo('amex'), $logo('gpay')])
            ->render($theme);

        return substr_count($html, '<img ') === 3
            && str_contains($html, 'src="/icons/visa.svg"')
            && str_contains($html, 'alt="Visa"')
            && !str_contains($html, 'amex.svg')
            && str_contains($html, '>+2</span>')
            && str_contains($html, 'data-choice-icons>');
    });

    check("{$theme}: icons() escapa src e alt e rispetta un massimo diverso", function () use ($theme) {
        $html = Choice::make('p', 1)->icons([['src' => '/a"b.svg', 'alt' => '<x>'], ['src' => '/c.svg']], 1)->render($theme);

        return str_contains($html, 'src="/a&quot;b.svg"')
            && str_contains($html, 'alt="&lt;x&gt;"')
            && substr_count($html, '<img ') === 1
            && str_contains($html, '>+1</span>')
            && Choice::make('p', 1)->icons([], 0)->getSchema('icons_max') === 1;
    });

    check("{$theme}: senza loghi né pannello le parti ci sono, nascoste", function () use ($theme) {
        $html = Choice::make('p', 1)->title('Bonifico')->render($theme);

        return str_contains($html, 'data-choice-icons hidden></span>')
            && str_contains($html, 'data-choice-panel hidden></span>');
    });

    check("{$theme}: panel() esce escapato, dopo l'aside e prima della chiusura", function () use ($theme) {
        $html = Choice::make('p', 1)->title('PayPal')->aside('1,00 €')
            ->icons([['src' => '/p.svg', 'alt' => 'PayPal']])
            ->panel('Verrai <reindirizzato> a "PayPal"')
            ->render($theme);
        $panel = strpos($html, 'data-choice-panel');

        return str_contains($html, 'data-choice-panel>Verrai &lt;reindirizzato&gt; a &quot;PayPal&quot;</span>')
            && strpos($html, 'data-choice-icons') < strpos($html, 'data-choice-aside')
            && strpos($html, 'data-choice-aside') < $panel
            && str_ends_with($html, '</span></label>');
    });

    check("{$theme}: variant() accetta solo segmented e list", fn () =>
        ChoiceGroup::make()->variant(' LIST ')->getSchema('variant') === 'list'
        && ChoiceGroup::make()->variant('segmented')->getSchema('variant') === 'segmented'
        && ChoiceGroup::make()->variant('boh')->getSchema('variant') === ''
        && ChoiceGroup::make()->getSchema('variant') === ''
    );
```

Dopo il check `'wonder: le classi della lib'` aggiungere:

```php
check('wonder: varianti, icona, loghi e pannello con le classi della lib', function () {
    $choice = Choice::make('p', 1)->icon('truck')->title('Spedisci')
        ->icons([['src' => '/a.svg'], ['src' => '/b.svg']], 1)->panel('Istruzioni')->render('wonder');
    $segmented = ChoiceGroup::make()->variant('segmented')->choices(Choice::make('a', 1))->render('wonder');
    $list = ChoiceGroup::make()->variant('list')->choices(Choice::make('a', 1))->render('wonder');
    $plain = ChoiceGroup::make()->choices(Choice::make('a', 1))->render('wonder');

    return str_contains($choice, '<i class="wi-choice__icon bi bi-truck" aria-hidden="true"></i><span class="wi-choice__body">')
        && str_contains($choice, '<span class="wi-choice__icons" data-choice-icons>')
        && str_contains($choice, '<span class="wi-choice__more">+1</span>')
        && str_contains($choice, '<span class="wi-choice__panel" data-choice-panel>Istruzioni</span></label>')
        && str_contains($choice, 'width="38" height="24"')
        && str_contains($segmented, 'class="wi-choice-group wi-choice-group--segmented"')
        && str_contains($list, 'class="wi-choice-group wi-choice-group--list"')
        && !str_contains($plain, 'wi-choice-group--');
});
```

Dopo il check `'bootstrap: form-check dentro una card'` aggiungere:

```php
check('bootstrap: btn-group per i segmenti, list-group per la lista, card-footer per il pannello', function () {
    $group = static fn (string $variant): string => ChoiceGroup::make()->variant($variant)->choices(Choice::make('a', 1))->render('bootstrap');
    $choice = Choice::make('p', 1)->title('Bonifico')->panel('IBAN')->render('bootstrap');

    return str_contains($group('segmented'), '<div class="btn-group w-100" data-choice-list>')
        && str_contains($group('list'), '<div class="list-group" data-choice-list>')
        && str_contains($group(''), '<div class="d-grid gap-2" data-choice-list>')
        && str_contains($choice, '</span><span class="card-footer small" style="display:block" data-choice-panel>IBAN</span></label>')
        && str_contains(Choice::make('p', 1)->render('bootstrap'), '<span class="card-footer small" style="display:block" data-choice-panel hidden></span>')
        && str_contains(Choice::make('p', 1)->render('bootstrap'), '<span class="align-items-center gap-1" style="display:flex" data-choice-icons hidden></span>');
});
```

- [ ] **Step 2: Lanciare i test e vederli fallire**

Run: `cd /Users/andreamarinoni/Developer/packages/app && php tests/Themes/ChoiceTest.php`
Expected: FAIL — i check nuovi con `✗` e «Call to undefined method …::icon()» / «…::variant()»; i check vecchi restano `✓`.

- [ ] **Step 3: Element `Choice` e `ChoiceGroup`**

In `app/class/Elements/Components/Choice.php`, nel costruttore aggiungere dopo `->schema('disabled', false)`:

```php
            ->schema('disabled', false)
            ->schema('icon', '')
            ->schema('icons', [])
            ->schema('icons_max', 3)
            ->schema('panel', '');
```

(togliendo il `;` dalla riga `->schema('disabled', false)` di prima) e in fondo alla classe:

```php
    /**
     * Icona di Bootstrap Icons prima del titolo, per i segmenti: `truck`
     * oppure `bi-truck`. Restano solo lettere minuscole, cifre e trattini.
     */
    public function icon(string $icon): self
    {
        $icon = (string) preg_replace('/[^a-z0-9-]/', '', strtolower(trim($icon)));

        return $this->schema('icon', (string) preg_replace('/^bi-/', '', $icon));
    }

    /**
     * Loghi a destra del titolo (carte, wallet): ogni voce è
     * `['src' => …, 'alt' => …]`, le voci senza `src` si saltano. Oltre
     * `$max` loghi resta un «+N».
     *
     * @param array<int, array{src?: string, alt?: string}> $icons
     */
    public function icons(array $icons, int $max = 3): self
    {
        $list = [];

        foreach ($icons as $icon) {
            $src = is_array($icon) ? trim((string) ($icon['src'] ?? '')) : '';

            if ($src !== '') {
                $list[] = ['src' => $src, 'alt' => trim((string) ($icon['alt'] ?? ''))];
            }
        }

        return $this->schema('icons', $list)->schema('icons_max', max(1, $max));
    }

    /**
     * Testo di un riquadro sotto il Choice, visibile solo quando l'input è
     * scelto (istruzioni del bonifico, «verrai reindirizzato a…»).
     */
    public function panel(string $text): self
    {
        return $this->schema('panel', $text);
    }
```

In `app/class/Elements/Components/ChoiceGroup.php`:

```php
class ChoiceGroup extends Component
{
    use Renderer;

    /** `segmented`: scelte in riga, unite; `list`: scelte impilate, unite. */
    public const VARIANTS = ['segmented', 'list'];

    public function __construct(string $legend = '')
    {
        $this->schema('legend', $legend)->schema('choices', [])->schema('variant', '');
    }

    public static function make(string $legend = ''): self
    {
        return new self($legend);
    }

    public function choices(Choice ...$choices): self
    {
        return $this->schema('choices', $choices);
    }

    public function variant(string $variant): self
    {
        $variant = strtolower(trim($variant));

        return $this->schema('variant', in_array($variant, self::VARIANTS, true) ? $variant : '');
    }
}
```

- [ ] **Step 4: Renderer Wonder**

`app/class/Themes/Wonder/Components/Choice.php`, metodo `render` (il resto invariato):

```php
    public function render($class): string
    {
        $schema = $class->getSchema();
        $icon = (string) ($schema['icon'] ?? '');

        $input = $this->renderAttributes([
            'type' => (string) $schema['type'],
            'name' => (string) $schema['name'],
            'value' => (string) $schema['value'],
            'checked' => (bool) $schema['checked'],
            'disabled' => (bool) $schema['disabled'],
            'data-choice-input' => true,
        ]);

        return '<label '.$this->renderComponentAttributes($class, ['wi-choice']).'>'
            .'<input '.$input.'>'
            .($icon !== '' ? '<i class="wi-choice__icon bi bi-'.$this->escape($icon).'" aria-hidden="true"></i>' : '')
            .'<span class="wi-choice__body">'
            .$this->part('wi-choice__title', 'data-choice-title', (string) $schema['title'])
            .$this->part('wi-choice__text', 'data-choice-text', (string) $schema['text'])
            .'</span>'
            .$this->icons((array) ($schema['icons'] ?? []), (int) ($schema['icons_max'] ?? 3))
            .$this->part('wi-choice__aside', 'data-choice-aside', (string) $schema['aside'])
            .$this->part('wi-choice__panel', 'data-choice-panel', (string) ($schema['panel'] ?? ''))
            .'</label>';
    }

    /** @param array<int, array{src: string, alt: string}> $icons */
    private function icons(array $icons, int $max): string
    {
        $html = '';

        foreach (array_slice($icons, 0, $max) as $icon) {
            $html .= '<img '.$this->renderAttributes([
                'src' => $icon['src'],
                'alt' => $icon['alt'],
                'width' => '38',
                'height' => '24',
                'loading' => 'lazy',
            ]).'>';
        }

        if (count($icons) > $max) {
            $html .= '<span class="wi-choice__more">+'.(count($icons) - $max).'</span>';
        }

        return '<span class="wi-choice__icons" data-choice-icons'.($html === '' ? ' hidden' : '').'>'.$html.'</span>';
    }
```

Se `renderAttributes` stampa `alt=""` per un `alt` vuoto va bene; se lo salta, va bene lo stesso (nessun test lo chiede).

`app/class/Themes/Wonder/Components/ChoiceGroup.php`, metodo `render`:

```php
    public function render($class): string
    {
        $schema = $class->getSchema();
        $legend = (string) ($schema['legend'] ?? '');
        $variant = (string) ($schema['variant'] ?? '');
        $classes = ['wi-choice-group'];

        if ($variant !== '') {
            $classes[] = 'wi-choice-group--'.$variant;
        }

        return '<fieldset '.$this->renderComponentAttributes($class, $classes).'>'
            .($legend !== '' ? '<legend class="wi-choice-group__legend">'.$this->escape($legend).'</legend>' : '')
            .'<div class="wi-choice-group__list" data-choice-list>'
            .$this->renderComponents($schema['choices'] ?? [])
            .'</div></fieldset>';
    }
```

- [ ] **Step 5: Renderer Bootstrap**

`app/class/Themes/Bootstrap/Components/Choice.php`, metodo `render` e nuovo metodo `icons`:

```php
    public function render($class): string
    {
        $schema = $class->getSchema();
        $icon = (string) ($schema['icon'] ?? '');

        $input = $this->renderAttributes([
            'class' => 'form-check-input',
            'type' => (string) $schema['type'],
            'name' => (string) $schema['name'],
            'value' => (string) $schema['value'],
            'checked' => (bool) $schema['checked'],
            'disabled' => (bool) $schema['disabled'],
            'data-choice-input' => true,
        ]);

        return '<label '.$this->renderComponentAttributes($class, ['card', 'user-select-none']).'>'
            .'<span class="card-body d-flex align-items-start gap-3">'
            .'<span class="form-check m-0"><input '.$input.'></span>'
            .($icon !== '' ? '<i class="bi bi-'.$this->escape($icon).'" aria-hidden="true"></i>' : '')
            .'<span class="flex-grow-1">'
            .$this->part('d-block fw-semibold', 'data-choice-title', (string) $schema['title'])
            .$this->part('d-block small text-body-secondary', 'data-choice-text', (string) $schema['text'])
            .'</span>'
            .$this->icons((array) ($schema['icons'] ?? []), (int) ($schema['icons_max'] ?? 3))
            .$this->part('text-nowrap fw-semibold', 'data-choice-aside', (string) $schema['aside'])
            .'</span>'
            .$this->panel((string) ($schema['panel'] ?? ''))
            .'</label>';
    }

    /** @param array<int, array{src: string, alt: string}> $icons */
    private function icons(array $icons, int $max): string
    {
        $html = '';

        foreach (array_slice($icons, 0, $max) as $icon) {
            $html .= '<img '.$this->renderAttributes([
                'src' => $icon['src'],
                'alt' => $icon['alt'],
                'width' => '38',
                'height' => '24',
                'loading' => 'lazy',
            ]).'>';
        }

        if (count($icons) > $max) {
            $html .= '<span class="small text-body-secondary">+'.(count($icons) - $max).'</span>';
        }

        return '<span class="align-items-center gap-1" style="display:flex" data-choice-icons'.($html === '' ? ' hidden' : '').'>'.$html.'</span>';
    }

    /** `style` e non `d-block`: le utility di Bootstrap sono `!important` e batterebbero `[hidden]`. */
    private function panel(string $text): string
    {
        return '<span class="card-footer small" style="display:block" data-choice-panel'.($text === '' ? ' hidden' : '').'>'
            .$this->escape($text).'</span>';
    }
```

Nel tema Bootstrap il pannello è sempre visibile quando c'è testo (niente `:has()`): va scritto nella guida.

`app/class/Themes/Bootstrap/Components/ChoiceGroup.php`, metodo `render`:

```php
    public function render($class): string
    {
        $schema = $class->getSchema();
        $legend = (string) ($schema['legend'] ?? '');
        $list = match ((string) ($schema['variant'] ?? '')) {
            'segmented' => 'btn-group w-100',
            'list' => 'list-group',
            default => 'd-grid gap-2',
        };

        return '<fieldset '.$this->renderComponentAttributes($class, ['border-0', 'p-0', 'm-0']).'>'
            .($legend !== '' ? '<legend class="form-label fw-semibold fs-6">'.$this->escape($legend).'</legend>' : '')
            .'<div class="'.$list.'" data-choice-list>'
            .$this->renderComponents($schema['choices'] ?? [])
            .'</div></fieldset>';
    }
```

- [ ] **Step 6: Lanciare i test e vederli passare**

Run: `cd /Users/andreamarinoni/Developer/packages/app && php tests/Themes/ChoiceTest.php`
Expected: tutti `✓`, ultima riga `32 test, 0 falliti` (oggi sono 18; i nuovi sono 6 per tema ×2 + 2).

Poi la suite dei temi, per vedere che il resto non si è rotto:

Run: `cd /Users/andreamarinoni/Developer/packages/app && for f in tests/Themes/*.php; do php "$f" | tail -1; done`
Expected: ogni riga `… 0 falliti`.

- [ ] **Step 7: Guida e CHANGELOG**

In `app/docs/app/concetti/componenti/README.md`, nella sezione `## Choice, ChoiceGroup e Steps`, dopo l'elenco puntato esistente aggiungere:

```markdown
- **Pagina unica del checkout**:
  - `ChoiceGroup::variant('segmented')` mette le scelte in riga, unite (Spedisci / Ritiro);
    `variant('list')` le impila unite, con i bordi in comune (metodi di spedizione e di
    pagamento). Altri valori valgono come nessuna variante.
  - `Choice::icon('truck')` (o `'bi-truck'`) stampa un'icona di Bootstrap Icons prima del
    titolo.
  - `Choice::icons([['src' => …, 'alt' => …], …], 3)` stampa i loghi a destra; oltre il
    massimo resta «+N». Gancio `data-choice-icons`, vuoto con `hidden`.
  - `Choice::panel($testo)` aggiunge un riquadro sotto la scelta, visibile solo quando
    l'input è scelto (nel tema Wonder, con `:has(input:checked)`); nel tema Bootstrap è
    sempre visibile. Gancio `data-choice-panel`, vuoto con `hidden`.

```php
ChoiceGroup::make('Pagamento')
    ->variant('list')
    ->choices(
        Choice::make('payment_method_id', 1)->title('Carta')
            ->icons([['src' => $visa, 'alt' => 'Visa'], ['src' => $master, 'alt' => 'Mastercard']])
            ->panel('Dopo aver cliccato «Paga ora» verrai reindirizzato a Stripe.'),
        Choice::make('payment_method_id', 2)->title('Bonifico')->panel($istruzioni),
    );
```
```

In `app/CHANGELOG.md`, sotto `## Unreleased` → `### Added`, dopo la voce di `Choice` e `ChoiceGroup`:

```markdown
- `ChoiceGroup::variant('segmented'|'list')` e, su `Choice`, `icon()`, `icons()` con
  «+N» oltre il massimo e `panel()` visibile solo sulla scelta fatta: servono alla
  pagina unica del checkout. Ganci `data-choice-icons` e `data-choice-panel` nei due temi.
```

- [ ] **Step 8: Commit**

```bash
cd /Users/andreamarinoni/Developer/packages/app && git branch --show-current
git add class/Elements/Components/Choice.php class/Elements/Components/ChoiceGroup.php class/Themes/Wonder/Components/Choice.php class/Themes/Wonder/Components/ChoiceGroup.php class/Themes/Bootstrap/Components/Choice.php class/Themes/Bootstrap/Components/ChoiceGroup.php docs/app/concetti/componenti/README.md CHANGELOG.md
git add -f tests/Themes/ChoiceTest.php
git commit -m "Choice e ChoiceGroup: segmenti, lista unita, icona, loghi con +N e pannello della scelta

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

Expected: il branch stampato è `checkout-a-passi-componenti`; un commit con 9 file.

---

### Task 2: CSS di `wi-choice` in lib (varianti, icona, loghi, pannello)

**Files:**
- Modify: `lib/src/build/frontend/css/components/choice.css`
- Modify: `lib/MANIFEST.json` (`components.choice`)
- Modify: `lib/docs/styles/components.md` (sezione `## Choice`)
- Modify: `lib/CHANGELOG.md`
- Test: `lib/test/checkout-components.test.cjs`

**Interfaces:**
- Consumes: il markup del Task 1 (classi `wi-choice__icon`, `wi-choice__icons`, `wi-choice__more`, `wi-choice__panel`, `wi-choice-group--segmented`, `wi-choice-group--list`).
- Produces: le stesse classi disegnate dal bundle frontend.

- [ ] **Step 1: Scrivere il test che fallisce**

In `lib/test/checkout-components.test.cjs`, sostituire la riga `choice:` di `expected` con:

```js
    choice: ['.wi-choice', '.wi-choice__body', '.wi-choice__title', '.wi-choice__text', '.wi-choice__aside', '.wi-choice-group', '.wi-choice-group__legend', '.wi-choice-group__list', '.wi-choice__icon', '.wi-choice__icons', '.wi-choice__more', '.wi-choice__panel', '.wi-choice-group--segmented', '.wi-choice-group--list'],
```

e dopo la riga `assert.ok(rule(choice, '.wi-choice__body').includes('flex: 1 1 0;'), …);` aggiungere:

```js
assert.ok(rule(choice, '.wi-choice').includes('flex-wrap: wrap;'), 'il pannello di .wi-choice va a capo sotto la riga');
assert.ok(rule(choice, '.wi-choice__panel').includes('display: none;'), 'il pannello è nascosto finché la scelta non è fatta');
assert.ok(rule(choice, '.wi-choice:has(input:checked) > .wi-choice__panel').includes('display: block;'), 'il pannello si apre sulla scelta fatta');
assert.ok(rule(choice, '.wi-choice__icons img').includes('float: none;'), 'i loghi non flottano (section img del reset)');
assert.ok(rule(choice, '.wi-choice-group--segmented .wi-choice-group__list').includes('grid-auto-flow: column;'), 'i segmenti stanno in riga');
assert.ok(rule(choice, '.wi-choice-group--segmented .wi-choice > input').includes('opacity: 0;'), 'il radio dei segmenti è nascosto ma resta raggiungibile');
assert.ok(rule(choice, '.wi-choice-group--list .wi-choice-group__list').includes('gap: 0;'), 'le scelte della lista sono unite');
for (const corner of ['.wi-choice-group--list .wi-choice:first-of-type', '.wi-choice-group--list .wi-choice:last-of-type', '.wi-choice-group--segmented .wi-choice:first-of-type', '.wi-choice-group--segmented .wi-choice:last-of-type']) {
    assert.ok(rule(choice, corner).includes('radius'), corner + ' senza angoli');
}
assert.ok(!/:(first|last)-child/.test(choice), 'choice.css usa :first-of-type / :last-of-type: un <template> nella lista non deve spostare gli angoli');
```

- [ ] **Step 2: Lanciare il test e vederlo fallire**

Run: `cd /Users/andreamarinoni/Developer/packages/lib && node test/checkout-components.test.cjs`
Expected: FAIL con `AssertionError` su `MANIFEST.json` (`deepEqual` delle classi di `choice`).

- [ ] **Step 3: Scrivere il CSS**

In `choice.css`, nella regola `.wi-choice {` aggiungere `flex-wrap: wrap;` subito dopo `display: flex;`.

Prima del blocco `@media (max-width: 768px) {` aggiungere (solo ASCII):

```css
.wi-choice__icon {
flex-shrink: 0;
font-size: 1.25em;
line-height: 1;
}
.wi-choice__icons {
display: flex;
flex-shrink: 0;
align-items: center;
gap: 4px;
margin-left: auto;
}
.wi-choice__icons img {
display: block;
float: none;
width: 38px;
height: 24px;
}
.wi-choice__icons:not([hidden]) + .wi-choice__aside {
margin-left: 0;
}
.wi-choice__more {
font-size: var(--text-small-font-size);
line-height: var(--text-small-line-height);
opacity: 0.7;
}
.wi-choice__panel {
display: none;
flex: 0 0 calc(100% + 32px);
margin: 0 -16px -16px;
padding: 16px;
box-sizing: border-box;
background: rgba(0, 0, 0, 0.04);
border-top: var(--dropdown-border-width) solid var(--dropdown-border-color);
border-radius: 0 0 var(--button-border-radius) var(--button-border-radius);
font-size: var(--text-small-font-size);
line-height: var(--text-small-line-height);
white-space: pre-line;
}
.wi-choice:has(input:checked) > .wi-choice__panel {
display: block;
}

.wi-choice-group--segmented .wi-choice-group__list {
grid-auto-flow: column;
grid-auto-columns: 1fr;
gap: 0;
}
.wi-choice-group--segmented .wi-choice {
position: relative;
align-items: center;
justify-content: center;
border-radius: 0;
}
.wi-choice-group--segmented .wi-choice + .wi-choice {
margin-left: calc(var(--dropdown-border-width) * -1);
}
.wi-choice-group--segmented .wi-choice:first-of-type {
border-top-left-radius: var(--button-border-radius);
border-bottom-left-radius: var(--button-border-radius);
}
.wi-choice-group--segmented .wi-choice:last-of-type {
border-top-right-radius: var(--button-border-radius);
border-bottom-right-radius: var(--button-border-radius);
}
.wi-choice-group--segmented .wi-choice > input {
position: absolute;
width: 1px;
height: 1px;
opacity: 0;
pointer-events: none;
}
.wi-choice-group--segmented .wi-choice__body {
flex: 0 1 auto;
}

.wi-choice-group--list .wi-choice-group__list {
gap: 0;
}
.wi-choice-group--list .wi-choice {
position: relative;
border-radius: 0;
}
.wi-choice-group--list .wi-choice + .wi-choice {
margin-top: calc(var(--dropdown-border-width) * -1);
}
.wi-choice-group--list .wi-choice:first-of-type {
border-top-left-radius: var(--button-border-radius);
border-top-right-radius: var(--button-border-radius);
}
.wi-choice-group--list .wi-choice:last-of-type {
border-bottom-left-radius: var(--button-border-radius);
border-bottom-right-radius: var(--button-border-radius);
}
.wi-choice-group--list .wi-choice:not(:last-of-type) > .wi-choice__panel {
border-radius: 0;
}

.wi-choice-group--segmented .wi-choice:has(input:checked),
.wi-choice-group--list .wi-choice:has(input:checked) {
z-index: 1;
}
```

Nota: `rule()` del test cerca un selettore a inizio riga seguito da ` {`; le regole controllate sono tutte a selettore singolo.

- [ ] **Step 4: MANIFEST**

In `lib/MANIFEST.json`, `components.choice`:

```json
      "classes": [".wi-choice", ".wi-choice__body", ".wi-choice__title", ".wi-choice__text", ".wi-choice__aside", ".wi-choice-group", ".wi-choice-group__legend", ".wi-choice-group__list", ".wi-choice__icon", ".wi-choice__icons", ".wi-choice__more", ".wi-choice__panel", ".wi-choice-group--segmented", ".wi-choice-group--list"],
      "states": [":has(input:checked)", ":has(input:focus-visible)", ":has(input:disabled)"],
      "markup": "label.wi-choice > input[type=radio|checkbox] + i.wi-choice__icon? + .wi-choice__body(.wi-choice__title, .wi-choice__text) + .wi-choice__icons(img, .wi-choice__more) + .wi-choice__aside + .wi-choice__panel; fieldset.wi-choice-group(--segmented|--list) > legend.wi-choice-group__legend + .wi-choice-group__list"
```

- [ ] **Step 5: Documentazione e CHANGELOG**

In `lib/docs/styles/components.md`, nella tabella di `## Choice` aggiungere dopo `.wi-choice-group__list`:

```markdown
| `.wi-choice__icon` | Bootstrap Icons glyph before the title (`bi bi-truck`) |
| `.wi-choice__icons` | Row of payment logos (38x24 `img`) on the right |
| `.wi-choice__more` | The "+N" after the last shown logo |
| `.wi-choice__panel` | Grey box under the row, shown only when the input is checked |
| `.wi-choice-group--segmented` | Choices side by side and joined; the input is hidden, the checked one gets the dark border |
| `.wi-choice-group--list` | Choices stacked and joined with shared borders; rounded corners only at the top and bottom |
```

e dopo il primo esempio HTML della sezione:

````markdown
Joined list with logos and a panel (payment methods):

```html
<fieldset class="wi-choice-group wi-choice-group--list">
  <div class="wi-choice-group__list">
    <label class="wi-choice">
      <input type="radio" name="payment_method_id" value="1" checked>
      <span class="wi-choice__body"><span class="wi-choice__title">Card</span></span>
      <span class="wi-choice__icons"><img src="visa.svg" alt="Visa" width="38" height="24"><span class="wi-choice__more">+2</span></span>
      <span class="wi-choice__panel">You will be redirected to Stripe.</span>
    </label>
  </div>
</fieldset>
```
````

In `lib/CHANGELOG.md`, sotto `## Unreleased` → `### Added`:

```markdown
- `.wi-choice-group--segmented` and `.wi-choice-group--list` (joined choices in a row or
  stacked), `.wi-choice__icon`, `.wi-choice__icons` with `.wi-choice__more` and
  `.wi-choice__panel`, shown only for the checked choice, for the one-page checkout.
```

- [ ] **Step 6: Lanciare i test e vederli passare**

Run: `cd /Users/andreamarinoni/Developer/packages/lib && node test/checkout-components.test.cjs && npm test 2>&1 | tail -3`
Expected: `checkout-components: ok`, poi la suite intera senza errori (exit 0).

- [ ] **Step 7: Commit**

```bash
cd /Users/andreamarinoni/Developer/packages/lib && git branch --show-current
git add src/build/frontend/css/components/choice.css MANIFEST.json docs/styles/components.md CHANGELOG.md test/checkout-components.test.cjs
git commit -m "Choice: segmenti e lista unita, icona, loghi con +N e pannello della scelta fatta

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

Expected: branch `checkout-a-passi-componenti`, un commit con 5 file.

---

### Task 3: catalogo dei font web in app (`Wonder\View\WebFonts`)

**Files:**
- Create: `app/resources/assets/font/web/<Cartella>/*.woff2` e `LICENSE` per 9 famiglie
- Create: `app/resources/assets/font/web/README.md`
- Create: `app/class/View/WebFonts.php`
- Create: `app/docs/app/concetti/font-web.md`
- Modify: `app/docs/app/SUMMARY.md` (una voce sotto i concetti)
- Modify: `app/CHANGELOG.md`
- Test: `app/tests/View/WebFontsTest.php`

**Interfaces:**
- Consumes: `$GLOBALS['PATH']->appAssets` (URL di `vendor/wonder-image/app/resources/assets`, lo stesso che usa `RuntimeDefaults` per i loghi).
- Produces (usati dal piano 2 in gestionale ed ecommerce):
  - `WebFonts::all(): array<string, string>` — chiave → nome, in quest'ordine: `inter` Inter, `roboto` Roboto, `open-sans` Open Sans, `lato` Lato, `montserrat` Montserrat, `poppins` Poppins, `dm-sans` DM Sans, `nunito` Nunito, `work-sans` Work Sans.
  - `WebFonts::has(string $key): bool` — chiave normalizzata (`trim` + minuscole).
  - `WebFonts::files(string $key): array<int, string>` — percorsi assoluti dei woff2 (vuoto se sconosciuta).
  - `WebFonts::css(string $key, ?string $baseUrl = null): string` — `@font-face` + `html:root{…}` con `--font-family`, `--title-big-font-family`, `--title-font-family`, `--subtitle-font-family`, `--text-font-family`, `--text-small-font-family`; `''` per chiave vuota o sconosciuta, o se non c'è un URL base. Senza `<style>`: lo aggiunge chi stampa.

Ruling già preso scrivendo il piano (da riportare nel registro):
- **URL dei file**: la spec §8 dice «passano da `Wonder\App\Module\Assets`», ma `app` non è un modulo registrato; si usa `$PATH->appAssets` come per i loghi di `RuntimeDefaults`. Costo se sbagliato: cambiare una riga in `baseUrl()`.
- **Pesi di Lato**: la spec dice 400–700 statici, ma Lato non ha 500 e 600; si prendono 400 e 700 (il browser usa il più vicino). Poppins ha 400, 500, 600, 700.
- **Variabili**: oltre alle quattro della spec si ridefiniscono `--text-font-family` e `--text-small-font-family`, che `root.css` del sito definisce a parte; senza, il testo resterebbe nel font del sito.
- **Selettore `html:root`**: più specifico di `:root`, così vince sul `root.css` del sito in qualunque ordine si stampino.

- [ ] **Step 1: Scrivere il test che fallisce**

Create `app/tests/View/WebFontsTest.php`:

```php
<?php
/** php tests/View/WebFontsTest.php */
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../harness.php';

use Wonder\View\WebFonts;

$base = 'https://sito.test/vendor/wonder-image/app/resources/assets/font/web';

check('il catalogo ha i 9 font, nell\'ordine del menu', fn () =>
    WebFonts::all() === [
        'inter' => 'Inter',
        'roboto' => 'Roboto',
        'open-sans' => 'Open Sans',
        'lato' => 'Lato',
        'montserrat' => 'Montserrat',
        'poppins' => 'Poppins',
        'dm-sans' => 'DM Sans',
        'nunito' => 'Nunito',
        'work-sans' => 'Work Sans',
    ]
);

check('ogni file esiste ed è un woff2', function () {
    foreach (array_keys(WebFonts::all()) as $key) {
        $files = WebFonts::files($key);

        if ($files === []) {
            return false;
        }

        foreach ($files as $file) {
            if (!is_file($file) || file_get_contents($file, false, null, 0, 4) !== 'wOF2') {
                echo "    manca o non è woff2: {$file}\n";

                return false;
            }
        }
    }

    return true;
});

check('ogni famiglia ha la licenza OFL accanto ai file', function () {
    foreach (array_keys(WebFonts::all()) as $key) {
        $license = dirname(WebFonts::files($key)[0]).'/LICENSE';

        if (!is_file($license) || !str_contains((string) file_get_contents($license), 'Open Font License')) {
            echo "    licenza mancante: {$license}\n";

            return false;
        }
    }

    return true;
});

check('css() di un font variable: un @font-face con intervallo di pesi e le variabili', function () use ($base) {
    $css = WebFonts::css('inter', $base);

    return substr_count($css, '@font-face') === 1
        && str_contains($css, 'font-family:"Inter"')
        && str_contains($css, 'font-weight:100 900')
        && str_contains($css, 'font-display:swap')
        && str_contains($css, 'url("'.$base.'/Inter/inter-latin-wght-normal.woff2") format("woff2")')
        && str_contains($css, 'html:root{')
        && str_contains($css, '--font-family:"Inter", sans-serif;')
        && str_contains($css, '--title-big-font-family:"Inter", sans-serif;')
        && str_contains($css, '--title-font-family:"Inter", sans-serif;')
        && str_contains($css, '--subtitle-font-family:"Inter", sans-serif;')
        && str_contains($css, '--text-font-family:"Inter", sans-serif;')
        && str_contains($css, '--text-small-font-family:"Inter", sans-serif;')
        && !str_contains($css, '<style');
});

check('css() dei font statici: un @font-face per peso', fn () =>
    substr_count(WebFonts::css('lato', $base), '@font-face') === 2
    && str_contains(WebFonts::css('lato', $base), 'font-weight:700;')
    && substr_count(WebFonts::css('poppins', $base), '@font-face') === 4
    && str_contains(WebFonts::css('poppins', $base), $base.'/Poppins/poppins-latin-600-normal.woff2')
);

check('chiave vuota o sconosciuta: nessun CSS; spazi e maiuscole non contano', fn () =>
    WebFonts::css('', $base) === ''
    && WebFonts::css('boh', $base) === ''
    && WebFonts::files('boh') === []
    && !WebFonts::has('')
    && WebFonts::has(' Open-Sans ')
    && str_contains(WebFonts::css(' Open-Sans ', $base), 'font-family:"Open Sans"')
);

check('l\'URL base perde virgolette e parentesi angolari', function () {
    $css = WebFonts::css('inter', 'https://x.test/a"b<c>/');

    return str_contains($css, 'url("https://x.test/abc/Inter/')
        && !str_contains($css, 'a"b');
});

check('senza URL base usa $PATH->appAssets; senza $PATH non stampa nulla', function () {
    unset($GLOBALS['PATH']);
    $none = WebFonts::css('inter');
    $GLOBALS['PATH'] = (object) ['appAssets' => 'https://sito.test/vendor/wonder-image/app/resources/assets'];
    $css = WebFonts::css('inter');
    unset($GLOBALS['PATH']);

    return $none === ''
        && str_contains($css, 'url("https://sito.test/vendor/wonder-image/app/resources/assets/font/web/Inter/inter-latin-wght-normal.woff2")');
});

summary();
```

- [ ] **Step 2: Lanciare il test e vederlo fallire**

Run: `cd /Users/andreamarinoni/Developer/packages/app && php tests/View/WebFontsTest.php`
Expected: FAIL — `✗` con «Class "Wonder\View\WebFonts" not found».

- [ ] **Step 3: Scaricare i font e le licenze da Fontsource**

```bash
cd /Users/andreamarinoni/Developer/packages/app/resources/assets/font && mkdir -p web && cd web
cdn=https://cdn.jsdelivr.net/npm
for pair in Inter:inter Roboto:roboto OpenSans:open-sans Montserrat:montserrat DMSans:dm-sans Nunito:nunito WorkSans:work-sans; do
  dir=${pair%%:*}; slug=${pair##*:}; mkdir -p "$dir"
  curl -fsSL "$cdn/@fontsource-variable/$slug@5/files/$slug-latin-wght-normal.woff2" -o "$dir/$slug-latin-wght-normal.woff2"
  curl -fsSL "$cdn/@fontsource-variable/$slug@5/LICENSE" -o "$dir/LICENSE"
done
mkdir -p Lato Poppins
for w in 400 700; do curl -fsSL "$cdn/@fontsource/lato@5/files/lato-latin-$w-normal.woff2" -o "Lato/lato-latin-$w-normal.woff2"; done
for w in 400 500 600 700; do curl -fsSL "$cdn/@fontsource/poppins@5/files/poppins-latin-$w-normal.woff2" -o "Poppins/poppins-latin-$w-normal.woff2"; done
curl -fsSL "$cdn/@fontsource/lato@5/LICENSE" -o Lato/LICENSE
curl -fsSL "$cdn/@fontsource/poppins@5/LICENSE" -o Poppins/LICENSE
for v in inter roboto open-sans montserrat dm-sans nunito work-sans; do printf '%s ' "$v"; curl -fsSL "$cdn/@fontsource-variable/$v@5/package.json" | grep -m1 '"version"'; done
for v in lato poppins; do printf '%s ' "$v"; curl -fsSL "$cdn/@fontsource/$v@5/package.json" | grep -m1 '"version"'; done
find . -name '*.woff2' | wc -l; du -sh .
grep -L "Open Font License" */LICENSE
```

Expected: 13 file woff2, qualche centinaio di KB in tutto, l'ultimo `grep -L` non stampa nulla (tutte le licenze sono OFL). Annotare le versioni stampate per il README.

Create `app/resources/assets/font/web/README.md` (con le versioni stampate al posto di `x.y.z`, è un dato da copiare dall'output, non un segnaposto da lasciare):

```markdown
# Font web

Font per le pagine del sito (accesso, account, checkout, carrello), scelti nel gestionale
e serviti da qui: niente Google Fonts. Il catalogo è `Wonder\View\WebFonts`.

Presi da [Fontsource](https://fontsource.org) il 2026-10-07, solo il sottoinsieme latino.
Licenza SIL Open Font License 1.1: il file `LICENSE` è in ogni cartella.

| Cartella | Pacchetto | Versione | File |
|---|---|---|---|
| Inter | `@fontsource-variable/inter` | x.y.z | variable, pesi 100-900 |
| Roboto | `@fontsource-variable/roboto` | x.y.z | variable, 100-900 |
| OpenSans | `@fontsource-variable/open-sans` | x.y.z | variable, 300-800 |
| Lato | `@fontsource/lato` | x.y.z | 400, 700 (Lato non ha 500 e 600) |
| Montserrat | `@fontsource-variable/montserrat` | x.y.z | variable, 100-900 |
| Poppins | `@fontsource/poppins` | x.y.z | 400, 500, 600, 700 |
| DMSans | `@fontsource-variable/dm-sans` | x.y.z | variable, 100-1000 |
| Nunito | `@fontsource-variable/nunito` | x.y.z | variable, 200-1000 |
| WorkSans | `@fontsource-variable/work-sans` | x.y.z | variable, 100-900 |

I font per i PDF (FPDF) sono nelle altre cartelle di `font/`: vedi `../README.md`.
```

- [ ] **Step 4: Scrivere `WebFonts`**

Create `app/class/View/WebFonts.php`:

```php
<?php

namespace Wonder\View;

/**
 * Catalogo chiuso dei font web serviti da `resources/assets/font/web/`.
 * Il gestionale salva la chiave (`inter`, `open-sans`, …); una chiave vuota
 * o sconosciuta vale «come il sito» e non produce CSS.
 */
final class WebFonts
{
    /**
     * chiave => [nome, cartella, [peso CSS => file]]. I font variable hanno
     * un solo file con l'intervallo di pesi dell'asse `wght`.
     */
    private const FONTS = [
        'inter' => ['Inter', 'Inter', ['100 900' => 'inter-latin-wght-normal.woff2']],
        'roboto' => ['Roboto', 'Roboto', ['100 900' => 'roboto-latin-wght-normal.woff2']],
        'open-sans' => ['Open Sans', 'OpenSans', ['300 800' => 'open-sans-latin-wght-normal.woff2']],
        'lato' => ['Lato', 'Lato', ['400' => 'lato-latin-400-normal.woff2', '700' => 'lato-latin-700-normal.woff2']],
        'montserrat' => ['Montserrat', 'Montserrat', ['100 900' => 'montserrat-latin-wght-normal.woff2']],
        'poppins' => ['Poppins', 'Poppins', [
            '400' => 'poppins-latin-400-normal.woff2',
            '500' => 'poppins-latin-500-normal.woff2',
            '600' => 'poppins-latin-600-normal.woff2',
            '700' => 'poppins-latin-700-normal.woff2',
        ]],
        'dm-sans' => ['DM Sans', 'DMSans', ['100 1000' => 'dm-sans-latin-wght-normal.woff2']],
        'nunito' => ['Nunito', 'Nunito', ['200 1000' => 'nunito-latin-wght-normal.woff2']],
        'work-sans' => ['Work Sans', 'WorkSans', ['100 900' => 'work-sans-latin-wght-normal.woff2']],
    ];

    /** Variabili del sito che `css()` ridefinisce (vedi `assets/<v>/css/set-up/root.css`). */
    private const VARIABLES = [
        '--font-family',
        '--title-big-font-family',
        '--title-font-family',
        '--subtitle-font-family',
        '--text-font-family',
        '--text-small-font-family',
    ];

    /** @return array<string, string> chiave => nome */
    public static function all(): array
    {
        return array_map(static fn (array $font): string => $font[0], self::FONTS);
    }

    public static function has(string $key): bool
    {
        return isset(self::FONTS[self::key($key)]);
    }

    /** @return array<int, string> percorsi assoluti dei file woff2 */
    public static function files(string $key): array
    {
        $font = self::FONTS[self::key($key)] ?? null;

        if ($font === null) {
            return [];
        }

        $dir = dirname(__DIR__, 2).'/resources/assets/font/web/'.$font[1];

        return array_values(array_map(static fn (string $file): string => $dir.'/'.$file, $font[2]));
    }

    /**
     * `@font-face` e variabili del sito per il font `$key`, senza `<style>`.
     * Stringa vuota per una chiave sconosciuta o senza URL base.
     */
    public static function css(string $key, ?string $baseUrl = null): string
    {
        $font = self::FONTS[self::key($key)] ?? null;
        $baseUrl = rtrim(str_replace(['"', '<', '>'], '', $baseUrl ?? self::baseUrl()), '/');

        if ($font === null || $baseUrl === '') {
            return '';
        }

        [$name, $dir, $files] = $font;
        $css = '';

        foreach ($files as $weight => $file) {
            $css .= '@font-face{font-family:"'.$name.'";font-style:normal;font-display:swap;'
                .'font-weight:'.$weight.';'
                .'src:url("'.$baseUrl.'/'.$dir.'/'.$file.'") format("woff2");}';
        }

        $stack = '"'.$name.'", sans-serif';

        return $css.'html:root{'.implode('', array_map(
            static fn (string $variable): string => $variable.':'.$stack.';',
            self::VARIABLES,
        )).'}';
    }

    private static function key(string $key): string
    {
        return strtolower(trim($key));
    }

    /** URL della cartella dei font, dallo stesso `$PATH->appAssets` dei loghi. */
    private static function baseUrl(): string
    {
        $path = $GLOBALS['PATH'] ?? null;
        $assets = is_object($path) ? (string) ($path->appAssets ?? '') : '';

        return $assets !== '' ? $assets.'/font/web' : '';
    }
}
```

Nota: le chiavi numeriche di `[peso => file]` (`'400'`) PHP le trasforma in interi; `'font-weight:'.$weight` le stampa uguali, quindi va bene.

- [ ] **Step 5: Lanciare il test e vederlo passare**

Run: `cd /Users/andreamarinoni/Developer/packages/app && php tests/View/WebFontsTest.php`
Expected: 8 `✓`, `8 test, 0 falliti`.

Poi gli altri test di `tests/View`:

Run: `cd /Users/andreamarinoni/Developer/packages/app && for f in tests/View/*.php; do php "$f" | tail -1; done`
Expected: ogni riga `… 0 falliti`.

- [ ] **Step 6: Guida e CHANGELOG**

Create `app/docs/app/concetti/font-web.md`:

```markdown
# Font web

`Wonder\View\WebFonts` è il catalogo chiuso dei font che un sito può usare nelle aree
senza header (accesso, account, checkout) e nel carrello, scelti nel gestionale. I file
woff2 sono in `resources/assets/font/web/` (Fontsource, licenza OFL), serviti dal sito:
niente richieste a Google.

| Chiave | Font |
|---|---|
| `inter` | Inter |
| `roboto` | Roboto |
| `open-sans` | Open Sans |
| `lato` | Lato |
| `montserrat` | Montserrat |
| `poppins` | Poppins |
| `dm-sans` | DM Sans |
| `nunito` | Nunito |
| `work-sans` | Work Sans |

```php
use Wonder\View\WebFonts;

WebFonts::all();          // ['inter' => 'Inter', …] per un menu a tendina
WebFonts::has('inter');   // true
$css = WebFonts::css('inter');
if ($css !== '') {
    echo '<style>'.$css.'</style>';
}
```

- `css()` restituisce i `@font-face` e una regola `html:root{…}` che ridefinisce
  `--font-family`, `--title-big-font-family`, `--title-font-family`,
  `--subtitle-font-family`, `--text-font-family` e `--text-small-font-family`.
  `html:root` vince sul `:root` di `root.css` del sito in qualunque ordine.
- Chiave vuota o sconosciuta → `''`: la pagina resta nel font del sito.
- L'URL dei file parte da `$PATH->appAssets`; nei test si passa `css($key, $baseUrl)`.
- Font variable per Inter, Roboto, Open Sans, Montserrat, DM Sans, Nunito e Work Sans;
  pesi statici per Lato (400, 700) e Poppins (400, 500, 600, 700).
```

In `app/docs/app/SUMMARY.md` aggiungere la voce `font-web.md` accanto alle altre pagine di `concetti/` (stesso formato delle righe vicine, per esempio dopo quella di `frontend-performance.md`).

In `app/CHANGELOG.md`, sotto `## Unreleased` → `### Added`:

```markdown
- `Wonder\View\WebFonts`: catalogo di 9 font web (Inter, Roboto, Open Sans, Lato,
  Montserrat, Poppins, DM Sans, Nunito, Work Sans) serviti da
  `resources/assets/font/web/` con licenza OFL; `css($chiave)` dà i `@font-face` e
  le variabili `--*-font-family` del sito, vuoto per una chiave sconosciuta.
```

- [ ] **Step 7: Commit**

```bash
cd /Users/andreamarinoni/Developer/packages/app && git branch --show-current
git add resources/assets/font/web class/View/WebFonts.php docs/app/concetti/font-web.md docs/app/SUMMARY.md CHANGELOG.md
git add -f tests/View/WebFontsTest.php
git commit -m "WebFonts: catalogo di 9 font web serviti da app, con @font-face e variabili del sito

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

Expected: branch `checkout-a-passi-componenti`; nel commit 13 woff2, 9 `LICENSE`, il README, la classe, la guida, il SUMMARY, il CHANGELOG e il test.

---

## Dopo il piano

- La `dist` della lib si ricostruisce e si rilascia a parte (con `npm run release`), dopo la conferma dell'utente; poi `npm install` nel sito di prova.
- Il piano 2 (pagina unica, gestionale ed ecommerce) usa `ChoiceGroup::variant`, `Choice::icon/icons/panel` e `WebFonts::all/css`.
