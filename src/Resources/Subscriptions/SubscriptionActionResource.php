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
use Wonder\Plugin\Gestionale\Models\Subscriptions\SubscriptionItem;
use Wonder\Plugin\Gestionale\Support\Documents\DocumentFiles;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;
use Wonder\Plugin\Gestionale\Support\Subscriptions\OfficeSubscriptions;
use Wonder\Plugin\Gestionale\Support\Subscriptions\OnlineSubscriptions;
use Wonder\Plugin\Gestionale\Support\Subscriptions\SubscriptionItems;
use Wonder\Plugin\Gestionale\Support\Subscriptions\SubscriptionNotifier;

/**
 * Le azioni sull'abbonamento dalla scheda (G10 §2.4 e §3, G10b §4): i servizi
 * (*Aggiungi*, *Cambia*, *Togli*, *Correggi personalizzazioni*) e *Disdici*,
 * *Annulla* per quelli da ufficio, *Rimanda il proforma*.
 * Riceve il POST delle finestre della scheda, passa da `OnlineSubscriptions`
 * o `OfficeSubscriptions` secondo il canale e rimanda alla scheda con una
 * frase. Un rifiuto è una frase, mai una pagina d'errore.
 */
final class SubscriptionActionResource extends NavigationOnlyResource
{
    public const ACTIONS = ['add_item', 'change_item', 'remove_item', 'customize_item', 'cancel', 'terminate', 'proforma'];

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
     * @param array<string, mixed> $values action, subscription_id, item_id, plan_price_id, billing, quantity, parent_item_id, customization
     * @return array{ok: bool, message: string}
     */
    public static function run(string $action, int $subscriptionId, array $values, int $userId): array
    {
        $row = $subscriptionId > 0 ? Subscription::findById($subscriptionId) : null;

        if (!Gestionale::feature('subscriptions') || !is_array($row) || (int) ($row['id'] ?? 0) <= 0) {
            return ['ok' => false, 'message' => 'Abbonamento non trovato.'];
        }

        $office = (string) $row['channel'] === 'office';
        $itemId = (int) ($values['item_id'] ?? 0);
        $billing = (string) ($values['billing'] ?? '');
        $customization = is_array($values['customization'] ?? null) ? $values['customization'] : [];

        // Le finestre della scheda mandano `customization_<id>`, come il form d'ufficio.
        foreach ($values as $key => $value) {
            if (preg_match('/^customization_(\d+)$/', (string) $key, $match) === 1 && is_scalar($value) && trim((string) $value) !== '') {
                $customization[(int) $match[1]] = trim((string) $value);
            }
        }

        if (str_ends_with($action, '_item') && !$office) {
            return ['ok' => false, 'message' => 'I servizi di un abbonamento online si cambiano da Stripe: arriva con il prossimo aggiornamento.'];
        }

        if ($itemId > 0 && in_array($action, ['change_item', 'remove_item', 'customize_item'], true)
            && (int) (SubscriptionItem::findById($itemId)['subscription_id'] ?? 0) !== $subscriptionId) {
            return ['ok' => false, 'message' => 'Servizio non trovato.'];
        }

        try {
            switch ($action) {
                case 'add_item':
                    if (!in_array($billing, SubscriptionItems::ADD_BILLINGS, true)) {
                        return ['ok' => false, 'message' => 'Scegli come si paga il servizio aggiunto.'];
                    }

                    SubscriptionItems::add($subscriptionId, [
                        'plan_price_id' => (int) ($values['plan_price_id'] ?? 0),
                        'quantity' => max(1, (int) ($values['quantity'] ?? 1)),
                        'customization' => $customization,
                        'parent_item_id' => (int) ($values['parent_item_id'] ?? 0),
                    ], $billing, $userId);

                    return ['ok' => true, 'message' => match ($billing) {
                        'prorate' => 'Servizio aggiunto: la quota fino al suo rinnovo è una rata, col proforma al cliente.',
                        'free' => 'Servizio aggiunto senza addebiti fino al suo rinnovo.',
                        default => 'Servizio aggiunto: parte con il prossimo periodo.',
                    }];

                case 'change_item':
                    if (!in_array($billing, SubscriptionItems::ADD_BILLINGS, true)) {
                        return ['ok' => false, 'message' => 'Scegli quando vale il prezzo nuovo.'];
                    }

                    SubscriptionItems::change($itemId, (int) ($values['plan_price_id'] ?? 0), $billing, $customization, $userId);

                    return ['ok' => true, 'message' => $billing === 'next_period'
                        ? 'Cambio registrato: il prezzo nuovo vale dal prossimo periodo del servizio.'
                        : 'Servizio cambiato.'];

                case 'remove_item':
                    if (!in_array($billing, SubscriptionItems::REMOVE_BILLINGS, true)) {
                        return ['ok' => false, 'message' => 'Scegli quando finisce il servizio.'];
                    }

                    $removed = SubscriptionItems::remove($itemId, $billing, $userId);

                    return ['ok' => true, 'message' => $billing === 'credit'
                        ? 'Servizio tolto: la parte non goduta resta come credito.'
                        : 'Servizio tolto: resta attivo fino al '.OrderSheet::date($removed['ends_at']).'.'];

                case 'customize_item':
                    SubscriptionItems::customize($itemId, $customization, $userId);

                    return ['ok' => true, 'message' => 'Personalizzazioni corrette: il sovrapprezzo nuovo vale dal prossimo periodo.'];

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
