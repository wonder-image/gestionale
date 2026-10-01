<?php
/** php tests/OrderNoteResourceTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Resources\Sales\OrderNoteResource;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderResource;

/**
 * Le note interne e quelle sul documento si modificano dalla scheda, con una
 * finestra che posta a una pagina senza menu.
 */
check('la pagina sta sotto vendite, senza menu né pagine, e solo l\'amministratore la usa', function () {
    $permessi = json_encode(OrderNoteResource::permissionSchema()->toArray());

    return OrderNoteResource::path() === 'app/gestionale/ordine-note'
        && OrderNoteResource::isFormPage()
        && empty(OrderNoteResource::navigationSchema()->toArray()['enabled'])
        && str_contains((string) $permessi, 'administrator');
});

check('la finestra ha le due note, già compilate e con il testo escapato', function () {
    $html = OrderNoteResource::modal(
        ['id' => 9, 'order_number' => '2025/001', 'internal_note' => 'Chiamare <b>Rossi</b>', 'document_note' => "Riga 1\nRiga 2"],
        ''
    );

    return str_contains($html, 'id="'.OrderNoteResource::MODAL_ID.'"')
        && str_contains($html, 'name="internal_note"') && str_contains($html, 'name="document_note"')
        && str_contains($html, 'name="order_id" value="9"')
        && str_contains($html, 'Chiamare &lt;b&gt;Rossi&lt;/b&gt;') && !str_contains($html, '<b>Rossi</b>')
        && str_contains($html, "Riga 1\nRiga 2")
        && str_contains($html, '<textarea');
});

check('la finestra porta con sé la strada del ritorno', fn () =>
    str_contains(OrderNoteResource::modal(['id' => 9], '/backend/app/gestionale/ordini/'), 'name="back" value="/backend/app/gestionale/ordini/"')
);

check('la scheda ha il pulsante che apre la finestra, accanto a ciascuna nota', function () {
    $r = new ReflectionMethod(OrderResource::class, 'headerHtml');
    $html = (string) $r->invoke(null, ['id' => 9, 'order_number' => '2025/001', 'internal_note' => 'x'], []);

    return substr_count($html, OrderNoteResource::MODAL_ID) >= 2 && str_contains($html, 'bi-pencil');
});

check('le note si puliscono: a capo uniformi, spazi ai bordi, e una nota troppo lunga si rifiuta', function () {
    $ok = OrderNoteResource::clean("  riga 1\r\nriga 2  ");
    $troppo = OrderNoteResource::clean(str_repeat('a', OrderNoteResource::MAX + 1));

    return $ok === "riga 1\nriga 2" && $troppo === null && OrderNoteResource::clean('') === '';
});

summary();
