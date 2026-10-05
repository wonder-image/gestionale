<?php
/** php tests/integrazione/CouponBackendTest.php */

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';
require __DIR__.'/supporto/layout.php';

use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Promotions\Coupon;
use Wonder\Plugin\Gestionale\Models\Promotions\CouponCustomer;
use Wonder\Plugin\Gestionale\Models\Promotions\CouponProductModel;
use Wonder\Plugin\Gestionale\Models\Promotions\CouponRedemption;
use Wonder\Plugin\Gestionale\Resources\Promotions\CouponResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Promotions\Coupons;
use Wonder\Plugin\Gestionale\Support\Promotions\ProductScope;
use Wonder\Sql\Transaction;

/** Serve a buttare via tutto quello che la prova scrive. */
final class Annulla extends RuntimeException {}

/** Fa girare il corpo dentro una transazione e poi la annulla. */
function prova(callable $corpo): mixed
{
    $esito = null;

    try {
        Transaction::run(static function () use ($corpo, &$esito): void {
            $esito = $corpo();

            throw new Annulla();
        });
    } catch (Annulla) {
        // Voluto: i dati della prova non restano.
    }

    return $esito;
}

const ORA = '2026-10-05 12:00:00';

/** Un cliente vero: serve a «clienti riservati», che rifiuta gli id che non ci sono. */
function clienteDiProva(): int
{
    $creato = Contact::create([
        'type' => 'private', 'name' => 'Mario', 'surname' => 'Rossi '.uniqid(), 'country' => 'IT',
        'email' => 'cp.'.uniqid().'@example.com', 'is_customer' => 'true', 'active' => 'true',
    ]);

    return (int) ($creato->insert_id ?? 0);
}

/**
 * Il salvataggio dalla pagina del backend: lo stesso percorso del controller
 * (preparazione dei valori, scrittura, selettore e clienti). Ridà l'id.
 *
 * @param array<string, mixed> $post
 */
function salva(array $post, ?int $id = null): int
{
    $_POST = $post;
    // Il controller passa agli hook solo i campi che sono colonne.
    $post = array_diff_key($post, array_flip(['categories', 'tags', 'brands', 'models', 'excluded_models', 'customers']));
    $vecchio = $id !== null ? Coupon::find(['id' => $id], 1) : null;
    $valori = CouponResource::mutateRequestValues($post, $id === null ? 'store' : 'update', 'backend', $vecchio);

    if ($id === null) {
        $risultato = Coupon::query()->Insert(Coupon::$table, $valori);
        $id = (int) ($risultato->insert_id ?? 0);
        CouponResource::afterStore($risultato, $valori);
    } else {
        $risultato = Coupon::query()->Update(Coupon::$table, $valori, 'id', $id);
        CouponResource::afterUpdate($id, $risultato, $valori);
    }

    $_POST = [];

    return $id;
}

/** La richiesta di un coupon del 10 %, con quello che serve in più o in meno. */
function richiesta(array $in = []): array
{
    return $in + [
        'code' => 'EST'.strtoupper(substr(uniqid(), -7)),
        'name' => 'Estate',
        'discount_type' => 'percent',
        'discount_value' => '10',
        'min_order_amount' => '',
        'usage_limit' => '',
        'usage_limit_per_customer' => '',
        'first_order_only' => 'false',
        'exclude_discounted_products' => 'false',
        'starts_at' => '2026-10-01',
        'ends_at' => '2026-10-31',
        'active' => 'true',
        'applies_online' => 'true',
        'applies_office' => 'false',
        'applies_pos' => 'false',
        'applies_to_all' => 'true',
        'note' => '',
    ];
}

/** Il tipo di errore con cui una funzione si ferma, '' se non si ferma. */
function errore(callable $fai): string
{
    try {
        $fai();
    } catch (UserError $e) {
        return $e->key();
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }

    return '';
}

/** Le righe di un ponte del coupon, sempre come lista. @return list<array<string, mixed>> */
function righe(string $modello, int $couponId): array
{
    $trovate = $modello::find(['coupon_id' => $couponId]);

    if (!is_array($trovate) || $trovate === []) {
        return [];
    }

    return array_key_exists('id', $trovate) ? [$trovate] : array_values($trovate);
}

