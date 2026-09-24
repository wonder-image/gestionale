---
icon: sliders
---

# Attributi

> **Inclusa.** Fanno parte del gestionale, non c'è niente da attivare.

## A cosa servono

Un attributo è **quello che distingue un articolo da un altro**: il colore, la
taglia, il materiale, il peso. Si scrivono una volta sola e poi si scelgono
nelle schede dei prodotti, invece di riscriverli ogni volta.

Sono anche quello che fa nascere le **opzioni in vendita**: spunti *Blu*,
*Rosso*, *S*, *M* e *L* nella scheda di una maglietta e vengono fuori sei righe
da vendere, ognuna con il suo codice, il suo prezzo e la sua giacenza.

## "Uso": la scelta importante

Quando crei un attributo, il gestionale chiede **a cosa serve**. È la domanda
che conta di più, perché decide dove lo incontrerai dopo. Le risposte
dipendono dal tipo (vedi sotto): un attributo che si sceglie da un elenco può
far nascere delle opzioni, uno che si scrive a mano no.

Per *Elenco*, *Colore*, *Fantasia* e *Icona*:

| Risposta | Cosa fa | Esempio |
|---|---|---|
| **Scheda tecnica dell'articolo** | uno o più valori per articolo, da spuntare nella scheda tecnica; non fa nascere niente da vendere | Lavaggio: 30°, non candeggiare |
| **Opzione da scegliere** | ogni valore spuntato fa nascere delle opzioni in vendita, su una pagina sola | Taglia: S, M, L |
| **Opzione con foto proprie** | come la precedente, ma ogni valore ha le sue foto | Colore, in un negozio dove ogni colore si fotografa |

Per *Testo* e *Numero*:

| Risposta | Cosa fa | Esempio |
|---|---|---|
| **Scheda tecnica dell'articolo** | un valore per articolo | Paese di produzione |
| **Scheda tecnica di ogni opzione** | un valore per ogni opzione in vendita, perché cambia dall'una all'altra | Peso: la L pesa più della S |

Il modo semplice di decidere fra le due opzioni: **le fotografi diverse?** Se
sì, è quella con foto proprie. Se cambia solo quello che prendi dallo scaffale,
è l'opzione da scegliere.

Questa scelta si fa **una volta per negozio**: se nel tuo il colore ha foto
sue, le ha per tutti gli articoli. Chi compila la scheda di un prodotto non deve
più pensarci: spunta i valori e basta.

**L'uso non si cambia su un attributo che sta già sugli articoli.** I valori
scritti fin lì stanno dove li ha messi quell'uso, e con un altro nessuno li
leggerebbe più: la casella si spegne. Se ti serve un uso diverso, crea un
attributo nuovo.

Su uno stesso articolo puoi usarne **al massimo tre**, e **uno solo** può avere
foto proprie. Il pannello te lo dice in tutti e due i casi.

## Quando un attributo compare nelle opzioni in vendita

Nel riquadro **Opzioni in vendita** della scheda prodotto trovi un attributo
quando valgono tutte e quattro:

1. è **visibile** (non nascosto);
2. il suo uso è **Opzione da scegliere** oppure **Opzione con foto proprie**;
3. il tipo è **Elenco**, **Colore**, **Fantasia** o **Icona**;
4. ha **almeno un valore**.

Se nel negozio non c'è nessun attributo così, la scheda non chiede nemmeno
«Questo articolo ha varianti?»: non avrebbe niente da offrire.

## Il tipo: come si scrive il valore

| Tipo | Cosa fa |
|---|---|
| **Elenco** | scegli da una lista che prepari tu (S, M, L, XL) |
| **Colore** | come l'elenco, ma ogni voce ha anche il suo colore |
| **Fantasia** | come l'elenco, ma ogni voce ha anche un'immagine: un tessuto scozzese, una stampa a fiori |
| **Icona** | come l'elenco, ma ogni voce ha un simbolo, cioè un'immagine sua, piccola: «Impermeabile», «Lavabile in lavatrice», «Spedizione rapida» |
| **Testo** | si scrive a mano, valore per valore |
| **Numero** | si scrive a mano, con l'unità di misura accanto (g, cm) |

Solo *Elenco*, *Colore*, *Fantasia* e *Icona* fanno nascere opzioni in
vendita: si spunta da un elenco, e un elenco deve esistere.

## Creare un attributo

1. **Catalogo → Attributi → Aggiungi attributo**.
2. Scrivi il **nome** ("Colore", "Taglia", "Materiale") e scegli il **tipo**.
3. **Unità di misura**: accanto al tipo, compare solo su *Numero* e *Testo*,
   ed è quella con cui si misura il valore — grammi, centimetri. Si sceglie da
   un elenco, così due schede non scrivono "g" e "grammi" per la stessa cosa.
