<?php

namespace Wonder\Plugin\Gestionale\Resources\Payments;

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
use Wonder\Plugin\Gestionale\Models\Payments\PaymentAccount;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;

/**
 * «Conti di pagamento»: il conto su cui arrivano i bonifici. Banca e IBAN
 * finiscono nell'email di conferma del bonifico e sui documenti.
 */
final class PaymentAccountResource extends GestionaleResource
{
    public static string $model = PaymentAccount::class;
    public static string $orderColumn = 'name';
    public static string $orderDirection = 'ASC';

    public static function path(): string
    {
        return 'app/gestionale/conti-di-pagamento';
    }

    public static function icon(): string
    {
        return 'bi-bank';
    }

    public static function titleLabel(): string
    {
        return 'Conti di pagamento';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'conto',
            'plural_label' => 'conti',
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
            'name' => 'Nome',
            'holder' => 'Intestatario',
            'bank_name' => 'Banca',
            'iban' => 'IBAN',
            'bic' => 'BIC',
            'active' => 'Stato',
        ];
    }

    public static function formSchema(): array
    {
        return [
            FormField::key('name')->text()->label('Nome')->required(),
            FormField::key('holder')->text()->label('Intestatario'),
            FormField::key('bank_name')->text()->label('Banca'),
            FormField::key('iban')->text()->label('IBAN'),
            FormField::key('bic')->text()->label('BIC'),
            FormField::key('active')
                ->select(['true' => 'Attivo', 'false' => 'Non attivo'])
                ->value('true')
                ->label('Stato'),
        ];
    }

    public static function formLayoutSchema(): ?Form
    {
        return (new Form)->components([
            (new Container)->components([
                (new Card)->components([
                    SectionTitle::make('Conto')
                        ->tooltip('Il nome serve a te, per riconoscere il conto (per esempio «Banca principale»). L\'intestatario è a chi è intestato il conto: compare, con banca e IBAN, nell\'email del bonifico. L\'IBAN si può scrivere con gli spazi: si salva compatto e in maiuscolo. Un conto non attivo non si propone più, ma resta sui metodi che lo usano.')
                        ->columnSpan(12),
                    static::getInput('name')->columnSpan(5),
                    static::getInput('holder')->columnSpan(4),
                    static::getInput('active')->columnSpan(3),
                    static::getInput('bank_name')->columnSpan(4),
                    static::getInput('iban')->columnSpan(5),
                    static::getInput('bic')->columnSpan(3),
                ])->columns(12)->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ]);
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('name')->text()->link('edit'),
            TableColumn::key('holder')->text(),
            TableColumn::key('bank_name')->text(),
            TableColumn::key('iban')->text(),
            TableColumn::key('active')
                ->booleanBadge()
                ->badgeOn('Attivo', 'bi-check-circle', 'success')
                ->badgeOff('Non attivo', 'bi-dash-circle', 'secondary')
                ->size('little'),
            TableColumn::key('actions')->button()->actions(['edit', 'delete']),
        ];
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()
            ->disable(['view'])
            ->titles([
                'list' => 'Conti di pagamento',
                'create' => 'Nuovo conto',
                'edit' => 'Modifica conto',
            ]);
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->backendCrud(['admin']);
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->enabled(false);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->inSection('set-up')
            ->group('pagamenti', 'Pagamenti', 60, ['admin'])
            ->title('Conti di pagamento')
            ->order(20)
            ->authority(['admin']);
    }

    /** L'IBAN si salva come lo si confronta: senza spazi e in maiuscolo. */
    public static function mutateRequestValues(
        array $values,
        string $action,
        string $context = 'backend',
        ?array $oldValues = null
    ): array {
        if (array_key_exists('iban', $values)) {
            $values['iban'] = static::normalizeIban((string) $values['iban']);
        }

        return $values;
    }

    /**
     * L'IBAN compatto e in maiuscolo. Vuoto è ammesso: un conto può nascere
     * senza. La regola è minima — da 15 a 34 fra lettere e cifre — perché il
     * controllo vero lo fa la banca; qui si ferma il refuso evidente.
     */
    public static function normalizeIban(string $iban): string
    {
        $iban = strtoupper(preg_replace('/\s+/', '', $iban) ?? '');

        if ($iban === '') {
            return '';
        }

        if (preg_match('/^[A-Z0-9]{15,34}$/', $iban) !== 1) {
            throw UserError::make('payment_account.invalid_iban');
        }

        return $iban;
    }

    /** Un conto con dei metodi collegati non si elimina: si spegne. */
    public static function assertDeletable(int|string $id): void
    {
        $metodi = (int) sqlCount(PaymentMethod::$table, 'payment_account_id = '.(int) $id." AND deleted = 'false'");

        if ($metodi > 0) {
            throw UserError::refusal('payment_account.in_use', ['count' => $metodi]);
        }
    }
}
