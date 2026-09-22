<?php

namespace Wonder\Plugin\Gestionale\Backend\Widgets;

use Throwable;
use Wonder\Backend\Contracts\HomeWidget;
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
        return ['admin', 'administrator'];
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
        $rows = [self::line(
            'Clienti',
            $customers,
            '/backend/'.CustomerResource::path(),
            'cliente'
        )];

        if ($suppliers !== null) {
            $rows[] = self::line(
                'Fornitori',
                $suppliers,
                '/backend/'.SupplierResource::path(),
                'fornitore'
            );
        }

        $body = implode('', $rows);

        return <<<HTML
<wi-card class="col-12">
    <h5 class="mb-2"><i class="bi bi-people"></i> Anagrafiche</h5>
    {$body}
</wi-card>
HTML;
    }

    private static function line(string $label, int $count, string $url, string $singular): string
    {
        $label = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        $url = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $singular = htmlspecialchars($singular, ENT_QUOTES, 'UTF-8');
        $text = $count === 0
            ? 'Nessuno ancora'
            : $count.' '.($count === 1 ? $singular : strtolower($label));

        return <<<HTML
<p class="mb-1 d-flex justify-content-between align-items-center">
    <span><b>{$label}:</b> <span class="text-body-secondary small">{$text}</span></span>
    <a class="btn btn-sm btn-secondary" href="{$url}">Apri</a>
</p>
HTML;
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
