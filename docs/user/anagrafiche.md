---
icon: people
---

# Persone, aziende e fornitori

> **Inclusa.** I clienti ci sono sempre. I **fornitori** compaiono solo con la
> funzionalità *Acquisti* sbloccata.

## Con chi lavora il gestionale

Due interruttori di **Set Up → Funzionalità**, area *Anagrafiche*, dicono a chi
vendi:

| Clienti privati | Aziende | Il gestionale lavora con… | L'elenco delle persone si chiama |
|---|---|---|---|
| accesa | accesa | persone e aziende (di serie) | **Persone** |
| accesa | spenta | solo persone | **Clienti** |
| spenta | accesa | solo aziende | **Referenti** |

Tutte e due spente non si può: il pannello lo rifiuta.

- **Solo persone** — per chi vende a privati. Spariscono dal menu e dalle
  schede le **Aziende** e quello che è solo loro: brand, referenti,
  capogruppo, reparti, e le funzionalità *Brand*, *Gruppi di aziende* e
  *Account aziendali*, che si spengono da sole perché hanno bisogno delle
  aziende. Le pagine delle aziende non si
  aprono nemmeno dall'indirizzo. I **fornitori** restano (sono sempre
  aziende, li governa *Acquisti*), con i loro dati ma senza brand,
  capogruppo e referenti.
- **Solo aziende** — per chi vende solo a ditte. Le persone ci sono come
  **referenti** delle aziende: l'elenco si chiama *Referenti*, ogni persona
  nuova va legata a un'azienda e un cliente privato non si crea più.
- **Persone e aziende** — tutto quello che c'è in questa pagina.

Cambiare modo **nasconde e non cancella**: schede, legami, brand e documenti
restano nel database e tornano quando riaccendi l'interruttore. Un ordine già
fatto con un cliente che il modo nasconde si riapre e si salva com'è; il
cliente nuovo di un ordine, invece, deve essere del tipo con cui lavori.

## Persone e aziende

La rubrica ha due tipi di scheda:

- **Persone** — chi compra per sé, i referenti delle aziende, chi ha un
  accesso al sito. Solo una persona può avere un account.
- **Aziende** — le ditte a cui vendi o da cui compri, con ragione sociale,
  partita IVA, SDI e PEC. Un'azienda esiste anche senza nessuna persona.

Persone e aziende si collegano fra loro (vedi *Referenti e aziende* sotto):
la stessa persona può lavorare per più aziende e un'azienda può avere più
referenti. Non c'è più un campo *Privato o azienda*: chi è un'azienda ha la sua
scheda fra le **Aziende**.

I **fornitori** sono sempre aziende. La stessa azienda a cui vendi e da cui
compri è **una scheda sola**: la apri e scegli **Ruolo → Cliente e fornitore**.
Da lì compare in tutti e due gli elenchi e si modifica in un posto solo.

Il campo **Ruolo** ha tre risposte — *Cliente*, *Fornitore*, *Cliente e
fornitore* — e nasce già sul ruolo dell'elenco da cui hai premuto *Aggiungi*.
Se lo cambi, la scheda **si sposta**: un cliente che diventa solo fornitore
esce dall'elenco dei clienti e lo trovi fra i fornitori. Senza la funzionalità
*Acquisti* il campo non c'è: i fornitori non esistono e ogni scheda è di un
cliente.

Il form di una persona, di un'azienda e di un fornitore ha le stesse sezioni
nello stesso ordine, in creazione e in modifica:

1. **Azienda** (o **Persona**): ragione sociale o nome e cognome, ruolo, stato;
2. **Brand**, per le aziende, con la funzionalità *Brand*;
3. **Recapiti dell'azienda** (per una persona **Email e telefoni**): le email
   e i telefoni generici, come info@, amministrazione o centralino. Con la
   funzionalità *CRM* una persona ha qui sotto anche i **Social**;
4. **Referenti** (per una persona **Aziende**): le persone con cui parli, con
   i loro contatti aziendali. L'email di una persona precisa va qui, non nei
   recapiti dell'azienda;
5. **Dati di fatturazione**;
6. **Indirizzi di consegna/ritiro**: dove consegni al cliente o ritiri dal
   fornitore. Sul fornitore si chiama **Indirizzi di ritiro**, su una persona
   **Indirizzi di consegna**;
