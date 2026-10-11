# reCAPTCHA v3 con la casella di riserva — design

Data: 2026-10-11 · Rami per il lavoro: `recaptcha-v3` (lib, app, ecommerce), ciascuno nel suo
worktree; l'app parte da `origin/main`. Spec e `TODO.md` restano su main in gestionale.

Riferimenti: revisione del checkout in `TODO.md` («Revisione del checkout (2026-10-11)», S1),
spec della guardia `app/docs/superpowers/specs/2026-06-18-recaptcha-guard-design.md`, spec del
pagamento `2026-10-09-pagamento-stripe-design.md` §11 (Express Checkout).

## 1. Obiettivo

Proteggere i form pubblici e il pagamento rapido degli ospiti senza far cliccare «Non sono un
robot» a chi è umano. reCAPTCHA v3 lavora in background e dà un punteggio; solo se il punteggio
è basso compare la casella v2 nel punto in cui si stava lavorando. Se Google non risponde, non si
blocca nessuno.

Criteri di riuscita:

- con la chiave a punteggio, login, registrazione, recupero password, checkout ospite e
  pagamento rapido ospite passano senza casella quando il punteggio è alto;
- con punteggio basso e le due chiavi, compare la casella con un messaggio; spuntata, l'invio
  passa;
- con la sola chiave a punteggio il punteggio basso blocca (617); con la sola chiave a casella
  tutto funziona come oggi;
- il pagamento rapido di un ospite non crea ordini senza una verifica fatta all'avvio (chiude S1);
- se Google è giù o ha finito la quota, la verifica si salta e resta una riga nel log;
- dal backend si nasconde il badge di v3; al suo posto compare la dicitura che Google chiede;
- nessun test chiama Google vero.

## 2. Fuori

- Verifica via email o SMS al posto della casella: va in `TODO.md`.
- Soglia del punteggio dal backend: resta nel codice (`->minScore()`, 0.5).
- Limite di richieste per IP (vedi il rischio in §8).
- I componenti vecchi (`Plugin\Custom\Input\reCAPTCHA`, `function/frontend/plugin/reCAPTCHA.php`)
  restano solo a casella e leggono `g_recaptcha_site_key` come oggi.
- `formSubmit` della lib passa `undefined` al callback dopo un errore: difetto vecchio, va in
  `TODO.md`.
- A1 e A2 della revisione restano in `TODO.md`.

## 3. Decisioni prese

