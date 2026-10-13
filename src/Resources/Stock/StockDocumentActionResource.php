<?php

namespace Wonder\Plugin\Gestionale\Resources\Stock;

use Throwable;
use Wonder\App\LegacyGlobals;
use Wonder\App\Resources\Support\NavigationOnlyResource;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\Http\Csrf;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocument;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Stock\StockDocumentForm;
use Wonder\Plugin\Gestionale\Support\Stock\StockDocuments;

/**
 * *Annulla* e *Duplica* dalla scheda di un documento di magazzino (G2b-bis 4.2),
 * e *Ricevi* del trasferimento (G3.16).
 *
 * Non ha una pagina sua: riceve il POST dei form nascosti della scheda e
 * della bozza, fa passare l'azione da `StockDocuments` e torna alla pagina
 * giusta con una frase. Un rifiuto è una frase, mai una pagina d'errore.
 */
final class StockDocumentActionResource extends NavigationOnlyResource
{
    public const CANCEL = 'annulla';
    public const DUPLICATE = 'duplica';
    public const RECEIVE = 'ricevi';

    public static function path(): string
    {
        return 'app/gestionale/documento-magazzino-azione';
    }

    public static function icon(): string
    {
        return 'bi-file-earmark-text';
    }

    public static function titleLabel(): string
    {
        return 'Azione sul documento di magazzino';
    }

    public static function isFormPage(): bool
    {
        return true;
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()->only([])->titles(['form' => 'Azione sul documento di magazzino']);
    }

    /** Le pagine-form leggono `edit` (apertura) e `update` (invio): come Carichi e Scarichi. */
    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->backend(['edit', 'update'], ['admin', 'administrator']);
    }

    public static function navigationSchema(): NavigationSchema
    {
        // Fuori dal menu: ci si arriva dai bottoni della scheda.
        return NavigationSchema::for(static::class)
            ->inSection('magazzino')
            ->title('Azione sul documento di magazzino')
            ->authority(['admin', 'administrator'])
            ->enabled(false);
    }

    /** Dove postano i form nascosti: la rotta di questa pagina, in POST. */
    public static function submitUrl(): string
    {
        $base = '/backend/'.static::path();

        if (function_exists('__r')) {
            try {
                $named = (string) __r('backend.resource.'.static::slug().'.form');
                $base = $named !== '' ? $named : $base;
            } catch (Throwable) {
                // Rotta non registrata (test, comandi): resta il percorso.
            }
        }

        return $base;
    }

    /**
     * Esegue l'azione e dice com'è andata, con la pagina dove tornare.
     *
     * @return array{ok: bool, message: string, url: string}
     */
    public static function run(string $action, int $documentId, int $userId): array
    {
        $document = static::document($documentId);
        $class = $document === null ? null : StockDocumentResource::classFor((string) $document['type']);

        if ($document === null || $class === null) {
            return ['ok' => false, 'message' => 'Documento non trovato.', 'url' => StockMovementResource::listUrl()];
        }

        try {
            if ($action === self::CANCEL) {
                StockDocuments::cancel($documentId, ['user_id' => $userId, 'source' => 'user']);

                return ['ok' => true, 'message' => $class::named().' '.(string) $document['number'].' è annullato.', 'url' => $class::viewUrl($documentId)];
            }

            if ($action === self::RECEIVE) {
                StockDocuments::receive($documentId, ['user_id' => $userId, 'source' => 'user']);

                return ['ok' => true, 'message' => $class::named().' '.(string) $document['number'].' è arrivato.', 'url' => $class::viewUrl($documentId)];
            }

            if ($action === self::DUPLICATE) {
                $copy = StockDocuments::duplicate($documentId, ['user_id' => $userId]);

                return ['ok' => true, 'message' => 'Copia creata: questa è una bozza nuova.', 'url' => $class::editUrl($copy)];
            }

            return ['ok' => false, 'message' => 'Azione non riconosciuta.', 'url' => $class::pageUrl($document)];
        } catch (UserError $error) {
            $fresh = static::document($documentId);

            return ['ok' => false, 'message' => $error->getMessage(), 'url' => $fresh === null ? $class::listUrl() : $class::pageUrl($fresh)];
        }
    }

    /**
     * Riceve il POST: `action`, `document_id`. Il CSRF si controlla qui: il
     * core non lo fa per le pagine-form.
     */
    public static function submitFormPage(array $values): string
    {
        $documentId = (int) ($values['document_id'] ?? 0);

        if (Csrf::verify()) {
            $result = static::run((string) ($values['action'] ?? ''), $documentId, static::currentUserId());
        } else {
            $document = static::document($documentId);
            $class = $document === null ? null : StockDocumentResource::classFor((string) $document['type']);
            $result = [
                'ok' => false,
                'message' => UserError::make('stock_document.expired')->getMessage(),
                'url' => $document === null || $class === null ? StockMovementResource::listUrl() : $class::pageUrl($document),
            ];
        }

        if (headers_sent()) {
            return $result['message'];
        }

        StockDocumentForm::respond($result);
    }

    /** @return array<string, mixed>|null il documento, se c'è e non è eliminato */
    private static function document(int $id): ?array
    {
        $document = $id > 0 ? StockDocument::findById($id) : null;

        return is_array($document) && $document !== [] && (string) ($document['deleted'] ?? 'false') !== 'true' ? $document : null;
    }

    private static function currentUserId(): int
    {
        $user = LegacyGlobals::get('USER');

        return is_object($user) ? (int) ($user->id ?? 0) : 0;
    }
}
