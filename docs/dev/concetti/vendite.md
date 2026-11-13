---
icon: cart-shopping
---

# Vendite: ordini, pagamenti e resi

## Chi scrive cosa

Ogni colonna di stato ha **un solo scrittore**. Nessuna Resource e nessun
comando la cambia a mano: chiamano il servizio.

| Cosa | Chi lo scrive | Dove |
| --- | --- | --- |
| `status` e `fulfillment_status` dell'ordine | `Lifecycle::confirm()`, `cancel()`, `fulfill()` | `Support\Orders` |
| `payment_status` dell'ordine | `Ledger::sync()`, chiamato da `register()`, `refund()`, `fail()` | `Support\Payments` |
| Prenotazione, scarico e rientro della merce | `Allocation::reserve()`, `release()`, `commit()`, `restore()`, `returnGoods()` | `Support\Stock` |
| Bozza d'ordine da ufficio, conferma, annullo di righe | `OfficeOrders::save()`, `confirm()`, `delete()`, `cancelLines()` | `Support\Sales` |
| Rate di un ordine (righe `pending` con `due_date`) | `Installments::create()`, `register()`, `registerOnOrder()`, `recalculate()` | `Support\Payments` |
| Stato del reso e sue righe | `Returns::register()`, `complete()`, `cancel()` | `Support\Returns` |
| Giacenza e movimenti | `Stock::apply()` (vedi [Magazzino](magazzino.md)) | `Support\Stock` |

`Allocation` non scrive mai la giacenza per conto suo: passa da `Stock::apply()`.
Per questo chi legge i movimenti trova sempre il motivo (`sale`,
`return`, …) e l'ordine o il reso che li ha causati (`reference_type` `order` o `sales_return`).

## Il percorso di un ordine

1. **Carrello** — `Cart::open()`, `add()`, `setQuantity()`, `remove()`,
   `merge()`. Ogni modifica passa da `recalculate()`, che rifà prezzi, IVA e
   totale.
2. **Ordine** — `Checkout::place()` trasforma il carrello in ordine `pending` e
   prenota la merce con `Allocation::reserve()`. La giacenza non cambia: la
   merce è solo messa da parte.
   Con un metodo online (`provider` diverso da `manual`) l'ordine nasce
   **«In pagamento»** (`awaiting_payment`): niente numero, niente
   `ordered_at`, niente email. Non compare al commerciante (lista ordini,
   cliente, MCP) né al cliente (area account). Diventa `pending` con
   `Lifecycle::receive()`, chiamato da `OnlinePayments::succeeded()` o
   `processing()` (SEPA, `payment_intent.processing`): un solo UPDATE
   condizionato dà numero, `ordered_at`, email «ricevuto» al cliente e avviso
   al commerciante, una volta sola anche con webhook e ritorno insieme. Con il
   pagamento in elaborazione l'email lo dice; l'incasso arriva poi col webhook.
   `succeeded()` trova l'ordine dal riferimento prima della transazione, poi
   blocca l'ordine e la riga di pagamento (`findForUpdate`), nello stesso
   ordine di `abandon()` e `cancel()`: le letture successive vedono i dati
   dopo l'attesa. Tiene i lock per tutta la registrazione: chi arriva secondo
   la trova pagata. Le email del
   pagamento e l'avviso di rimborso partono dopo il commit.
   **Abbandono** — `Lifecycle::abandon()` porta un `awaiting_payment` ad
   **«Abbandonato»** (`abandoned`): annulla l'intento, libera merce, pagamenti
   aperti e coupon, nessuna email. Se l'intento è riuscito o in elaborazione
   restituisce false e l'ordine resta. Lo chiamano `Expiry` (prenotazione
   scaduta) e il checkout (nuovo ordine sopra uno in pagamento). Gli abbandonati
   si vedono solo col filtro «Pagamenti non riusciti» della lista ordini.