| # | Decisione |
|---|-----------|
| R1 | Due chiavi Enterprise nello stesso progetto GCP, con la stessa API key: quella a casella che c'è già (`g_recaptcha_site_key`) e una nuova a punteggio (`g_recaptcha_score_site_key`, env `G_RECAPTCHA_SCORE_SITE_KEY`). |
| R2 | La modalità la decidono le chiavi configurate (§4). Nessun interruttore in più. |
| R3 | La riserva è senza stato: il client manda `g-recaptcha-type` (`score` o `checkbox`) e il server verifica il token con la chiave di quel tipo. Un bot che salta v3 deve comunque risolvere la casella. |
| R4 | Punteggio basso con le due chiavi: codice nuovo **618** («Conferma di non essere un robot») e compare la casella. Con la sola chiave a punteggio: 617. |
| R5 | Soglia 0.5, cambiabile per chiamata con `->minScore(float)`. |
| R6 | La guardia confronta anche l'action che restituisce Google con quella attesa, in tutte e due le modalità. |
| R7 | Si salta la verifica (fail-open, scelta dell'utente) quando Google non dà un giudizio sul token: errori di rete, timeout, HTTP 429, 5xx e gli altri errori. Si blocca solo col token mancante, quando Google ha letto il token e lo giudica male, o con HTTP 400 (§8). Vale per v2 e v3. |
| R8 | Interruttore in *Sicurezza* per mostrare o nascondere il badge di v3, acceso di default. Col badge nascosto ogni punto protetto mostra la dicitura di Google con i due link. |
| R9 | Pagamento rapido: si verifica a `start` (action `ecommerce_express`), solo per gli ospiti; `place` rifiuta un ospite senza il segno di verifica del suo carrello rapido. Il rapido resta visibile agli ospiti. |
| R10 | 617 e 618 non fanno più da codice HTTP: le risposte JSON usano 422 e portano il codice nel corpo (`status`). |

## 4. Modalità

| Chiavi configurate | Chi compila vede | Punteggio basso |
|---|---|---|
| solo casella | la casella, come oggi | — |
| solo punteggio | niente (badge o dicitura) | 617, bloccato |
| tutte e due | niente (badge o dicitura) | 618, compare la casella |
| nessuna | come oggi | — |

## 5. App

### 5.1 Configurazione

- Modello `Config/Security`: `Column::key('g_recaptcha_score_site_key')` e
  `Column::key('g_recaptcha_badge')->default('true')`, con i due `Field` come gli altri.
- `SecurityResource`, sezione «Google reCAPTCHA*»: «Chiave Sito v2 (casella)» (è la «Chiave Sito»
  di oggi), «Chiave Sito v3 (punteggio)», «Badge v3». Il badge è fatto come `stripe_test`:
  `->select(['true' => 'Visibile', 'false' => 'Nascosto'])->required()`. Spiegazioni nei tooltip,
  non in testi sotto i campi.
- `Credentials::api()`: `envOrRow('G_RECAPTCHA_SCORE_SITE_KEY', …)` e
  `envOrRow('G_RECAPTCHA_BADGE', …)`, con default `''` e `'true'`.
  `Plugin/Google/Credentials` espone `recaptcha_score_site_key`.
- `Console/Commands/Config.php`: la nuova env va nell'elenco delle chiavi dev condivise, accanto a
  `G_RECAPTCHA_SITE_KEY`.
- `docs/app/servizi/recaptcha.md`: le due chiavi, il badge, il 618, il fail-open.

### 5.2 Motore (`Plugin/Google/Security/reCAPTCHA`)

- `verify(string $token, string $action, ?string $siteKey = null): object`. Senza `$siteKey` usa
  la chiave a casella, come oggi.
- Chiama con `Call::call()`, `connectTimeout(3)` e `timeout(5)`. Il codice HTTP arriva da un
  metodo nuovo del core, `Api/Call::httpCode()` (`CURLINFO_HTTP_CODE`, 0 se la richiesta non è
  partita).
- Restituisce `valid` (bool), `score` (float|null), `action` (string|null), `available` (bool),
  `httpCode` (int) e `result` (array, come oggi).
- `available` è vero solo con HTTP 200 e un corpo JSON, oppure con HTTP 400 (allora `valid` è
  falso). Tutto il resto (errore di rete, timeout, 401, 403, 404, 429, 5xx, corpo illeggibile)
  dà `available = false`.

### 5.3 Guardia (`App/Security/RecaptchaGuard`)

`verify()`, in quest'ordine:

1. token o action mancanti → `missing`, 617. Vale anche con Google giù.
2. tipo da `g-recaptcha-type`; se manca è `checkbox` (form vecchi). Un tipo la cui chiave non è
   configurata → `invalid`, 617.
3. action del client diversa da quella attesa → `action_mismatch`, 617 (come oggi).
4. motore con la chiave del tipo. Un'eccezione → `engine_error`, log ERROR, **passa**.
   `available = false` → `engine_unavailable`, **passa**: log WARNING per rete, timeout, 429,
   5xx e corpo illeggibile; ERROR per gli altri codici, che chiedono di sistemare chiave o
   progetto.
5. `valid` falso → `invalid`, 617.
6. action di Google diversa da quella attesa → `action_mismatch`, 617.
7. solo tipo `score`: punteggio sotto `minScore` → `low_score`. Con la chiave a casella
   configurata lancia `RecaptchaChallenge` (618), altrimenti 617.

Il resto:

- `RecaptchaChallenge extends RuntimeException`, costante `CHALLENGE_CODE = 618`, messaggio
  `notifications.618.text`. Chi oggi cattura il 617 non cambia.
- Col 618 la guardia scrive in sessione che l'action va sfidata.
  `RecaptchaGuard::challenged(string $action): bool` legge il segno e lo toglie; lo usa il
  componente. Funziona sia quando il form si ridisegna nella stessa richiesta sia dopo un
  redirect. Una verifica riuscita toglie il segno.
- `passes()` resta: è vero anche quando la verifica si salta.
- Il log aggiunge `type`, `score` e `http_code`. Mai il token, mai le chiavi.

### 5.4 Componente (`Themes/Wonder/Form/Components/reCAPTCHA`)

- `div.g-recaptcha` con `data-wi-site-key` (casella, se c'è), `data-wi-score-key` (punteggio, se
  c'è), `data-wi-badge` (`true|false`), `data-wi-challenge="1"` quando
  `RecaptchaGuard::challenged($action)`, più `data-wi-theme`, `data-wi-size` e `data-wi-action`
  come oggi.
- Campi nascosti: `g-recaptcha-token`, `g-recaptcha-action` e il nuovo `g-recaptcha-type`.
  `required` solo in modalità casella o con la sfida aperta: altrimenti il controllo dei form del
  core spegnerebbe l'invio prima che la lib metta il token.
- Con la chiave a punteggio e il badge nascosto, sotto il componente c'è la dicitura di Google
  con i link a `https://policies.google.com/privacy` e `https://policies.google.com/terms`,
  tradotta nei file di lingua dell'app (5 lingue).
- `Elements/Form/Components/reCAPTCHA` e `ResourceSchema/Inputs/InputReCaptcha` non cambiano
  firma: la modalità la sceglie il componente leggendo le credenziali.

### 5.5 Dove arriva il 618

- `AuthController::verifyRecaptcha()` gestisce anche il 618 (`$ALERT = 618`, `return false`): il
  form si ridisegna con la casella aperta. Login, registrazione e recupero password non cambiano
  altro.
- `http/api/resource/index.php` e `Api/Handler::sendError`: per 617 e 618, HTTP 422 con corpo
  `{success: false, status: 617|618, response: <messaggio>}`. Un codice d'eccezione fuori da
  400–599 non diventa più il codice HTTP: si usa 500.
- `resources/lang/*/notifications.json` (5 lingue): voce `618`.

## 6. Lib

### 6.1 Caricamento e badge

- `form/recaptcha.js` cerca `.g-recaptcha` dentro o fuori da un form. Lo script Enterprise si
  carica una volta sola per pagina: con una chiave a punteggio
  `enterprise.js?render=<chiave>&onload=…`, altrimenti `render=explicit` come oggi. Il
  caricamento resta pigro: vicino alla vista, al focus, al primo tocco.
- Con `data-wi-badge="false"` la lib mette la classe `wi-recaptcha-badge-off` su `<html>`. Il CSS
  della lib nasconde `.grecaptcha-badge` (`visibility: hidden`) sotto quella classe.

### 6.2 Invio col punteggio

- Un ascolto `submit` in cattura su `document` vede i form con un `.g-recaptcha` a punteggio e
  senza sfida aperta. Ferma l'invio (`preventDefault` e `stopImmediatePropagation`), aspetta lo
  script (al massimo 5 s) e chiama `grecaptcha.enterprise.execute(chiave, {action})`. Scrive
  token, action e `type=score`, poi richiama `form.requestSubmit(event.submitter)` una volta sola,
  con un segno sul form per non rientrare. I gestori del sito (checkout, `formSubmit`, onsubmit)
  ricevono così il form già col token e non cambiano.
- Ogni invio prende un token nuovo: un token vale una volta e per 2 minuti.
- Se lo script non arriva o `execute` fallisce: con la chiave a casella apre la sfida; senza,
  invia senza token e il server risponde 617.

### 6.3 Sfida e funzioni pubbliche

- `wiRecaptchaChallenge(scope?)` disegna la casella (`grecaptcha.enterprise.render` con la chiave
  a casella) nel `.g-recaptcha` di `scope` (un form o un elemento). Senza argomento usa l'ultimo
  che ha preso un token a punteggio. Da lì il tipo è `checkbox` e i campi diventano `required`.
- `data-wi-challenge="1"` all'avvio vuol dire sfida già aperta: il server ha risposto 618 a un
  invio classico.
- `wiRecaptchaToken(scope)` restituisce una Promise con `{token, action, type}`, oppure `null` se
  la sfida è aperta e la casella non è spuntata. Serve ai flussi che non passano da un submit (il
  pagamento rapido).
- `wiRecaptchaReset(scope)` svuota i campi e rinnova la casella, se è aperta.
- Quando il token cambia, la lib emette `wi-recaptcha-change` sul `.g-recaptcha`.

### 6.4 Risposte JSON

- `fetch.js` (`API_CLIENT.post`): con una risposta non 2xx prova a leggere il JSON. Se c'è
  `status` segue la strada di oggi (`alertToast(status)`); se non c'è, 802 come oggi. Con
  `status` 618 chiama anche `wiRecaptchaChallenge()`.
- Rilascio: lib 2.1.2-alpha.27, con l'ok dell'utente. Poi l'app alza il minimo della lib.

## 7. Ecommerce

### 7.1 Checkout a pagina unica

- Il token lo mette la lib (§6.2) prima che `guardSubmit` veda l'invio: il flusso non cambia.
- `CheckoutController`: con `RecaptchaChallenge` la risposta JSON aggiunge `status: 618`.
  `checkout.js`, in `place()`, chiama `wiRecaptchaChallenge(this.form)` e mostra il messaggio.
  Senza JSON (pagamenti non Stripe) il form si ridisegna con la casella aperta, grazie al segno in
  sessione (§5.3).
- `resetRecaptcha()` usa `wiRecaptchaReset(this.form)`.

### 7.2 Pagamento rapido

- Per gli ospiti, con almeno una chiave configurata, il blocco del rapido (checkout, carrello,
  scheda prodotto) mette il componente reCAPTCHA (action `ecommerce_express`) sotto i bottoni,
  fuori da ogni form. Col badge nascosto la dicitura sta lì.
- `click()`: dopo `event.resolve`, `await wiRecaptchaToken(el)`; i tre campi vanno nel
  `post('start', …)`.
- `ExpressController::start`, per l'ospite, prima di creare il carrello rapido:
  `RecaptchaGuard::for('ecommerce_express')->withService('ecommerce-express')->withLogAction('start')->verify()`.
  Se passa, anche saltata, `ExpressVerification::mark($cartId)` segna in sessione quel carrello
  rapido come verificato.
- `place`: un ospite senza il segno per quel carrello riceve 403 con un messaggio e nessun
  ordine. Il segno si toglie quando il carrello diventa ordine, con `cancel` e al `start`
  successivo. Un secondo tentativo di pagamento nello stesso foglio lo trova ancora.
- 618 a `start`: risposta `{success: false, status: 618, message}`, e `address()` rifiuta col
  messaggio. Quando il foglio si chiude, il rapido apre la sfida sotto i bottoni e li spegne (una
  classe sul contenitore) finché la casella non è spuntata (`wi-recaptcha-change`). Il clic dopo
  manda il token della casella.
- Via il commento «Niente reCAPTCHA: il wallet ha già riconosciuto il cliente.» in
  `ExpressController.php:20`.

## 8. Errori e fail-open

| Esito della verifica | Cosa succede |
|---|---|
| token mancante | 617, anche con Google giù: altrimenti un bot non lo manderebbe apposta |
| tipo `checkbox` con la sola chiave a punteggio | 617 (`invalid`), niente declassamento |
| HTTP 200, token valido, action giusta, punteggio sopra soglia | passa |
| HTTP 200, token non valido o action diversa | 617 |
| HTTP 200, punteggio basso | 618 o 617 (§4) |
| HTTP 400 | 617: il contenuto del token lo sceglie chi invia |
| rete, DNS, timeout, HTTP 429, 5xx, corpo illeggibile | passa, log WARNING `engine_unavailable` |
| HTTP 401, 403, 404 e gli altri 4xx | passa, log ERROR `engine_unavailable` |
| eccezione del motore | passa, log ERROR `engine_error` |

- Rischio, scelta dell'utente: chi vuole saltare la verifica può provare a finire la quota di
  Google con molte richieste; finché Google risponde 429 i form passano senza controllo.
- Lo script di Google bloccato nel browser (adblock, rete): niente token, quindi sfida o 617
  (§6.2). Non è fail-open: lo deciderebbe il client.
- Due schede aperte: ogni invio prende il suo token.
- Un form AJAX che non usa la lib riceve 422 con `status: 618` e decide lui.

## 9. Prove

App, col motore finto di `withEngine`:

- punteggio alto passa; basso con le due chiavi dà 618 e `challenged()` vero; basso con la sola
  chiave a punteggio dà 617;
- tipo senza la sua chiave, action di Google diversa e HTTP 400 danno 617;
- 429, 5xx, rete, 401 ed eccezione passano, col motivo e il livello di log giusti;
- token mancante con Google giù dà 617; `minScore()` cambia la soglia;
- motore: esiti classificati da un `Call` finto; `Call::httpCode()` provato con `php -S` su
  127.0.0.1 e uno script che risponde 429 (niente rete esterna);
- componente: attributi nelle quattro modalità, `required`, dicitura col badge nascosto,
  `data-wi-challenge`;
- API: 617 e 618 danno HTTP 422 col codice nel corpo; un codice fuori da 400–599 dà 500;
- `AuthController` col 618 imposta `$ALERT = 618`.

Lib (`node --test`, `grecaptcha` finto, come `recaptcha-loading.test.cjs`):

- script con `render=<chiave>` o `render=explicit`;
- invio fermato una volta e rimandato col token; un token per invio;
- sfida dal 618 e da `data-wi-challenge`; `execute` che fallisce apre la sfida o invia vuoto;
- classe del badge; `fetch.js` legge il corpo di un 422 e apre la sfida col 618.

Ecommerce:

- prove dirette di `ExpressVerification` (segna, controlla, toglie);
- statiche su `ExpressController`: guardia a `start` solo per l'ospite, `place` che rifiuta
  senza segno;
- statiche su `checkout.js`: `wiRecaptchaToken` nel `click`, `wiRecaptchaReset`, sfida al 618;
- suite completa con `WONDER_NO_MAIL=1`.

Browser, a cura dell'utente: le chiavi a punteggio create in GCP, più una seconda chiave di prova
con il punteggio di test fissato basso, per vedere comparire la casella.

## 10. Ordine di lavoro

1. lib, ramo `recaptcha-v3`: prove, poi rilascio 2.1.2-alpha.27 con l'ok dell'utente.
2. app, ramo `recaptcha-v3` da `origin/main`: minimo della lib a alpha.27, prove.
3. ecommerce, ramo `recaptcha-v3`: prove.
4. Sito di prova: `npm run postinstall` (niente composer).
5. Revisione finale, prova nel browser dell'utente, poi PR con merge commit. Niente push, PR o
   rilascio senza l'ok dell'utente.
