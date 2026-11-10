# Commerciali: visibilità per riga

Funzionalità `sales_agents` (#77, area Vendite, richiede `orders` e
`office_sales`). Un commerciale è un utente del backend col ruolo
`sales_agent` che vede e gestisce solo i propri clienti. Il codice sta in
`Support\SalesAgents\SalesAgents`; la scheda in `Models\SalesAgents\SalesAgent`
(`gst_sales_agents`) e `Resources\SalesAgents\SalesAgentResource`.

## Chi è commerciale

`SalesAgents::restricted($user)` è vero se la funzionalità è accesa, l'utente
ha `sales_agent` e **nessun** ruolo d'ufficio (`SalesAgents::OFFICE`: `admin`,
`administrator`, `sales`). Chi ha anche `sales` lavora per tutti.

Il perimetro è la riga di `gst_sales_agents` con il suo `user_id`, attiva e non
eliminata (`idOf()`, in cache per richiesta; `forget()` la svuota dopo un
salvataggio e nei test). Un utente per scheda: lo controlla
`SalesAgentResource::mutateRequestValues`.

L'utente nasce dalla pagina «Nuovo commerciale»: il form in creazione ha
`user_name`, `user_surname`, `user_email` (non sono colonne, si tolgono prima
del salvataggio) e `SalesAgentUsers::create()` chiama `user()` di app col ruolo
`sales_agent` nell'area `backend`, poi `Wonder\Backend\Support\WelcomeMail::send()`
(link `password_set`, la mail non blocca). Un'email già di un commerciale senza
scheda si riusa; con scheda dà `sales_agent.user_taken`, con un altro ruolo
`sales_agent.user_other_role`. In modifica `user_id` è disabilitato e la
Resource lo scarta: l'utente non si cambia. Senza scheda attiva il commerciale
non vede nulla (`RowVisibility::none()`).

## Le regole

`config/boot.php` (in `module.json` → `boot.files`) chiama
`SalesAgents::register()`, che registra le regole su `Wonder\App\Visibility\RowVisibility`
del core (vedi *Visibilità per riga* nella documentazione di app). Le regole
leggono lo stato della funzionalità quando vengono chiamate e restituiscono
`null` per chi non è commerciale.

| Tabella | Visibile se |
|---|---|
| contatti | `agent_id` è suo **oppure** la persona è legata (`ContactRelation`) a un'azienda sua |
| aziende | `agent_id` è suo |
| brand delle aziende (`company_brands`) | è legato con un legame aperto (`ended_at` nullo) a un'azienda sua |
| ordini/preventivi, DDT, resi, abbonamenti | `agent_id` è suo **oppure** `customer_id` è un contatto suo **oppure** `company_id` è un'azienda sua |
| pagamenti (Scadenzario) | l'ordine o l'abbonamento è visibile **oppure** `customer_id` è un contatto suo **oppure** `company_id` è un'azienda sua |

Le condizioni sono in OR: il documento è di chi l'ha fatto (`agent_id`) e
segue anche il cliente di oggi, così il nuovo commerciale eredita lo storico.
`RowVisibility` vieta di nominare `deleted` in una regola (toglierebbe il soft
delete alla query principale), quindi nemmeno nelle sotto-query: un cliente
eliminato ma ancora assegnato apre comunque i documenti che il commerciale
vedeva già, e un legame di referente cancellato (`contact_relations.deleted`)
apre ancora la persona. Per chiuderlo serve un aiuto del core (vedi *Limiti*
sotto).

### Legami, scelte e accessi

`config/boot.php` chiama anche `ContactVisibility::register()`, che dà a
customer-account i due ganci:

- `ContactRelations::useCounterpartFilter()`: nei repeater «Referenti» e
  «Aziende» le scelte sono le schede visibili (più quelle già legate) e in
  scrittura un id non visibile si scarta (`ContactVisibility::visibleIds()`:
  nessuna query per chi vede tutto, una per chiamata per chi ha la regola);
- `ContactRelations::useAccessGuard()`: alza l'accesso all'area clienti
  (`buyer`, `admin`) solo chi vede tutte le aziende e può modificarle
  (`ContactVisibility::canGrantAccess()`); per il commerciale l'accesso resta
  quello salvato.

I select costruiti dal gestionale con le scelte di tutti (brand della scheda
azienda, brand seguiti dei referenti, brand degli indirizzi, capogruppo,
brand principale) passano da `ContactVisibility::options()`; il salvataggio
dei brand dell'azienda tiene solo quelli che il commerciale vede o ha appena
creato con la «+» (`linkableBrands()`, i nuovi in sessione); capogruppo e
brand principale non visibili si rifiutano. La «+» del brand, per lui, non
aggancia un brand con lo stesso nome che non vede (rifiuta con un messaggio
generico) e non mostra i nomi simili di altri. La pagina del brand non ha
«Aggiungi» per lui: il brand nasce dalla «+», legato alla sua azienda.
Un doppione (email, codice fiscale, partita IVA) di una scheda che non vede si
rifiuta senza nominarla (`contact.duplicate_hidden`, e nella creazione rapida).

