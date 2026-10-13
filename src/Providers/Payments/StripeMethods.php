<?php

namespace Wonder\Plugin\Gestionale\Providers\Payments;

use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;

/**
 * Le scelte che un solo metodo Stripe offre nel checkout: la carta, i wallet
 * e una scelta per ogni altro metodo acceso nel conto (§11b). I wallet stanno
 * anche nella barra rapida (§11), che però non serve la fattura dell'ospite né
 * il ritiro. Non si chiama Stripe: si lavora sui tipi già letti.
 */
final class StripeMethods
{
    /** Vanno nella barra rapida e, dopo la carta, fra le scelte del modulo. */
    private const WALLETS = ['apple_pay', 'google_pay', 'link'];

    /** Si pagano dopo, con un riferimento o delle istruzioni: al ritorno l'intento è ancora aperto e il checkout lo annullerebbe. */
    private const DELAYED = ['multibanco', 'customer_balance', 'oxxo', 'boleto', 'konbini'];

    /** Le icone che la carta mostra: solo i circuiti, gli altri metodi hanno la loro voce. */
    private const CARD_ICONS = ['visa', 'master', 'maestro', 'american_express', 'diners_club', 'discover', 'jcb', 'unionpay'];

    private const NAMES = [
        'affirm' => 'Affirm',
        'afterpay_clearpay' => 'Clearpay',
        'alipay' => 'Alipay',
        'amazon_pay' => 'Amazon Pay',
        'apple_pay' => 'Apple Pay',
        'bancontact' => 'Bancontact',
        'blik' => 'BLIK',
        'eps' => 'EPS',
        'google_pay' => 'Google Pay',
        'ideal' => 'iDEAL',
        'klarna' => 'Klarna',
        'link' => 'Link',
        'mobilepay' => 'MobilePay',
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
        $others = array_values(array_diff($types, ['card'], self::WALLETS, self::DELAYED));
        sort($others);

        return array_merge(['card'], self::expressChoices($types), $others);
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
            'card' => array_values(array_intersect($rowIcons, self::CARD_ICONS)),
            default => isset(PaymentMethod::ICONS[$choice]) ? [$choice] : ['genericbank'],
        };
    }

    /**
     * I wallet della barra rapida fra i tipi attivi, nell'ordine di WALLETS.
     *
     * @param list<string> $types
     * @return list<string>
     */
    public static function expressChoices(array $types): array
    {
        return array_values(array_intersect(self::WALLETS, $types));
    }

    /** @return list<string> i tipi del PaymentIntent per questa scelta: per i wallet decide Stripe (lista vuota) */
    public static function intentTypes(string $choice): array
    {
        return in_array($choice, self::WALLETS, true) ? [] : [$choice];
    }
}
