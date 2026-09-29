# E1a — Negozio online: guscio del modulo

- **Sotto-progetto:** E1a, prima fetta di E1 (negozio online); le fette dopo sono
  E1b (vetrina e scheda prodotto pubblica) e E1c (carrello, checkout, ordini
  nell'area cliente), che dipendono da G4
- **Stato:** da approvare
- **Documento di riferimento:** [architettura](2026-09-11-gestionale-ecommerce-architettura-design.md)
  §2.3, §4.4, §5.1, §7, §10.2, §10.3
- **Dipende da:** G1 Fondamenta; G2 per catalogo e anagrafiche; `wonder-image/app`
  `^2.4.0-beta.1`
- **Non dipende da:** G4 Ordini e pagamenti, che resta il percorso critico della
  consegna

## Contesto

D61 fissa la consegna del primo negozio al 2026-10-29 e mette E1 dopo G4, apribile
in parallelo a G5–G7. G4 è cominciato il 2026-09-29 e occupa il percorso critico.

E1a anticipa di E1 soltanto ciò che non tocca G4: il pacchetto, il suo collegamento
al sito di prova, il guscio delle pagine e l'impianto degli account, che poggia sul
permesso `frontend.client` già presente nel core. Nessuna tabella nuova: i clienti
sono `gst_contacts` di G2, gli ordini saranno `gst_orders` di G4.

## Obiettivo

Alla fine di E1a esiste `wonder-image/ecommerce` installato su
`boilerplates/ecommerce-site`, con:

- lo scheletro del modulo sul modello del gestionale, test e GitHub Actions verdi;
- i layout del modulo agganciati a quelli del sito, che resta padrone di header e
  footer;
- il meccanismo delle view sigillate e dei loro slot;
- gli account dei clienti: registrazione, verifica email, accesso, recupero
  password, con la scheda del gestionale collegata.

## Non obiettivi

| Fuori da E1a | Dove |
|---|---|
| Vetrina, catalogo pubblico, scheda prodotto, SEO delle pagine prodotto | E1b |
| Carrello, checkout, pagamenti, webhook | E1c, dopo G4 |
| Ordini, resi e richieste di reso nell'area cliente | E1c, dopo G4 |
| Registrazione a fine ordine (§5.1) | E1c, dopo G4 |
| Accesso con Google e Apple | dopo il primo rilascio, con `AuthFederated` (§10.4) |
| Abbonamenti nell'area cliente | E3 |
| Condizioni di vendita e testi legali | E1c, con il checkout (§8.5) |

## Design

### 1. Il pacchetto

`packages/ecommerce`, sul modello del gestionale.

| | |
|---|---|
| composer | `wonder-image/ecommerce`; richiede `php ^8.2`, `wonder-image/app ^2.4.0-beta.1 \|\| dev-main`, `wonder-image/gestionale @dev`; `repositories` di tipo `path` verso `../app` e `../gestionale` con symlink |
| `module.json` | slug `ecommerce`, namespace `Wonder\Plugin\Ecommerce\`, entrypoint `Wonder\Plugin\Ecommerce\Ecommerce`, `dependencies.modules: ["gestionale"]` |
| rotte | `config/routes/route.frontend.php`: è il primo modulo del gestionale con rotte pubbliche, il gestionale ha solo `backend` |
| permessi | `config/permissions.php`: configura `frontend.client` con i suoi hook e la verifica email |
| cartelle | `src/` (`Frontend`, `Http`, `Support`, `Extensions`, `Seeding`), `http/`, `view/` (`layout`, `components`, `pages`, `emails`), `lang/`, `config/`, `tests/` |
| repository | `wonder-image/ecommerce` su GitHub, privato |

Il modulo non aggiunge tabelle. Se in seguito servisse una tabella sua, vale la
regola dei prefissi (§4.1): prefisso `ecm_`.

### 2. Collegamento al sito di prova

`boilerplates/ecommerce-site` è già il sito del gestionale e resta uno solo: il
negozio online prova sugli stessi dati del pannello, che è il punto di avere due
pacchetti e un sito.

- `composer.json` del sito: `repositories` di tipo `path` verso
  `../../packages/ecommerce` con symlink, requisito `wonder-image/ecommerce: "@dev"`;
- `custom/config/modules.php`: `'ecommerce' => ['enabled' => true]`;
- `php forge update` e `php forge config` senza errori, `php forge modules` mostra il
  modulo valido.

Come per il gestionale, il symlink va tolto quando il sito diventerà lo starter di
D12.

### 3. Il guscio

Il sito ha già `custom/view/layout/frontend/main.php`, `minimal.php` e `auth.php`, e
il core risolve `custom/view/layout/` prima di tutto il resto
(`View::resolveLayoutPath()`). Il modulo quindi **non fornisce header e footer**: le
sue pagine si agganciano al layout del sito, come fa `wonder-image/immobili` con
`View::layout('frontend.main')`.

In mezzo il modulo mette un layout sottile suo, che chaina su quello del sito e fa il
lavoro che il sito non deve rifare: asset e dati JS del negozio, slot di testa e
piede, SEO della pagina.

| Layout del modulo | Chaina su | Pagine |
|---|---|---|
| `ecommerce.shop` | `frontend.main` | vetrina, catalogo, scheda prodotto, carrello, checkout, area cliente |
| `ecommerce.auth` | `frontend.minimal` | accesso, registrazione, verifica email, recupero password |

Il checkout resta su `frontend.main` come il resto del negozio (E1a.4).

**Componenti innestabili.** Il modulo espone componenti che il sito richiama dove
vuole nel suo header e nel suo footer: mini-carrello, ricerca del catalogo, menu
dell'account, avvisi. Sono guscio, quindi pubblicabili e personalizzabili.

### 4. View sigillate e slot

Revisione di §2.3 e §7, che oggi dichiarano pubblicabili tutte le pagine del modulo.

| Parte | Di chi è | Sigillata |
|---|---|---|
| `layout/frontend/main·minimal·auth` (header, footer, menu, logo) | sito | no, è suo |
| Componenti per header e footer (mini-carrello, ricerca, menu account, avvisi) | modulo | no |
| Layout sottili `ecommerce.*` | modulo | no |
| Pagine del carrello, del checkout e dell'area cliente | modulo | **sì** |
| Pagine di autenticazione | modulo | **sì** |
| Vetrina, catalogo, scheda prodotto | modulo | da decidere in E1b |

**Come si sigilla.** L'override delle view di un modulo lo fa il modulo, non il core:
in immobili è `viewPath()`, che usa `custom/modules/immobili/view/<path>` se il file
esiste. Quindi:

1. `module.json` dichiara l'elenco: `views.sealed: ["pages/cart", "pages/checkout", "pages/account", "pages/auth"]`;
2. `Ecommerce::viewPath()` non consulta l'override per quei percorsi;
3. nel core, `publish:module` legge l'elenco e salta quei file con un avviso —
   altrimenti copia file che nessuno userà e chi li modifica non capisce perché non
   cambia niente. Lavoro dei preparatori (§10.2), non blocca E1a.

**Slot.** Le view sigillate dichiarano pochi punti d'innesto stabili, che il sito
riempie con proprie partial dalla configurazione del modulo. Sono contratto pubblico
come i dati delle view: documentati uno per uno, e i cambi vanno nel changelog. Gli
slot di E1a sono quelli delle pagine di autenticazione e dell'area cliente; quelli di
carrello e checkout nascono con E1c.

Gli slot aggiungono markup; per i dati e per i campi restano gli hook già previsti in
§7 (`checkoutFields()`, `productViewData()`, `seo()`).

### 5. Account dei clienti

Il core ha già l'impianto (`docs/app/concetti/utenti/permessi-client.md`): permesso
`frontend.client`, hook `validateClient` / `client` / `infoClient`,
`authenticateUser()`, verifica email con token, `RememberMe`, policy della password.
Al modulo tocca configurare il permesso, cablare gli hook e fare le pagine, che il
core per il frontend non ha (§2.3).

| Percorso | Pagina | Cosa fa |
|---|---|---|
| `/account/auth/login/` | Accesso | email e password, "ricordami", link a registrazione e password dimenticata |
| `/account/auth/signup/request/` | Registrazione | crea l'utente `frontend.client`; l'hook `client()` crea o collega la scheda |
| `/account/auth/email-verification/send/` | Link inviato | attesa con reinvio |
| `/account/auth/email-verification/verify/` | Verifica | consuma il token con `confirmUserVerificationToken()` |
| `/account/auth/password/restore/` | Password dimenticata | chiede l'email e manda il link |
| `/account/auth/password/recovery/` · `/set/` | Nuova password | verifica il token, imposta la password |
| `/account/auth/logout/` | Uscita | |

I segmenti degli URL stanno in `lang/`, quindi traducibili e sovrascrivibili dal
sito. `clients/spingy/projects/spingy-it/account/auth/` è il riferimento del flusso
già in produzione.

**Verifica email obbligatoria** (`verification.email.required = true`): la richiede
§4.4, perché una scheda creata dall'ufficio o da un ordine da ospite si collega
all'account solo dopo la verifica.

**Collegamento alla scheda.** Alla verifica, l'hook cerca in `gst_contacts` una
scheda con quella email:

| Caso | Esito |
|---|---|
| nessuna scheda | ne crea una nuova con `user_id` |
| scheda senza `user_id` | la collega |
| scheda con un altro `user_id` | l'account resta senza scheda e il caso finisce in "Da controllare" del gestionale: è la spia di un'email condivisa o di un doppione da unire a mano |

**Consensi:** tabelle dei consensi del core, privacy obbligatoria e newsletter
facoltativa; nessuna tabella nel gestionale (§4.4).

**Area cliente in E1a:** profilo, indirizzi (`gst_contact_addresses`), consensi,
cambio password. Ordini e resi arrivano con E1c: in E1a le loro voci non esistono
ancora, invece di esistere vuote.

### 6. Decisione aperta

**Campi della registrazione.** Da fissare prima del piano delle pagine di
autenticazione. Le opzioni sono: minimo (nome, cognome, email, password, consensi),
minimo più telefono, o scheda fiscale completa. Il resto del design non cambia:
cambia solo il form e la validazione di `validateClient`.

### 7. Test

Come nel gestionale: harness del core, database `ecommerce_site` con le modifiche
annullate dalle transazioni (G1.11), GitHub Actions con unitari e convenzioni,
`config.platform.php` a 8.2.

Prove di E1a: modulo valido e rotte pubbliche raggiungibili; layout che chaina
davvero su quello del sito; override ignorato su una view sigillata e rispettato su
una non sigillata; giro completo di registrazione, verifica, accesso, uscita e
recupero password nel browser; i tre casi del collegamento alla scheda.

### 8. Piani previsti

1. **Pacchetto e collegamento:** scheletro, repository, sito di prova, test e
   Actions, comando dei dati di prova se serve.
2. **Guscio:** layout, componenti per header e footer, sigillatura e slot, più la
   modifica di `publish:module` nel core.
3. **Account:** permesso, hook, pagine di autenticazione, collegamento alla scheda,
   area cliente con profilo, indirizzi, consensi e password.

## Decisioni

| # | Decisione |
|---|---|
| E1a.1 | E1 si apre prima di G4 solo con le parti che non lo toccano: pacchetto, guscio, account. Carrello, checkout e ordini restano a E1c, dopo G4 |
| E1a.2 | Pacchetto `wonder-image/ecommerce`, slug `ecommerce`, namespace `Wonder\Plugin\Ecommerce\`, dipendente dal modulo `gestionale`; repository privato; nessuna tabella nuova |
| E1a.3 | Sito di prova unico: `ecommerce-site` con entrambi i moduli abilitati e il pacchetto in symlink |
| E1a.4 | Header e footer sono del sito. Le pagine del modulo chainano su `frontend.main`, l'autenticazione su `frontend.minimal`; il checkout resta su `frontend.main` |
| E1a.5 | Carrello, checkout, area cliente e pagine di autenticazione **non sono pubblicabili**: restano aggiornabili con `composer update`. Revisione di §2.3 e §7 |
| E1a.6 | La sigillatura è dichiarata in `module.json`, applicata da `Ecommerce::viewPath()` e rispettata da `publish:module` del core |
| E1a.7 | Le view sigillate offrono slot di markup, contratto pubblico documentato come i dati delle view; i dati e i campi restano affidati agli hook di §7 |
| E1a.8 | Gli account usano il permesso `frontend.client` del core con verifica email obbligatoria; le pagine sono del modulo |
| E1a.9 | Una scheda già collegata a un altro utente non viene riusata: l'account nasce senza scheda e il caso finisce in "Da controllare" |

## Revisioni della spec di architettura

Da riportare come D62:

- **§2.3 e §7:** le pagine dell'ecommerce non sono tutte pubblicabili. Carrello,
  checkout, area cliente e autenticazione sono sigillate e si personalizzano con il
  tema di `wonder-image/lib`, i testi dei `lang/`, gli slot e gli hook; header,
  footer e componenti del guscio restano del sito;
- **§7:** nuovo meccanismo degli slot accanto agli hook;
- **§10.2:** nei preparatori del core, `publish:module` che rispetta le view
  sigillate;
- **§10.3:** E1 diviso in E1a (guscio, prima di G4), E1b (vetrina) ed E1c (carrello,
  checkout, area cliente completa).