### Limiti

- Legame cancellato in `contactsRule()`: vedi sopra. Il gancio minimo nel core
  sarebbe permettere `deleted` dentro una sotto-query esplicita (per esempio
  una regola che dichiara le sue sotto-query), lasciando il divieto sulla
  tabella principale.
- L'esistenza di un doppione resta deducibile (la creazione si rifiuta): non
  si può evitare senza creare doppioni.

Le regole valgono ovunque si legga attraverso `RowVisibility`: elenchi e schede
delle Resource (dal core), ricerche dell'ordine da ufficio (`OfficeSearch`,
`OfficeCustomerPanel`), salvataggio dell'ordine da ufficio (`OfficeOrders`),
abbonamenti d'ufficio. Codice nuovo che legge clienti o documenti per un
utente del backend deve passare da `RowVisibility::current()` (`find`,
`findById`, `count`, `visible`) o `RowVisibility::applySql()`: così fanno
`OrderPaymentResource` e `OrderActionResource`, che altrimenti aprirebbero un
ordine qualsiasi dall'id nell'indirizzo.

I **Fornitori** sono acquisti: `SupplierResource` toglie `sales_agent` da menu
e permessi con `SalesAgents::withoutAgents()`. Lo stesso fanno i riquadri della
home con dati di tutti (`AttentionWidget`, `DuePaymentsWidget`).

## Il timbro sui documenti

`SalesAgents::forDocument($document, $creator)` dà il commerciale di un
documento nuovo: quello dell'azienda se c'è `company_id`, altrimenti quello del
contatto; se il cliente non ne ha e il creatore è un commerciale, il suo. Lo
chiamano `Checkout` (ordini online), `OfficeOrders` (preventivi e ordini da
ufficio) e `OfficeSubscriptions`; gli abbonamenti online prendono quello della
scheda del cliente (`SalesAgents::forContact`). DDT e resi copiano `agent_id`
dall'ordine. Il valore resta sul
documento: spostare il cliente non lo cambia. Con la funzionalità spenta è 0.

## Clienti e aziende

Nella scheda (`CustomerResource`, persone e aziende) il campo **Commerciale**
c'è solo per l'ufficio (`agentShown()`). Quando salva un commerciale,
`agentValue()` toglie `agent_id` dalla richiesta e, in creazione, mette il suo:
il commerciale non assegna né sposta clienti.

## Elenchi, home e MCP

- `SalesAgents::filter($layout)` aggiunge il filtro «Commerciale» (`agent_id`,
  con *Nessuno* = 0) agli elenchi; non lo mostra al commerciale. È su clienti,
  aziende, preventivi, ordini e bozze.
- I riquadri della home con dati di tutto il negozio tolgono `sales_agent` con
  `SalesAgents::withoutAgents()`. `Backend\Widgets\SalesAgentWidget` («I miei
  clienti») c'è solo per il commerciale e conta con `RowVisibility::current()`.
- Il server MCP esegue ogni strumento dentro `RowVisibility::as($utente, ...)`:
  un token di un commerciale legge e scrive solo i suoi clienti
  (`ContactTools`, `QuoteTools`, ricerche).

## Permessi

Ruolo `sales_agent` in `config/permissions.php` (non un'estensione di `sales`:
la visibilità per riga nasconderebbe i dati anche all'ufficio). Aree in
`config/module.php`: `contatti` e `vendite` in `view`/`create`/`edit`,
`catalogo` in lettura; nessuna `delete`. L'area `commerciali` (gestione delle
schede) è dell'`administrator`.

## Funzionalità previste

Nel catalogo con `'created' => false`, tutte richiedono `sales_agents`:
`sales_agent_network` (rete su più livelli, usa `parent_id` della scheda),
provvigioni e provvigioni di rete (useranno `commission_percent`), sconto
massimo (`max_discount_percent`), listini, codici sconto e obiettivi dei
commerciali. Con la rete le regole dovranno includere i commerciali sotto
quello corrente: la forma resta la stessa, cambia `agent_id = N` in
`agent_id IN (...)`.

## Test

- `tests/QuickContactsTest.php` — controlli di `SalesAgentUsers::create()`
  prima del database (la creazione rapida di referenti e aziende è in
  `tests/ContactResourcesTest.php`).
- `tests/SalesAgentsTest.php` — ruolo, `restricted`, SQL delle regole,
  `none()` senza scheda, `withoutAgents`, `forDocument`.
- `tests/integrazione/SalesAgentsTest.php` — visibilità reale di contatti,
  aziende e documenti, ufficio, commerciale disattivato, timbro, scheda
  cliente e filtro.
