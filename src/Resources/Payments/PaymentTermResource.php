<?php

namespace Wonder\Plugin\Gestionale\Resources\Payments;

use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\RepeaterColumn;
use Wonder\App\ResourceSchema\RepeaterRelation;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\App\Support\Repeater;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Form;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentTerm;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentTermInstallment;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Payments\PaymentTermRules;
use Wonder\Plugin\Gestionale\Support\Numbers;
use Wonder\Plugin\Gestionale\Support\Positions;

/**
 * «Condizioni di pagamento»: in quante rate e quando si paga un ordine da
 * ufficio (G9). Le rate stanno in un repeater: giorni, fine mese, percentuale.
 *
 * Configurazione di `admin`, come metodi e conti. Una condizione usata da un
 * ordine o da un cliente non si elimina: si spegne.
 */
final class PaymentTermResource extends GestionaleResource
{
    public static string $model = PaymentTerm::class;
    public static string $orderColumn = 'position';
    public static string $orderDirection = 'ASC';

    public static function path(): string
    {
        return 'app/gestionale/condizioni-di-pagamento';
    }

    public static function icon(): string
    {
        return 'bi-calendar3';
    }

    public static function titleLabel(): string
    {
        return 'Condizioni di pagamento';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'condizione',
            'plural_label' => 'condizioni',
            'last' => 'ultime',
            'all' => 'tutte',
            'article' => 'le',
            'full' => 'attiva',
            'empty' => 'non attiva',
            'this' => 'questa',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'name' => 'Nome',
            'sdi_condition' => 'Condizione',
            'installments' => 'Rate',
            'active' => 'Stato',
        ];
    }

    /** Le condizioni come le chiede la fattura elettronica. */
    public static function sdiOptions(): array
    {
        return [
            'TP02' => 'Pagamento completo',
            'TP01' => 'Pagamento a rate',
            'TP03' => 'Anticipo',
        ];
    }

    public static function formSchema(): array
    {
        return [
            FormField::key('name')->text()->label('Nome')->required(),
            FormField::key('sdi_condition')
                ->select(static::sdiOptions())
                ->value('TP02')
                ->label('Condizione')
                ->required(),
            FormField::key('active')
                ->select(['true' => 'Attiva', 'false' => 'Non attiva'])
                ->value('true')
                ->label('Stato'),
            FormField::key('installments')
                ->repeater([
                    RepeaterColumn::key('id')->hidden(),
                    RepeaterColumn::key('days')->number()->decimal(0)->label('Giorni')->columnSpan(3),
                    RepeaterColumn::key('end_of_month')
                        ->select(['false' => 'No', 'true' => 'Sì'])
                        ->label('Fine mese')
                        ->columnSpan(3),
                    RepeaterColumn::key('percentage')->number()->decimal(2)->label('Percentuale')->columnFill(),
                ])
                ->relation(
                    RepeaterRelation::make(PaymentTermInstallment::$table, 'payment_term_id')
                        ->model(PaymentTermInstallment::class)
                        ->positionKey('position')
                        ->softDelete(false)
                )
                ->nested()
                ->repeaterSortable()
                ->repeaterAddLabel('Aggiungi rata')
                ->repeaterDeleteTitle('Elimina rata')
                ->repeaterDeleteText('Confermi l\'eliminazione di questa rata?')
                ->repeaterDeleteCancelLabel('Annulla')
                ->repeaterDeleteConfirmLabel('Elimina')
                ->repeaterDeleteConfirmClass('btn btn-danger')
                // Il titolo del riquadro dice già «Rate».
                ->label(''),
        ];
    }

    public static function formLayoutSchema(): ?Form
    {
        return (new Form)->components([
            (new Container)->components([
                (new Card)->components([
                    SectionTitle::make('Condizione')
                        ->tooltip('Il nome lo vedono il cliente e l\'operatore: dillo come lo diresti a voce («Bonifico 30 gg fine mese»). La condizione serve alla fattura elettronica: un pagamento solo, a rate o un anticipo. Una condizione non attiva non si propone più, ma resta sugli ordini che la usano.')
                        ->columnSpan(12),
                    static::getInput('name')->columnSpan(6),
                    static::getInput('sdi_condition')->columnSpan(3),
                    static::getInput('active')->columnSpan(3),
                ])->columns(12)->columnSpan(12),
                (new Card)->components([
                    SectionTitle::make('Rate')
                        ->tooltip('Una riga per rata. I giorni partono dalla conferma dell\'ordine; con «Fine mese» la scadenza va all\'ultimo giorno di quel mese (30 giorni fine mese dal 10 ottobre è il 30 novembre). Le percentuali fanno 100; l\'ultima rata prende i centesimi dell\'arrotondamento.')
                        ->columnSpan(12),
                    static::getInput('installments')->columnSpan(12),
                ])->columns(12)->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ]);
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('name')->text()->link('edit'),
            TableColumn::key('installments')
                ->text()
                ->formatter(static fn (array $row): string => static::escape(static::summary((int) ($row['id'] ?? 0)))),
            TableColumn::key('sdi_condition')
                ->text()
                ->formatter(static fn (array $row): string => static::escape(static::sdiOptions()[(string) ($row['sdi_condition'] ?? '')] ?? '')),
            TableColumn::key('active')
                ->booleanBadge()
                ->badgeOn('Attiva', 'bi-check-circle', 'success')
                ->badgeOff('Non attiva', 'bi-dash-circle', 'secondary')
                ->size('little'),
            TableColumn::key('actions')->button()->actions([
                'edit',
                // L'etichetta segue lo stato della riga; il core inverte la colonna.
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
                'list' => 'Condizioni di pagamento',
                'create' => 'Nuova condizione',
                'edit' => 'Modifica condizione',
            ]);
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->access(Gestionale::area('impostazioni_pagamenti'));
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->enabled(false);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->inSection('set-up')
            ->group('pagamenti', 'Pagamenti', 60)
            ->title('Condizioni di pagamento')
            ->order(30)
            ->access(Gestionale::area('impostazioni_pagamenti'));
    }

    /** «30 gg fm 50% · 60 gg fm 50%», per l'elenco. */
    public static function summary(int $termId): string
    {
        $parts = [];

        foreach (static::rowsOf(PaymentTermInstallment::class, ['payment_term_id' => $termId], 'position') as $rata) {
            $parts[] = static::installmentLabel($rata);
        }

        return implode(' · ', $parts);
    }

    /** @param array<string, mixed> $rata */
    public static function installmentLabel(array $rata): string
    {
        $days = (int) ($rata['days'] ?? 0);
        $when = $days === 0 ? 'subito' : $days.' gg';

        if ((string) ($rata['end_of_month'] ?? 'false') === 'true') {
            $when .= ' fine mese';
        }

        return $when.' '.str_replace('.', ',', (string) (round((float) ($rata['percentage'] ?? 0), 2) + 0)).'%';
    }

    /** Le rate si controllano tutte insieme: le percentuali fanno 100. */
    public static function mutateRequestValues(
        array $values,
        string $action,
        string $context = 'backend',
        ?array $oldValues = null
    ): array {
        $rows = Repeater::rowsFromRequest('installments', (array) $_POST);
        PaymentTermRules::installments($rows);

        if ($action === 'store') {
            $values['position'] = Positions::next(PaymentTerm::$table);
        } else {
            unset($values['position']);
        }

        return $values;
    }

    /**
     * Le righe del repeater senza giorni né percentuale non sono rate; le
     * altre si scrivono come il controllo le ha lette: giorni interi, la
     * percentuale col punto, fine mese sì o no.
     */
    public static function prepareRepeaterRows(
        string $inputName,
        array $rows,
        string $action = 'store',
        string $context = 'backend'
    ): array {
        if ($inputName !== 'installments') {
            return $rows;
        }

        $rate = [];

        foreach ($rows as $row) {
            if (!is_array($row) || (trim((string) ($row['days'] ?? '')) === '' && trim((string) ($row['percentage'] ?? '')) === '')) {
                continue;
            }

            $row['days'] = (int) trim((string) ($row['days'] ?? '0'));
            $row['percentage'] = Numbers::fromForm($row['percentage'] ?? null) ?? '';
            $row['end_of_month'] = ($row['end_of_month'] ?? 'false') === 'true' ? 'true' : 'false';
            $rate[] = $row;
        }

        return $rate;
    }

    /** Quanti ordini e clienti la usano. */
    public static function usage(int $id): int
    {
        if ($id <= 0) {
            return 0;
        }

        return (int) sqlCount(Order::$table, 'payment_term_id = '.$id." AND deleted = 'false'")
            + (int) sqlCount(Contact::$table, 'payment_term_id = '.$id." AND deleted = 'false'");
    }

    /** Una condizione in uso non si elimina: si spegne. */
    public static function assertDeletable(int|string $id): void
    {
        $count = static::usage((int) $id);

        if ($count > 0) {
            throw UserError::refusal('payment_term.in_use', ['count' => $count]);
        }
    }
}
