# G9 piano 3 — Preventivi, PDF ed email

Spec: [vendite da ufficio](../specs/2026-10-13-vendite-da-ufficio-design.md) §3
(G9.9–G9.13, G9.17). Ramo `g9-piano-3`, sopra `g9-piano-2`. Si unisce su `main`
dopo la `1.0.0` (G9.1).

## Cosa fa

Il preventivo con lo stesso editor dell'ordine da ufficio, inviato con il suo PDF
e le revisioni, accettato (diventa una bozza d'ordine), rifiutato o scaduto. I
PDF di preventivo e conferma d'ordine, sostituibili dal sito. Le email del
preventivo e della conferma da ufficio con il PDF allegato.

## Decisioni del piano

- **Un servizio solo per bozza d'ordine e preventivo:** `OfficeOrders::save()`
  prende lo `stage` (`order` o `quote`); un preventivo inviato, rifiutato o
  scaduto che si modifica torna `draft` (da inviare) e al prossimo invio la
  revisione sale.
- **Numero e revisione all'invio:** il primo invio dà `quote_number`
  (`DocumentSequences::next('quote')`) e la revisione 1, ogni invio dopo una
  modifica la revisione +1. Ogni invio archivia il suo PDF in
  `gst_order_quote_revisions`.
- **Validità:** `quote_expires_at` dalla pagina, predefinita dalle impostazioni
  (`quote_validity_days`, 30, fuori dal sync); `QuoteExpiryTask` (una volta al
  giorno, spenta come le altre) porta a `expired` gli inviati oltre la data.
- **PDF:** `DocumentPdf` sopra `Wonder\Pdf` (FPDF, testo in Windows-1252):
  intestazione con i dati della società, cliente, righe, totali e riepilogo
  IVA, note stampate, pagamento. `QuotePdf` e `OrderConfirmationPdf`. Il sito
  sceglie la sua classe con l'hook `documentPdf(string $type, string $class)`
  di `GestionaleExtension`. I file in `storage/gestionale/documents`, non
  pubblica; si scaricano da una rotta del backend con i permessi.
- **La conferma d'ordine** non si archivia: il PDF si fa quando si scarica o si
  manda (l'ordine confermato cambia solo con *Annulla righe*).
- **Email:** `Mailer` e `OrderNotifier` accettano allegati (il core li sa già
  mandare); chiavi nuove `quote_sent` e `office_confirmed`.

## Task

1. **Allegati e PDF.** Allegati in `Mailer::send()` e `OrderNotifier::send()`;
   `DocumentPdf`, `QuotePdf`, `OrderConfirmationPdf`, `DocumentFiles`
   (cartella, nome, lettura), hook `documentPdf`. Test unitari (il PDF nasce,
   il testo con € e accenti si legge, la classe del sito vince).
2. **Preventivi: dati e servizio.** `OrderQuoteRevision`
   (`gst_order_quote_revisions`); `quote_validity_days`; `Quotes::send()`,
   `accept()`, `reject()`, `delete()`, `expireDue()`; `QuoteExpiryTask`;
   `quotes` costruita. Test d'integrazione `QuotesTest`.
3. **Pagine.** *Vendite → Preventivi* (`QuoteResource`, con `quotes`): elenco con
   stato, cliente, totale, scadenza; la pagina del preventivo con l'editor
   dell'ordine, la validità, *Invia al cliente*, *Accetta*, *Rifiuta*, le
   revisioni con il loro PDF. Download dei PDF; *Scarica PDF* sulla scheda di
   un ordine da ufficio; la conferma da ufficio manda `office_confirmed` con il
   PDF.
4. **Contorno:** dati di prova (un preventivo inviato), guida
   `vendite-preventivi.md`, guida sviluppatori (PDF sostituibili), CHANGELOG,
   TODO, PR.

## Test d'integrazione (sul sito di prova)

- preventivo salvato senza numero; invio: numero, revisione 1, PDF archiviato,
  email con allegato; modifica e nuovo invio: revisione 2, due PDF;
- accetta: lo stesso documento è una bozza d'ordine con le stesse righe e il
  numero di preventivo; rifiuta; scaduti segnati dall'attività;
- un preventivo non tocca mai il magazzino.