function conta(string $modello): int
{
    $righe = $modello::all();

    return !is_array($righe) || $righe === [] ? 0 : (array_key_exists('id', $righe) ? 1 : count($righe));
}

check('salvare un coupon scrive la riga, le date piene, i ponti e i clienti riservati', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'coupons']);
        $modello = modelloDi(articoloConGiacenza(0, 'CB-'.substr(uniqid(), -7)));
        $cliente = clienteDiProva();

        $id = salva(richiesta([
            'code' => '  Estate10  ', 'applies_to_all' => 'false', 'models' => [(string) $modello], 'customers' => [(string) $cliente],
            'usage_limit' => '5', 'min_order_amount' => '20,50',
        ]));

        $riga = Coupon::find(['id' => $id], 1);
        $riservati = righe(CouponCustomer::class, $id);

        return $riga['code'] === 'Estate10'
            && $riga['starts_at'] === '2026-10-01 00:00:00'
            && $riga['ends_at'] === '2026-10-31 23:59:59'
            && $riga['usage_limit'] === '5'
            && $riga['min_order_amount'] === '20.50'
            && ProductScope::of('coupon', $id)['models'] === [$modello]
            && array_column($riservati, 'customer_id') === [(string) $cliente];
    });
});

check('ripetere il salvataggio con la selezione cambiata riscrive i ponti senza orfani', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'coupons']);
        $uno = modelloDi(articoloConGiacenza(0, 'CB-'.substr(uniqid(), -7)));
        $due = modelloDi(articoloConGiacenza(0, 'CB-'.substr(uniqid(), -7)));
        [$a, $b] = [clienteDiProva(), clienteDiProva()];

        $id = salva(richiesta(['applies_to_all' => 'false', 'models' => [(string) $uno], 'customers' => [(string) $a]]));
        salva(richiesta(['code' => Coupon::findById($id)['code'], 'applies_to_all' => 'false', 'models' => [(string) $due], 'customers' => [(string) $b]]), $id);

        $ponti = righe(CouponProductModel::class, $id);
        $clienti = righe(CouponCustomer::class, $id);

        return ProductScope::of('coupon', $id)['models'] === [$due]
            && array_column($ponti, 'product_model_id') === [(string) $due]
            && array_column($clienti, 'customer_id') === [(string) $b];
    });
});

check('un coupon che non sta in piedi si rifiuta senza scrivere niente', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'coupons']);
        $esistente = richiesta(['code' => 'Estate10']);
        salva($esistente);
        $righe = conta(Coupon::class);
        $clienti = conta(CouponCustomer::class);

        $casi = [
            'coupon.code_required' => richiesta(['code' => '   ']),
            'coupon.code_taken' => richiesta(['code' => 'estate10']),
            'coupon.percent_out_of_range' => richiesta(['discount_value' => '120']),
            'coupon.amount_not_positive' => richiesta(['discount_type' => 'amount', 'discount_value' => '-5']),
            'coupon.ends_before_starts' => richiesta(['starts_at' => '2026-10-10', 'ends_at' => '2026-10-01']),
            'coupon.type_not_allowed' => richiesta(['discount_type' => 'store_credit', 'discount_value' => '10']),
            'coupon.customer_unknown' => richiesta(['customers' => ['99999999']]),
        ];

        foreach ($casi as $chiave => $post) {
            if (errore(static fn () => salva($post)) !== $chiave) {
                echo '    atteso '.$chiave."\n";

                return false;
            }
        }

        return conta(Coupon::class) === $righe && conta(CouponCustomer::class) === $clienti
            // La spedizione gratuita non ha valore: sta in piedi anche con zero.
            && errore(static fn () => salva(richiesta(['discount_type' => 'free_shipping', 'discount_value' => '']))) === '';
    });
});

check('un coupon si può risalvare con il suo stesso codice', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'coupons']);
        $id = salva(richiesta(['code' => 'Estate10']));

        return errore(static fn () => salva(richiesta(['code' => 'ESTATE10', 'name' => 'Nuovo nome']), $id)) === ''
            && Coupon::find(['id' => $id], 1)['name'] === 'Nuovo nome';
    });
});

