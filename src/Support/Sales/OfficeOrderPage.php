<?php

namespace Wonder\Plugin\Gestionale\Support\Sales;

use Throwable;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\Input;
use Wonder\Elements\Components\Button;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\RichText;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Form;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Resources\Contacts\CustomerResource;
use Wonder\Plugin\Gestionale\Resources\Sales\QuoteResource;
use Wonder\Plugin\Gestionale\Support\Contacts\Contacts;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;

/**
 * La pagina della bozza d'ordine da ufficio e del preventivo (G9 §2.2, §3)
 * con i componenti del core: cliente, fatturazione, consegna, righe,
 * pagamento e sconto, note, totali, revisioni e pulsanti, in `Card` con
 * `FormField`.
 *
 * Le righe restano dell'editor di `OfficeOrderEditor` (ricerca degli
 * articoli, prezzi e disponibilità dal vivo): viaggiano nel solo campo
 * `lines`, scritto dallo script della pagina all'invio. I pulsanti postano
 * `intent`; quelli che non tornano indietro chiedono conferma con la lib.
 */
final class OfficeOrderPage
{
    /** Le azioni che non salvano le righe: lo script manda `lines` vuoto. */
    public const WITHOUT_LINES = ['reject', 'delete'];

    /**
     * @param class-string<\Wonder\Plugin\Gestionale\Resources\Sales\OfficeOrderResource> $resource
     * @param array<string, mixed> $values i valori di `mutateFormValues()`
     * @param string|null $editor l'editor delle righe già disegnato; null lo disegna `OfficeOrderEditor`
     */
    public static function layout(string $resource, array $values, ?string $editor = null): Form
    {
        $quote = $resource::stage() === 'quote';
        // I testi salvati arrivano già passati da htmlspecialchars: si riportano come li ha scritti l'operatore.
        $v = static fn (string $key): string => html_entity_decode((string) ($values[$key] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $campo = static fn (string $key, string $label, int $span = 3): Input => self::field(FormField::key($key)->text(), $key, $label, $span, $v($key));
        $riquadro = static fn (string $titolo, string $aiuto, array $componenti): Card => (new Card)->components([
            $aiuto === '' ? SectionTitle::make($titolo)->columnSpan(12) : SectionTitle::make($titolo)->tooltip($aiuto)->columnSpan(12),
            ...$componenti,
        ])->columns(12)->columnSpan(12);
        $locations = $resource::locations();
        $shippingMethods = $resource::shippingMethods();
        $terms = $resource::paymentTerms();
        $discountValue = (float) ($values['manual_discount_value'] ?? 0);
        $paese = static fn (string $key): string => $v($key) !== '' ? $v($key) : 'IT';

        $customerId = (int) ($values['customer_id'] ?? 0);
        // Testata dell'ordine nascosta: la riempiono la scheda del cliente e
        // l'indirizzo spuntato, e la posta il form come prima.
        $nascosti = [
            self::hidden('email', $v('email')),
            self::hidden('phone', $v('phone')),
            ...array_map(static fn (string $k): Input => self::hidden('billing_'.$k, $v('billing_'.$k)), OfficeOrders::billingKeys()),
            ...array_map(static fn (string $k): Input => self::hidden('shipping_'.$k, $v('shipping_'.$k)), OfficeOrders::shippingKeys()),
        ];

        $sinistra = [
            $riquadro('Cliente', 'Scegliendo il cliente si riempiono fatturazione, consegna e pagamento dalla sua scheda. Il cliente nuovo si crea in un\'altra scheda; poi cercalo qui.', [
                FormField::key('customer_id')->searchText(OfficeOrderEditor::customersUrl())
                    ->valueLabel(self::customerLabel($customerId))
                    ->emptyText('Nessun cliente trovato: crealo con «Nuovo cliente».')
                    ->label('Cliente')
                    ->value($customerId > 0 ? (string) $customerId : '')
                    ->columnSpan(12),
                RichText::make(Button::to(self::newCustomerUrl(), 'Nuovo cliente')->icon('bi bi-person-plus')->variant('secondary')->outline()->size('sm')->blank()->schema('inline', true)->render('bootstrap'))
                    ->tag('div')->columnSpan(12),
                ...$nascosti,
            ]),
            self::panel('Contatti', 'contacts', OfficeCustomerPanel::CONTACTS_MODAL),
            self::panel('Fatturazione', 'billing', OfficeCustomerPanel::BILLING_MODAL),
            self::panel('Indirizzi di consegna', 'addresses', ''),
        ];

        $componenti = [
            $riquadro('Righe', 'Prezzo vuoto = quello del listino o della campagna in corso. Lo sconto vale sulla riga. «Disponibili» è la merce della sede: se non basta la conferma si ferma, salvo gli articoli che si vendono senza giacenza.', [
                FormField::key('lines')->hidden()->value('')->attribute('data-wi-oo-lines'),
                RichText::make($editor ?? OfficeOrderEditor::render(
                    (array) (((array) ($values['editor'] ?? []))['lines'] ?? []),
                    OfficeOrderEditor::searchUrl(),
                    OfficeOrderForm::taxCategories(),
                    Gestionale::feature('batch_tracking'),
                    Gestionale::feature('purchasing')
                ))->tag('div')->columnSpan(12),
            ]),
            $riquadro('Consegna', 'Con la spedizione la merce va all\'indirizzo spuntato a sinistra (senza spunta, a quello di fatturazione); con il ritiro esce dalla sede scelta. Il costo di spedizione vuoto è quello del listino del metodo: scrivilo per fissarlo a mano, anche zero.', array_values(array_filter([
                self::field(FormField::key('fulfillment_type')->select(['shipping' => 'Spedizione', 'pickup' => 'Ritiro in sede', 'none' => 'Nessuna consegna']), 'fulfillment_type', 'Come arriva la merce', 3, (string) ($values['fulfillment_type'] ?? 'shipping')),
                $locations !== []
                    ? self::field(FormField::key('location_id')->select($locations), 'location_id', 'Sede che evade', 3, (string) ($values['location_id'] ?? ''))->attribute('data-wi-office-location')
                    : null,
                $shippingMethods !== []
                    ? self::field(FormField::key('shipping_method_id')->select(['0' => 'Nessuno'] + $shippingMethods), 'shipping_method_id', 'Metodo di spedizione', 3, (string) ($values['shipping_method_id'] ?? '0'))->visibleWhen('fulfillment_type', 'shipping')
                    : null,
                self::field(FormField::key('shipping_amount')->text(), 'shipping_amount', 'Costo di spedizione (€)', 3, $v('shipping_amount'))->attribute('inputmode="decimal"')->visibleWhen('fulfillment_type', 'shipping'),
            ]))),
            $riquadro('Pagamento e sconto', $quote
                ? 'La condizione dice in quante rate si pagherà: le rate nascono quando l\'ordine si conferma.'
                : 'La condizione dice in quante rate e quando si paga: alla conferma nascono le rate nello Scadenzario.', array_values(array_filter([
                self::field(FormField::key('payment_method_id')->select(['0' => 'Nessuno'] + $resource::paymentMethods()), 'payment_method_id', 'Metodo di pagamento', 3, (string) ($values['payment_method_id'] ?? '0')),
                count($terms) > 1
                    ? self::field(FormField::key('payment_term_id')->select(['0' => 'Nessuna (a vista)'] + $terms), 'payment_term_id', 'Condizione di pagamento', 3, (string) ($values['payment_term_id'] ?? '0'))
                    : null,
                self::field(FormField::key('manual_discount_type')->select(['none' => 'Nessuno', 'percent' => 'Percentuale', 'amount' => 'Importo']), 'manual_discount_type', 'Sconto sul totale', 3, (string) ($values['manual_discount_type'] ?? 'none')),
                self::field(FormField::key('manual_discount_value')->text(), 'manual_discount_value', 'Valore dello sconto', 3, $discountValue > 0 ? number_format($discountValue, 2, ',', '') : '')
                    ->attribute('inputmode="decimal"')
                    ->hiddenWhen('manual_discount_type', 'none'),
                $quote
                    ? self::field(FormField::key('quote_expires_at')->dateInput(), 'quote_expires_at', 'Valido fino al', 3, self::expiry($values))
                    : null,
            ]))),
            $riquadro('Note', 'La nota stampata compare sul documento che riceve il cliente; la nota interna la vede solo chi lavora nel gestionale.', [
                self::field(FormField::key('document_note')->textarea(), 'document_note', 'Nota stampata', 4, $v('document_note')),
                self::field(FormField::key('customer_note')->textarea(), 'customer_note', 'Nota del cliente', 4, $v('customer_note')),
                self::field(FormField::key('internal_note')->textarea(), 'internal_note', 'Nota interna', 4, $v('internal_note')),
            ]),
        ];

        if ((int) ($values['id'] ?? 0) > 0) {
            $componenti[] = $riquadro('Totali', 'I totali dell\'ultimo salvataggio, con listino, campagne, sconti e spedizione. Dopo una modifica salva la bozza per vederli aggiornati.', [
                RichText::make(self::totalsHtml($values))->tag('div')->columnSpan(12),
            ]);
        }

        if ($quote && (int) ($values['id'] ?? 0) > 0) {
            $revisioni = self::revisionsHtml((int) $values['id']);

            if ($revisioni !== '') {
                $componenti[] = $riquadro('Revisioni inviate', 'Ogni invio archivia il PDF che il cliente ha ricevuto: non cambia più, anche se poi modifichi il preventivo.', [
                    RichText::make($revisioni)->tag('div')->columnSpan(12),
                ]);
            }
        }

        $pulsanti = (new Card)->components([
            FormField::key('notify')->toggle()
                ->label($quote ? 'Manda l\'email con il PDF' : 'Alla conferma scrivi al cliente')
                ->value($quote ? 'true' : 'false')
                ->columnSpan(['default' => 12, 'md' => 4]),
            RichText::make(self::buttonsHtml($quote, $values))->tag('div')->columnSpan(['default' => 12, 'md' => 8]),
        ])->columns(12)->columnSpan(12);

        return (new Form)->components([
            (new Container)->columnSpan(['default' => 12, 'lg' => 4])->columns(12)->components($sinistra),
            (new Container)->columnSpan(['default' => 12, 'lg' => 8])->columns(12)->components($componenti),
            $pulsanti,
        ])->columns(12);
    }

    /** Un campo della testata: etichetta, valore, larghezza e l'aggancio per lo script (`data-wi-oo-field`). */
    private static function field(Input $field, string $key, string $label, int $span, string $value): Input
    {
        return $field->label($label)
            ->value($value)
            ->attribute('data-wi-oo-field="'.htmlspecialchars($key, ENT_QUOTES, 'UTF-8').'"')
            ->columnSpan(['default' => 12, 'md' => $span]);
    }

    /** Un campo nascosto della testata, con l'aggancio per lo script. */
    private static function hidden(string $key, string $value): Input
    {
        return FormField::key($key)->hidden()->value($value)
            ->attribute('data-wi-oo-field="'.htmlspecialchars($key, ENT_QUOTES, 'UTF-8').'"');
    }

    /**
     * Un riquadro della colonna del cliente: lo riempie lo script dalla
     * scheda (`data-wi-oo-panel`), e «Modifica» apre la sua finestra.
     */
    private static function panel(string $title, string $key, string $modal): Card
    {
        $modifica = $modal === '' ? '' : Button::make('Modifica')
            ->icon('bi bi-pencil')
            ->variant('secondary')
            ->outline()
            ->size('sm')
            ->attr('data-bs-toggle', 'modal')
            ->attr('data-bs-target', '#'.$modal)
            ->attr('data-wi-oo-edit', true)
            ->attr('disabled', true)
            ->render('bootstrap');

        return (new Card)->components([
            RichText::make('<div class="d-flex align-items-center justify-content-between gap-2">'
                .SectionTitle::make($title)->render('bootstrap').$modifica.'</div>')->tag('div')->columnSpan(12),
            RichText::make('<div data-wi-oo-panel="'.$key.'"><p class="text-muted small mb-0">Scegli un cliente.</p></div>')
                ->tag('div')->columnSpan(12),
        ])->columns(12)->columnSpan(12);
    }

    /** Il nome del cliente scelto, nel campo di ricerca. */
    private static function customerLabel(int $customerId): string
    {
        if ($customerId <= 0) {
            return '';
        }

        try {
            $customer = Contact::findById($customerId);
        } catch (Throwable) {
            return '';
        }

        return is_array($customer) && $customer !== [] ? Contacts::displayName($customer) : '';
    }

    /** *Nuovo cliente*: la pagina di creazione, dalla rotta con il nome. */
    private static function newCustomerUrl(): string
    {
        $url = '/backend/'.CustomerResource::path().'/create/';

        if (function_exists('__r')) {
            try {
                $url = (string) __r('backend.resource.'.CustomerResource::slug().'.create') ?: $url;
            } catch (Throwable) {
                // Rotta non registrata: resta il percorso.
            }
        }

        return $url;
    }

    /** La scadenza del preventivo, in `Y-m-d`: quella salvata o quella di partenza. */
    private static function expiry(array $values): string
    {
        $expires = substr((string) ($values['quote_expires_at'] ?? ''), 0, 10);

        return $expires === '' || str_starts_with($expires, '0000') ? substr(OfficeOrders::defaultExpiry(), 0, 10) : $expires;
    }

    private static function totalsHtml(array $values): string
    {
        $riga = static fn (string $etichetta, mixed $valore, bool $forte = false): string => '<dt class="col-6 col-md-3'.($forte ? ' fs-5' : '').'">'.OrderSheet::esc($etichetta).'</dt>'
            .'<dd class="col-6 col-md-3 text-end'.($forte ? ' fs-5 fw-semibold' : '').'">'.OrderSheet::esc(OrderSheet::money($valore ?? 0)).'</dd>';

        return '<dl class="row mb-0">'
            .$riga('Prodotti', $values['products_total'] ?? 0)
            .$riga('Sconto', $values['discount_total'] ?? 0)
            .$riga('Spedizione', $values['shipping_total'] ?? 0)
            .$riga('di cui IVA', $values['tax_total'] ?? 0)
            .$riga('Totale', $values['total'] ?? 0, true)
            .'</dl>';
    }

    /** Le revisioni inviate del preventivo, ognuna con il suo PDF archiviato. */
    private static function revisionsHtml(int $quoteId): string
    {
        $righe = '';

        try {
            $revisioni = Quotes::revisions($quoteId);
        } catch (Throwable) {
            // Senza database (test, comandi) la pagina si disegna lo stesso, senza revisioni.
            return '';
        }

        foreach ($revisioni as $revision) {
            $pdf = Button::to(QuoteResource::revisionPdfUrl($quoteId, (int) $revision['revision']), 'PDF')
                ->icon('bi bi-file-earmark-pdf')->variant('secondary')->outline()->size('sm')->blank()->schema('inline', true);
            $righe .= '<tr>'
                .'<td>'.OrderSheet::esc((string) (int) $revision['revision']).'</td>'
                .'<td>'.OrderSheet::esc(OrderSheet::date((string) ($revision['sent_at'] ?? ''))).'</td>'
                .'<td class="d-none d-md-table-cell">'.OrderSheet::esc(trim((string) ($revision['sent_to'] ?? '')) !== '' ? (string) $revision['sent_to'] : 'Nessuna email').'</td>'
                .'<td class="text-end">'.OrderSheet::esc(OrderSheet::money($revision['total'] ?? 0)).'</td>'
                .'<td class="text-end">'.$pdf->render('bootstrap').'</td>'
                .'</tr>';
        }

        if ($righe === '') {
            return '';
        }

        return '<div class="table-responsive"><table class="table table-sm align-middle mb-0">'
            .'<thead><tr><th>Rev.</th><th>Inviata il</th><th class="d-none d-md-table-cell">A</th><th class="text-end">Totale</th><th></th></tr></thead>'
            .'<tbody>'.$righe.'</tbody></table></div>';
    }

    /**
     * I pulsanti in fondo. *Salva bozza* è il primo, così Invio salva; quelli
     * che cancellano stanno a sinistra e l'isola di salvataggio non li copia.
     */
    private static function buttonsHtml(bool $quote, array $values): string
    {
        $editing = (int) ($values['id'] ?? 0) > 0;
        $status = (string) ($values['status'] ?? 'draft');
        $neverSent = trim((string) ($values['quote_number'] ?? '')) === '';
        $invia = static fn (string $intent, string $label, string $variant, bool $outline, string $icon = ''): Button => ($icon === ''
            ? Button::make($label)
            : Button::make($label)->icon($icon))
            ->type('submit')
            ->attr('name', 'intent')
            ->attr('value', $intent)
            ->variant($variant)
            ->outline($outline)
            ->schema('inline', true);
        $aSinistra = static fn (Button $button): Button => $button->attr('class', 'order-first me-auto')->attr('data-wi-save-bar-ignore', true);
        $pulsanti = [$invia('save', 'Salva bozza', 'dark', true)];

        if ($quote) {
            if ($editing && in_array($status, ['draft', 'sent'], true)) {
                $pulsanti[] = $invia('accept', 'Accettato', 'success', true, 'bi bi-check2-circle')
                    ->confirm('Il preventivo si salva e diventa una bozza d\'ordine con le stesse righe: lì controlli consegna e pagamento e lo confermi.', title: 'Preventivo accettato', ok: 'Accettato', variant: 'success');
            }

            $pulsanti[] = $invia('send', $status === 'sent' ? 'Invia di nuovo' : 'Invia al cliente', 'dark', false, 'bi bi-send')
                ->confirm($neverSent
                    ? 'Il preventivo prende il numero e si archivia il suo PDF; con l\'interruttore acceso il cliente lo riceve per email.'
                    : 'Il preventivo si salva e, se è cambiato, diventa una revisione nuova con il suo PDF; con l\'interruttore acceso il cliente lo riceve per email.', title: 'Invia il preventivo', ok: 'Invia', variant: 'primary');

            if ($editing && $neverSent && $status === 'draft') {
                $pulsanti[] = $aSinistra($invia('delete', 'Elimina', 'danger', true, 'bi bi-trash'))
                    ->confirm('Il preventivo non è mai stato inviato: si elimina con le sue righe.', title: 'Elimina preventivo', ok: 'Elimina');
            } elseif ($editing && $status === 'sent') {
                $pulsanti[] = $aSinistra($invia('reject', 'Rifiutato', 'danger', true, 'bi bi-x-circle'))
                    ->confirm('Il preventivo si segna come rifiutato. Le modifiche non salvate di questa pagina si perdono; modificandolo dopo lo riapri.', title: 'Preventivo rifiutato', ok: 'Rifiutato');
            }
        } else {
            $pulsanti[] = $invia('confirm', 'Conferma ordine', 'dark', false, 'bi bi-check2-circle')
                ->confirm('L\'ordine prende il numero, la merce esce dal magazzino e nascono le rate. Dopo le righe non si modificano più: si possono solo annullare.', title: 'Conferma ordine', ok: 'Conferma ordine', variant: 'primary');

            if ($editing) {
                $pulsanti[] = $aSinistra($invia('delete', 'Elimina bozza', 'danger', true, 'bi bi-trash'))
                    ->confirm('La bozza si elimina con le sue righe. Non ha numero né movimenti di magazzino: non resta niente.', title: 'Elimina bozza', ok: 'Elimina');
            }
        }

        return '<div class="d-flex flex-wrap align-items-center justify-content-end gap-2">'
            .implode('', array_map(static fn (Button $button): string => $button->render('bootstrap'), $pulsanti))
            .'</div>';
    }
}
