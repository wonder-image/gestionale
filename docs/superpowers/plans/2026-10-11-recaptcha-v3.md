# reCAPTCHA v3 con la casella di riserva — piano di lavoro

> **Per chi esegue:** SOTTO-SKILL OBBLIGATORIA: superpowers:subagent-driven-development (consigliata) o superpowers:executing-plans. I passi usano le caselle (`- [ ]`).

**Obiettivo:** i form protetti da reCAPTCHA usano la v3 in background (nessun clic) e, se il punteggio è basso, mostrano la casella v2 «Non sono un robot»; l'express dell'ospite passa dalla stessa verifica a `start`; se Google non risponde (429, 5xx, rete) la verifica si salta.

**Architettura:** la lib (`recaptcha.js`) intercetta il submit dei form con la chiave v3, chiede un token fresco a `grecaptcha.enterprise.execute`, lo scrive nei campi nascosti con `g-recaptcha-type=score` e rimanda il form; su un 618 del server (o su un errore di caricamento) accende la casella v2 nello stesso riquadro. L'app sceglie la chiave dal tipo, classifica la risposta di Google (`reCAPTCHA::classify`), segna la sfida in sessione e lancia `RecaptchaChallenge` (618) quando il punteggio è basso e la chiave a casella c'è; il componente del form disegna il riquadro nei quattro modi. L'ecommerce verifica l'ospite a `start` dell'express, segna il carrello rapido verificato e rifiuta `place` senza il segno.

**Tecnologie:** JavaScript della lib (build concatenata con webpack, test in `node` con `vm`, test nel browser con Playwright), PHP 8.3 del framework Wonder Image (test standalone `php tests/…Test.php`), ecommerce (`tests/run.php`, `tests/integrazione/*.php`), reCAPTCHA Enterprise.

**Spec:** `docs/superpowers/specs/2026-10-11-recaptcha-v3-design.md` (R1–R10, §5).

## Vincoli globali

- Chiavi: `g_recaptcha_site_key` (casella v2, env `G_RECAPTCHA_SITE_KEY`) e `g_recaptcha_score_site_key` (punteggio v3, env `G_RECAPTCHA_SCORE_SITE_KEY`); badge `g_recaptcha_badge`, `'true'` di base (env `G_RECAPTCHA_BADGE`).
- Modi: solo casella → come oggi; solo punteggio → badge o attribuzione, punteggio basso = 617; tutte e due → badge o attribuzione, punteggio basso = 618 e la casella; nessuna → come oggi.
- `g-recaptcha-type` vale `score` o `checkbox`; assente = `checkbox`. Il server non tiene stato oltre al segno della sfida in sessione (`wi_recaptcha_challenge[<azione>]`).
- `minScore` 0.5. L'azione di Google si confronta solo se non è vuota.
- Fail-open (la verifica passa) per eccezione del motore e per `available === false` (HTTP 0, 200 senza JSON, 429, 5xx, altri 4xx tranne 400). Non passano: token assente, token non valido per Google, HTTP 400.
- Codici: 617 = verifica fallita (esistente), 618 = «Conferma di non essere un robot» (`RecaptchaChallenge::CHALLENGE_CODE`). Al client tutti e due escono HTTP 422 con `status` nel corpo; un codice fuori da 400–599 esce 500.
- I log non contengono mai token né chiavi (il campo `event` della risposta di Google si toglie).
- Badge nascosto → riga di attribuzione sotto il riquadro con i due link di Google (testi in `components.forms.recaptcha.notice|privacy|terms`).
- Express: verificato a `start` (azione `ecommerce_express`) solo per l'ospite; `place` rifiuta l'ospite senza il segno (403 `unverified`).
- Nessuna chiamata vera a Google né a Stripe nei test; niente email (`WONDER_NO_MAIL=1`). Chiavi nei test: `site-checkbox`, `site-score`, `site-key`, `score-key`.
- Mai chiavi vere (siti, GCP) in chat, nel codice o nei log dei test.
- Testi in italiano nel codice e nei commenti, stile del codice vicino. Lingue app: it, en, de, es, fr. Lingue ecommerce: it, en.
- Pavimento della lib nell'app: `^2.1.2-alpha.27`.

## Punti da guardare (revisione)

