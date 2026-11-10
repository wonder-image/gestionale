# Più emittenti: documenti, pagamenti e fatture per ragione sociale

Data: 2026-11-10. Stato: **da approvare**, nessun codice scritto.
Repo coinvolti: gestionale (quasi tutto), customer-account (profilo sull'azienda), ecommerce (emittente del canale), app (solo se serve per `$SOCIETY`).
Issue: wonder-image/gestionale#178. Collegata a wonder-image/gestionale#109 (fatturazione elettronica, G8): le fatture nascono già con l'emittente.

## 1. Perché

Oggi il gestionale ha un solo emittente:

- tutti i PDF (preventivo, conferma, proforma, DDT) prendono l'intestazione da `DocumentData::society()`, cioè dalla società unica del core (`$SOCIETY`);
- `DocumentSequences` numera per tipo di documento, anno e mese, senza distinguere chi emette;
- conti (`gst_payment_accounts`) e metodi di pagamento (`gst_payment_methods`) sono globali;
- IVA e totali si calcolano per l'intero ordine.

Chi lavora con una rete di professionisti e aziende partner ha bisogno di altro: parte del lavoro è fatturata direttamente dal partner al cliente, parte è fatturata da chi usa il gestionale e acquistata dal partner. Ogni ragione sociale ha i suoi conti, le sue condizioni, il suo regime fiscale e la sua numerazione delle fatture. La fattura del partner può essere emessa per suo conto (con le sue credenziali del provider) oppure emessa da lui e caricata nel gestionale.

Il documento commerciale resta **uno**, di chi usa il gestionale. A dividersi è la **fatturazione**: l'emittente si decide per riga e si raccoglie nella fattura.

## 2. Principi

- **Vuoto = noi.** Senza scelta l'emittente è la società del gestionale. Chi ha una sola ragione sociale non vede nulla di nuovo.
- **Dietro un interruttore.** Funzionalità `multi_issuer` in `config/features.php` (area Vendite, spenta, richiede `companies` e `office_sales`).
- **L'emittente è una dimensione generale.** Sta su documenti, righe, pagamenti, numerazione e statistiche. Nel modello non si chiama mai «collaboratore» o «partner»: così la stessa base serve anche a gruppi e reti in franchising (§11).
- **Il documento copia.** Come per brand e referente, i dati dell'emittente si copiano sul documento alla conferma: una modifica al profilo non cambia i documenti già emessi.

## 3. L'emittente

Un'azienda dell'anagrafica (`companies`) con un **profilo emittente** (`gst_issuers`, codice `iss_`, una riga per azienda):

- intestazione: ragione sociale, dati fiscali e indirizzo dall'azienda; logo proprio;
- regime fiscale (§5);
- modo di fatturare: `internal`, `on_behalf`, `external` (§7);
- provider di fatturazione e riferimento alle credenziali, solo per `on_behalf`;
- note legali da stampare sul suo blocco del documento;
- `active`.

La società del gestionale è l'**emittente predefinito** e non ha bisogno di una riga: `issuer_id = 0` vuol dire lei, e si legge da `$SOCIETY` come oggi.

Conti e pagamenti:

- `gst_payment_accounts.issuer_id` (0 = società del gestionale): un conto appartiene a un emittente;
- metodi e condizioni ammessi per emittente (`gst_issuer_payment_methods`, `gst_issuer_payment_terms`). Vuoto = tutti quelli che si incassano a mano (`PaymentMethod::MANUAL_PROVIDERS`). I gateway (Stripe, PayPal, Nexi) restano della società del gestionale finché l'emittente non ha credenziali proprie (§9).

## 4. Documenti: preventivo e ordine

### 4.1 Dati

- `gst_orders.issuer_id`: l'emittente predefinito del documento (0 = società).
- `gst_order_items.issuer_id` — **fatturato da**: chi emetterà la fattura della riga. 0 = quello del documento.
- `gst_order_items.supplier_id` — **fornito da**: esiste già (G9); indica chi fa il lavoro o fornisce la merce.
- `gst_order_items.unit_cost` — **costo**: quanto chiede il fornitore per la riga. Oggi sulle righe non c'è. Il margine della riga è prezzo meno costo.

I tre casi:

| Caso | Fatturato da | Fornito da |
|---|---|---|
| Lavoro proprio | società | — |
| Il partner fattura al cliente | partner | partner |
| Il partner fattura alla società, che rifattura al cliente | società | partner (è un acquisto: fattura passiva) |

Risoluzione dell'emittente, a cascata: riga → documento → canale di vendita (§9) → società. La cascata è un registro estendibile: in futuro ci si potrà inserire la sede (§11).

### 4.2 Un solo documento

Il preventivo e l'ordine restano **uno**, con la numerazione della società, anche quando le righe hanno emittenti diversi.

- Nel form: un campo «Emittente» sul documento e, a funzionalità accesa, una colonna «Fatturato da» sulle righe, con costo e fornitore.
- Nel PDF: un blocco per emittente, ciascuno con righe, imponibile, imposta, totale, metodo, condizioni e IBAN. In fondo il totale complessivo.
- Se tutte le righe sono di un solo emittente diverso dalla società, l'intestazione del PDF è la sua; altrimenti è della società e ogni blocco porta la ragione sociale di chi fattura.
- DDT: resta della società (è lei che consegna). Da confermare.

## 5. Fisco per emittente

Oggi `OrderTotals`, `TaxTotals` e `gst_order_tax_summaries` lavorano sull'intero ordine. Con più emittenti:

- `gst_order_tax_summaries.issuer_id`: il riepilogo IVA è per emittente;
- i totali del documento sono la somma dei totali per emittente;
- il regime si legge dal profilo: ordinario, forfettario (nessuna IVA, natura dedicata, bollo sopra soglia), con eventuale ritenuta e cassa previdenziale;
- `vat_collectability` e `prices_include_tax` restano del documento, ma un emittente senza IVA espone solo l'imponibile.

Le regole dei regimi vanno chiuse con il commercialista prima della fase 2 (§12).

## 6. Scadenzario e incassi

- `gst_payments.issuer_id`: ogni rata e ogni incasso appartengono a un emittente.
- Le rate (`Installments`) si generano per emittente, con le sue condizioni di pagamento.
- Lo scadenzario si filtra per emittente; l'incasso sul conto di un altro emittente si registra a mano, come oggi un bonifico.
- `payment_status` dell'ordine resta complessivo; accanto si mostra lo stato per emittente.
- I pagamenti online arrivano sul conto della società: in un ordine misto il gateway incassa solo la sua parte, il resto va a bonifico sull'IBAN dell'emittente.

## 7. Fatture

Le fatture non esistono ancora (#109). Nascono con `issuer_id` e con tre modi, scelti nel profilo:

- **`internal`** — la fattura della società: numerazione e sezionali propri, invio tramite il provider del gestionale.
- **`on_behalf`** — emessa dal gestionale con le credenziali dell'emittente sul suo provider (Fatture in Cloud, Aruba). **Il numero lo assegna il provider**: l'emittente fattura anche fuori dal gestionale, una sequenza locale creerebbe buchi e doppioni. Il gestionale conserva numero, data, stato e documento restituiti.
- **`external`** — emessa dall'emittente e caricata nel gestionale. Si importa l'XML della fattura elettronica, che compila numero, data, importi e scadenze; in mancanza, PDF più dati essenziali inseriti a mano.

In tutti e tre i casi la fattura si lega alle righe dell'ordine e alle rate dello scadenzario dello stesso emittente.

Da verificare prima della fase 3: come Fatture in Cloud e Aruba gestiscono più aziende e credenziali delegate sulle loro API.

La fattura **passiva** del fornitore (terzo caso del §4.1) non è in questa spec: va con le uscite (§8).

## 8. Conto tra ragioni sociali (predisposto, non costruito)

Con «fatturato da», «fornito da» e costo, il saldo con ogni partner si calcola senza altri dati:

- **gli spetta**: il costo di tutte le righe fornite da lui, più le eventuali provvigioni;
- **ha già ricevuto**: quanto ha incassato fatturando direttamente ai clienti, più i pagamenti ricevuti dalla società.

Esempio, riga con costo 800 e prezzo 1.000: se fattura la società, al partner spettano 800 e il margine di 200 resta alla società; se fattura il partner, ha incassato 1.000 e ne spettavano 800, quindi deve 200.

In questa spec si salvano solo i dati. La pagina del conto, i pagamenti verso i partner e le fatture passive arrivano con il modulo delle uscite, ancora da progettare. Il conto è uno strumento di gestione: non sostituisce i documenti fiscali tra le parti.

## 9. Ecommerce e abbonamenti

- Un solo emittente per ordine online e per abbonamento, mai scelto a mano.
- Si legge dal **canale di vendita** (`online`, `office`, `pos`): impostazione «Emittente del canale», vuota = società. Per l'ufficio vale come proposta iniziale del documento.
- `gst_subscriptions.issuer_id` copiato alla creazione.
- Il carrello non mescola emittenti: con un emittente per canale non può succedere.
- Incasso con carta a nome di un emittente diverso dalla società: servono le sue credenziali del gateway. Fuori da questa spec; fino ad allora un canale con emittente diverso offre solo i metodi manuali.

## 10. Statistiche, permessi, export

- Cruscotti e report distinguono **fatturato proprio** (righe della società) e **transato** (righe di altri emittenti). Le provvigioni dei commerciali si calcolano sul fatturato proprio, salvo scelta diversa (§14).
- Filtro «Emittente» negli elenchi di ordini, preventivi, scadenzario e fatture.
- Permessi: gestire i profili emittente è dell'amministratore. I commerciali scelgono tra gli emittenti attivi; non vedono costo e margine se non hanno il permesso.
- Gli emittenti non accedono al gestionale (§11).
- Export e strumenti MCP: emittente, fornitore e costo sulle righe.

## 11. Fuori da questa spec: franchising e gruppi

La base è la stessa (più ragioni sociali in un sistema, con un conto tra loro), ma servono tre pezzi in più:

- **accesso per emittente**: l'affiliato usa il gestionale e vede solo i suoi documenti, clienti e incassi (estensione di `ContactVisibility`);
- **emittente per sede**: `gst_locations` legata a un emittente, con magazzino e cassa propri; la sede entra nella cascata del §4.1;
- **regole di royalty**: percentuali, periodi e fatture periodiche tra casa madre e affiliato.

Qui ci si limita a non chiudere la strada: emittente come dimensione generale e cascata estendibile.

## 12. Fasi e PR

1. **Emittente e documenti** — funzionalità `multi_issuer`, `gst_issuers`, profilo nella scheda azienda, `issuer_id` su ordini e righe, costo sulle righe, conti e metodi per emittente, PDF a blocchi, copia dei dati alla conferma.
2. **Fisco e scadenzario** — totali e riepilogo IVA per emittente, regimi, rate e incassi per emittente, filtri.
3. **Fatture** (con #109) — `external` con import XML, poi `on_behalf` tramite provider.
4. **Canali** — emittente del canale per ecommerce, ufficio, cassa e abbonamenti.
5. **Statistiche** — fatturato proprio e transato, export, MCP.

Migrazioni: solo colonne nuove con default 0, nessun dato da convertire. Ogni fase resta inerte a interruttore spento.

## 13. Decisioni prese

- Un solo preventivo anche con più emittenti, con numerazione della società; PDF a blocchi.
- La numerazione delle fatture è dell'emittente; per `on_behalf` la assegna il provider.
- Per ecommerce e abbonamenti l'emittente è uno solo e dipende dal canale di vendita.
- Sulle righe si salvano fatturato da, fornito da e costo; il conto tra ragioni sociali si costruisce dopo, con le uscite.
- Gli emittenti non accedono al gestionale.

## 14. Da decidere

- DDT: sempre della società, o dell'emittente quando il documento ha un solo emittente?
- Sconto sul totale e spedizione in un documento misto: ripartiti in proporzione tra gli emittenti, o attribuiti alla società?
- Provvigioni dei commerciali: solo sul fatturato proprio o anche sul transato?
- Regimi fiscali da coprire nella prima versione (ordinario e forfettario; ritenuta e cassa subito o dopo).
- Dove vivono le credenziali del provider dell'emittente.
