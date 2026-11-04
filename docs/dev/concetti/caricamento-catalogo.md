# Caricamento del catalogo da file

«Importa» nell'elenco dei prodotti apre la pagina di caricamento del core
(`ImportResource`, vedi `docs/app/concetti/importazione.md` nel pacchetto
`wonder-image/app`) col tracciato `catalog`:
`Support\Catalog\Import\CatalogLayout`, registrato in `config/module.php`
(`import.layouts`). Il bottone e la pagina li vede chi può fare `update` su
`ProductModelResource`.

Il file è lo stesso dell'esportazione (`ProductExport`): stesse colonne, stesse
intestazioni, più qualche nome comune (`Codice`, `Quantità`, `Nome`,
`Prezzo`…). Un tracciato salvato in Set-up → Tracciati file vale per
l'esportazione e per il caricamento.

## Una riga, una versione

La chiave è lo **SKU** (senza la colonna il caricamento non parte).

| Lo SKU… | Codice articolo | Azione |
| --- | --- | --- |
| c'è | vuoto o uguale | `update`: solo le celle diverse da adesso (`unchanged` se nessuna) |
| c'è | diverso | errore |
| non c'è | di un articolo che c'è (o nuovo, già visto sopra nel file) | `new_version`: una versione in più (`Generator::newVariant()` + `Skeleton::product()`) |
| non c'è | vuoto o nuovo | `new_model`: un articolo nuovo di tipo «Prodotto», serve il nome |

Il tipo di un articolo non si cambia; multiprodotti e abbonamenti non si
creano e non prendono versioni da file. Uno SKU ripetuto nel file è un errore.

## Le celle

Le legge `CatalogCells`, pura: numeri all'italiana (`1.234,50 €`), Sì/No coi
sinonimi, liste con `|`, categorie `Padre › Figlia` (la prima è la
principale, quelle che mancano si creano), descrizioni confrontate in testo
semplice. `id` e `link` si ignorano. Una cella vuota di codice articolo, nome,
tipo, unità, prezzo, peso e disponibili non cancella niente.

- **Prezzi.** L'intestazione dice «IVA inclusa» o «IVA esclusa»: se
  contraddice l'impostazione del negozio il file è rifiutato all'abbinamento.
- **Disponibili.** Il numero è la giacenza voluta: la differenza diventa un
  movimento con causale interna `import` (`reference_type` `import`, id del
  lavoro). «Illimitata» o vuota non tocca niente; sui multiprodotti e sugli
  abbonamenti la colonna va lasciata com'era.
- **Immagine.** Un indirizzo `https://` (anche dopo i reindirizzamenti) di un
  PNG, JPG o WebP fino a 8 MB: `RemoteImage` lo scarica e lo mette in coda
  come una foto caricata a mano (`pending`). Il file si chiama `md5(url)`, così
  ricaricare lo stesso file non duplica la foto. Un indirizzo non https è un
  avviso, un download fallito un avviso sulla riga: la riga si scrive lo stesso.

## Annullare

Ogni riga registra le sue `Change`: i record creati (articolo, versione,
prodotto, marchio, categoria, valore, foto) si annullano con `deleted =
'true'` scritto diretto (`prepare()` scarterebbe la chiave), quelli aggiornati
tornano ai valori di prima, la giacenza con un movimento opposto di causale
`import_undo`.

## Test

```bash
php tests/integrazione/CatalogImportTest.php
```

Gira sul sito di prova (dati demo, scritture annullate alla fine); le foto le
dà un fetcher finto (`RemoteImage::useFetcher()`), mai la rete.