1. Due riquadri nella stessa pagina (form del checkout + riquadro autonomo dell'express): il submit del form non tocca il riquadro autonomo e la sfida si accende solo nel riquadro giusto. Test: L1 casi 10 e 12.
2. Pagina tornata dalla cache del browser (bfcache) o secondo «Paga» dopo un errore: ogni submit chiede un token nuovo, mai quello vecchio. Test: L1 caso 14.
3. `RecaptchaGuard::challenged()` chiamato senza sessione (pagine API, CLI): risponde `false` e non avvisa. Test: A3.
4. Il ripiego `form.submit()` di `confirm.js:143` (browser senza `requestSubmit`) salta l'ascolto del submit: il server risponde 617 (token vuoto). Documentato in A7, nessun codice.
5. Tag `enterprise.js` già in pagina che fallisce, o script che non arriva mai: con la chiave a casella si accende la sfida, senza si manda il form con il token vuoto (617 dal server). Test: L1 casi 5, 7, 8 e 15.

In più, a mano nel browser (E5): il foglio Apple Pay/Google Pay vuole `event.resolve` entro 1 s dal clic; la verifica v3 parte dopo `resolve`.

## Note per il messaggio finale

- Il vecchio `formSubmit` che passa `undefined` alla callback dopo un errore resta in TODO (spec §2).
- La prova nel browser con le chiavi vere (chiave v3 del progetto GCP più una chiave di prova che dà punteggio basso) è dell'utente.
- Rilascio della lib (alpha.27), merge e push solo con il via dell'utente, uno per volta.

## Decisioni già prese

- Riquadro autonomo: `data-wi-recaptcha-standalone` sul contenitore; i suoi campi nascosti prendono `form="wi-recaptcha-none"` e `required=false`, così non entrano nel form che li contiene e non lo bloccano.
- Doppio submit durante l'attesa del token: il secondo si inghiotte (`WeakSet` `recaptchaWaiting`).
- Reinvio: `form.dataset.wiRecaptchaSending='1'` per la durata del `requestSubmit`; se `requestSubmit(submitter)` lancia, si riprova senza submitter.
- `fetch.js` (`postData`) legge il JSON anche dalle risposte non 2xx: se c'è `status` lo usa; 618 accende la sfida.
- `send.js` (`formSubmit`) accende la sfida sul form quando la risposta ha `status` 618.
- `markup()` del componente è statico e puro (testabile senza credenziali).
- `data-wi-badge` è sempre `"true"` o `"false"`.
- Etichette nel backend: «Chiave Sito v2 (casella)», «Chiave Sito v3 (punteggio)», «Badge v3» (select «Visibile»/«Nascosto», obbligatoria).
- Il test della guardia apre la sessione sotto `ob_start()` (in CLI `session_start()` dopo un output fallisce; verificato).
- Express nascosto all'ospite quando il reCAPTCHA non ha chiavi (`RecaptchaGuard::configured()` falso): `verify()` senza token lancia sempre 617, l'avvio non passerebbe mai.
- Express con la casella a vista all'avvio (solo v2, o sfida già aperta dal server): i bottoni restano spenti (`wi-express-locked`) finché non si spunta; il clic a bottoni spenti non chiama `resolve`, quindi il foglio non si apre.
- Express senza token (casella aperta e non spuntata): `start` non parte, il JavaScript tratta la risposta come un 618.
- Express, sfida: la casella si apre appena arriva il 618 (il foglio del wallet la copre finché resta aperto); il foglio chiuso ripete il messaggio. Scostamento voluto dalla spec §7.2, che la apriva a foglio chiuso: così non dipende dall'evento di chiusura del wallet.
- `place` dell'express guarda il segno dopo `expressCart()`: senza carrello rapido resta il 409 di oggi.
- `resetRecaptcha()` del checkout passa a `wiRecaptchaReset` quando c'è e tiene il ripiego Enterprise (lo controlla `CheckoutStripeTest`).
- In `checkout.js` il metodo nuovo della classe `Express` si chiama `lockButtons`: `lock` esiste già in un'altra classe dello stesso file.

## Mappa dei file

**Lib** (`/Users/andreamarinoni/Developer/worktrees/recaptcha-v3/lib`)
- Riscrive: `src/build/frontend/js/form/recaptcha.js`.
- Modifica: `src/build/global/js/fetch.js`, `src/build/frontend/js/form/send.js`, `src/export/frontend/head.js`, `package.json`.
- Crea: `src/build/frontend/css/components/form/recaptcha.css`.
- Test: `test/recaptcha-loading.test.cjs` (adattato), `test/recaptcha-v3.test.cjs`, `test/fetch-recaptcha.test.cjs`, `test/recaptcha-v3.browser.test.cjs` (fuori da `npm test`).

**App** (`/Users/andreamarinoni/Developer/worktrees/recaptcha-v3/app`)
- Crea: `class/App/Security/RecaptchaChallenge.php`, `class/Api/ErrorStatus.php`.
- Modifica: `class/Api/Call.php`, `class/Plugin/Google/Security/reCAPTCHA.php`, `class/App/Security/RecaptchaGuard.php`, `class/Plugin/Google/Credentials.php`, `class/App/Credentials.php`, `class/App/Models/Config/Security.php`, `class/App/Resources/Config/SecurityResource.php`, `class/Themes/Wonder/Form/Components/reCAPTCHA.php`, `class/Api/Handler.php`, `app/http/api/resource/index.php`, `class/Auth/Frontend/AuthController.php`, `resources/lang/{it,en,de,es,fr}/{notifications,components}.json`, `composer.json`, `package.json`, `docs/app/servizi/recaptcha.md`, `docs/app/piattaforma/dev-shared.md`.
- Test: `tests/Http/CallHttpCodeTest.php`, `tests/Plugin/Google/RecaptchaEngineTest.php`, `tests/Security/RecaptchaGuardTest.php`, `tests/Security/RecaptchaConfigTest.php`, `tests/Themes/RecaptchaComponentTest.php`, `tests/Http/ErrorStatusTest.php`.

**Ecommerce** (`/Users/andreamarinoni/Developer/worktrees/recaptcha-v3/ecommerce`)
- Crea: `src/Frontend/Checkout/ExpressVerification.php`.
- Modifica: `src/Frontend/Checkout/ExpressController.php`, `src/Frontend/Checkout/ExpressCart.php`, `src/Frontend/Checkout/ExpressCheckout.php`, `src/Frontend/Checkout/CheckoutController.php`, `view/components/checkout/express.php`, `resources/assets/js/checkout.js`, `resources/assets/css/express.css`, `lang/{it,en}/ecommerce.json`.
- Test: `tests/ExpressRecaptchaTest.php`, `tests/CheckoutRecaptchaTest.php`, `tests/integrazione/ExpressHttpTest.php`.

Comandi:
- lib: `cd …/lib && npm test` (un file: `node test/recaptcha-v3.test.cjs`).
- app: un file `cd …/app && php tests/Security/RecaptchaGuardTest.php`; tutta la suite:
  ```bash
  for f in $(find tests -name '*.php' ! -name 'harness.php' ! -path '*/fixtures/*' ! -path '*/support/*' | sort); do php "$f" >/dev/null 2>&1 || echo "FAIL $f"; done
  ```
  Rosso già noto e non nostro: `tests/Docs/ApiReferenceTest.php`.
- ecommerce: `cd …/ecommerce && WONDER_NO_MAIL=1 php tests/run.php`. Mai due suite insieme (DB condiviso: prima `ps aux | grep "php .*Test.php"`).

---

### Compito 0: i worktree

**File:** nessuno nel codice; il registro in `packages/gestionale/.superpowers/sdd/`.

- [ ] **Passo 1: worktree dai main remoti**

```bash
mkdir -p /Users/andreamarinoni/Developer/worktrees/recaptcha-v3
for r in lib app ecommerce; do
  git -C /Users/andreamarinoni/Developer/packages/$r fetch origin
  git -C /Users/andreamarinoni/Developer/packages/$r worktree add -b recaptcha-v3 /Users/andreamarinoni/Developer/worktrees/recaptcha-v3/$r origin/main
done
```

Expected: tre worktree sul ramo `recaptcha-v3`. Non si cambia mai ramo nelle cartelle di `packages/` (altre sessioni ci lavorano).

- [ ] **Passo 2: dipendenze**

```bash
cp -R /Users/andreamarinoni/Developer/packages/app/vendor /Users/andreamarinoni/Developer/worktrees/recaptcha-v3/app/vendor
cp -R /Users/andreamarinoni/Developer/packages/ecommerce/vendor /Users/andreamarinoni/Developer/worktrees/recaptcha-v3/ecommerce/vendor
ln -sfn /Users/andreamarinoni/Developer/worktrees/recaptcha-v3/app /Users/andreamarinoni/Developer/worktrees/recaptcha-v3/ecommerce/vendor/wonder-image/app
ln -sfn /Users/andreamarinoni/Developer/packages/gestionale /Users/andreamarinoni/Developer/worktrees/recaptcha-v3/ecommerce/vendor/wonder-image/gestionale
cd /Users/andreamarinoni/Developer/worktrees/recaptcha-v3/lib && npm ci
```

Expected: `npm ci` senza errori. Se un test PHP non trova una classe nuova: `composer dump-autoload` nel worktree (mai composer nel sito di prova).

- [ ] **Passo 3: suite di partenza**

Run: `cd …/lib && npm test`; la suite dell'app col ciclo sopra; `cd …/ecommerce && WONDER_NO_MAIL=1 php tests/run.php`.
Expected: verdi, tranne `tests/Docs/ApiReferenceTest.php` nell'app. Annotare nel registro i rossi trovati prima di toccare il codice.

---

### Compito L1: `recaptcha.js` con v3, sfida e riquadro autonomo

**File:**
- Riscrive: `src/build/frontend/js/form/recaptcha.js`
- Test: `test/recaptcha-loading.test.cjs` (adattato), `test/recaptcha-v3.test.cjs` (nuovo)

**Interfacce:**
- Produce (globali, la build concatena i file): `setRecaptcha()` (già chiamata da `src/build/frontend/js/form/input.js:399`), `setRecaptchaInput()` (callback `onload` di Google), `wiRecaptchaChallenge(scope?) → bool`, `wiRecaptchaToken(scope) → Promise<{token, action, type}|null>`, `wiRecaptchaReset(scope)`, `RECAPTCHA_WAIT` (ms, 5000).
- Evento: `wi-recaptcha-change` (bubbles) sul `.g-recaptcha` con `detail = {token, type}` a ogni scrittura dei campi.
- Markup letto (prodotto da A5): `.g-recaptcha` con `data-wi-site-key`, `data-wi-score-key` (solo se c'è la v3), `data-wi-badge` (`"true"`/`"false"`), `data-wi-challenge="1"` (sfida aperta dal server), `data-wi-theme`, `data-wi-size`, `data-wi-action`; campi `g-recaptcha-token`, `g-recaptcha-action`, `g-recaptcha-type`.

- [ ] **Passo 1: test nuovo `test/recaptcha-v3.test.cjs`**

```js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const SOURCE = fs.readFileSync(require.resolve('../src/build/frontend/js/form/recaptcha.js'), 'utf8');
const STANDALONE = '[data-wi-recaptcha-standalone]';
const flush = () => new Promise(r => setImmediate(r));
const wait = ms => new Promise(r => setTimeout(r, ms));
const plain = v => JSON.parse(JSON.stringify(v)); // gli oggetti del contesto vm non passano deepStrictEqual

function setup(options = {}) {
    const t = { scripts: [], renders: [], resets: [], executes: [], widgets: [], listeners: [], classes: new Set(), checks: 0 };

    t.form = (id = 'contact-form') => {
        const form = { tagName: 'FORM', id, dataset: {}, submits: [], marks: [], members: [],
            addEventListener() {}, matches: () => false,
            closest: s => s === 'form' ? form : null,
            querySelectorAll: s => form.members.filter(n => n.is(s)),
            requestSubmit(submitter) {
                form.marks.push(form.dataset.wiRecaptchaSending);
                if (options.rejectSubmitter && submitter) { throw new TypeError('submitter non valido'); }
                form.submits.push(submitter === undefined ? 'none' : submitter);
                if (form.onResubmit) { form.onResubmit(); }
            } };
        return form;
    };

    t.box = form => {
        const box = { members: [], addEventListener() {}, matches: s => s === STANDALONE,
            closest: s => s === STANDALONE ? box : s === 'form' ? form : null,
            querySelectorAll: s => box.members.filter(n => n.is(s)) };
        return box;
    };

    t.widget = (dataset = {}, form = null, box = null) => {
        const data = Object.assign({ wiAction: 'contact', wiSiteKey: 'site-key' }, dataset);
        const scopeOf = s => s === STANDALONE ? box : s === 'form' ? form : null;
        const inputs = {};
        ['token', 'action', 'type'].forEach(name => {
            inputs[name] = { name, value: '', required: false, attrs: {},
                is: s => s === 'input[name="g-recaptcha-' + name + '"]',
                setAttribute(k, v) { this.attrs[k] = v; }, closest: scopeOf };
        });
        const element = { isConnected: true, events: [], dataset: data, inputs,
            is: s => s === '.g-recaptcha' || (s === '.g-recaptcha[data-wi-score-key]' && !!data.wiScoreKey),
            matches: s => s === '.g-recaptcha', closest: scopeOf,
            dispatchEvent(e) { element.events.push(e); return true; } };
        [box, form].forEach(owner => { if (owner) { owner.members.push(element, inputs.token, inputs.action, inputs.type); } });
        t.widgets.push(element);
        return element;
    };

    const document = {
        addEventListener: (type, fn, capture) => t.listeners.push({ type, fn, capture }),
        querySelectorAll: s => t.widgets.filter(n => n.is(s)),
        querySelector: s => s.includes('enterprise.js') ? options.existingScript || null : t.widgets.find(n => n.is(s)) || null,
        createElement: () => { const script = { remove() { t.scripts = t.scripts.filter(s => s !== script); } }; return script; },
        head: { appendChild: script => t.scripts.push(script) },
        documentElement: { classList: { add: c => t.classes.add(c) } },
    };
    const context = { Set, WeakMap, WeakSet, Promise, setTimeout, clearTimeout, document,
        CustomEvent: class { constructor(type, init) { this.type = type; this.detail = init.detail; this.bubbles = init.bubbles; } },
        check: () => { t.checks += 1; } };
    context.window = context;
    vm.runInNewContext(SOURCE, context);
    t.context = context;

    t.grecaptcha = (execute = () => Promise.resolve('tok-v3')) => {
        context.grecaptcha = { enterprise: {
            ready: fn => fn(),
            execute: (key, o) => { t.executes.push({ key, action: o.action }); return execute(key, o); },
            render: (el, o) => { t.renders.push({ element: el, options: o }); return t.renders.length - 1; },
            reset: id => t.resets.push(id) } };
    };

    t.submit = (form, submitter = 'btn') => {
        const event = { target: form, submitter, prevented: false, stopped: false,
            preventDefault() { event.prevented = true; }, stopImmediatePropagation() { event.stopped = true; } };
        t.listeners.filter(l => l.type === 'submit').forEach(l => l.fn(event));
        return event;
    };

    return t;
}

const tests = [];
const test = (name, fn) => tests.push({ name, fn });

test('setRecaptcha: un solo ascolto in cattura, badge spento, script con la chiave v3', () => {
    const t = setup();
    t.widget({ wiScoreKey: 'score key', wiBadge: 'false' }, t.form());
    t.context.setRecaptcha(); t.context.setRecaptcha();
    assert.equal(t.listeners.length, 1);
    assert.equal(t.listeners[0].type, 'submit');
    assert.equal(t.listeners[0].capture, true);
    assert.ok(t.classes.has('wi-recaptcha-badge-off'));
    assert.equal(t.scripts.length, 1);
    assert.match(t.scripts[0].src, /onload=setRecaptchaInput&render=score%20key$/);
});

test('submit con la v3: token fresco, tipo score, reinvio col submitter', async () => {
    const t = setup(); const form = t.form();
    const element = t.widget({ wiScoreKey: 'score-key' }, form);
    t.context.setRecaptcha(); t.grecaptcha();
    let inner = null;
    form.onResubmit = () => { inner = t.submit(form); };
    const event = t.submit(form);
    assert.ok(event.prevented && event.stopped);
    await flush();
    assert.equal(inner.prevented, false, 'il reinvio passa');
    assert.deepEqual(form.submits, ['btn']);
    assert.deepEqual(form.marks, ['1']);
    assert.equal(form.dataset.wiRecaptchaSending, undefined);
    assert.equal(element.inputs.token.value, 'tok-v3');
    assert.equal(element.inputs.action.value, 'contact');
    assert.equal(element.inputs.type.value, 'score');
    assert.deepEqual(plain(t.executes), [{ key: 'score-key', action: 'contact' }]);
    assert.equal(t.renders.length, 0, 'la casella non si disegna');
    assert.deepEqual(plain(element.events.at(-1).detail), { token: 'tok-v3', type: 'score' });
});

test('doppio clic durante l\'attesa: una sola richiesta e un solo invio', async () => {
    const t = setup(); const form = t.form();
    t.widget({ wiScoreKey: 'score-key' }, form);
    t.context.setRecaptcha();
    let release;
    t.grecaptcha(() => new Promise(r => { release = r; }));
    assert.ok(t.submit(form).prevented);
    assert.ok(t.submit(form).prevented);
    await flush();
    release('tok-v3');
    await flush();
    assert.equal(t.executes.length, 1);
    assert.deepEqual(form.submits, ['btn']);
});

test('requestSubmit rifiuta il submitter: si riprova senza', async () => {
    const t = setup({ rejectSubmitter: true }); const form = t.form();
    t.widget({ wiScoreKey: 'score-key' }, form);
    t.context.setRecaptcha(); t.grecaptcha();
    t.submit(form);
    await flush();
    assert.deepEqual(form.submits, ['none']);
    assert.deepEqual(form.marks, ['1', '1']);
    assert.equal(form.dataset.wiRecaptchaSending, undefined);
});

test('execute fallisce con la chiave a casella: si accende la sfida, niente invio', async () => {
    const t = setup(); const form = t.form();
    const element = t.widget({ wiScoreKey: 'score-key' }, form);
    t.context.setRecaptcha(); t.grecaptcha(() => Promise.reject(new Error('no')));
    t.submit(form);
    await flush();
    assert.deepEqual(form.submits, []);
    assert.equal(element.dataset.wiChallenge, '1');
    assert.equal(element.inputs.type.value, 'checkbox');
    assert.equal(element.inputs.token.required, true);
    assert.equal(t.renders.length, 1);
    assert.equal(t.renders[0].options.sitekey, 'site-key');
});

test('execute fallisce senza chiave a casella: invio col token vuoto (617 dal server)', async () => {
    const t = setup(); const form = t.form();
    const element = t.widget({ wiScoreKey: 'score-key', wiSiteKey: '' }, form);
    t.context.setRecaptcha(); t.grecaptcha(() => Promise.reject(new Error('no')));
    t.submit(form);
    await flush();
    assert.deepEqual(form.submits, ['btn']);
    assert.equal(element.inputs.token.value, '');
    assert.equal(element.inputs.type.value, 'score');
});

test('script che non arriva mai: dopo RECAPTCHA_WAIT si accende la sfida', async () => {
    const t = setup(); const form = t.form();
    const element = t.widget({ wiScoreKey: 'score-key' }, form);
    t.context.setRecaptcha();
    t.context.RECAPTCHA_WAIT = 20;
    t.submit(form);
    await wait(60);
    assert.equal(element.dataset.wiChallenge, '1');
    assert.deepEqual(form.submits, []);
});

test('script che fallisce: si accende la sfida', async () => {
    const t = setup(); const form = t.form();
    const element = t.widget({ wiScoreKey: 'score-key' }, form);
    t.context.setRecaptcha();
    t.submit(form);
    t.scripts[0].onerror();
    await flush();
    assert.equal(element.dataset.wiChallenge, '1');
    assert.deepEqual(form.submits, []);
});

test('solo casella: il submit non si ferma e il token arriva dalla casella', async () => {
    const t = setup(); const form = t.form();
    t.widget({}, form);
    t.context.setRecaptcha(); t.grecaptcha();
    assert.equal(t.submit(form).prevented, false);
    assert.equal(await t.context.wiRecaptchaToken(form), null);
    t.context.setRecaptchaInput();
    t.renders[0].options.callback('chk');
    assert.deepEqual(plain(await t.context.wiRecaptchaToken(form)), { token: 'chk', action: 'contact', type: 'checkbox' });
});

test('riquadro autonomo: campi fuori dal form, submit del form libero, token a richiesta', async () => {
    const t = setup(); const form = t.form('checkout'); const box = t.box(form);
    const element = t.widget({ wiScoreKey: 'score-key', wiAction: 'ecommerce_express' }, form, box);
    t.context.setRecaptcha(); t.grecaptcha(() => Promise.resolve('tok-x'));
    Object.values(element.inputs).forEach(input => {
        assert.equal(input.attrs.form, 'wi-recaptcha-none');
        assert.equal(input.required, false);
    });
    assert.equal(t.submit(form).prevented, false);
    assert.deepEqual(plain(await t.context.wiRecaptchaToken(box)), { token: 'tok-x', action: 'ecommerce_express', type: 'score' });
});

test('riquadro autonomo, execute fallisce: null, sfida accesa, campi non obbligatori', async () => {
    const t = setup(); const form = t.form('checkout'); const box = t.box(form);
    const element = t.widget({ wiScoreKey: 'score-key', wiAction: 'ecommerce_express' }, form, box);
    t.context.setRecaptcha(); t.grecaptcha(() => Promise.reject(new Error('no')));
    assert.equal(await t.context.wiRecaptchaToken(box), null);
    assert.equal(element.dataset.wiChallenge, '1');
    assert.equal(element.inputs.token.required, false);
});

test('due form: la sfida senza scope va all\'ultimo usato', async () => {
    const t = setup(); const a = t.form('a'); const b = t.form('b');
    const wa = t.widget({ wiScoreKey: 'score-key' }, a);
    const wb = t.widget({ wiScoreKey: 'score-key' }, b);
    t.context.setRecaptcha(); t.grecaptcha();
    t.submit(b);
    await flush();
    assert.equal(t.context.wiRecaptchaChallenge(), true);
    assert.equal(wb.dataset.wiChallenge, '1');
    assert.equal(wa.dataset.wiChallenge, undefined);
});

test('wiRecaptchaReset: casella azzerata e campi vuoti', () => {
    const t = setup(); const form = t.form();
    const element = t.widget({}, form);
    t.context.setRecaptcha(); t.grecaptcha();
    t.context.setRecaptchaInput();
    t.renders[0].options.callback('chk');
    t.context.wiRecaptchaReset(form);
    assert.deepEqual(plain(t.resets), [0]);
    assert.equal(element.inputs.token.value, '');
    assert.equal(element.inputs.type.value, 'checkbox');
});

test('bfcache o secondo «Paga»: ogni submit chiede un token nuovo', async () => {
    const t = setup(); const form = t.form();
    const element = t.widget({ wiScoreKey: 'score-key' }, form);
    t.context.setRecaptcha();
    let n = 0;
    t.grecaptcha(() => Promise.resolve('tok-' + (++n)));
    t.submit(form); await flush();
    t.submit(form); await flush();
    assert.equal(t.executes.length, 2);
    assert.equal(element.inputs.token.value, 'tok-2');
    assert.deepEqual(form.submits, ['btn', 'btn']);
});

test('tag enterprise.js già in pagina che fallisce: sfida accesa, nessuno script aggiunto', async () => {
    const handlers = {};
    const t = setup({ existingScript: { addEventListener: (type, fn) => { handlers[type] = fn; } } });
    const form = t.form();
    const element = t.widget({ wiScoreKey: 'score-key' }, form);
    t.context.setRecaptcha();
    t.submit(form);
    handlers.error();
    await flush();
    assert.equal(element.dataset.wiChallenge, '1');
    assert.equal(t.scripts.length, 0);
});

(async () => {
    for (const { name, fn } of tests) {
        try { await fn(); console.log('PASS: ' + name); }
        catch (error) { console.log('FAIL: ' + name + '\n  ' + error.message); process.exitCode = 1; }
    }
})();
```

- [ ] **Passo 2: il test di caricamento si adatta (stessi casi, nuovo stub di `document`)**

Sostituire `test/recaptcha-loading.test.cjs` con:

```js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
let observer, scripts = [], renders = [], checks = 0;
const elements = [0, 1].map(id => {
    const inputs = { token: {value: '', closest: () => null}, action: {value: '', closest: () => null} };
    const events = {};
    const form = {id: 'form'+id, matches: () => false, addEventListener: (name, fn) => { events[name] = fn; },
        querySelectorAll: selector => selector.includes('-token') ? [inputs.token] : selector.includes('-action') ? [inputs.action] : []};
    return {isConnected: true, dataset: {wiSiteKey: 'test', wiAction: 'contact'},
        closest: selector => selector === 'form' ? form : null, dispatchEvent() {}, inputs, events};
});
const context = {Set, WeakMap, WeakSet, check: () => checks++,
    IntersectionObserver: class {constructor(fn) {observer = fn;} observe() {}},
    document: {querySelectorAll: () => elements, querySelector: () => null, addEventListener() {},
        documentElement: {classList: {add() {}}},
        createElement: () => ({remove() {scripts = scripts.filter(s => s !== this);}}),
        head: {appendChild: script => scripts.push(script)}}};
context.window = context;
vm.runInNewContext(fs.readFileSync(require.resolve('../src/build/frontend/js/form/recaptcha.js'), 'utf8'), context);
context.setRecaptcha(); context.setRecaptcha();
assert.equal(scripts.length, 0, 'offscreen forms do not download reCAPTCHA');
observer([{target: elements[0], isIntersecting: true}]);
elements[0].events.focusin();
assert.equal(scripts.length, 1, 'one loader across repeated activation');
assert.match(scripts[0].src, /render=explicit$/, 'checkbox only loads the explicit renderer');
scripts[0].onerror();
elements[0].events.focusin();
assert.equal(scripts.length, 1, 'failed loader can retry');
context.grecaptcha = {enterprise: {render(element, options) {renders.push({element, options}); return renders.length-1;}}};
context.setRecaptchaInput(); context.setRecaptchaInput();
assert.equal(renders.length, 1, 'only activated widget renders once');
renders[0].options.callback('token');
assert.equal(elements[0].inputs.token.value, 'token');
assert.equal(elements[0].inputs.action.value, 'contact');
renders[0].options['expired-callback']();
assert.equal(elements[0].inputs.token.value, '');
assert.equal(elements[0].inputs.action.value, '');
elements[1].events.focusin();
assert.equal(renders.length, 2, 'second form can initialize independently');
assert.equal(checks, 2, 'validation runs on token and expiration');
console.log('PASS: deferred loading, deduplication, retry, multiple forms, token expiration');
```

- [ ] **Passo 3: i test falliscono**

Run: `node test/recaptcha-v3.test.cjs; node test/recaptcha-loading.test.cjs`
Expected: `FAIL` su quasi tutti i casi v3 (`wiRecaptchaToken`/`wiRecaptchaChallenge` non definite, nessun ascolto del submit); il test di caricamento può già passare o fallire su `type`: annotare quale.

- [ ] **Passo 4: riscrivere `src/build/frontend/js/form/recaptcha.js`**

```js
var recaptchaPending = new Set();
var recaptchaWidgets = new WeakMap();
var recaptchaObserved = new WeakSet();
var recaptchaWaiting = new WeakSet();
var recaptchaReady = [];
var recaptchaScript = null;
var recaptchaObserver = null;
var recaptchaLast = null;
var recaptchaSubmitBound = false;
var RECAPTCHA_WAIT = 5000;
var RECAPTCHA_STANDALONE = '[data-wi-recaptcha-standalone]';

function recaptchaScope(element) { return element.closest(RECAPTCHA_STANDALONE) || element.closest('form') || element.parentElement; }
function recaptchaStandalone(element) { return !!element.closest(RECAPTCHA_STANDALONE) || !element.closest('form'); }
// Il primo nodo dello scope che non appartiene a un riquadro autonomo annidato.
function recaptchaOwn(scope, selector) {
    var own = scope.matches && scope.matches(RECAPTCHA_STANDALONE) ? scope : null;
    var nodes = scope.querySelectorAll(selector);
    for (var i = 0; i < nodes.length; i++) { if (nodes[i].closest(RECAPTCHA_STANDALONE) === own) { return nodes[i]; } }
    return null;
}
function recaptchaInput(scope, name) { return recaptchaOwn(scope, 'input[name="g-recaptcha-' + name + '"]'); }
function recaptchaElementIn(scope) { return scope.matches && scope.matches('.g-recaptcha') ? scope : recaptchaOwn(scope, '.g-recaptcha'); }
function recaptchaAction(element) { var form = element.closest('form'); return element.dataset.wiAction || (form && form.id) || 'undefined'; }
// Casella visibile: solo con la chiave v2 e, se c'è anche la v3, dopo la sfida.
function recaptchaRenders(element) { return !!element.dataset.wiSiteKey && (!element.dataset.wiScoreKey || element.dataset.wiChallenge === '1'); }
function recaptchaPageScoreKey() { var element = document.querySelector('.g-recaptcha[data-wi-score-key]'); return element ? element.dataset.wiScoreKey : ''; }
function recaptchaWrite(element, token, type) {
    var scope = recaptchaScope(element);
    var fields = { token: token, action: token ? recaptchaAction(element) : '', type: type };
    Object.keys(fields).forEach(function (name) { var input = recaptchaInput(scope, name); if (input) { input.value = fields[name]; } });
    if (typeof check === 'function') { check(); }
    if (typeof CustomEvent === 'function') { element.dispatchEvent(new CustomEvent('wi-recaptcha-change', { bubbles: true, detail: { token: token, type: type } })); }
}
function recaptchaLoaded() { return !!(window.grecaptcha && grecaptcha.enterprise && (grecaptcha.enterprise.execute || grecaptcha.enterprise.render)); }
function recaptchaWhenReady(callback) { if (recaptchaLoaded()) { callback(true); return; } recaptchaReady.push(callback); }
function recaptchaFlush(ready) { var queue = recaptchaReady; recaptchaReady = []; queue.forEach(function (callback) { callback(ready); }); }
function recaptchaFail() { recaptchaFlush(false); }
function setRecaptchaInput() {
    recaptchaFlush(true);
    if (!window.grecaptcha || !grecaptcha.enterprise || !grecaptcha.enterprise.render) { return; }
    recaptchaPending.forEach(function (element) {
        if (!element.isConnected || recaptchaWidgets.has(element)) { recaptchaPending.delete(element); return; }
        var widget = grecaptcha.enterprise.render(element, { sitekey: element.dataset.wiSiteKey, action: recaptchaAction(element), theme: element.dataset.wiTheme, size: element.dataset.wiSize,
            callback: function (token) { recaptchaWrite(element, token, 'checkbox'); },
            'expired-callback': function () { recaptchaWrite(element, '', 'checkbox'); },
            'error-callback': function () { recaptchaWrite(element, '', 'checkbox'); } });
        recaptchaWidgets.set(element, widget);
        recaptchaPending.delete(element);
    });
}
function requestRecaptcha(element) {
    if (recaptchaRenders(element) && !recaptchaWidgets.has(element)) { recaptchaPending.add(element); }
    if (recaptchaLoaded()) { setRecaptchaInput(); return; }
    if (recaptchaScript) { return; }
    recaptchaScript = document.querySelector('script[src*="/recaptcha/enterprise.js"]');
    if (recaptchaScript) {
        recaptchaScript.addEventListener('load', setRecaptchaInput, { once: true });
        recaptchaScript.addEventListener('error', recaptchaFail, { once: true });
        return;
    }
    var scoreKey = recaptchaPageScoreKey();
    recaptchaScript = document.createElement('script');
    recaptchaScript.async = true;
    recaptchaScript.src = 'https://www.google.com/recaptcha/enterprise.js?onload=setRecaptchaInput&render=' + (scoreKey ? encodeURIComponent(scoreKey) : 'explicit');
    recaptchaScript.onerror = function () { recaptchaScript.remove(); recaptchaScript = null; recaptchaFail(); };
    document.head.appendChild(recaptchaScript);
}
function recaptchaExecute(element) {
    return new Promise(function (resolve, reject) {
        var done = false;
        var timer = setTimeout(function () { finish(new Error('timeout')); }, RECAPTCHA_WAIT);
        function finish(error, token) {
            if (done) { return; }
            done = true; clearTimeout(timer);
            if (error) { reject(error); return; }
            recaptchaWrite(element, token, 'score'); recaptchaLast = element; resolve(token);
        }
        recaptchaWhenReady(function (ready) {
            if (!ready) { finish(new Error('load')); return; }
            try {
                grecaptcha.enterprise.ready(function () {
                    try {
                        grecaptcha.enterprise.execute(element.dataset.wiScoreKey, { action: recaptchaAction(element) })
                            .then(function (token) { finish(null, token); }, function (error) { finish(error || new Error('execute')); });
                    } catch (error) { finish(error); }
                });
            } catch (error) { finish(error); }
        });
        requestRecaptcha(element);
    });
}
function recaptchaScoreElement(form) { var element = recaptchaOwn(form, '.g-recaptcha'); return element && element.dataset.wiScoreKey && element.dataset.wiChallenge !== '1' ? element : null; }
function recaptchaResubmit(form, submitter) {
    form.dataset.wiRecaptchaSending = '1';
    try {
        if (typeof form.requestSubmit === 'function') { try { form.requestSubmit(submitter); } catch (error) { form.requestSubmit(); } }
        else { var event = new Event('submit', { bubbles: true, cancelable: true }); if (form.dispatchEvent(event)) { form.submit(); } }
    } finally { delete form.dataset.wiRecaptchaSending; }
}
function recaptchaSubmit(event) {
    var form = event.target;
    if (!form || form.tagName !== 'FORM') { return; }
    var element = recaptchaScoreElement(form);
    if (!element || form.dataset.wiRecaptchaSending === '1') { return; }
    event.preventDefault(); event.stopImmediatePropagation();
    if (recaptchaWaiting.has(form)) { return; }
    recaptchaWaiting.add(form); recaptchaLast = element;
    var submitter = event.submitter;
    recaptchaExecute(element).then(function () { recaptchaWaiting.delete(form); recaptchaResubmit(form, submitter); },
        function () {
            recaptchaWaiting.delete(form);
            if (element.dataset.wiSiteKey) { wiRecaptchaChallenge(element); return; }
            recaptchaWrite(element, '', 'score'); recaptchaResubmit(form, submitter);
        });
}
function wiRecaptchaChallenge(scope) {
    var element = scope ? recaptchaElementIn(scope) : recaptchaLast;
    if (!element || !element.dataset.wiSiteKey) { return false; }
    element.dataset.wiChallenge = '1';
    if (!recaptchaStandalone(element)) {
        var owner = recaptchaScope(element);
        ['token', 'action'].forEach(function (name) { var input = recaptchaInput(owner, name); if (input) { input.required = true; } });
    }
    recaptchaWrite(element, '', 'checkbox');
    if (recaptchaWidgets.has(element)) { try { grecaptcha.enterprise.reset(recaptchaWidgets.get(element)); } catch (error) { /* non montato */ } }
    requestRecaptcha(element);
    return true;
}
function wiRecaptchaToken(scope) {
    var element = recaptchaElementIn(scope);
    if (!element) { return Promise.resolve(null); }
    var action = recaptchaAction(element);
    if (!element.dataset.wiScoreKey || element.dataset.wiChallenge === '1') {
        var input = recaptchaInput(recaptchaScope(element), 'token'); var token = input ? input.value : '';
        return Promise.resolve(token ? { token: token, action: action, type: 'checkbox' } : null);
    }
    return recaptchaExecute(element).then(function (token) { return { token: token, action: action, type: 'score' }; },
        function () { if (element.dataset.wiSiteKey) { wiRecaptchaChallenge(element); return null; } return { token: '', action: action, type: 'score' }; });
}
function wiRecaptchaReset(scope) {
    var element = recaptchaElementIn(scope);
    if (!element) { return; }
    recaptchaWrite(element, '', recaptchaRenders(element) ? 'checkbox' : 'score');
    if (recaptchaWidgets.has(element)) { try { grecaptcha.enterprise.reset(recaptchaWidgets.get(element)); } catch (error) { /* widget non montato */ } }
}
function setRecaptcha() {
    if (!recaptchaSubmitBound) { recaptchaSubmitBound = true; document.addEventListener('submit', recaptchaSubmit, true); }
    if (!recaptchaObserver && 'IntersectionObserver' in window) {
        recaptchaObserver = new IntersectionObserver(function (entries) { entries.forEach(function (entry) { if (entry.isIntersecting) { requestRecaptcha(entry.target); } }); }, { rootMargin: '300px' });
    }
    document.querySelectorAll('.g-recaptcha').forEach(function (element) {
        if (recaptchaObserved.has(element)) { return; }
        recaptchaObserved.add(element);
        if (element.dataset.wiBadge === 'false') { document.documentElement.classList.add('wi-recaptcha-badge-off'); }
        var scope = recaptchaScope(element);
        if (recaptchaStandalone(element)) {
            ['token', 'action', 'type'].forEach(function (name) { var input = recaptchaInput(scope, name); if (input) { input.setAttribute('form', 'wi-recaptcha-none'); input.required = false; } });
        }
        scope.addEventListener('focusin', function () { requestRecaptcha(element); });
        scope.addEventListener('pointerdown', function () { requestRecaptcha(element); }, { passive: true });
        if (recaptchaObserver) { recaptchaObserver.observe(element); } else { requestRecaptcha(element); }
    });
}
```

Nota per chi esegue: `RECAPTCHA_WAIT` è una `var` globale letta a ogni `recaptchaExecute`, così il caso 7 la abbassa dal contesto.

- [ ] **Passo 5: i test passano**

Run: `node test/recaptcha-v3.test.cjs && node test/recaptcha-loading.test.cjs`
Expected: 15 righe `PASS:` e la riga `PASS: deferred loading, …`; codice d'uscita 0.

- [ ] **Passo 6: commit**

```bash
git add src/build/frontend/js/form/recaptcha.js test/recaptcha-v3.test.cjs test/recaptcha-loading.test.cjs
git commit -m "reCAPTCHA v3 in background con la casella v2 come sfida e il riquadro autonomo

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Compito L2: il 618 arriva al riquadro (`fetch.js`, `send.js`), CSS del badge

**File:**
- Modifica: `src/build/global/js/fetch.js` (`postData`, righe 1–74), `src/build/frontend/js/form/send.js` (`formSubmit`, righe 139–160), `src/export/frontend/head.js` (dopo la riga 63), `package.json` (`scripts.test`)
- Crea: `src/build/frontend/css/components/form/recaptcha.css`
- Test: `test/fetch-recaptcha.test.cjs`

**Interfacce:**
- Consuma: `wiRecaptchaChallenge(scope?)` da L1.
- Produce: `postData` restituisce il corpo JSON di una risposta non 2xx se ha `status` (poi la catena esistente lancia `Error(status)`); su `618` chiama `wiRecaptchaChallenge()` prima del toast. `formSubmit` chiama `wiRecaptchaChallenge(form)` su `status == 618` e passa comunque la risposta alla callback.
- Classi CSS: `.wi-recaptcha-badge-off` su `<html>` nasconde il badge; `.wi-recaptcha-terms` è la riga d'attribuzione (A5).

- [ ] **Passo 1: test `test/fetch-recaptcha.test.cjs`**

```js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const FETCH = fs.readFileSync(require.resolve('../src/build/global/js/fetch.js'), 'utf8');
const SEND = fs.readFileSync(require.resolve('../src/build/frontend/js/form/send.js'), 'utf8');

const reply = (ok, status, body) => ({ ok, status, json: async () => { if (body === undefined) { throw new SyntaxError('no json'); } return body; } });

async function run(response, withChallenge = true) {
    const toasts = []; const challenges = [];
    const context = { console: { error() {} }, loadingSpinner() {}, alertToast: c => toasts.push(String(c)),
        FormData: class { append() {} }, fetch: async () => response };
    if (withChallenge) { context.wiRecaptchaChallenge = scope => challenges.push(scope === undefined ? 'last' : scope); }
    vm.runInNewContext(FETCH, context);
    await context.postData('/frontend/form/', new context.FormData());
    return { toasts, challenges };
}

(async () => {
    let r = await run(reply(false, 422, { status: 618 }));
    assert.deepEqual(r.challenges, ['last']); assert.deepEqual(r.toasts, ['618']);
    r = await run(reply(false, 422, { status: 617 }));
    assert.deepEqual(r.challenges, []); assert.deepEqual(r.toasts, ['617']);
    r = await run(reply(false, 500));
    assert.deepEqual(r.toasts, ['802']);
    r = await run(reply(false, 404, { message: 'x' }));
    assert.deepEqual(r.toasts, ['802']);
    r = await run(reply(false, 422, { status: 618 }), false);
    assert.deepEqual(r.toasts, ['618']);
    r = await run(reply(true, 200, { status: 200 }));
    assert.deepEqual(r.toasts, []);
    console.log('PASS: postData legge lo status delle risposte 4xx e accende la sfida sul 618');

    for (const [status, expected] of [[618, 1], [617, 0]]) {
        const challenges = []; const responses = [];
        const form = { id: 'f' };
        const context = { console: { error() {} }, loadingSpinner() {}, FormData: class { constructor() {} },
            API_CLIENT: { post: async () => { throw { success: false, status }; } },
            wiRecaptchaChallenge: s => challenges.push(s) };
        vm.runInNewContext(SEND, context);
        await context.formSubmit(form, '/x', r => responses.push(r));
        assert.equal(challenges.length, expected);
        if (expected) { assert.equal(challenges[0], form); }
        assert.equal(responses.length, 1, 'la callback riceve comunque la risposta');
    }
    console.log('PASS: formSubmit accende la sfida sul form col 618');
})().catch(error => { console.error(error); process.exit(1); });
```

Se `send.js` dichiara altre funzioni che al caricamento toccano globali assenti (es. `document`), aggiungerle come stub vuoti al contesto: il test prova solo `formSubmit`.

- [ ] **Passo 2: il test fallisce**

Run: `node test/fetch-recaptcha.test.cjs`
Expected: AssertionError sul primo caso (toast `802` al posto di `618`, nessuna sfida).

- [ ] **Passo 3: `fetch.js`, ramo delle risposte non ok**

Sostituire il ramo che oggi fa `console.error("Impossibile connettersi o trovare il file!"); throw new Error(802);` con:

```js
return response.json().then((value) => {
    if (value && value.status) { return value; }
    console.error("Impossibile connettersi o trovare il file!");
    throw new Error(802);
}, () => {
    console.error("Impossibile connettersi o trovare il file!");
    throw new Error(802);
});
```

e nel `.catch((error) => { loadingSpinner(); alertToast(error.message); })` finale, prima di `alertToast`:

```js
if (error.message == 618 && typeof wiRecaptchaChallenge === 'function') { wiRecaptchaChallenge(); }
```

La catena `.then(value)` esistente resta: `200` → valore, `401` → `Error(911)`, altro status → `Error(status)`, niente status → `803`.

- [ ] **Passo 4: `send.js`, dopo il try/catch di `formSubmit`**

```js
if (response && response.status == 618 && typeof wiRecaptchaChallenge === 'function') { wiRecaptchaChallenge(form); }
```

(prima di `if (typeof callback === 'function') …`).

- [ ] **Passo 5: CSS e import**

`src/build/frontend/css/components/form/recaptcha.css`:

```css
.wi-recaptcha-badge-off .grecaptcha-badge{visibility:hidden}
.wi-recaptcha-terms{font-size:.75rem;opacity:.7;margin:.5rem 0 0}
```

In `src/export/frontend/head.js`, dopo la riga 63 (`no-floating.css`):

```js
import '/src/build/frontend/css/components/form/recaptcha.css';
```

In `package.json`, in fondo a `scripts.test` (che oggi finisce con `&& node test/places.test.cjs`):

```
 && node test/recaptcha-v3.test.cjs && node test/fetch-recaptcha.test.cjs
```

- [ ] **Passo 6: test e suite**

Run: `node test/fetch-recaptcha.test.cjs && npm test`
Expected: le due righe `PASS:` del nuovo test; `npm test` verde.

- [ ] **Passo 7: commit**

```bash
git add src/build/global/js/fetch.js src/build/frontend/js/form/send.js src/build/frontend/css/components/form/recaptcha.css src/export/frontend/head.js package.json test/fetch-recaptcha.test.cjs
git commit -m "Il 618 del server accende la casella; badge v3 nascondibile

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Compito L3: prova nel browser (Chrome senza testa) e build

**File:**
- Test: `test/recaptcha-v3.browser.test.cjs` (non in `npm test`: vuole Playwright e Chrome)

**Interfacce:**
- Consuma: `setRecaptcha`, `setRecaptchaInput` da L1.

- [ ] **Passo 1: test nel browser**

```js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

const PAGE = 'https://recaptcha.test/';
const SOURCE = fs.readFileSync(require.resolve('../src/build/frontend/js/form/recaptcha.js'), 'utf8');
const HTML = `<!doctype html><meta charset="utf-8">
<form id="contact" method="post" action="${PAGE}send">
  <input name="name" value="Mario">
  <div class="g-recaptcha" data-wi-site-key="site-key" data-wi-score-key="score-key" data-wi-badge="true" data-wi-action="contact"></div>
  <input type="hidden" name="g-recaptcha-token"><input type="hidden" name="g-recaptcha-action"><input type="hidden" name="g-recaptcha-type" value="score">
  <button name="go" value="paga">Invia</button>
</form>
<script>window.check = () => {};</script><script src="${PAGE}recaptcha.js"></script><script>setRecaptcha();</script>`;
const fake = reject => `window.grecaptcha = { enterprise: {
  ready: fn => fn(),
  execute: () => ${reject ? "Promise.reject(new Error('basso'))" : "Promise.resolve('tok-browser')"},
  render: (el, o) => { window.__renders = (window.__renders || []).concat(o.sitekey); return 0; },
  reset() {} } };
window.setRecaptchaInput();`;

async function scenario(browser, reject) {
    const page = await browser.newPage();
    const errors = []; let posted = null;
    page.on('pageerror', error => errors.push(error.message));
    await page.route(`${PAGE}**`, route => {
        const request = route.request();
        if (request.url().endsWith('recaptcha.js')) { return route.fulfill({ contentType: 'text/javascript', body: SOURCE }); }
        if (request.method() === 'POST') { posted = request.postData(); return route.fulfill({ contentType: 'text/plain', body: 'ok' }); }
        return route.fulfill({ contentType: 'text/html', body: HTML });
    });
    await page.route('https://www.google.com/recaptcha/enterprise.js*', route => route.fulfill({ contentType: 'text/javascript', body: fake(reject) }));
    await page.goto(PAGE);
    await page.click('button');
    if (reject) {
        await page.waitForFunction(() => document.querySelector('.g-recaptcha').dataset.wiChallenge === '1');
        assert.deepEqual(await page.evaluate(() => window.__renders), ['site-key']);
        assert.equal(posted, null);
    } else {
        await page.waitForURL(`${PAGE}send`);
        const body = new URLSearchParams(posted);
        assert.equal(body.get('g-recaptcha-token'), 'tok-browser');
        assert.equal(body.get('g-recaptcha-type'), 'score');
        assert.equal(body.get('go'), 'paga', 'il bottone cliccato arriva al server');
    }
    assert.deepEqual(errors, []);
    await page.close();
}

(async () => {
    const browser = await chromium.launch({ channel: process.env.CHROME_CHANNEL || 'chrome', headless: true });
    await scenario(browser, false);
    await scenario(browser, true);
    await browser.close();
    console.log('PASS: submit con token v3 e submitter; punteggio rifiutato → casella');
})().catch(error => { console.error(error); process.exit(1); });
```

- [ ] **Passo 2: eseguire**

Run: `node test/recaptcha-v3.browser.test.cjs` (se Playwright non è nel progetto: `PLAYWRIGHT_MODULE=/percorso/node_modules/playwright node …`; cercare un'installazione esistente con `ls ~/Developer/*/node_modules/playwright` prima di installare qualcosa).
Expected: `PASS: submit con token v3 e submitter; punteggio rifiutato → casella`. Se Playwright non c'è da nessuna parte, annotarlo nel registro come `Ruling:` e passare oltre (i test `vm` coprono la logica).

- [ ] **Passo 3: build e copia nel sito di prova**

```bash
npm run build
rsync -a --delete dist/ /Users/andreamarinoni/Developer/boilerplates/ecommerce-site/assets/lib/wonder-image/dist/
```

Expected: build senza errori. `dist/` non si committa sul ramo (lo fa lo script di rilascio).

- [ ] **Passo 4: commit**

```bash
git add test/recaptcha-v3.browser.test.cjs
git commit -m "Prova nel browser del submit con reCAPTCHA v3

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Compito L4: STOP — merge e rilascio della lib

- [ ] **Passo 1:** fermarsi e chiedere all'utente il via per: (a) PR del ramo `recaptcha-v3` della lib con merge commit su main; (b) `npm run release -- 2.1.2-alpha.27`. Un via per ogni azione. Senza il rilascio i compiti dell'app si fanno lo stesso (i test PHP non usano la lib); solo A8 (pavimento) aspetta il numero di versione pubblicato.

---

## App

I test PHP girano nel worktree dell'app (`cd /Users/andreamarinoni/Developer/worktrees/recaptcha-v3/app`). Non usano la lib: i compiti A1–A7 si fanno anche prima del rilascio di L4.

### Compito A1: `Call::httpCode()`

**File:**
- Modifica: `class/Api/Call.php` (nuovo metodo subito dopo `call()`, che finisce intorno alla riga 165)
- Test: `tests/Http/CallHttpCodeTest.php` (nuovo)

**Interfacce:**
- Produce: `Wonder\Api\Call::httpCode(): int` — codice HTTP dell'ultima chiamata (`curl_getinfo(…, CURLINFO_HTTP_CODE)`), `0` prima di `call()` o senza risposta.

- [ ] **Passo 1: test**

```php
<?php
/** php tests/Http/CallHttpCodeTest.php — il codice HTTP dell'ultima chiamata di Call. */
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../harness.php';

use Wonder\Api\Call;

echo "Call::httpCode\n";

$dir = sys_get_temp_dir() . '/wi-call-http-' . getmypid();
@mkdir($dir, 0777, true);
file_put_contents($dir . '/router.php', "<?php http_response_code(429); echo '{}';");

$probe = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);

$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", $dir . '/router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $dir);

for ($i = 0; $i < 50; $i++) {
    $socket = @fsockopen('127.0.0.1', $port);
    if ($socket) { fclose($socket); break; }
    usleep(100000);
}

check('prima della chiamata il codice è 0', fn () => (new Call("http://127.0.0.1:{$port}/", []))->httpCode() === 0);

check('dopo la chiamata il codice è quello del server (429)', function () use ($port) {
    $call = new Call("http://127.0.0.1:{$port}/", []);
    $call->call();

    return $call->httpCode() === 429;
});

check('server che non risponde: codice 0', function () {
    $call = new Call('http://127.0.0.1:1/', []);
    $call->connectTimeout(1)->timeout(1);
    $call->call();

    return $call->httpCode() === 0;
});

proc_terminate($server);
summary();
```

- [ ] **Passo 2: il test fallisce**

Run: `php tests/Http/CallHttpCodeTest.php`
Expected: tre `✗` con «Call to undefined method Wonder\Api\Call::httpCode()», uscita 1.

- [ ] **Passo 3: il metodo**

Subito dopo la chiusura di `call()`, con l'indentazione del file (8 spazi per i metodi):

```php
        /** Codice HTTP dell'ultima chiamata; 0 prima di call() o se il server non ha risposto. */
        public function httpCode(): int
        {

            return $this->cURL instanceof CurlHandle ? (int) curl_getinfo($this->cURL, CURLINFO_HTTP_CODE) : 0;

        }
```

`CurlHandle` è già importato (riga 5).

- [ ] **Passo 4: il test passa**

Run: `php tests/Http/CallHttpCodeTest.php`
Expected: `3 test, 0 falliti`.

- [ ] **Passo 5: commit**

```bash
git add class/Api/Call.php tests/Http/CallHttpCodeTest.php
git commit -m "Call::httpCode() per leggere il codice HTTP dell'ultima chiamata

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Compito A2: il motore dice se Google ha risposto (`reCAPTCHA::classify`)

**File:**
- Riscrive: `class/Plugin/Google/Security/reCAPTCHA.php`
- Test: `tests/Plugin/Google/RecaptchaEngineTest.php` (nuovo)

**Interfacce:**
- Consuma: `Call::httpCode()` (A1); `Call::contentType()`, `connectTimeout(int)`, `timeout(int)` (esistono, restituiscono `static`); `Call::call()` → `['success','error','errno','result']`.
- Produce:
  - `new reCAPTCHA(?Closure $callFactory = null, ?object $credentials = null)` — `$callFactory(string $url, array $event)` restituisce un oggetto con l'interfaccia di `Call`; `$credentials` ha `gcp_project_id`, `gcp_api_key`, `recaptcha_site_key`.
  - `verify(string $token, string $action, ?string $siteKey = null): object` — `$siteKey` null = chiave a casella.
  - `static classify(array $response, int $httpCode): object` con `valid: bool`, `score: ?float`, `action: ?string`, `available: bool`, `httpCode: int`, `result: array`. `available` è vero solo con HTTP 200 e JSON non vuoto, oppure HTTP 400.

- [ ] **Passo 1: test**

```php
<?php
/** php tests/Plugin/Google/RecaptchaEngineTest.php — il motore reCAPTCHA classifica la risposta di Google. */
declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/../../harness.php';

use Wonder\Plugin\Google\Security\reCAPTCHA;

echo "reCAPTCHA engine\n";

/** Finta Call: risposta e codice HTTP fissati, ricorda l'evento spedito. */
final class FakeCall
{
    public static array $events = [];

    public function __construct(private array $reply, private int $code, array $event)
    {
        self::$events[] = $event;
    }

    public function contentType($type): static { return $this; }
    public function connectTimeout(int $seconds): static { return $this; }
    public function timeout(int $seconds): static { return $this; }
    public function call(): array { return $this->reply; }
    public function httpCode(): int { return $this->code; }
}

function engine(array $reply, int $code): reCAPTCHA
{
    $credentials = (object) ['gcp_project_id' => 'p', 'gcp_api_key' => 'k', 'recaptcha_site_key' => 'site-checkbox'];

    return new reCAPTCHA(fn (string $url, array $event) => new FakeCall($reply, $code, $event), $credentials);
}

function ok(array $json): array
{
    return ['success' => true, 'error' => null, 'errno' => 0, 'result' => json_encode($json)];
}

check('200 valido: valid, punteggio, azione, available', function () {
    $r = engine(ok(['tokenProperties' => ['valid' => true, 'action' => 'contact'], 'riskAnalysis' => ['score' => 0.9]]), 200)->verify('tok', 'contact');

    return $r->valid === true && $r->score === 0.9 && $r->action === 'contact' && $r->available === true && $r->httpCode === 200;
});

check('200 con token non valido: valid falso ma Google ha risposto', function () {
    $r = engine(ok(['tokenProperties' => ['valid' => false, 'invalidReason' => 'MALFORMED']]), 200)->verify('tok', 'contact');

    return $r->valid === false && $r->available === true && $r->score === null && $r->action === null;
});

check('400: Google ha risposto, non valido', function () {
    $r = engine(ok(['error' => ['code' => 400]]), 400)->verify('tok', 'contact');

    return $r->valid === false && $r->available === true;
});

check('429 e 503: non disponibile', function () {
    $a = engine(ok(['error' => ['code' => 429]]), 429)->verify('tok', 'contact');
    $b = engine(['success' => true, 'error' => null, 'errno' => 0, 'result' => 'Service Unavailable'], 503)->verify('tok', 'contact');

    return $a->available === false && $a->valid === false && $b->available === false;
});

check('errore di rete (success falso, codice 0): non disponibile', function () {
    $r = engine(['success' => false, 'error' => 'timeout', 'errno' => 28, 'result' => null], 0)->verify('tok', 'contact');

    return $r->available === false && $r->valid === false && $r->httpCode === 0;
});

check('200 senza JSON: non disponibile', function () {
    $r = engine(['success' => true, 'error' => null, 'errno' => 0, 'result' => '<html>'], 200)->verify('tok', 'contact');

    return $r->available === false;
});

check('valid come stringa "true" (forma storica) resta valido', function () {
    return reCAPTCHA::classify(['tokenProperties' => ['valid' => 'true']], 200)->valid === true;
});

check('azione vuota di Google → null', function () {
    return reCAPTCHA::classify(['tokenProperties' => ['valid' => true, 'action' => ' ']], 200)->action === null;
});

check('la chiave passata va nell\'evento; senza, la chiave a casella', function () {
    FakeCall::$events = [];
    engine(ok(['tokenProperties' => ['valid' => true]]), 200)->verify('tok', 'contact', 'site-score');
    engine(ok(['tokenProperties' => ['valid' => true]]), 200)->verify('tok', 'submit');

    return FakeCall::$events[0]['event']['siteKey'] === 'site-score'
        && FakeCall::$events[0]['event']['expectedAction'] === 'contact'
        && FakeCall::$events[1]['event']['siteKey'] === 'site-checkbox';
});

summary();
```

- [ ] **Passo 2: il test fallisce**

Run: `php tests/Plugin/Google/RecaptchaEngineTest.php`
Expected: `✗` su tutti i casi (il costruttore ignora la fabbrica e legge le credenziali vere; `classify` non esiste), uscita 1.

- [ ] **Passo 3: il motore**

Contenuto completo di `class/Plugin/Google/Security/reCAPTCHA.php`:

```php
<?php

    namespace Wonder\Plugin\Google\Security;

    use Closure;
    use Wonder\Api\Call;
    use Wonder\Plugin\Google\Credentials;

    /**
     * Chiamata a reCAPTCHA Enterprise (assessments). Oltre a `valid` dice se
     * Google ha risposto davvero (`available`): la guardia salta la verifica
     * quando non ha risposto (rete, 429, 5xx, 4xx diversi da 400).
     */
    class reCAPTCHA {

        protected static $projectId;
        protected static $apiKey;
        public static $siteKey;
        protected static $action;

        private Closure $callFactory;

        public function __construct(?Closure $callFactory = null, ?object $credentials = null) {

            $credentials ??= Credentials::get();

            self::$projectId = $credentials->gcp_project_id;
            self::$apiKey = $credentials->gcp_api_key;
            self::$siteKey = $credentials->recaptcha_site_key;

            $this->callFactory = $callFactory ?? fn (string $url, array $event) => new Call($url, $event);

        }

        /** `$siteKey` null = chiave a casella (v2). */
        public function verify(string $token, string $action, ?string $siteKey = null): object {

            $event = [
                'event' => [
                    'token' => $token,
                    'expectedAction' => $action,
                    'siteKey' => $siteKey ?? self::$siteKey
                ]
            ];

            $call = ($this->callFactory)('https://recaptchaenterprise.googleapis.com/v1/projects/'.self::$projectId.'/assessments?key='.self::$apiKey, $event);
            $call->contentType('application/json')->connectTimeout(3)->timeout(5);

            $response = $call->call();
            $decoded = ($response['success'] ?? false) ? json_decode((string) $response['result'], true) : null;

            return self::classify(is_array($decoded) ? $decoded : [], $call->httpCode());

        }

        /** Risposta di Google → `valid`, `score`, `action`, `available`, `httpCode`, `result`. */
        public static function classify(array $response, int $httpCode): object {

            $properties = (array) ($response['tokenProperties'] ?? []);
            $score = $response['riskAnalysis']['score'] ?? null;
            $action = trim((string) ($properties['action'] ?? ''));

            return (object) [
                'valid' => in_array($properties['valid'] ?? false, [true, 'true'], true),
                'score' => is_numeric($score) ? (float) $score : null,
                'action' => $action !== '' ? $action : null,
                'available' => ($httpCode === 200 && $response !== []) || $httpCode === 400,
                'httpCode' => $httpCode,
                'result' => $response,
            ];

        }

    }
```

Note: il vecchio `$CALL->result()` diventa `call()` + `httpCode()`; il doppio `;;` del vecchio costruttore sparisce. Nessun altro chiama il motore oltre a `RecaptchaGuard` (verificato con grep).

- [ ] **Passo 4: il test passa**

Run: `php tests/Plugin/Google/RecaptchaEngineTest.php`
Expected: `9 test, 0 falliti`.

- [ ] **Passo 5: commit**

```bash
git add class/Plugin/Google/Security/reCAPTCHA.php tests/Plugin/Google/RecaptchaEngineTest.php
git commit -m "Motore reCAPTCHA: chiave per chiamata, punteggio, azione e disponibilità di Google

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Compito A3: la guardia con v3, la sfida 618 e il fail-open

**File:**
- Crea: `class/App/Security/RecaptchaChallenge.php`
- Modifica: `class/App/Security/RecaptchaGuard.php`; `resources/lang/{it,en,de,es,fr}/notifications.json` (voce `618` subito dopo `617`)
- Test: `tests/Security/RecaptchaGuardTest.php` (esteso)

**Interfacce:**
- Consuma: `reCAPTCHA::verify(string $token, string $action, ?string $siteKey): object` con `valid`, `score`, `action`, `available`, `httpCode`, `result` (A2). Un engine iniettato che non dà `available`/`httpCode` vale come `available=true`, `httpCode=200`.
- Produce:
  - `final class Wonder\App\Security\RecaptchaChallenge extends RuntimeException`, `public const CHALLENGE_CODE = 618`, `__construct(?Throwable $previous = null)`, messaggio `__t('notifications.618.text')` o «Conferma di non essere un robot».
  - `RecaptchaGuard::minScore(float $score): self` (0.5 di base).
  - `RecaptchaGuard::withSiteKeys(string $checkbox, string $score): self` (per i test; di base le chiavi vengono da `GoogleCredentials::get()`).
  - `RecaptchaGuard::configured(): bool` (statico) — almeno una delle due chiavi c'è.
  - `RecaptchaGuard::isFailure(Throwable $error): bool` (statico) — `RuntimeException` con codice 617 o 618.
  - `RecaptchaGuard::challenged(string $action): bool` (statico) — legge e cancella `$_SESSION['wi_recaptcha_challenge'][$action]`; `false` senza sessione attiva.
  - `verify()` lancia `RuntimeException(617)` o `RecaptchaChallenge(618)`; `passes()` restituisce `false` per tutti e due.

Ordine dei controlli in `verify()` (spec R3–R7):
1. token o azione assenti → 617 `missing`;
2. tipo (`g-recaptcha-type`, vuoto = `checkbox`) senza chiave configurata → 617 `invalid` (engine non chiamato);
3. azione del client diversa da quella attesa (solo se l'attesa non è vuota) → 617 `action_mismatch` (engine non chiamato);
4. engine: eccezione → salta (`engine_error`, ERROR); `available === false` → salta (`engine_unavailable`, WARNING per HTTP 0, 200, 429 e ≥ 500, ERROR per gli altri 4xx);
5. `valid` falso → 617 `invalid` (dal `google_result` del log si toglie `event`);
6. azione di Google non vuota e diversa → 617 `action_mismatch` (`source: google`);
7. solo tipo `score`: punteggio null o sotto `minScore` → con la chiave a casella segno in sessione + 618, senza → 617 (`low_score`);
8. successo → cancella il segno della sfida per l'azione.

- [ ] **Passo 1: test, la sessione e il nuovo engine finto**

In `tests/Security/RecaptchaGuardTest.php`:

a) Dopo `use Wonder\App\Security\RecaptchaGuard;` aggiungere `use Wonder\App\Security\RecaptchaChallenge;`.

b) Subito dopo `chdir($tmp);`:

```php
// In CLI session_start() dopo un output fallisce: tutto l'output va nel buffer.
ob_start();
ini_set('session.use_cookies', '0');
ini_set('session.cache_limiter', '');
session_save_path($tmp);
session_start();
```

c) Sostituire la classe `FakeEngine` con:

```php
/** Engine fake: risultato configurabile (valid, punteggio, azione, disponibilità) o eccezione. */
final class FakeEngine
{
    public int $calls = 0;
    public ?string $lastToken = null;
    public ?string $lastAction = null;
    public ?string $lastSiteKey = null;

    public function __construct(
        private bool $valid = true,
        private bool $throws = false,
        private ?float $score = null,
        private ?string $action = null,
        private bool $available = true,
        private int $httpCode = 200,
    ) {}

    public function verify(string $token, string $action, ?string $siteKey = null): object
    {
        $this->calls++;
        $this->lastToken = $token;
        $this->lastAction = $action;
        $this->lastSiteKey = $siteKey;

        if ($this->throws) {
            throw new RuntimeException('engine boom');
        }

        return (object) [
            'valid' => $this->valid,
            'score' => $this->score,
            'action' => $this->action,
            'available' => $this->available,
            'httpCode' => $this->httpCode,
            'result' => ['tokenProperties' => ['valid' => $this->valid], 'event' => ['token' => $token]],
        ];
    }
}
```

d) Dopo `assert617` aggiungere:

```php
/** Asserisce che $fn lanci RecaptchaChallenge (618). */
function assert618(callable $fn): void
{
    try {
        $fn();
    } catch (RecaptchaChallenge $e) {
        assertTrue($e->getCode() === RecaptchaChallenge::CHALLENGE_CODE, "codice atteso 618, ottenuto {$e->getCode()}");
        return;
    }

    throw new RuntimeException('attesa RecaptchaChallenge(618), nessuna lanciata');
}

/** Guardia con la sola chiave a casella (il comportamento di prima). */
function guard(string $action = 'contact'): RecaptchaGuard
{
    return RecaptchaGuard::for($action)->withSiteKeys('site-checkbox', '');
}

$score = ['g-recaptcha-token' => 'tok', 'g-recaptcha-action' => 'contact', 'g-recaptcha-type' => 'score'];
$logFile = $tmp . '/storage/logs/error/recaptcha.log';
```

e) Nei casi 1–8 sostituire ogni `RecaptchaGuard::for('contact')` con `guard()` e `RecaptchaGuard::for('')` con `guard('')`. Nel caso 1 aggiungere in fondo:

```php
    assertTrue($engine->lastSiteKey === 'site-checkbox', 'senza tipo si usa la chiave a casella');
```

f) Il caso 5 diventa (fail-open, spec R7):

```php
check('5. engine lancia eccezione → la verifica si salta', function () use ($valid) {
    $engine = new FakeEngine(throws: true);
    guard()->withRequest($valid)->withEngine($engine)->verify();
    assertTrue($engine->calls === 1, 'engine chiamato');
});
```

g) Prima della riga finale `echo "\n{$tests} test, …` aggiungere i casi nuovi:

```php
// --- v3, sfida 618, fail-open ---

check('12. tipo score → chiave v3; punteggio alto passa', function () use ($score) {
    $engine = new FakeEngine(score: 0.9, action: 'contact');
    RecaptchaGuard::for('contact')->withSiteKeys('site-checkbox', 'site-score')->withRequest($score)->withEngine($engine)->verify();
    assertTrue($engine->lastSiteKey === 'site-score', 'attesa la chiave v3');
});

check('13. punteggio basso con le due chiavi → 618 e segno in sessione (letto una volta)', function () use ($score) {
    assert618(fn () => RecaptchaGuard::for('contact')->withSiteKeys('site-checkbox', 'site-score')->withRequest($score)->withEngine(new FakeEngine(score: 0.1))->verify());
    assertTrue(RecaptchaGuard::challenged('contact') === true, 'segno atteso');
    assertTrue(RecaptchaGuard::challenged('contact') === false, 'il segno si consuma alla lettura');
});

check('14. punteggio basso con la sola chiave v3 → 617, nessun segno', function () use ($score) {
    assert617(fn () => RecaptchaGuard::for('contact')->withSiteKeys('', 'site-score')->withRequest($score)->withEngine(new FakeEngine(score: 0.1))->verify());
    assertTrue(RecaptchaGuard::challenged('contact') === false, 'nessun segno senza chiave a casella');
});

check('15. punteggio assente con tipo score → come basso (618)', function () use ($score) {
    assert618(fn () => RecaptchaGuard::for('contact')->withSiteKeys('site-checkbox', 'site-score')->withRequest($score)->withEngine(new FakeEngine(score: null))->verify());
    RecaptchaGuard::challenged('contact');
});

check('16. tipo checkbox con la sola chiave v3 → 617, engine non chiamato', function () use ($valid) {
    $engine = new FakeEngine();
    assert617(fn () => RecaptchaGuard::for('contact')->withSiteKeys('', 'site-score')->withRequest($valid + ['g-recaptcha-type' => 'checkbox'])->withEngine($engine)->verify());
    assertTrue($engine->calls === 0, 'engine non chiamato');
});

check('17. tipo sconosciuto → 617, engine non chiamato', function () use ($valid) {
    $engine = new FakeEngine();
    assert617(fn () => RecaptchaGuard::for('contact')->withSiteKeys('site-checkbox', 'site-score')->withRequest($valid + ['g-recaptcha-type' => 'foo'])->withEngine($engine)->verify());
    assertTrue($engine->calls === 0, 'engine non chiamato');
});

check('18. Google non disponibile (429, 500, 0, 401) → la verifica si salta', function () use ($valid) {
    foreach ([429, 500, 0, 401] as $code) {
        guard()->withRequest($valid)->withEngine(new FakeEngine(valid: false, available: false, httpCode: $code))->verify();
    }
});

check('19. azione di Google diversa → 617; vuota → passa', function () use ($valid) {
    assert617(fn () => guard()->withRequest($valid)->withEngine(new FakeEngine(action: 'login'))->verify());
    guard()->withRequest($valid)->withEngine(new FakeEngine(action: null))->verify();
});

check('20. HTTP 400 (Google ha risposto, non valido) → 617', function () use ($valid) {
    assert617(fn () => guard()->withRequest($valid)->withEngine(new FakeEngine(valid: false, available: true, httpCode: 400))->verify());
});

check('21. token assente → 617 anche se l\'engine lancerebbe (il fail-open viene dopo)', function () {
    assert617(fn () => guard()->withRequest(['g-recaptcha-action' => 'contact'])->withEngine(new FakeEngine(throws: true))->verify());
});

check('22. minScore(0.9) con 0.7 → 618', function () use ($score) {
    assert618(fn () => RecaptchaGuard::for('contact')->withSiteKeys('site-checkbox', 'site-score')->minScore(0.9)->withRequest($score)->withEngine(new FakeEngine(score: 0.7))->verify());
    RecaptchaGuard::challenged('contact');
});

check('23. challenged() senza sessione → false', function () {
    session_write_close();
    $result = RecaptchaGuard::challenged('contact');
    session_start();
    assertTrue($result === false, 'atteso false');
});

check('24. il successo cancella il segno della sfida', function () use ($score, $valid) {
    assert618(fn () => RecaptchaGuard::for('contact')->withSiteKeys('site-checkbox', 'site-score')->withRequest($score)->withEngine(new FakeEngine(score: 0.1))->verify());
    RecaptchaGuard::for('contact')->withSiteKeys('site-checkbox', 'site-score')->withRequest($valid + ['g-recaptcha-type' => 'checkbox'])->withEngine(new FakeEngine())->verify();
    assertTrue(RecaptchaGuard::challenged('contact') === false, 'segno cancellato dal successo');
});

check('25. isFailure: 617 e 618 sì, altri no', function () {
    assertTrue(RecaptchaGuard::isFailure(new RuntimeException('x', 617)), '617');
    assertTrue(RecaptchaGuard::isFailure(new RecaptchaChallenge()), '618');
    assertTrue(!RecaptchaGuard::isFailure(new RuntimeException('x', 500)), '500');
    assertTrue(!RecaptchaGuard::isFailure(new LogicException('x', 617)), 'non RuntimeException');
});

check('26. passes() → false anche sul 618', function () use ($score) {
    $ok = RecaptchaGuard::for('contact')->withSiteKeys('site-checkbox', 'site-score')->withRequest($score)->withEngine(new FakeEngine(score: 0.1))->passes();
    RecaptchaGuard::challenged('contact');
    assertTrue($ok === false, 'atteso false');
});

check('27. il log non contiene il token', function () use ($logFile) {
    @unlink($logFile);
    $request = ['g-recaptcha-token' => 'tok-segreto-123', 'g-recaptcha-action' => 'contact'];
    assert617(fn () => guard()->withRequest($request)->withEngine(new FakeEngine(valid: false))->verify());
    $log = (string) @file_get_contents($logFile);
    assertTrue($log !== '' && str_contains($log, 'invalid'), 'riga di log attesa');
    assertTrue(!str_contains($log, 'tok-segreto-123'), 'il token non deve finire nel log');
});

check('28. salto loggato: engine_unavailable, ERROR per 401, WARNING per 429', function () use ($valid, $logFile) {
    @unlink($logFile);
    guard()->withRequest($valid)->withEngine(new FakeEngine(available: false, httpCode: 401))->verify();
    $log = (string) @file_get_contents($logFile);
    assertTrue(str_contains($log, 'engine_unavailable') && str_contains($log, 'ERROR'), '401 → ERROR');

    @unlink($logFile);
    guard()->withRequest($valid)->withEngine(new FakeEngine(available: false, httpCode: 429))->verify();
    $log = (string) @file_get_contents($logFile);
    assertTrue(str_contains($log, 'engine_unavailable') && str_contains($log, 'WARNING') && !str_contains($log, 'ERROR'), '429 → WARNING');
});

check('29. configured() non lancia senza credenziali', function () {
    assertTrue(is_bool(RecaptchaGuard::configured()), 'atteso un bool');
});

check('30. 618 nelle notifiche delle cinque lingue', function () {
    foreach (['it', 'en', 'de', 'es', 'fr'] as $lang) {
        $data = json_decode((string) file_get_contents(__DIR__ . "/../../resources/lang/{$lang}/notifications.json"), true);
        assertTrue(trim((string) ($data['618']['text'] ?? '')) !== '', "618 mancante in {$lang}");
    }
    $it = json_decode((string) file_get_contents(__DIR__ . '/../../resources/lang/it/notifications.json'), true);
    assertTrue($it['618']['text'] === 'Conferma di non essere un robot', 'testo italiano della spec R4');
});
```

h) In fondo, al posto delle due righe finali:

```php
echo "\n{$tests} test, {$failures} fallimenti\n";
ob_end_flush();
exit($failures === 0 ? 0 : 1);
```

- [ ] **Passo 2: il test fallisce**

Run: `php tests/Security/RecaptchaGuardTest.php`
Expected: errore fatale «Class "Wonder\App\Security\RecaptchaChallenge" not found» o «Call to undefined method …withSiteKeys()» sui casi; uscita diversa da 0.

- [ ] **Passo 3: `RecaptchaChallenge`**

`class/App/Security/RecaptchaChallenge.php`:

```php
<?php

namespace Wonder\App\Security;

use RuntimeException;
use Throwable;

/** Punteggio v3 basso con la chiave a casella configurata: il form deve mostrare la casella. */
final class RecaptchaChallenge extends RuntimeException
{
    public const CHALLENGE_CODE = 618;

    public function __construct(?Throwable $previous = null)
    {
        $message = function_exists('__t') ? trim((string) __t('notifications.'.self::CHALLENGE_CODE.'.text')) : '';

        parent::__construct($message !== '' ? $message : 'Conferma di non essere un robot', self::CHALLENGE_CODE, $previous);
    }
}
```

- [ ] **Passo 4: la guardia**

In `class/App/Security/RecaptchaGuard.php`:

a) Nel docblock della classe, dopo «…la verifica è testabile senza HTTP reale.» aggiungere:

```php
 *
 * v3 (punteggio): il campo `g-recaptcha-type` sceglie la chiave (`score` o
 * `checkbox`, assente = `checkbox`). Punteggio sotto {@see minScore()} con la
 * chiave a casella configurata → segno in sessione e {@see RecaptchaChallenge}
 * (618: il form mostra la casella); senza chiave a casella → 617. Se l'engine
 * lancia o Google non risponde (rete, 429, 5xx, 4xx diversi da 400) la verifica
 * si salta (fail-open) con una riga di log.
```

e il commento di `withEngine` diventa «Engine low-level alternativo (`verify($token, $action, $siteKey): object`). Default `new reCAPTCHA()`.».

b) Dopo `private const ACTION_KEY = 'g-recaptcha-action';`:

```php
    private const TYPE_KEY = 'g-recaptcha-type';

    /** Sessione: azioni per cui il form deve mostrare la casella. */
    private const CHALLENGE_SESSION = 'wi_recaptcha_challenge';
```

c) Dopo la proprietà `$logFile`:

```php
    /** Punteggio v3 minimo. */
    private float $minScore = 0.5;

    /** Chiavi `checkbox`/`score`; null = lazy dalle credenziali. */
    private ?array $siteKeys = null;
```

d) Dopo `withLogFile()`:

