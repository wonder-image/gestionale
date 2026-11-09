<?php

namespace Wonder\Plugin\Gestionale\Resources\SalesAgents;

use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Form;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Contacts\Company;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\SalesAgents\SalesAgent;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Numbers;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeOrderEditor;
use Wonder\Plugin\Gestionale\Support\SalesAgents\SalesAgents;

/**
 * «Commerciali» (#77, funzionalità `sales_agents`): chi vende per il negozio e
 * vede solo i propri clienti. Il commerciale è un utente del backend col ruolo
 * `sales_agent`; qui si lega a lui la scheda, una per utente.
 *
 * Solo l'ufficio (area `commerciali`) li gestisce. I clienti si assegnano
 * dalla scheda del cliente o dell'azienda.
 */
final class SalesAgentResource extends GestionaleResource
{
    public static string $model = SalesAgent::class;
    public static string $feature = 'sales_agents';
    public static string $docsPage = 'anagrafiche/commerciali';

    public static function path(): string
    {
        return 'app/gestionale/commerciali';
    }

    public static function icon(): string
    {
        return 'bi-person-badge';
    }

    public static function titleLabel(): string
    {
        return 'Commerciali';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'commerciale',
            'plural_label' => 'commerciali',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'i',
            'full' => 'attivo',
            'empty' => 'non attivo',
            'this' => 'questo',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'user_id' => 'Commerciale',
            'contact_id' => 'Scheda anagrafica',
            'commission_percent' => 'Provvigione %',
            'max_discount_percent' => 'Sconto massimo %',
            'active' => 'Stato',
            'note' => 'Note',
            'customers' => 'Clienti',
        ];
    }

    public static function formSchema(): array
    {
        return [
            FormField::key('user_id')
                ->select(['' => 'Scegli'] + SalesAgents::userOptions())
                ->label('Utente')
                ->required(),
            FormField::key('contact_id')->searchText(OfficeOrderEditor::customersUrl())
                ->emptyText('Nessun contatto trovato: crealo dai Contatti.')
                ->label('Scheda anagrafica'),
            FormField::key('commission_percent')->number()->decimal(2)->label('Provvigione %'),
            FormField::key('max_discount_percent')->number()->decimal(2)->label('Sconto massimo %'),
            FormField::key('active')
                ->select(['true' => 'Attivo', 'false' => 'Non attivo'])
                ->value('true')
                ->label('Stato'),
            FormField::key('note')->textarea()->label('Note'),
        ];
    }

    public static function formLayoutSchema(): ?Form
    {
        // Provvigione e sconto compaiono con la loro funzionalità; spenta, il valore salvato resta.
        $conditions = [];
        if (Gestionale::feature('sales_agent_commissions')) {
            $conditions[] = static::getInput('commission_percent')->columnSpan(6);
        }
        if (Gestionale::feature('sales_agent_discount_limits')) {
            $conditions[] = static::getInput('max_discount_percent')->columnSpan(6);
        }

        return (new Form)->components([
            (new Container)->components([
                (new Card)->components([
                    SectionTitle::make('Commerciale')
                        ->tooltip('L\'utente è chi entra nel pannello: crealo da Utenti col ruolo «Commerciale», poi sceglilo qui. Vede e gestisce solo i clienti e le aziende assegnati a lui e le vendite che ha fatto. Un commerciale non attivo non entra più nei suoi clienti: i suoi restano assegnati finché non li sposti.')
                        ->columnSpan(12),
                    static::getInput('user_id')->columnSpan(6),
                    static::getInput('active')->columnSpan(6),
                    static::getInput('contact_id')->columnSpan(12),
                ])->columns(12)->columnSpan(12),
                (new Card)->components([
                    SectionTitle::make($conditions === [] ? 'Note' : 'Condizioni')->columnSpan(12),
                    ...$conditions,
                    static::getInput('note')->columnSpan(12),
                ])->columns(12)->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ]);
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('user_id')
                ->text()
                ->link('edit')
                ->formatter(static fn (array $row): string => static::escape(SalesAgents::label($row))),
            TableColumn::key('customers')
                ->text()
                ->formatter(static fn (array $row): string => (string) static::customers((int) ($row['id'] ?? 0))),
            TableColumn::key('active')
                ->booleanBadge()
                ->badgeOn('Attivo', 'bi-check-circle', 'success')
                ->badgeOff('Non attivo', 'bi-dash-circle', 'secondary')
                ->size('little'),
            TableColumn::key('actions')->button()->actions([
                'edit',
                'active' => ['label' => ['true' => 'Disattiva', 'false' => 'Attiva'], 'request' => 'boolean'],
                'delete',
            ]),
        ];
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()
            ->disable(['view'])
            ->titles([
                'list' => 'Commerciali',
                'create' => 'Nuovo commerciale',
                'edit' => 'Modifica commerciale',
            ]);
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->access(Gestionale::area('commerciali'));
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->enabled(false);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->section('anagrafiche', 'Anagrafiche', 'bi-people', 320)
            ->title('Commerciali')
            ->order(40)
            ->access(Gestionale::area('commerciali'))
            ->enabled(static::featureActive());
    }

    /** Persone e aziende assegnate. */
    public static function customers(int $id): int
    {
        if ($id <= 0) {
            return 0;
        }

        return (int) sqlCount(Contact::$table, 'agent_id = '.$id." AND deleted = 'false'")
            + (int) sqlCount(Company::$table, 'agent_id = '.$id." AND deleted = 'false'");
    }

    public static function mutateRequestValues(
        array $values,
        string $action,
        string $context = 'backend',
        ?array $oldValues = null
    ): array {
        if (array_key_exists('user_id', $values) || $action === 'store') {
            $userId = (int) ($values['user_id'] ?? 0);
            if ($userId <= 0) {
                throw UserError::make('sales_agent.user_required');
            }
            if (!array_key_exists($userId, SalesAgents::userOptions())) {
                throw UserError::make('sales_agent.user_not_agent');
            }

            // Un utente, un commerciale: la visibilità legge una riga sola.
            $self = (int) ($oldValues['id'] ?? 0);
            $taken = sqlCount(SalesAgent::$table, 'user_id = '.$userId." AND deleted = 'false'".($self > 0 ? ' AND id <> '.$self : ''));
            if ((int) $taken > 0) {
                throw UserError::make('sales_agent.user_taken');
            }
            $values['user_id'] = $userId;
        }

        foreach (['commission_percent' => 'Provvigione', 'max_discount_percent' => 'Sconto massimo'] as $key => $label) {
            if (!array_key_exists($key, $values)) {
                continue;
            }
            $value = Numbers::fromForm($values[$key]);
            if ($value !== null && ((float) $value < 0 || (float) $value > 100)) {
                throw UserError::make('sales_agent.invalid_percent', ['field' => $label]);
            }
            $values[$key] = $value ?? '0';
        }

        // Il nuovo stato vale subito per la visibilità.
        SalesAgents::forget();

        return $values;
    }
}
