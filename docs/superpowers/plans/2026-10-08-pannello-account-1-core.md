# Pannello account del core — piano 1: core, lib e passaggio dell'ecommerce

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Il pannello account del cliente diventa del core, con lo stile di
elenajossifov.com, e l'ecommerce ci si aggancia con un'estensione.

**Architecture:** `AccountRoutes` registra il gruppo `/account/` e raccoglie le
`AccountExtension` dei moduli. Un solo `AccountController` del core usa servizi
piccoli: `AccountPersonal`, `AccountEmail`, `AccountAddresses` e
`AccountBilling` per i dati, `AccountPage` per SEO e viste, `AccountModal` per
i modal. Le viste usano `Modal` e `Button` del core e tre componenti CSS nuovi
della lib.

**Tech Stack:**
- PHP 8.2 con il framework Wonder Image.
- Test PHP a script: `$check` nei test unitari del core; `check()` e
  `summary()` di `tests/harness.php` nei test d'integrazione dell'ecommerce.
- Lib: test Node con `node:assert`, build webpack.
- Browser: Playwright (`.cjs`).

**Spec:** `packages/gestionale/docs/superpowers/specs/2026-10-08-pannello-account-design.md`

## Global Constraints

**Markup e stile**
- Ogni `type="submit"` del pannello ha la classe `wi-input-submit`: Salva dei
  modal, ripieghi senza JS, conferma di eliminazione, Esci.
- Colori ammessi: `var(--primary-color)`, `var(--primary-color-10)`, `#000`,
  `#fff`. Nessun altro colore fisso.
- Il «Salva» dei modal:
  - è nero (`variant('black')`) e a tutta larghezza (`block()`);
  - resta spento finché mancano i campi obbligatori. I campi `FormField`
    escono già con `data-wi-check="true"` (`app/class/Elements/Form/Field.php:29`).
- Bottoni secondari: outline nero.
- Ogni pagina ha il titolo `<h2 class="subtitle wi-side-layout__title">` con il
  divisore sotto.

**URL e sicurezza**
- URL esattamente come §4.1 della spec, sotto `/account/`. Le URL
  `/account/auth/…` non si toccano.
- L'accesso richiede il login e i permessi di `AccountPanel::authorities()`
  (default `['client']`).
- CSRF su ogni POST con `Wonder\Http\Csrf`.
- Un indirizzo di un altro cliente dà 404.
- SEO del pannello `NOINDEX,NOFOLLOW`.

**Testi e lib**
- Testi in `resources/lang/it/account.json` ed `en/account.json`, con le
  stesse chiavi.
- CSS della lib solo ASCII; chiavi del `MANIFEST.json` in snake_case.

**Modo di lavorare**
- Si lavora solo nei worktree `pannello-account`. Mai `git switch` nelle
  cartelle condivise `packages/*`.