```php
    /** Punteggio v3 minimo (default 0.5). */
    public function minScore(float $score): self
    {
        $this->minScore = $score;

        return $this;
    }

    /** Chiavi del sito (default dalle credenziali). Iniettabili per i test. */
    public function withSiteKeys(string $checkbox, string $score): self
    {
        $this->siteKeys = ['checkbox' => trim($checkbox), 'score' => trim($score)];

        return $this;
    }

    /** Almeno una chiave reCAPTCHA configurata. */
    public static function configured(): bool
    {
        $keys = self::credentialKeys();

        return $keys['checkbox'] !== '' || $keys['score'] !== '';
    }

    /** L'errore è un fallimento della verifica (617) o la richiesta della casella (618). */
    public static function isFailure(Throwable $error): bool
    {
        return $error instanceof RuntimeException
            && in_array($error->getCode(), [self::ERROR_CODE, RecaptchaChallenge::CHALLENGE_CODE], true);
    }

    /** Il form di `$action` deve mostrare la casella? Legge e cancella il segno; false senza sessione. */
    public static function challenged(string $action): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        $action = trim($action);
        $open = ($_SESSION[self::CHALLENGE_SESSION][$action] ?? false) === true;
        unset($_SESSION[self::CHALLENGE_SESSION][$action]);

        return $open;
    }
```

