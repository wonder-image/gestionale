# Revisioni scheda ordine (richieste dell'utente, 2026-10-01)

- [x] 2.1 `TableColumn::price()` nel core (il ramo `tablecolumn-money` di packages/app, PR wonder-image/app#52, modifica `price` e non aggiunge `money`) + colonna `total`
- [x] 1.7 Titolo pagina «Ordine {Numero}», via il doppio titolo
- [x] 1.1 Via il bottone «Elenco» (basta la chevron)
- [x] 1.2 «Registra pagamento» in una finestra (modal)
- [x] 1.3 Righe, Pagamenti, Resi, Storico: TableLayoutSchema + TableColumn, in accordion
- [x] 1.4 Foto del prodotto nella tabella Righe
- [x] 1.5 Cliente cliccabile → scheda cliente (stessa logica per il coupon, quando ci sarà)
- [x] 1.6 Note interne e note sul documento modificabili
- [x] Test (suite verde), CHANGELOG
- [ ] Push PR #3, CI; prova nel browser (serve il login); merge; PR del core #52 (ramo `tablecolumn-money`)
- [x] 3.1 Righe d'ordine: nome completo (`ProductNames::full()`) e foto copiata sulla riga (`image`)
- [x] 3.2 Scheda: *Totali* e poi *Riepilogo IVA* in alto a destra
- [x] 3.3 `price()` al posto di `money()` (core e gestionale)
- [x] 4.1 Note bloccate se l'ordine è evaso e pagato (lucchetto, salvataggio rifiutato)
- [x] 4.2 Cliente cliccabile → scheda cliente con: prodotti nel carrello, ordini, coupon assegnati (predisposti: la frase, i coupon sono G7), tutti i suoi dati, statistiche
- [x] Dati demo: Anna Verdi come cliente con i suoi ordini; l'ospite resta senza scheda
- [x] Prova nel browser, push, CI della PR #3, merge e pulizia (la prova è stata rifatta con il Piano 5, vedi sotto)

## Scheda cliente, seconda revisione (2026-10-01)

- [ ] 5.1 Core: componente `DataItem` (etichetta + valore in linea, azione accanto all'etichetta) — branch `data-item` di packages/app, PR a parte
- [ ] 5.2 Scheda ordine: l'intestazione usa `DataItem` al posto del `$dato` scritto a mano
- [ ] 5.3 Scheda cliente: Coupon in fondo (dopo ordini e carrello, e dopo gli indirizzi)
- [ ] 5.4 «Tutti i suoi dati» non è più un accordion: una card, senza «Chi è» e senza i dati di fatturazione, con `DataItem`
- [ ] 5.5 Card «Dati di fatturazione» in alto a destra (codice fiscale, P.IVA/SDI/PEC se azienda, indirizzo)
- [ ] 5.6 Card «Indirizzi di consegna» con una card per indirizzo; aggiungi / modifica / predefinito / elimina da modal (`ContactAddressResource`, un solo predefinito)
- [ ] Test (unitari + integrazione), CHANGELOG, prova nel browser, push e CI

## Prova nel browser del Piano 5 (2026-10-01, sito di prova, funzionalità Ordini e Resi accese)

Fatto: ordine evaso 2026/100045 con il reso di prova; *Registra reso* con motivo `damaged` (la spunta del rientro si toglie da sola, «Salva» resta attivo) e con `changed_mind`; *Chiudi* un reso; *Annulla* su un reso già rientrato (rifiutato: «usa una rettifica in Magazzino»); scheda cliente di Anna Verdi; finestre *Registra pagamento*, *Conferma* e *Annulla* (con l'avviso sul denaro da rimborsare).

- [x] 6.1 **500 su *Registra reso*** (`SelectForUpdate richiede una transazione attiva`): `Returns::returned()` bloccava le righe anche quando la pagina leggeva soltanto, fuori da una transazione. Ora blocca solo dentro una transazione; test `leggere quanto è reso e le righe di un ordine non chiede una transazione`. La suite non lo vedeva perché i test girano dentro `Transaction::run`.
- [x] 6.2 **`&#8212;` al posto di «—» nel nome della tabella Righe**: il database conserva le entità e il formatter le escapava una seconda volta. `GestionaleResource::escapeStored()` decodifica e poi escapa; usato per nome e SKU. Test in `OrderSectionResourcesTest`.
- [x] 6.3 `DefaultsTest` non svuotava i resi prima delle sedi: con un reso nel database condiviso la chiave esterna lo fermava. Ora toglie prima log, righe e resi (dentro la sua transazione).
- [ ] 6.4 Scheda cliente: «Speso» (159,20 €) somma anche l'ordine in attesa non pagato; da decidere se deve contare solo il pagato.
- [ ] 6.5 Il pulsante *Registra reso* nella testata dell'ordine con resi ancora possibili ha lo stesso aspetto grigio di un pulsante spento: valutare uno stile più chiaro.
