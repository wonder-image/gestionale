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
- [ ] Prova nel browser (serve il login); push, CI della PR #3, merge e pulizia