e) `verify()` e `passes()` diventano:

```php
    /**
     * Esegue la verifica. Non ritorna nulla in caso di successo (o di salto per
     * Google non disponibile); lancia `RuntimeException(617)` o
     * `RecaptchaChallenge(618)`.
     */
    public function verify(): void
    {
        $request = $this->request ?? $_POST;

        $token = trim((string) ($request[self::TOKEN_KEY] ?? ''));
        $action = trim((string) ($request[self::ACTION_KEY] ?? ''));
        $type = trim((string) ($request[self::TYPE_KEY] ?? ''));
        $type = $type === '' ? 'checkbox' : $type;

        if ($token === '' || $action === '') {
            $this->fail('missing', [
                'has_token' => $token !== '',
                'has_action' => $action !== '',
                'type' => $type,
            ]);
        }

        $keys = $this->siteKeys();

        if (!isset($keys[$type]) || $keys[$type] === '') {
            $this->fail('invalid', ['type' => $type, 'type_configured' => false]);
        }

        if ($this->expectedAction !== '' && $action !== $this->expectedAction) {
            $this->fail('action_mismatch', [
                'expected' => $this->expectedAction,
                'received' => $action,
                'type' => $type,
            ]);
        }

        $expected = $this->expectedAction !== '' ? $this->expectedAction : $action;

        try {
            $engine = $this->engine ?? new reCAPTCHA();
            $result = $engine->verify($token, $expected, $keys[$type]);
        } catch (Throwable $exception) {
            $this->skip('engine_error', 'ERROR', ['expected' => $expected, 'type' => $type], $exception);

            return;
        }

        $httpCode = (int) ($result->httpCode ?? 200);
        $context = ['expected' => $expected, 'type' => $type, 'http_code' => $httpCode];

        if (($result->available ?? true) === false) {
            $level = in_array($httpCode, [0, 200, 429], true) || $httpCode >= 500 ? 'WARNING' : 'ERROR';
            $this->skip('engine_unavailable', $level, $context);

            return;
        }

        if (empty($result->valid)) {
            $google = (array) ($result->result ?? []);
            unset($google['event']);
            $this->fail('invalid', $context + ['google_result' => $google]);
        }

        $googleAction = trim((string) ($result->action ?? ''));

        if ($googleAction !== '' && $googleAction !== $expected) {
            $this->fail('action_mismatch', $context + ['received' => $googleAction, 'source' => 'google']);
        }

        if ($type === 'score') {
            $score = isset($result->score) && is_numeric($result->score) ? (float) $result->score : null;

            if ($score === null || $score < $this->minScore) {
                $context['score'] = $score;

                if ($keys['checkbox'] !== '') {
                    $this->markChallenge($expected);
                    $this->fail('low_score', $context + ['challenge' => true], null, new RecaptchaChallenge());
                }

                $this->fail('low_score', $context);
            }
        }

        $this->clearChallenge($expected);
    }

    /**
     * Variante non-throwing: `false` invece del 617 o del 618.
     * Qualsiasi altra eccezione (configurazione, ecc.) viene propagata.
     */
    public function passes(): bool
    {
        try {
            $this->verify();

            return true;
        } catch (RuntimeException $exception) {
            if (!self::isFailure($exception)) {
                throw $exception;
            }

            return false;
        }
    }
```

f) `fail()` diventa (sempre WARNING: l'`engine_error` ora passa da `skip()`):

```php
    private function fail(string $reason, array $context = [], ?Throwable $cause = null, ?RuntimeException $error = null): void
    {
        $exception = $error ?? new RuntimeException($this->userMessage(), self::ERROR_CODE, $cause);

        Logger::log(
            $cause ?? $exception,
            $this->service,
            $this->logAction,
            'WARNING',
            $this->logFile,
            array_merge($this->baseContext(), ['reason' => $reason], $context),
            false
        );

        throw $exception;
    }
```

(il docblock sopra resta; aggiungere `@param RuntimeException|null $error eccezione da lanciare al posto del 617`).

g) Dopo `fail()`:

```php
    /** Verifica saltata (fail-open): solo log, nessuna eccezione. */
    private function skip(string $reason, string $level, array $context, ?Throwable $cause = null): void
    {
        Logger::log(
            $cause ?? new RuntimeException('reCAPTCHA verification skipped'),
            $this->service,
            $this->logAction,
            $level,
            $this->logFile,
            array_merge($this->baseContext(), ['reason' => $reason, 'skipped' => true], $context),
            false
        );
    }

    private function markChallenge(string $action): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[self::CHALLENGE_SESSION][$action] = true;
        }
    }

    private function clearChallenge(string $action): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION[self::CHALLENGE_SESSION][$action]);
        }
    }

    /** @return array{checkbox: string, score: string} */
    private function siteKeys(): array
    {
        return $this->siteKeys ??= self::credentialKeys();
    }

    /** @return array{checkbox: string, score: string} */
    private static function credentialKeys(): array
    {
        try {
            $credentials = GoogleCredentials::get();

            return [
                'checkbox' => trim((string) ($credentials->recaptcha_site_key ?? '')),
                'score' => trim((string) ($credentials->recaptcha_score_site_key ?? '')),
            ];
        } catch (Throwable) {
            return ['checkbox' => '', 'score' => ''];
        }
    }
```

h) In `baseContext()`, dopo `has_site_key`:

```php
            $context['has_score_site_key'] = trim((string) ($credentials->recaptcha_score_site_key ?? '')) !== '';
```

(`recaptcha_score_site_key` arriva in A4; fino ad allora vale `false`.)

- [ ] **Passo 5: la voce 618 nelle notifiche**

Subito dopo il blocco `"617": {…},` di ogni `resources/lang/<lingua>/notifications.json`:

| lingua | `title` | `text` |
|---|---|---|
| it | Verifica richiesta | Conferma di non essere un robot |
| en | Verification required | Confirm you are not a robot |
| de | Bestätigung erforderlich | Bestätige, dass du kein Roboter bist |
| es | Verificación necesaria | Confirma que no eres un robot |
| fr | Vérification requise | Confirmez que vous n'êtes pas un robot |

con `"type": "warning"`, per esempio in italiano:

```json
    "618": {
        "type": "warning",
        "title": "Verifica richiesta",
        "text": "Conferma di non essere un robot"
    },
```

Controllo: `for l in it en de es fr; do php -r "json_decode(file_get_contents('resources/lang/$l/notifications.json'), flags: JSON_THROW_ON_ERROR);" || echo "JSON rotto: $l"; done` non stampa nulla.

- [ ] **Passo 6: il test passa**

Run: `php tests/Security/RecaptchaGuardTest.php`
Expected: `30 test, 0 fallimenti`, uscita 0.

- [ ] **Passo 7: commit**

```bash
git add class/App/Security/RecaptchaChallenge.php class/App/Security/RecaptchaGuard.php resources/lang/it/notifications.json resources/lang/en/notifications.json resources/lang/de/notifications.json resources/lang/es/notifications.json resources/lang/fr/notifications.json tests/Security/RecaptchaGuardTest.php
git commit -m "Guardia reCAPTCHA: v3 col punteggio, sfida 618 con la casella e fail-open se Google non risponde

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Compito A4: chiave v3 e badge nella configurazione

**File:**
- Modifica: `class/Plugin/Google/Credentials.php`, `class/App/Credentials.php`, `class/App/Models/Config/Security.php`, `class/App/Resources/Config/SecurityResource.php`, `docs/app/piattaforma/dev-shared.md`
- Test: `tests/Security/RecaptchaConfigTest.php` (nuovo)

**Interfacce:**
- Produce: `Credentials::api()->g_recaptcha_score_site_key` (stringa, `''` di base, env `G_RECAPTCHA_SCORE_SITE_KEY`), `Credentials::api()->g_recaptcha_badge` (`'true'`/`'false'`, `'true'` di base, env `G_RECAPTCHA_BADGE`); `GoogleCredentials::get()->recaptcha_score_site_key` e `->recaptcha_badge` (letti da A3 e A5).

- [ ] **Passo 1: test**

```php
<?php
/** php tests/Security/RecaptchaConfigTest.php — chiave v3 e badge nella configurazione. */
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../harness.php';

echo "RecaptchaConfig\n";

$root = dirname(__DIR__, 2);
$read = fn (string $path): string => (string) file_get_contents($root.'/'.$path);

check('Credentials dell\'app: env e colonne per chiave v3 e badge, badge visibile di base', function () use ($read) {
    $source = $read('class/App/Credentials.php');

    return str_contains($source, "self::envOrRow('G_RECAPTCHA_SCORE_SITE_KEY'")
        && str_contains($source, "'g_recaptcha_score_site_key'")
        && str_contains($source, "self::envOrRow('G_RECAPTCHA_BADGE'")
        && str_contains($source, "'g_recaptcha_badge' => 'true'");
});

check('Credentials di Google: recaptcha_score_site_key e recaptcha_badge', function () use ($read) {
    $source = $read('class/Plugin/Google/Credentials.php');

    return str_contains($source, '->recaptcha_score_site_key = $default->g_recaptcha_score_site_key')
        && str_contains($source, '->recaptcha_badge = $default->g_recaptcha_badge');
});

check('modello: colonne e campi, badge \'true\' di base', function () use ($read) {
    $source = $read('class/App/Models/Config/Security.php');

    return str_contains($source, "Column::key('g_recaptcha_score_site_key')")
        && str_contains($source, "Column::key('g_recaptcha_badge')->default('true')")
        && str_contains($source, "Field::key('g_recaptcha_score_site_key')")
        && str_contains($source, "Field::key('g_recaptcha_badge')");
});

check('backend: etichette, select del badge e campi nel riquadro reCAPTCHA', function () use ($read) {
    $source = $read('class/App/Resources/Config/SecurityResource.php');

    return str_contains($source, "'Chiave Sito v2 (casella)'")
        && str_contains($source, "'Chiave Sito v3 (punteggio)'")
        && str_contains($source, "'Badge v3'")
        && str_contains($source, "FormField::key('g_recaptcha_badge')->select(['true' => 'Visibile', 'false' => 'Nascosto'])->value('true')->required()")
        && str_contains($source, "static::getInput('g_recaptcha_score_site_key')")
        && str_contains($source, "static::getInput('g_recaptcha_badge')");
});

check('dev-shared: la riga della chiave v3', fn () => str_contains($read('docs/app/piattaforma/dev-shared.md'), '`G_RECAPTCHA_SCORE_SITE_KEY`'));

summary();
```

- [ ] **Passo 2: il test fallisce**

Run: `php tests/Security/RecaptchaConfigTest.php`
Expected: `5 test, 5 falliti`.

- [ ] **Passo 3: credenziali**

`class/App/Credentials.php`, subito dopo la riga `self::$API->g_recaptcha_secret_key … = self::envOrRow('G_RECAPTCHA_SECRET_KEY', …);` (stesso allineamento delle righe vicine):

```php
            self::$API->g_recaptcha_score_site_key   = self::envOrRow('G_RECAPTCHA_SCORE_SITE_KEY',       $row, 'g_recaptcha_score_site_key',       self::$API->g_recaptcha_score_site_key);
            self::$API->g_recaptcha_badge            = self::envOrRow('G_RECAPTCHA_BADGE',                $row, 'g_recaptcha_badge',                self::$API->g_recaptcha_badge);
```

e nei valori di base, subito dopo `'g_recaptcha_secret_key' => '',`:

```php
            'g_recaptcha_score_site_key' => '',
            'g_recaptcha_badge' => 'true',
```

`class/Plugin/Google/Credentials.php`, subito dopo `$RETURN->recaptcha_site_key = $default->g_recaptcha_site_key;`:

```php
            $RETURN->recaptcha_score_site_key = $default->g_recaptcha_score_site_key;
            $RETURN->recaptcha_badge = $default->g_recaptcha_badge;
```

- [ ] **Passo 4: modello e backend**

`class/App/Models/Config/Security.php`: dopo `Column::key('g_recaptcha_secret_key'),`

```php
            Column::key('g_recaptcha_score_site_key'),
            Column::key('g_recaptcha_badge')->default('true'),
```

e dopo `Field::key('g_recaptcha_secret_key')->text(),`

```php
            Field::key('g_recaptcha_score_site_key')->text(),
            Field::key('g_recaptcha_badge')->text(),
```

`class/App/Resources/Config/SecurityResource.php`:

a) etichette (righe 43–44) diventano:

```php
            'g_recaptcha_site_key' => 'Chiave Sito v2 (casella)',
            'g_recaptcha_score_site_key' => 'Chiave Sito v3 (punteggio)',
            'g_recaptcha_secret_key' => 'Chiave Segreta',
            'g_recaptcha_badge' => 'Badge v3',
```

b) nel form, dopo `FormField::key('g_recaptcha_secret_key')->text(),`:

```php
            FormField::key('g_recaptcha_score_site_key')->text(),
            FormField::key('g_recaptcha_badge')->select(['true' => 'Visibile', 'false' => 'Nascosto'])->value('true')->required(),
```

c) nel layout, le tre righe del riquadro reCAPTCHA (`SectionTitle::make('Google reCAPTCHA*')->columnSpan(12),` e i due `getInput` a `columnSpan(4)`) diventano:

```php
                    SectionTitle::make('Google reCAPTCHA*')
                        ->tooltip('v2: casella «Non sono un robot». v3: punteggio in background; con le due chiavi un punteggio basso mostra la casella. Badge v3 nascosto: sotto il form compare la riga di attribuzione di Google.')
                        ->columnSpan(12),
                    static::getInput('g_recaptcha_site_key')->columnSpan(3),
                    static::getInput('g_recaptcha_score_site_key')->columnSpan(3),
                    static::getInput('g_recaptcha_secret_key')->columnSpan(3),
                    static::getInput('g_recaptcha_badge')->columnSpan(3),
```

- [ ] **Passo 5: `dev-shared.md`**

Nella tabella dei servizi (riga 37), subito dopo `| reCAPTCHA | \`G_RECAPTCHA_SITE_KEY\` |`:

```markdown
| reCAPTCHA v3 | `G_RECAPTCHA_SCORE_SITE_KEY` |
```

- [ ] **Passo 6: il test passa, e la colonna nuova non rompe il resto**

Run: `php tests/Security/RecaptchaConfigTest.php`
Expected: `5 test, 0 falliti`.

Run: `php tests/Security/RecaptchaGuardTest.php`
Expected: `30 test, 0 fallimenti`.

Run: `php -l class/App/Credentials.php && php -l class/App/Resources/Config/SecurityResource.php && php -l class/App/Models/Config/Security.php && php -l class/Plugin/Google/Credentials.php`
Expected: `No syntax errors detected` quattro volte.

- [ ] **Passo 7: commit**

```bash
git add class/Plugin/Google/Credentials.php class/App/Credentials.php class/App/Models/Config/Security.php class/App/Resources/Config/SecurityResource.php docs/app/piattaforma/dev-shared.md tests/Security/RecaptchaConfigTest.php
git commit -m "Configurazione: chiave reCAPTCHA v3 (punteggio) e interruttore del badge

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Compito A5: il riquadro del form nei quattro modi

**File:**
- Modifica: `class/Themes/Wonder/Form/Components/reCAPTCHA.php`; `resources/lang/{it,en,de,es,fr}/components.json` (chiave `forms.recaptcha`)
- Test: `tests/Themes/RecaptchaComponentTest.php` (nuovo)

**Interfacce:**
- Consuma: `Credentials::api()->g_recaptcha_site_key`, `->g_recaptcha_score_site_key`, `->g_recaptcha_badge` (A4); `RecaptchaGuard::challenged(string $action): bool` (A3).
- Produce: `reCAPTCHA::markup(array $schema, string $siteKey, string $scoreKey, bool $badge, bool $challenge): string` (statico e puro). Il markup è quello che legge L1: `.g-recaptcha` con `data-wi-site-key`, `data-wi-score-key` (solo con la v3), `data-wi-badge` (`"true"`/`"false"`), `data-wi-challenge="1"` (solo a sfida aperta e con tutte e due le chiavi), `data-wi-theme`, `data-wi-size`, `data-wi-action`; campi `g-recaptcha-token` e `g-recaptcha-action` (`required` solo senza v3 o a sfida aperta), `g-recaptcha-type` (`score` o `checkbox`); `<p class="wi-recaptcha-terms">` solo con badge nascosto e v3.

| chiavi | `data-wi-score-key` | `required` | tipo | attribuzione |
|---|---|---|---|---|
| solo casella | no | sì | checkbox | mai |
| solo v3 | sì | no | score | se badge nascosto |
| tutte e due | sì | no (sì a sfida aperta) | score (checkbox a sfida aperta) | se badge nascosto |
| nessuna | no | sì | checkbox | mai |

- [ ] **Passo 1: test**

```php
<?php
/** php tests/Themes/RecaptchaComponentTest.php — il riquadro reCAPTCHA del form nei quattro modi. */
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../harness.php';

use Wonder\Themes\Wonder\Form\Components\reCAPTCHA;

echo "reCAPTCHA component\n";

$schema = ['recaptcha_action' => 'contact'];
$required = fn (string $html): bool => str_contains($html, 'name="g-recaptcha-token" required') && str_contains($html, 'name="g-recaptcha-action" required');

check('solo casella: come oggi, campi obbligatori, tipo checkbox', function () use ($schema, $required) {
    $html = reCAPTCHA::markup($schema, 'site-key', '', true, false);

    return $required($html) && !str_contains($html, 'data-wi-score-key') && str_contains($html, 'name="g-recaptcha-type" value="checkbox"')
        && str_contains($html, 'data-wi-site-key="site-key"') && str_contains($html, 'data-wi-action="contact"') && !str_contains($html, 'wi-recaptcha-terms');
});

check('solo v3: chiave v3, campi non obbligatori, tipo score', function () use ($schema, $required) {
    $html = reCAPTCHA::markup($schema, '', 'score-key', true, false);

    return !$required($html) && str_contains($html, 'data-wi-score-key="score-key"') && str_contains($html, 'name="g-recaptcha-type" value="score"');
});

check('tutte e due, senza sfida: tipo score, nessun data-wi-challenge', function () use ($schema, $required) {
    $html = reCAPTCHA::markup($schema, 'site-key', 'score-key', true, false);

    return !$required($html) && str_contains($html, 'value="score"') && !str_contains($html, 'data-wi-challenge');
});

check('tutte e due, sfida aperta: casella obbligatoria, tipo checkbox', function () use ($schema, $required) {
    $html = reCAPTCHA::markup($schema, 'site-key', 'score-key', true, true);

    return $required($html) && str_contains($html, 'data-wi-challenge="1"') && str_contains($html, 'value="checkbox"');
});

check('sfida senza chiave a casella o senza v3: ignorata', function () use ($schema) {
    return !str_contains(reCAPTCHA::markup($schema, '', 'score-key', true, true), 'data-wi-challenge')
        && !str_contains(reCAPTCHA::markup($schema, 'site-key', '', true, true), 'data-wi-challenge');
});

check('nessuna chiave: come oggi', function () use ($schema, $required) {
    $html = reCAPTCHA::markup($schema, '', '', true, false);

    return $required($html) && str_contains($html, 'data-wi-site-key=""') && str_contains($html, 'value="checkbox"');
});

check('data-wi-badge sempre "true" o "false"', function () use ($schema) {
    return str_contains(reCAPTCHA::markup($schema, 'site-key', '', true, false), 'data-wi-badge="true"')
        && str_contains(reCAPTCHA::markup($schema, 'site-key', 'score-key', false, false), 'data-wi-badge="false"');
});

check('attribuzione solo con badge nascosto e v3, con i due link di Google', function () use ($schema) {
    $html = reCAPTCHA::markup($schema, 'site-key', 'score-key', false, false);

    return str_contains($html, '<p class="wi-recaptcha-terms">')
        && str_contains($html, 'href="https://policies.google.com/privacy"')
        && str_contains($html, 'href="https://policies.google.com/terms"')
        && !str_contains($html, '{{privacy}}')
        && !str_contains(reCAPTCHA::markup($schema, 'site-key', '', false, false), 'wi-recaptcha-terms')
        && !str_contains(reCAPTCHA::markup($schema, 'site-key', 'score-key', true, false), 'wi-recaptcha-terms');
});