4. Rispondi a **"Uso"**.
5. **Filtro**: se in vetrina il cliente potrà cercare per questo attributo.
6. Salva.

La scheda **segue il tipo mentre lo scegli**, senza bisogno di salvare: chiede
solo quello che serve.

Il riquadro **Valori** c'è per *Elenco*, *Colore*, *Fantasia* e *Icona*: *Aggiungi
valore* per ogni voce. Ogni riga ha il **valore**, cioè il nome, e in più:

| Tipo | Cosa chiede la riga |
|---|---|
| **Elenco** | solo il valore |
| **Colore** | il valore e il **colore**, il pallino che il cliente vede in vetrina |
| **Fantasia** | l'**immagine**, per quando un colore solo non basta a far capire com'è, e il valore |
| **Icona** | il valore e la sua **immagine**: meglio un PNG o un WebP quadrato, con lo sfondo trasparente, perché si mostra piccola, a 16×16 pixel. Va bene anche un JPG, ma lo sfondo resta pieno |

Dietro *Aggiungi una descrizione* c'è una frase per spiegarlo a chi compra.
L'ordine è quello che vedrà il cliente e si cambia con le frecce.

Se scegli il tipo "Testo" o "Numero" i valori non servono — quello lo scrivi tu
prodotto per prodotto — e il riquadro sparisce.

## Cose da sapere

**I valori si possono creare anche dalla scheda di un prodotto**, con il
pulsante **+** accanto alle spunte: è comodo quando ti accorgi lì che il colore
manca. Finiscono comunque qui, nell'elenco del negozio, e li ritrovi su tutti
gli altri articoli.

**Rinominare un valore lo rinomina ovunque.** Se scrivi "Blu notte" al posto di
"Blu", al primo salvataggio i prodotti che lo usano si chiamano "Blu notte / M".
È l'unico posto da cui si cambiano quei nomi: nella scheda del prodotto non si
scrivono.

**Fra Elenco, Colore, Fantasia e Icona si passa quando vuoi.** I valori
restano, e anche i colori e le immagini che avevi messo: la scheda smette solo di
mostrarli, e tornando al tipo di prima li ritrovi.

**Verso Testo o Numero il tipo non si cambia se ci sono già dei valori.**
Cancellerebbe l'elenco che hai preparato: il gestionale si ferma e te lo dice.
Se vuoi davvero cambiarlo, elimina prima i valori.

**L'unità di misura vale solo per Testo e Numero.** Se passi a un altro tipo,
al salvataggio si svuota.

**Un attributo usato non si elimina, si nasconde.** Mettendolo su "Nascosto"
sparisce dalle schede nuove, ma i prodotti che l'avevano tengono il loro valore.

**Il nome lo leggono i clienti.** "Colore" e non "col", "Taglia" e non "size".

**Un valore creato dal + della scheda prodotto nasce senza colore né
immagine.** Lo completi da qui, quando hai tempo.

**Le immagini SVG non si caricano.** Un SVG è un documento che può contenere
codice: per le icone usa PNG o WebP.

## Dove si usano

Nella scheda del prodotto.

- Quelli che **descrivono** stanno nel riquadro *Scheda tecnica*, in fondo alla
  colonna di sinistra. Una caratteristica di **Testo** o di **Numero** si crea
  anche da lì, con **Nuova caratteristica**, senza passare da questa pagina;
  un elenco con i suoi valori (e le loro immagini) si prepara qui.
- Gli altri stanno in **«Opzioni in vendita»**, il riquadro subito sotto
  «Prodotto»: premi *«Aggiungi un attributo»*, scegli quello che ti serve,
  spunta i valori, e le righe da vendere compaiono nella griglia con
  il loro codice proposto, pronte da prezzare.

Come funziona quel riquadro è scritto in [I prodotti](catalogo-prodotti.md).

## La personalizzazione non è un attributo

Un'incisione, un nome ricamato, un biglietto d'auguri: sono cose che scrive chi
compra, non cose che hai a magazzino. Per questo non si fanno con gli
attributi, che descrivono quello che l'articolo è e fanno nascere opzioni da
tenere in giacenza.

Arriveranno insieme agli ordini, come un **elenco a parte**, simile a questo:
«Incisione, massimo 20 caratteri, +5 €» si prepara una volta e si spunta sugli
articoli che la offrono. Il sovrapprezzo sta nell'elenco; se è obbligatoria lo
decidi articolo per articolo, perché la stessa incisione è facoltativa su una
penna e obbligatoria su una targa.
