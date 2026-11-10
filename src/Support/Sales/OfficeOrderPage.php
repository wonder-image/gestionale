<?php

namespace Wonder\Plugin\Gestionale\Support\Sales;

use Closure;
use Throwable;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\Input;
use Wonder\Elements\Components\Button;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\QuickCreateButton;
use Wonder\Elements\Components\RichText;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Form;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Contacts\Company;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Resources\Contacts\CompanyBrandResource;
use Wonder\Plugin\Gestionale\Resources\Contacts\CompanyResource;
use Wonder\Plugin\Gestionale\Resources\Contacts\CustomerResource;
use Wonder\Plugin\Gestionale\Resources\Pricing\PriceListResource;
use Wonder\Plugin\Gestionale\Resources\Sales\QuoteResource;
use Wonder\Plugin\CustomerAccount\Support\CompanyBrands;
use Wonder\Plugin\Gestionale\Support\Contacts\Contacts;
use Wonder\Plugin\Gestionale\Support\Contacts\CustomerMode;
use Wonder\Plugin\Gestionale\Support\Contacts\Brands;
use Wonder\Plugin\Gestionale\Support\Contacts\DocumentBrands;
use Wonder\Plugin\Gestionale\Support\Contacts\DocumentReferents;
use Wonder\Plugin\Gestionale\Support\Contacts\QuickContacts;
use Wonder\Plugin\Gestionale\Support\Locations\PickupPoints;
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
 *
 * Cliente, referente e brand nuovi si creano in una finestra della pagina
 * (creazione rapida del core): niente uscita, le righe restano. Cosa si
 * sceglie e cosa si crea segue il modo del gestionale (`CustomerMode`).
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
        $pickupPoints = [];

        try {
            foreach (PickupPoints::all() as $point) {
                $pickupPoints[(string) $point['id']] = trim($point['name'].($point['address'] !== '' ? ' ('.$point['address'].')' : ''));
            }
        } catch (Throwable) {
            $pickupPoints = [];
        }

        $pickup = (string) ($values['fulfillment_type'] ?? '') === 'pickup' ? (string) ($values['location_id'] ?? '0') : '0';
        $terms = $resource::paymentTerms();
        $discountValue = (float) ($values['manual_discount_value'] ?? 0);
        $paese = static fn (string $key): string => $v($key) !== '' ? $v($key) : 'IT';

        $customerId = (int) ($values['customer_id'] ?? 0);
        $companyId = (int) ($values['company_id'] ?? 0);
        // Testata dell'ordine nascosta: la riempiono la scheda del cliente e
        // l'indirizzo spuntato, e la posta il form come prima. `customer_id`
        // è la persona (con un'azienda, il referente), `company_id` l'azienda.
        $nascosti = [
            self::hidden('customer_id', $customerId > 0 ? (string) $customerId : ''),
            self::hidden('company_id', (string) $companyId),
            self::hidden('email', $v('email')),
            self::hidden('phone', $v('phone')),
            ...array_map(static fn (string $k): Input => self::hidden('billing_'.$k, $v('billing_'.$k)), OfficeOrders::billingKeys()),
            ...array_map(static fn (string $k): Input => self::hidden('shipping_'.$k, $v('shipping_'.$k)), OfficeOrders::shippingKeys()),
        ];

        $sinistra = [
            (new Card)->components(array_values(array_filter([
                SectionTitle::make('Cliente')
                    ->tooltip('Scegliendo il cliente si riempiono fatturazione, consegna e pagamento dalla sua scheda. Se non c\'è, crealo con il bottone qui sotto: si apre una finestra e quello che hai scritto nella pagina resta.')
                    ->columnSpan(12),
                // Persone e aziende: il valore è la chiave composta (`contact:12`, `company:7`).
                FormField::key('customer')->searchText(self::customersSearchUrl())
                    ->valueLabel(self::customerLabel($customerId, $companyId))
                    ->emptyText(self::noCustomerText())
                    ->label('Cliente')
                    ->value(OfficeSearch::customerKey($customerId, $companyId))
                    ->columnSpan(12),
                ...self::newCustomerButtons(),
                // Il referente dell'azienda: lo script rifà le opzioni quando
                // cambia il cliente e scrive la scelta in `customer_id`. Il
                // «+» lo crea già legato all'azienda scelta.
                CustomerResource::relationsShown()
                    ? self::field(
                        FormField::key('referent')->select(self::referentOptions($companyId))
                            ->quickCreate(CustomerResource::class, ['name', 'surname', 'email'], self::personModal(true), 'name', 'Nuovo referente'),
                        'referent', 'Referente', 12, (string) ($companyId > 0 ? $customerId : 0)
                    )->hiddenWhen('company_id', ['0', ''])
                    : null,
                // Il brand dell'azienda, con «Brand»: lo script rifà le opzioni
                // come per il referente; il «+» lo crea già legato all'azienda.
                Brands::enabled()
                    ? self::field(
                        FormField::key('company_brand_id')->select(self::brandOptions($companyId, $values))
                            ->quickCreate(CompanyBrandResource::class, ['name'], self::brandModal(), 'name', 'Nuovo brand'),
                        'company_brand_id', 'Brand', 12, (string) ($companyId > 0 ? (int) ($values['company_brand_id'] ?? 0) : 0)
                    )->hiddenWhen('company_id', ['0', ''])
                    : null,
                // Il logo del brand scelto: lo ridisegna lo script (`logo()`).
                Brands::enabled()
                    ? RichText::make('<div data-wi-oo-brand-logo>'.DocumentBrands::logoOf($values).'</div>')->tag('div')->columnSpan(12)
                    : null,
                // Sotto il cliente, a chi va l'email: il PDF del preventivo o la conferma.
                FormField::key('notify')->toggle()
                    ->label($quote ? 'Manda l\'email con il PDF' : 'Alla conferma scrivi al cliente')
                    ->value($quote ? 'true' : 'false')
                    ->columnSpan(12),
                ...$nascosti,
            ])))->columns(12)->columnSpan(12),
            // La Fatturazione di un'azienda apre la sua finestra: lo script cambia il bersaglio.
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
            $riquadro('Consegna', 'Con la spedizione la merce va all\'indirizzo spuntato a sinistra (senza spunta, a quello di fatturazione); con il ritiro il cliente passa dalla sede scelta. Con un metodo scelto il costo è quello del listino; con «Nessuno» lo scrivi a mano, anche zero.', array_values(array_filter([
                self::field(FormField::key('fulfillment_type')->select(['shipping' => 'Spedizione', 'pickup' => 'Ritiro in sede', 'none' => 'Nessuna consegna']), 'fulfillment_type', 'Come arriva la merce', 3, (string) ($values['fulfillment_type'] ?? 'shipping')),
                $locations !== []
                    ? self::field(FormField::key('location_id')->select($locations), 'location_id', 'Sede che evade', 3, (string) ($values['location_id'] ?? ''))->attribute('data-wi-office-location')->hiddenWhen('fulfillment_type', 'pickup')
                    : null,
                $pickupPoints !== []
                    ? self::field(FormField::key('pickup_location_id')->select(['0' => 'Scegli la sede'] + $pickupPoints), 'pickup_location_id', 'Sede del ritiro', 6, $pickup)->visibleWhen('fulfillment_type', 'pickup')
                    : null,
                $shippingMethods !== []
                    ? self::field(FormField::key('shipping_method_id')->select(['0' => 'Nessuno'] + $shippingMethods), 'shipping_method_id', 'Metodo di spedizione', 3, (string) ($values['shipping_method_id'] ?? '0'))->visibleWhen('fulfillment_type', 'shipping')
                    : null,
                self::field(FormField::key('shipping_amount')->text(), 'shipping_amount', 'Costo di spedizione (€)', 3, $v('shipping_amount'))->attribute('inputmode="decimal"')->attribute('data-wi-oo-shipping-amount')->visibleWhen('fulfillment_type', 'shipping'),
            ]))),
            $riquadro('Pagamento e sconto', $quote
                ? 'La condizione dice in quante rate si pagherà: le rate nascono quando l\'ordine si conferma.'
                : 'La condizione dice in quante rate e quando si paga: alla conferma nascono le rate nello Scadenzario.', array_values(array_filter([
                self::field(FormField::key('payment_method_id')->select(['0' => 'Nessuno'] + $resource::paymentMethods()), 'payment_method_id', 'Metodo di pagamento', 3, (string) ($values['payment_method_id'] ?? '0')),
                count($terms) > 1
                    ? self::field(FormField::key('payment_term_id')->select(['0' => 'Nessuna (a vista)'] + $terms), 'payment_term_id', 'Condizione di pagamento', 3, (string) ($values['payment_term_id'] ?? '0'))
                    : null,
                // Il listino si sceglie dal primo salvataggio: prima vale quello del cliente.
                Gestionale::feature('customer_price_lists') && (int) ($values['id'] ?? 0) > 0
                    ? self::field(FormField::key('price_list_id')->select(['0' => 'Nessuno (prezzi di catalogo)'] + PriceListResource::options((int) ($values['price_list_id'] ?? 0))), 'price_list_id', 'Listino', 3, (string) ($values['price_list_id'] ?? '0'))
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
            RichText::make(self::buttonsHtml($quote, $values))->tag('div')->columnSpan(12),
        ])->columns(12)->columnSpan(12);

        return (new Form)->components([
            (new Container)->columnSpan(['default' => 12, 'lg' => 4])->columns(12)->components($sinistra),
            (new Container)->columnSpan(['default' => 12, 'lg' => 8])->columns(12)->components($componenti),
            $pulsanti,
        ])->columns(12);
    }

    /**
     * Le scelte del referente: nessuno, poi le persone dell'azienda.
     *
     * @return array<string, string>
     */
    public static function referentOptions(int $companyId): array
    {
        $options = ['0' => 'Nessun referente'];

        foreach (DocumentReferents::people($companyId) as $option) {
            $options[(string) $option['id']] = $option['name'].($option['role'] !== '' ? ' ('.$option['role'].')' : '');
        }

        return $options;
    }

    /**
     * Le scelte del brand: nessuno, poi quelli dell'azienda. Il brand di un
     * documento salvato resta fra le scelte anche se l'azienda non l'ha più
     * (staccato o archiviato): altrimenti la tendina lo perderebbe salvando.
     *
     * @param array<string, mixed> $values il documento
     * @return array<string, string>
     */
    public static function brandOptions(int $companyId, array $values = []): array
    {
        $options = ['0' => 'Nessun brand'];

        foreach (CompanyBrands::of($companyId) as $brand) {
            $options[(string) $brand['id']] = CompanyBrands::label($brand);
        }

        $saved = (int) ($values['company_brand_id'] ?? 0);

        if ($saved > 0 && (int) ($values['id'] ?? 0) > 0 && (int) ($values['company_id'] ?? 0) === $companyId && !isset($options[(string) $saved])) {
            $options[(string) $saved] = DocumentBrands::nameOf($values).' (non più dell\'azienda)';
        }

        return $options;
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
            ->attr('data-wi-oo-edit', $key)
            ->attr('disabled', true)
            ->render('bootstrap');

        return (new Card)->components([
            RichText::make('<div class="d-flex align-items-center justify-content-between gap-2">'
                .SectionTitle::make($title)->render('bootstrap').$modifica.'</div>')->tag('div')->columnSpan(12),
            RichText::make('<div data-wi-oo-panel="'.$key.'"><p class="text-muted small mb-0">Scegli un cliente.</p></div>')
                ->tag('div')->columnSpan(12),
        ])->columns(12)->columnSpan(12);
    }

    /** Il nome del cliente scelto, nel campo di ricerca: la ragione sociale o la persona. */
    private static function customerLabel(int $customerId, int $companyId = 0): string
    {
        if ($companyId <= 0 && $customerId <= 0) {
            return '';
        }

        try {
            $row = $companyId > 0 ? Company::findById($companyId) : Contact::findById($customerId);
        } catch (Throwable) {
            return '';
        }

        if (!is_array($row) || $row === []) {
            return '';
        }

        return $companyId > 0 ? trim((string) ($row['business_name'] ?? '')) : Contacts::displayName($row);
    }

    /** La ricerca del cliente con persone e aziende (`mixed=1`). */
    private static function customersSearchUrl(): string
    {
        $url = OfficeOrderEditor::customersUrl();

        return $url.(str_contains($url, '?') ? '&' : '?').'mixed=1';
    }

    /**
     * I bottoni del cliente nuovo, sotto la ricerca: la persona dove si vende
     * ai privati, l'azienda dove ci sono le aziende. Ognuno apre la finestra
     * della creazione rapida; lo script della pagina sceglie la scheda nata
     * (`data-wi-oo-new` dice se è `contact` o `company`) come dalla ricerca.
     * Chi non può creare schede non li vede (`QuickCreateAuthorizer`).
     *
     * @return list<QuickCreateButton>
     */
    public static function newCustomerButtons(): array
    {
        $buttons = [];

        if (CustomerMode::people()) {
            $buttons[] = QuickCreateButton::make(CustomerResource::class)
                ->text(CustomerMode::companies() ? 'Nuova persona' : 'Nuovo cliente')
                ->fields(['name', 'surname', 'email'])
                ->layout(self::personModal(false))
                ->label('name')
                ->id('wi-oo-new-contact')
                ->attr('data-wi-oo-new', 'contact');
        }

        if (CustomerMode::companies()) {
            $buttons[] = QuickCreateButton::make(CompanyResource::class)
                ->text('Nuova azienda')
                ->fields(['business_name', 'pi'])
                ->layout(self::companyModal())
                ->label('business_name')
                ->id('wi-oo-new-company')
                ->attr('data-wi-oo-new', 'company');
        }

        $span = count($buttons) > 1 ? 6 : 12;

        return array_map(
            static fn (QuickCreateButton $button): QuickCreateButton => $button->size('sm')->columnSpan(['default' => 12, 'sm' => $span]),
            $buttons
        );
    }

    /*
     * Le finestre hanno i loro campi, gli stessi che legge `QuickContacts`
     * (o il brand): `getInput()` costruirebbe ogni volta il form intero della
     * scheda, con le tendine di tutte le aziende e persone.
     */

    /**
     * La finestra di una persona nuova: nome, cognome, email. Il cliente porta
     * `role` = `customer`; il referente l'azienda a cui legarlo, che lo script
     * scrive all'apertura (`data-wi-oo-qc-company`).
     */
    private static function personModal(bool $referent): Closure
    {
        return static fn (): Container => (new Container)->components([
            ...CustomerResource::quickPersonFields(),
            $referent ? self::linkCompanyField() : FormField::key('role')->hidden()->value(QuickContacts::ROLE_CUSTOMER),
        ])->columns(12);
    }

    /** La finestra di un'azienda nuova: ragione sociale e partita IVA, facoltativa. */
    private static function companyModal(): Closure
    {
        return static fn (): Container => (new Container)->components([
            FormField::key('business_name')->text()->label('Ragione sociale')->required()->columnSpan(12),
            FormField::key('pi')->text()->label('Partita IVA')->columnSpan(12),
        ])->columns(12);
    }

    /** La finestra di un brand nuovo: il nome e l'azienda a cui legarlo. */
    private static function brandModal(): Closure
    {
        return static fn (): Container => (new Container)->components([
            FormField::key('name')->text()->label('Nome del brand')->required()->columnSpan(12),
            self::linkCompanyField(),
        ])->columns(12);
    }

    private static function linkCompanyField(): Input
    {
        return FormField::key(QuickContacts::LINK_COMPANY)->hidden()->value('0')->attribute('data-wi-oo-qc-company');
    }

    /** La frase della ricerca vuota: dice quali bottoni ci sono. */
    private static function noCustomerText(): string
    {
        return match (CustomerMode::current()) {
            CustomerMode::PEOPLE => 'Nessun cliente trovato: crealo con «Nuovo cliente».',
            CustomerMode::COMPANIES => 'Nessuna azienda trovata: creala con «Nuova azienda».',
            default => 'Nessun cliente trovato: crealo con «Nuova persona» o «Nuova azienda».',
        };
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
     * Le azioni sulla pagina, nell'ordine della testa: per il preventivo
     * *Trasforma in ordine*, *Invia al cliente* ed *Elimina*; per la bozza
     * *Conferma ordine* ed *Elimina bozza*. *Rifiutato* resta in fondo.
     *
     * @return list<array{intent: string, label: string, icon: string, variant: string, outline: bool, header: bool, confirm: array{0: string, 1: string, 2: string, 3: string}}>
     */
    public static function intents(bool $quote, array $values): array
    {
        $editing = (int) ($values['id'] ?? 0) > 0;
        $status = (string) ($values['status'] ?? 'draft');
        $neverSent = (int) ($values['quote_revision'] ?? 0) === 0;
        $intents = [];

        if ($quote) {
            // Anche su un preventivo nuovo: prima si salva, poi passa a ordine.
            if (in_array($status, ['draft', 'sent'], true)) {
                $intents[] = ['intent' => 'accept', 'label' => 'Trasforma in ordine', 'icon' => 'bi bi-arrow-right-circle', 'variant' => 'success', 'outline' => true, 'header' => true,
                    'confirm' => ['Il cliente ha accettato: il preventivo si salva e diventa una bozza d\'ordine con le stesse righe e lo stesso numero. Lì controlli consegna e pagamento e lo confermi.', 'Trasforma in ordine', 'Trasforma in ordine', 'success']];
            }

            $intents[] = ['intent' => 'send', 'label' => $status === 'sent' ? 'Invia di nuovo' : 'Invia al cliente', 'icon' => 'bi bi-send', 'variant' => 'dark', 'outline' => false, 'header' => true,
                'confirm' => [$neverSent
                    ? 'Il preventivo si salva e si archivia il suo PDF; con l\'interruttore acceso il cliente lo riceve per email.'
                    : 'Il preventivo si salva e, se è cambiato, diventa una revisione nuova con il suo PDF; con l\'interruttore acceso il cliente lo riceve per email.', 'Invia il preventivo', 'Invia', 'primary']];

            if ($editing && $neverSent && $status === 'draft') {
                $intents[] = ['intent' => 'delete', 'label' => 'Elimina', 'icon' => 'bi bi-trash', 'variant' => 'danger', 'outline' => true, 'header' => true,
                    'confirm' => ['Il preventivo non è mai stato inviato: si elimina con le sue righe.', 'Elimina preventivo', 'Elimina', 'danger']];
            } elseif ($editing && $status === 'sent') {
                $intents[] = ['intent' => 'reject', 'label' => 'Rifiutato', 'icon' => 'bi bi-x-circle', 'variant' => 'danger', 'outline' => true, 'header' => false,
                    'confirm' => ['Il preventivo si segna come rifiutato. Le modifiche non salvate di questa pagina si perdono; modificandolo dopo lo riapri.', 'Preventivo rifiutato', 'Rifiutato', 'danger']];
            }

            return $intents;
        }

        $intents[] = ['intent' => 'confirm', 'label' => 'Conferma ordine', 'icon' => 'bi bi-check2-circle', 'variant' => 'dark', 'outline' => false, 'header' => true,
            'confirm' => ['La bozza si salva, la merce esce dal magazzino e nascono le rate; il numero resta quello della bozza. Dopo le righe non si modificano più: si possono solo annullare.', 'Conferma ordine', 'Conferma ordine', 'primary']];

        if ($editing) {
            $intents[] = ['intent' => 'delete', 'label' => 'Elimina bozza', 'icon' => 'bi bi-trash', 'variant' => 'danger', 'outline' => true, 'header' => true,
                'confirm' => ['La bozza si elimina con le sue righe. Non ha movimenti di magazzino; il suo numero non si riusa.', 'Elimina bozza', 'Elimina', 'danger']];
        }

        return $intents;
    }

    /**
     * I pulsanti in testa, accanto a *Guida*: ognuno preme il suo gemello
     * nascosto nel form, così l'invio porta le righe e passa dalla conferma
     * della lib come prima.
     *
     * @return list<array<string, string>>
     */
    public static function headerActions(bool $quote, array $values): array
    {
        $actions = [];

        foreach (self::intents($quote, $values) as $intent) {
            if (!$intent['header']) {
                continue;
            }

            $actions[] = [
                'label' => $intent['label'],
                'icon' => str_replace('bi ', '', $intent['icon']),
                'class' => 'btn-'.($intent['outline'] ? 'outline-' : '').$intent['variant'].' btn-sm',
                'onclick' => "document.querySelector('form button[name=intent][value=".$intent['intent']."]').click()",
            ];
        }

        return $actions;
    }

    /**
     * I pulsanti del form. *Salva bozza* è il primo, così Invio salva; quelli
     * della testa ci sono ma nascosti, e l'isola di salvataggio non li copia.
     */
    private static function buttonsHtml(bool $quote, array $values): string
    {
        $pulsanti = [Button::make('Salva bozza')
            ->type('submit')->attr('name', 'intent')->attr('value', 'save')
            ->variant('dark')->outline(true)->schema('inline', true)];

        foreach (self::intents($quote, $values) as $intent) {
            [$message, $title, $ok, $variant] = $intent['confirm'];
            $pulsanti[] = Button::make($intent['label'])
                ->icon($intent['icon'])
                ->type('submit')
                ->attr('name', 'intent')
                ->attr('value', $intent['intent'])
                ->variant($intent['variant'])
                ->outline($intent['outline'])
                ->attr('class', $intent['header'] ? 'd-none' : 'order-first me-auto')
                ->attr('data-wi-save-bar-ignore', true)
                ->schema('inline', true)
                ->confirm($message, title: $title, ok: $ok, variant: $variant);
        }

        return '<div class="d-flex flex-wrap align-items-center justify-content-end gap-2">'
            .implode('', array_map(static fn (Button $button): string => $button->render('bootstrap'), $pulsanti))
            .'</div>';
    }
}