check('valori di base e escape degli attributi', function () {
    $html = reCAPTCHA::markup(['recaptcha_action' => 'a"b'], 'k"1', 's"2', true, false);
    $base = reCAPTCHA::markup([], 'site-key', '', true, false);

    return str_contains($html, 'data-wi-action="a&quot;b"') && str_contains($html, 'data-wi-site-key="k&quot;1"') && str_contains($html, 'data-wi-score-key="s&quot;2"')
        && str_contains($base, 'data-wi-theme="light"') && str_contains($base, 'data-wi-size="normal"') && str_contains($base, 'data-wi-action="submit"');
});

check('testi dell\'attribuzione nelle cinque lingue', function () {
    foreach (['it', 'en', 'de', 'es', 'fr'] as $lang) {
        $data = json_decode((string) file_get_contents(__DIR__ . "/../../resources/lang/{$lang}/components.json"), true);
        $texts = $data['forms']['recaptcha'] ?? [];

        if (!str_contains((string) ($texts['notice'] ?? ''), '{{privacy}}') || !str_contains((string) ($texts['notice'] ?? ''), '{{terms}}')
            || trim((string) ($texts['privacy'] ?? '')) === '' || trim((string) ($texts['terms'] ?? '')) === '') {
            echo "    {$lang}: forms.recaptcha incompleto\n";

            return false;
        }
    }

    return true;
});

summary();
```

- [ ] **Passo 2: il test fallisce**

Run: `php tests/Themes/RecaptchaComponentTest.php`
Expected: `✗` su tutti i casi («Call to undefined method …reCAPTCHA::markup()» e chiavi di lingua assenti), uscita 1.

- [ ] **Passo 3: il componente**

Contenuto completo di `class/Themes/Wonder/Form/Components/reCAPTCHA.php`:

```php
<?php

namespace Wonder\Themes\Wonder\Form\Components;

use Wonder\App\Credentials;
use Wonder\App\Security\RecaptchaGuard;
use Wonder\Themes\Wonder\Form\Field;

/**
 * Renderer Wonder dell'Element `reCAPTCHA`.
 *
 * Output:
 *  - `<div class="g-recaptcha" data-wi-…>` (widget Google; con
 *    `data-wi-score-key` la lib usa la v3 in background e accende la casella
 *    v2 solo su richiesta del server, `data-wi-challenge="1"` o risposta 618)
 *  - `<input type="hidden" name="g-recaptcha-token">` e
 *    `<input type="hidden" name="g-recaptcha-action">` (riempiti dal JS;
 *    `required` solo quando la casella è visibile da subito)
 *  - `<input type="hidden" name="g-recaptcha-type">` (`score` o `checkbox`:
 *    sceglie la chiave lato server)
 *  - con la v3 e il badge nascosto, la riga di attribuzione di Google.
 *
 * Le chiavi e il badge sono credenziali globali del sito
 * (`\Wonder\App\Credentials::api()`), non parametri per chiamata.
 */
class reCAPTCHA extends Field
{
    public function render($class): string
    {
        $this->schema = (array) ($class->schema ?? []);

        return $this->renderInput();
    }

    public function renderInput(): string
    {
        $api = Credentials::api();
        $siteKey = trim((string) ($api->g_recaptcha_site_key ?? ''));
        $scoreKey = trim((string) ($api->g_recaptcha_score_site_key ?? ''));
        $badge = (string) ($api->g_recaptcha_badge ?? 'true') !== 'false';
        $action = (string) ($this->schema['recaptcha_action'] ?? 'submit');
        $challenge = $scoreKey !== '' && $siteKey !== '' && RecaptchaGuard::challenged($action);

        return self::markup($this->schema, $siteKey, $scoreKey, $badge, $challenge);
    }

    /** Markup del riquadro, senza credenziali né sessione (testabile). */
    public static function markup(array $schema, string $siteKey, string $scoreKey, bool $badge, bool $challenge): string
    {
        $e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

        $score = $scoreKey !== '';
        $open = $challenge && $score && $siteKey !== '';
        $required = !$score || $open ? ' required' : '';
        $type = $score && !$open ? 'score' : 'checkbox';

        return '<div class="g-recaptcha" data-wi-site-key="'.$e($siteKey).'"'
            .($score ? ' data-wi-score-key="'.$e($scoreKey).'"' : '')
            .' data-wi-badge="'.($badge ? 'true' : 'false').'"'
            .($open ? ' data-wi-challenge="1"' : '')
            .' data-wi-theme="'.$e($schema['recaptcha_theme'] ?? 'light').'"'
            .' data-wi-size="'.$e($schema['recaptcha_size'] ?? 'normal').'"'
            .' data-wi-action="'.$e($schema['recaptcha_action'] ?? 'submit').'"></div>'
            .'<input type="hidden" name="g-recaptcha-token"'.$required.'>'
            .'<input type="hidden" name="g-recaptcha-action"'.$required.'>'
            .'<input type="hidden" name="g-recaptcha-type" value="'.$type.'">'
            .(!$badge && $score ? self::terms() : '');
    }

    /** Riga di attribuzione di Google (obbligatoria quando il badge v3 è nascosto). */
    private static function terms(): string
    {
        $link = static fn (string $href, string $text): string => '<a href="'.$href.'" target="_blank" rel="noopener">'.htmlspecialchars($text, ENT_QUOTES, 'UTF-8').'</a>';

        $notice = htmlspecialchars(self::text('components.forms.recaptcha.notice', 'Questo sito è protetto da reCAPTCHA: si applicano le {{privacy}} e i {{terms}} di Google.'), ENT_QUOTES, 'UTF-8');

        return '<p class="wi-recaptcha-terms">'.strtr($notice, [
            '{{privacy}}' => $link('https://policies.google.com/privacy', self::text('components.forms.recaptcha.privacy', 'Norme sulla privacy')),
            '{{terms}}' => $link('https://policies.google.com/terms', self::text('components.forms.recaptcha.terms', 'Termini di servizio')),
        ]).'</p>';
    }

    private static function text(string $key, string $fallback): string
    {
        $value = function_exists('__t') ? trim((string) __t($key)) : '';

        return $value !== '' && $value !== $key ? $value : $fallback;
    }
}
```

Nota: `$this->escape()` del vecchio codice non serve più (escape fatto in `markup()`); `Field` resta la classe madre.

- [ ] **Passo 4: i testi dell'attribuzione**

In ogni `resources/lang/<lingua>/components.json`, dentro l'oggetto `forms` già esistente, una chiave `recaptcha`:

| lingua | `notice` | `privacy` | `terms` |
|---|---|---|---|
| it | Questo sito è protetto da reCAPTCHA: si applicano le {{privacy}} e i {{terms}} di Google. | Norme sulla privacy | Termini di servizio |
| en | This site is protected by reCAPTCHA and the Google {{privacy}} and {{terms}} apply. | Privacy Policy | Terms of Service |
| de | Diese Website ist durch reCAPTCHA geschützt; es gelten die {{privacy}} und die {{terms}} von Google. | Datenschutzerklärung | Nutzungsbedingungen |
| es | Este sitio está protegido por reCAPTCHA y se aplican la {{privacy}} y los {{terms}} de Google. | Política de privacidad | Condiciones del servicio |
| fr | Ce site est protégé par reCAPTCHA ; les {{privacy}} et les {{terms}} de Google s'appliquent. | Règles de confidentialité | Conditions d'utilisation |

Per esempio in italiano:

```json
        "recaptcha": {
            "notice": "Questo sito è protetto da reCAPTCHA: si applicano le {{privacy}} e i {{terms}} di Google.",
            "privacy": "Norme sulla privacy",
            "terms": "Termini di servizio"
        }
```

(virgola sulla chiave precedente di `forms`; indentazione del file.) Controllo: `for l in it en de es fr; do php -r "json_decode(file_get_contents('resources/lang/$l/components.json'), flags: JSON_THROW_ON_ERROR);" || echo "JSON rotto: $l"; done` non stampa nulla.

- [ ] **Passo 5: il test passa**

Run: `php tests/Themes/RecaptchaComponentTest.php`
Expected: `10 test, 0 falliti`.

- [ ] **Passo 6: commit**

```bash
git add class/Themes/Wonder/Form/Components/reCAPTCHA.php resources/lang/it/components.json resources/lang/en/components.json resources/lang/de/components.json resources/lang/es/components.json resources/lang/fr/components.json tests/Themes/RecaptchaComponentTest.php
git commit -m "Riquadro reCAPTCHA: v3 in background, casella a sfida aperta e riga d'attribuzione col badge nascosto

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Compito A6: 617 e 618 escono HTTP 422; il login accetta il 618

**File:**
- Crea: `class/Api/ErrorStatus.php`
- Modifica: `class/Api/Handler.php` (`sendError`, riga 63), `app/http/api/resource/index.php` (righe 27 e 34), `class/Auth/Frontend/AuthController.php` (`verifyRecaptcha`, righe 427–432)
- Test: `tests/Http/ErrorStatusTest.php` (nuovo)

**Interfacce:**
- Consuma: `RecaptchaGuard::isFailure(Throwable): bool` (A3).
- Produce: `Wonder\Api\ErrorStatus::http(int $code): int` — 617 e 618 → 422; 400–599 → il codice; tutto il resto → 500. Il corpo delle risposte API tiene `status` col codice originale (617/618), che `fetch.js` e `send.js` leggono (L2).

`Resource::verifyRecaptcha` (`class/App/Resource.php:247`) lascia passare le eccezioni della guardia: con questo compito il 618 arriva all'API come 422 con `status: 618` nel corpo, senza toccare `Resource`.

- [ ] **Passo 1: test**

```php
<?php
/** php tests/Http/ErrorStatusTest.php — codici applicativi → codice HTTP. */
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../harness.php';

use Wonder\Api\ErrorStatus;

echo "ErrorStatus\n";

$root = dirname(__DIR__, 2);

check('617 e 618 → 422', fn () => ErrorStatus::http(617) === 422 && ErrorStatus::http(618) === 422);
check('codici HTTP di errore restano', fn () => ErrorStatus::http(400) === 400 && ErrorStatus::http(404) === 404 && ErrorStatus::http(500) === 500 && ErrorStatus::http(599) === 599);
check('fuori da 400–599 → 500', fn () => ErrorStatus::http(0) === 500 && ErrorStatus::http(200) === 500 && ErrorStatus::http(399) === 500 && ErrorStatus::http(700) === 500 && ErrorStatus::http(-1) === 500);

check('Handler::sendError passa da ErrorStatus e tiene status nel corpo', function () use ($root) {
    $source = (string) file_get_contents($root.'/class/Api/Handler.php');

    return str_contains($source, 'http_response_code(ErrorStatus::http($status));') && !str_contains($source, 'http_response_code($status);');
});

check('API resource: tutte e due le catch passano da ErrorStatus', function () use ($root) {
    $source = (string) file_get_contents($root.'/app/http/api/resource/index.php');

    return substr_count($source, '\Wonder\Api\ErrorStatus::http(') === 2;
});

check('login: il 618 della guardia diventa l\'avviso, non un errore', function () use ($root) {
    $source = (string) file_get_contents($root.'/class/Auth/Frontend/AuthController.php');

    return str_contains($source, 'if (!RecaptchaGuard::isFailure($exception))') && str_contains($source, '$ALERT = $exception->getCode();');
});

summary();
```

- [ ] **Passo 2: il test fallisce**

Run: `php tests/Http/ErrorStatusTest.php`
Expected: i primi tre con «Class "Wonder\Api\ErrorStatus" not found», gli ultimi tre `✗`; `6 test, 6 falliti`.

- [ ] **Passo 3: `ErrorStatus`**

`class/Api/ErrorStatus.php`:

```php
<?php

namespace Wonder\Api;

/**
 * Codice applicativo → codice HTTP della risposta. I codici dell'app fuori
 * dagli HTTP (617 reCAPTCHA fallito, 618 casella richiesta) escono 422; il
 * codice originale resta nel corpo (`status`).
 */
final class ErrorStatus
{
    public static function http(int $code): int
    {
        if ($code === 617 || $code === 618) {
            return 422;
        }

        return $code >= 400 && $code <= 599 ? $code : 500;
    }
}
```

- [ ] **Passo 4: i tre punti che scrivono il codice HTTP**

`class/Api/Handler.php`, in `sendError` (stesso namespace, nessun `use`):

```php
        http_response_code(ErrorStatus::http($status));
```

al posto di `http_response_code($status);`. Il corpo con `'status' => $status` resta com'è.

`app/http/api/resource/index.php`:

```php
} catch (EndpointException $exception) {
    http_response_code(\Wonder\Api\ErrorStatus::http($exception->getCode() ?: 400));
```

```php
} catch (Throwable $throwable) {
    http_response_code(\Wonder\Api\ErrorStatus::http((int) ($throwable->getCode() ?: 500)));
```

`class/Auth/Frontend/AuthController.php`, in `verifyRecaptcha`, la catch diventa:

```php
        } catch (\RuntimeException $exception) {
            if (!RecaptchaGuard::isFailure($exception)) {
                throw $exception;
            }

            $ALERT = $exception->getCode();

            return false;
        }
```

(618 → avviso «Conferma di non essere un robot» e il form, ridisegnato, mostra la casella grazie al segno in sessione.)

- [ ] **Passo 5: il test passa**

Run: `php tests/Http/ErrorStatusTest.php && php -l class/Api/Handler.php && php -l app/http/api/resource/index.php && php -l class/Auth/Frontend/AuthController.php`
Expected: `6 test, 0 falliti` e `No syntax errors detected` tre volte.

- [ ] **Passo 6: commit**

```bash
git add class/Api/ErrorStatus.php class/Api/Handler.php app/http/api/resource/index.php class/Auth/Frontend/AuthController.php tests/Http/ErrorStatusTest.php
git commit -m "API: 617 e 618 escono HTTP 422 col codice nel corpo; il login gestisce la sfida 618

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Compito A7: documentazione di reCAPTCHA

**File:**
- Modifica: `docs/app/servizi/recaptcha.md`

Nessun test nuovo (solo testo). Controllo con `grep`.

- [ ] **Passo 1: tabella dei metodi**

Nella tabella di «Uso diretto fuori dai Resource», la riga di `->withEngine` diventa:

```markdown
| `->withEngine($engine)` | engine alternativo `verify($token, $action, $siteKey): object` (default `reCAPTCHA` Enterprise); usato per i test |
```

e in fondo alla tabella:

```markdown
| `->withSiteKeys($checkbox, $score)` | chiavi del sito (default dalle credenziali); usato per i test |
| `->minScore($score)` | punteggio v3 minimo (default `0.5`) |
| `RecaptchaGuard::configured()` | c'è almeno una chiave (casella o v3) |
| `RecaptchaGuard::isFailure($e)` | `$e` è un 617 o un 618 della guardia |
| `RecaptchaGuard::challenged($action)` | il form di `$action` deve mostrare la casella (legge e cancella il segno in sessione) |
```

La riga di `->passes()` diventa «come sopra ma ritorna `false` invece di lanciare il `617` o il `618`».

- [ ] **Passo 2: sezione v3, prima di «## Casi gestiti»**

```markdown
## v3 (punteggio) e casella di riserva

Due chiavi in Configurazione → Sicurezza: **Chiave Sito v2 (casella)**
(`g_recaptcha_site_key`, env `G_RECAPTCHA_SITE_KEY`) e **Chiave Sito v3
(punteggio)** (`g_recaptcha_score_site_key`, env `G_RECAPTCHA_SCORE_SITE_KEY`).
**Badge v3** (`g_recaptcha_badge`, env `G_RECAPTCHA_BADGE`, `true` di base)
mostra o nasconde il badge di Google; nascosto, sotto il riquadro compare la
riga di attribuzione con i link a privacy e termini di Google (obbligatoria).

| Chiavi | Riquadro | Punteggio basso |
|--------|----------|-----------------|
| solo casella | casella «Non sono un robot» (come prima) | — |
| solo v3 | nessun clic, badge o attribuzione | `617` |
| tutte e due | nessun clic, badge o attribuzione | `618` e la casella |
| nessuna | come prima (la verifica fallisce con `617`) | — |

Con la v3 la lib chiede un token nuovo a ogni invio del form e manda
`g-recaptcha-type=score`; la guardia sceglie la chiave dal tipo (assente =
`checkbox`). Con punteggio sotto `0.5` (o assente) e la chiave a casella
configurata, la guardia segna l'azione in sessione e lancia
`RecaptchaChallenge` (`618`, «Conferma di non essere un robot»): le API
rispondono 422 con `status: 618`, la lib accende la casella nello stesso
riquadro; i form ridisegnati dal server la mostrano grazie al segno. Il
successo cancella il segno.

**Fail-open.** Se l'engine lancia o Google non risponde davvero (rete, 200
senza JSON, 429, 5xx, 4xx diversi da 400) la verifica si **salta** con una
riga di log (`skipped: true`). Non passano mai: token o azione assenti, tipo
senza chiave, token non valido per Google, HTTP 400.

