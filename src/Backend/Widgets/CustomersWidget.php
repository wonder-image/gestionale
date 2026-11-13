<?php

namespace Wonder\Plugin\Gestionale\Backend\Widgets;

use Throwable;
use Wonder\App\Permission\Access;
use Wonder\Backend\Contracts\HomeWidget;
use Wonder\Elements\Components\StatusCard;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Contacts\Company;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Resources\Contacts\CompanyResource;
use Wonder\Plugin\Gestionale\Resources\Contacts\CustomerResource;
use Wonder\Plugin\Gestionale\Support\Contacts\CustomerCounts;
use Wonder\Plugin\Gestionale\Support\Contacts\CustomerMode;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\SalesAgents\SalesAgents;

/**
 * «Clienti»: quanti clienti privati e quante aziende clienti, con i nuovi
 * del mese. Ogni card segue il modo del gestionale (`CustomerMode`): le
 * aziende compaiono solo con la funzionalità *Aziende* accesa.
 */
final class CustomersWidget implements HomeWidget
{
    public function title(): string
    {
        return 'Clienti';
    }

    public function authorities(): array
    {
        // I totali sono di tutti: il commerciale ha il suo riquadro.
        return SalesAgents::withoutAgents(Access::viewers(Gestionale::area('contatti')));
    }

    public function order(): int
    {
        return 18;
    }

    public function render(): string
    {
        try {
            return self::markup(
                CustomerMode::people() ? CustomerCounts::of(Contact::class) : null,
                CustomerMode::companies() ? CustomerCounts::of(Company::class) : null,
                self::urls()
            );
        } catch (Throwable $error) {
            // La home si apre lo stesso: il guasto va nel log, non in pagina.
            Errors::internal($error, 'widget.customers');

            return '';
        }
    }

    /**
     * Gli elenchi con il filtro «Clienti», dove c'è: senza, l'elenco ha già
     * solo i clienti (persone) o anche i fornitori (aziende senza acquisti no).
     *
     * @return array{clienti: string, aziende: string}
     */
    public static function urls(): array
    {
        $people = CustomerResource::listUrl();
        $companies = CompanyResource::listUrl();

        if (CustomerResource::relationsShown() && CustomerMode::current() === CustomerMode::BOTH) {
            $people .= '?'.Contact::$table.'__role=customers';
        }

        if (CompanyResource::rolesShown()) {
            $companies .= '?'.Company::$table.'__role=customer';
        }

        return ['clienti' => $people, 'aziende' => $companies];
    }

    /**
     * `null` vuol dire che il gestionale non lavora con quel tipo di cliente.
     *
     * @param array{totale: int, mese: int}|null $people
     * @param array{totale: int, mese: int}|null $companies
     * @param array<string, string> $urls
     */
    public static function markup(?array $people, ?array $companies, array $urls = []): string
    {
        $cards = [];

        if ($people !== null) {
            $people = CustomerCounts::normalize($people);
            $cards[] = StatusCard::make('Clienti', (string) $people['totale'])
                ->icon('bi-person')
                ->variant('primary')
                ->detail(match ($people['mese']) {
                    0 => 'Nessuno nuovo questo mese.',
                    1 => '1 nuovo questo mese.',
                    default => $people['mese'].' nuovi questo mese.',
                })
                ->href($urls['clienti'] ?? '');
        }

        if ($companies !== null) {
            $companies = CustomerCounts::normalize($companies);
            $cards[] = StatusCard::make('Aziende clienti', (string) $companies['totale'])
                ->icon('bi-building')
                ->variant('primary')
                ->detail(match ($companies['mese']) {
                    0 => 'Nessuna nuova questo mese.',
                    1 => '1 nuova questo mese.',
                    default => $companies['mese'].' nuove questo mese.',
                })
                ->href($urls['aziende'] ?? '');
        }

        return WidgetCard::halfRow($cards);
    }
}
