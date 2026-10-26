<?php

namespace Wonder\Plugin\Gestionale\Backend\Widgets;

use Throwable;
use Wonder\App\Permission\Access;
use Wonder\Backend\Contracts\HomeWidget;
use Wonder\Elements\Components\LinkListCard;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Resources\Contacts\CustomerResource;
use Wonder\Plugin\Gestionale\Resources\Contacts\SupplierResource;

/**
 * "Anagrafiche": quanti clienti e quanti fornitori ci sono.
 *
 * Due numeri e due link. Serve a due cose: dire a colpo d'occhio se la rubrica
 * si sta riempiendo, e dare la strada più corta per aggiungere una scheda —
 * che nei primi giorni è l'operazione più frequente.
 *
 * I fornitori compaiono solo con la funzionalità *Acquisti* sbloccata: senza,
 * quella parola non esiste in tutto il pannello (D20).
 */
final class ContactsWidget implements HomeWidget
{
    public function title(): string
    {
        return 'Anagrafiche';
    }

    public function authorities(): array
    {
        return Access::viewers(Gestionale::area('contatti'));
    }

    public function order(): int
    {
        return 30;
    }

    public function render(): string
    {
        return self::markup(
            self::count('is_customer'),
            Gestionale::feature('purchasing') ? self::count('is_supplier') : null
        );
    }

    /** `null` fra i fornitori vuol dire "gli acquisti sono bloccati". */
    public static function markup(int $customers, ?int $suppliers): string
    {
        $card = LinkListCard::make('Anagrafiche')->icon('bi-people')->count(false)
            ->link('Clienti', '/backend/'.CustomerResource::path(), '', '', self::text($customers, 'cliente', 'clienti'));

        if ($suppliers !== null) {
            $card->link('Fornitori', '/backend/'.SupplierResource::path(), '', '', self::text($suppliers, 'fornitore', 'fornitori'));
        }

        return WidgetCard::column($card);
    }

    private static function text(int $count, string $singular, string $plural): string
    {
        return match ($count) {
            0 => 'Nessuno ancora',
            1 => '1 '.$singular,
            default => $count.' '.$plural,
        };
    }

    /** Quante schede vive hanno quel ruolo; zero se il database non risponde. */
    private static function count(string $column): int
    {
        try {
            $rows = Contact::find([$column => 'true', 'deleted' => 'false']);
        } catch (Throwable) {
            return 0;
        }

        if (!is_array($rows) || $rows === []) {
            return 0;
        }

        return isset($rows['id']) ? 1 : count(array_filter($rows, 'is_array'));
    }
}
