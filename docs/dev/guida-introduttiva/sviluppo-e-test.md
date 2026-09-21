---
icon: flask
---

# Sviluppo, test e sito di prova

## Sito di prova

`boilerplates/ecommerce-site` risponde su `https://ecommerce.test` con il
database `ecommerce_site` e ha il modulo collegato da repository `path`. È lì
che si prova tutto: `forge update`, il backend nel browser, gli screenshot
della guida.

```bash
cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site
php forge update
```

`APP_ENV=local` nel `.env` tiene il sito in locale; mettendo `production` si
provano la sola lettura e la sincronizzazione — ricordarsi di rimetterlo.

## Test

```bash
php tests/run.php            # tutto
php tests/TaxTotalsTest.php  # un file solo
```

| Livello | Cosa copre | Database |
|---|---|---|
| Unitari | classi pure: codici, numeri, IVA, stato delle funzionalità, controlli dei Primi passi | no |
| Convenzioni | ogni Resource dichiara la sua funzionalità, prefisso `gst_`, id stabili nelle tabelle sincronizzate | no |
| Integrazione | righe precaricate, numerazione, log degli stati, sync, sola lettura | `ecommerce_site` |

I test d'integrazione stanno in `tests/integrazione/` e girano **dentro una
transazione che annulla sempre**, anche quando falliscono: il database del sito
resta com'era. Le righe che servono se le creano da soli, senza dare per
scontato cosa c'è nel sito.

GitHub Actions esegue unitari e convenzioni a ogni push; l'integrazione resta
locale, perché vuole il database.

## Comandi

```bash
php forge gestionale:demo              # dati di prova, solo in locale
php forge gestionale:demo --fresh      # rifà da capo
php forge gestionale:features-doc      # riscrive la tabella nella guida
```

## Trappole già pagate

- Il registro dei Model legge la **prima** parola `class` del file, commenti
  compresi: un esempio di codice in un docblock fa fallire il boot.
- `Model::prepare()` non genera i codici `uniqueCode()`: chi inserisce da codice
  se li fa dare dal Model.
- Una colonna che vive in un'altra tabella si mostra nell'elenco con un
  formatter a closure, non dichiarandola e basta.
- `$ALERT` non vuoto significa errore e blocca il salvataggio: per dire che è
  andata bene c'è il toast.
