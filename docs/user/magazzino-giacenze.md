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
  quanto cambiarli.

Quando l'articolo si vende in un'unica opzione, la casella *Giacenza* in alto
nella scheda si **legge** soltanto: accanto c'è il link alla rettifica.

## L'elenco Giacenze

**Magazzino → Giacenze** serve quando le righe da sistemare sono tante e stanno
su articoli diversi: è l'elenco di tutto quello che vendi, con la sua quantità
già scritta nella casella. Correggi quelle che devi correggere e salvi in un
colpo solo.

- In cima c'è la **causale di tutta la schermata**: di solito *Inventario*,
  perché si sta contando lo scaffale.
- Ogni riga cambiata diventa un movimento. Le righe che **lasci come sono** non
  ne fanno nessuno.
- **Uno zero scritto è uno zero vero:** diventa un movimento che svuota la riga.

In alto trovi la ricerca (nome, SKU o EAN), il pulsante *Solo sotto scorta* e
*Azzera i filtri*. Si lavora cinquanta righe per volta, con *Indietro* e
*Avanti* in fondo. Dopo il salvataggio torni esattamente dov'eri, filtri
compresi.

### Il primo carico

Appena installato il gestionale, il modo più veloce per caricare il magazzino è
proprio questo: apri *Giacenze*, metti la causale su **Giacenza iniziale**,
scrivi le quantità e salva.

## Rettificare una riga sola

Il pulsante **Rettifica** — nell'elenco *Giacenze*, o nella scheda di una
singola opzione in vendita — apre una pagina che chiede tre cose:

- **Come la scrivi:** *Adesso ce ne sono* (il totale che hai contato) oppure
  *Aggiungi o togli* (scrivi `-2` se ne hai buttati due).
- **Causale:** inventario, giacenza iniziale, danneggiato, scaduto, regalo, uso
  interno, altro.
- **Nota:** facoltativa, ma è quella che fra sei mesi spiega cosa era successo.

È la strada da fare quando la causale **non** è l'inventario: due pezzi rotti,
uno regalato, tre usati in negozio. Salvando torni da dove eri arrivato, e nei
*Movimenti* compare la riga nuova.

## Dove si vede la giacenza

- Nella **scheda dell'articolo**: la colonna *Giacenza* della griglia, che si
  scrive.
- Nella scheda di un articolo con **un'unica opzione**: la casella accanto al
  prezzo, in sola lettura, con il link alla rettifica.
- Nella scheda di una **singola opzione in vendita**: il riquadro *Magazzino*,
  con quanti pezzi ci sono e gli ultimi dieci movimenti.

Quello che è successo, tutto e in ordine di tempo, sta in
[Movimenti](magazzino-movimenti.md).
