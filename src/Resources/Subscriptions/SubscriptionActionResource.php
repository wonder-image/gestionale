<?php

namespace Wonder\Plugin\Gestionale\Resources\Subscriptions;

use Throwable;
use Wonder\App\LegacyGlobals;
use Wonder\App\Resources\Support\NavigationOnlyResource;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\Backend\Support\FlashAlert;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Subscription;
use Wonder\Plugin\Gestionale\Support\Documents\DocumentFiles;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;
use Wonder\Plugin\Gestionale\Support\Subscriptions\OfficeSubscriptions;
use Wonder\Plugin\Gestionale\Support\Subscriptions\OnlineSubscriptions;
use Wonder\Plugin\Gestionale\Support\Subscriptions\SubscriptionNotifier;

/**
 * Le azioni sull'abbonamento dalla scheda (G10 §2.4 e §3): *Cambia piano* e
 * *Disdici* per tutti, *Annulla* per quelli da ufficio, *Rimanda il proforma*.
 * Riceve il POST delle finestre della scheda, passa da `OnlineSubscriptions`
 * o `OfficeSubscriptions` secondo il canale e rimanda alla scheda con una
 * frase. Un rifiuto è una frase, mai una pagina d'errore.
 */
final class SubscriptionActionResource extends NavigationOnlyResource
{
    public const ACTIONS = ['change_plan', 'cancel', 'terminate', 'proforma'];

    public static function path(): string
    {
        return 'app/gestionale/abbonamento-azione';
    }

    public static function icon(): string
    {
        return 'bi-check2-circle';
    }

    public static function titleLabel(): string
    {
        return 'Azione sull\'abbonamento';
    }

    public static function isFormPage(): bool
    {
        return true;
    }

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

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()->only([])->titles(['form' => 'Azione sull\'abbonamento']);
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->backend(['edit', 'update'], ['admin', 'administrator']);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->inSection('vendite')
            ->title('Azione sull\'abbonamento')
            ->authority(['admin', 'administrator'])
            ->enabled(false);
    }

    public static function formSchema(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $values action, subscription_id, plan_price_id
     * @return array{ok: bool, message: string}
     */
    public static function run(string $action, int $subscriptionId, array $values, int $userId): array
    {
        $row = $subscriptionId > 0 ? Subscription::findById($subscriptionId) : null;

        if (!Gestionale::feature('subscriptions') || !is_array($row) || (int) ($row['id'] ?? 0) <= 0) {
            return ['ok' => false, 'message' => 'Abbonamento non trovato.'];
        }

        $office = (string) $row['channel'] === 'office';

        try {
            switch ($action) {
                case 'change_plan':
                    $price = (int) ($values['plan_price_id'] ?? 0);
                    $office
                        ? OfficeSubscriptions::changePlan($subscriptionId, $price, $userId)
                        : OnlineSubscriptions::changePlan($subscriptionId, $price, 'user', $userId ?: null);

                    return ['ok' => true, 'message' => $office
                        ? 'Piano cambiato: il nuovo prezzo vale dal prossimo periodo.'
                        : 'Piano cambiato in Stripe: il nuovo prezzo vale dal prossimo rinnovo, senza conguagli.'];

                case 'cancel':
                    $end = $office
                        ? OfficeSubscriptions::cancel($subscriptionId, $userId)
                        : OnlineSubscriptions::cancel($subscriptionId, 'user', $userId ?: null);

                    return ['ok' => true, 'message' => 'Disdetta registrata: l\'abbonamento resta attivo fino al '.OrderSheet::date($end).'.'];

                case 'terminate':
                    if (!$office) {
                        return ['ok' => false, 'message' => 'Un abbonamento online si disdice: lo chiude Stripe a fine periodo.'];
                    }

                    OfficeSubscriptions::terminate($subscriptionId, $userId, trim((string) ($values['reason'] ?? '')));

                    return ['ok' => true, 'message' => 'Abbonamento annullato: le rate in attesa sono annullate.'];

                case 'proforma':
                    if (!$office || trim((string) ($row['current_period_start'] ?? '')) === '') {
                        return ['ok' => false, 'message' => 'Non c\'è un periodo da mandare.'];
                    }

                    $file = OfficeSubscriptions::proforma($row, (string) $row['current_period_start'], (string) $row['current_period_end']);
                    $path = DocumentFiles::path($file);
                    $sent = SubscriptionNotifier::send('proforma', $subscriptionId, $path === null ? [] : [$path => OfficeSubscriptions::proformaName($row)]);

                    return $sent['sent'] !== []
                        ? ['ok' => true, 'message' => 'Proforma rimandato a '.implode(', ', $sent['sent']).'.']
                        : ['ok' => false, 'message' => 'Il proforma non è partito: controlla l\'email del cliente.'];
            }
        } catch (UserError $error) {
            return ['ok' => false, 'message' => $error->getMessage()];
        } catch (Throwable $error) {
            Errors::internal($error, 'subscriptions.action', ['subscription' => $subscriptionId, 'action' => $action]);

            return ['ok' => false, 'message' => 'Qualcosa non ha funzionato: riprova tra qualche minuto.'];
        }

        return ['ok' => false, 'message' => 'Azione sconosciuta.'];
    }

    public static function submitFormPage(array $values): string
    {
        $subscriptionId = (int) ($values['subscription_id'] ?? 0);
        $user = LegacyGlobals::get('USER');
        $result = static::run((string) ($values['action'] ?? ''), $subscriptionId, $values, is_object($user) ? (int) ($user->id ?? 0) : 0);

        if (headers_sent()) {
            return $result['message'];
        }

        if ($result['ok']) {
            FlashAlert::saved($result['message']);
        } else {
            FlashAlert::custom('Attenzione', $result['message'], 'warning');
        }

        header('Location: '.SubscriptionResource::detailUrl($subscriptionId));
        exit();
    }
}
