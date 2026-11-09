<?php

use Wonder\App\Permission\Permission;
use Wonder\App\Permission\Permissions;

// Ruoli operativi del gestionale, oltre a quelli del core: admin (Wonder Image)
// e administrator (commerciante). Cosa vede ogni ruolo lo decidono le aree in
// `permissions` di config/module.php.
Permissions::addPermission(
    Permission::make('sales', 'backend')
        ->name('Vendite')
        ->icon("<i class='bi bi-bag-check'></i>")
        ->bg('bg-success')
        ->tx('text-white')
        ->color('success')
        ->creator(['admin', 'administrator'])
)->addPermission(
    Permission::make('warehouse', 'backend')
        ->name('Magazzino')
        ->icon("<i class='bi bi-box-seam'></i>")
        ->bg('bg-warning')
        ->tx('text-dark')
        ->color('warning')
        ->creator(['admin', 'administrator'])
)->addPermission(
    // Non `sales`: la visibilità per riga nasconderebbe i dati anche all'ufficio (#77).
    Permission::make('sales_agent', 'backend')
        ->name('Commerciale')
        ->icon("<i class='bi bi-person-badge'></i>")
        ->bg('bg-info')
        ->tx('text-dark')
        ->color('info')
        ->creator(['admin', 'administrator'])
);

return Permissions::instance();