7. **Altri dati**: pagamento e note. In creazione è chiuso; in modifica si apre
   da solo se c'è qualcosa di compilato.

Accanto al titolo di ogni sezione c'è una **i**: passaci sopra con il mouse per
leggere che cosa va scritto lì. Brand, referenti e indirizzi partono senza
righe: premi *Aggiungi* solo se servono.

Per questo partita IVA, codice fiscale ed email sono **unici**: se li riscrivi
su una scheda nuova, il gestionale ti dice di chi sono già e ti lascia aprire
quella invece di crearne una seconda. Partita IVA e codice fiscale li controlla
il gestionale: se sono sbagliati, il salvataggio si ferma e te lo dice. Lo
stesso vale per l'email, di cui viene controllato anche il dominio.

Un fornitore scritto fra i **fornitori** di un articolo, o di una sua
opzione, non smette di essere fornitore: da *Fornitore* o *Cliente e
fornitore* non passa a *Cliente*. Se ci provi il salvataggio si ferma e ti
dice su quanti articoli o opzioni in vendita compare. Prima toglilo dai loro
fornitori (vedi [Da chi lo compri e a
quanto](catalogo-prodotti.md#da-chi-lo-compri-e-a-quanto)), oppure lascialo
fornitore e metti la scheda su **Non attiva** (vedi sotto).

## Chi è il cliente di un documento

Su preventivi, ordini, DDT, abbonamenti e resi il cliente è **una persona** o
**un'azienda**:

- con una **persona** valgono il suo listino e i suoi pagamenti, e la fattura
  è da privato (o da azienda se la persona ha una partita IVA sua);
- con un'**azienda** valgono il **listino e le condizioni di pagamento
  dell'azienda**, la fattura è sempre all'azienda e la persona scelta è il suo
  **referente** (*Alla c.a.* sul PDF, in copia sulle email).

Nei documenti fatti prima di questa versione, i clienti che erano aziende sono
stati spostati sulle schede delle **Aziende**: il loro referente, se c'era,
resta la persona del documento.

## Pagamento

Nel form di un cliente (persona o azienda) il riquadro **Pagamento** tiene su
una riga quello che vale per i suoi ordini: **metodo** e **condizione di
pagamento** proposti dall'ordine da ufficio, **esigibilità IVA**, **listino** e
**commerciale**, ognuno se la sua funzionalità è accesa. Sul telefono e sugli
schermi stretti vanno uno sotto l'altro.

Metodo e condizione sono facoltativi: lasciati su *Nessuno* restano vuoti e
l'ordine da ufficio non propone niente. Per toglierne uno già scelto riporta
il campo su *Nessuno* e salva.

## Contatti

Le email e i telefoni di una persona, di un'azienda o di un fornitore stanno
nel riquadro dei contatti della scheda: **Recapiti dell'azienda** per aziende e
fornitori, **Email e telefoni** per le persone. Quanti ne puoi scrivere dipende
dalla funzionalità **CRM** (area *Anagrafiche*, spenta di serie).

**Senza CRM** il riquadro ha un'email e un telefono soli, su una riga: email,
prefisso e numero. Il prefisso parte da quello del paese del sito.

**Con il CRM** il riquadro è un elenco: una riga per recapito, con *Aggiungi
un contatto* in fondo. Ogni riga ha il **Tipo** (*Email*, *Telefono fisso*,
*Cellulare*, *WhatsApp*) e, secondo il tipo, l'email oppure prefisso e numero.
Per aziende e fornitori la riga ha anche l'**Ufficio**: uno solo fra
*Generale*, *Amministrazione*, *Acquisti*, *Commerciale*, *Tecnico*,
*Direzione*, *Altro* e quelli che hai creato tu. Una riga nuova parte da
*Generale*, la prima voce: è quella giusta per info@ o per il numero unico
dell'azienda, e vuol dire che il recapito vale per tutto. Così
*amministrazione@*, con l'ufficio *Amministrazione*, riceve proforma e
solleciti anche senza creare un referente (vedi sotto).

Spegnendo il CRM non si perde niente: il form mostra solo la prima email e il
primo telefono, e i recapiti in più già salvati restano dove sono. Tornano
quando lo riaccendi.

Per **creare un ufficio** nuovo (per esempio *Marketing*) premi il **+**
accanto al campo, scrivi il nome e conferma: l'ufficio si seleziona nella riga
e da quel momento lo trovi in tutte le schede, sui recapiti e sui referenti. Un
nome che esiste già riprende quell'ufficio, senza doppioni. Gli uffici creati
si rinominano ma non si eliminano.

Il recapito che il gestionale usa (ordini, documenti, ricerche, export) è uno
per l'email e uno per il telefono: la prima riga di ognuno. Non c'è da
sceglierlo. Le righe si tolgono con il cestino. Salvare una scheda senza
toccarla la lascia com'è (il numero di telefono viene solo riscritto in cifre,
senza spazi). Un'email scritta male o ripetuta ferma il salvataggio, e una
email già di un'altra scheda pure (se la scheda non è fra le tue, senza dire
di chi è).

