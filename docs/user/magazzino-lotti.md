---
icon: barcode
---

# Lotti e scadenze

> **Da attivare.** Funzionalità *Lotti e scadenze*. Finché è spenta non vedi
> lotti né scadenze, e il magazzino funziona come sempre.

## A cosa serve

Sapere quale **lotto** di un prodotto hai in magazzino, **quando scade** e, se
un lotto va richiamato, **a quali clienti l'hai venduto**. Serve per alimentari,
cosmetici, integratori e per tutto quello che ha un numero di lotto sulla
confezione.

## Quali prodotti hanno i lotti

Nella scheda dell'articolo accendi **Gestisci lotti e scadenze**. Vale per
tutte le sue opzioni; i prodotti composti non lo hanno (i lotti sono dei loro
componenti). Gli altri articoli restano come prima.

Non si può spegnere finché ci sono pezzi in un lotto: scaricali prima, o
fai l'inventario.

## Il carico con i lotti

In **Magazzino → Carichi → Nuovo carico**, per un articolo con i lotti ogni
riga ha due caselle in più:

- **Lotto**: il numero scritto sulla confezione. È obbligatorio per
  confermare.
- **Scadenza**: la data scritta sulla confezione. Si può lasciare vuota se il
  prodotto non scade.

Se nello stesso carico arrivano **due lotti** dello stesso prodotto, premi
**+ lotto** sulla riga: si apre una riga nuova subito sotto, per il secondo
lotto. Con il lettore di codici a barre, il primo pezzo letto porta il cursore
sulla casella *Lotto*; gli altri pezzi si aggiungono all'ultima riga di quel
prodotto.

Un lotto con lo stesso numero di uno già caricato **è lo stesso lotto**: i
pezzi si sommano. Se scrivi una scadenza diversa da quella che il lotto ha già,
il gestionale ti ferma, perché uno dei due è sbagliato.

## Da quale lotto esce la merce

Una vendita, uno scarico o una rettifica in meno prendono la merce da sé:

1. prima quella **senza lotto** (quella che avevi prima di attivare i lotti);
2. poi dal lotto che **scade prima**; i lotti senza scadenza per ultimi.

I lotti **scaduti non si vendono**: escono dal disponibile del negozio e lo
scarico automatico non li prende. Il giorno della scadenza si vende ancora.

Nello **scarico** puoi scegliere a mano il lotto di ogni riga (tendina
**Lotto**; i lotti scaduti sono in rosso): è così che butti un lotto scaduto.
Con *Acquisti* attiva puoi scegliere anche il **Fornitore**. Lasciate su
*automatico*, valgono le regole qui sopra.

## La pagina Lotti

In **Magazzino → Lotti** trovi i lotti con i loro pezzi. La scadenza è:

- **rossa** se il lotto è scaduto;
- **gialla** se scade entro i giorni che hai scelto (30, se non li cambi).

Il filtro **Stato** parte da *Con pezzi*; puoi guardare *In scadenza*,
*Scaduti*, *Esauriti* o *Tutti*. Con più sedi c'è anche il filtro **Sede**. La
ricerca trova il numero del lotto, il nome del prodotto e lo SKU.

I giorni del giallo si cambiano in **Set Up → Impostazioni**, riquadro
**Magazzino**, *Giorni prima della scadenza*.

In home il riquadro **Lotti in scadenza** elenca i primi dieci lotti con pezzi
scaduti o in scadenza.

## La scheda del lotto

Aprendo un lotto vedi:

- il prodotto, la scadenza, il fornitore (con *Acquisti*) e i pezzi, divisi
  per sede se ne hai più di una;
- i **clienti che l'hanno ricevuto**: ordine, data, cliente, email, telefono e
  pezzi, già al netto di ordini annullati e resi;
- gli ultimi **movimenti** del lotto.

Con **Modifica** correggi la scadenza o scrivi una nota. Il numero del lotto
non si cambia. Un lotto che ha movimenti non si elimina.

## Un richiamo, passo per passo

1. Cerca il numero del lotto in **Magazzino → Lotti** (filtro *Stato* su
   *Tutti*, se il lotto è già finito) e aprilo.
2. In **Clienti che l'hanno ricevuto** premi **Scarica i clienti**: ottieni un
   file che si apre con Excel, con ordine, data, cliente, email, telefono e
   pezzi.
3. Contatta i clienti dell'elenco.
4. Per la merce del lotto ancora in magazzino fai uno **scarico** con causale
   adatta (per esempio *Scaduto* o *Danneggiato*) scegliendo quel lotto nella
   tendina **Lotto**.
5. I resi dei clienti rientrano nel lotto da cui era uscita la merce: puoi
   scaricarli allo stesso modo.

## Dove vedi i lotti

- **Carichi e scarichi**: lotto, scadenza e fornitore scelto sotto il nome di
  ogni riga.
- **Giacenze**: la colonna **Scaduti**, con i pezzi dei lotti scaduti.
- **Movimenti**: la colonna **Lotto**; la ricerca trova anche il numero del
  lotto.
