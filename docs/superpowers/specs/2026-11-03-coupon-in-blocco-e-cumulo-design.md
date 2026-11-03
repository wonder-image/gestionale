# Coupon in blocco e cumulo (gestionale#113)

Data: 2026-11-03. Decisioni dell'utente (le quattro scelte consigliate):

1. **Tra coupon**: flag per coupon «Cumulabile con altri coupon» (`stackable`,
   predefinito no: resta il comportamento di oggi). Un coupon non cumulabile
   sta da solo; i cumulabili si sommano.
2. **Con le promozioni**: resta il flag per coupon già esistente
   `exclude_discounted_products` («Non sui prodotti già scontati»). Nessun
   flag nuovo.
3. **Ordine di applicazione**: prima le percentuali, ognuna sul residuo;
   poi gli importi fissi sul residuo. Lo sconto totale non supera la merce.
4. **Codici in blocco**: un coupon modello tiene le regole, una tabella di N
   codici monouso (prefisso + parte casuale) sta sotto di lui.

## Contratto

### Dati

- `gst_coupons.stackable` (true/false, no) e `gst_coupons.batch`
  (true/false, no). Con `batch` il codice del modello non si accetta: valgono
  solo i codici generati. Generare codici accende `batch`.
- `gst_coupon_codes` (`CouponCode`): `coupon_id`, `code` (unico). Ogni codice
  vale **una volta**: è usato se ha un utilizzo non rilasciato. I limiti del
  coupon (`usage_limit`, `usage_limit_per_customer`) contano su tutti i suoi
  codici insieme.
- Un codice è unico fra `gst_coupons.code` e `gst_coupon_codes.code`,
  eliminati compresi, senza badare alle maiuscole.
- `gst_coupon_redemptions` prende `coupon_code_id` e `code` (il codice
  scritto dal cliente).
- `gst_order_coupons` (`OrderCoupon`): `order_id`, `coupon_id`,
  `coupon_code_id`, `code`, `position`, `discount_amount`. È la verità per
  i coupon dell'ordine. Sull'ordine restano, come specchio, `coupon_id` (il
  primo) e `coupon_code` (i codici separati da «, », colonna a 255).
  Un carrello di prima, con `coupon_id` e senza righe, vale come un solo
  coupon.

### Servizio `Coupons`

- `find($code)`: cerca prima fra i coupon (un modello `batch` non vale), poi
  fra i codici generati; ridà la riga del coupon con `code` = il codice
  scritto e `coupon_code_id`.
- `apply($cartId, $code)`: se il nuovo e quelli già applicati sono tutti
  cumulabili **aggiunge**, altrimenti **sostituisce** (come prima: il campo
  unico del negozio continua a funzionare). Errore nuovo:
  `coupon.already_applied` (stesso coupon già sul carrello, anche con un
  altro codice dello stesso blocco).
- `remove($cartId, ?string $code = null)`: `null` li toglie tutti.
- `applied($orderId)`: le righe di `gst_order_coupons` in ordine di
  applicazione (`coupon_id`, `coupon_code_id`, `code`, `discount_amount`).
- `context($cartId, $lines, $now)` (da `Cart::recalculate`) rivaluta i
  coupon e prepara `discounts` per `OrderTotals`; `record($context,
  $totals)` scrive su ogni riga l'importo che ha tolto.
- `Cart::restore` («Modifica l'ordine») riapplica i coupon dell'ordine uno
  per uno con `apply`; quelli che non reggono più restano fuori.
- Il ricalcolo rivaluta ogni coupon; quelli che non reggono escono
  (`coupon_dropped` = il motivo del primo che esce). Se dopo una modifica nel
  backend due coupon non sono più cumulabili, resta il primo e i successivi
  escono con `not_stackable`.
- Motivo nuovo delle regole: `code_used` (codice monouso già usato).
- La spesa minima di ogni coupon si misura sulle righe **prima** dei coupon.
- `redeem` crea un utilizzo per coupon, con l'importo della sua riga di
  `gst_order_coupons` (per la spedizione gratuita: la spedizione risparmiata,
  al primo coupon di spedizione).
- Un coupon `batch` non vale per gli abbonamenti (`validate` rifiuta
  `batch` con `applies_subscriptions`).

### Totali

`OrderTotals::of` accetta `discounts` nel contesto: lista di
`{type: percent|amount, value, lines?: [line id]}` (senza `lines`: le righe
di merce). Le
percentuali prima (nell'ordine dato), ognuna sul residuo delle sue righe,
poi gli importi sul residuo. Ogni passo ripartisce in proporzione sul
residuo, col centesimo alla riga più alta. Ridà `discounts` con l'importo di
ognuno, nell'ordine dato. Senza `discounts` vale il percorso di prima
(`discount_type`/`discount_value`, sconto a mano compreso).

### Generazione

`CouponCodes::generate($couponId, $quantity, $prefix = '', $length = 8)`:
alfabeto senza caratteri ambigui `ABCDEFGHJKLMNPQRSTUVWXYZ23456789`,
`random_int`; codice `PREFISSO-XXXXXXXX` (prefisso in maiuscolo, solo
lettere, cifre, `-` e `_`, al più 20). Quantità 1..10000. Unicità
controllata su tutte e due le tabelle prima di scrivere; l'indice unico è
l'ultima difesa. ecommerce#51 chiama `generate($couponId, 1, 'CARRELLO')`.
`CouponCodes::of($couponId)` ridà i codici con `used`, `order_number`,
`used_at`; `CouponCodes::make()` è la parte pura (caso e controllo esterni).

### Backend

Sulla scheda del coupon: «Genera codici» (finestra con prefisso e quantità)
e «Scarica i codici» (CSV con `;`, BOM e celle di testo: codice, usato,
ordine, data). Rotte `GET <coupon>/{id}/codici/` (`codes`, permesso `view`)
e `POST <coupon>/{id}/codici/genera/` (`generate`, permesso `edit`, CSRF).
Coupon degli abbonamenti: niente pulsanti.

### Chi legge `coupon_code`

Lo specchio sull'ordine continua a servire chi mostra il codice, tutti
nell'ecommerce: `CheckoutSummary`, `CheckoutSnapshot`, `AccountOrder`,
`AccountCoupons`. Con più coupon vi arriva «A, B».

## Fuori da qui

- Interfaccia del negozio per più coupon (ecommerce): il campo unico di oggi
  continua a funzionare con `apply`/`remove`.