3. **Conferma** — `Lifecycle::confirm()` scarica la merce (`Allocation::commit()`),
   manda l'email al cliente e, se è passato un pagamento, lo registra col
   `Ledger`. Confermare **non** registra denaro da solo.
   Un ordine pagato online **non si conferma da solo**:
   `OnlinePayments::succeeded` registra l'incasso e lascia l'ordine `pending`
   con `payment_status` `paid`. La merce resta prenotata senza scadenza
   (`Allocation::keep()`), `Expiry` lo salta, e partono «pagato» al cliente e
   l'avviso al commerciante. Lo conferma il commerciante dal backend, dopo aver
   controllato che la merce ci sia.
   **Riapertura** — `Lifecycle::reopen()` riporta a carrello, sulla stessa
   riga, un ordine `awaiting_payment` o `abandoned` (il checkout lo chiama da
   solo al primo cambio, dal carrello e dal coupon; sull'abbandonato cambia solo
   lo stadio). Sul pagamento aperto scrive la ragione «Ordine modificato dal
   cliente», distinta da un rifiuto. Libera le prenotazioni, segna come falliti i pagamenti aperti,
   rilascia il coupon e annulla gli intenti **prima** di toccare l'ordine. Se
   l'annullo non riesce rilegge lo stato: già annullato riapre, pagamento
   partito (`succeeded`/`processing`) dà `order.payment_in_progress`, altro dà
   `order.reopen_retry`; in entrambi i casi l'ordine resta. Il numero resta:
   il `Checkout::place()` successivo riusa l'`order_number`. Con denaro
   incassato non riapre. Un incasso arrivato comunque su un ordine tornato
   carrello è un `PaymentMismatch` («va rimborsato»): un solo avviso al
   commerciante.
   **Rifiuti** — ogni rifiuto della banca resta nella storia del pagamento:
   `PaymentState`/`PaymentEvent` portano `failure` (il motivo) e `attempt`
   (l'id del tentativo, da `last_payment_error`), e `Ledger::fail` scrive una
   riga `failed` per tentativo, saltando lo stesso tentativo già scritto (dal
   webhook o dal checkout). La lista pagamenti mostra l'ultimo motivo e
   « · N tentativi».
4. **Evasione** — `Lifecycle::fulfill()` cambia solo `fulfillment_status`: la
   merce è già uscita alla conferma.
5. **Annullo** — `Lifecycle::cancel()` libera la prenotazione se l'ordine era
   `pending`, rimette la merce in magazzino se era già confermato. Il denaro
   incassato non si rimborsa da qui: lo fa il gateway con `Ledger::refund()`.
6. **Scadenze** — `Expiry::run()` gira ogni ora (`ExpiryTask`, cron
   `0 * * * *`): manda il promemoria agli ordini che aspettano il pagamento e
   annulla quelli scaduti, secondo `order_payment_wait_days` (bonifico
   manuale). Abbandona gli `awaiting_payment` con la prenotazione scaduta e
   salta i `pending` il cui intento è riuscito o in elaborazione.

### Le personalizzazioni sulla riga

`Cart::add($carrello, ['product_id' => …, 'quantity' => …, 'customization' => [id => valore]])`.
Con `customizations` accesa il carrello passa i valori da
`Customizations::resolve()`: un valore sbagliato o una obbligatoria mancante
lanciano `UserError` (`field()` è l'id della personalizzazione). Con la
funzionalità spenta i valori si ignorano, e un articolo con una personalizzazione
obbligatoria non si aggiunge (`customization.unavailable`).

- **Il sovrapprezzo lo calcola il server.** `customization_surcharge` che
  arriva dalla pagina non si legge più; la riga salva `customization`
  (stringa ASCII) e il sovrapprezzo, che entra nel prezzo di `LinePrice`.
- Due righe dello stesso articolo si fondono solo se hanno la stessa
  personalizzazione (`Customizations::signature()`), anche nel `merge()`.
- `Cart::contents()['items'][]['customization']` è una **lista** già decodificata
  (`label`, `value`, `option_id`, `surcharge`), mai la stringa del database:
  chi ha bisogno della colonna passa da `Customizations::decode()`.
- `recalculate()` ripassa ogni riga personalizzata contro l'anagrafica di oggi:
  riprezza se il sovrapprezzo è cambiato e riscrive le etichette; se la
  personalizzazione è stata disattivata, scollegata, l'opzione cancellata o è
  comparsa una obbligatoria, **la riga esce** e il suo nome va in `removed`,
  come per un articolo non più vendibile.
- Dopo l'ordine la riga è una copia: schede, email ed elenco resi mostrano
  `Customizations::lines()` sotto il nome. Un'incisione non si rimette in
  vendita: `ReturnRules::onlineReturnable($riga)` dice **no** a una riga
  personalizzata, ed è quello che E1c deve consultare per il reso fatto dal
  cliente. Il reso registrato dal commerciante (`Returns::register()`) non cambia.

### Le confezioni (multiprodotti)

`Cart::add($carrello, ['product_id' => …, 'choices' => [id opzione, …]])`. Con
`bundles` spenta un multiprodotto non si aggiunge (`bundle.feature_off`); le
scelte passano da `Bundles::resolve()`, e un valore sbagliato lancia `UserError`.

- **Una madre e tante figlie.** La madre è la riga che si vende, col prezzo
  (base + sovrapprezzo delle opzioni, `LinePrice`). Ogni componente è una riga
  figlia a prezzo zero con `parent_item_id` che punta alla madre e, per le
  parti scelte, `bundle_option_id`; la quantità della figlia è
  `quantity` del componente per i pezzi della madre.
- **`OrderLines` decide chi è chi**: `flat()` (la lista col `parent_item_id`),
  `goods()` (le righe con merce dietro: **la madre non c'è**), `sold()` (le
  righe comprate: tutte tranne le figlie) e `children()`. Prenotare, scaricare
  e rimettere a scaffale parte sempre da `goods()`: **la madre non entra mai in
  `Allocation`**, perché non ha giacenza. Chi scrive nuovo codice sulle righe
  non filtra a mano: passa di lì.
- `Cart::contents()['items'][]['children']` è la lista annidata delle figlie
  (le stesse chiavi della madre, con `bundle_option_id`); le figlie hanno prezzo
  zero, quindi i totali non cambiano.
- Due righe uguali si fondono solo se hanno le stesse scelte; cambiare la
  quantità della madre riscrive le figlie, e `assertPacks()` controlla che i
  componenti bastino.
- **`recalculate()` ripassa le confezioni** contro l'anagrafica di oggi: se un
  componente è spento o un'opzione non c'è più, **la riga esce** (madre e figlie)
  e il nome va in `removed`. Dopo l'ordine le righe sono una copia.

### Il reso di una confezione

`Returns::register()` vuole, per una confezione, **una riga per la madre**
(quantità, motivo) e, in `children_restock`, un `id figlia => bool|null` per dire
quali componenti rientrano (assente o `null` = come dice il motivo; per
`damaged` e `defective` non rientrano). Il reso scrive una riga per la madre e
una per ogni componente, ciascuna col suo «rientra a magazzino»; la quantità
massima è quella della madre, e il rientro passa da `Allocation::returnGoods()`
sulle sole figlie. La pulizia dei dati di prova (`OrdersDemo`) restituisce la
merce di `OrderLines::goods()`, mai quella della madre.

## L'ordine da ufficio (G9)

La bozza è un ordine vero: `stage` `order`, `status` `draft`, `channel`
`office`, senza `order_number` e senza prenotazioni. `user_id` è l'account del
cliente sul sito, come online (l'operatore sta nello storico): mai l'operatore,
o l'area cliente del sito gli mostrerebbe gli ordini dell'ufficio.

- `OfficeOrders::save()` riscrive testata e righe a ogni salvataggio (prodotto,
  `custom`, `text`; la spedizione a mano è una riga `shipping` `manual`) e
  chiama `Cart::recalculate()`, che accetta le bozze. Il prezzo scritto a mano
  sta in `manual_unit_price`, **prima** dello sconto di riga: rileggere
  `unit_price` come prezzo a mano sconterebbe due volte.
- `OfficeOrderRules` è pura: righe dell'editor, testata, cosa chiede la
  conferma, quanto si annulla dopo.
- `confirm()` controlla la giacenza della sede per versione
  (`Levels::forSale`) e rifiuta col nome (salvo vendita senza giacenza), poi
  numero, `Lifecycle::confirm()` con `payment => false` (lotto e fornitore
  della riga vanno allo scarico) e `Installments::create()`.
- `cancelLines()` annulla al massimo quanto non è assegnato a una spedizione
  (`Shipments::remaining()`): `quantity` scende, `quantity_cancelled` sale, la
  merce rientra con `Allocation::restore()`, i totali si rifanno con i prezzi
  scritti e le rate con `Installments::recalculate()`. Tutto = `Lifecycle::cancel()`.
- `OfficeSearch` trova anche i referenti di un'azienda cliente: la riga
  `company:<azienda>:<persona>` apre l'azienda con quel referente. Le sedi
  dell'azienda passano da `ContactAddressResource` con `OWNER_COMPANY`, come
  gli indirizzi di una persona.
- `OfficeCustomerPanel` legge e modifica solo schede cliente
  (`CustomerResource::roleColumn()`): un fornitore dà un payload vuoto e
  `ok => false` su contatti, fatturazione e indirizzi.
- In `OrderActions` la bozza da ufficio non ha azioni (si apre nella sua
  pagina, `OfficeOrderResource`); un ordine da ufficio confermato ha
  `cancel_lines`.

## DDT e righe evase (G9)

`DeliveryNotes` è la sola porta del DDT: `createFromOrder()` (le righe di
merce non ancora in un DDT emesso, divise per lotto con
`StockMovements::netOut()`), `saveDraft()`, `issue()` (numero, data, PDF in
`storage/gestionale/documents/delivery-notes/`), `cancel()`, `delete()`,
`send()`. Non muove il magazzino.

Una riga è evasa per `FulfilledLines::of($ordinato, $nei DDT emessi,
$spedito)`: il **massimo**, mai la somma. La usano `Shipments::remaining()`
(non si spedisce quello che un DDT ha consegnato), `Shipments::syncOrder()`
(lo stato di evasione, che resta il solo a scriverlo e che chiamano anche
`issue()` e `cancel()` del DDT) e *Annulla righe*. Il PDF è
`DeliveryNotePdf` (tipo `delivery_note` dell'hook `documentPdf`).

## Preventivi e PDF (G9)

Il preventivo è lo stesso documento della bozza d'ordine con `stage` `quote`:
`OfficeOrders::save(..., 'quote')` lo scrive, `Quotes` lo invia (numero al
primo invio, `quote_revision` +1, PDF in `gst_order_quote_revisions`),
`accept()` lo fa diventare una bozza d'ordine (`stage` `order`, `status`
`draft`), `expireDue()` (con `QuoteExpiryTask`) segna gli scaduti. Non prenota
e non scarica mai.

I PDF di vendita (`Support\Documents`):

- `DocumentData::forOrder()` legge società, documento, righe, IVA e pagamento;
- `DocumentPdf` disegna con `Wonder\Pdf` (FPDF, testo in Windows-1252 con
  `text()`), `QuotePdf` e `OrderConfirmationPdf` cambiano titolo e dati;
- `DocumentFiles` li tiene in `storage/gestionale/documents`, non pubblica:
  si scaricano dalle rotte `…/preventivi/{id}/pdf/{rev}/` e
  `…/ordine-da-ufficio/{id}/pdf/`.

Un documento che non c'è (PDF, stampa, proforma, CSV dei clienti di un lotto,
righe di un listino) lancia `NotFoundHttpException`: il core mostra la sua
pagina 404, come per le altre rotte del backend, non un testo nudo.

Il sito sostituisce un PDF con l'hook `documentPdf` di `GestionaleExtension`:

```php
final class MioGestionale extends GestionaleExtension
{
    public function documentPdf(string $type, string $class): string
    {
        return $type === 'quote' ? MioPreventivo::class : $class;
    }
}

class MioPreventivo extends \Wonder\Plugin\Gestionale\Support\Documents\QuotePdf
{
    protected function notes(): void
    {
        parent::notes();
        $this->section('Condizioni generali');
        $this->pdf->MultiCell(180, 5, self::text('…'));
    }
}
```

Una classe che non esiste o non estende `DocumentPdf` non vale: resta quella
del modulo. Le email `quote_sent` e `office_confirmed` portano il PDF
allegato (`Mailer::send(..., $attachments)`).

## Il cliente del documento: persona o azienda (#77)

Da gestionale#77 le aziende hanno una tabella loro (`companies`, di
customer-account) e ogni documento con un cliente ha due colonne:

| Colonna | Significato |
|---|---|
| `customer_id` | la **persona** (`contacts`): chi compra, oppure il referente dell'azienda; `0` = nessuno |
| `company_id` | l'**azienda** (`companies`); `0` = documento di una persona |
| `user_id` | l'account della persona, se c'è |
| `referent_name` | copia del nome del referente, solo con `company_id` |
| `company_brand_id` | il brand dell'azienda (`company_brands` di customer-account, non il marchio degli articoli `gst_brands`), solo su `gst_orders`; `0` = nessuno |
| `company_brand_name` | copia del nome del brand, solo su `gst_orders` |

`company_id` è su `gst_orders`, `gst_delivery_notes`, `gst_sales_returns`,
`gst_payments`, `gst_subscriptions`, `gst_coupon_customers` e
`gst_coupon_redemptions`. La vecchia colonna `referent_id` non c'è più: il
referente è `customer_id`. I `supplier_id` puntano a `companies` (i fornitori
sono sempre aziende).

Chi è il cliente lo dice `Support\Contacts\CustomerKind`:

- `of($document)` → `business` con `company_id` o con una P.IVA nello
  snapshot, altrimenti `private`;
- `record($document)` → la scheda dell'azienda se c'è, altrimenti quella della
  persona;
- `name($document)` → ragione sociale o nome.

Listino e IVA seguono l'azienda quando c'è: `PriceLists::forDocument()` /
`forCompany()` e `VatCollectability::forDocument()`.

### Il referente

Tutto passa da `Support\Contacts\DocumentReferents`:

- `people($companyId)` legge `ContactRelations::peopleOf()` e tiene i legami
  vivi con la persona viva: fra questi si sceglie il referente;
- `options($companyId)` è uguale a `people()`: `receives_documents` non
  filtra più (nei form non si sceglie; resta salvato sul legame);
- `defaultFor($companyId, $brandId = 0)` propone il primo referente della
  scheda, altrimenti nessuno; con il brand il primo fra chi segue quel brand
  (`ContactRelations::peopleOf($companyId, $brandId)`), poi il primo di tutti.
  `is_primary` non conta;
- `legalRepresentatives($companyId)` dà i referenti con la spunta
  «Rappresentante legale» (`is_legal_representative` sul legame, anche più
  d'uno), nell'ordine della scheda: è la lettura per chi genera un contratto
  o un documento da firmare. Ogni voce di `people()` porta
  `is_legal_representative` (bool). Non cambia né il referente proposto né le
  copie;
- `resolve($companyId, $personId)` controlla la persona scelta contro le
  opzioni e dà `customer_id` e `referent_name`. Un id che non è fra le opzioni
  diventa `0` / `''`;
- `recipients($companyId, $document, $brandId)` dà i referenti per un
  documento: `ContactRelations::peopleFor($companyId, area($document), $brandId)`
  (chi ha l'area, nessuna area o l'ufficio «Generale»), nell'ordine della
  scheda; se nessuno corrisponde, tutti i referenti. Non decide più le copie;
- `AREAS` è l'unica mappa documento → area (`quote_sent`, `office_confirmed`,
  `delivery_note_sent` → `purchasing`; `subscription.proforma`, `.reminder`,
  `.payment_failed` → `administration`; il resto: nessuna area). Un modulo la
  cambia con `useArea($document, $area)`;
- `companyEmails($companyId, $document, $company)` dà le email dell'azienda
  per l'area del documento: `ContactPoints::companyEmailsFor($companyId,
  area($document))` (le caselle del riquadro «Contatti» con quell'area o
  nessuna, la principale per prima); vuota o illeggibile → la principale della
  scheda (`$company['email']`), come prima delle aree;
- `copyEmails($order, $company, $document)` dà gli indirizzi in copia, senza
  doppioni e senza quelli che ricevono già la mail: `companyEmails()` e
  l'email del referente scelto sul documento (`customer_id`, `emailOf()`).
  Nessun altro referente riceve copie da solo. L'email di un referente è `contact_email` delle
  righe di `peopleFor()` (di lavoro se c'è, altrimenti la principale della
  persona); `fromRows()` dà anche `phone` (di lavoro, o quello della persona).
  `OrderNotifier` passa la chiave dell'email; `SubscriptionNotifier` scrive
  alla prima di `companyEmails()` (o al referente, `emailOf()`) e copia il
  resto (le altre email dell'azienda e il referente dell'abbonamento) con `subscription.` + la sua chiave;
- `emailOf($companyId, $personId)`: l'email per l'azienda di un suo referente
  (anche l'email proposta dall'ordine quando l'azienda non ne ha una);
- `useReader()` e `useCompanyReader()` servono solo ai test.

Ogni riga di `people()` / `options()` porta anche `departments` (gli uffici,
vuota = tutti: le chiavi di serie o l'id di un ufficio creato, come testo) e
`role`: il ruolo (`job_title`), o le etichette degli uffici (`areasLabel()`)
se il ruolo è vuoto. Le tendine dei referenti mostrano `role`. I form
scrivono un ufficio solo (`department`, vedi sotto): `departments` resta una
lista per le righe salvate prima e per chi scrive da codice.

### Layout del form delle anagrafiche

`CustomerResource::formLayoutSchema()` disegna le stesse sezioni per persone,
aziende e fornitori, in creazione e in modifica: chi è, `brandCards()`,
recapiti, `relationCards()`, fatturazione, consegna, `Accordion` «Altri dati»
(`paymentCards()` e note). Regole:

- la spiegazione di ogni card sta nel tooltip del titolo
  (`SectionTitle::tooltip()`), mai in un `HelpText` sotto; i testi stanno in
  `gestionale.contacts.form.*`, `gestionale.contacts.relations.tooltip_*`,
  `brands.tooltip` e `groups.tooltip`. L'`Accordion` «Altri dati» non ha
  descrizione;
- brand, referenti e indirizzi partono senza righe (`repeaterStartEmpty()`);
- la card dei recapiti si chiama «Recapiti dell'azienda» o «Email e telefoni»
  (`form.points_company` / `form.points_person`) e la disegna
  `contactsCard()` con `contactBoxFields()` di customer-account. Con il CRM
  (vedi sotto) è un riquadro solo, una riga per recapito con il «Tipo»; senza,
  `email`, `phone_prefix` e `phone` su una riga. Non c'è «Principale»: vale la
  prima riga di ogni famiglia. `contactBoxByKindFields()` del trait non si usa
  più qui;
- «Ufficio» è lo stesso select singolo sui recapiti delle aziende
  (`contactBoxOffice()`: le persone non l'hanno) e sui referenti
  (`ContactRelations::departments()`: uffici di serie, «Generale» per primo,
  più quelli di `contact_office_types`). Il «+» ne crea uno con
  `Resources\Contacts\OfficeTypeResource` (path `app/gestionale/tipi-ufficio`,
  permessi dell'area contatti), che `CustomerResource::officeTypeResource()`
  passa al trait; non ha voce di menu e non si elimina;
- `contactPeopleField()` e `contactCompaniesField()` sono ridefiniti qui: una
  scheda richiudibile per riga (`repeaterCollapsible()`: nome, poi ruolo e
  ufficio), ordinabile dalla maniglia, vuota all'inizio
  (`repeaterStartEmpty()`), con il titolo `form.relation_new_person` /
  `form.relation_new_company` finché la riga non ha una scheda. La riga ha
  persona (o azienda), ruolo e ufficio, poi i contatti aziendali. Non c'è più
  `repeaterAdvanced()`: principale, documenti in copia e accesso non sono nel
  form. Le chiavi postate sono quelle del trait;
- gli indirizzi restano anche per il fornitore, che li usa per il ritiro:
  `addressesKind()` sceglie titolo, aiuto e frase vuota (`addresses` con il
  campo ruoli, altrimenti `delivery`; `SupplierResource` risponde `pickup`) e
  `addressesTitle()` lo espone alla scheda;
- metodo e termini di pagamento senza scelta si salvano `NULL`:
  `mutateRequestValues()` passa la stringa vuota, perché `formToArray()` del
  framework salta i `null` e lascerebbe il valore di prima;
- «Altri dati» è chiuso in creazione e, in modifica, aperto solo se
  `otherDataFilled()` trova note, pagamento, listino o commerciale.

Una sottoclasse che aggiunge sezioni usa gli hook `brandCards()`,
`relationCards()` e `paymentCards()`, senza riscrivere il layout.

### Contatti e indirizzi della scheda (customer-account)

Persone, aziende e fornitori (`CustomerResource` e sottoclassi) hanno il
riquadro «Contatti» di customer-account (`contactBoxFields()`: input
`contact_points`, marker `contact_box_form`, lo script `contactFormScript()`
nella card) al posto dei campi `email`, `phone_prefix`, `phone` e di «Altri
contatti». In `mutateRequestValues()`:

1. `applyContactPoints($values, $oldValues)` rimette il principale di ogni tipo
   in `email` / `phone_prefix` / `phone`, **prima** dei controlli: il doppione
   dell'email (`contact.email_taken`, o `contact.duplicate_hidden` per una
   scheda che chi scrive non vede) vale sul principale come prima;
2. `keepAccessEmail()`: una persona con account (`ContactPoints::lockedEmail()`)
   tiene `contacts.email`. Il riquadro la ignora già; un campo singolo diverso
   (finestra dell'ordine, API) ferma con `contact.access_email_locked`;
3. `assertRelationContacts($_POST)` controlla i contatti di lavoro del
   repeater dei legami (con il DNS: è il form);
4. riquadro e marker escono dai valori; le righe le scrive `ContactPoints::syncAll()`
   da `syncRepeaterRelations()` del trait.

Senza marker (creazione rapida `QuickContacts`, finestre dell'ordine, API, MCP)
i campi singoli passano come prima. La finestra «Contatti» dell'ordine si fa i
suoi campi (`email` non è più un campo della scheda). Le schede in lettura
mostrano `ContactPoints::all()` (`CustomerResource::pointItems()`,
`CustomerSheet::points()`): principale, «Email di accesso», etichette e aree.
Con il CRM `pointItems()` passa a `points()` anche i nomi dei tipi
(`ContactPoints::kindLabel()`; per il telefono della scheda senza righe vale
`phone_kind`) e, per una persona, aggiunge i social con
`CustomerSheet::socials()`: una voce sola, un `Link` per rete che si apre in
un'altra scheda (`rel="noopener"`). Le aziende li hanno sui brand. Senza CRM
la scheda è quella di prima.

Il repeater dei referenti ha le colonne di lavoro del trait
(`workContactColumns()`): con il CRM il repeater interno `points` («Contatti
aziendali», una riga per recapito con il tipo), senza `email`, `phone_prefix`
e `phone`. «Brand seguiti» (`brand_ids`) c'è solo dal lato dell'azienda e
solo se ha più di un brand (`RELATION_BRANDS_MANY`); il filtro dei brand
toglie solo `brand_ids`. Una riga senza scheda ma con qualcosa di scritto
ferma il salvataggio (`assertRelationContacts()`), una vuota si scarta: il
form non crea più persone dalla riga, c'è il «+». Ovunque si scrive o si mostra
l'email di un referente per un'azienda si usa `contact_email` (scheda
dell'azienda, `DocumentReferents`, email proposta dall'ordine, MCP).

Indirizzi: la fatturazione di persone e aziende (`withGeo()`,
`withNameSearch()` dei modelli di customer-account) ha `place_id`, `lat`, `lng`
nascosti nel layout (e nelle finestre dell'ordine, `BILLING_KEYS`), ripuliti
con `GoogleMaps::cleanGeo()` solo se arrivano; sulla via delle aziende
`data-ca-place-name="business_name"`. Il repeater degli indirizzi e la
finestra di `ContactAddressResource` hanno la ricerca per nome
(`data-wi-place-names`) e place ID e coordinate (nel repeater colonne
nascoste, ripulite in `prepareRepeaterRelationRow()`). «Presso»
(`business_name`) non lo chiede più nessuno dei due: non è tra i `FIELDS`
della finestra e la via non ha più `data-ca-place-name`. La colonna resta e
una riga salvata senza la chiave tiene il suo valore (`Model::update()` scrive
solo le chiavi che riceve). Place ID e coordinate arrivano dal
browser e non decidono niente. L'ordine copia dalla sede `shipping_place_id`,
`shipping_lat`, `shipping_lng` (`shippingKeys()`); la fatturazione dell'ordine
non ha colonne geo e non ne prende.

### Recapiti, uffici e social con il CRM

Lo stato si legge da `Support\Contacts\Crm` (vedi
[Funzionalità](funzionalita.md)); i pezzi sono di customer-account:

- `ContactPoints`: i tipi di un recapito sono `email`, `phone` (fisso),
  `mobile` e `whatsapp` (`kinds()`, `kindOptions()`, `kindLabel()`). Le
  famiglie sono due, email e telefono (`family()`, `isPhone()`,
  `PHONE_KINDS`): il principale è il primo di ogni famiglia e il tipo del
  telefono principale sta nella colonna `phone_kind` della scheda. Senza CRM
  `syncAll()` scrive con `primary_only`: i recapiti in più restano salvati;
- ufficio singolo: i form postano `department` (una chiave di serie o l'id di
  un ufficio creato), sia sui recapiti sia sui referenti.
  `ContactRelations::GENERAL` («Generale») è la prima voce e vale per tutte
  le aree; `firstDepartment()` dà il valore del select da una lista salvata.
  Le letture portano `department` accanto a `departments`. Sui recapiti di un
  brand l'ufficio sta in `label`;
- email di accesso: per una persona `separateAccessEmail()` la tiene in un
  campo a parte, fuori dal riquadro (`formRowsWithoutAccess()`, `syncAll()`
  con `separate_email`). In lettura `ContactPoints::all()` la dà ancora per
  prima, come principale;
- `Support\Socials`: le reti (`NETWORKS`, `networks()`, `add()` per
  aggiungerne una), la pulizia (`clean()`, `column()`) e le letture (`of()`,
  `formRows()`). Li usano il brand e la persona (`contacts.socials`, trait
  `HasSocials`: card «Social» da `socialCards()`, solo con il CRM);
- `BrandData::CRM_FIELDS` (`contacts`, `socials`): i campi del brand che
  seguono il CRM anche con `brand_kit` spenta. `BrandData::clean($values,
  $kit, $stored, $primaryOnly, $crm)` li lascia passare con `$crm`; con il
  kit e senza CRM tiene un'email e un telefono (`$primaryOnly`).

Il form del brand (`Contacts\CompanyBrandResource`) in modifica ha anche la
card degli indirizzi che le aziende hanno applicato a quel brand
(`ADDRESSES_INPUT` `brand_addresses`, marker `brand_addresses_form`,
`CompanyAddresses::forBrand()` sulle aziende che chi scrive vede). Le righe
si correggono, non si aggiungono né si tolgono; azienda e «Vale per» sono in
sola lettura. Controlla `CompanyAddresses::brandProblems()`, scrive
`updateFromBrand()` e solo i campi di `BRAND_FIELDS`.

### Il brand

Preventivi e ordini da ufficio di un'azienda hanno `company_brand_id`: il nome
commerciale (customer-account, `CompanyBrands`) a cui si legheranno i progetti
nati dal preventivo. Si chiama così per non confonderlo con il marchio degli
articoli (`Catalog\Brand`, `brand_id` su modelli, listini, coupon e campagne).
Il nome si copia in `company_brand_name`, come `referent_name`: resta com'era
anche se il brand cambia nome, si stacca o si archivia.

Tutto questo vale con la funzionalità «Brand» (`Brands::enabled()`, vedi
[Funzionalità](funzionalita.md)).
Spenta, il brand non si mostra né si sceglie, e `resolve()` non cambia quello
salvato (vedi sotto).

Tutto passa da `Support\Contacts\DocumentBrands`:

- `resolve($companyId, $brandId, $stored)` — chiamato da
  `OfficeOrders::headerValues()` con la bozza salvata in modifica. Nuovo
  documento senza `company_brand_id`: `CompanyBrands::defaultOf()` (il
  predefinito, altrimenti il primo). Documento salvato della stessa azienda
  senza il campo, o con lo stesso brand: resta com'è, senza rivalidarlo (un
  brand staccato o archiviato non si perde). Un brand diverso deve essere
  dell'azienda oggi (`CompanyBrands::belongsTo()` senza data), altrimenti
  `UserError` `office_order.brand_unknown`; `0` va sempre bene. Cambiata
  l'azienda, senza il campo vale il predefinito della nuova; il vecchio brand
  ancora postato è un errore. Persona o nessun cliente: `0` e nome vuoto.
  Con «Brand» spenta il brand indicato si ignora: lo stesso documento resta
  com'è (anche al risalvataggio), uno nuovo o di un'altra azienda nasce con
  `0` e nome vuoto.
- `nameOf($order)` — il nome da stampare: quello copiato, altrimenti (documenti
  di prima) `CompanyBrands::find()`. È testo: chi lo stampa lo escapa. La
  scheda dell'ordine lo mostra sotto il cliente;
- `merged()` — ascoltatore di `company.brand_merged` (`register()` da
  `config/boot.php`): una UPDATE porta `company_brand_id` da `from_id` a
  `into_id`, senza toccare `company_brand_name`;
- `useBrands()` serve solo ai test.

`company_brand_name` non arriva mai dalla richiesta: `OfficeOrderForm::header()`
non lo lascia passare e `company_brand_id` non è in `OfficeOrders::HEADER`. Con
il brand noto, il referente proposto è `DocumentReferents::defaultFor($companyId,
$brandId)`: nel pannello cliente (brand predefinito) e nel salvataggio senza
`customer_id` (MCP, codice). Cambiare il brand nella pagina non cambia il
referente già scelto.

Il pannello cliente (`OfficeCustomerPanel`) manda `brands` con `is_default` e lo
script della pagina ricostruisce la tendina `company_brand_id` proponendo il
predefinito. In modifica `OfficeOrderPage::brandOptions($companyId, $values)`
aggiunge il brand salvato se l'azienda non l'ha più, «(non più dell'azienda)».
La card *Brand* del form azienda è `CompanyResource::brandCards()`, hook di
`CustomerResource`, con il repeater di customer-account (brand e ruolo: il
predefinito non si sceglie più nel form, `CompanyBrands::chooseDefault()`
tiene quello di prima se c'è ancora, altrimenti vale il primo); la Resource che crea il brand al volo è
`Contacts\CompanyBrandResource`, la stessa di customer-account su
`app/gestionale/brand` con i permessi dell'area contatti e la voce
*Anagrafiche → Brand*.

DDT, resi e revisioni del preventivo non portano il brand: lo legge l'ordine.

Sui PDF il brand è `brand` dei dati (`DocumentData::brand($order)`: il nome di
`nameOf()`, vuoto con «Brand» spenta; per il DDT quello del suo ordine).
`DocumentPdf::billingLines()` lo stampa senza etichetta, nello stile delle
altre righe, nell'ordine: «Alla c.a.» (referente), brand, ragione sociale o
nome, indirizzo, dati fiscali, email. Passa da `text()` come le altre righe.
Spenta la funzionalità, il PDF rigenerato non ha il brand; riaccesa sì: il
nome resta sul documento. Le email dei documenti non ripetono il blocco del
cliente.

Le sedi proposte seguono il brand: `OfficeOrders::companyAddresses($companyId,
$brandId)` legge `CompanyAddresses::addressesFor()` e le mette in ordine con
`forBrand()` (pura): prima quelle del brand, poi quelle per tutti, in ogni
gruppo la predefinita; la prima è la proposta (`customerDefaults()`, `proposed`
nel pannello). Il pannello (`customers.php`, `customer-save.php`) riceve il
brand della pagina in `oo_brand`; lo script, su `change` del brand, rilegge le
sedi e spunta la proposta solo se l'operatore non ha già spuntato o salvato
una sede (`touched`, vero anche per un documento riaperto). Il brand rimesso
dallo script alla scelta del cliente (`autoBrand`) non rilegge. Nel form
dell'azienda la colonna `brand_ids` del repeater degli indirizzi (con il
marcatore `address_brands_form`) si toglie dal payload della riga in
`prepareRepeaterRelationRow()`, si legge con `CompanyAddresses::withBrandIds()`
e si scrive con `setBrands()` in `CompanyResource::syncRepeaterRelations()`,
dopo i brand dell'azienda (su una scheda nuova sono legati solo allora):
`setBrands()` tiene solo i brand legati all'azienda dell'indirizzo.

Il logo del brand (`DocumentBrands::logoOf()` / `logoHtml()`, URL solo
http(s) o del sito, tutto escapato) sta nella scheda dell'ordine e sotto la
tendina del brand; lo script lo ridisegna con il DOM dai `brands` del pannello
(`logo`). Il brand kit (`brand_kit`) lo accende
`Contacts\CompanyBrandResource::useKit()` prima di disegnare o salvare il form
del brand, non al boot: lì lo stato delle funzionalità si leggerebbe prima del
database e resterebbe memorizzato «tutto spento».

### Cliente, referente e brand creati dall'ordine

La pagina non manda più alla creazione del cliente: usa la creazione rapida
del core (`QuickCreateModal`, `QuickCreateStore`), e le finestre hanno campi
loro, senza `getInput()` (che costruirebbe ogni volta il form intero della
scheda, con le tendine di tutte le aziende).

- **Nuova persona / Nuovo cliente** e **Nuova azienda**:
  `OfficeOrderPage::newCustomerButtons()`, due `QuickCreateButton` su
  `CustomerResource` (con `role` = `customer`) e `CompanyResource`, secondo
  `CustomerMode`. Il bottone porta `data-wi-oo-new` (`contact`/`company`): lo
  script della pagina, su `wi:quick-create:created`, sceglie la chiave
  composta nella ricerca con `wiRemoteSearch.select()`, che fa partire
  `wi:search-select` e quindi il caricamento del pannello come a mano.
- **Referente** e **Brand**: `quickCreate()` sulle due tendine; l'adapter
  `select` del core aggiunge e sceglie l'opzione. Le loro finestre hanno il
  campo nascosto `link_company_id` (`QuickContacts::LINK_COMPANY`, non
  `company_id` per non scambiarsi con quello dell'ordine), scritto dallo script
  su `show.bs.modal` con l'azienda scelta.
- Lato server `QuickContacts::person()` lega la persona all'azienda dal lato
  della persona, ripassando a `ContactRelations::sync()` i suoi legami vivi tali
  e quali; `CompanyBrandResource::quickCreateStore()` aggiunge il brand con
  `CompanyBrands::sync()` ripassando quelli aperti con ruolo e predefinito.
  L'azienda deve essere viva e visibile a chi scrive
  (`QuickContacts::reachableCompany()`, `RowVisibility`) e si controlla prima
  di creare. Permessi (area contatti) e CSRF li controlla il proxy del core;
  dal modal passano solo nome, cognome, email, `role` e l'azienda (o ragione
  sociale e P.IVA, o il nome del brand).
- I campi di una persona nuova (nome, cognome, email) sono
  `CustomerResource::quickPersonFields()`, usati anche dal «+» dei referenti
  nella scheda dell'azienda: l'email non è un campo del form (sta nel riquadro
  «Contatti»), quindi ogni finestra li dichiara nel suo layout invece di
  passare da `getInput()`.

Il DDT eredita `company_id` e `referent_name` dall'ordine (`DeliveryNotes`), e
il PDF stampa il referente «Alla c.a.» nel riquadro del cliente
(`DocumentPdf::billingLines()`).

### La migrazione dei documenti

`Migrations\DocumentCompanyMigration` gira dopo `CompanySplitMigration` di
customer-account, che sposta le schede `type = business` in `companies` e
scrive `contact_company_map` (contatto → azienda, con l'azione `moved`,
`linked` o `supplier`). La migrazione del gestionale:

1. aggiunge `company_id` alle tabelle dei documenti (anche su un'installazione
   nuova);
2. con la mappa, mette l'azienda in `company_id`: un'azienda **spostata** lascia
   in `customer_id` il vecchio `referent_id` (o `0`), un'azienda **collegata**
   (aveva un account) lascia la persona;
3. rimappa `supplier_id` in due passi (prima negativo, poi positivo), perché un
   id nuovo può coincidere con uno vecchio;
4. toglie le chiavi esterne `supplier_id → contacts`, che la sincronizzazione
   delle tabelle ricrea verso `companies`;
5. scrive `document_company` in `gst_migrations` e non gira più.

Tutto in una transazione: se qualcosa va storto non cambia niente.

`Migrations\OrderCompanyBrandMigration` porta il brand da `brand_id` a
`company_brand_id`. Gira prima della sincronizzazione delle tabelle, quindi
crea da sé `company_brand_id` e `company_brand_name` se mancano; copia l'id
dove `company_brand_id` è `0` e il nome da `company_brands` dove manca, poi
scrive `order_company_brand` in `gst_migrations`. La colonna `brand_id` non la
cancella: la toglie dopo la sincronizzazione delle tabelle del core, che fa
cadere le colonne non più nello schema; per questo un'istruzione che fallisce
lancia un'eccezione e ferma l'aggiornamento prima. Senza `brand_id` né
`company_brand_id` non fa niente. Le istruzioni sono in `plan()`, provato in
`tests/OrderCompanyBrandMigrationTest.php`.

`Migrations\PaymentDefaultsNullMigration` porta a `NULL` gli `0` di
`payment_method_id` e `payment_term_id` su `contacts` e `companies`: lo `0`
non è un metodo, è il vuoto salvato dalle versioni precedenti. Tocca solo le
colonne presenti e scrive `payment_defaults_null` in `gst_migrations`. Le
istruzioni sono in `plan()`, provato in
`tests/PaymentDefaultsNullMigrationTest.php`.

Le mail `quote_sent`, `office_confirmed` e `delivery_note_sent`
(`OrderNotifier::COPY_KEYS`) vanno a `order.email`, con gli indirizzi di
`copyEmails()` nel **CC vero** della stessa mail (`OrderNotifier::copies()` →
`Mailer::send(..., $cc)` → `sendMail(..., options: ['cc' => ...])` del core,
wonder-image/app#202). Il risultato di `OrderNotifier::send()` ha `cc` con le
copie partite; il preventivo le registra in `sent_to` insieme ai destinatari.
Con un core senza `$options` in `sendMail()` le copie partono come email a
parte, una per indirizzo e solo se l'originale è partita.

Con i referenti spenti non c'è niente da scegliere, il campo non compare e le
mail partono come prima.

## Le rate (G9)

Una **rata** è una riga `payment` di `gst_payments` con `due_date`, scritta
**in attesa** (`pending`, `provider` `manual`, `note` «Rata 1 di 2»). Le righe
senza scadenza (il checkout, il bonifico a mano) non sono rate e
`Installments` non le tocca, salvo una: alla `create()` la richiesta di
pagamento aperta senza scadenza si annulla, perché la sostituiscono le rate.

- Le condizioni stanno in `gst_payment_terms` e `gst_payment_term_installments`
  (giorni, fine mese, percentuale); `PaymentTermRules` le valida.
- `PaymentSchedule::of($rate, $totale, $da)` è **pura**: scadenze e importi,
  l'ultima rata col resto. La userà anche G10 per gli abbonamenti da ufficio.
- `InstallmentMath` è **pura**: dice cosa scrivere per un incasso su una rata
  (uguale, minore col resto alla stessa scadenza, maggiore che scala le
  successive) e per il ricalcolo proporzionale. `Installments` scrive e chiude
  con `Ledger::sync()`.
- `OrderPaymentResource` passa da `Installments` quando l'ordine ha rate in
  attesa: `Ledger::register()` scriverebbe un incasso a parte e le rate
  resterebbero aperte. Per lo stesso motivo `Ledger` non adotta una rata come
  riga aperta del checkout.
- Lo scadenzario (`ScheduleResource`) e il riquadro (`DuePaymentsWidget`)
  leggono con `DuePayments`; i giorni di preavviso sono `payment_due_days`
  delle impostazioni, fuori dal sync.

## Il reso

`Returns::register($ordine, $righe, $opzioni)` fa tutto dentro una transazione,
con l'ordine bloccato (`FOR UPDATE`): due invii contemporanei si mettono in
fila e il secondo non può rendere più di quanto resta. Non c'è un controllo
sull'invio ripetuto: due invii uguali di una quantità parziale sono due resi.

- Il reso nasce `received`: la merce è già in mano al commerciante.
- `ReturnRules` (pura, senza database) decide se l'ordine può avere un reso, il
  massimo rendibile per riga e se il motivo propone il ricarico a magazzino.
- Per le righe con la spunta «Rientra a magazzino» il rientro passa da
  `Allocation::returnGoods()`: un movimento `return` sulla sede scelta, col
  reso come documento di riferimento.
- `complete()` chiude un reso ricevuto. `cancel()` lo annulla, ma **solo se
  nessuna riga è rientrata**: altrimenti `return.already_restocked` rimanda alla
  rettifica in Magazzino.
- Il reso **non** tocca `payment_status` né rimborsa: il denaro resta al gateway.

Una funzionalità accende l'altra a catena: `returns` richiede `orders`
(vedi [Funzionalità e ruoli](funzionalita.md)). Con `returns` spenta spariscono
la pagina *Registra reso*, la voce nel menu dell'ordine e la tabella dei resi
nella scheda.

## Azioni di massa nelle liste

`Support\Backend\ListBulkActions` dichiara le azioni di massa del core
(`TableLayoutSchema::bulkActions()`, vedi *Azioni di massa* nella doc di
wonder-image/app):

- `products()`: *Pubblica* / *Metti in bozza*, `BulkAction::toggle()` sulla
  colonna `visible` di `ProductModelResource`. `ProductResource` (le opzioni)
  ha il suo `tableLayoutSchema()` e non le eredita.
- `orders()`: *Conferma*, *Segna evaso*, *Annulla* su `OrderResource`. Ogni id
  passa da `OrderActions::available()` (stesse regole dei bottoni della scheda)
  e poi da `OrderActionResource::run()`, con la sua transazione: l'azione ha
  `transaction(false)`. Il permesso è `update` di `OrderActionResource`,
  perché quello di `OrderResource` non limita `update`.
  Le tre azioni hanno anche `->row(fn ($row) => …)`: compaiono nel menu dei
  tre puntini della riga solo se `OrderActions::available($row, $features)`
  le contiene (la riga di SSP ha `status`, `channel`, `fulfillment_*`). Su un
  core senza `row()` restano solo nella barra (`method_exists`).
- `promotions()`: *Attiva* / *Disattiva* (`BulkAction::toggle()` su `active`)
  ed *Elimina* (`BulkAction::delete()`), su `CouponResource` e
  `DiscountCampaignResource`. Niente voci di riga: il menu ha già i suoi
  bottoni.
- I messaggi di errore usano `{{rows}}` («Azione non disponibile per 1 riga
  per lo stato dell'ordine.»): il core lo sostituisce con il numero di righe.
- `apply()` non fa niente su un core senza azioni di massa.

Test: `tests/ListBulkActionsTest.php`.

## Il riquadro «Ordini» della home

`Backend\Widgets\OrdersWidget` (ordine 15, subito dopo `SetupWidget` in
`backend.home_widgets`) disegna quattro `StatusCard`: ordini del mese (con
quelli di oggi nel dettaglio) e le tre code di `Support\Orders\OrderQueues`:

- `in_attesa`: `status = 'pending'`;
- `da_evadere`: `status` in `Lifecycle::COMMITTED`, `fulfillment_status`
  `unfulfilled` o `partially_fulfilled`;
- `da_pagare`: le stesse condizioni di `OrderActions::canRegisterPayment()`.

Tutto su `OrderQueues::BASE`, le righe dell'elenco Ordini senza gli
abbandonati, in una query sola (`countSql()`). Le stesse condizioni sono il
filtro **Situazione** di `OrderResource` (`filterQuery('situazione')`), e
`OrderResource::filteredUrl(['situazione' => ...])` costruisce i link delle
card: numero e righe non possono divergere. Il periodo usa `MovementPeriod`
su `ordered_at`, come il filtro **Periodo**.

Lo vede chi vede le vendite, senza i commerciali (`SalesAgents::withoutAgents`,
come `AttentionWidget`); con `orders` spenta non disegna niente. `markup()` è
puro: `tests/OrderQueuesTest.php` lo prova senza database.

## Errori che il commerciante legge

I servizi lanciano `UserError::make('gruppo.chiave')`; la frase sta in
`lang/it/gestionale.json` sotto `errors.<gruppo>`. I gruppi di questa area sono
`order`, `cart`, `payment` e `return`. Le pagine mostrano la frase così com'è:
vedi [Errori e log](errori.md).

## Provare a mano e nei test

- `php forge gestionale:demo` crea ordini in tutti gli stati, pagamenti e, con
  `returns` accesa, un reso di 1 pezzo (`changed_mind`) sull'ordine evaso;
  `--fresh` li toglie e li rifà (con `customizations` accesa, uno su due
  porta l'incisione «Auguri»). Il `purge` restituisce al magazzino solo la
  merce che il reso non aveva già rimesso.
- Nei test d'integrazione, `tests/integrazione/supporto/compra.php` offre
  `articoloConGiacenza()`, `ordineDiProva()`, `resoDiProva()` e
  `accendiFunzionalita(['orders', 'returns'])`. Si lavora dentro una
  transazione che alla fine si annulla, così il database del sito di prova
  resta com'era.
- Con `office_sales` accesa, `gestionale:demo` crea anche due ordini da
  ufficio dal flusso vero: «bozza-ufficio» e «rate-ufficio», confermato in
  RiBa 30/60 con le rate che partono 31 giorni prima dell'inizio del mese
  (`installments_from`): la prima è sempre scaduta, la seconda no, qualunque
  sia il giorno. `tests/integrazione/OfficeOrdersTest.php` prova bozza,
  conferma e annullo di righe. `tests/integrazione/InstallmentsTest.php` prova i conti delle
  rate sul database.
- Per spegnere una funzionalità solo dentro la prova:
  `sqlModify(Feature::$table, ['enabled' => 'false'], 'feature_key', 'returns')`
  seguito da `Gestionale::reset()`.

## Trappole già pagate

- `Lifecycle::cancel()` rimette a magazzino solo la merce non ancora resa
  (`quantità − Returns::returned()`): quella resa è già rientrata col reso, o
  era rotta e non va a scaffale.
- `Lifecycle::confirm()` su un ordine già confermato non rifà niente e non
  riscarica la merce: ripassare da un webhook è sicuro.
- `Ledger::sync()` non scrive né logga se lo stato non cambia: un webhook
  ripetuto non riempie la storia di righe uguali.
- Una riga d'ordine che non è un prodotto (spedizione, sconto) non è rendibile:
  `Returns::lines()` mostra solo i prodotti.
