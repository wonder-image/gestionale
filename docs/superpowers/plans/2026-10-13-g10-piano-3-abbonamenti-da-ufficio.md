# G10 piano 3 — Abbonamenti da ufficio

Spec: [abbonamenti](../specs/2026-10-13-abbonamenti-design.md) §3 e §4 (G10.16).
Ramo `g10-piano-3`, sopra `g10-piano-2` **con `g9-piano-3` unito**: servono
`PaymentSchedule`, le condizioni di pagamento, lo Scadenzario (G9 piano 1) e
`DocumentPdf` (G9 piano 3). Si unisce su `main` dopo la `1.0.0`, dopo G9.

## Cosa fa

Il commerciante crea un abbonamento per un cliente: canale `office`, provider
`manual`, niente Stripe. A ogni periodo nascono le rate in attesa secondo la
condizione di pagamento, e parte un proforma PDF per email. Le rate si incassano
dallo Scadenzario o dalla scheda. Una rata scaduta manda un promemoria; oltre N
giorni l'abbonamento è *non pagato*. L'annullamento lo decide il commerciante.

## Task

1. **Rate degli abbonamenti.**
   - `Installments` lavora anche sugli abbonamenti: `createForSubscription()`,
     `pendingForSubscription()`.
   - `register()` riconosce una rata con `subscription_id`: stessi conti di
     `InstallmentMath`, nessun ordine da riallineare.
2. **`OfficeSubscriptions`.**
   - `create()`: cliente, prezzo, data d'inizio, metodo e condizione (predefiniti
     dalla scheda del cliente), coupon, nota. La fatturazione e l'aliquota sono
     come online.
   - `openPeriod()`: rate da `PaymentSchedule` dal `current_period_start`,
     importi da `SubscriptionAmounts` (l'addebito si conta dai mesi trascorsi),
     proforma ed email.
   - `registerPayment()`: dopo l'incasso, senza rate scadute, l'abbonamento torna
     `active`.
   - `changePlan()`: il nuovo prezzo vale dal periodo dopo.
   - `cancel()`: niente rinnovo; fine a fine periodo, o a fine vincolo.
   - `terminate()`: annullato subito, le rate in attesa annullate.
   - `daily()`: fine prova, nuovo periodo alla scadenza, fine dei disdetti, rata
     scaduta → `past_due` con promemoria, oltre i giorni dell'impostazione →
     `unpaid`.
3. **Proforma.**
   - `ProformaPdf` su `DocumentPdf` (tipo `proforma`, sostituibile con l'hook
     `documentPdf`): «non valido ai fini fiscali», importo, scadenze, IBAN del
     conto del metodo, periodo coperto.
   - Archiviato in `proformas/`, scaricabile dalla scheda.
4. **Pagine.**
   - *Nuovo abbonamento* (pagina-form, dal pulsante dell'elenco *Abbonamenti*).
   - Scheda dell'abbonamento:
     - *Registra pagamento* sulle rate (`SubscriptionPaymentResource`);
     - *Cambia piano* e *Disdici*, anche per gli online (§2.4: dal backend);
     - *Annulla* per gli abbonamenti da ufficio;
     - proforma dei periodi.
   - Scadenzario: le rate degli abbonamenti accanto a quelle degli ordini, con il
     link all'abbonamento e *Registra pagamento*.
5. **Impostazione e attività.**
   - «Giorni prima di *non pagato*» (`subscription_unpaid_days`, predefinito 15):
     impostazione tecnica, fuori dal sync, modificabile in produzione.
   - `OfficeSubscriptionsTask`: giornaliera, spenta di serie.
6. **Email:** proforma (con il PDF) e promemoria della rata scaduta.
7. **Contorno.**
   - Dati di prova: un abbonamento da ufficio su «Base» con la condizione a 30
     giorni.
   - Guida `vendite-abbonamenti.md` (da ufficio), CHANGELOG, TODO.
   - Test unitari e d'integrazione `OfficeSubscriptionsTest`.
   - PR.

## Scelte rispetto alla spec

- **Stato iniziale:** da ufficio l'abbonamento parte attivo (o in prova) alla
  data d'inizio, senza aspettare il primo pagamento: le rate hanno le loro
  scadenze, come gli ordini da ufficio. Se una rata scade non pagata,
  l'abbonamento passa a *da pagare*.
- Il proforma non ha numerazione: è un avviso di pagamento, non un documento
  fiscale.

## Test d'integrazione (sul sito di prova)

- creazione con la condizione a due rate: importi, scadenze, proforma, email;
- prova: nessuna rata fino a fine prova, poi il primo periodo;
- nuovo periodo alla scadenza, con il coupon che finisce dopo N mesi;
- rata scaduta → `past_due` con un solo promemoria; oltre i giorni → `unpaid`;
  incasso → `active`;
- disdetta a fine periodo e a fine vincolo; annulla con le rate annullate;
- Scadenzario: la rata dell'abbonamento si registra.