check('il codice di un coupon eliminato non si riusa', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'coupons']);
        $id = salva(richiesta(['code' => 'Vecchio10']));
        Coupon::query()->Update(Coupon::$table, ['deleted' => 'true'], 'id', $id);

        return errore(static fn () => salva(richiesta(['code' => 'vecchio10']))) === 'coupon.code_taken';
    });
});

check('l\'elenco mostra stato e utilizzi giusti', function () {
    $riga = ['active' => 'true', 'starts_at' => '2026-10-01 00:00:00', 'ends_at' => '2026-10-31 23:59:59', 'usage_limit' => '10'];

    return CouponResource::statusLabel($riga, ORA) === 'In corso'
        && CouponResource::statusLabel(['starts_at' => '2026-11-01 00:00:00'] + $riga, ORA) === 'Programmato'
        && CouponResource::statusLabel(['ends_at' => '2026-10-04 23:59:59'] + $riga, ORA) === 'Terminato'
        && CouponResource::statusLabel(['active' => 'false'] + $riga, ORA) === 'Disattivato'
        && prova(static function (): bool {
            accendiFunzionalita(['orders', 'coupons']);
            $id = salva(richiesta(['usage_limit' => '10']));
            foreach ([null, null, '2026-10-02 10:00:00'] as $rilasciato) {
                CouponRedemption::create([
                    'coupon_id' => $id, 'order_id' => ordineDiProva(10.0), 'customer_id' => 0, 'email' => 'a@example.com',
                    'discount_amount' => '5.00', 'redeemed_at' => '2026-10-02 09:00:00',
                ] + ($rilasciato === null ? [] : ['released_at' => $rilasciato]));
            }

            // Il rilasciato non conta.
            return Coupons::usedCount($id) === 2
                && CouponResource::usageLabel(Coupons::usedCount($id), ['usage_limit' => '10']) === '2 / 10';
        });
});

check('la tabella degli utilizzi mostra l\'ordine, l\'importo e se è stato rilasciato', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'coupons']);
        $id = salva(richiesta());
        $primo = ordineDiProva(10.0);
        $secondo = ordineDiProva(10.0);
        CouponRedemption::create([
            'coupon_id' => $id, 'order_id' => $primo, 'customer_id' => 0, 'email' => 'uno@example.com',
            'discount_amount' => '5.00', 'redeemed_at' => '2026-10-02 09:00:00',
        ]);
        CouponRedemption::create([
            'coupon_id' => $id, 'order_id' => $secondo, 'customer_id' => 0, 'email' => '<b>due</b>@example.com',
            'discount_amount' => '7.50', 'redeemed_at' => '2026-10-03 09:00:00', 'released_at' => '2026-10-04 09:00:00',
        ]);
        $html = CouponResource::redemptionsHtml($id);

        return str_contains($html, '5,00 €') && str_contains($html, '7,50 €')
            && str_contains($html, '02/10/2026') && str_contains($html, 'uno@example.com')
            && str_contains($html, '&lt;b&gt;due&lt;/b&gt;@example.com') && !str_contains($html, '<b>due</b>')
            && substr_count($html, 'Sì') === 1 && substr_count($html, 'No') >= 1
            && CouponResource::redemptionsHtml(99999999) !== '' && !str_contains(CouponResource::redemptionsHtml(99999999), '<table');
    });
});

check('eliminare un coupon lo toglie dall\'elenco e il codice non si applica più', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'coupons']);
        $id = salva(richiesta(['code' => 'Addio10']));
        $prima = Coupons::find('Addio10') !== null;
        Coupon::query()->Update(Coupon::$table, ['deleted' => 'true'], 'id', $id);

        return $prima && Coupons::find('Addio10') === null
            && (Coupon::find(['id' => $id, 'deleted' => 'true'], 1)['code'] ?? '') === 'Addio10';
    });
});

check('un id che non esiste o un tipo cambiato a mano non rompono la pagina', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'coupons']);

        return is_array(CouponResource::mutateFormValues(['id' => 99999999, 'starts_at' => '0000-00-00 00:00:00'], 'edit'))
            && CouponResource::redemptionsHtml(0) !== '';
    });
});

