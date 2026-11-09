<?php

namespace Wonder\Plugin\Gestionale\Backend\Widgets;

use Throwable;
use Wonder\App\Visibility\RowVisibility;
use Wonder\Backend\Contracts\HomeWidget;
use Wonder\Elements\Components\LinkListCard;
use Wonder\Plugin\Gestionale\Models\Contacts\Company;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Resources\Contacts\CompanyResource;
use Wonder\Plugin\Gestionale\Resources\Contacts\CustomerResource;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderResource;
use Wonder\Plugin\Gestionale\Resources\Sales\QuoteResource;
use Wonder\Plugin\Gestionale\Support\SalesAgents\SalesAgents;

/**
 * «I miei clienti» (#77): il riquadro del commerciale, al posto di quelli con
 * i totali di tutti. Conta solo ciò che lui vede.
 */
final class SalesAgentWidget implements HomeWidget
{
    public function title(): string
    {
        return 'I miei clienti';
    }

    public function authorities(): array
    {
        return SalesAgents::enabled() ? [SalesAgents::ROLE] : [];
    }

    public function order(): int
    {
        return 30;
    }

    public function render(): string
    {
        // Un utente d'ufficio con anche il ruolo di commerciale ha già i riquadri di tutti.
        if (!SalesAgents::restricted(RowVisibility::currentUser())) {
            return '';
        }

        return self::markup(
            self::count(Contact::class, "is_customer = 'true' AND deleted = 'false'"),
            self::count(Company::class, "deleted = 'false'"),
            self::count(Order::class, "stage = 'quote' AND deleted = 'false'"),
            self::count(Order::class, "stage = 'order' AND status <> 'draft' AND deleted = 'false'")
        );
    }

    public static function markup(int $customers, int $companies, int $quotes, int $orders): string
    {
        $card = LinkListCard::make('I miei clienti')->icon('bi-person-badge')->count(false)
            ->link('Clienti', '/backend/'.CustomerResource::path(), '', '', self::text($customers, 'cliente', 'clienti', 'Nessuno ancora'))
            ->link('Aziende', '/backend/'.CompanyResource::path(), '', '', self::text($companies, 'azienda', 'aziende', 'Nessuna ancora'))
            ->link('Preventivi', '/backend/'.QuoteResource::path(), '', '', self::text($quotes, 'preventivo', 'preventivi', 'Nessuno ancora'))
            ->link('Ordini', '/backend/'.OrderResource::path(), '', '', self::text($orders, 'ordine', 'ordini', 'Nessuno ancora'));

        return WidgetCard::column($card);
    }

    private static function text(int $count, string $singular, string $plural, string $none): string
    {
        return match ($count) {
            0 => $none,
            1 => '1 '.$singular,
            default => $count.' '.$plural,
        };
    }

    /** Quante righe vede chi lavora; zero se il database non risponde. */
    private static function count(string $model, string $condition): int
    {
        try {
            return RowVisibility::current()->count($model, $condition);
        } catch (Throwable) {
            return 0;
        }
    }
}
