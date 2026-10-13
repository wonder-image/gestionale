<?php

namespace Wonder\Plugin\Gestionale\Resources\Sales;

use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\App\ResourceSchema\TableLayoutSchema;
use Wonder\Http\Route;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;
use Wonder\Plugin\Gestionale\Support\Orders\StatusLabels;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeOrderForm;

/**
 * *Vendite → Preventivi* (G9 §3.1): la stessa pagina dell'ordine da ufficio,
 * con la validità, l'invio con il PDF, *Accetta* (il preventivo diventa una
 * bozza d'ordine) e *Rifiuta*. Le revisioni inviate, con il loro PDF, sotto.
 *
 * Il servizio è lo stesso (`OfficeOrders::save()` con lo `stage` `quote`) più
 * `Quotes`. Un preventivo non tocca mai il magazzino.
 */
final class QuoteResource extends OfficeOrderResource
{
    public static string $feature = 'quotes';
    public static string $docsPage = 'vendite/vendite-preventivi';

    public static function path(): string
    {
        return 'app/gestionale/preventivi';
    }

    public static function icon(): string
    {
        return 'bi-file-earmark-text';
    }

    public static function titleLabel(): string
    {
        return 'Preventivi';
    }

    public static function stage(): string
    {
        return 'quote';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'preventivo',
            'plural_label' => 'preventivi',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'i',
            'this' => 'questo',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'quote_number' => 'Numero',
            'customer' => 'Cliente',
            'total' => 'Totale',
            'status' => 'Stato',
            'quote_expires_at' => 'Valido fino al',
            'creation' => 'Creato',
        ];
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('quote_number')
                ->text()
                ->size('little')
                ->link('edit')
                ->formatter(static fn (array $row): string => trim((string) ($row['quote_number'] ?? '')) === ''
                    ? '<span class="text-body-secondary">Bozza</span>'
                    : static::escape((string) $row['quote_number'].((int) ($row['quote_revision'] ?? 0) > 1 ? ' rev. '.(int) $row['quote_revision'] : ''))),
            TableColumn::key('customer')
                ->text()
                ->formatter(static fn (array $row): string => static::escape(OrderResource::customerName($row))),
            TableColumn::key('total')->price()->size('little'),
            TableColumn::key('status')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => StatusLabels::badge('order', (string) ($row['status'] ?? ''))),
            TableColumn::key('quote_expires_at')
                ->text()
                ->size('little')
                ->hiddenDevice('mobile')
                ->formatter(static fn (array $row): string => static::escape(substr(OrderSheet::date((string) ($row['quote_expires_at'] ?? '')), 0, 10))),
            TableColumn::key('actions')->button()->actions(['edit']),
        ];
    }

    public static function tableLayoutSchema(): TableLayoutSchema
    {
        $stati = ['' => 'Tutti'];

        foreach (['draft', 'sent', 'rejected', 'expired'] as $stato) {
            $stati[$stato] = StatusLabels::order($stato)['label'];
        }

        return TableLayoutSchema::for(static::class)
            ->title('Preventivi')
            ->results()
            ->buttonAdd(true, 'Nuovo preventivo')
            ->filterSearch()
            ->searchFields(['quote_number', 'billing_name', 'billing_surname', 'billing_business_name', 'email'])
            ->filterCustom('Stato', 'status', $stati);
    }

    /**
     * I preventivi, in ogni stato: quelli accettati sono ordini e stanno lì.
     *
     * @return array<string, mixed>
     */
    public static function querySchema(): array
    {
        $schema = parent::querySchema();
        $schema['condition'] = "deleted = 'false' AND stage = 'quote'";
        $schema['order'] = ['column' => 'creation', 'direction' => 'DESC'];

        return $schema;
    }

    public static function pageSchema(): PageSchema
    {
        // Quella dell'ordine da ufficio (stessa vista), con le parole del preventivo.
        return parent::pageSchema()
            ->titles(['list' => 'Preventivi', 'create' => 'Nuovo preventivo', 'edit' => 'Preventivo'])
            ->subtitles([
                'list' => 'I preventivi ai clienti: inviati con il PDF, accettati diventano ordini. Non toccano il magazzino.',
                'create' => 'Si salva come bozza; all\'invio prende il numero e il cliente riceve il PDF.',
                'edit' => 'Una modifica dopo l\'invio è una revisione nuova: al prossimo invio il cliente riceve il PDF aggiornato.',
            ]);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->inSection('vendite')
            ->title('Preventivi')
            ->order(11)
            ->authority(['admin', 'administrator'])
            ->enabled(static::featureActive());
    }

    /** Lo stato del preventivo in testa alla pagina. */
    public static function formNotices(array $values, string $mode): array
    {
        $notices = parent::formNotices($values, $mode);
        $status = (string) ($values['status'] ?? '');
        $number = trim((string) ($values['quote_number'] ?? ''));

        $message = match ($status) {
            'sent' => 'Inviato al cliente: revisione '.(int) ($values['quote_revision'] ?? 1).'. Se lo modifichi torna da inviare, e al prossimo invio è la revisione '.((int) ($values['quote_revision'] ?? 1) + 1).'.',
            'rejected' => 'Il cliente l\'ha rifiutato. Modificandolo lo riapri: torna da inviare.',
            'expired' => 'È scaduto senza risposta. Modificandolo lo riapri: torna da inviare, con una validità nuova.',
            'draft' => $number !== '' ? 'Modificato dopo l\'invio: è da inviare di nuovo.' : '',
            default => '',
        };

        if ($message !== '') {
            array_unshift($notices, ['level' => $status === 'sent' ? 'info' : 'warning', 'message' => $message]);
        }

        return $notices;
    }

    /** Un preventivo si apre qui in ogni stato; accettato è un ordine. */
    public static function closedRedirect(array $values): ?string
    {
        if ((string) ($values['stage'] ?? '') === 'quote') {
            return null;
        }

        $id = (int) ($values['id'] ?? 0);

        return (string) ($values['status'] ?? '') === 'draft' ? OfficeOrderResource::editUrl($id) : OrderResource::detailUrl($id);
    }

    public static function handleSubmit(array $post, int $id, int $userId): array
    {
        $result = OfficeOrderForm::submit($id, $post, $userId, 'quote');
        $edit = $result['id'] > 0 ? static::editUrl($result['id']) : static::createUrl();

        if ($result['deleted']) {
            return ['ok' => true, 'message' => 'Preventivo eliminato.', 'url' => static::listUrl()];
        }

        if ($result['error'] !== '') {
            return ['ok' => false, 'message' => $result['error'], 'url' => $edit];
        }

        if ($result['accepted']) {
            return ['ok' => true, 'message' => 'Preventivo accettato: ora è una bozza d\'ordine. Controlla consegna e pagamento, poi confermala.', 'url' => OfficeOrderResource::editUrl($result['id'])];
        }

        if ($result['rejected']) {
            return ['ok' => true, 'message' => 'Preventivo segnato come rifiutato.', 'url' => $edit];
        }

        if ($result['sent'] !== '') {
            $email = match ($result['email']) {
                'sent' => ' Il cliente ha ricevuto l\'email con il PDF.',
                'no_recipients' => ' Il cliente non ha un\'email: scarica il PDF qui sotto e mandalo tu.',
                'failed' => ' L\'email non è partita: scarica il PDF qui sotto e mandalo tu.',
                'skipped' => ' Nessuna email: scarica il PDF qui sotto.',
                default => '',
            };

            return ['ok' => true, 'message' => 'Preventivo '.$result['sent'].' inviato.'.$email, 'url' => $edit];
        }

        return ['ok' => true, 'message' => 'Bozza salvata.', 'url' => $edit];
    }

    public static function listUrl(): string
    {
        return static::routeUrl('list', '/backend/'.static::path().'/');
    }

    /** Il PDF di una revisione inviata. */
    public static function revisionPdfUrl(int $id, int $revision): string
    {
        return static::routeUrl('pdf', '/backend/'.static::path().'/'.$id.'/pdf/'.$revision.'/', ['id' => $id, 'revision' => $revision]);
    }

    /** *PDF* di ogni revisione (G9 §3.2). */
    public static function registerBackendRoutes(string $rootApp, string $slug): void
    {
        Route::get('/{id}/pdf/{revision}/', Gestionale::handlerPath('backend/sales/quote-pdf.php'), [
            'resource' => $slug,
            'resource_action' => 'pdf',
        ])->name('pdf')
            ->permit(['admin', 'administrator'])
            ->where('id', '[0-9]+')
            ->where('revision', '[0-9]+');
    }
}
