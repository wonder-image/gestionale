# G9 piano 1 — Condizioni di pagamento e scadenzario

Spec: [vendite da ufficio](../specs/2026-10-13-vendite-da-ufficio-design.md) §1
(G9.2, G9.3). Ramo `g9-piano-1`, sopra `g9-vendite-ufficio` (la spec). Si unisce su
`main` dopo la `1.0.0` (G9.1).

## Cosa fa

Condizioni di pagamento a rate in Set Up, il calcolo delle scadenze, le rate di un
ordine come pagamenti in attesa, lo scadenzario con il suo riquadro. Le rate
nascono **da `Installments::create()`**: in questo piano lo chiamano i test e i
dati di prova; la conferma dell'ordine da ufficio lo chiamerà nel piano 2.

## Task

1. **Dati.** `PaymentTerm` (`gst_payment_terms`) e `PaymentTermInstallment`
   (`gst_payment_term_installments`), sincronizzati come metodi e conti
   (`multiRow()->keepIds()->localOnly()`); codice `ptm_` in `Codes`. Tre righe
   precaricate in `Defaults`: Rimessa diretta, Bonifico 30 gg fine mese, RiBa 30/60
   gg fine mese.
2. **Regole pure.** `PaymentTermRules::assertInstallments()` (almeno una rata,
   percentuali a somma 100 con due decimali, giorni 0–365); `PaymentSchedule::of()`
   (scadenze, fine mese, ultima rata col resto); `InstallmentMath` (pura: pagamento
   su una rata minore o maggiore, ricalcolo proporzionale). Test unitari.
3. **Set Up → Condizioni di pagamento** (`PaymentTermResource`, `admin`, gruppo
   *Pagamenti*): nome, condizione SDI, stato, rate nel repeater (giorni, fine mese,
   percentuale). Una condizione usata da un ordine, da un cliente o da un
   pagamento non si elimina.
4. **Servizio `Installments`.** `create($orderId)`, `register($paymentId, $amount,
   $options)`, `recalculate($orderId)`, e il registro libero sull'ordine che chiude
   prima le rate più vicine (`OrderPaymentResource` passa da qui quando l'ordine ha
   rate in attesa).
5. **Scadenzario** (`ScheduleResource`, *Vendite → Scadenzario*, con
   `office_sales`): elenco delle righe `pending` con scadenza, filtri *Scaduti*,
   *In scadenza*, *Tutti in attesa*, *Pagati* (90 giorni), cliente; azione
   *Registra pagamento*. **Riquadro** `DuePaymentsWidget`. **Impostazione**
   «Giorni di preavviso per le scadenze» (`payment_due_days`, predefinita 7, fuori
   dal sync, modificabile in produzione).
6. **Scheda cliente:** metodo e condizione di pagamento predefiniti (colonne già
   presenti), la condizione solo con più di una attiva (D20).
7. **Contorno:** dati di prova (un ordine da ufficio con due rate, una scaduta),
   guida `pagamenti-a-rate.md` e ritocchi a *Ordini*, guida sviluppatori, CHANGELOG,
   TODO, test d'integrazione (`InstallmentsTest`), PR.

## Test d'integrazione (sul sito di prova)

- tre rate da RiBa 30/60 su un ordine: importi, scadenze, somma;
- pagamento uguale, minore (resto con la stessa scadenza) e maggiore (scala le
  successive, una azzerata diventa `cancelled`), oltre il totale rifiutato;
- ricalcolo dopo un totale più basso, e con il pagato oltre il nuovo totale;
- registro libero su un ordine con rate;
- condizione in uso che non si elimina.