- Una persona ha l'**Email di accesso** in un campo a parte, sopra gli altri
  contatti: è quella con cui entra nell'area clienti e non compare fra le
  righe dell'elenco. Se la persona ha già un **account sul sito** il campo è
  bloccato: si cambia dal suo account, non dal gestionale (nemmeno dalle
  finestre dell'ordine, che lo dicono). Le altre email non servono mai per
  accedere.
- Con il CRM una persona ha anche il riquadro **Social**: una riga per rete,
  con il suo indirizzo. Senza CRM non si vede e i social già salvati restano.
- Una scheda che ha già un contatto non resta **senza**: un riquadro svuotato
  non si salva. Se
  l'ultimo contatto è sbagliato, cambialo. Una scheda nuova può nascere senza
  contatti.

La scheda in lettura mostra tutti i contatti, con il principale, l'email di
accesso e gli uffici. Con il **CRM** acceso ogni contatto porta anche il suo
tipo (Email, Telefono fisso, Cellulare, WhatsApp) e la scheda di una persona
elenca i suoi **social**: il nome della rete e l'indirizzo, che si apre in
un'altra scheda del browser. Le finestre *Contatti* dell'ordine da ufficio
cambiano solo email e telefono principali.

## Dati di fatturazione

Sia le persone sia le aziende hanno i **dati di fatturazione**: una persona
può avere il suo codice fiscale e il suo indirizzo, un'azienda ha i suoi.
Online, chi compra sceglie se fatturare a sé o a una delle sue aziende.
Nell'indirizzo si cerca anche il **nome di un'attività** (un negozio, un
ufficio): Google completa l'indirizzo e, per un'azienda con la ragione sociale
vuota, la scrive con il nome dell'attività. Place ID e coordinate si salvano
senza vederli.

## Indirizzi di consegna e di ritiro

L'indirizzo della scheda è quello **di fatturazione**. Dove si consegna si
scrive nel riquadro *Indirizzi di consegna*: uno per il magazzino, uno per
casa, uno per il cantiere. Il **destinatario** è chi il corriere deve cercare,
e spesso non è chi compra. Nella via si cerca anche il **nome di
un'attività**: Google completa l'indirizzo. Il campo **Presso** non c'è più,
né nel form della scheda né nella finestra *Aggiungi indirizzo* della scheda
di una persona: un «presso» già salvato resta sull'indirizzo e non si perde
quando salvi. L'ordine copia dalla sede scelta anche place ID e coordinate.

Gli indirizzi si gestiscono anche dalla **scheda del cliente** (il nome nell'elenco
la apre): il riquadro *Indirizzi di consegna* mostra una card per indirizzo, con
**Modifica**, **Rendi predefinito** e il cestino per eliminarlo, e **Aggiungi
indirizzo** in fondo. Ogni gesto apre una finestra; non serve passare dal form
di modifica. Uno solo è il **predefinito** e il primo che scrivi lo diventa; se
elimini il predefinito, passa al primo rimasto. Un indirizzo eliminato esce dalla
scheda ma non dagli ordini già fatti, che ne conservano una copia.

Anche un **fornitore** ha i suoi indirizzi: sono quelli dove **ritiri la
merce**, quando non coincidono con la sede di fatturazione. Il riquadro è lo
stesso e cambia solo il nome: *Indirizzi di consegna/ritiro* dove scegli se
l'azienda è cliente, fornitore o tutti e due, *Indirizzi di ritiro* nella
scheda di chi è solo fornitore.

## L'elenco delle persone

Cliente è un **ruolo**, non un tipo di scheda: la stessa persona può essere
cliente, referente di un'azienda o tutte e due. Per questo, con persone e
aziende, l'elenco si chiama **Persone** e sopra la tabella c'è il filtro
**Ruolo** a vista: *Tutti*, *Clienti* (chi compra per sé) e *Referenti* (chi
è legato a un'azienda). Il nome della persona apre sempre la sua scheda, che
sia cliente o solo referente. Titoli, *Aggiungi persona*, *Scheda persona* e
il nome del file esportato seguono l'elenco (*Clienti* o *Referenti* negli
altri due modi).

## La scheda del cliente

In alto le **statistiche** e, a destra, i **dati di fatturazione** (codice
fiscale, e per un'azienda partita IVA, SDI e PEC, più l'indirizzo). Sotto, **tutti
i suoi dati** — nome o ragione sociale, ruolo, stato, email, telefono, accesso al
sito (solo per le persone) e note — e gli **indirizzi di consegna**. La scheda di
un'azienda elenca anche i suoi **referenti**, quella di una persona le **aziende**
per cui è referente: il nome apre la loro scheda, sotto ci sono il ruolo e i
contatti che usa per quell'azienda. Chi è **rappresentante legale** ha
l'etichetta accanto al nome. Poi gli ordini, il carrello, gli
**abbonamenti** (con la funzionalità *Abbonamenti*) e, in fondo, i coupon.

La scheda di una persona che è **solo referente** si apre come le altre, ma
mostra solo quello che ha senso per lei: **tutti i suoi dati** (con il ruolo
*Referente*), le **aziende** di cui è referente e gli **indirizzi di
consegna**. Statistiche, ordini, carrello, pagamento, listino, abbonamenti e
coupon sono del cliente e non ci sono. Con il gestionale *solo aziende* ogni
persona ha questa scheda.

## La scheda del fornitore

Dall'elenco dei fornitori il nome apre la sua scheda, come per i clienti:
**tutti i suoi dati**, i **dati di fatturazione**, gli ultimi **carichi**
ricevuti da lui (il numero apre il carico) e gli **articoli forniti**, con il
suo codice e il costo. Per cambiare qualcosa c'è **Modifica** in alto.

## Le anagrafiche da completare

Negli elenchi di aziende, persone e fornitori la prima colonna mostra un
**pallino giallo** quando alla scheda manca qualcosa; passandoci sopra si
legge cosa:

- **aziende**: il brand (solo per i clienti, con la funzionalità *Brand*), un
  recapito (email o telefono), un referente, i dati di fatturazione;
- **persone**: il nome, il cognome o l'email (di accesso o fra i contatti);
- **fornitori**: un recapito o i dati di fatturazione.

I **dati di fatturazione** sono completi con ragione sociale, via, città e
paese; per l'Italia servono anche partita IVA o codice fiscale, e codice SDI o
PEC.

Sopra la tabella il filtro **Da completare** (*Solo da completare*) tiene solo
le righe con il pallino. Aprendo la scheda, lo stesso elenco compare in alto
in un riquadro giallo. Il pallino non entra nel file esportato.

## Brand

> Con la funzionalità **Brand** (area *Anagrafiche*, serve *Aziende*, spenta
> di serie). Si accende da sola all'aggiornamento se nel gestionale c'è già
> almeno un brand e nessuno l'ha mai spenta.

Il **brand** è il nome commerciale con cui un'azienda si presenta: *RClass* può
essere il nome commerciale di due ragioni sociali, e una ragione sociale può
avere più brand (per esempio un ristorante e un albergo). Nel form di
un'**azienda** il brand è la **prima cosa** che si sceglie: cercalo scrivendo
una parte del nome e selezionane uno o più; se non c'è, il **+** lo crea. Un
brand con lo stesso nome di uno che c'è già non si duplica: si seleziona
quello.

Tutti i brand stanno in **Anagrafiche → Brand**: da lì li apri, li modifichi
e, se puoi creare schede, ne aggiungi uno nuovo. Un commerciale vede solo i
brand delle sue aziende e ne crea uno solo dal **+** (vedi
[Commerciali](commerciali.md)).

I preventivi e gli ordini da ufficio di un'azienda hanno il campo **Brand**,
fra i brand di quell'azienda: parte dal **primo** dell'elenco. Nel form
dell'azienda il *Predefinito* non si sceglie più: se un'azienda ne aveva già
uno segnato, vale ancora quello finché resta fra i suoi brand. Servirà
per legare al brand i progetti nati dal preventivo. Il documento tiene il nome
del brand com'era: se poi il brand cambia nome, si stacca dall'azienda o si
archivia, il preventivo non cambia. Unendo due brand doppioni, i documenti
passano al brand che resta. Sui **PDF** (preventivo, conferma d'ordine,
proforma e DDT, che lo prende dal suo ordine) il brand si stampa nel riquadro
del cliente, su una riga sua fra *Alla c.a.* e la ragione sociale.

La scheda del brand ha **logo**, **sito**, **domini email** (uno o più, per
riconoscere le email del brand; vuoto, si ricava dal sito) e **descrizione**.
Con la funzionalità **CRM** ci sono anche i **recapiti** del brand (una riga
per recapito, con il **Tipo**, l'email oppure prefisso e numero, e
l'**Ufficio**, di serie *Generale*) e i **social**, nel gruppo *Recapiti e
social*: valgono anche con *Brand kit* spenta.
Con la funzionalità **Brand kit** (area *Anagrafiche*, serve *Brand*) ci sono
anche **colori** (scelti col selettore o scritti in HEX, per
esempio `#1a2b3c`), tono, **settore** (da una tendina con le sezioni ATECO),
**lingua** (da una tendina, di serie *Italiano*) e il link ai materiali; un
settore scritto a mano prima di questa versione resta salvato ma la tendina lo
mostra vuoto finché non ne scegli uno; spenta, quei campi non si vedono e
restano salvati. Con *Brand kit* accesa e il CRM spento il brand ha
un'email e un telefono soli e nessun social: i recapiti in più e i social già
salvati restano. Il logo compare accanto al brand nell'ordine da ufficio e
nella scheda dell'ordine.

In **modifica** la scheda del brand ha anche il riquadro **Indirizzi**: gli
indirizzi di consegna e di ritiro che le sue aziende hanno legato a quel brand
(o a tutti i loro brand). Da qui li correggi: etichetta, indirizzo, civico,
paese, provincia, città e CAP. *Azienda* e *Vale per* si leggono soltanto e le
righe non hanno il cestino. Per
aggiungerne, toglierne o cambiare i brand per cui valgono apri la scheda
dell'azienda.

Con **Brand** spenta i brand **non si vedono e non si cancellano**: spariscono
la voce di menu, il riquadro nella scheda dell'azienda, il campo nei
preventivi e negli ordini, i brand seguiti dei referenti e quelli degli
indirizzi, la riga sui PDF. Un documento salvato con un brand lo tiene anche se
lo risalvi, e lo mostra di nuovo quando riaccendi la funzionalità; un documento
nuovo nasce senza brand. Senza brand le sedi proposte e i referenti in copia
non guardano il brand.

Con la funzionalità *Brand* ogni **indirizzo di consegna** di un'azienda ha il campo **Brand**: se scegli
uno o più brand l'indirizzo vale solo per loro, vuoto vale per tutti. Nei
preventivi e negli ordini da ufficio si propongono prima le sedi del brand del
documento, poi quelle per tutti; le sedi degli altri brand non compaiono.

## Gruppi di aziende

> Con la funzionalità **Gruppi di aziende** (area *Anagrafiche*, serve
> *Aziende*, spenta di serie).

Nel form di un'azienda il campo **Capogruppo** dice a capo di quale gruppo sta:
l'azienda madre. Il gruppo ha un livello solo: una capogruppo non ha a sua
volta una capogruppo. Spenta la funzionalità, il campo non si vede e la
capogruppo già scelta resta salvata.

## Referenti e aziende

Un'azienda ha spesso più persone con cui parli: chi ordina, chi paga, chi
riceve la merce. Nel form di un'**azienda** il riquadro **Referenti** elenca queste persone;
nel form di una **persona** il riquadro **Aziende** elenca le aziende per cui
lavora. La stessa persona può essere referente di più aziende ed è sempre
**una scheda sola**, con un solo accesso al sito.

Ogni referente è una **scheda richiudibile**: chiusa mostra il nome, il ruolo
e l'ufficio; un clic sul titolo la apre. Aperta ha su una riga la **Persona**
(nel form di una persona, l'**Azienda**), il **Ruolo**, l'**Ufficio** e la
spunta **Rappresentante legale**; sotto, i **Contatti aziendali**. Non ci sono
altre opzioni da aprire. Le schede si riordinano trascinandole dai **puntini**
a sinistra del titolo e si tolgono con il cestino.

Finché non c'è nessun referente il riquadro mostra un'area tratteggiata con al
centro il bottone **Aggiungi**: un clic apre la prima riga e il bottone passa
sotto le righe, a destra. Togliendo l'ultima riga torna l'area tratteggiata.
Funziona così anche per i **Contatti aziendali** dentro la riga di un
referente, per i **Brand** dell'azienda, per i **Social** e per gli *Altri
contatti*. Nel riquadro **Contatti** di una scheda la prima riga resta sempre
aperta.

Per ogni riga cerca la scheda nella tendina scrivendo una parte del nome. Nel
form di una persona la tendina delle aziende mostra solo la **ragione
sociale**. Se la persona non c'è ancora, il **+** accanto alla tendina apre
una finestra: scrivi nome, cognome ed email (o ragione sociale e partita IVA),
e la scheda nasce e si seleziona nella riga. Prima di crearla si controlla che
non esista già: con la stessa **email** o la stessa **partita IVA** si
seleziona quella che c'è, senza doppioni. Il referente nuovo non è né cliente
né fornitore; l'azienda nuova è cliente. Funziona anche quando crei un cliente
o un'azienda nuovi. Una riga compilata ma senza persona ferma il salvataggio e
ti chiede di sceglierla o di svuotarla; una riga del tutto vuota si scarta.
Poi:

- **Ruolo**: scritto a mano, per esempio *Responsabile acquisti*;
  se manca, sui documenti e nelle tendine compare il suo ufficio;
- **Ufficio**: lo stesso campo dei recapiti, uno solo: *Generale*,
  *Amministrazione*, *Acquisti*, *Commerciale*, *Tecnico*, *Direzione*,
  *Altro* e quelli creati da te con il **+**. *Generale*, la prima voce, vuol
  dire che si occupa di tutto;
- **Rappresentante legale**: spuntala per chi firma per l'azienda. Vale per
  quell'azienda sola: la stessa persona può esserlo per un'azienda e non per
  un'altra, e un'azienda può averne più d'uno. Non dà accesso al sito e non
  cambia l'ufficio né i brand seguiti. Accanto al nome, nella scheda
  dell'azienda e in quella della persona, compare l'etichetta *Rappresentante
  legale*;
- **Contatti aziendali**: i contatti del referente per quell'azienda
  (facoltativi). Con il CRM è un elenco: *Aggiungi un contatto* e, per ogni
  riga, il **Tipo** (*Email*, *Telefono fisso*, *Cellulare*, *WhatsApp*) con
  l'email oppure prefisso e numero. Senza CRM sono tre campi: **Email
  aziendale**, **Prefisso** e **Telefono aziendale**. Se c'è un'email
  aziendale i documenti di quell'azienda vanno alla prima e non alla sua
  email personale; la scheda dell'azienda e l'ordine la mostrano. Non serve
  mai per accedere al sito;
- **Brand seguiti**: con la funzionalità *Brand*, i brand dell'azienda di cui
  si occupa; vuoto = tutti. Il campo c'è solo se l'azienda ha **più di un
  brand**;
- quando fai un preventivo, un ordine da ufficio o un DDT per quell'azienda è
  proposto il **primo referente** della scheda (con un brand, il primo fra chi
  lo segue): trascina le righe per cambiare l'ordine. Sui documenti si può
  scegliere qualsiasi referente.

*Referente principale*, *Riceve i documenti* e *Accesso all'area clienti* non
sono più nel form: il referente proposto è il primo, e in copia va quello
scelto sul documento (vedi sotto).

### A chi vanno in copia i documenti

Ogni documento ha la sua area: **preventivo, conferma dell'ordine e DDT** vanno
a chi si occupa degli **Acquisti**; **proforma, solleciti e pagamenti non
riusciti degli abbonamenti** a chi si occupa dell'**Amministrazione**. Dei
referenti riceve la copia solo quello **scelto sul documento**: nessun altro
la riceve da solo.

Dell'azienda ricevono le email del suo riquadro *Contatti* che si occupano di
quell'area (o di tutto); se nessuna corrisponde, la sua email principale, come
prima. Nessuno riceve lo stesso documento due volte.

- **Preventivo, conferma, DDT**: destinatario l'email del documento (di solito
  quella dell'azienda); in copia le email dell'azienda per gli *Acquisti* (o la
  principale) e il referente del documento, all'email di lavoro se ce l'ha.
- **Proforma, solleciti e pagamenti non riusciti degli abbonamenti**:
  destinatario la prima email dell'azienda per l'*Amministrazione* (o la
  principale; senza, il referente); in copia le altre email per
  l'*Amministrazione* e il referente dell'abbonamento.

Le copie vanno all'email del referente **per quell'azienda** (di lavoro, se
c'è, altrimenti la sua principale): gli altri contatti della persona non
ricevono mai documenti e non servono per accedere al sito.

Nell'elenco delle persone il filtro **Ruolo → Referenti** mostra solo queste
persone (*Clienti* solo chi compra per sé), e la colonna **Referenti /
aziende** dice chi è legato a chi; lo dice anche l'export. Togliere un referente dal riquadro non elimina la sua scheda e
non cambia i documenti già fatti, che ne conservano il nome.

## Comprare sul sito per un'azienda

Con la funzionalità **Account aziendali** (servono *Vendita online* e
*Aziende*) una persona
con l'account sul sito può ordinare anche per le aziende a cui è collegata.
Lo decide l'**accesso** del referente a quell'azienda, che non si sceglie più
nel riquadro Referenti: un referente aggiunto ora nasce con *Ordina* (se lo
aggiunge un commerciale che vede solo le sue aziende, con *Nessuno*); un
referente già salvato tiene l'accesso che aveva.

- **Nessuno**: la persona compra solo per sé;
- **Ordina**: nel checkout sceglie in **Acquista come** se comprare per sé o
  per l'azienda;
- **Gestisce l'azienda**: come *Ordina*, e nell'area clienti vede anche gli
  ordini e gli abbonamenti fatti dagli altri per l'azienda e ne cambia i dati
  di fatturazione nella scheda **La mia azienda**.

Quando la persona compra per un'azienda, l'ordine è intestato all'azienda con
i suoi dati di fatturazione (sempre fattura, da società), il suo listino e il
suo metodo di pagamento proposto; la persona è il referente dell'ordine. Se
all'azienda mancano dati di fatturazione, il sito non fa ordinare e chiede di
completarli. Gli abbonamenti si comprano sempre per sé.

Le persone dell'azienda si vedono nella scheda **La mia azienda** ma si
cambiano solo qui, dal gestionale.

## Eliminare o disattivare

Una scheda con un **account sul sito** non si elimina: chi ha le credenziali
resterebbe senza scheda. E appena ci saranno ordini, varrà lo stesso per chi ha
comprato. La strada giusta è metterla su **Non attiva**: sparisce dal lavoro di
ogni giorno e la storia resta.

Lo stesso per un **fornitore scritto su articoli ancora in catalogo**, o su
una loro opzione: eliminandolo, perderebbero da chi li compri e a quanto. Il
gestionale lo rifiuta e ti dice su quanti articoli o opzioni compare, anche
quando la funzionalità *Acquisti* è spenta: codici e costi restano salvati e
li ritrovi quando la riaccendi. Gli articoli che hai già eliminato non contano.

Un fornitore **Non attivo** non si propone più a chi non lo usa, ma dove è
già scelto resta al suo posto, con scritto *(non attivo)* accanto al nome: il
suo codice e il suo costo non si perdono. Se invece vuoi proprio eliminarlo,
toglilo prima dai fornitori di quegli articoli e di quelle opzioni.

## Nella home

Il riquadro **Anagrafiche** dice quanti clienti e quante aziende ci sono e
porta agli elenchi; con un modo solo mostra solo quello con cui lavori.
# Storage condiviso

Le schede contatto e gli indirizzi di consegna (`contacts`, `contact_addresses`)
sono di `wonder-image/customer-account`; il riferimento esterno
(`external_references`) è di `wonder-image/app`. I namespace del gestionale
restano compatibili e aggiungono le opzioni commerciali del contatto. Forge
update rinomina i precedenti nomi `gst_*` prima di allineare gli schemi,
conservando ID, dati e collegamenti. Aggiornare app, customer-account e
gestionale insieme. Se entrambi i nomi sono già presenti la migrazione si
ferma, senza cancellazioni automatiche.