**Limite noto.** Il ripiego `form.submit()` di `confirm.js` (browser senza
`requestSubmit`) salta l'ascolto del submit della lib: con la v3 il form parte
col token vuoto e il server risponde `617`.
```

- [ ] **Passo 3: «Casi gestiti»**

Il paragrafo introduttivo diventa «I fallimenti producono il codice utente `617` (o `618` per la casella) e una riga di log strutturata (file `storage/logs/error/recaptcha.log`) con `reason`; le verifiche saltate scrivono `skipped: true`:» e la tabella:

```markdown
| `reason` | Quando | Esito | Livello |
|----------|--------|-------|---------|
| `missing` | `g-recaptcha-token` o `g-recaptcha-action` mancanti | `617` | `WARNING` |
| `invalid` | tipo senza chiave configurata (engine non chiamato), oppure Google risponde `valid = false` | `617` | `WARNING` |
| `action_mismatch` | action ricevuta ≠ action attesa (engine non chiamato), oppure action di Google ≠ attesa (`source: google`) | `617` | `WARNING` |
| `low_score` | tipo `score`, punteggio sotto il minimo o assente | `618` con la chiave a casella, altrimenti `617` | `WARNING` |
| `engine_unavailable` | Google non risponde davvero (`http_code` nel log) | salta | `WARNING` (0, 200, 429, 5xx), `ERROR` (altri 4xx: configurazione) |
| `engine_error` | l'engine lancia un'eccezione (loggata l'originale) | salta | `ERROR` |
```

e nella frase sul context del log, dopo `has_site_key`, aggiungere `has_score_site_key`; in coda: «Il campo `event` della risposta di Google (contiene il token) non va nel log.»

- [ ] **Passo 4: «Test»**

Il blocco diventa:

````markdown
Runner standalone, niente phpunit:

```
php tests/Security/RecaptchaGuardTest.php
php tests/Plugin/Google/RecaptchaEngineTest.php
php tests/Themes/RecaptchaComponentTest.php
php tests/Security/RecaptchaConfigTest.php
php tests/Http/ErrorStatusTest.php
php tests/Http/CallHttpCodeTest.php
```

La guardia copre token valido, token/action mancanti, action mismatch (client
e Google), fail-open (eccezione, 429, 5xx, rete, 4xx), `valid=false`, HTTP 400,
v3 col punteggio alto e basso, la sfida `618` e il suo segno in sessione,
`passes()`, action vuota (BC) e la derivazione dell'action da `formSchema()`.
L'engine è iniettato (fake) → nessuna HTTP reale; la classificazione della
risposta di Google è provata a parte con una `Call` finta.
````

- [ ] **Passo 5: controllo e commit**

Run: `grep -c "618\|engine_unavailable\|G_RECAPTCHA_SCORE_SITE_KEY\|confirm.js" docs/app/servizi/recaptcha.md`
Expected: un numero ≥ 6.

```bash
git add docs/app/servizi/recaptcha.md
git commit -m "Documentazione reCAPTCHA: v3, sfida 618, badge e fail-open

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Compito A8: pavimento della lib e suite completa dell'app

Si fa **dopo** il rilascio di L4 (`wonder-image@2.1.2-alpha.27` pubblicata).

**File:**
- Modifica: `composer.json` (`extra.wonder.lib`), `package.json` (`dependencies.wonder-image`)
- Test: `tests/Docs/LibConstraintTest.php` (esistente)

- [ ] **Passo 1: il vincolo**

In tutti e due i file `^2.1.2-alpha.26` diventa `^2.1.2-alpha.27`.

- [ ] **Passo 2: il test del vincolo**

Run: `php tests/Docs/LibConstraintTest.php`
Expected: `0 falliti`.

- [ ] **Passo 3: suite completa**

Run (prima `ps aux | grep "php .*Test.php"`: nessun'altra suite in corsa):

```bash
for f in $(find tests -name '*.php' ! -name 'harness.php' ! -path '*/fixtures/*' ! -path '*/support/*' | sort); do php "$f" >/dev/null 2>&1 || echo "FAIL $f"; done
```

Expected: solo `FAIL tests/Docs/ApiReferenceTest.php` (rosso già noto). Altri `FAIL`: aprire il file con `php <file>` e correggere prima del commit.

- [ ] **Passo 4: commit**

```bash
git add composer.json package.json
git commit -m "Lib minima 2.1.2-alpha.27 (reCAPTCHA v3)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Passo 5: STOP**

Il ramo `recaptcha-v3` dell'app è pronto. Merge e push solo col via dell'utente (insieme all'ecommerce, compito E7).

---

## Ecommerce

Tutti i compiti E girano nel worktree `/Users/andreamarinoni/Developer/worktrees/recaptcha-v3/ecommerce`, dove `vendor/wonder-image/app` punta al worktree dell'app (compito 0): le classi di A3 (`RecaptchaChallenge`, `RecaptchaGuard::configured()`) ci sono. I test statici leggono i file del worktree e non caricano l'app.

### Compito E1: `ExpressVerification`, il segno dell'ospite verificato

**File:**
- Crea: `src/Frontend/Checkout/ExpressVerification.php`
- Modifica: `src/Frontend/Checkout/ExpressCart.php` (`settled()`)
- Test: `tests/ExpressRecaptchaTest.php` (nuovo; `tests/run.php` lo prende da solo con il glob `*Test.php`)

**Interfacce:**
- Consuma: niente dai compiti precedenti.
- Produce: `final class Wonder\Plugin\Ecommerce\Frontend\Checkout\ExpressVerification` con `public const SESSION = 'ecommerce_express_verified'`, `mark(int $cartId): void`, `verified(int $cartId): bool` (vero solo se `$cartId > 0` e uguale al segno), `clear(): void`. Nel test, la closure `$metodo(string $file, string $nome): string` (corpo di un metodo PHP) che E2 e E5 riusano nello stesso file.

- [ ] **Passo 1: il test**

`tests/ExpressRecaptchaTest.php`:

```php
<?php
/** php tests/ExpressRecaptchaTest.php — il reCAPTCHA dell'ospite nel check-out rapido. */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';

use Wonder\Plugin\Ecommerce\Frontend\Checkout\ExpressVerification;

$root = dirname(__DIR__);

/** Il corpo di un metodo PHP: dalla firma alla prima graffa chiusa a quattro spazi; vuoto se non c'è. */
$metodo = static function (string $file, string $nome): string {
    $codice = (string) file_get_contents($file);
    $inizio = strpos($codice, "function {$nome}(");
    if ($inizio === false) {
        return '';
    }
    $fine = strpos($codice, "\n    }\n", $inizio);

    return $fine === false ? substr($codice, $inizio) : substr($codice, $inizio, $fine - $inizio);
};

$_SESSION = [];
check('senza segno nessun carrello è verificato', fn () => !ExpressVerification::verified(12));

ExpressVerification::mark(12);
check('il segno vale per il suo carrello', fn () => ExpressVerification::verified(12));
check('il segno non vale per un altro carrello', fn () => !ExpressVerification::verified(13));

ExpressVerification::mark(0);
check('il carrello 0 non è mai verificato', fn () => !ExpressVerification::verified(0));

ExpressVerification::mark(12);
ExpressVerification::clear();
check('clear toglie il segno', fn () => !ExpressVerification::verified(12) && !isset($_SESSION[ExpressVerification::SESSION]));

$settled = $metodo($root.'/src/Frontend/Checkout/ExpressCart.php', 'settled');
check('settled toglie il segno insieme al carrello rapido', fn () =>
    (bool) preg_match('/self::forget\(\);\s*ExpressVerification::clear\(\);/', $settled));

summary();
```

- [ ] **Passo 2: il test fallisce**

Run: `php tests/ExpressRecaptchaTest.php`
Expected: errore fatale `Class "Wonder\Plugin\Ecommerce\Frontend\Checkout\ExpressVerification" not found`.

- [ ] **Passo 3: la classe**

`src/Frontend/Checkout/ExpressVerification.php`:

```php
<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Checkout;

/**
 * Il segno che l'ospite ha passato il reCAPTCHA all'avvio del check-out
 * rapido. Vale solo per il carrello rapido nato in quell'avvio: `place` lo
 * vuole; l'ordine pagato, il foglio chiuso e l'avvio successivo lo tolgono.
 */
final class ExpressVerification
{
    public const SESSION = 'ecommerce_express_verified';

    public static function mark(int $cartId): void
    {
        $_SESSION[self::SESSION] = $cartId;
    }

    public static function verified(int $cartId): bool
    {
        return $cartId > 0 && (int) ($_SESSION[self::SESSION] ?? 0) === $cartId;
    }

    public static function clear(): void
    {
        unset($_SESSION[self::SESSION]);
    }
}
```

In `src/Frontend/Checkout/ExpressCart.php`, dentro `settled()`, subito dopo `self::forget();`:

```php
        self::forget();
        ExpressVerification::clear();
        unset($_SESSION[ExpressAddress::SESSION]);
```

(`settled()` è chiamato da `CheckoutController` al ritorno del pagamento; l'id dell'ordine è quello del carrello rapido.)

- [ ] **Passo 4: il test passa**

Run: `php tests/ExpressRecaptchaTest.php`
Expected: `6 test, 0 falliti`.

- [ ] **Passo 5: commit**

```bash
git add src/Frontend/Checkout/ExpressVerification.php src/Frontend/Checkout/ExpressCart.php tests/ExpressRecaptchaTest.php
git commit -m "Express: il segno dell'ospite verificato, legato al carrello rapido

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Compito E2: `ExpressController` — guardia a `start`, `place` vuole il segno

**File:**
- Modifica: `src/Frontend/Checkout/ExpressController.php` (`use`, docblock, `start()`, `place()`, `cancel()`), `lang/it/ecommerce.json`, `lang/en/ecommerce.json`
- Test: `tests/ExpressRecaptchaTest.php`

**Interfacce:**
- Consuma: `ExpressVerification` (E1); dall'app (A3) `RecaptchaGuard::for(string $action)->withService(string)->withLogAction(string)->verify(): void` (legge i campi `g-recaptcha-*` da `$_POST`; lancia `RuntimeException` 617 o `RecaptchaChallenge` 618; passa senza lanciare quando la verifica si salta), `final class Wonder\App\Security\RecaptchaChallenge extends RuntimeException` con `CHALLENGE_CODE = 618`.
- Produce (per E5 e E6): `POST /checkout/express/start/` dell'ospite → 422 `{success:false, message}` per un 617, 422 `{success:false, status:618, message}` per la sfida, 200 come oggi quando passa; `POST /checkout/express/place/` dell'ospite senza segno → 403 `{success:false, error:'unverified', message}`; la chiave `ecommerce.checkout.express_unverified`.

- [ ] **Passo 1: i test**

In `tests/ExpressRecaptchaTest.php`, prima di `summary();`:

```php
$controller = $root.'/src/Frontend/Checkout/ExpressController.php';
$codiceController = (string) file_get_contents($controller);
$start = $metodo($controller, 'start');
$place = $metodo($controller, 'place');
$cancel = $metodo($controller, 'cancel');
$guardia = "RecaptchaGuard::for('ecommerce_express')";

check('use della guardia e della sfida', fn () =>
    str_contains($codiceController, 'use Wonder\App\Security\RecaptchaChallenge;')
    && str_contains($codiceController, 'use Wonder\App\Security\RecaptchaGuard;'));
check('il docblock non dice più «Niente reCAPTCHA»', fn () => !str_contains($codiceController, 'Niente reCAPTCHA'));
check('start: il segno vecchio si toglie prima della guardia', fn () =>
    str_contains($start, 'ExpressVerification::clear();')
    && strpos($start, 'ExpressVerification::clear();') < (int) strpos($start, $guardia));
check('start: la guardia solo per l\'ospite, con servizio e azione di log', fn () =>
    str_contains($start, '$guest = !CartSession::authenticated();')
    && (bool) preg_match('/if \(\$guest\) \{\s*RecaptchaGuard::for\(\'ecommerce_express\'\)\s*->withService\(\'ecommerce-express\'\)\s*->withLogAction\(\'start\'\)\s*->verify\(\);/', $start));
check('start: la guardia prima del carrello, il segno subito dopo', fn () =>
    strpos($start, $guardia) < (int) strpos($start, 'self::begin(')
    && (bool) preg_match('/\$cartId = self::begin\(\$_POST\);\s*if \(\$guest\) \{\s*ExpressVerification::mark\(\$cartId\);/', $start));
check('start: la sfida 618 si prende prima dei 422 generici', fn () =>
    str_contains($start, 'catch (RecaptchaChallenge $e)')
    && strpos($start, 'catch (RecaptchaChallenge $e)') < (int) strpos($start, 'catch (UserError|RuntimeException $error)')
    && str_contains($start, "self::json(['success' => false, 'status' => RecaptchaChallenge::CHALLENGE_CODE, 'message' => \$e->getMessage()], 422);"));
check('place: l\'ospite senza segno riceve 403 unverified, dopo il controllo del carrello', fn () =>
    (bool) preg_match('/\$cartId = self::expressCart\(\);\s*(?:\/\/[^\n]*\s*)?if \(!CartSession::authenticated\(\) && !ExpressVerification::verified\(\$cartId\)\) \{\s*self::json\(\[\'success\' => false, \'error\' => \'unverified\', \'message\' => \(string\) __t\(\'ecommerce\.checkout\.express_unverified\'\)\], 403\);/', $place));
check('cancel toglie il segno', fn () => str_contains($cancel, 'ExpressVerification::clear();'));

foreach (['it', 'en'] as $lingua) {
    $testi = json_decode((string) file_get_contents($root."/lang/{$lingua}/ecommerce.json"), true);
    check("lang {$lingua}: express_unverified", fn () => ($testi['checkout']['express_unverified'] ?? '') !== '');
}
```

- [ ] **Passo 2: i test falliscono**

Run: `php tests/ExpressRecaptchaTest.php`
Expected: `16 test, 10 falliti` (i 6 di E1 verdi, i 10 nuovi rossi).

- [ ] **Passo 3: `use` e docblock**

In testa, dopo `use Throwable;`:

```php
use Wonder\App\Security\RecaptchaChallenge;
use Wonder\App\Security\RecaptchaGuard;
```

Nel docblock della classe la riga «Niente reCAPTCHA: il wallet ha già riconosciuto il cliente.» diventa:

```php
 * L'ospite passa dal reCAPTCHA a `start` (azione `ecommerce_express`): il
 * segno in sessione lega la verifica al carrello rapido e `place` lo vuole.
```

- [ ] **Passo 4: `start()`**

Il metodo intero diventa:

```php
    /** Il carrello a parte: il solo prodotto della scheda, oppure la copia del carrello. */
    private static function start(): never
    {
        try {
            // Ogni avvio rifà la verifica: il segno vecchio non vale per il carrello nuovo.
            ExpressVerification::clear();
            $guest = !CartSession::authenticated();
            if ($guest) {
                RecaptchaGuard::for('ecommerce_express')
                    ->withService('ecommerce-express')
                    ->withLogAction('start')
                    ->verify();
            }

            self::dropPending();
            $cartId = self::begin($_POST);
            if ($guest) {
                ExpressVerification::mark($cartId);
            }
            $order = CheckoutSummary::payload($cartId, ['fulfillment_type' => 'shipping'], CartSession::user())['order'];
        } catch (RecaptchaChallenge $e) {
            // Punteggio basso: il JavaScript apre la casella sotto i bottoni.
            self::json(['success' => false, 'status' => RecaptchaChallenge::CHALLENGE_CODE, 'message' => $e->getMessage()], 422);
        } catch (UserError|RuntimeException $error) {
            self::json(['success' => false, 'message' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            Errors::internal($error, 'ecommerce.express.start');
            self::json(['success' => false, 'message' => (string) __t('ecommerce.checkout.express_error')], 500);
        }

        self::json(['success' => true] + self::amounts($order));
    }
```

Il 617 della guardia è una `RuntimeException` e cade nel 422 generico col suo messaggio. Con la verifica saltata (fail-open) `verify()` torna normalmente e il carrello si segna.

- [ ] **Passo 5: `place()` e `cancel()`**

In `place()`, subito dopo `$cartId = self::expressCart();` (così senza carrello rapido resta il 409 `no_cart` di oggi):

```php
        // L'ospite paga solo il carrello rapido nato da un avvio verificato.
        if (!CartSession::authenticated() && !ExpressVerification::verified($cartId)) {
            self::json(['success' => false, 'error' => 'unverified', 'message' => (string) __t('ecommerce.checkout.express_unverified')], 403);
        }
```

In `cancel()`, prima del `try`:

```php
        ExpressVerification::clear();

```

- [ ] **Passo 6: i testi**

In `lang/it/ecommerce.json`, dentro `checkout`, dopo `express_changed`:

```json
        "express_unverified": "Conferma di non essere un robot prima di pagare.",
```

In `lang/en/ecommerce.json`, stesso posto:

```json
        "express_unverified": "Confirm you are not a robot before paying.",
```

- [ ] **Passo 7: i test passano**

Run: `php tests/ExpressRecaptchaTest.php && php -l src/Frontend/Checkout/ExpressController.php`
Expected: `16 test, 0 falliti` e `No syntax errors detected`.

- [ ] **Passo 8: commit**

```bash
git add src/Frontend/Checkout/ExpressController.php lang/it/ecommerce.json lang/en/ecommerce.json tests/ExpressRecaptchaTest.php
git commit -m "Express: reCAPTCHA dell'ospite a start, place rifiuta senza verifica

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Compito E3: `CheckoutController` — la sfida 618 nel checkout

**File:**
- Modifica: `src/Frontend/Checkout/CheckoutController.php` (`use`, i `catch` di `place()`, `rememberErrors()`)
- Test: `tests/CheckoutRecaptchaTest.php` (nuovo)

**Interfacce:**
- Consuma: `RecaptchaChallenge` (A3). La guardia dell'ospite c'è già, dentro il `try` di `place()` (`RecaptchaGuard::for('ecommerce_checkout')…->verify()`).
- Produce (per E4): con JSON, 422 `{success:false, status:618, errors:[messaggio]}`; senza JSON, il redirect al checkout con l'errore in flash (il riquadro torna con la casella aperta grazie al segno in sessione letto da A5). La closure `$metodo` del test (uguale a quella di E1) che E4 riusa.

- [ ] **Passo 1: il test**

`tests/CheckoutRecaptchaTest.php`:

```php
<?php
/** php tests/CheckoutRecaptchaTest.php — la sfida 618 del reCAPTCHA nel checkout: controller e JavaScript. */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';

$root = dirname(__DIR__);

/** Il corpo di un metodo PHP: dalla firma alla prima graffa chiusa a quattro spazi; vuoto se non c'è. */
$metodo = static function (string $file, string $nome): string {
    $codice = (string) file_get_contents($file);
    $inizio = strpos($codice, "function {$nome}(");
    if ($inizio === false) {
        return '';
    }
    $fine = strpos($codice, "\n    }\n", $inizio);

    return $fine === false ? substr($codice, $inizio) : substr($codice, $inizio, $fine - $inizio);
};

$file = $root.'/src/Frontend/Checkout/CheckoutController.php';
$controller = (string) file_get_contents($file);
$place = $metodo($file, 'place');

check('use della sfida', fn () => str_contains($controller, 'use Wonder\App\Security\RecaptchaChallenge;'));
check('place: la sfida si prende dopo CheckoutRejected e prima di UserError e RuntimeException', fn () =>
    str_contains($place, 'catch (RecaptchaChallenge $e)')
    && strpos($place, 'catch (RecaptchaChallenge $e)') > (int) strpos($place, 'catch (CheckoutRejected $e)')
    && strpos($place, 'catch (RecaptchaChallenge $e)') < (int) strpos($place, 'catch (UserError $error)')
    && strpos($place, 'catch (RecaptchaChallenge $e)') < (int) strpos($place, 'catch (RuntimeException $error)'));
check('place: in JSON la sfida risponde 422 con status 618', fn () =>
    (bool) preg_match('/catch \(RecaptchaChallenge \$e\) \{.*?if \(\$json\) \{\s*self::json\(\[\'success\' => false, \'status\' => RecaptchaChallenge::CHALLENGE_CODE, \'errors\' => \[\$e->getMessage\(\)\]\], 422\);\s*\}\s*self::reject\(\[\$e->getMessage\(\)\], \$post, \$json\);/s', $place));
check('rememberErrors non ricorda il tipo del token', fn () =>
    str_contains($metodo($file, 'rememberErrors'), "\$values['g-recaptcha-type']"));
```

e in fondo `summary();` (E4 aggiunge i suoi controlli prima di questa riga).

- [ ] **Passo 2: il test fallisce**

Run: `php tests/CheckoutRecaptchaTest.php`
Expected: `4 test, 4 falliti`.

- [ ] **Passo 3: il codice**

In testa, accanto a `use Wonder\App\Security\RecaptchaGuard;`:

```php
use Wonder\App\Security\RecaptchaChallenge;
```

In `place()`, fra il `catch (CheckoutRejected $e) { … }` e `catch (UserError $error)`:

```php
        } catch (RecaptchaChallenge $e) {
            // Punteggio basso: il JavaScript apre la casella; senza JSON il modulo torna con la casella aperta (segno in sessione).
            if ($json) {
                self::json(['success' => false, 'status' => RecaptchaChallenge::CHALLENGE_CODE, 'errors' => [$e->getMessage()]], 422);
            }

            self::reject([$e->getMessage()], $post, $json);
```

In `rememberErrors()`, l'`unset` diventa:

```php
        unset(
            $values['csrf_token'],
            $values['g-recaptcha-token'],
            $values['g-recaptcha-action'],
            $values['g-recaptcha-type']
        );
```

- [ ] **Passo 4: il test passa, e quelli vicini**

Run: `php tests/CheckoutRecaptchaTest.php && php tests/CheckoutPlaceJsonTest.php && php -l src/Frontend/Checkout/CheckoutController.php`
Expected: `4 test, 0 falliti`; `CheckoutPlaceJsonTest` con `0 falliti` (controlla che `CheckoutRejected` venga prima di `RuntimeException`); `No syntax errors detected`.

- [ ] **Passo 5: commit**

```bash
git add src/Frontend/Checkout/CheckoutController.php tests/CheckoutRecaptchaTest.php
git commit -m "Checkout: la sfida 618 del reCAPTCHA in JSON e col modulo ridisegnato

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Compito E4: `checkout.js` — la casella dopo un 618, il rinnovo passa alla lib

**File:**
- Modifica: `resources/assets/js/checkout.js` (`place()` e `resetRecaptcha()` della classe del checkout, righe ~813–858 di oggi)
- Test: `tests/CheckoutRecaptchaTest.php`

**Interfacce:**
- Consuma: dalla lib (L1) `window.wiRecaptchaChallenge(scope)` e `window.wiRecaptchaReset(scope)`; da E3 la risposta JSON con `status: 618`.
- Produce: niente per gli altri compiti.

Il ripiego di `resetRecaptcha()` resta com'è: `CheckoutStripeTest` controlla `window.grecaptcha?.enterprise?.reset`, `grecaptcha.enterprise.reset()`, i due selettori dei campi e che `window.grecaptcha.reset` non ci sia.

- [ ] **Passo 1: i test**

In `tests/CheckoutRecaptchaTest.php`, prima di `summary();`:

```php
$js = (string) file_get_contents($root.'/resources/assets/js/checkout.js');
check('place: sul 618 si apre la casella, altrimenti si rinnova il widget', fn () =>
    (bool) preg_match('/if \(payload\.status === 618 && typeof window\.wiRecaptchaChallenge === \'function\'\) \{\s*window\.wiRecaptchaChallenge\(this\.form\);\s*\} else \{\s*this\.resetRecaptcha\(\);\s*\}/', $js));
check('resetRecaptcha passa alla lib quando c\'è', fn () =>
    (bool) preg_match('/resetRecaptcha\(\) \{\s*if \(typeof window\.wiRecaptchaReset === \'function\'\) \{\s*window\.wiRecaptchaReset\(this\.form\);\s*return;\s*\}/', $js));
```

- [ ] **Passo 2: i test falliscono**

Run: `php tests/CheckoutRecaptchaTest.php`
Expected: `6 test, 2 falliti`.

- [ ] **Passo 3: il codice**

In `place()`, la riga `this.resetRecaptcha();` dopo il blocco `if (payload.error === 'total_changed' …) { … } else { this.say(…); }` diventa:

```js
        // Punteggio basso: la lib apre la casella nel riquadro del modulo; altrimenti il token usato si butta.
        if (payload.status === 618 && typeof window.wiRecaptchaChallenge === 'function') {
            window.wiRecaptchaChallenge(this.form);
        } else {
            this.resetRecaptcha();
        }
```

Il commento sopra `resetRecaptcha()` (tre righe, da «Il token del reCAPTCHA vale una volta sola» a «invece di rimandare un token già usato.») diventa:

```js
    // Il token del reCAPTCHA vale una volta sola. Con la lib nuova se ne occupa `wiRecaptchaReset`, che sa
    // se il riquadro è a casella o v3 e scrive anche il tipo. Altrimenti il widget è Enterprise (lo monta la
    // lib) e scrive il token in due campi nascosti: si rinnova il widget e si svuotano i campi, così
    // l'ospite lo rifà prima del prossimo «Paga» invece di rimandare un token già usato.
```

e dentro `resetRecaptcha()`, prima del `try` esistente:

```js
        if (typeof window.wiRecaptchaReset === 'function') {
            window.wiRecaptchaReset(this.form);
            return;
        }

```

- [ ] **Passo 4: i test passano, e quello di Stripe**

Run: `php tests/CheckoutRecaptchaTest.php && php tests/CheckoutStripeTest.php`
Expected: `6 test, 0 falliti` e `CheckoutStripeTest` con `0 falliti`.

- [ ] **Passo 5: commit**

```bash
git add resources/assets/js/checkout.js tests/CheckoutRecaptchaTest.php
git commit -m "Checkout JS: casella del reCAPTCHA sul 618, rinnovo con wiRecaptchaReset

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Compito E5: l'express dell'ospite — riquadro, token a `start`, sfida sotto i bottoni

**File:**
- Modifica: `src/Frontend/Checkout/ExpressCheckout.php` (`use`, docblock, `render()`, `method()`), `view/components/checkout/express.php`, `resources/assets/js/checkout.js` (classe `Express`, da riga ~1231), `resources/assets/css/express.css`, `lang/it/ecommerce.json`, `lang/en/ecommerce.json`
- Test: `tests/ExpressRecaptchaTest.php`

**Interfacce:**
- Consuma: `RecaptchaGuard::configured(): bool` (A3); il componente `FormField::key('recaptcha')->recaptcha(string $action)` (A5: `.g-recaptcha` con `data-wi-site-key`, `data-wi-score-key` solo con la v3, `data-wi-challenge="1"` solo a sfida aperta); dalla lib (L1) `window.wiRecaptchaToken(scope) → Promise<{token, action, type}|null>`, `window.wiRecaptchaChallenge(scope)`, `window.wiRecaptchaReset(scope)`, l'evento `wi-recaptcha-change` (bolle, `detail {token, type}`) e il riquadro autonomo `[data-wi-recaptcha-standalone]` (campi fuori dal form che lo contiene); da E2 le risposte di `start`.
- Produce: niente per gli altri compiti.

Decisioni (vedi «Decisioni già prese»): l'ospite senza chiavi reCAPTCHA non vede l'express; con la casella a vista (solo v2, o sfida aperta dal server) i bottoni restano spenti finché non si spunta; senza token `start` non parte; la sfida si apre appena arriva il 618 (il foglio del wallet la copre finché resta aperto).

- [ ] **Passo 1: i test**

In `tests/ExpressRecaptchaTest.php`, prima di `summary();`:

```php
$express = $root.'/src/Frontend/Checkout/ExpressCheckout.php';
$codiceExpress = (string) file_get_contents($express);
check('l\'ospite senza chiavi reCAPTCHA non vede i bottoni', fn () =>
    str_contains($codiceExpress, 'use Wonder\App\Security\RecaptchaGuard;')
    && (bool) preg_match('/if \(!CartSession::authenticated\(\) && !RecaptchaGuard::configured\(\)\) \{\s*return null;/', $metodo($express, 'method')));
check('la vista riceve captcha per l\'ospite, il JS l\'etichetta della casella', fn () =>
    str_contains($codiceExpress, "'captcha' => !CartSession::authenticated(),")
    && str_contains($codiceExpress, "'captcha' => (string) __t('ecommerce.checkout.express_captcha'),"));

$vista = (string) file_get_contents($root.'/view/components/checkout/express.php');
check('la vista mette il riquadro autonomo sotto i bottoni', fn () =>
    str_contains($vista, 'use Wonder\App\ResourceSchema\FormField;')
    && (bool) preg_match('/data-express-element><\/div>\s*<\?php if \(!empty\(\$captcha\)\): \?><div class="w-100 mt-3" data-wi-recaptcha-standalone><\?=FormField::key\(\'recaptcha\'\)->recaptcha\(\'ecommerce_express\'\)\?><\/div><\?php endif; \?>/', $vista));

/** Un metodo della classe Express in checkout.js: dalla firma alla prima graffa chiusa a quattro spazi. */
$metodoJs = static function (string $classe, string $firma): string {
    $inizio = strpos($classe, "\n    {$firma}");
    if ($inizio === false) {
        return '';
    }
    $fine = strpos($classe, "\n    }\n", $inizio + 1);

    return $fine === false ? substr($classe, $inizio) : substr($classe, $inizio, $fine - $inizio);
};
$codiceJs = (string) file_get_contents($root.'/resources/assets/js/checkout.js');
$classe = substr($codiceJs, (int) strpos($codiceJs, 'class Express {'));
$costruttore = $metodoJs($classe, 'constructor(root) {');
$click = $metodoJs($classe, 'click(event) {');
$verifica = $metodoJs($classe, 'async verify(data) {');
$annulla = $metodoJs($classe, 'cancel() {');

check('Express: il riquadro autonomo e la casella a vista spengono i bottoni', fn () =>
    str_contains($costruttore, "this.captcha = root.querySelector('[data-wi-recaptcha-standalone]');")
    && str_contains($costruttore, "this.challenge = Boolean(box?.dataset.wiSiteKey) && (!box.dataset.wiScoreKey || box.dataset.wiChallenge === '1');")
    && str_contains($costruttore, "addEventListener('wi-recaptcha-change'")
    && str_contains($classe, "this.root.classList.toggle('wi-express-locked', on);"));
check('Express: a bottoni spenti il clic non apre il foglio', fn () =>
    str_contains($click, "this.root.classList.contains('wi-express-locked')")
    && strpos($click, "this.root.classList.contains('wi-express-locked')") < (int) strpos($click, 'event.resolve('));
check('Express: il token si chiede dopo resolve e va a start con i tre campi', fn () =>
    strpos($click, 'event.resolve(') < (int) strpos($click, 'this.verify(data)')
    && str_contains($verifica, 'window.wiRecaptchaToken(this.captcha)')
    && str_contains($verifica, "'g-recaptcha-token': answer.token, 'g-recaptcha-action': answer.action, 'g-recaptcha-type': answer.type"));
check('Express: sul 618 la casella si apre sotto i bottoni', fn () =>
    str_contains($click, 'reply.data?.status === 618')
    && str_contains($click, 'window.wiRecaptchaChallenge?.(this.captcha);')
    && str_contains($click, 'this.lockButtons(true);'));
check('Express: il token della casella si rinnova dopo start', fn () =>
    str_contains($click, "values['g-recaptcha-type'] === 'checkbox'") && str_contains($click, 'window.wiRecaptchaReset?.(this.captcha);'));
check('Express: il foglio chiuso ricorda la casella', fn () => str_contains($annulla, 'this.say(this.labels.captcha);'));

$css = (string) file_get_contents($root.'/resources/assets/css/express.css');
check('CSS: bottoni spenti', fn () =>
    (bool) preg_match('/\.wi-express\.wi-express-locked \[data-express-element\] \{\s*pointer-events: none;/', $css));

foreach (['it', 'en'] as $lingua) {
    $testi = json_decode((string) file_get_contents($root."/lang/{$lingua}/ecommerce.json"), true);
    check("lang {$lingua}: express_captcha", fn () => ($testi['checkout']['express_captcha'] ?? '') !== '');
}
```

- [ ] **Passo 2: i test falliscono**

Run: `php tests/ExpressRecaptchaTest.php`
Expected: `28 test, 12 falliti` (i 16 di E1–E2 verdi, i 12 nuovi rossi).

- [ ] **Passo 3: `ExpressCheckout`**

In testa, dopo `use Throwable;`:

```php
use Wonder\App\Security\RecaptchaGuard;
```

Nel docblock della classe «…e per l'ospite quando gli ordini senza account sono spenti.» diventa «…e per l'ospite quando gli ordini senza account sono spenti o il reCAPTCHA non ha chiavi.».

In `render()`, in `'labels' => [ … ]` dopo `'unavailable' => …`:

```php
                    'captcha' => (string) __t('ecommerce.checkout.express_captcha'),
```

e fra gli argomenti di `View::component(…)`, dopo `'privacy' => …`:

```php
                // L'ospite passa dal reCAPTCHA a `start`: il riquadro sta sotto i bottoni.
                'captcha' => !CartSession::authenticated(),
```

In `method()`, dopo il primo `if (…) { return null; }`:

```php
        // L'ospite passa dal reCAPTCHA a `start`: senza chiavi non potrebbe mai aprire il foglio.
        if (!CartSession::authenticated() && !RecaptchaGuard::configured()) {
            return null;
        }
```

- [ ] **Passo 4: la vista**

In `view/components/checkout/express.php`, in testa dopo `<?php`:

```php
use Wonder\App\ResourceSchema\FormField;
```

e la riga `<div class="w-100" data-express-element></div>` diventa (una riga sola):

```php
    <div class="w-100" data-express-element></div><?php if (!empty($captcha)): ?><div class="w-100 mt-3" data-wi-recaptcha-standalone><?=FormField::key('recaptcha')->recaptcha('ecommerce_express')?></div><?php endif; ?>
```

`data-wi-recaptcha-standalone` serve anche nel checkout, dove l'express sta dentro il modulo: i campi del riquadro restano fuori dal `FormData` del checkout e la sfida del checkout non lo tocca (L1).

- [ ] **Passo 5: `checkout.js`, classe `Express`**

Nel costruttore, dopo `this.toggle();` (il blocco della fattura):

```js

        // Il riquadro reCAPTCHA dell'ospite sta fuori dal modulo (riquadro autonomo); chi è entrato non ce l'ha.
        this.captcha = root.querySelector('[data-wi-recaptcha-standalone]');
        const box = this.captcha?.querySelector('.g-recaptcha');
        // Casella a vista (solo v2, o sfida già aperta dal server): i bottoni aspettano la spunta.
        this.challenge = Boolean(box?.dataset.wiSiteKey) && (!box.dataset.wiScoreKey || box.dataset.wiChallenge === '1');
        this.captcha?.addEventListener('wi-recaptcha-change', (event) => {
            if (this.challenge) {
                this.lockButtons(!event.detail?.token);
            }
        });
        if (this.challenge) {
            this.lockButtons(true);
        }
```

Il metodo `click(event)` intero diventa:

```js
    // Stripe vuole la risposta entro un secondo: il foglio si apre subito, il carrello a parte nasce intanto.
    // L'ospite passa dal reCAPTCHA: il token v3 si chiede dopo `resolve`, la casella va spuntata prima.
    click(event) {
        if (this.root.classList.contains('wi-express-locked')) {
            // Senza `resolve` il foglio non si apre: prima la casella.
            this.say(this.labels.captcha);
            return;
        }

        this.say('');
        event.resolve({
            emailRequired: true,
            phoneNumberRequired: true,
            shippingAddressRequired: true,
            billingAddressRequired: true,
            shippingRates: [{ id: 'pending', displayName: this.labels.pending_rate, amount: 0 }],
        });

        const data = { source: this.config.source };

        if (this.config.source === 'product') {
            // Variante e quantità sono quelle scelte nel modulo «Aggiungi».
            const form = document.querySelector('form[data-ecommerce-add-to-cart]');

            data.product_id = form?.querySelector('[name="product_id"]')?.value || this.config.product_id;
            data.quantity = form?.querySelector('[name="quantity"]')?.value || '1';
        }

        this.started = this.verify(data)
            .then((values) => {
                if (!values) {
                    // Nessun token: la casella è aperta e non spuntata.
                    return { ok: false, data: { status: 618, message: this.labels.captcha } };
                }

                return this.post('start', values).then((reply) => {
                    // Il token della casella vale una volta: si rinnova per il prossimo avvio.
                    if (values['g-recaptcha-type'] === 'checkbox') {
                        window.wiRecaptchaReset?.(this.captcha);
                    }

                    return reply;
                });
            })
            .then((reply) => {
                this.total = reply.ok ? reply.data.total : null;
                this.startError = reply.ok ? '' : reply.data.message || '';

                // Punteggio basso: la casella si apre sotto i bottoni, che aspettano la spunta.
                if (reply.data?.status === 618) {
                    this.challenge = true;
                    window.wiRecaptchaChallenge?.(this.captcha);
                    this.lockButtons(true);
                    this.say(this.labels.captcha);
                }

                return reply;
            });
    }

    // Il token reCAPTCHA dell'ospite per `start`; null se la casella è aperta e non spuntata.
    async verify(data) {
        if (!this.captcha || typeof window.wiRecaptchaToken !== 'function') {
            return data;
        }

        const answer = await window.wiRecaptchaToken(this.captcha).catch(() => null);

        return answer ? { ...data, 'g-recaptcha-token': answer.token, 'g-recaptcha-action': answer.action, 'g-recaptcha-type': answer.type } : null;
    }

    // Bottoni spenti finché la casella non è spuntata (vedi express.css).
    lockButtons(on) {
        this.root.classList.toggle('wi-express-locked', on);
    }
```

(Il gestore dell'indirizzo legge già `this.startError`: col 618 il foglio rifiuta con il messaggio della sfida.)

In `cancel()`, dopo `this.post('cancel', {});`:

```js

        // Il cliente ritrova la casella aperta sotto i bottoni.
        if (this.challenge) {
            this.say(this.labels.captcha);
        }
```

- [ ] **Passo 6: CSS e testi**

In fondo a `resources/assets/css/express.css`:

```css
/* Ospite con la casella reCAPTCHA a vista: i bottoni aspettano la spunta. */
.wi-express.wi-express-locked [data-express-element] {
    pointer-events: none;
    opacity: 0.5;
}
```

In `lang/it/ecommerce.json`, dentro `checkout`, dopo `express_unverified`:

```json
        "express_captcha": "Conferma di non essere un robot qui sotto, poi riprova.",
```

In `lang/en/ecommerce.json`, stesso posto:

```json
        "express_captcha": "Confirm you are not a robot below, then try again.",
```

- [ ] **Passo 7: i test passano**

Run: `php tests/ExpressRecaptchaTest.php && php tests/CheckoutStripeTest.php && php -l src/Frontend/Checkout/ExpressCheckout.php && php -l view/components/checkout/express.php && node --check resources/assets/js/checkout.js`
Expected: `28 test, 0 falliti`; `CheckoutStripeTest` con `0 falliti`; due `No syntax errors detected`; `node --check` senza output.

- [ ] **Passo 8: commit**

```bash
git add src/Frontend/Checkout/ExpressCheckout.php view/components/checkout/express.php resources/assets/js/checkout.js resources/assets/css/express.css lang/it/ecommerce.json lang/en/ecommerce.json tests/ExpressRecaptchaTest.php
git commit -m "Express: riquadro reCAPTCHA dell'ospite, token a start, casella sul 618

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Compito E6: il test HTTP dell'express e la suite completa

**File:**
- Modifica: `tests/integrazione/ExpressHttpTest.php`

**Interfacce:**
- Consuma: le risposte di `start` da E2.
- Produce: niente.

Il test HTTP parla col sito di prova, che carica l'ecommerce di `packages/ecommerce` (link simbolico in `vendor/wonder-image/ecommerce`), non quello del worktree: finché il ramo non è unito in locale `ExpressVerification` lì non c'è e il test gira come oggi. Il ramo nuovo si prova davvero al compito E7.

- [ ] **Passo 1: il test**

In `tests/integrazione/ExpressHttpTest.php`, fra gli `use`:

```php
use Wonder\Plugin\Ecommerce\Frontend\Checkout\ExpressVerification;
```

dopo `$ospite = GuestCheckout::enabled();`:

```php
// Con la verifica dell'ospite l'avvio vuole un token reCAPTCHA, che da qui non si può avere.
$verifica = class_exists(ExpressVerification::class);
```

subito prima di `if ($ospite) {` (il blocco del prodotto):

```php
if ($ospite && $verifica) {
    $primo = ((array) sqlSelect('gst_products', ['active' => 'true'], 20, 'id', 'ASC')->row)[0] ?? [];
    [$status, $risposta] = $request('/checkout/express/start/', ['csrf_token' => $csrf, 'source' => 'product', 'product_id' => $primo['id'] ?? 0, 'quantity' => '1']);
    check('start da un prodotto senza token reCAPTCHA: 422 con un messaggio', fn () =>
        $status === 422 && is_array($risposta) && ($risposta['success'] ?? null) === false && ($risposta['message'] ?? '') !== '');
}

```

e `if ($ospite) {` diventa `if ($ospite && !$verifica) {`.

I controlli di prima restano veri: `shipping` e `place` senza carrello rapido danno ancora 409 (il segno si guarda dopo il carrello), `start` dal carrello vuoto dà 422 con un messaggio (ora dalla guardia, 617).

- [ ] **Passo 2: il test**

Run: `php tests/integrazione/ExpressHttpTest.php`
Expected: `0 falliti` (o «Saltato: … non risponde.» se il sito di prova è spento). Qui gira ancora il blocco di oggi: `$verifica` è falso.

- [ ] **Passo 3: suite completa**

Run (prima `ps aux | grep "php .*Test.php"`: nessun'altra suite in corsa):

```bash
WONDER_NO_MAIL=1 php tests/run.php
```

Expected: «Tutti i test dell'ecommerce passano.»

- [ ] **Passo 4: commit**

```bash
git add tests/integrazione/ExpressHttpTest.php
git commit -m "Test HTTP dell'express: l'ospite senza token reCAPTCHA non avvia

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Compito E7: STOP — revisione, unione e prova nel browser

Niente codice. Ogni passo che tocca `packages/`, il remoto o il sito di prova vuole il via dell'utente, uno per volta.

- [ ] **Passo 1: revisione finale** del ramo `recaptcha-v3` di app ed ecommerce (e della lib, se non già unita) con i «Punti da guardare» in testa al piano.

- [ ] **Passo 2: col via dell'utente, unione in locale.** Ordine: app, poi ecommerce. `packages/app` è su un ramo di un'altra sessione (`feat/sedi-google-sync`): prima di unire chiedere all'utente come fare (aspettare che quel ramo finisca, o unire `recaptcha-v3` su `main` in un worktree e spostare lì il sito di prova). Mai cambiare ramo in `packages/`.

- [ ] **Passo 3: test HTTP sul codice nuovo.** Con l'ecommerce unito in `packages/ecommerce` (e l'app con A1–A8 nel sito di prova): `php tests/integrazione/ExpressHttpTest.php` deve passare dal blocco `$ospite && $verifica` (422 senza token). Poi `WONDER_NO_MAIL=1 php tests/run.php`.

- [ ] **Passo 4: lib nel sito di prova.** Dopo il rilascio di alpha.27: `cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && npm run postinstall` (mai composer nel sito di prova, mai toccare i link di `vendor/wonder-image/*`).

- [ ] **Passo 5: prova nel browser dell'utente** (login e casella li fa l'utente):
  - checkout da ospite con la sola v3: «Paga» senza clic; badge o attribuzione secondo **Badge v3**;
  - con la chiave v3 di prova che dà punteggio basso: dopo «Paga» compare la casella nel riquadro del modulo e il messaggio 618; spuntata, «Paga» passa;
  - express da ospite (scheda prodotto e checkout): il foglio si apre subito (`resolve` entro 1 s), poi il token v3; col punteggio basso il foglio mostra l'errore, chiuso si vede la casella sotto i bottoni spenti; spuntata, i bottoni tornano e il pagamento passa;
  - solo casella v2: i bottoni express spenti finché non si spunta; dopo un foglio chiuso vanno rispuntati.

- [ ] **Passo 6: PR e push** solo col via dell'utente: una PR per repo (lib, app, ecommerce) con merge commit su `main`, poi rami remoto e locale e worktree eliminati.
