<?php
/** php tests/integrazione/OrderEmailsTest.php */

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\System\MerchantSetting;
use Wonder\Plugin\Gestionale\Support\Catalog\Customizations;
use Wonder\Plugin\Gestionale\Support\Mail\Mailer;
use Wonder\Plugin\Gestionale\Support\Orders\OrderEmail;
use Wonder\Plugin\Gestionale\Support\Orders\OrderNotifier;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

function prova(callable $corpo): mixed
{
    $esito = null;

    try {
        Transaction::run(static function () use ($corpo, &$esito): void {
            $esito = $corpo();

            throw new Annulla();
        });
    } catch (Annulla) {
    }

    return $esito;
}

/** Un ordine con una riga, per avere qualcosa da raccontare. */
function ordineConRiga(string $email = 'cliente@example.com'): int
{
    $ordine = ordineDiProva(61.00);
    Order::update(array_filter([
        'order_number' => date('Y').'/09'.substr((string) microtime(true), -4),
        'email' => $email,
        'ordered_at' => date('Y-m-d H:i:s'),
    ]), $ordine);
    OrderItem::create([
        'order_id' => $ordine,
        'type' => 'product',
        'position' => 1,
        'sku' => 'TST-MAIL',
        'name' => 'Crema da prova',
        'quantity' => '2.000',
        'unit_price' => '25.00',
        'line_total' => '50.00',
    ]);

    return $ordine;
}

check('le sei chiavi hanno tutte un oggetto e un corpo', function () {
    return prova(static function (): bool {
        $ordine = ordineConRiga();
        $riga = Order::findById($ordine);
        $righe = [['name' => 'Crema da prova', 'quantity' => '2.000', 'line_total' => '50.00']];

        foreach (OrderEmail::KEYS as $chiave) {
            $email = OrderEmail::compose($chiave, (array) $riga, $righe);

            if (trim($email['subject']) === '' || trim($email['body']) === '') {
                return false;
            }
        }

        return true;
    });
});

check('l\'email al cliente porta numero, riga e totale', function () {
    return prova(static function (): bool {
        $ordine = ordineConRiga();
        $riga = (array) Order::findById($ordine);
        $email = OrderEmail::compose('received', $riga, [
            ['name' => 'Crema da prova', 'quantity' => '2.000', 'line_total' => '50.00'],
        ]);

        return str_contains($email['subject'], (string) $riga['order_number'])
            && str_contains($email['body'], 'Crema da prova')
            && str_contains($email['body'], '61,00');
    });
});

check('le istruzioni del metodo finiscono nell\'email di ricevuta', function () {
    return prova(static function (): bool {
        $ordine = ordineConRiga();
        $email = OrderEmail::compose('received', (array) Order::findById($ordine), [], [
            'instructions' => 'Bonifico a IT00 X000 0000 0000',
        ]);

        return str_contains($email['body'], 'IT00 X000 0000 0000');
    });
});

check('le istruzioni di pagamento stanno solo nella ricevuta e nel promemoria', function () {
    return prova(static function (): bool {
        $ordine = ordineConRiga();
        $metodo = Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod::create([
            'code' => 'tst_'.uniqid(),
            'name' => 'Bonifico di prova',
            'provider' => 'manual',
            'timing' => 'deferred',
            'active' => 'true',
            'position' => 1,
            'instructions' => 'Bonifico a IT99 ISTRUZIONI',
        ]);
        Order::update(['payment_method_id' => (int) ($metodo->insert_id ?? 0)], $ordine);

        $corpi = [];
        Mailer::useTransport(static function (string $to, string $subject, string $body) use (&$corpi): bool {
            $corpi[] = $body;

            return true;
        });

        try {
            $dentro = [];

            foreach (['received', 'reminder', 'confirmed', 'cancelled'] as $chiave) {
                $corpi = [];
                OrderNotifier::send($chiave, $ordine);
                $dentro[$chiave] = str_contains(implode(' ', $corpi), 'IT99 ISTRUZIONI');
            }
        } finally {
            Mailer::useTransport(null);
        }

        return $dentro === ['received' => true, 'reminder' => true, 'confirmed' => false, 'cancelled' => false];
    });
});

check('la personalizzazione sta sotto il nome in entrambe le email, con la & escapata una volta sola', function () {
    return prova(static function (): bool {
        $ordine = (array) Order::findById(ordineConRiga());
        $righe = [[
            'sku' => 'TST-MAIL', 'name' => 'Crema da prova', 'quantity' => '1.000', 'line_total' => '25.00',
            'customization' => Customizations::encode([['customization_id' => 1, 'label' => 'Incisione', 'value' => 'Marco & Luca', 'option_id' => 0, 'surcharge' => '5.00']]),
        ]];

        foreach (['received', 'merchant_new'] as $chiave) {
            $corpo = OrderEmail::compose($chiave, $ordine, $righe)['body'];

            if (!str_contains($corpo, 'Incisione: Marco &amp; Luca') || str_contains($corpo, '&amp;amp;')) {
                return false;
            }
        }

        $senza = OrderEmail::compose('received', $ordine, [['name' => 'Crema', 'quantity' => '1.000', 'line_total' => '1.00']])['body'];

        return !str_contains($senza, 'text-muted');
    });
});

check('il corpo non si fa scrivere dentro dal nome di un articolo', function () {
    return prova(static function (): bool {
        $ordine = ordineConRiga();
        $email = OrderEmail::compose('received', (array) Order::findById($ordine), [
            ['name' => '<script>alert(1)</script>', 'quantity' => '1.000', 'line_total' => '1.00'],
        ]);

        return !str_contains($email['body'], '<script>');
    });
});

check('l\'email al cliente parte e arriva al suo indirizzo', function () {
    return prova(static function (): bool {
        $partite = [];
        Mailer::useTransport(static function (string $to) use (&$partite): bool {
            $partite[] = $to;

            return true;
        });

        try {
            $esito = OrderNotifier::send('received', ordineConRiga());
        } finally {
            Mailer::useTransport(null);
        }

        return $esito['sent'] === ['cliente@example.com'] && $partite === ['cliente@example.com'];
    });
});

check('l\'email al commerciante va ai destinatari delle notifiche', function () {
    return prova(static function (): bool {
        $riga = MerchantSetting::current();
        MerchantSetting::update(
            ['merchant_notification_emails' => 'uno@example.test, due@example.test'],
            (int) ($riga['id'] ?? 1)
        );

        Mailer::useTransport(static fn (): bool => true);

        try {
            $esito = OrderNotifier::send('merchant_new', ordineConRiga());
        } finally {
            Mailer::useTransport(null);
        }

        return $esito['sent'] === ['uno@example.test', 'due@example.test'];
    });
});

check('senza indirizzo non si manda niente e non si esplode', function () {
    return prova(static function (): bool {
        $ordine = ordineConRiga('');

        return OrderNotifier::send('received', $ordine)['status'] === OrderNotifier::NO_RECIPIENTS;
    });
});

summary();