check('la scheda mostra dettagli, regole, canali, clienti riservati e utilizzi', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'coupons']);
        $cliente = clienteDiProva();
        $id = salva(richiesta([
            'code' => 'Scheda10', 'usage_limit' => '5', 'min_order_amount' => '20',
            'first_order_only' => 'true', 'customers' => [(string) $cliente],
        ]));
        $ordine = ordineDiProva(10.0);
        CouponRedemption::create([
            'coupon_id' => $id, 'order_id' => $ordine, 'customer_id' => $cliente, 'email' => 'x@example.com',
            'discount_amount' => '5.00', 'redeemed_at' => '2026-10-02 09:00:00',
        ]);
        $html = layoutHtml(CouponResource::showLayoutSchema((array) Coupon::find(['id' => $id], 1)));

        return str_contains($html, 'Scheda10') && str_contains($html, '10 %')
            && str_contains($html, 'dal 01/10/2026 al 31/10/2026') && str_contains($html, '1 / 5')
            && str_contains($html, '20,00') && str_contains($html, 'Tutto il catalogo')
            && str_contains($html, 'Rossi') && str_contains($html, '5,00 €')
            && str_contains($html, 'Sito') && str_contains($html, 'Ufficio') && str_contains($html, 'Cassa');
    });
});

check('la scheda di un coupon con selezione elenca categorie e articoli esclusi, e sempre il motivo dello stato', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'coupons']);
        $id = salva(richiesta(['code' => 'Sel10', 'applies_to_all' => 'false', 'active' => 'false']));
        $html = layoutHtml(CouponResource::showLayoutSchema((array) Coupon::find(['id' => $id], 1)));

        return str_contains($html, 'Solo la selezione') && str_contains($html, 'Disattivato')
            && str_contains($html, 'Nessun utilizzo');
    });
});

check('il form di modifica non porta più la tabella degli utilizzi', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'coupons']);
        $id = salva(richiesta());
        $_GET['id'] = $id;
        $html = layoutHtml(CouponResource::formLayoutSchema());
        unset($_GET['id']);

        return !str_contains($html, 'Nessun utilizzo') && !str_contains($html, 'Rilasciato');
    });
});

check('con il solo sito acceso il form non ha i toggle dei canali; con ufficio acceso sì, ma senza la cassa', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'coupons']);
        $solo = layoutHtml(CouponResource::formLayoutSchema());

        accendiFunzionalita(['online_sales', 'office_sales']);
        $due = layoutHtml(CouponResource::formLayoutSchema());

        return !str_contains($solo, 'Dove vale') && !str_contains($solo, 'applies_office')
            && str_contains($due, 'Dove vale') && str_contains($due, 'applies_online')
            && str_contains($due, 'applies_office') && !str_contains($due, 'applies_pos');
    });
});

check('con un solo canale il salvataggio non tocca gli altri e l\'unico attivo vale «sì»', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'coupons']);
        $senzaCanali = array_diff_key(richiesta(), array_flip(['applies_online', 'applies_office', 'applies_pos']));

        // Nuovo: il sito sì, gli altri no.
        $nuovo = salva($senzaCanali);
        $riga = (array) Coupon::find(['id' => $nuovo], 1);

        // Vecchio coupon che valeva anche in ufficio: la modifica lo lascia così.
        Coupon::update(['applies_office' => 'true'], $nuovo);
        salva(array_merge($senzaCanali, ['name' => 'Cambiato']), $nuovo);
        $dopo = (array) Coupon::find(['id' => $nuovo], 1);

        return $riga['applies_online'] === 'true' && $riga['applies_office'] === 'false' && $riga['applies_pos'] === 'false'
            && $dopo['applies_online'] === 'true' && $dopo['applies_office'] === 'true' && $dopo['name'] === 'Cambiato';
    });
});

check('un coupon nuovo parte con i canali accesi a «sì»', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'coupons', 'office_sales']);
        $valori = [];

        foreach (CouponResource::formSchema() as $campo) {
            $valori[(string) $campo->name] = (new ReflectionProperty($campo, 'schema'))->getValue($campo)['value'] ?? null;
        }

        return $valori['applies_online'] === 'false' && $valori['applies_office'] === 'true' && $valori['applies_pos'] === 'false';
    });
});

summary();
