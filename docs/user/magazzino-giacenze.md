---
icon: boxes
---

# Giacenze e rettifiche

> **Inclusa.** Fa parte del gestionale, non c'è niente da attivare.

La giacenza non si "imposta": ogni volta che cambia nasce un **movimento**, con
una causale che dice cosa è successo. Cambiano solo i posti da cui lo si fa.

## Dalla scheda del prodotto: il caso normale

Nella griglia **Opzioni in vendita** c'è la colonna **Giacenza**. Scrivi quanti
pezzi ci sono adesso, salvi, e il gestionale registra da sé il movimento della
differenza con causale *Inventario*. Non ti chiede altro: stai correggendo un
numero, non compilando un documento.

- Una casella che **non tocchi** non muove niente. Puoi sistemare due righe e
  lasciare stare le altre.
- Una riga **appena nata** parte con un movimento di *Giacenza iniziale*.
- Un **numero negativo** viene rifiutato: si scrive quanti pezzi hai, non di
  quanto cambiarli. Vale anche per gli articoli che si vendono senza giacenza
  (vedi [Sotto zero](#sotto-zero)).

Quando l'articolo si vende in un'unica opzione, la casella *Giacenza* sta in
alto nella scheda, accanto al prezzo, e si scrive allo stesso modo; sotto c'è
il link alla rettifica.

Con **più sedi** la casella della griglia mostra il totale e si legge
soltanto: i pezzi e la scorta minima si scrivono sede per sede, dal bottone
**Giacenza** dietro «Compila le informazioni avanzate» o, senza varianti,
dalle righe **Giacenza per sede** del riquadro *Magazzino* (vedi [Con più
sedi](catalogo-prodotti.md#con-piu-sedi)).

## L'elenco Giacenze

**Magazzino → Giacenze** serve a **consultare**: quanti pezzi hai di ogni
opzione in vendita e, se hai più sedi, in quale. Da qui non si cambia nessun
numero.

C'è una riga per ogni opzione, anche a zero e anche ferma, con foto, nome e
SKU. Poi:

- **Giacenza**: i pezzi che hai. Con più sedi si chiama **Totale**, e prima
  vengono le colonne delle sedi, una per sede, con il suo nome; gli zeri sono
  in grigio.
- **Scorta minima**, se hai gli [avvisi di scorta minima](magazzino-avvisi.md);
  con più sedi è quella della sede principale. Un'opzione sotto scorta ha il
  totale in rosso, con l'etichetta *sotto scorta*.
- **Impegnati** e **Disponibili**, se hai gli ordini: i pezzi già promessi a
  un ordine e quelli che puoi ancora vendere, contando tutte le sedi.

Ogni colonna si ordina con un clic sul titolo. In alto ci sono la ricerca
(nome dell'articolo, SKU o EAN) e i filtri *Stato*, *Marchio*, *Categoria*
(sottocategorie comprese) e, con gli avvisi, *Scorta*.

I tre puntini in fondo alla riga portano ai **Movimenti** di quell'opzione, già
filtrati, e alla sua scheda con **Apri la versione**.

### Quali sedi hanno una colonna

Le colonne delle sedi ci sono solo con la funzionalità *Più sedi* e almeno due
sedi da mostrare. Sono le sedi che tengono la giacenza, nell'ordine della
pagina [Sedi](sedi.md), più ogni altra sede che ha ancora dei pezzi: così la
somma delle colonne è sempre il *Totale*. Con una sede sola resta la colonna
*Giacenza*. Sono le stesse sedi che la scheda propone nelle righe *Giacenza
per sede*.

## Il primo carico

Appena installato il gestionale, i pezzi che hai si scrivono nella colonna
**Giacenza** della scheda dell'articolo, quando crei le opzioni: una riga
appena nata parte con un movimento di *Giacenza iniziale*. Per un'opzione che
esiste già usa la [rettifica](#rettificare-una-riga-sola), con la causale
**Giacenza iniziale**.

## Rettificare una riga sola

Il pulsante **Rettifica** nella scheda di una singola opzione in vendita — o il
link accanto alla casella *Giacenza*, negli articoli con un'unica opzione —
apre una pagina che chiede tre cose:

- **Come la scrivi:** *Adesso ce ne sono* (il totale che hai contato) oppure
  *Aggiungi o togli* (scrivi `-2` se ne hai buttati due).
- **Causale:** inventario, giacenza iniziale, danneggiato, scaduto, regalo, uso
  interno, altro.
- **Nota:** facoltativa, ma è quella che fra sei mesi spiega cosa era successo.

È la strada da fare quando la causale **non** è l'inventario: due pezzi rotti,
uno regalato, tre usati in negozio. Salvando torni da dove eri arrivato, e nei
*Movimenti* compare la riga nuova.

## Sotto zero

Di regola la giacenza **non va sotto zero**: un movimento che toglie pezzi e
ce la porterebbe — per esempio una rettifica che toglie più pezzi di quanti ce
ne sono — viene rifiutato con *Non c'è abbastanza giacenza*, e non si registra
niente.

Ci va solo su un articolo con **Vendita senza giacenza** accesa nel riquadro
**Come si vende** della sua scheda (vedi [I
prodotti](catalogo-prodotti.md#stato-come-si-vende-e-tipo-fiscale)). Servono
tutte e due le cose: la funzionalità *Vendita senza giacenza* sbloccata e
l'interruttore acceso su quell'articolo. Un articolo con l'interruttore spento
non ci scende, anche con la funzionalità sbloccata.

La merce che **arriva** invece si registra sempre, anche se la giacenza resta
sotto zero. Un articolo venduto scoperto fino a −5, con l'interruttore poi
spento, accetta un carico di 2 pezzi e sale a −3: i pezzi arrivati ci sono, e
rifiutarli lascerebbe la giacenza ancora più lontana dal vero.

Una giacenza sotto zero compare in [Da controllare](da-controllare.md), con il
pulsante **Rettifica**: quando la merce arriva, scrivi quanti pezzi ci sono
davvero e la riga sparisce.

## Dove si vede la giacenza

- Nell'elenco **Giacenze**: tutte le opzioni, sede per sede.
- Nella **scheda dell'articolo**: la colonna *Giacenza* della griglia, che si
  scrive con una sede sola; con più sedi si legge, e i pezzi stanno dietro il
  bottone *Giacenza* di ogni riga, sede per sede.
- Nella scheda di un articolo con **un'unica opzione**: la casella accanto al
  prezzo, che si scrive, con il link alla rettifica; con più sedi le righe
  *Giacenza per sede* del riquadro *Magazzino*.
- Nella scheda di una **singola opzione in vendita**: il riquadro *Magazzino*,
  con quanti pezzi ci sono — con più sedi, sede per sede — e gli ultimi dieci
  movimenti, e la *Scorta minima* se hai gli [avvisi](magazzino-avvisi.md).

Quello che è successo, tutto e in ordine di tempo, sta in
[Movimenti](magazzino-movimenti.md).
