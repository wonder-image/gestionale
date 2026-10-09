<?php

namespace Wonder\Plugin\Gestionale\Providers\Payments;

use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;

/**
 * Le scelte che un solo metodo Stripe offre nel checkout: la carta e una
 * scelta per ogni altro metodo acceso nel conto (§11b). Apple Pay, Google
 * Pay e Link stanno nella barra rapida (§11), non qui. Non si chiama Stripe: si
 * lavora sui tipi già letti.
 */
final class StripeMethods
{
    /** Vanno nella barra rapida, non fra le scelte del modulo. */
    private const WALLETS = ['apple_pay', 'google_pay', 'link'];

    /** Le icone che la carta non mostra: hanno un altro posto. */
    private const NOT_CARD_ICONS = ['apple_pay', 'google_pay', 'klarna', 'paypal'];

    private const NAMES = [
        'affirm' => 'Affirm',
        'afterpay_clearpay' => 'Clearpay',
        'alipay' => 'Alipay',
        'amazon_pay' => 'Amazon Pay',
        'bancontact' => 'Bancontact',
        'blik' => 'BLIK',
        'eps' => 'EPS',
        'ideal' => 'iDEAL',
        'klarna' => 'Klarna',
        'link' => 'Link',
        'mobilepay' => 'MobilePay',
        'multibanco' => 'Multibanco',
        'p24' => 'Przelewy24',
        'paypal' => 'PayPal',
        'revolut_pay' => 'Revolut Pay',
        'satispay' => 'Satispay',
        'sepa_debit' => 'Addebito SEPA',
        'twint' => 'TWINT',
        'wechat_pay' => 'WeChat Pay',
    ];

    /**
     * @param list<array<string, mixed>> $configurations
     * @return list<string>
     */
    public static function typesFrom(array $configurations): array
    {
        $active = array_values(array_filter($configurations, static fn (array $c): bool => ($c['active'] ?? false) === true));
        $chosen = null;

        foreach ($active as $configuration) {
            if (($configuration['is_default'] ?? false) === true) {
                $chosen = $configuration;
                break;
            }
        }

        $chosen ??= $active[0] ?? [];
        $types = [];

        foreach ($chosen as $key => $value) {
            if (is_array($value) && ($value['available'] ?? null) === true) {
                $types[] = (string) $key;
            }
        }

        return $types;
    }

    /**
     * @param list<string> $types
     * @return list<string>
     */
    public static function choices(array $types): array
    {
        $others = array_values(array_diff($types, ['card'], self::WALLETS));
        sort($others);

        return array_merge(['card'], $others);
    }

    public static function name(string $choice, string $cardName): string
    {
        return match ($choice) {
            'card' => $cardName,
            default => self::NAMES[$choice] ?? ucfirst(str_replace('_', ' ', $choice)),
        };
    }

    /**
     * @param list<string> $rowIcons
     * @return list<string>
     */
    public static function icons(string $choice, array $rowIcons): array
    {
        return match ($choice) {
            'card' => array_values(array_diff($rowIcons, self::NOT_CARD_ICONS)),
            default => isset(PaymentMethod::ICONS[$choice]) ? [$choice] : ['genericbank'],
        };
    }

    /** @return list<string> i tipi del PaymentIntent per questa scelta */
    public static function intentTypes(string $choice): array
    {
        return [$choice];
    }
}
