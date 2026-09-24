# Changelog

Il formato segue [Keep a Changelog](https://keepachangelog.com/it/1.1.0/) e il
versionamento semantico.

## 0.1.0 — non rilasciata

### Aggiunto

- Scheletro del modulo: manifest, entrypoint, configurazione, Resource base.
- Test del modulo con harness proprio e `php tests/run.php`.
- Funzionalità sbloccabili: catalogo nel codice, stato su database sincronizzato
  con `id` stabili, pannello "Funzionalità" con un interruttore per funzionalità
  (`formSchema` e `formLayoutSchema` su una pagina-form del core `2.2.4`, con il
  campo `toggle` e il contenitore `masonry`), righe
  precaricate create da `forge update`, pagine che spariscono quando la
  funzionalità è bloccata.
- Documentazione: `gitbook-docs.yaml` con gli spazi GitBook "Sviluppatori"
  (`docs/`) e "Guida commercianti" (`guide/`), con le prime pagine.
- Avvisi di scorta minima (`low_stock_alerts`): campo *Scorta minima* nelle due
  schede, avviso aperto e chiuso da `Stock::apply()` e dal salvataggio della
  soglia, attività `gestionale.stock_alerts` (ogni quarto d'ora, nata spenta)
  che manda una email raggruppata ai *Destinatari degli avvisi* con la view
  sovrascrivibile e l'hook `beforeEmailSend` (`stock.low_stock`), anteprima
  `php forge gestionale:stock-alerts`, riquadro *Sotto scorta* nella home e
  giacenze negative in *Da controllare*.
