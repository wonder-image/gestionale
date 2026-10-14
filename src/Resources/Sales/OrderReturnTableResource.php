<?php

namespace Wonder\Plugin\Gestionale\Resources\Sales;

use Throwable;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\Elements\Components\Modal;
use Wonder\Elements\Components\RichText;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Sales\SalesReturn;
use Wonder\Plugin\Gestionale\Models\Sales\SalesReturnItem;
use Wonder\Plugin\Gestionale\Support\Backend\RowMenu;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;

/** I resi dell'ordine: la tabella c'è solo con la funzionalità «returns». */
final class OrderReturnTableResource extends OrderSectionResource
{
    public static string $model = SalesReturn::class;
    public static string $feature = 'returns';

    public static function path(): string
    {
        return 'app/gestionale/ordine-resi';
    }

    public static function icon(): string
    {
        return 'bi-arrow-return-left';
    }

    public static function titleLabel(): string
    {
        return 'Resi dell\'ordine';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'reso',
            'plural_label' => 'resi',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'i',
            'this' => 'questo',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'number' => 'Numero',
            'status' => 'Stato',
            'requested_at' => 'Data',
            'lines' => 'Righe',
            'actions' => '',
        ];
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('number')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape((string) (($row['number'] ?? '') !== '' ? $row['number'] : ($row['code'] ?? '')))),
            TableColumn::key('status')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(OrderSheet::returnStatus((string) ($row['status'] ?? '')))),
            TableColumn::key('requested_at')
                ->text()
                ->formatter(static fn (array $row): string => static::escape(OrderSheet::date(
                    (string) (($row['requested_at'] ?? '') !== '' ? $row['requested_at'] : ($row['creation'] ?? ''))
                ))),
            TableColumn::key('lines')
                ->text()
                ->formatter(static fn (array $row): string => static::linesOf((int) ($row['id'] ?? 0))),
            TableColumn::key('actions')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::actionsOf($row)),
        ];
    }

    /** «Crema × 2, Sapone × 1»: cosa è tornato in quel reso. */
    private static function linesOf(int $returnId): string
    {
        if ($returnId <= 0) {
            return '';
        }

        try {
            $items = SalesReturnItem::find(['sales_return_id' => $returnId, 'deleted' => 'false']);
            $items = !is_array($items) || $items === [] ? [] : (isset($items['id']) ? [$items] : array_values($items));
        } catch (Throwable) {
            return '';
        }

        $testi = [];

        foreach ($items as $item) {
            $riga = OrderItem::findById((int) ($item['order_item_id'] ?? 0));
            $nome = is_array($riga) ? (string) ($riga['name'] ?? '') : '';
            $pezzi = rtrim(rtrim(number_format((float) ($item['quantity'] ?? 0), 3, ',', ''), '0'), ',');
            $testi[] = static::escape($nome.' × '.$pezzi);
        }

        return implode(', ', $testi);
    }

    /** Le azioni di un reso ricevuto: [azione, parola, variante, frase della conferma]. */
    private const ACTIONS = [
        ['complete', 'Chiudi', 'success', 'Il reso si chiude: la merce rientrata resta in magazzino e il reso non si cambia più.'],
        ['cancel', 'Annulla', 'danger', 'Il reso si annulla: la merce rientrata esce di nuovo dal magazzino.'],
    ];

    /** L'id della finestra di un'azione su un reso. */
    public static function modalId(int $returnId, string $action): string
    {
        return 'wi-reso-'.$action.'-'.$returnId;
    }

    /**
     * Il menu ⋯ di un reso ricevuto: «Chiudi» e «Annulla» aprono la loro
     * finestra di conferma. Per un reso già chiuso o annullato non c'è niente
     * da fare.
     *
     * @param array<string, mixed> $row
     */
    public static function actionsOf(array $row): string
    {
        if ((string) ($row['status'] ?? '') !== 'received') {
            return '';
        }

        $id = (int) ($row['id'] ?? 0);

        return RowMenu::render(array_map(static fn (array $azione): array => [
            'label' => $azione[1],
            'icon' => $azione[0] === 'cancel' ? 'bi bi-x-circle' : 'bi bi-check2-circle',
            'modal' => static::modalId($id, $azione[0]),
            'danger' => $azione[0] === 'cancel',
        ], self::ACTIONS));
    }

    /**
     * Le finestre dei resi ricevuti dell'ordine: postano a «Registra reso»,
     * che è l'unico a parlare con `Returns`.
     */
    public static function modals(int $orderId, string $back): string
    {
        if ($orderId <= 0 || !static::featureActive()) {
            return '';
        }

        $html = '';

        foreach (static::rowsOf(SalesReturn::class, ['order_id' => $orderId, 'status' => 'received'], 'id') as $reso) {
            $id = (int) ($reso['id'] ?? 0);
            $nome = html_entity_decode((string) (($reso['number'] ?? '') !== '' ? $reso['number'] : ($reso['code'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            foreach (self::ACTIONS as [$azione, $parola, $variante, $frase]) {
                $html .= Modal::make(trim($parola.' il reso '.$nome))
                    ->id(static::modalId($id, $azione))
                    ->form(OrderReturnResource::submitUrl(), hidden: [
                        'action' => $azione,
                        'return_id' => $id,
                        'order_id' => $orderId,
                        'back' => $back,
                    ])
                    ->components([RichText::make(static::escape($frase))->class('mb-0')])
                    ->cancel('Indietro')
                    ->submit($parola.' reso', $variante)
                    ->render('bootstrap');
            }
        }

        return $html;
    }

    protected static function emptyText(): string
    {
        return 'Nessun reso su questo ordine.';
    }
}
