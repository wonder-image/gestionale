<?php

namespace Wonder\Plugin\Gestionale\Resources\Sales;

use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\App\ResourceSchema\TableLayoutSchema;
use Wonder\Http\Route;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Support\Backend\RowMenu;
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
                ->formatter(static fn (array $row): string => static::number($row) === ''
                    ? '<span class="text-body-secondary">Bozza</span>'
                    : static::escape(static::number($row).((int) ($row['quote_revision'] ?? 0) > 1 ? ' rev. '.(int) $row['quote_revision'] : ''))),
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
            TableColumn::key('actions')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => RowMenu::render(static::rowActions($row))),
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
            ->searchFields(['order_number', 'quote_number', 'billing_name', 'billing_surname', 'billing_business_name', 'email'])
            ->filterCustom('Stato', 'status', $stati);
    }

    /** Il numero del preventivo: quello dell'ordine; i vecchi preventivi inviati hanno il loro. */
    public static function number(array $row): string
    {
        return trim((string) ($row['quote_number'] ?? '')) ?: trim((string) ($row['order_number'] ?? ''));
    }

    /**
     * Il menu ⋯ di un preventivo nell'elenco. *Invia al cliente* manda
     * subito l'email con il PDF.
     *
     * @param array<string, mixed> $row
     * @return list<array<string, mixed>>
     */
    public static function rowActions(array $row): array
    {
        $id = (int) ($row['id'] ?? 0);
        $status = (string) ($row['status'] ?? '');
        $neverSent = (int) ($row['quote_revision'] ?? 0) === 0;
        $items = [['label' => 'Modifica', 'icon' => 'bi bi-pencil', 'href' => static::editUrl($id)]];

        if (!in_array($status, ['draft', 'sent'], true)) {
            return $items;
        }

        $items[] = ['label' => 'Trasforma in ordine', 'icon' => 'bi bi-arrow-right-circle', 'href' => static::actionUrl($id, 'accept'), 'post' => true,
            'confirm' => 'Il cliente ha accettato: il preventivo diventa una bozza d\'ordine con le stesse righe e lo stesso numero.',
            'confirm_title' => 'Trasforma in ordine', 'confirm_ok' => 'Trasforma in ordine', 'confirm_variant' => 'success'];
        $items[] = ['label' => $status === 'sent' ? 'Invia di nuovo' : 'Invia al cliente', 'icon' => 'bi bi-send', 'href' => static::actionUrl($id, 'send'), 'post' => true,
            'confirm' => $neverSent
                ? 'Si archivia il PDF del preventivo e il cliente lo riceve per email.'
                : 'Se è cambiato diventa una revisione nuova; il cliente riceve per email il PDF.',
            'confirm_title' => 'Invia il preventivo', 'confirm_ok' => 'Invia', 'confirm_variant' => 'primary'];

        if ($status === 'draft' && $neverSent) {
            $items[] = ['label' => 'Elimina', 'icon' => 'bi bi-trash', 'danger' => true, 'href' => static::actionUrl($id, 'delete'), 'post' => true,
                'confirm' => 'Il preventivo non è mai stato inviato: si elimina con le sue righe.',
                'confirm_title' => 'Elimina preventivo', 'confirm_ok' => 'Elimina', 'confirm_variant' => 'danger'];
        }

        return $items;
    }

    public static function listIntents(): array
    {
        return ['accept', 'send', 'delete'];
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
                'create' => 'Al primo salvataggio prende il numero, lo stesso che avrà da ordine; all\'invio il cliente riceve il PDF.',
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
        $sentBefore = (int) ($values['quote_revision'] ?? 0) > 0;

        $message = match ($status) {
            'sent' => 'Inviato al cliente: revisione '.(int) ($values['quote_revision'] ?? 1).'. Se lo modifichi torna da inviare, e al prossimo invio è la revisione '.((int) ($values['quote_revision'] ?? 1) + 1).'.',
            'rejected' => 'Il cliente l\'ha rifiutato. Modificandolo lo riapri: torna da inviare.',
            'expired' => 'È scaduto senza risposta. Modificandolo lo riapri: torna da inviare, con una validità nuova.',
            'draft' => $sentBefore ? 'Modificato dopo l\'invio: è da inviare di nuovo.' : '',
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

    public static function handleSubmit(array $post, int $id, int $userId, bool $save = true): array
    {
        $result = OfficeOrderForm::submit($id, $post, $userId, 'quote', $save);
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

    /** Il PDF di una revisione inviata. */
    public static function revisionPdfUrl(int $id, int $revision): string
    {
        return static::routeUrl('pdf', '/backend/'.static::path().'/'.$id.'/pdf/'.$revision.'/', ['id' => $id, 'revision' => $revision]);
    }

    /** Il preventivo in PDF com'è adesso, da stampare. */
    public static function printUrl(int $id): string
    {
        return static::routeUrl('print', '/backend/'.static::path().'/'.$id.'/stampa/', ['id' => $id]);
    }

    /** *PDF* di ogni revisione (G9 §3.2), *Stampa* e le azioni del menu ⋯. */
    public static function registerBackendRoutes(string $rootApp, string $slug): void
    {
        Route::get('/{id}/stampa/', Gestionale::handlerPath('backend/sales/quote-print.php'), [
            'resource' => $slug,
            'resource_action' => 'print',
        ])->name('print')
            ->permit(['admin', 'administrator'])
            ->where('id', '[0-9]+');

        static::registerActionRoute($slug);

        Route::get('/{id}/pdf/{revision}/', Gestionale::handlerPath('backend/sales/quote-pdf.php'), [
            'resource' => $slug,
            'resource_action' => 'pdf',
        ])->name('pdf')
            ->permit(['admin', 'administrator'])
            ->where('id', '[0-9]+')
            ->where('revision', '[0-9]+');
    }
}
