# Caricamento del catalogo da file

Nell'elenco dei prodotti il bottone «File» ha due schede, Esporta e Importa; «Importa» apre la pagina di caricamento del core
(`ImportResource`, vedi `docs/app/concetti/importazione.md` nel pacchetto
`wonder-image/app`) col tracciato `catalog`:
`Support\Catalog\Import\CatalogLayout`, registrato in `config/module.php`
(`import.layouts`). `ProductModelResource::tableLayoutSchema()` la dichiara con
`importLayout(CatalogLayout::KEY)`, senza `if`: i permessi li decide
`CatalogLayout::permits()`. La scheda e la pagina ci sono solo con la
funzionalità `import_catalog` accesa (`Gestionale::feature()`) e le vede chi può
fare `update` su `ProductModelResource`.

Il file è lo stesso dell'esportazione (`ProductExport`): stesse colonne, stesse
intestazioni, più qualche nome comune (`Codice`, `Quantità`, `Nome`,
`Prezzo`…). Un tracciato salvato in Dev → File → Tracciati file vale
per l'esportazione e per il caricamento. La voce è
`Resources\System\ImportLayoutResource`: sottoclasse di quella del core con lo
stesso `path()`, che la sostituisce e la mostra solo con `import_catalog`
accesa; il posto nel menu resta quello del core.

Nella scheda «Esporta» del bottone «File» dei prodotti i tracciati del catalogo compaiono sotto
«Tracciati», solo per Excel e CSV: `ProductModelResource` dichiara
`->downloadLayouts(CatalogLayout::KEY, ProductExports::LAYOUT_FORMATS)` accanto
a `downloadUsing()`, e il gestore riceve l'id scelto
(`ProductExports::send($format, $layoutId)`). `ProductExports::layoutColumns()`
legge le colonne con `Profile::for()` (null senza tracciato o con un tracciato
di un'altra fonte: allora il file ha le colonne di sempre); gli attributi si
leggono dal DB solo se il tracciato ha una colonna `attr:<id>`
(`wantsAttributes()`). Il tracciato predefinito non si applica da solo
all'esportazione: le voci Excel e CSV in alto restano complete.

## Colonne e file di esempio

`CatalogLayout::columns()` dice al core a che dato corrisponde ogni colonna
(`Column::field()`, dalla mappa `FIELDS`): all'abbinamento la pagina mostra
quali colonne del file sono riconosciute, quali si ignorano e quali mancano
(`Mapping::report()`). Lo SKU è `required()`: senza, il core ferma l'anteprima
con `missing_column`. Il tracciato controlla da sé solo l'IVA dei prezzi
(`mappingErrors()`).

Sotto la scelta del file, «File di esempio» dà un `.xlsx` o un `.csv` con le
colonne di `sampleColumns()` e le righe di `sampleRows()` (un prodotto
semplice e uno con due versioni, SKU `ESEMPIO-*`): ricaricato così com'è passa
l'anteprima e crea quei prodotti.

## Una riga, una versione

La chiave è l'**ID** del prodotto, quando la riga ce l'ha e il prodotto
esiste (`exportById()`); altrimenti lo **SKU** (senza la colonna SKU il
caricamento non parte). Con l'ID il file esportato si ricarica anche se il
catalogo ha SKU vuoti o ripetuti: lo SKU invariato non si controlla, quello
cambiato non può essere già di un altro prodotto (`sku_taken`) né vuoto.
Un ID che non c'è (file di un altro negozio) torna allo SKU.

| Lo SKU… | Codice articolo | Azione |
| --- | --- | --- |
| c'è | vuoto o uguale | `update`: solo le celle diverse da adesso (`unchanged` se nessuna) |
| c'è | diverso | errore |
| non c'è | di un articolo che c'è (o nuovo, già visto sopra nel file) | `new_version`: una versione in più (`Generator::newVariant()` + `Skeleton::product()`) |
| non c'è | vuoto o nuovo | `new_model`: un articolo nuovo di tipo «Prodotto», serve il nome |

Il tipo di un articolo non si cambia; multiprodotti e abbonamenti non si
creano e non prendono versioni da file. Uno SKU nuovo o cambiato ripetuto nel
file è un errore (`sku_duplicate`); senza ID, uno SKU che nel catalogo hanno
più prodotti ferma la riga (`sku_ambiguous`), perché non si sa quale
aggiornare.

## Le celle

Le legge `CatalogCells`, pura: numeri all'italiana (`1.234,50 €`), Sì/No coi
sinonimi, liste con `|`, categorie `Padre › Figlia` (la prima è la
principale, quelle che mancano si creano), descrizioni confrontate in testo
semplice. `link` si ignora; `id` serve solo a trovare il prodotto, non si scrive. Una cella vuota di codice articolo, nome,
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
