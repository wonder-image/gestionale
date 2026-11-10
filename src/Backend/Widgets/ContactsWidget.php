<?php

namespace Wonder\Plugin\Gestionale\Backend\Widgets;

use Throwable;
use Wonder\App\Permission\Access;
use Wonder\Backend\Contracts\HomeWidget;
use Wonder\Elements\Components\LinkListCard;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Support\SalesAgents\SalesAgents;
use Wonder\Plugin\Gestionale\Models\Contacts\Company;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Resources\Contacts\CompanyResource;
use Wonder\Plugin\Gestionale\Resources\Contacts\CustomerResource;
use Wonder\Plugin\Gestionale\Resources\Contacts\SupplierResource;
use Wonder\Plugin\Gestionale\Support\Contacts\CustomerMode;

/**
 * "Anagrafiche": quanti clienti (persone), quante aziende e quanti fornitori.
 *
 * Due numeri e due link. Serve a due cose: dire a colpo d'occhio se la rubrica
 * si sta riempiendo, e dare la strada più corta per aggiungere una scheda —
 * che nei primi giorni è l'operazione più frequente.
 *
 * I fornitori compaiono solo con la funzionalità *Acquisti* sbloccata: senza,
 * quella parola non esiste in tutto il pannello (D20). Clienti privati e
 * aziende seguono il modo del gestionale (`CustomerMode`).
 */
final class ContactsWidget implements HomeWidget
{
    public function title(): string
    {
        return 'Anagrafiche';
    }

    public function authorities(): array
    {
        // I totali sono di tutti: il commerciale ha il suo riquadro.
        return SalesAgents::withoutAgents(Access::viewers(Gestionale::area('contatti')));
    }

    public function order(): int
    {
        return 30;
    }

    public function render(): string
    {
        return self::markup(
            CustomerMode::people() ? self::count(Contact::class, 'is_customer') : null,
            Gestionale::feature('purchasing') ? self::count(Company::class, 'is_supplier') : null,
            CustomerMode::companies() ? self::count(Company::class, 'deleted', 'false') : null
        );
    }

    /**
     * `null` fra i fornitori vuol dire "gli acquisti sono bloccati"; fra i
     * clienti e le aziende, "non contati" (il gestionale non lavora con loro).
     */
    public static function markup(?int $customers, ?int $suppliers, ?int $companies = null): string
    {
        $card = LinkListCard::make('Anagrafiche')->icon('bi-people')->count(false);

        if ($customers !== null) {
            $card->link('Clienti', '/backend/'.CustomerResource::path(), '', '', self::text($customers, 'cliente', 'clienti'));
        }

        if ($companies !== null) {
            $card->link('Aziende', '/backend/'.CompanyResource::path(), '', '', self::text($companies, 'azienda', 'aziende', 'Nessuna ancora'));
        }

        if ($suppliers !== null) {
            $card->link('Fornitori', '/backend/'.SupplierResource::path(), '', '', self::text($suppliers, 'fornitore', 'fornitori'));
        }

        return WidgetCard::column($card);
    }

    private static function text(int $count, string $singular, string $plural, string $none = 'Nessuno ancora'): string
    {
        return match ($count) {
            0 => $none,
            1 => '1 '.$singular,
            default => $count.' '.$plural,
        };
    }

    /** Quante schede vive hanno quel valore; zero se il database non risponde. */
    private static function count(string $model, string $column, string $value = 'true'): int
    {
        try {
            $rows = $model::find([$column => $value, 'deleted' => 'false']);
        } catch (Throwable) {
            return 0;
        }

        if (!is_array($rows) || $rows === []) {
            return 0;
        }

        return isset($rows['id']) ? 1 : count(array_filter($rows, 'is_array'));
    }
}
