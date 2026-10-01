<?php

namespace Wonder\Plugin\Gestionale\Support\Orders;

use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\System\MerchantSetting;
use Wonder\Plugin\Gestionale\Support\Mail\Mailer;
use Wonder\Plugin\Gestionale\Support\Mail\Recipients;

/**
 * Manda le email di un ordine a chi le deve ricevere.
 *
 * Un'email che non parte non ferma un ordine: se manca l'indirizzo o la posta
 * rifiuta, chi ha chiamato riceve l'esito e va avanti. Il denaro e la merce
 * sono già a posto — rimandare indietro tutto per una casella piena sarebbe
 * il danno peggiore.
 */
final class OrderNotifier
{
    public const NO_RECIPIENTS = 'no_recipients';
    public const NOT_FOUND = 'not_found';

    /**
     * @param array{instructions?: string, deadline?: string, url?: string} $extra
     * @return array{status: string, to: list<string>, sent: list<string>, failed: list<string>}
     */
    public static function send(string $key, int $orderId, array $extra = []): array
    {
        $order = Order::findById($orderId);

        if (!is_array($order) || $order === []) {
            return ['status' => self::NOT_FOUND, 'to' => [], 'sent' => [], 'failed' => []];
        }

        $to = self::recipients($key, $order);

        if ($to === []) {
            return ['status' => self::NO_RECIPIENTS, 'to' => [], 'sent' => [], 'failed' => []];
        }

        $method = (int) ($order['payment_method_id'] ?? 0) > 0
            ? PaymentMethod::findById((int) $order['payment_method_id'])
            : null;
        $email = OrderEmail::compose($key, $order, self::items($orderId), $extra + [
            'method' => is_array($method) ? (string) ($method['name'] ?? '') : '',
            'instructions' => is_array($method) ? (string) ($method['instructions'] ?? '') : '',
        ]);

        return Mailer::send('order.'.$key, $to, $email['subject'], $email['body']);
    }

    /**
     * @param array<string, mixed> $order
     * @return list<string>
     */
    private static function recipients(string $key, array $order): array
    {
        if (in_array($key, OrderEmail::MERCHANT_KEYS, true)) {
            return Recipients::parse(
                (string) (MerchantSetting::current()['merchant_notification_emails'] ?? '')
            )['valid'];
        }

        return Recipients::parse((string) ($order['email'] ?? ''))['valid'];
    }

    /** @return list<array<string, mixed>> */
    private static function items(int $orderId): array
    {
        $rows = OrderItem::find(['order_id' => $orderId, 'deleted' => 'false']);

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }
}