- Niente push, PR o merge senza la conferma esplicita dell'utente.
- Ogni commit finisce con `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Credenziali di prova solo su host `.test`. Il login nel browser lo fa
  l'utente; mai password in chat.

## Review Focus

1. **Link vecchio.** Un link di cambio email vecchio, dopo una richiesta più
   recente, dà «link non valido»: il token è stato revocato. Si prova nel Task 5.
2. **Conferma senza sessione.** La conferma dell'email funziona anche senza
   login. Con un altro utente loggato, non tocca né la sua sessione né i suoi
   dati. Si prova nel Task 5.
3. **Errori nei modal.** Con errori si riapre solo il modal giusto, con gli
   errori dentro e i valori inseriti; gli altri restano chiusi. Si prova nel
   Task 6.
4. **Niente righe vuote.** Una sezione spenta non ha né voce né route. Una voce
   di un'estensione senza `href` o `label` sparisce. Si prova nel Task 3.
5. **Scheda in conflitto.** Non si scrive nulla, né sull'utente né sulla scheda.
   Si prova nel Task 4.

## Scostamenti dalla spec decisi in fase di piano

- **Lingue.** Nel core, de, es e fr non hanno né `account.json` né `auth.json`:
  si aggiornano solo it ed en.
- **CSRF.** I form usano il campo `_csrf` di `Wonder\Http\Csrf`, che
  `Modal::form()` mette già. Il form di «Esci» manda invece
  `csrf_token = AuthSession::csrfToken()`, perché lo chiede l'auth. Entrambi
  usano la stessa chiave di sessione.
- **Logout.** Il form punta a `AccountRoutes::auth()->route('logout')`; con
  l'ecommerce è `ecommerce.auth.logout`.
- **Esito della conferma email.** Si usa la pagina `message.php` dell'auth
  (`AuthProfile::viewPath('message')`); non nasce `account/message.php`.
- **Indirizzi.** Non hanno più il campo `label`, perché lo screen non lo mostra.
- **`validatePersonal`.** Restituisce messaggi già tradotti.
- **Modal di «Dati personali».** Postano tutti su `account.personal`, con il
  campo nascosto `form` = `personal`, `email` o `password`.
- **Prove «senza moduli».** Girano sul sito di prova dell'ecommerce, dopo
  `AccountRoutes::reset()` e un `register()` senza estensioni.
- **`account.index`.** Esiste sempre. Con la Panoramica spenta rimanda alla
  prima voce del menu.
- **Avvisi.** Stanno in sessione (`$_SESSION['wonder_account_notice']`), non in
  query string, così l'email nuova non finisce nell'URL.

## Preparazione (una volta, prima del Task 1)

```bash
P=/Users/andreamarinoni/Developer/packages; W=/Users/andreamarinoni/Developer/worktrees/pannello-account
for r in app lib ecommerce gestionale; do git -C $P/$r worktree add $W/$r -b pannello-account main; done
for r in app ecommerce gestionale; do [ -d $P/$r/vendor ] && cp -R $P/$r/vendor $W/$r/; done
ln -s $P/lib/node_modules $W/lib/node_modules
B=/Users/andreamarinoni/Developer/boilerplates/ecommerce-site; S=/Users/andreamarinoni/Developer/boilerplates/ecommerce-site-account
git -C $B worktree add --detach $S
for f in .env .htaccess assets/0.0/css/set-up assets/lib assets/upload/app bin demo handler robots.txt storage vendor custom/config/modules.php custom/config/modules shared/sync-data.json; do mkdir -p "$S/$(dirname $f)"; rsync -a "$B/$f" "$S/$(dirname $f)/"; done
for m in app ecommerce gestionale; do rm -f $S/vendor/wonder-image/$m; ln -s $W/$m $S/vendor/wonder-image/$m; done
sed -i '' 's#^APP_URL=.*#APP_URL=https://ecommerce-account.test#' $S/.env
cd $S && herd link ecommerce-account && herd secure ecommerce-account
export WI_TEST_SITE=$S WI_TEST_URL=https://ecommerce-account.test
```

Il database `ecommerce_site` è condiviso con il sito di prova principale.
Dopo il Task 2 si lancia `php forge update` in `$S` per aggiungere
`birth_date`.

Le cartelle condivise `packages/ecommerce` hanno modifiche non salvate di
un'altra sessione (`config/module.php`, `lang/{it,en}/ecommerce.json`,
`tests/CartCheckoutTest.php`, `AccountAddressBrowserTest.cjs`): non si toccano.
All'unione su main ci si aspettano conflitti in quei file; si tengono tutte e
due le parti.

## Mappa dei file

**Core (`$W/app`)**
- Nuovi in `class/Auth/Frontend/`:
  - `AccountExtension.php`, `BaseAccountExtension.php`;
  - `AccountRoutes.php`, `AccountController.php`, `AccountPage.php`,
    `AccountModal.php`;
  - `AccountPersonal.php`, `AccountEmail.php`, `AccountAddresses.php`,
    `AccountBilling.php`.
- Nuovi altrove:
  - `app/http/frontend/account.php`;
  - `app/view/pages/frontend/account/{index,personal,addresses,address-form,billing}.php`;
  - `app/view/components/frontend/account/address-card.php`;
  - `tests/account-routes.php`.
- Da modificare:
  - `class/Auth/Frontend/{AccountPanel,AccountPassword,AuthValidator,AuthValidationAlert,AccountAddressForm}.php`;
  - `class/App/Models/Contacts/Contact.php`;
  - `app/view/layout/frontend/account/panel.php`;
  - `app/view/components/frontend/account/{navigation,row}.php`;
  - `resources/lang/{it,en}/account.json`;
  - `tests/account-password.php`;
  - `docs/app/concetti/utenti/auth-frontend.md`, `CHANGELOG.md`.
- Da eliminare: `class/Auth/Frontend/AccountAddressModal.php`,
  `app/view/components/frontend/account/{address-form,password-form}.php`.

**Lib (`$W/lib`)**
- Nuovi:
  - `src/build/frontend/css/components/{side-nav,row-table,address-card}.css`;
  - `src/build/frontend/js/side-nav.js`;
  - `test/account-components.test.cjs`.
- Da modificare: `src/export/frontend/{head,body-end}.js`, `MANIFEST.json`,
  `docs/styles/components.md`, `CHANGELOG.md`, `package.json`.

**Ecommerce (`$W/ecommerce`)**
- Nuovi:
  - `src/Frontend/Account/EcommerceAccountExtension.php`;
  - `src/Frontend/Account/EcommerceAccountController.php`;
  - `tests/integrazione/{ContactBirthDateTest,AccountCoreTest,AccountEmailTest}.php`.
- Da riscrivere: `view/pages/account/payment-methods.php`,
  `http/frontend/account.php`.
- Da modificare:
  - `config/routes/route.frontend.php`, `config/module.php`;
  - `lang/{it,en}/ecommerce.json`, `view/pages/checkout/completed.php`;
  - test esistenti: `tests/run.php`, `tests/EcommerceTest.php`,
    `tests/CartCheckoutTest.php`, `tests/integrazione/*`;
  - `CHANGELOG.md`, `TODO.md`.
- Da eliminare:
  - `src/Frontend/Account/{AccountController,EcommerceAccountPanel}.php`;
  - le altre viste di `view/pages/account/`;
  - `view/components/account/*`;
  - `view/layout/frontend/ecommerce.account.php`.

**Gestionale (`$W/gestionale`)**
- Solo documenti: §2.3 della spec di architettura e `TODO.md`.

---

### Task 1: Componenti della lib (menu laterale, tabella a righe, schede indirizzo)

Repo: `$W/lib`.

**Files:**
- Create: `src/build/frontend/css/components/side-nav.css`, `row-table.css`, `address-card.css`
- Create: `src/build/frontend/js/side-nav.js`
- Create: `test/account-components.test.cjs`
- Modify: `src/export/frontend/head.js:52` (dopo l'import di `thumb.css`), `src/export/frontend/body-end.js:6` (dopo `modal.js`)
- Modify: `MANIFEST.json`, `docs/styles/components.md`, `CHANGELOG.md`, `package.json:11`

**Interfaces:**
- Produces, con le classi usate dalle viste del Task 6 e del Task 7:
  - menu: `.wi-side-layout`, `__aside`, `__main`, `__title`,
    `.wi-side-nav__list`, `__link` (con `aria-current="page"`), `__icon`,
    `__form`;
  - tabelle e righe: `.wi-row-table`, `__head`, `__row`, `__cell`, `__title`,
    `__subtitle`, `__foot`; `.wi-pagination`, `__item`; `.wi-data-row`,
    `__cols`, `__col`, `__label`, `__value`, `__action`; `.wi-empty-state`,
    `__icon`;
  - indirizzi: `.wi-address-grid`, `.wi-address-card`, `__body`, `__name`,
    `__actions`, `__icon`, `--add`.
- Produces: il token `--wi-row-table-columns` e la funzione globale
  `wiSideNavCenter()`.

- [ ] **Step 1: Scrivi il test che fallisce**

`test/account-components.test.cjs`:

```js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const manifest = JSON.parse(read('MANIFEST.json'));
const head = read('src/export/frontend/head.js');
const bodyEnd = read('src/export/frontend/body-end.js');

const expected = {
    side_nav: ['.wi-side-layout', '.wi-side-layout__aside', '.wi-side-layout__main', '.wi-side-layout__title', '.wi-side-nav__list', '.wi-side-nav__link', '.wi-side-nav__icon', '.wi-side-nav__form'],
    row_table: ['.wi-row-table', '.wi-row-table__head', '.wi-row-table__row', '.wi-row-table__cell', '.wi-row-table__title', '.wi-row-table__subtitle', '.wi-row-table__foot', '.wi-pagination', '.wi-pagination__item', '.wi-data-row', '.wi-data-row__cols', '.wi-data-row__col', '.wi-data-row__label', '.wi-data-row__value', '.wi-data-row__action', '.wi-empty-state', '.wi-empty-state__icon'],
    address_card: ['.wi-address-grid', '.wi-address-card', '.wi-address-card__body', '.wi-address-card__name', '.wi-address-card__actions', '.wi-address-card__icon', '.wi-address-card--add'],
};

for (const [key, classes] of Object.entries(expected)) {
    const entry = manifest.components[key];
    assert.ok(entry, 'MANIFEST senza components.' + key);
    assert.deepEqual(entry.classes, classes, key + ': classi diverse');
    assert.ok(typeof entry.markup === 'string' && entry.markup !== '', key + ': markup mancante');
    const css = read(entry.file);
    assert.ok(head.includes("import '/" + entry.file + "';"), key + ': head.js non importa ' + entry.file);
    assert.ok(/^[\x00-\x7F]*$/.test(css), key + ': CSS non ASCII');
    for (const cls of classes) {
        const escaped = cls.replace(/[.*+?^${}()|[\]\\-]/g, '\\$&');
        assert.ok(new RegExp(escaped + '[\\s,:{\\[.]').test(css), key + ': manca ' + cls);
    }
    for (const hex of css.match(/#[0-9a-fA-F]{3,8}\b/g) || []) {
        assert.ok(['#000', '#fff', '#000000', '#ffffff'].includes(hex.toLowerCase()), key + ': colore fisso ' + hex);
    }
}

const side = read(manifest.components.side_nav.file);
assert.ok(side.includes('[aria-current="page"]'));
assert.ok(side.includes('inset 3px 0 0 var(--primary-color)'));
assert.ok(side.includes('var(--primary-color-10)'));
assert.ok(side.includes('@media (max-width: 767px)'));
assert.deepEqual(manifest.components.row_table.tokens, ['--wi-row-table-columns']);

const card = read(manifest.components.address_card.file);
assert.ok(card.includes('aspect-ratio: 3 / 2'));
assert.ok(card.includes('border: 2px solid #000'));

assert.ok(bodyEnd.includes("import 'script-loader!/src/build/frontend/js/side-nav.js';"));
assert.ok(read('src/build/frontend/js/side-nav.js').includes('scrollLeft'));

const docs = read('docs/styles/components.md');
for (const word of ['wi-side-nav', 'wi-row-table', 'wi-address-card']) {
    assert.ok(docs.includes(word), 'docs senza ' + word);
}
assert.ok(read('CHANGELOG.md').includes('wi-side-nav'));

console.log('account components: ok');
```

- [ ] **Step 2: Lancia il test e verifica che fallisca**

Run: `cd $W/lib && node test/account-components.test.cjs`
Expected: FAIL con `MANIFEST senza components.side_nav`.

- [ ] **Step 3: Scrivi i tre CSS e lo script**

`src/build/frontend/css/components/side-nav.css`:

```css
/* Account panel: side menu (220px) + content; horizontal scrolling row under 768px. */
.wi-side-layout { display: grid; grid-template-columns: 220px minmax(0, 1fr); gap: calc(var(--spacer) * 10); align-items: start; }
.wi-side-layout__aside, .wi-side-layout__main { min-width: 0; }
.wi-side-layout__title { padding-bottom: calc(var(--spacer) * 4); margin-bottom: calc(var(--spacer) * 6); border-bottom: 1px solid var(--dropdown-border-color); }
.wi-side-nav__list { display: flex; flex-direction: column; gap: var(--spacer); margin: 0; padding: 0; list-style: none; }
.wi-side-nav__link { display: flex; align-items: center; gap: calc(var(--spacer) * 3); width: 100%; padding: 16px; line-height: 24px; font-size: var(--input-font-size); border: var(--input-border-bottom) solid transparent; border-radius: var(--button-border-radius); background: transparent; color: #000; text-align: left; text-decoration: none; cursor: pointer; }
.wi-side-nav__link:hover { background: var(--primary-color-10); }
.wi-side-nav__link[aria-current="page"] { background: var(--primary-color-10); box-shadow: inset 3px 0 0 var(--primary-color); font-weight: 600; }
.wi-side-nav__icon { font-size: 18px; line-height: 1; }
.wi-side-nav__link[aria-current="page"] .wi-side-nav__icon { color: var(--primary-color); }
.wi-side-nav__form { margin: 0; }

@media (max-width: 767px) {
    .wi-side-layout { grid-template-columns: minmax(0, 1fr); gap: calc(var(--spacer) * 6); }
    .wi-side-nav__list { position: relative; flex-direction: row; overflow-x: auto; scrollbar-width: none; }
    .wi-side-nav__list::-webkit-scrollbar { display: none; }
    .wi-side-nav__link { width: auto; white-space: nowrap; }
    .wi-side-nav__link[aria-current="page"] { box-shadow: none; }
}
```

`src/build/frontend/css/components/row-table.css`:

```css
/* Account panel: row tables, pagination squares, data rows and empty states. */
.wi-row-table { display: flex; flex-direction: column; }
.wi-row-table__head, .wi-row-table__row { display: grid; grid-template-columns: var(--wi-row-table-columns, minmax(0, 2fr) minmax(0, 1fr) auto); gap: calc(var(--spacer) * 4); align-items: center; padding: calc(var(--spacer) * 4) 0; border-bottom: 1px solid var(--dropdown-border-color); }
.wi-row-table__head { font-weight: 700; }
.wi-row-table__cell { min-width: 0; }
.wi-row-table__title { font-weight: 600; }
.wi-row-table__subtitle { font-size: 14px; opacity: .7; }
.wi-row-table__foot { display: flex; justify-content: space-between; align-items: center; gap: calc(var(--spacer) * 4); padding-top: calc(var(--spacer) * 6); }
.wi-pagination { display: flex; gap: calc(var(--spacer) * 2); margin: 0; padding: 0; list-style: none; }
.wi-pagination__item { display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; border: 1px solid #000; color: #000; text-decoration: none; }
.wi-pagination__item[aria-current="page"] { background: #000; color: #fff; }
.wi-pagination__item[aria-disabled="true"] { opacity: .3; pointer-events: none; }
.wi-data-row { display: flex; justify-content: space-between; align-items: center; gap: calc(var(--spacer) * 4); padding: calc(var(--spacer) * 5) 0; border-bottom: 1px solid var(--dropdown-border-color); }
.wi-data-row__cols { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: calc(var(--spacer) * 4); flex: 1; min-width: 0; }
.wi-data-row__col { min-width: 0; }
.wi-data-row__label { font-weight: 600; }
.wi-data-row__value { overflow-wrap: anywhere; }
.wi-data-row__action { flex-shrink: 0; }
.wi-empty-state { display: flex; flex-direction: column; align-items: center; gap: calc(var(--spacer) * 4); padding: calc(var(--spacer) * 10) 0; text-align: center; }
.wi-empty-state__icon { font-size: 40px; color: var(--primary-color); }

@media (max-width: 767px) {
    .wi-row-table__head { display: none; }
    .wi-row-table__row { grid-template-columns: minmax(0, 1fr); }
    .wi-data-row { align-items: flex-start; }
    .wi-data-row__cols { grid-template-columns: minmax(0, 1fr); }
}
```

`src/build/frontend/css/components/address-card.css`:

```css
/* Account panel: address cards (3:2, black border) and the "add" card. */
.wi-address-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: calc(var(--spacer) * 6); }
.wi-address-card { display: flex; flex-direction: column; justify-content: space-between; gap: calc(var(--spacer) * 4); aspect-ratio: 3 / 2; padding: calc(var(--spacer) * 6); border: 2px solid #000; color: #000; text-decoration: none; min-width: 0; }
.wi-address-card__body { display: flex; flex-direction: column; gap: var(--spacer); min-width: 0; overflow-wrap: anywhere; }
.wi-address-card__name { font-size: 20px; font-weight: 700; }
.wi-address-card__actions { display: flex; justify-content: flex-end; gap: calc(var(--spacer) * 2); }
.wi-address-card--add { align-items: center; justify-content: center; cursor: pointer; background: transparent; }
.wi-address-card--add .wi-address-card__icon { font-size: 32px; }

@media (max-width: 1023px) { .wi-address-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 767px) { .wi-address-grid { grid-template-columns: minmax(0, 1fr); } }
```

`src/build/frontend/js/side-nav.js`:

```js
// Under 768px the side menu scrolls horizontally: bring the active item into view.
function wiSideNavCenter() {
    document.querySelectorAll('.wi-side-nav__list').forEach(function (list) {
        var active = list.querySelector('[aria-current="page"]');
        if (!active || list.scrollWidth <= list.clientWidth) return;
        list.scrollLeft = active.offsetLeft - (list.clientWidth - active.offsetWidth) / 2;
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', wiSideNavCenter);
} else {
    wiSideNavCenter();
}
```

- [ ] **Step 4: Collega i file, MANIFEST, docs, changelog e script di test**

1. Aggiungi gli import.
   - In `head.js`, dopo `import '/src/build/frontend/css/components/thumb.css';`,
     aggiungi le tre righe:
     `import '/src/build/frontend/css/components/side-nav.css';`, poi
     `row-table.css`, poi `address-card.css`.
   - In `body-end.js`, dopo la riga di `modal.js`, aggiungi
     `import 'script-loader!/src/build/frontend/js/side-nav.js';`.
2. In `MANIFEST.json`, sotto `components`, aggiungi tre voci con la forma di
   `thumb`, cioè `file`, `classes`, `tokens`, `markup`. Le liste `classes` sono
   quelle del test, nello stesso ordine.
   - `side_nav`:
     - `tokens`: `[]`;
     - `markup`: `".wi-side-layout > aside.wi-side-layout__aside > nav > ul.wi-side-nav__list > li > a.wi-side-nav__link[aria-current=page] > i.wi-side-nav__icon; Esci = form.wi-side-nav__form > button.wi-side-nav__link.wi-input-submit; main.wi-side-layout__main > h2.subtitle.wi-side-layout__title"`.
   - `row_table`:
     - `tokens`: `["--wi-row-table-columns"]`;
     - `markup`: `".wi-row-table > .wi-row-table__head + .wi-row-table__row > .wi-row-table__cell (.wi-row-table__title + .wi-row-table__subtitle); .wi-row-table__foot > span + ul.wi-pagination > li > a.wi-pagination__item; .wi-data-row > .wi-data-row__cols > .wi-data-row__col > .wi-data-row__label + .wi-data-row__value, .wi-data-row__action; .wi-empty-state > i.wi-empty-state__icon + p"`.
   - `address_card`:
     - `tokens`: `[]`;
     - `markup`: `".wi-address-grid > .wi-address-card > .wi-address-card__body > .wi-address-card__name + lines; .wi-address-card__actions > buttons; button.wi-address-card.wi-address-card--add > i.wi-address-card__icon + span"`.
3. In `docs/styles/components.md`, dopo la sezione `## Thumb`, aggiungi tre
   sezioni: `## Side nav`, `## Row table` e `## Address card`. Per ognuna:
   - due righe sullo scopo;
   - il markup minimo (lo stesso del MANIFEST);
   - i token del tema usati;
   - il comportamento sotto i 768px.
4. In `CHANGELOG.md`, sotto `## Unreleased` → `### Added`, aggiungi un punto
   per ognuno di `.wi-side-nav`/`.wi-side-layout`, `.wi-row-table` (con
   `.wi-pagination`, `.wi-data-row` e `.wi-empty-state`) e `.wi-address-card`.
   Usa lo stile dei punti già presenti e un rimando a
   `docs/styles/components.md`.
5. In `package.json:11`, aggiungi ` && node test/account-components.test.cjs`
   in coda allo script `test`.

- [ ] **Step 5: Lancia i test e la build**

Run: `cd $W/lib && npm test && npm run build && git checkout -- dist`
Expected: tutti i test stampano `ok`, inclusa la riga `account components: ok`,
e la build finisce senza errori. Il `git checkout -- dist` lascia `dist`
com'era: la build da spedire la fa il rilascio.

- [ ] **Step 6: Commit**

```bash
cd $W/lib && git add src/build/frontend/css/components/side-nav.css src/build/frontend/css/components/row-table.css src/build/frontend/css/components/address-card.css src/build/frontend/js/side-nav.js src/export/frontend/head.js src/export/frontend/body-end.js MANIFEST.json docs/styles/components.md CHANGELOG.md package.json test/account-components.test.cjs
git commit -m "Pannello account: menu laterale, tabella a righe e schede indirizzo

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Data di nascita sulla scheda, password senza conferma, test sul sito parallelo

Repo: `$W/app` e `$W/ecommerce`.

**Files:**
- Modify: `$W/app/class/App/Models/Contacts/Contact.php`:
  - riga 36: `sqlColumnsFromDataSchema`;
  - `dataSchema()`: dopo `Field::key('email')->email(),`.
- Modify: `$W/app/class/Auth/Frontend/AuthValidator.php` (`completion`)
- Modify: `$W/app/class/Auth/Frontend/AccountPassword.php` (`fields`, `validate`)
- Modify: `$W/app/resources/lang/{it,en}/account.json` (`password.current`)
- Modify: `$W/app/tests/account-password.php:56-57,80`
- Modify:
  - `$W/ecommerce/tests/run.php:10`;
  - l'intestazione di `$W/ecommerce/tests/integrazione/*Test.php` (`const SITE`);
  - `AuthHttpTest.php:6` e `CatalogSearchHttpTest.php:7,25`;
  - `AccountAddressBrowserTest.cjs` e `ContactResourcesBrowserTest.cjs`.
- Create: `$W/ecommerce/tests/integrazione/ContactBirthDateTest.php`

**Interfaces:**
- Produces:
  - la colonna `contacts.birth_date` (DATE, null) e il campo `birth_date` nel
    data schema di `Contact`;
  - `AuthValidator::completion(array $input, bool $passwordRequired = true, bool $phoneRequired = true, bool $confirmation = true): array`;
  - `AccountPassword::fields(bool $hasPassword)`, che restituisce solo
    `current_password` (se c'è una password) e `password`.
- Produces: i test dell'ecommerce leggono `WI_TEST_SITE` e `WI_TEST_URL`. Senza
  queste variabili tornano al sito `ecommerce-site` e a `https://ecommerce.test`.

- [ ] **Step 1: I test leggono il sito dalle variabili d'ambiente**

```bash
cd $W/ecommerce
grep -ln "^const SITE = " tests/integrazione/*.php
sed -i '' "s#^const SITE = '\([^']*\)';#define('SITE', getenv('WI_TEST_SITE') ?: '\1');#" tests/integrazione/*.php
sed -i '' "s#is_dir('/Users/andreamarinoni/Developer/boilerplates/ecommerce-site')#is_dir(getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site')#" tests/run.php
sed -i '' "s#curl_init('https://ecommerce.test'\.#curl_init((getenv('WI_TEST_URL') ?: 'https://ecommerce.test').#" tests/integrazione/AuthHttpTest.php tests/integrazione/CatalogSearchHttpTest.php
grep -n "ecommerce.test" tests/integrazione/*.php tests/integrazione/*.cjs
```

Poi sistema a mano le occorrenze che restano:
- in `CatalogSearchHttpTest.php:25`, componi l'atteso con
  `(getenv('WI_TEST_URL') ?: 'https://ecommerce.test').'/api/ecommerce/catalog/products/search/'`;
- nei due `.cjs`, sostituisci la costante dell'URL con
  `process.env.WI_TEST_URL || 'https://ecommerce.test'`.

Run: `cd $W/ecommerce && WI_TEST_SITE=$S php tests/integrazione/CartSessionTest.php`
Expected: PASS, come prima.

- [ ] **Step 2: Scrivi il test della data di nascita (fallisce)**

`$W/ecommerce/tests/integrazione/ContactBirthDateTest.php`. L'intestazione è
quella di `AuthAccountTest.php:1-12`, con `define('SITE', …)` dello Step 1.
Poi:

```php
use Wonder\App\Models\Contacts\Contact;
use Wonder\App\Models\User\User;
use Wonder\Auth\Frontend\ContactAccount;
use Wonder\Sql\Transaction;

final class AnnullaBirthDate extends RuntimeException {}

try {
    Transaction::run(static function (): void {
        $email = 'birth-'.bin2hex(random_bytes(6)).'@example.com';
        $created = User::create([
            'name' => 'Ada', 'surname' => 'Lovelace', 'email' => $email,
            'username' => create_link(explode('@', $email)[0], 'user', 'username'),
            'authority' => json_encode(['client'], JSON_THROW_ON_ERROR),
            'area' => json_encode(['frontend'], JSON_THROW_ON_ERROR),
            'active' => 'true',
        ]);
        $userId = (int) ($created->insert_id ?? 0);
        $contactId = (int) (ContactAccount::link($userId)->contact_id ?? 0);

        Contact::update(['birth_date' => '1990-05-17'], $contactId);
        check('la scheda salva la data di nascita', fn () => (Contact::find(['id' => $contactId], 1)['birth_date'] ?? null) === '1990-05-17');

        Contact::update(['birth_date' => null], $contactId);
        check('la data di nascita si può togliere', fn () => (Contact::find(['id' => $contactId], 1)['birth_date'] ?? 'x') === null);

        throw new AnnullaBirthDate();
    });
} catch (AnnullaBirthDate) {
}

summary();
```

Run: `cd $W/ecommerce && WI_TEST_SITE=$S php tests/integrazione/ContactBirthDateTest.php`
Expected: FAIL, perché la colonna `birth_date` non esiste ancora.

- [ ] **Step 3: Aggiungi `birth_date` a `Contact`**

In `Contact.php`:
- riga 36: `...static::sqlColumnsFromDataSchema(['code', 'email', 'birth_date', 'color']),`;
- in `dataSchema()`, dopo `Field::key('email')->email(),`, aggiungi
  `Field::key('birth_date')->date(),` (qui `Field` è `Wonder\Data\UploadSchema`,
  che ha `date()`; nel form invece si usa `FormField::key(...)->textDate()`).

Run: `cd $S && php forge update && cd $W/ecommerce && WI_TEST_SITE=$S php tests/integrazione/ContactBirthDateTest.php`
Expected: `2 test, 0 falliti`.

- [ ] **Step 4: Aggiorna il test della password (fallisce)**

In `$W/app/tests/account-password.php`:
- righe 56-57: le chiavi attese diventano `['current_password', 'password']`
  (con password) e `['password']` (senza);
- riga 80: il caso «conferma diversa» ora deve riuscire, perché la conferma
  non c'è più.

Run: `cd $W/app && php tests/account-password.php`
Expected: FAIL sulle chiavi dei campi.

- [ ] **Step 5: Togli la conferma dal cambio password**

1. In `AuthValidator::completion`:
   - aggiungi il parametro `bool $confirmation = true`;
   - controlla `password_confirmation.mismatch` solo se `$confirmation`.
2. In `AccountPassword`:
   - togli `password_confirmation` da `fields()`;
   - in `validate()`, chiama `AuthValidator::completion($input, true, false, false)`.
3. In `account.json`, `password.current` diventa «La tua password» in it e
   "Your password" in en.

Run: `cd $W/app && php tests/account-password.php && php tests/auth-frontend.php`
Expected: tutti e due passano. La registrazione chiede ancora la conferma.

- [ ] **Step 6: Commit (due repo)**

```bash
cd $W/app && git add class/App/Models/Contacts/Contact.php class/Auth/Frontend/AuthValidator.php class/Auth/Frontend/AccountPassword.php resources/lang/it/account.json resources/lang/en/account.json tests/account-password.php
git commit -m "Account: data di nascita sulla scheda, cambio password senza conferma

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
cd $W/ecommerce && git add tests/run.php tests/integrazione
git commit -m "Test: sito e URL di prova da WI_TEST_SITE e WI_TEST_URL; data di nascita della scheda

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Rotte, pannello ed estensioni

Repo: `$W/app` (e una firma in `$W/ecommerce`).

**Files:**
- Create: `$W/app/class/Auth/Frontend/AccountExtension.php`, `BaseAccountExtension.php`, `AccountRoutes.php`
- Modify: `$W/app/class/Auth/Frontend/AccountPanel.php` (tutto il corpo)
- Modify: `$W/ecommerce/src/Frontend/Account/EcommerceAccountPanel.php`. La firma
  di `navigation` diventa `(object $user, string $active = '')`, così il sito
  resta in piedi fino al Task 8.
- Test: `$W/app/tests/account-routes.php`

**Interfaces:**
- Produces, in `AccountExtension` (interfaccia):
  ```php
  public function routes(): void;
  public function navigation(array $items, object $user): array;
  public function overviewRows(array $rows, object $user): array;
  public function personalRows(array $rows, object $user): array;
  public function personalFields(array $fields, object $user): array;
  public function validatePersonal(array $input, object $user): array; // messaggi tradotti
  public function personalUserValues(array $input, object $user): array;
  public function afterPersonalSaved(array $input, object $user): void;
  public function head(): string;
  ```
- Produces: `BaseAccountExtension`, classe astratta con tutti i metodi che non
  fanno nulla. `routes()` è vuoto; ogni `array` torna com'è arrivato;
  `validatePersonal` restituisce `[]`; `personalUserValues` restituisce `[]`;
  `head()` restituisce `''`.
- Produces, in `AccountPanel`:
  - `authorities(): array` (`['client']`);
  - `sections(): array` (`['overview','personal','addresses','billing']`);
  - `enabled(string $section): bool`;
  - `navigation(object $user, string $active = ''): array`;
  - `overviewRows`, `personalRows`, `personalFields`, `validatePersonal`,
    `personalUserValues`, `afterPersonalSaved` e `head()`, a cascata sulle
    estensioni;
  - `parentLayout()`, `title()`, `layout()` come oggi.

  Una voce del menu ha la forma `['key','label','icon','href','active']`.
- Produces, in `AccountRoutes` (final):
  - `register(AccountPanel $panel, ?AuthProfile $auth = null): void`;
  - `extend(AccountExtension $extension): void`;
  - `group(callable $routes): void`, che registra route private nel gruppo del
    pannello (per le estensioni);
  - `panel(): AccountPanel`, `auth(): AuthProfile`, `extensions(): array`;
  - `handler(): string`, `reset(): void`.
- Produces: i metadati delle route sono `['account_action' => '<nome senza account.>']`.

Forma di una riga, uguale per le righe di «Dati personali» e quelle della
Panoramica, usata dal componente `row.php` del Task 6:

```php
['key' => 'email', 'columns' => [['label' => 'Email', 'value' => 'ada@example.com']],
 'action' => ['label' => 'Modifica', 'href' => '', 'modal' => 'account-email', 'icon' => 'bi bi-pencil', 'disabled' => false, 'hint' => '']]
```

- [ ] **Step 1: Scrivi il test che fallisce**

`$W/app/tests/account-routes.php`:

```php
<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Wonder\Auth\Frontend\AccountPanel;
use Wonder\Auth\Frontend\AccountRoutes;
use Wonder\Auth\Frontend\BaseAccountExtension;
use Wonder\Http\Route;

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

function __t(string $key, array $replace = []): string { return $key; }
function __r(string $name, array $parameters = []): string { return Route::url($name, $parameters); }

final class ProvaExtension extends BaseAccountExtension
{
    public function routes(): void
    {
        AccountRoutes::group(static function (): void {
            Route::get('/prova/', AccountRoutes::handler(), ['account_action' => 'prova'])->name('prova');
        });
    }
    public function navigation(array $items, object $user): array
    {
        unset($items['billing']);
        $items['prova'] = ['label' => 'Prova', 'href' => \__r('account.prova'), 'icon' => 'bi bi-star'];
        $items['vuota'] = ['label' => 'Vuota', 'href' => ''];
        return $items;
    }
    public function personalRows(array $rows, object $user): array { $rows[] = ['key' => 'prova']; return $rows; }
    public function validatePersonal(array $input, object $user): array { return ['errore prova']; }
    public function personalUserValues(array $input, object $user): array { return ['prova' => 1]; }
    public function head(): string { return '<link rel="stylesheet" href="/prova.css">'; }
}

final class SenzaIndirizzi extends AccountPanel
{
    public function sections(): array { return ['overview', 'personal', 'billing']; }
}

$fresh = static function (): void { Route::reset(); AccountRoutes::reset(); };
$byName = static function (): array {
    $out = [];
    foreach (Route::all() as $route) {
        if (($route['name'] ?? '') !== '') { $out[$route['name']] = $route; }
    }
    return $out;
};
$posts = static fn (): array => array_values(array_map(static fn ($r) => $r['path'], array_filter(Route::all(), static fn ($r) => strtoupper((string) $r['method']) === 'POST')));
$user = (object) ['id' => 1, 'name' => 'Ada'];

// Rotte del core, nomi e percorsi di §4.1.
$fresh();
AccountRoutes::register(new AccountPanel());
$routes = $byName();
$expected = [
    'account.index' => '/account/',
    'account.personal' => '/account/dati-personali/',
    'account.email.confirm' => '/account/email/conferma/',
    'account.addresses' => '/account/indirizzi/',
    'account.addresses.create' => '/account/indirizzi/nuovo/',
    'account.addresses.edit' => '/account/indirizzi/{id}/',
    'account.addresses.delete' => '/account/indirizzi/{id}/elimina/',
    'account.billing' => '/account/fatturazione/',
];
foreach ($expected as $name => $path) {
    $check(($routes[$name]['path'] ?? null) === $path, "{$name} deve stare su {$path}");
    $public = $name === 'account.email.confirm';
    $check((bool) ($routes[$name]['private'] ?? false) === !$public, "{$name}: protezione sbagliata");
    if (!$public) {
        $check(($routes[$name]['permit'] ?? null) === ['client'], "{$name} deve permettere solo client");
    }
}
foreach (['/account/dati-personali/', '/account/indirizzi/nuovo/', '/account/indirizzi/{id}/', '/account/indirizzi/{id}/elimina/', '/account/fatturazione/'] as $path) {
    $check(in_array($path, $posts(), true), "manca il POST di {$path}");
}

// Chiamate ripetute: innocue con la stessa classe, errore con un'altra.
$count = count(Route::all());
AccountRoutes::register(new AccountPanel());
$check(count(Route::all()) === $count, 'register ripetuto non deve duplicare le route');
try {
    AccountRoutes::register(new SenzaIndirizzi());
    $check(false, 'una classe diversa deve lanciare LogicException');
} catch (\LogicException) {
}

// Estensione prima e dopo register, una sola volta.
foreach (['prima', 'dopo'] as $when) {
    $fresh();
    if ($when === 'prima') { AccountRoutes::extend(new ProvaExtension()); AccountRoutes::extend(new ProvaExtension()); }
    AccountRoutes::register(new AccountPanel());
    if ($when === 'dopo') { AccountRoutes::extend(new ProvaExtension()); AccountRoutes::extend(new ProvaExtension()); }
    $prova = array_values(array_filter(Route::all(), static fn ($r) => $r['path'] === '/account/prova/'));
    $check(count($prova) === 1, "estensione {$when}: /account/prova/ una volta sola");
    $check((bool) ($prova[0]['private'] ?? false) && ($prova[0]['permit'] ?? null) === ['client'], "estensione {$when}: route protetta");
}

// Menu: ordine, voce attiva, aggiunte e tolte dell'estensione, niente voci vuote.
$items = AccountRoutes::panel()->navigation($user, 'personal');
$check(array_keys($items) === ['overview', 'personal', 'addresses', 'prova'], 'ordine del menu sbagliato: '.implode(',', array_keys($items)));
$check(($items['personal']['active'] ?? false) === true && ($items['overview']['active'] ?? true) === false, 'voce attiva sbagliata');
$check(($items['overview']['icon'] ?? '') === 'bi bi-house' && ($items['prova']['href'] ?? '') !== '', 'icone o href mancanti');

// Hook a cascata.
$panel = AccountRoutes::panel();
$check(count($panel->personalRows([], $user)) === 1, 'personalRows non passa dalle estensioni');
$check($panel->validatePersonal([], $user) === ['errore prova'], 'validatePersonal non raccoglie i messaggi');
$check($panel->personalUserValues([], $user) === ['prova' => 1], 'personalUserValues non unisce i valori');
$check(str_contains($panel->head(), '/prova.css'), 'head non concatena');

// Sezione spenta: né route né voce.
$fresh();
AccountRoutes::register(new SenzaIndirizzi());
$check(!isset($byName()['account.addresses']) && !isset($byName()['account.addresses.delete']), 'indirizzi spenti: non devono esserci route');
$check(!isset(AccountRoutes::panel()->navigation($user)['addresses']), 'indirizzi spenti: non deve esserci la voce');
$check(isset($byName()['account.index']), 'account.index c\'è sempre');

if ($failures !== []) {
    echo implode("\n", $failures)."\n";
    exit(1);
}
echo "account-routes: ok\n";
```

- [ ] **Step 2: Lancia il test e verifica che fallisca**

Run: `cd $W/app && php tests/account-routes.php`
Expected: FAIL con `Class "Wonder\Auth\Frontend\BaseAccountExtension" not found`.

- [ ] **Step 3: Scrivi interfaccia, classe base e `AccountRoutes`**

`AccountExtension` e `BaseAccountExtension` seguono il blocco Interfaces, con
un docblock di una riga ciascuna. `AccountRoutes`:

```php
<?php

namespace Wonder\Auth\Frontend;

use Wonder\Http\Route;

/** Pannello account del core, a richiesta: il sito o un modulo lo registra, i moduli lo estendono. */
final class AccountRoutes
{
    private static ?AccountPanel $panel = null;
    private static ?AuthProfile $auth = null;
    /** @var array<class-string, AccountExtension> */
    private static array $extensions = [];

    public static function register(AccountPanel $panel, ?AuthProfile $auth = null): void
    {
        if (self::$panel !== null) {
            if (get_class(self::$panel) !== get_class($panel)) {
                throw new \LogicException('Conflicting account panel');
            }
            return;
        }
        self::$panel = $panel;
        self::$auth = $auth;
        $handler = self::handler();

        Route::area('frontend')->response('html')->name('account.')->prefix('/account')
            ->group(static function () use ($handler): void {
                Route::get('/email/conferma/', $handler, ['account_action' => 'email.confirm'])->name('email.confirm');
            });

        self::group(static function () use ($panel, $handler): void {
            Route::get('/', $handler, ['account_action' => 'index'])->name('index');
            $page = static function (string $path, string $action) use ($handler): void {
                Route::get($path, $handler, ['account_action' => $action])->name($action);
                Route::post($path, $handler, ['account_action' => $action]);
            };
            if ($panel->enabled('personal')) {
                $page('/dati-personali/', 'personal');
            }
            if ($panel->enabled('addresses')) {
                Route::get('/indirizzi/', $handler, ['account_action' => 'addresses'])->name('addresses');
                $page('/indirizzi/nuovo/', 'addresses.create');
                Route::get('/indirizzi/{id}/', $handler, ['account_action' => 'addresses.edit'])->name('addresses.edit')->where('id', '[0-9]+');
                Route::post('/indirizzi/{id}/', $handler, ['account_action' => 'addresses.edit'])->where('id', '[0-9]+');
                Route::post('/indirizzi/{id}/elimina/', $handler, ['account_action' => 'addresses.delete'])->name('addresses.delete')->where('id', '[0-9]+');
            }
            if ($panel->enabled('billing')) {
                $page('/fatturazione/', 'billing');
            }
        });

        foreach (self::$extensions as $extension) {
            $extension->routes();
        }
    }

    public static function extend(AccountExtension $extension): void
    {
        if (isset(self::$extensions[get_class($extension)])) {
            return;
        }
        self::$extensions[get_class($extension)] = $extension;
        if (self::$panel !== null) {
            $extension->routes();
        }
    }

    /** Route private nel gruppo del pannello: stesso prefisso, nomi `account.*`, login e permessi. */
    public static function group(callable $routes): void
    {
        Route::area('frontend')->response('html')->name('account.')->prefix('/account')
            ->guarded()->permit(self::panel()->authorities())->group($routes);
    }

    public static function panel(): AccountPanel { return self::$panel ?? new AccountPanel(); }
    public static function auth(): AuthProfile { return self::$auth ?? new AuthProfile(); }
    /** @return list<AccountExtension> */
    public static function extensions(): array { return array_values(self::$extensions); }
    public static function handler(): string { return dirname(__DIR__, 3).'/app/http/frontend/account.php'; }

    public static function reset(): void
    {
        self::$panel = null;
        self::$auth = null;
        self::$extensions = [];
    }
}
```

`Route::get` e `Route::post`, usati dentro `group()`, ereditano `guarded` e
`permit` del gruppo: il record di `Route::all()` ha le chiavi `private` e
`permit`, e il `permit` del gruppo si unisce a quello della route.

- [ ] **Step 4: Riscrivi `AccountPanel`**

```php
<?php

namespace Wonder\Auth\Frontend;

use Wonder\Http\Route;
use Wonder\View\View;

/** Pannello account del core; il sito lo sostituisce con una sottoclasse, i moduli lo estendono con AccountExtension. */
class AccountPanel
{
    private const ITEMS = [
        'overview' => ['account.index', 'bi bi-house'],
        'personal' => ['account.personal', 'bi bi-person'],
        'addresses' => ['account.addresses', 'bi bi-geo-alt'],
        'billing' => ['account.billing', 'bi bi-receipt'],
    ];

    public function parentLayout(): string { return 'frontend.main'; }
    public function title(): string { return (string) __t('account.title'); }
    public function authorities(): array { return ['client']; }
    public function sections(): array { return ['overview', 'personal', 'addresses', 'billing']; }
    public function enabled(string $section): bool { return in_array($section, $this->sections(), true); }

    /** Voci del menu, già filtrate: una voce senza href o etichetta non esce. */
    public function navigation(object $user, string $active = ''): array
    {
        $items = [];
        foreach (self::ITEMS as $key => [$route, $icon]) {
            if ($this->enabled($key)) {
                $items[$key] = ['label' => (string) __t('account.navigation.'.$key), 'href' => Route::url($route), 'icon' => $icon];
            }
        }
        foreach (AccountRoutes::extensions() as $extension) {
            $items = $extension->navigation($items, $user);
        }
        $out = [];
        foreach ($items as $key => $item) {
            if (!is_array($item) || trim((string) ($item['href'] ?? '')) === '' || trim((string) ($item['label'] ?? '')) === '') {
                continue;
            }
            $out[$key] = ['key' => (string) $key, 'icon' => 'bi bi-circle'] + $item + ['active' => false];
            $out[$key]['active'] = $key === $active;
        }
        return $out;
    }

    public function overviewRows(array $rows, object $user): array { return $this->cascade('overviewRows', $rows, $user); }
    public function personalRows(array $rows, object $user): array { return $this->cascade('personalRows', $rows, $user); }
    public function personalFields(array $fields, object $user): array { return $this->cascade('personalFields', $fields, $user); }

    public function validatePersonal(array $input, object $user): array
    {
        $messages = [];
        foreach (AccountRoutes::extensions() as $extension) {
            $messages = array_merge($messages, $extension->validatePersonal($input, $user));
        }
        return $messages;
    }

    public function personalUserValues(array $input, object $user): array
    {
        $values = [];
        foreach (AccountRoutes::extensions() as $extension) {
            $values = array_merge($values, $extension->personalUserValues($input, $user));
        }
        return $values;
    }

    public function afterPersonalSaved(array $input, object $user): void
    {
        foreach (AccountRoutes::extensions() as $extension) {
            $extension->afterPersonalSaved($input, $user);
        }
    }

    public function head(): string
    {
        return implode("\n", array_map(static fn (AccountExtension $e): string => $e->head(), AccountRoutes::extensions()));
    }

    public function layout(array $data = []): void
    {
        View::layout('frontend.account.panel', $data + ['account_panel' => $this]);
    }

    private function cascade(string $hook, array $value, object $user): array
    {
        foreach (AccountRoutes::extensions() as $extension) {
            $value = $extension->{$hook}($value, $user);
        }
        return $value;
    }
}
```

Una sottoclasse del sito che sovrascrive un hook chiama `parent::` per tenere
le estensioni. Scrivilo nel docblock della classe.

In `$W/ecommerce/src/Frontend/Account/EcommerceAccountPanel.php`, cambia solo
la firma: `public function navigation(object $user, string $active = ''): array`.
Il file sparisce nel Task 8.

- [ ] **Step 5: Lancia i test**

Run: `cd $W/app && php tests/account-routes.php && php tests/auth-frontend.php && php tests/account-password.php`
Expected: `account-routes: ok` e gli altri due verdi.

- [ ] **Step 6: Commit**

```bash
cd $W/app && git add class/Auth/Frontend/AccountExtension.php class/Auth/Frontend/BaseAccountExtension.php class/Auth/Frontend/AccountRoutes.php class/Auth/Frontend/AccountPanel.php tests/account-routes.php
git commit -m "Account: rotte italiane del pannello, sezioni e estensioni dei moduli

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
cd $W/ecommerce && git add src/Frontend/Account/EcommerceAccountPanel.php
git commit -m "Account: firma del menu allineata al pannello del core

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Servizi dei dati (dati personali, indirizzi, fatturazione)

Repo: `$W/app`; test in `$W/ecommerce`.

**Files:**
- Create: `$W/app/class/Auth/Frontend/AccountPersonal.php`, `AccountAddresses.php`, `AccountBilling.php`
- Modify: `$W/app/class/Auth/Frontend/AuthValidationAlert.php` (`messageKeys`)
- Modify: `$W/app/resources/lang/{it,en}/account.json`
- Create: `$W/ecommerce/tests/integrazione/AccountCoreTest.php`

**Interfaces:**
- Consumes:
  - `AccountPanel::personalUserValues`, `validatePersonal` e `afterPersonalSaved`
    (Task 3);
  - `AuthValidator::completion` e `canonicalPhone` (Task 2);
  - `ContactAccount::link(int $userId, array $input = []): object{success, reason, contact_id}`;
  - `AccountAddressValidation::validate(AddressExtension $address, array $values, array $extra = []): array`
    (messaggi tradotti).
- Produces, in `AccountPersonal`:
  - `save(int $userId, array $input, AccountPanel $panel, bool $phoneRequired): object{success: bool, errors: array<string,string>, messages: list<string>}`;
  - `rows(object $user, array $contact, bool $hasPassword): array`, che dà le
    righe `personal`, `email` e `password` nella forma del Task 3.
- Produces, in `AccountAddresses`:
  - `all(int $contactId): list<array>`;
  - `find(int $contactId, int $addressId): ?array`;
  - `save(int $contactId, array $input, ?int $addressId = null): object{success, messages, id}`;
  - `delete(int $contactId, int $addressId): bool`;
  - `card(array $address): array{name, phone, phone_href, lines: list<string>}`.
- Produces, in `AccountBilling`:
  - `save(int $contactId, array $input): object{success, messages}`;
  - `rows(array $contact): array`.
- Produces: le chiavi di `AuthValidationAlert` `email.same`,
  `birth_date.invalid`, `contact.conflict` e `user.save`.

- [ ] **Step 1: Scrivi il test che fallisce**

`$W/ecommerce/tests/integrazione/AccountCoreTest.php`. L'intestazione è quella
del Task 2. Il file aggiunge una funzione di appoggio per creare un cliente:

```php
use Wonder\App\Models\Contacts\Contact;
use Wonder\App\Models\Contacts\ContactAddress;
use Wonder\Auth\Frontend\AccountAddresses;
use Wonder\Auth\Frontend\AccountPanel;
use Wonder\Auth\Frontend\AccountPersonal;
use Wonder\Auth\Frontend\ContactAccount;
use Wonder\App\Models\User\User;
use Wonder\Sql\Transaction;

final class AnnullaAccountCore extends RuntimeException {}

/** Come la fixture di AuthAccountTest.php:140-163: User::create, email verificata, password con user(). */
function clienteDiProva(string $prefix): int
{
    $email = $prefix.'-'.bin2hex(random_bytes(6)).'@example.com';
    $created = User::create([
        'name' => 'Ada', 'surname' => 'Lovelace', 'email' => $email,
        'username' => create_link(explode('@', $email)[0], 'user', 'username'),
        'authority' => json_encode(['client'], JSON_THROW_ON_ERROR),
        'area' => json_encode(['frontend'], JSON_THROW_ON_ERROR),
        'active' => 'true',
    ]);
    $id = (int) ($created->insert_id ?? 0);
    markUserEmailVerified($id, date('Y-m-d H:i:s'));
    $GLOBALS['ALERT'] = null;
    user([
        'password' => 'password-di-prova-123', 'password_confirmation' => 'password-di-prova-123',
        'area' => 'frontend', 'authority' => 'client',
    ], $id);
    $GLOBALS['ALERT'] = null;
    return $id;
}

try {
    Transaction::run(static function (): void {
        $panel = new AccountPanel();

        // Dati personali con data di nascita.
        $userId = clienteDiProva('account-core');
        $phone = '33'.random_int(10000000, 99999999);
        $saved = AccountPersonal::save($userId, ['name' => 'Grace', 'surname' => 'Hopper', 'birth_date' => '1990-05-17', 'phone_prefix' => '+39', 'phone' => $phone], $panel, false);
        $contact = Contact::find(['user_id' => $userId], 1);
        check('i dati personali salvano nome, cellulare canonico e data di nascita', fn () =>
            $saved->success && infoUser($userId, 'id')->name === 'Grace'
            && infoUser($userId, 'id')->phone === '+39'.$phone
            && ($contact['birth_date'] ?? null) === '1990-05-17');

        $bad = AccountPersonal::save($userId, ['name' => 'Grace', 'surname' => 'Hopper', 'birth_date' => '1990-02-31'], $panel, false);
        check('una data di nascita impossibile è un errore', fn () => !$bad->success && ($bad->errors['birth_date'] ?? '') === 'invalid');

        // Scheda in conflitto: nulla viene scritto.
        $otherId = clienteDiProva('account-other');
        $victimId = clienteDiProva('account-victim');
        $victimEmail = infoUser($victimId, 'id')->email;
        $conflictId = (int) ContactAccount::link($otherId)->contact_id;
        Contact::update(['email' => $victimEmail], $conflictId);
        $before = infoUser($victimId, 'id')->name;
        $conflict = AccountPersonal::save($victimId, ['name' => 'Cambiato', 'surname' => 'X', 'birth_date' => '2000-01-01'], $panel, false);
        check('con la scheda in conflitto non si scrive nulla', fn () =>
            !$conflict->success && ($conflict->errors['contact'] ?? '') === 'conflict'
            && infoUser($victimId, 'id')->name === $before
            && (Contact::find(['id' => $conflictId], 1)['birth_date'] ?? null) === null);

        // Indirizzi: aggiunta, modifica, eliminazione propria e altrui.
        $contactId = (int) $contact['id'];
        $values = ['name' => 'Ada', 'surname' => 'Lovelace', 'phone_prefix' => '+39', 'phone' => '3331234567', 'country' => 'IT', 'province' => 'MI', 'cap' => '20100', 'city' => 'Milano', 'street' => 'Via Roma', 'number' => '1'];
        $created = AccountAddresses::save($contactId, $values);
        $addressId = (int) $created->id;
        check('un indirizzo nuovo nasce sulla scheda del cliente', fn () => $created->success && AccountAddresses::find($contactId, $addressId) !== null);

        $edited = AccountAddresses::save($contactId, ['city' => 'Torino'] + $values, $addressId);
        check('la modifica tocca l\'indirizzo giusto', fn () => $edited->success && AccountAddresses::find($contactId, $addressId)['city'] === 'Torino');

        $invalid = AccountAddresses::save($contactId, ['cap' => ''] + $values);
        check('un indirizzo senza cap non si salva e dà messaggi', fn () => !$invalid->success && $invalid->messages !== []);

        $otherContact = (int) Contact::find(['user_id' => $otherId], 1)['id'];
        check('l\'indirizzo di un altro non si trova e non si elimina', fn () =>
            AccountAddresses::find($otherContact, $addressId) === null
            && AccountAddresses::delete($otherContact, $addressId) === false
            && AccountAddresses::find($contactId, $addressId) !== null);
        check('il cliente elimina il proprio indirizzo', fn () =>
            AccountAddresses::delete($contactId, $addressId) && AccountAddresses::find($contactId, $addressId) === null);

        throw new AnnullaAccountCore();
    });
} catch (AnnullaAccountCore) {
}

summary();
```

Run: `cd $W/ecommerce && WI_TEST_SITE=$S php tests/integrazione/AccountCoreTest.php`
Expected: FAIL con `Class "Wonder\Auth\Frontend\AccountPersonal" not found`.

- [ ] **Step 2: Chiavi nuove degli errori**

1. In `AuthValidationAlert::messageKeys`, aggiungi al `match`:
   ```php
   'email.same' => 'account.email.errors.same',
   'birth_date.invalid' => 'account.personal.errors.birth_date',
   'contact.conflict' => 'account.errors.contact',
   'user.save' => 'account.errors.save',
   ```
2. In `account.json` (it ed en):
   - `personal.errors.birth_date`: «Data di nascita: valore non valido.» /
     "Date of birth: invalid value.";
   - `email.errors.same`: «La nuova email è uguale a quella attuale.» / "The
     new email matches the current one.".
   - `errors.save` ed `errors.contact` ci sono già.

- [ ] **Step 3: Scrivi `AccountPersonal`**

La logica è quella di `ecommerce/src/Frontend/Account/AccountController.php:89-125`.
Cambiano tre cose:
- le chiavi degli errori si raccolgono, invece dei messaggi;
- la data di nascita;
- tutto passa in una transazione con `ContactAccount::link`.

```php
<?php

namespace Wonder\Auth\Frontend;

use Wonder\App\Models\Contacts\Contact;
use Wonder\Sql\Transaction;

/** Dati personali dal pannello: utente e scheda insieme, o niente. */
final class AccountPersonal
{
    public static function save(int $userId, array $input, AccountPanel $panel, bool $phoneRequired): object
    {
        $user = \infoUser($userId, 'id');
        $errors = [];
        $name = trim((string) ($input['name'] ?? ''));
        $surname = trim((string) ($input['surname'] ?? ''));
        if ($name === '') { $errors['name'] = 'required'; }
        if ($surname === '') { $errors['surname'] = 'required'; }
        $errors += array_intersect_key(AuthValidator::completion($input, false, $phoneRequired, false), ['phone' => true]);
        $phone = AuthValidator::canonicalPhone($input);
        if (!isset($errors['phone']) && $phone !== '' && !\unique($phone, 'user', 'phone', $userId)) {
            $errors['phone'] = 'not_unique';
        }
        $birth = trim((string) ($input['birth_date'] ?? ''));
        $date = $birth === '' ? null : \DateTimeImmutable::createFromFormat('!Y-m-d', $birth);
        if ($birth !== '' && (!$date || $date->format('Y-m-d') !== $birth || $date > new \DateTimeImmutable('today'))) {
            $errors['birth_date'] = 'invalid';
        }
        $messages = $panel->validatePersonal($input, $user);
        if ($errors !== [] || $messages !== []) {
            return (object) ['success' => false, 'errors' => $errors, 'messages' => $messages];
        }

        try {
            Transaction::run(static function () use ($userId, $input, $panel, $user, $name, $surname, $phone, $birth): void {
                $GLOBALS['ALERT'] = null;
                $result = \user(array_merge($panel->personalUserValues($input, $user), [
                    'name' => $name, 'surname' => $surname,
                    'phone_prefix' => (string) ($input['phone_prefix'] ?? ''), 'phone' => $phone,
                    'area' => 'frontend', 'authority' => 'client',
                ]), $userId);
                if (!empty($GLOBALS['ALERT']) || !($result->user->exists ?? false)) {
                    throw new AccountSaveFailed('user');
                }
                $link = ContactAccount::link($userId, ['phone_prefix' => (string) ($input['phone_prefix'] ?? ''), 'phone' => (string) ($input['phone'] ?? '')]);
                if (!($link->success ?? false)) {
                    throw new AccountSaveFailed('contact');
                }
                Contact::update(['birth_date' => $birth !== '' ? $birth : null], (int) $link->contact_id);
                $panel->afterPersonalSaved($input, $result->user);
            });
        } catch (AccountSaveFailed $e) {
            return (object) ['success' => false, 'errors' => $e->getMessage() === 'contact' ? ['contact' => 'conflict'] : ['user' => 'save'], 'messages' => []];
        }

        return (object) ['success' => true, 'errors' => [], 'messages' => []];
    }

    public static function rows(object $user, array $contact, bool $hasPassword): array
    {
        $edit = static fn (string $modal): array => ['label' => (string) __t('account.actions.edit'), 'href' => '', 'modal' => $modal, 'icon' => 'bi bi-pencil', 'disabled' => false, 'hint' => ''];
        $birth = (string) ($contact['birth_date'] ?? '');
        return [
            ['key' => 'personal', 'columns' => [
                ['label' => (string) __t('account.personal.name'), 'value' => trim((string) ($user->name ?? '').' '.(string) ($user->surname ?? ''))],
                ['label' => (string) __t('account.personal.birth_date'), 'value' => $birth !== '' ? date('d/m/Y', strtotime($birth)) : '—'],
                ['label' => (string) __t('account.personal.phone'), 'value' => trim((string) ($user->phone ?? '')) ?: '—'],
            ], 'action' => $edit('account-personal')],
            ['key' => 'email', 'columns' => [['label' => (string) __t('account.email.label'), 'value' => (string) ($user->email ?? '')]], 'action' => $edit('account-email')],
            ['key' => 'password', 'columns' => [['label' => (string) __t('account.password.label'), 'value' => $hasPassword ? '**********' : (string) __t('account.password.summary_missing')]], 'action' => $edit('account-password')],
        ];
    }
}
```

`AccountSaveFailed` è un'eccezione interna. Si dichiara in coda allo stesso
file (`final class AccountSaveFailed extends \RuntimeException {}`) e la usa
anche `AccountEmail` del Task 5. `Transaction::run` annulla tutto e rilancia,
quindi il `catch` vede l'eccezione dopo il rollback.

- [ ] **Step 4: Scrivi `AccountAddresses` e `AccountBilling`**

`AccountAddresses`. Le righe si leggono come `addresses()` in
`ecommerce/.../AccountController.php:316-329` e si salvano come in
`shippingEditor` (`:210-258`). Cambiano:
- la proprietà, controllata a ogni passo;
- `label`, che non c'è più;
- l'eliminazione, che è nuova.

```php
<?php

namespace Wonder\Auth\Frontend;

use Throwable;
use Wonder\App\Models\Contacts\ContactAddress;

/** Indirizzi della scheda del cliente: ognuno si legge, si cambia e si elimina solo dalla sua scheda. */
final class AccountAddresses
{
    public static function all(int $contactId): array
    {
        if ($contactId <= 0) { return []; }
        try {
            $rows = ContactAddress::find(['contact_id' => $contactId], null, 'position', 'ASC');
            return is_array($rows) ? array_values($rows) : [];
        } catch (Throwable) {
            return [];
        }
    }

    public static function find(int $contactId, int $addressId): ?array
    {
        if ($contactId <= 0 || $addressId <= 0) { return null; }
        $row = ContactAddress::find(['id' => $addressId, 'contact_id' => $contactId], 1);
        return is_array($row) && $row !== [] ? $row : null;
    }

    public static function save(int $contactId, array $input, ?int $addressId = null): object
    {
        if ($addressId !== null && self::find($contactId, $addressId) === null) {
            return (object) ['success' => false, 'messages' => [(string) __t('account.errors.save')], 'id' => 0];
        }
        $address = ContactAddress::address();
        $values = array_intersect_key($input, $address->labels());
        $messages = AccountAddressValidation::validate($address, $values);
        if ($messages !== []) {
            return (object) ['success' => false, 'messages' => $messages, 'id' => $addressId ?? 0];
        }
        if ($addressId === null) {
            $result = ContactAddress::create($values + ['contact_id' => $contactId, 'position' => count(self::all($contactId)) + 1]);
            $id = (int) ($result->insert_id ?? 0);
        } else {
            $result = ContactAddress::update($values, $addressId);
            $id = $addressId;
        }
        return ($result->success ?? false)
            ? (object) ['success' => true, 'messages' => [], 'id' => $id]
            : (object) ['success' => false, 'messages' => [(string) __t('account.errors.save')], 'id' => $id];
    }

    public static function delete(int $contactId, int $addressId): bool
    {
        return self::find($contactId, $addressId) !== null && (bool) (ContactAddress::delete($addressId)->success ?? false);
    }

    /** Righe della scheda: «via numero, cap» e «città (provincia)». */
    public static function card(array $address): array
    {
        $phone = trim((string) ($address['phone_prefix'] ?? '').' '.(string) ($address['phone'] ?? ''));
        $province = trim((string) ($address['province'] ?? ''));
        return [
            'name' => trim((string) ($address['name'] ?? '').' '.(string) ($address['surname'] ?? '')),
            'phone' => $phone,
            'phone_href' => $phone !== '' ? 'tel:'.preg_replace('/[^0-9+]/', '', $phone) : '',
            'lines' => array_values(array_filter([
                trim(trim((string) ($address['street'] ?? '').' '.(string) ($address['number'] ?? '')).', '.(string) ($address['cap'] ?? ''), ', '),
                trim((string) ($address['city'] ?? '').($province !== '' ? ' ('.$province.')' : '')),
            ])),
        ];
    }
}
```

`AccountBilling`:
- `save` riprende `billing()` di `ecommerce/.../AccountController.php:140-175`:
  - whitelist dalle `labels()` dell'indirizzo di fatturazione di `Contact`;
  - validazione con `AccountAddressValidation::validate`;
  - `Contact::update($values, $contactId)`;
  - restituisce `{success, messages}`.
- `rows(array $contact)` restituisce una sola riga `billing`:
  - colonne «Intestatario» (ragione sociale, oppure nome e cognome), «Codice
    fiscale / P. IVA» e «Indirizzo» (le righe di `addressLines` di oggi, unite
    con `, `);
  - azione `Modifica` con `modal = account-billing`;
  - le colonne vuote mostrano `—`.

- [ ] **Step 5: Lancia i test**

Run: `cd $W/ecommerce && WI_TEST_SITE=$S php tests/integrazione/AccountCoreTest.php`
Expected: `7 test, 0 falliti`.

- [ ] **Step 6: Commit**

```bash
cd $W/app && git add class/Auth/Frontend/AccountPersonal.php class/Auth/Frontend/AccountAddresses.php class/Auth/Frontend/AccountBilling.php class/Auth/Frontend/AuthValidationAlert.php resources/lang/it/account.json resources/lang/en/account.json
git commit -m "Account: servizi per dati personali, indirizzi e fatturazione

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
cd $W/ecommerce && git add tests/integrazione/AccountCoreTest.php
git commit -m "Test: dati personali, indirizzi e scheda in conflitto del pannello del core

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Cambio email con conferma

Repo: `$W/app`; test in `$W/ecommerce`.

**Files:**
- Create: `$W/app/class/Auth/Frontend/AccountEmail.php`
- Modify: `$W/app/resources/lang/{it,en}/account.json` (blocco `email`)
- Create: `$W/ecommerce/tests/integrazione/AccountEmailTest.php`

**Interfaces:**
- Consumes:
  - `OneTimeToken(string $purpose, int $ttlSeconds)`;
  - `->issue(int $subjectUserId, ?int $actorUserId = null, ?string $continueUrl = null, array $metadata = [], bool $revokeOpenTokens = true): object{token}`;
  - `->consume(string $token): ?object{subject_user_id, metadata}`;
  - `\checkPassword`, `\unique($str, $table, $column, $id)`, `\markUserEmailVerified($id, $at)`, `\sendMail($from, $to, $subject, $body)`.
- Produces:
  - `AccountEmail::request(object $user, string $newEmail, string $password, string $confirmUrl, ?callable $mailer = null): object{success, errors: array<string,string>}`.
    `$confirmUrl` è l'URL assoluto di `account.email.confirm`; il controller lo
    passa con `Route::url('account.email.confirm')`. `$mailer` riceve
    `($to, $subject, $body)`: il controller non lo passa (vale `\sendMail`),
    i test passano una closure che tiene il messaggio.
  - `AccountEmail::confirm(string $token): string`, che restituisce
    `'confirmed' | 'invalid' | 'taken'`.
  - La costante `AccountEmail::PURPOSE = 'email_change'`.

- [ ] **Step 1: Scrivi il test che fallisce**

`$W/ecommerce/tests/integrazione/AccountEmailTest.php`. L'intestazione è quella
del Task 2, poi la funzione `clienteDiProva()` del Task 4, copiata qui.
`\sendMail` spedisce davvero (PHPMailer o Brevo): il test non lo chiama mai e
passa a `request()` un mailer che tiene il messaggio. Il token si legge dal
link nel corpo.

```php
use Wonder\Auth\Frontend\AccountEmail;
use Wonder\App\Models\User\User;
use Wonder\Sql\Transaction;

final class AnnullaAccountEmail extends RuntimeException {}

/** Mailer finto: tiene l'ultimo messaggio e ne estrae il token. */
final class PostaDiProva
{
    public array $sent = [];

    public function __invoke(string $to, string $subject, string $body): void
    {
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
    }

    public function token(): string
    {
        $last = end($this->sent);
        return preg_match('/[?&]token=([^\s"&<]+)/', (string) ($last['body'] ?? ''), $m) ? rawurldecode($m[1]) : '';
    }
}

try {
    Transaction::run(static function (): void {
        $url = 'https://example.test/account/email/conferma/';
        $mail = new PostaDiProva();
        $userId = clienteDiProva('email-change');
        $user = infoUser($userId, 'id');
        $new = 'nuova-'.bin2hex(random_bytes(6)).'@example.com';

        $wrong = AccountEmail::request($user, $new, 'sbagliata', $url, $mail);
        check('password sbagliata: niente richiesta', fn () => !$wrong->success && ($wrong->errors['current_password'] ?? '') === 'wrong');
        $same = AccountEmail::request($user, $user->email, 'password-di-prova-123', $url, $mail);
        check('email uguale: errore email.same', fn () => !$same->success && ($same->errors['email'] ?? '') === 'same');
        $takenId = clienteDiProva('email-taken');
        $busy = AccountEmail::request($user, infoUser($takenId, 'id')->email, 'password-di-prova-123', $url, $mail);
        check('email di un altro: errore email.exists', fn () => !$busy->success && ($busy->errors['email'] ?? '') === 'exists');
        check('nessun errore: nessuna email spedita', fn () => $mail->sent === []);

        AccountEmail::request($user, $new, 'password-di-prova-123', $url, $mail);
        $first = $mail->token();
        AccountEmail::request($user, $new, 'password-di-prova-123', $url, $mail);
        $second = $mail->token();
        check('il link va alla nuova casella', fn () => end($mail->sent)['to'] === $new && $second !== '' && $second !== $first);
        check('la vecchia email resta valida fino al clic', fn () => infoUser($userId, 'id')->email === $user->email);
        check('un link vecchio dopo una richiesta nuova non vale', fn () => AccountEmail::confirm($first) === 'invalid');

        $_SESSION['user_id'] = $takenId;
        check('la conferma cambia email e verifica', fn () =>
            AccountEmail::confirm($second) === 'confirmed'
            && infoUser($userId, 'id')->email === $new
            && (string) infoUser($userId, 'id')->email_verified === '1');
        check('la conferma non tocca la sessione di chi è loggato', fn () => $_SESSION['user_id'] === $takenId);
        unset($_SESSION['user_id']);
        check('un link già usato non vale', fn () => AccountEmail::confirm($second) === 'invalid');

        $raceId = clienteDiProva('email-race');
        $raceUser = infoUser($raceId, 'id');
        $wanted = 'contesa-'.bin2hex(random_bytes(6)).'@example.com';
        AccountEmail::request($raceUser, $wanted, 'password-di-prova-123', $url, $mail);
        $pending = $mail->token();
        $GLOBALS['ALERT'] = null;
        user(['email' => $wanted, 'area' => 'frontend', 'authority' => 'client'], clienteDiProva('email-thief'));
        $GLOBALS['ALERT'] = null;
        check('email presa nel frattempo: esito taken, nulla cambia', fn () =>
            AccountEmail::confirm($pending) === 'taken' && infoUser($raceId, 'id')->email === $raceUser->email);

        check('un token inventato non vale', fn () => AccountEmail::confirm('inventato') === 'invalid');

        throw new AnnullaAccountEmail();
    });
} catch (AnnullaAccountEmail) {
}

summary();
```

Per il caso «scaduto» basta il token inventato e quello già usato:
`OneTimeToken::consume` tratta allo stesso modo scaduti, revocati e
sconosciuti, e lo prova già il core.

Run: `cd $W/ecommerce && WI_TEST_SITE=$S php tests/integrazione/AccountEmailTest.php`
Expected: FAIL con `Class "Wonder\Auth\Frontend\AccountEmail" not found`.

- [ ] **Step 2: Scrivi `AccountEmail`**

```php
<?php

namespace Wonder\Auth\Frontend;

use Wonder\App\Models\Contacts\Contact;
use Wonder\Auth\OneTimeToken;
use Wonder\Sql\Transaction;

/** Cambio email: la nuova casella conferma con un link monouso; fino al clic resta valida la vecchia. */
final class AccountEmail
{
    public const PURPOSE = 'email_change';
    private const TTL = 86400;

    /** `$mailer($to, $subject, $body)`; senza, spedisce con `\sendMail`. */
    public static function request(object $user, string $newEmail, string $password, string $confirmUrl, ?callable $mailer = null): object
    {
        $userId = (int) ($user->id ?? 0);
        $email = strtolower(trim($newEmail));
        $row = \sqlSelect('user', ['id' => $userId], 1)->row ?? [];
        $errors = [];
        if (!\checkPassword($password, (string) ($row['password'] ?? ''))) {
            $errors['current_password'] = 'wrong';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'invalid';
        } elseif ($email === strtolower((string) ($user->email ?? ''))) {
            $errors['email'] = 'same';
        } elseif (!\unique($email, 'user', 'email', $userId)) {
            $errors['email'] = 'exists';
        }
        if ($errors !== []) {
            return (object) ['success' => false, 'errors' => $errors];
        }

        $issued = (new OneTimeToken(self::PURPOSE, self::TTL))->issue($userId, $userId, null, ['email' => $email]);
        $url = $confirmUrl.'?token='.rawurlencode($issued->token);
        $mailer ??= static fn (string $to, string $subject, string $body) => \sendMail((string) ($GLOBALS['SOCIETY']->email ?? ''), $to, $subject, $body);
        $mailer($email, (string) __t('account.email.mail_subject'), (string) __t('account.email.mail_body', ['url' => $url]));

        return (object) ['success' => true, 'errors' => []];
    }

    public static function confirm(string $token): string
    {
        $record = (new OneTimeToken(self::PURPOSE, self::TTL))->consume($token);
        $userId = (int) ($record->subject_user_id ?? 0);
        $email = strtolower(trim((string) ($record->metadata['email'] ?? '')));
        if ($record === null || $userId <= 0 || $email === '') {
            return 'invalid';
        }
        if (!\unique($email, 'user', 'email', $userId)) {
            return 'taken';
        }
        try {
            Transaction::run(static function () use ($userId, $email): void {
                $GLOBALS['ALERT'] = null;
                $result = \user(['email' => $email, 'area' => 'frontend', 'authority' => 'client'], $userId);
                if (!empty($GLOBALS['ALERT']) || !($result->user->exists ?? false)) {
                    throw new AccountSaveFailed('user');
                }
                \markUserEmailVerified($userId, date('Y-m-d H:i:s'));
                $contact = Contact::find(['user_id' => $userId], 1);
                if (is_array($contact) && !empty($contact['id'])) {
                    Contact::update(['email' => $email], (int) $contact['id']);
                }
            });
        } catch (AccountSaveFailed) {
            return 'invalid';
        }
        return 'confirmed';
    }
}
```

`issue()` restituisce il token in chiaro in `->token`; `consume()` restituisce
`metadata` già come array (verificato in `class/Auth/OneTimeToken.php`).

- [ ] **Step 3: Testi del blocco `email`**

In `account.json`, oggetto `email`. Valori in it, tra parentesi en:
- `label`: «Email» ("Email");
- `title`: «Modifica email» ("Change email");
- `current`: «Email corrente» ("Current email");
- `new`: «Nuova email» ("New email");
- `password`: «Password» ("Password");
- `sent`: «Ti abbiamo mandato un link a {{email}}: aprilo per confermare la nuova email.» ("We sent a link to {{email}}: open it to confirm your new email.");
- `mail_subject`: «Conferma la tua nuova email» ("Confirm your new email");
- `mail_body`: «Per confermare la nuova email apri questo link entro 24 ore: {{url}}» ("To confirm your new email open this link within 24 hours: {{url}}");
- `confirmed`: «La tua email è stata aggiornata.» ("Your email has been updated.");
- `invalid`: «Il link non è valido o è scaduto. Richiedi di nuovo il cambio email dal tuo account.» ("The link is invalid or has expired. Request the email change again from your account.");
- `taken`: «Questa email è già usata da un altro account.» ("This email is already used by another account.");
- `errors.same`: aggiunto nel Task 4.

- [ ] **Step 4: Lancia i test**

Run: `cd $W/ecommerce && WI_TEST_SITE=$S php tests/integrazione/AccountEmailTest.php`
Expected: `9 test, 0 falliti`.

- [ ] **Step 5: Commit**

```bash
cd $W/app && git add class/Auth/Frontend/AccountEmail.php resources/lang/it/account.json resources/lang/en/account.json
git commit -m "Account: cambio email con link di conferma monouso

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
cd $W/ecommerce && git add tests/integrazione/AccountEmailTest.php
git commit -m "Test: cambio email del pannello (link vecchio, usato, email presa)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Controller, layout, Panoramica e Dati personali

Repo: `$W/app`; test in `$W/ecommerce`.

**Files:**
- Create: `$W/app/class/Auth/Frontend/AccountController.php`, `AccountPage.php`, `AccountModal.php`
- Create: `$W/app/app/http/frontend/account.php`
- Create: `$W/app/app/view/pages/frontend/account/index.php`, `personal.php`
- Modify (riscrittura): `$W/app/app/view/layout/frontend/account/panel.php`
- Modify (riscrittura): `$W/app/app/view/components/frontend/account/navigation.php`, `row.php`
- Modify: `$W/app/resources/lang/{it,en}/account.json`
- Modify: `$W/ecommerce/tests/integrazione/AccountCoreTest.php` (casi del controller e del markup)

**Interfaces:**
- Consumes:
  - `AccountRoutes::panel()`, `auth()` ed `extensions()` (Task 3);
  - `AccountPersonal::save` e `rows` (Task 4);
  - `AccountEmail::request` e `confirm` (Task 5);
  - `AccountPassword::fields`, `validate` e `change`.
- Produces, in `AccountController` (non final; l'ecommerce lo estende):
  - `__construct(protected readonly AccountPanel $panel, protected readonly AuthProfile $auth)`;
  - `handle(string $action, array $parameters = []): void`;
  - protetti:
    - `user(): object`, `contact(int $userId): array` (crea il collegamento
      se manca, `[]` se fallisce);
    - `page(string $view, string $active, array $data = []): void`;
    - `flash(string $message): void`;
    - `requireCsrf(): void`;
    - `redirect(string $url): never`, `notFound(): never` e
      `invalidCsrf(): never`, che i test sovrascrivono.
- Produces, in `AccountPage`:
  - `render(string $view, array $data): void`. `$data` contiene `active`,
    `title`, `seo_url`, `modals`, `errors` e i dati della pagina.
  - `view(string $page): string`, il percorso della pagina del core.
- Produces, in `AccountModal`:
  - `make(string $id, string $title, array $fields, string $action, array $hidden = [], array $errors = [], bool $open = false): Modal`;
  - `confirm(string $id, string $title, string $text, string $action, string $label): Modal`.
- Produces, componenti:
  - `frontend.account.row`, che prende una riga nella forma del Task 3;
  - `frontend.account.navigation`, che prende `items`, `logout_url` e
    `logout_token`.

- [ ] **Step 1: Scrivi il test che fallisce (controller e markup)**

In coda ad `AccountCoreTest.php`, prima di `summary()`, aggiungi una sottoclasse
che non esce e un blocco nuovo nella stessa transazione. Il blocco va in una
seconda `Transaction::run` con la sua eccezione di annullamento.

```php
use Wonder\Auth\Frontend\AccountController;
use Wonder\Auth\Frontend\AccountRoutes;
use Wonder\Http\Csrf;
use Wonder\Http\Route;

final class UscitaDiProva extends RuntimeException {}

final class ControllerDiProva extends AccountController
{
    protected function redirect(string $url): never { throw new UscitaDiProva('redirect '.$url); }
    protected function notFound(): never { throw new UscitaDiProva('404'); }
    protected function invalidCsrf(): never { throw new UscitaDiProva('419'); }
}

function pagina(string $action, array $parameters = [], string $method = 'GET', array $post = []): string
{
    $_SERVER['REQUEST_METHOD'] = $method;
    $_POST = $post;
    ob_start();
    try {
        (new ControllerDiProva(AccountRoutes::panel(), AccountRoutes::auth()))->handle($action, $parameters);
        return (string) ob_get_clean();
    } catch (UscitaDiProva $e) {
        ob_end_clean();
        return $e->getMessage();
    }
}
```

I casi:

```php
Route::reset();
AccountRoutes::reset();
AccountRoutes::register(new AccountPanel()); // senza moduli: nessuna estensione

$userId = clienteDiProva('account-http');
$_SESSION['user_id'] = $userId;
$html = pagina('personal');
check('Dati personali: tre righe con Modifica che apre il proprio modal', fn () =>
    substr_count($html, 'data-wi-modal-target') >= 3 && str_contains($html, 'id="account-email"'));
preg_match_all('/<(button|input)\b[^>]*type="submit"[^>]*>/', $html, $submits);
check('ogni submit ha wi-input-submit', fn () =>
    $submits[0] !== [] && array_filter($submits[0], static fn ($tag) => !str_contains($tag, 'wi-input-submit')) === []);
check('Esci posta il csrf_token all\'auth', fn () => str_contains($html, 'name="csrf_token"') && str_contains($html, AccountRoutes::auth()->route('logout')));
check('voce attiva e niente voci di moduli', fn () =>
    str_contains($html, 'aria-current="page"') && !str_contains($html, '/account/ordini/'));

check('POST senza CSRF: 419', fn () => pagina('personal', [], 'POST', ['form' => 'personal', 'name' => 'X']) === '419');

$csrf = Csrf::token();
$errorHtml = pagina('personal', [], 'POST', ['_csrf' => $csrf, 'form' => 'personal', 'name' => '', 'surname' => 'Hopper', 'birth_date' => '1990-02-31']);
check('con errori si riapre solo il modal giusto, con errori e valori', fn () =>
    preg_match('/id="account-personal"[^>]*class="[^"]*wi-show/', $errorHtml) === 1
    && preg_match('/id="account-email"[^>]*class="[^"]*wi-show/', $errorHtml) === 0
    && str_contains($errorHtml, 'value="Hopper"')
    && str_contains($errorHtml, (string) __t('account.personal.errors.birth_date')));

check('salvataggio riuscito: redirect alla pagina con avviso', fn () =>
    pagina('personal', [], 'POST', ['_csrf' => $csrf, 'form' => 'personal', 'name' => 'Grace', 'surname' => 'Hopper', 'birth_date' => '1990-05-17']) === 'redirect '.Route::url('account.personal')
    && ($_SESSION['wonder_account_notice'] ?? '') !== '');

$overview = pagina('index');
check('Panoramica: saluto e codice cliente', fn () =>
    str_contains($overview, '<strong>Grace</strong>')
    && str_contains($overview, (string) Contact::find(['user_id' => $userId], 1)['code']));

check('azione sconosciuta: 404', fn () => pagina('nessuna') === '404');
```

Per l'esito della conferma email si prova il solo rendering, perché la logica
l'ha già provata il Task 5: `pagina('email.confirm')` con
`$_GET['token'] = 'inventato'` contiene il testo di `account.email.invalid`.

Run: `cd $W/ecommerce && WI_TEST_SITE=$S php tests/integrazione/AccountCoreTest.php`
Expected: FAIL con `Class "Wonder\Auth\Frontend\AccountController" not found`.

- [ ] **Step 2: Handler, `AccountPage` e `AccountModal`**

`app/http/frontend/account.php`:

```php
<?php

use Wonder\Auth\Frontend\AccountController;
use Wonder\Auth\Frontend\AccountRoutes;

(new AccountController(AccountRoutes::panel(), AccountRoutes::auth()))
    ->handle((string) ($ROUTE_META['account_action'] ?? ''), (array) ($ROUTE_PARAMETERS ?? []));
```

`AccountPage::render(string $view, array $data)` fa tre cose.
1. Imposta la SEO come `ecommerce/.../AccountController.php:398-425`:
   - `title` da `$data['title']`;
   - `description` da `account.seo.description`;
   - `url` da `$data['seo_url']`;
   - `breadcrumb` vuoto;
   - `robots` `NOINDEX,NOFOLLOW`.
2. Legge e cancella `$_SESSION['wonder_account_notice']`.
3. Chiama `View::make($view, $data + [...])->render()` con questi default:
   - `account_panel` (`AccountRoutes::panel()`) e `user`;
   - `navigation` (`account_panel->navigation($user, $data['active'])`);
   - `logout_url` (`AccountRoutes::auth()->route('logout')`) e `logout_token`
     (`AuthSession::csrfToken()`);
   - `notice`, `errors` (`[]`), `modals` (`[]`) e `head`
     (`account_panel->head()`).

`AccountPage::view(string $page)` restituisce
`dirname(__DIR__, 3).'/app/view/pages/frontend/account/'.basename($page).'.php'`.
Le viste del pannello sono sigillate: niente sostituzioni da `custom/`.

`AccountModal`:

```php
<?php

namespace Wonder\Auth\Frontend;

use Wonder\Elements\Components\Alert;
use Wonder\Elements\Components\Button;
use Wonder\Elements\Components\Modal;
use Wonder\Elements\Components\Text;

/** Modal del pannello: form POST con CSRF, Salva nero a tutta larghezza spento finché mancano i campi obbligatori. */
final class AccountModal
{
    public static function make(string $id, string $title, array $fields, string $action, array $hidden = [], array $errors = [], bool $open = false): Modal
    {
        $body = [];
        if ($errors !== []) {
            $body[] = Alert::make(implode("\n", $errors), 'error')->title((string) __t('account.error_title'));
        }
        $body[] = AccountAddressForm::layout($fields);
        $modal = Modal::make($title)->id($id)->frontend()->scrollable()
            ->form($action, 'post', $hidden)
            ->components($body)
            ->footer([self::submit((string) __t('account.actions.save'))]);
        return $open ? $modal->addClass('wi-show') : $modal;
    }

    public static function confirm(string $id, string $title, string $text, string $action, string $label): Modal
    {
        return Modal::make($title)->id($id)->frontend()
            ->form($action, 'post')
            ->components([Text::make($text)])
            ->footer([self::submit($label)]);
    }

    private static function submit(string $label): Button
    {
        return Button::make($label)->type('submit')->variant('black')->block()->addClass('wi-input-submit wi-submit');
    }
}
```

`AccountAddressForm::layout()` accetta qualsiasi `Input`. Cambia solo la riga
`$keys` di `AccountAddressForm.php`: aggiungi `'birth_date', 'current_email',
'email', 'current_password', 'password'` dopo `'surname'`, così i campi dei
dati personali escono nell'ordine giusto. Nel `match` delle colonne:
- `email`, `current_email`, `current_password` e `password` hanno `12`;
- `birth_date` ha `6`.

- [ ] **Step 3: `AccountController`**

Lo scheletro, con le azioni del core. Gli indirizzi e la fatturazione arrivano
nel Task 7; fino ad allora quei rami chiamano `notFound()`.

```php
<?php

namespace Wonder\Auth\Frontend;

use Wonder\App\ResourceSchema\FormField;
use Wonder\Http\Csrf;
use Wonder\Http\Route;

/** Pagine del pannello account del core. I moduli lo estendono per le loro sezioni. */
class AccountController
{
    public function __construct(protected readonly AccountPanel $panel, protected readonly AuthProfile $auth) {}

    public function handle(string $action, array $parameters = []): void
    {
        match ($action) {
            'index' => $this->overview(),
            'personal' => $this->personal(),
            'email.confirm' => $this->confirmEmail(),
            default => $this->notFound(),
        };
    }

    protected function overview(): void
    {
        if (!$this->panel->enabled('overview')) {
            $first = array_values($this->panel->navigation($this->user()))[0]['href'] ?? '';
            $first !== '' ? $this->redirect($first) : $this->notFound();
        }
        $user = $this->user();
        $contact = $this->contact((int) $user->id);
        $this->page(AccountPage::view('index'), 'overview', [
            'title' => (string) __t('account.overview.title'),
            'seo_url' => Route::url('account.index'),
            'contact' => $contact,
            'rows' => $this->panel->overviewRows([], $user),
        ]);
    }

    protected function personal(): void { /* sotto */ }
    protected function confirmEmail(): void { /* sotto */ }

    protected function user(): object { return \infoUser((int) ($_SESSION['user_id'] ?? 0), 'id'); }

    protected function contact(int $userId): array
    {
        $contact = \Wonder\App\Models\Contacts\Contact::find(['user_id' => $userId], 1);
        if (!is_array($contact) || empty($contact['id'])) {
            ContactAccount::link($userId);
            $contact = \Wonder\App\Models\Contacts\Contact::find(['user_id' => $userId], 1);
        }
        return is_array($contact) ? $contact : [];
    }

    protected function page(string $view, string $active, array $data = []): void
    {
        AccountPage::render($view, ['active' => $active] + $data);
    }

    protected function flash(string $message): void { $_SESSION['wonder_account_notice'] = $message; }

    protected function requireCsrf(): void
    {
        if (!Csrf::verify()) {
            $this->invalidCsrf();
        }
    }

    protected function redirect(string $url): never { header('Location: '.$url); exit; }
    protected function notFound(): never { http_response_code(404); exit; }
    protected function invalidCsrf(): never { http_response_code(419); exit('CSRF token invalid'); }
}
```

`personal()`:
1. Carica `$user`, `$contact` e `$hasPassword`, cioè la password non vuota in
   `sqlSelect('user', …)` come `hasPassword()` dell'ecommerce.
2. Imposta `$open = ''`, `$errors = []` e `$values = []`.
3. Su POST:
   - chiama `requireCsrf()`;
   - legge `$form = (string) ($_POST['form'] ?? '')`;
   - con `personal`:
     - chiama `AccountPersonal::save($userId, $_POST, $this->panel, $this->auth->phoneRequired())`;
     - se riesce: `flash(__t('account.saved'))` e redirect a `account.personal`;
     - altrimenti: `$errors` è dato dalle traduzioni di
       `AuthValidationAlert::messageKeys($result->errors)` più
       `$result->messages`.
   - con `email`:
     - chiama `AccountEmail::request($user, $_POST['email'] ?? '', $_POST['current_password'] ?? '', Route::url('account.email.confirm'))`;
     - se riesce: `flash(__t('account.email.sent', ['email' => …]))` e
       redirect;
     - altrimenti: messaggi da `messageKeys`.
   - con `password`:
     - riprende `password()` di `ecommerce/.../AccountController.php:268-290`;
     - fa la whitelist di `current_password` e `password`;
     - se riesce: `flash(__t('account.password.saved'))`.
   - con qualsiasi altro valore: `notFound()`.
   - Se non c'è redirect: `$open = $form` e `$values = $_POST` (senza i campi
     password).
4. Costruisce i tre modal con `AccountModal::make`. Ogni modal ha `open` uguale
   a `$open === <suo form>`, e solo quello riceve `$errors` e `$values`.
   - `account-personal`, titolo «Modifica dati»:
     - campi `name`*, `surname`*, `birth_date` (`FormField::key('birth_date')->textDate()`, input date nativo che invia Y-m-d),
       `phone_prefix` e `phone`, come in
       `ecommerce/view/pages/account/profile.php` (vedi con `git show main:view/pages/account/profile.php`);
     - i campi passano da `$this->panel->personalFields($fields, $user)`;
     - valori dall'utente e dalla scheda, oppure da `$values`;
     - hidden `form=personal`.
   - `account-email`, titolo «Modifica email»:
     - `current_email`, spento, col valore attuale (`->disabled()`);
     - `email`* (`->email()->required()`);
     - `current_password`* (`->password()->required()`, label
       `account.email.password`);
     - hidden `form=email`.
   - `account-password`, titolo «Modifica password»:
     - `AccountPassword::fields($hasPassword)`;
     - hidden `form=password`.
5. Chiama `page()` con:
   - la vista `personal`, `active` `personal` e `title` `account.personal.label`;
   - `seo_url` `account.personal`;
   - le righe:
     `$this->panel->personalRows(AccountPersonal::rows($user, $contact, $hasPassword), $user)`;
   - i `modals`.

`confirmEmail()`:
1. `$outcome = AccountEmail::confirm((string) ($_GET['token'] ?? ''))`.
2. `View::make($this->auth->viewPath('message'), ['auth_profile' => $this->auth, 'message_key' => 'account.email.'.$outcome])->render()`,
   con la SEO `NOINDEX,NOFOLLOW` e il titolo `account.email.title`.

Non c'è redirect né login: la sessione resta com'è.

- [ ] **Step 4: Layout, componenti e pagine**

`layout/frontend/account/panel.php`:
- Riscrivilo con le classi della lib.
- Variabili: `title`, `navigation`, `errors`, `notice`, `modals`,
  `logout_url`, `logout_token`, `head`.

```php
<?php
use Wonder\Elements\Components\Alert;
use Wonder\View\View;
$account_panel ??= new \Wonder\Auth\Frontend\AccountPanel();
$errors = array_values(array_filter(array_map('strval', (array) ($errors ?? []))));
$notice = trim((string) ($notice ?? ''));
View::layout($account_panel->parentLayout());
?>
<?=$head ?? ''?>
<main id="account-page">
    <section class="intro">
        <div class="content">
            <div class="wi-side-layout">
                <aside class="wi-side-layout__aside">
                    <?=View::component('frontend.account.navigation', ['items' => $navigation ?? [], 'logout_url' => $logout_url ?? '', 'logout_token' => $logout_token ?? ''])?>
                </aside>
                <div class="wi-side-layout__main">
                    <h2 class="subtitle wi-side-layout__title"><?=e((string) ($title ?? ''))?></h2>
                    <?php if ($notice !== ''): ?><?=Alert::make($notice, 'success')->title((string) __t('account.notice_title'))->render()?><?php endif; ?>
                    <?php if ($errors !== []): ?><?=Alert::make(implode("\n", $errors), 'error')->title((string) __t('account.error_title'))->render()?><?php endif; ?>
                    <?=$PAGE_CONTENT?>
                </div>
            </div>
        </div>
    </section>
</main>
<?php foreach ($modals ?? [] as $modal): ?><?=$modal->render()?><?php endforeach; ?>
<?php View::end(); ?>
```

`$errors` della pagina porta solo gli errori di pagina (per esempio il
contatto collegato a un altro utente). Gli errori di un form restano dentro il
suo modal, riaperto con `wi-show`: così non escono due volte.

`components/frontend/account/navigation.php`:
- `<nav aria-label="{{account.navigation.label}}"><ul class="wi-side-nav__list">`.
- Per ogni voce:
  `<li><a class="wi-side-nav__link" href="…"` più `aria-current="page"` se
  `active`, `><i class="wi-side-nav__icon {icon}" aria-hidden="true"></i><span>{label}</span></a></li>`.
- In fondo, se `logout_url` non è vuoto:
  `<li><form class="wi-side-nav__form" method="post" action="{logout_url}"><input type="hidden" name="csrf_token" value="{logout_token}"><button type="submit" class="wi-side-nav__link wi-input-submit"><i class="wi-side-nav__icon bi bi-box-arrow-right" aria-hidden="true"></i><span>{{account.navigation.logout}}</span></button></form></li>`.
- Tutto passa da `e()`.

`components/frontend/account/row.php`:
- `<div class="wi-data-row"><div class="wi-data-row__cols">`.
- Per ogni colonna:
  `<div class="wi-data-row__col"><div class="wi-data-row__label">…</div><div class="wi-data-row__value">…</div></div>`.
- Poi `</div><div class="wi-data-row__action">` con l'azione:
  - con `modal`:
    `Button::to('#', label)->outline()->variant('black')->size('sm')->icon(icon)->opensModal(modal)`;
  - con `href`: `Button::to(href, label)->outline()->variant('black')->size('sm')->icon(icon)`;
  - con `disabled`: `->disabled()`, più `<small>` con `hint` sotto.
- Poi `</div></div>`.

`pages/frontend/account/index.php`:
1. `$account_panel->layout([...])` con tutte le variabili ricevute.
2. Il saluto:
   ```php
   <p><?=str_replace('%NAME%', '<strong>'.e((string) $user->name).'</strong>', e((string) __t('account.overview.greeting', ['name' => '%NAME%'])))?></p>
   <p><?=e((string) __t('account.overview.code'))?> <strong><?=e((string) ($contact['code'] ?? ''))?></strong></p>
   ```
3. Le righe di `$rows` con il componente `row`.
4. `View::end()`.

`pages/frontend/account/personal.php`: layout, poi le righe con il componente
`row`, poi `View::end()`.

Testi nuovi in `account.json`. Valori in it, tra parentesi en:
- `navigation.overview`: «Panoramica» ("Overview");
- `navigation.personal`: «Dati personali» ("Personal details");
- `navigation.addresses`: «Indirizzi» ("Addresses");
- `navigation.billing`: «Fatturazione» ("Billing");
- `overview.title`: «Il tuo account» ("Your account");
- `overview.greeting`: «Ciao {{name}}, questo è il tuo account: qui modifichi i tuoi dati e guardi i tuoi ordini.» ("Hi {{name}}, this is your account: here you edit your details and see your orders.");
- `overview.code`: «Codice cliente:» ("Customer code:");
- `personal.name`: «Nome» ("Name");
- `personal.birth_date`: «Data di nascita» ("Date of birth");
- `personal.phone`: «Cellulare» ("Mobile");
- `personal.modal_title`: «Modifica dati» ("Edit details");
- `password.modal_title`: «Modifica password» ("Change password");
- `actions.save`: «Salva» ("Save").

Si tolgono le chiavi rimaste senza uso: `overview_title`, `navigation.profile`,
`navigation.shipping`, `navigation.password`, `navigation.welcome`,
`password.confirmation`, `password.intro*`. Prima controlla che nessuno le usi:
`grep -rn "account\.\(overview_title\|navigation\.profile\|navigation\.shipping\|navigation\.password\|navigation\.welcome\|password\.confirmation\|password\.intro\)" $W/app $W/ecommerce --include=*.php`.
Se le usa ancora l'ecommerce, la chiave sparisce nel Task 8.

- [ ] **Step 5: Lancia i test**

Run: `cd $W/ecommerce && WI_TEST_SITE=$S php tests/integrazione/AccountCoreTest.php && cd $W/app && php tests/account-routes.php`
Expected: tutti verdi.

- [ ] **Step 6: Commit**

```bash
cd $W/app && git add class/Auth/Frontend/AccountController.php class/Auth/Frontend/AccountPage.php class/Auth/Frontend/AccountModal.php class/Auth/Frontend/AccountAddressForm.php app/http/frontend/account.php app/view/layout/frontend/account/panel.php app/view/components/frontend/account/navigation.php app/view/components/frontend/account/row.php app/view/pages/frontend/account resources/lang/it/account.json resources/lang/en/account.json
git commit -m "Account: pannello del core con Panoramica e Dati personali a modal

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
cd $W/ecommerce && git add tests/integrazione/AccountCoreTest.php
git commit -m "Test: pagine del pannello del core, submit con wi-input-submit e modal con errori

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Indirizzi e Fatturazione

Repo: `$W/app`; test in `$W/ecommerce`.

**Files:**
- Modify: `$W/app/class/Auth/Frontend/AccountController.php` (`handle`, più cinque azioni nuove)
- Create: `$W/app/app/view/pages/frontend/account/addresses.php`, `address-form.php`, `billing.php`
- Create: `$W/app/app/view/components/frontend/account/address-card.php`
- Delete: `$W/app/class/Auth/Frontend/AccountAddressModal.php`, `$W/app/app/view/components/frontend/account/address-form.php`, `password-form.php`
- Modify: `$W/app/resources/lang/{it,en}/account.json`
- Modify: `$W/ecommerce/tests/integrazione/AccountCoreTest.php`, `account-address-preview.php`, `AccountAddressBrowserTest.cjs`

**Interfaces:**
- Consumes: `AccountAddresses::*` e `AccountBilling::*` (Task 4);
  `AccountModal::make` e `confirm`, `AccountPage`, il componente `row` (Task 6).
- Produces: le azioni `addresses`, `addresses.create`, `addresses.edit`,
  `addresses.delete` e `billing` del controller. Gli id dei modal sono:
  - `account-address-new`, per il nuovo indirizzo;
  - `account-address-{id}`, per la modifica;
  - `account-address-delete-{id}`, per la conferma di eliminazione;
  - `account-billing`, per la fatturazione.

- [ ] **Step 1: Scrivi i casi che falliscono**

In `AccountCoreTest.php`, nel blocco del controller del Task 6, aggiungi:

```php
$contactId = (int) Contact::find(['user_id' => $userId], 1)['id'];
$empty = pagina('addresses');
check('senza indirizzi: stato vuoto e bottone per aggiungere', fn () =>
    str_contains($empty, 'wi-empty-state') && str_contains($empty, 'data-wi-modal-target="#account-address-new"'));

$address = ['_csrf' => $csrf, 'name' => 'Ada', 'surname' => 'Lovelace', 'phone_prefix' => '+39', 'phone' => '3331234567', 'country' => 'IT', 'province' => 'MI', 'cap' => '20100', 'city' => 'Milano', 'street' => 'Via Roma', 'number' => '1'];
check('aggiunta: redirect all\'elenco', fn () => pagina('addresses.create', [], 'POST', $address) === 'redirect '.Route::url('account.addresses'));
$id = (int) (AccountAddresses::all($contactId)[0]['id'] ?? 0);
$list = pagina('addresses');
check('scheda con nome, tel: e righe dell\'indirizzo', fn () =>
    str_contains($list, 'wi-address-card__name') && str_contains($list, 'href="tel:+393331234567"')
    && str_contains($list, 'Via Roma 1, 20100') && str_contains($list, 'Milano (MI)'));

$invalidHtml = pagina('addresses.edit', ['id' => $id], 'POST', ['cap' => ''] + $address);
check('modifica con errori: si riapre il modal di quell\'indirizzo', fn () =>
    preg_match('/id="account-address-'.$id.'"[^>]*class="[^"]*wi-show/', $invalidHtml) === 1);

$otherUser = clienteDiProva('account-http-other');
$_SESSION['user_id'] = $otherUser;
check('indirizzo altrui: 404 in modifica', fn () => pagina('addresses.edit', ['id' => $id]) === '404');
check('indirizzo altrui: 404 in eliminazione', fn () => pagina('addresses.delete', ['id' => $id], 'POST', ['_csrf' => $csrf]) === '404');
$_SESSION['user_id'] = $userId;
check('eliminazione senza CSRF: 419', fn () => pagina('addresses.delete', ['id' => $id], 'POST', []) === '419');
check('eliminazione propria', fn () =>
    pagina('addresses.delete', ['id' => $id], 'POST', ['_csrf' => $csrf]) === 'redirect '.Route::url('account.addresses')
    && AccountAddresses::find($contactId, $id) === null);

$billing = pagina('billing');
check('Fatturazione: righe e modal con Salva wi-input-submit', fn () =>
    str_contains($billing, 'wi-data-row') && str_contains($billing, 'id="account-billing"'));
```

Aggiungi anche `use Wonder\Auth\Frontend\AccountAddresses;` in cima.

Run: `cd $W/ecommerce && WI_TEST_SITE=$S php tests/integrazione/AccountCoreTest.php`
Expected: FAIL sui casi nuovi, che danno `404` perché l'azione non c'è ancora.

- [ ] **Step 2: Azioni del controller**

1. In `handle()`, aggiungi:
   ```php
   'addresses' => $this->addresses(),
   'addresses.create' => $this->addressEditor(null),
   'addresses.edit' => $this->addressEditor((int) ($parameters['id'] ?? 0)),
   'addresses.delete' => $this->deleteAddress((int) ($parameters['id'] ?? 0)),
   'billing' => $this->billing(),
   ```
   Ogni ramo prima controlla `$this->panel->enabled('addresses' | 'billing')`;
   se la sezione è spenta, `notFound()`.
2. `addresses()` fa `renderAddresses(open: '', errors: [], values: [])`.
3. `renderAddresses(string $open, array $errors, array $values)`:
   - prende la scheda e `AccountAddresses::all($contactId)`;
   - fa un modal di modifica per ogni indirizzo:
     - `AccountModal::make('account-address-'.$id, __t('account.addresses.edit_title'), AccountAddressForm::fields(ContactAddress::address(), $open === (string) $id ? $values : $row, $open === (string) $id), Route::url('account.addresses.edit', ['id' => $id]), [], …)`;
     - il modal è aperto solo se `$open === (string) $id`;
   - fa un modal di conferma per ogni indirizzo:
     - `AccountModal::confirm('account-address-delete-'.$id, __t('account.addresses.delete_title'), <righe di card() unite con ", ">, Route::url('account.addresses.delete', ['id' => $id]), __t('account.addresses.delete'))`;
   - fa il modal `account-address-new`, aperto se `$open === 'new'`;
   - chiama `page()` con la vista `addresses`, `active` `addresses`, `title`
     `account.navigation.addresses`, `seo_url` `account.addresses`, le
     `cards` (`AccountAddresses::card` più `id`) e i `modals`.
4. `addressEditor(?int $id)`:
   - se `$id !== null` e `AccountAddresses::find($contactId, $id) === null`,
     dà `notFound()` (anche su GET);
   - su POST:
     - chiama `requireCsrf()` e
       `$result = AccountAddresses::save($contactId, $_POST, $id)`;
     - se riesce: `flash(__t('account.saved'))` e redirect a `account.addresses`;
     - altrimenti, se `$_SERVER['HTTP_ACCEPT']` non è un fallback senza JS
       (vedi sotto), chiama
       `renderAddresses($id === null ? 'new' : (string) $id, $result->messages, $_POST)`;
   - su GET mostra la pagina di ripiego `address-form`:
     - titolo `create_title` o `edit_title`;
     - i campi in un `<form method="post">` con `Csrf::field()` e un bottone
       `type="submit"` con `wi-input-submit`.

   «Senza JS» qui vuol dire solo «la pagina GET». Il POST con errori riapre
   sempre l'elenco con il modal aperto: il modal con `wi-show` è visibile anche
   senza JS.
5. `deleteAddress(int $id)`:
   - chiama prima `requireCsrf()`;
   - se `AccountAddresses::delete($contactId, $id)` fallisce, `notFound()`;
   - altrimenti `flash(__t('account.addresses.deleted'))` e redirect a
     `account.addresses`.

   Il controllo di proprietà sta già in `delete()`; l'ordine «CSRF prima, poi
   404» è quello del test.
6. `billing()`:
   - su POST:
     - chiama `requireCsrf()` e `AccountBilling::save($contactId, $_POST)`;
     - se riesce: `flash` e redirect;
     - altrimenti apre `account-billing` con i messaggi e i valori.
   - La pagina:
     - righe `AccountBilling::rows($contact)` con il componente `row`;
     - un modal `account-billing` con i campi dell'indirizzo di fatturazione
       di `Contact`, presi come in `ecommerce/.../AccountController.php:140-175`.

Con `$contact === []` le azioni non scrivono: mostrano l'errore
`account.errors.contact` sulla pagina.

- [ ] **Step 3: Viste**

`components/frontend/account/address-card.php`:
- Prende `card` (`name`, `phone`, `phone_href`, `lines`, `id`).
- Struttura:
  `<div class="wi-address-card"><div class="wi-address-card__body"><div class="wi-address-card__name">…</div>`;
  se c'è il telefono, `<a href="{phone_href}" style="text-decoration:underline">…</a>`
  (sottolineato come lo screen; il colore è ereditato);
  una `<div>` per ogni riga; poi `</div><div class="wi-address-card__actions">`.
- I bottoni:
  - cestino: `Button::make('')->type('button')->variant('black')->size('sm')->icon('bi bi-trash')->opensModal('account-address-delete-'.$id)`
    con `aria-label` `account.addresses.delete`;
  - modifica: `Button::to('…edit url…', __t('account.actions.edit'))->outline()->variant('black')->size('sm')->icon('bi bi-pencil')->opensModal('account-address-'.$id)`.
  Il link della modifica porta alla pagina di ripiego quando manca JS.
- Chiusura: `</div></div>`.

`pages/frontend/account/addresses.php`:
- Con le schede:
  `<div class="wi-address-grid">`, una scheda per ognuna, poi
  `<a class="wi-address-card wi-address-card--add" href="{create url}" data-wi-modal-target="#account-address-new" aria-haspopup="dialog" aria-controls="account-address-new"><i class="wi-address-card__icon bi bi-plus-lg" aria-hidden="true"></i><span>{{account.addresses.add}}</span></a></div>`.
  Sono gli stessi attributi che `opensModal()` mette sui bottoni del frontend
  (`class/Themes/Concerns/RendersButtonModal.php`).
- Senza schede:
  `<div class="wi-empty-state"><i class="wi-empty-state__icon bi bi-geo-alt" aria-hidden="true"></i><p>{{account.addresses.empty}}</p>` più
  `Button::to(create url, __t('account.addresses.add_first'))->variant('black')->opensModal('account-address-new')`,
  poi `</div>`.

`pages/frontend/account/address-form.php`: il ripiego senza JS.
- Layout del pannello, `active` `addresses`.
- `<form method="post" action="{action}">`, con `Csrf::field()->render()` e
  `AccountAddressForm::layout($fields)->render()`.
- Un `<button type="submit" class="btn btn-black w-100 wi-input-submit wi-submit">`
  con `account.actions.save`.
- Un link «Torna agli indirizzi».

`pages/frontend/account/billing.php`: layout, poi le righe con il componente
`row`.

Testi nuovi in `account.json`, oggetto `addresses`. Valori in it, tra parentesi
en:
- `add`: «Aggiungi indirizzo» ("Add address");
- `add_first`: «Aggiungi un indirizzo» ("Add an address");
- `empty`: «Non hai indirizzi salvati» ("You have no saved addresses");
- `create_title`: «Nuovo indirizzo» ("New address");
- `edit_title`: «Modifica indirizzo» ("Edit address");
- `delete`: «Elimina» ("Delete");
- `delete_title`: «Eliminare questo indirizzo?» ("Delete this address?");
- `deleted`: «L'indirizzo è stato eliminato.» ("The address has been deleted.");
- `back`: «Torna agli indirizzi» ("Back to addresses").

In `billing`, aggiungi `holder` («Intestatario» / "Holder"), `tax`
(«Codice fiscale / P. IVA» / "Tax code / VAT number") e `address`
(«Indirizzo» / "Address"). Togli il blocco `shipping` dopo il `grep` del
Task 6.

- [ ] **Step 4: Elimina i vecchi componenti e aggiorna anteprima e prova nel browser**

```bash
cd $W/app && git rm class/Auth/Frontend/AccountAddressModal.php app/view/components/frontend/account/address-form.php app/view/components/frontend/account/password-form.php
grep -rn "AccountAddressModal\|account.address-form\|account.password-form" $W/app $W/ecommerce --include=*.php
```

Expected: le sole occorrenze rimaste sono nell'ecommerce (vecchio controller e
viste), che sparisce nel Task 8, e in `account-address-preview.php`.

- `account-address-preview.php`:
  - riscrivilo come pagina di prova del nuovo modal;
  - chiama `AccountModal::make('account-address-new', …, AccountAddressForm::fields(ContactAddress::address()), '#')`
    con `addClass('wi-show')` e lo rende dentro il layout di prova attuale.
- `AccountAddressBrowserTest.cjs` resta sulla pagina di anteprima, come oggi
  (nessun login):
  - l'id `#preview-address` diventa `#account-address-new`;
  - verifica che `#account-address-new button[type=submit].wi-input-submit`
    sia `disabled` a form vuoto e attivo dopo aver riempito i campi
    obbligatori;
  - l'host dei fogli di stile passa da `ecommerce.test` a `WI_TEST_URL`
    (default `https://ecommerce.test`).

- [ ] **Step 5: Lancia i test**

Run: `cd $W/ecommerce && WI_TEST_SITE=$S php tests/integrazione/AccountCoreTest.php && cd $W/app && php tests/account-routes.php`
Expected: tutti verdi.

- [ ] **Step 6: Commit**

```bash
cd $W/app && git add -A class/Auth/Frontend app/view resources/lang/it/account.json resources/lang/en/account.json
git commit -m "Account: indirizzi a schede con modal, eliminazione e fatturazione a righe

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
cd $W/ecommerce && git add tests/integrazione/AccountCoreTest.php tests/integrazione/account-address-preview.php tests/integrazione/AccountAddressBrowserTest.cjs
git commit -m "Test: indirizzi propri e altrui, CSRF e fatturazione del pannello del core

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: L'ecommerce passa al pannello del core

Repo: `$W/ecommerce`.

**Files:**
- Create: `src/Frontend/Account/EcommerceAccountExtension.php`, `EcommerceAccountController.php`
- Rewrite: `http/frontend/account.php`, `view/pages/account/payment-methods.php`
- Modify: `config/routes/route.frontend.php:67-99`, `config/module.php:39-47`, `view/pages/checkout/completed.php:18`
- Modify: `lang/{it,en}/ecommerce.json` (blocco `account`, da `:253`)
- Delete:
  - `src/Frontend/Account/AccountController.php`, `EcommerceAccountPanel.php`;
  - le altre viste di `view/pages/account/`;
  - `view/components/account/`;
  - `view/layout/frontend/ecommerce.account.php`.
- Modify (test): `tests/integrazione/AuthCoreViewsTest.php` (`use` a riga 18, blocchi 86-118, 133-148, 171-181), `tests/EcommerceTest.php:43-48`, `tests/CartCheckoutTest.php:295-300`
- Modify: `tests/integrazione/AccountCoreTest.php` (casi della riga Metodi di pagamento)

**Interfaces:**
- Consumes:
  - `BaseAccountExtension`, `AccountRoutes::register`, `extend`, `group` e
    `handler` (Task 3);
  - `AccountController` e i suoi metodi protetti, `AccountPage` (Task 6).
- Produces:
  - `EcommerceAccountExtension extends BaseAccountExtension`, con `routes()`,
    `navigation()`, `personalRows()` e `head()`;
  - la route `account.payment-methods` su `GET /account/metodi-di-pagamento/`;
  - `EcommerceAccountController extends AccountController`, con le azioni
    `payment-methods` (questo piano) e `orders`, `orders.show` e `coupons`
    (piano 2);
  - l'handler `http/frontend/account.php` dell'ecommerce per le sue route.

- [ ] **Step 1: Scrivi i casi che falliscono**

In `AccountCoreTest.php` aggiungi un terzo blocco, dopo quelli senza moduli.
Il blocco registra il pannello con l'estensione.

```php
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Account\EcommerceAccountExtension;

Route::reset();
AccountRoutes::reset();
AccountRoutes::register(new AccountPanel());
AccountRoutes::extend(new EcommerceAccountExtension());
$_SESSION['user_id'] = $userId;

check('l\'ecommerce aggiunge la route dei metodi di pagamento, protetta', fn () =>
    Route::url('account.payment-methods') !== '' && str_ends_with(Route::url('account.payment-methods'), '/account/metodi-di-pagamento/'));
$withShop = pagina('personal');
$enabled = Ecommerce::config('account.payment_methods.enabled', false) === true;
check('riga Metodi di pagamento in Dati personali', fn () => str_contains($withShop, (string) __t('ecommerce.account.payment_methods.label')));
check('Metodi di pagamento: Gestisci attivo solo se acceso', fn () =>
    $enabled
        ? str_contains($withShop, 'href="'.Route::url('account.payment-methods').'"')
        : str_contains($withShop, (string) __t('ecommerce.account.payment_methods.soon')));
check('Metodi di pagamento fuori dal menu', fn () => !str_contains($withShop, 'wi-side-nav__link" href="'.Route::url('account.payment-methods')));
```

Run: `cd $W/ecommerce && WI_TEST_SITE=$S php tests/integrazione/AccountCoreTest.php`
Expected: FAIL con `Class "…EcommerceAccountExtension" not found`.

- [ ] **Step 2: Estensione e controller dell'ecommerce**

`EcommerceAccountExtension`:
- `routes()`:
  ```php
  AccountRoutes::group(static function (): void {
      $handler = Ecommerce::handlerPath('frontend/account.php');
      Route::get('/metodi-di-pagamento/', $handler, ['account_action' => 'payment-methods'])->name('payment-methods');
  });
  ```
- `navigation(array $items, object $user)`:
  - per ora non aggiunge voci; Ordini e Coupon arrivano nel piano 2;
  - applica la configurazione `Ecommerce::config('account.navigation', [])`
    come oggi `EcommerceAccountPanel`: `false` toglie la voce, un array passa
    da `array_replace`.
- `personalRows(array $rows, object $user)`: aggiunge la riga
  ```php
  ['key' => 'payment_methods',
   'columns' => [['label' => __t('ecommerce.account.payment_methods.label'), 'value' => __t('ecommerce.account.payment_methods.summary')]],
   'action' => $enabled
       ? ['label' => __t('account.actions.manage'), 'href' => Route::url('account.payment-methods'), 'modal' => '', 'icon' => 'bi bi-credit-card', 'disabled' => false, 'hint' => '']
       : ['label' => __t('account.actions.manage'), 'href' => '', 'modal' => '', 'icon' => 'bi bi-credit-card', 'disabled' => true, 'hint' => __t('ecommerce.account.payment_methods.soon')]]
  ```
- `head()`: `StoreFont::style('account').StoreStyle::sheet()`. Prendi le due
  chiamate da dove oggi le stampa `view/layout/frontend/ecommerce.account.php`.

`EcommerceAccountController extends AccountController`:
- `handle()`:
  `match ($action) { 'payment-methods' => $this->paymentMethods(), default => parent::handle($action, $parameters) }`;
- `paymentMethods()`:
  `$this->page(Ecommerce::viewPath('pages/account/payment-methods.php'), 'personal', ['title' => __t('ecommerce.account.payment_methods.title'), 'seo_url' => Route::url('account.payment-methods'), 'enabled' => …])`.
  La voce attiva è `personal`, perché la pagina sta dentro «Dati personali».

`http/frontend/account.php` dell'ecommerce:
`(new EcommerceAccountController(AccountRoutes::panel(), AccountRoutes::auth()))->handle(...)`,
come l'handler del core.

`view/pages/account/payment-methods.php`:
- `$account_panel->layout(get_defined_vars())`;
- l'`Alert` di oggi (ready o pending, titolo Stripe);
- `Button::to(Route::url('account.personal'), __t('account.actions.back'))->outline()->variant('black')`;
- `View::end()`.

- [ ] **Step 3: Rotte, configurazione, rimandi e testi**

1. In `config/routes/route.frontend.php:73-99`:
   - togli il gruppo dell'account;
   - dopo la registrazione dell'auth, metti:
     ```php
     $panelClass = Ecommerce::config('account.panel', \Wonder\Auth\Frontend\AccountPanel::class);
     AccountRoutes::register(new $panelClass(), new $authProfileClass());
     AccountRoutes::extend(new EcommerceAccountExtension());
     ```
   - lascia il controllo `is_a(..., AccountPanel::class, true)` come oggi in
     `AccountController::panel()`.
2. In `config/module.php:39-47`, `account.panel` diventa
   `\Wonder\Auth\Frontend\AccountPanel::class`.
3. In `view/pages/checkout/completed.php:18`, metti `__r('account.index')`.
4. Fai `grep -rn "ecommerce\.account\." src view config http` e cambia ogni
   rimando rimasto in `account.*`.
5. In `lang/{it,en}/ecommerce.json`, il blocco `account` tiene solo
   `payment_methods`, con `label`, `title`, `summary`, `pending`, `ready` e il
   nuovo `soon`: «Presto disponibile» / "Coming soon". Il resto lo danno i testi
   del core.
6. Elimina i file vecchi:
   ```bash
   git rm src/Frontend/Account/AccountController.php src/Frontend/Account/EcommerceAccountPanel.php view/layout/frontend/ecommerce.account.php
   git rm -r view/components/account
   git rm $(ls view/pages/account/*.php | grep -v payment-methods.php)
   grep -rn "EcommerceAccountPanel\|Frontend\\\\Account\\\\AccountController\|ecommerce.account.php\|components/account/" src view config http tests
   ```
   Expected: nessuna occorrenza fuori dai test del passo 4.

- [ ] **Step 4: Test esistenti**

- `AuthCoreViewsTest.php`:
  - togli il `use` a riga 18 e i blocchi alle righe 86-118, 133-148 e 171-181,
    che provavano le vecchie pagine dell'account;
  - la copertura passa ad `AccountCoreTest`.
- `EcommerceTest.php:43-48`: le route attese diventano `account.index`,
  `account.personal` e `account.payment-methods`, con i percorsi italiani.
- `CartCheckoutTest.php:295-300`: il rimando dell'ordine completato è
  `Route::url('account.index')`.

Run: `cd $W/ecommerce && WI_TEST_SITE=$S WI_TEST_URL=https://ecommerce-account.test php tests/run.php`
Expected: `Tutti i test dell'ecommerce passano.`

- [ ] **Step 5: Commit**

```bash
cd $W/ecommerce && git add -A src/Frontend/Account http/frontend/account.php view config lang tests
git commit -m "Account: l'ecommerce usa il pannello del core con la sua estensione

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Documenti, suite complete e prova nel browser

Repo: tutti e quattro.

**Files:**
- Modify: `$W/gestionale/docs/superpowers/specs/2026-09-11-gestionale-ecommerce-architettura-design.md` (§2.3)
- Modify: `$W/app/docs/app/concetti/utenti/auth-frontend.md`, `$W/app/CHANGELOG.md`
- Modify: `$W/ecommerce/CHANGELOG.md`, `$W/ecommerce/TODO.md:118,124,128,129`
- Modify: `$W/gestionale/TODO.md`

- [ ] **Step 1: Documenti**

- **Architettura §2.3.** Scrivi che il pannello cliente è del core
  (`AccountRoutes`, `AccountExtension`) e che ordini, coupon, resi e
  abbonamenti sono sezioni dei moduli. Rimanda alla spec del pannello.
- **`auth-frontend.md`.** Aggiungi una sezione «Pannello account» con:
  - `AccountRoutes::register` ed `extend`;
  - `sections()` e `authorities()`;
  - la forma delle righe e delle voci del menu;
  - le URL di §4.1;
  - l'esempio minimo di `BaseAccountExtension` (route più voce);
  - la regola `wi-input-submit`.
- **CHANGELOG del core**, sotto `Unreleased`:
  - pannello account del core;
  - `birth_date` su `Contact`;
  - cambio email con conferma;
  - password senza conferma;
  - in «Changed», le URL italiane;
  - in «Removed», `AccountAddressModal` e i componenti `address-form` e
    `password-form`.
- **CHANGELOG dell'ecommerce:**
  - account sul pannello del core;
  - in «Removed», le vecchie route `ecommerce.account.*`, il controller e le
    viste.
- **TODO dell'ecommerce:**
  - C0 superato da questo lavoro (data e spec);
  - in C3 è fatto il cambio password, i consensi restano;
  - C4 in attesa del piano 2;
  - C0d chiuso dalla prova nel browser di questo Task.
- **TODO del gestionale:** stato del pannello account, piano 1 fatto e piano 2
  da fare.

- [ ] **Step 2: Suite complete**

```bash
cd $W/lib && npm test
cd $W/app && for t in tests/*.php; do php "$t" || echo "FALLITO $t"; done
cd $W/ecommerce && WI_TEST_SITE=$S WI_TEST_URL=https://ecommerce-account.test php tests/run.php
cd $W/gestionale && ls tests >/dev/null 2>&1 && (php tests/run.php 2>/dev/null || true)
```

Expected: tutto verde. Il gestionale ha solo documenti in questo piano: lancia
la sua suite solo se esiste un `tests/run.php`. Un rosso già presente su main va
segnalato con l'output, non nascosto.

- [ ] **Step 3: Prova nel browser (l'utente fa il login)**

1. Porta la lib nel sito di prova:
   ```bash
   cd $W/lib && npm run build && rsync -a --delete dist/ $S/assets/lib/wonder-image/dist/ && git checkout -- dist
   ```
2. Chiedi all'utente di entrare su `https://ecommerce-account.test/account/`
   con il suo cliente di prova. Il login non lo fai tu.
3. Su 1280, 768 e 390 px controlla:
   - il menu laterale, la voce attiva e lo scorrimento orizzontale a 390;
   - il titolo `subtitle` con il divisore;
   - in Dati personali, ognuno dei tre modal: Salva spento a campi vuoti e
     acceso quando sono pieni;
   - un errore che riapre il modal giusto;
   - la riga Metodi di pagamento;
   - in Indirizzi, la griglia 3, 2 e 1, una scheda in 3:2, l'aggiunta, la
     modifica e l'eliminazione con conferma;
   - la Fatturazione;
   - «Esci».
4. Lancia `AccountAddressBrowserTest.cjs` con `WI_TEST_URL`.
5. Salva le schermate nella cartella scratchpad e mandale all'utente con
   SendUserFile.

- [ ] **Step 4: Commit dei documenti**

```bash
cd $W/gestionale && git add docs/superpowers/specs/2026-09-11-gestionale-ecommerce-architettura-design.md TODO.md
git commit -m "Docs: il pannello account è del core; stato del piano 1

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
cd $W/app && git add docs/app/concetti/utenti/auth-frontend.md CHANGELOG.md
git commit -m "Docs: pannello account del core

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
cd $W/ecommerce && git add CHANGELOG.md TODO.md
git commit -m "Docs: account sul pannello del core, stato di C0, C3, C4 e C0d

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

Push, PR e unione su main si fanno solo dopo la conferma dell'utente. Poi si
segue la regola di memoria «PR: merge e pulizia li faccio io».
