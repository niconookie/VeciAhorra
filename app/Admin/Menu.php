<?php

declare(strict_types=1);

namespace VeciAhorra\Admin;

use VeciAhorra\Modules\Stores\Controllers\StoresController;

/**
 * Menú principal del administrador.
 */
final class Menu
{
    public function register(): void
    {
        add_action(
            'admin_menu',
            [$this, 'buildMenu']
        );

        add_action(
            'admin_head',
            [$this, 'hideSubmenus']
        );
    }
    public function buildMenu(): void
    {
        add_menu_page(
            'VeciAhorra',
            'VeciAhorra',
            'manage_options',
            'veciahorra',
            [$this, 'dashboard'],
            'dashicons-store',
            25
        );

        add_submenu_page(
            'veciahorra',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'veciahorra',
            [$this, 'dashboard']
        );

        add_submenu_page(
            'veciahorra',
            'Minimarkets',
            'Minimarkets',
            'manage_options',
            'veciahorra-stores',
            [$this, 'stores']
        );

        /*
        |--------------------------------------------------------------------------
        | Página oculta para crear un minimarket
        |--------------------------------------------------------------------------
        */
        add_submenu_page(
    'veciahorra',
    'Nuevo Minimarket',
    'Nuevo Minimarket',
    'manage_options',
    'veciahorra-store-create',
    [$this, 'createStore']
);

        add_submenu_page(
    'veciahorra',
    'Editar Minimarket',
    'Editar Minimarket',
    'manage_options',
    'veciahorra-store-edit',
    [$this, 'editStore']
);

add_submenu_page(
    'veciahorra',
    'Eliminar Minimarket',
    'Eliminar Minimarket',
    'manage_options',
    'veciahorra-store-delete',
    [$this, 'deleteStore']
);
    }

    public function dashboard(): void
    {
        echo '<div class="wrap">';
        echo '<h1>Dashboard VeciAhorra</h1>';
        echo '<p>Bienvenido al Marketplace.</p>';
        echo '</div>';
    }

    /**
     * Listado de minimarkets.
     */
    public function stores(): void
    {
        $controller = new StoresController();

        $controller->index();
    }

    /**
     * Formulario de creación.
     */
    public function createStore(): void
    {
        $controller = new StoresController();

        $controller->create();
    }

    /**
 * Formulario de edición.
 */
public function editStore(): void
{
    $controller = new StoresController();

    $controller->edit();
}

/**
 * Elimina un minimarket.
 */
public function deleteStore(): void
{
    $controller = new StoresController();

    $controller->delete();
}

/**
 * Oculta las páginas internas del CRUD.
 */
public function hideSubmenus(): void
{
    remove_submenu_page(
        'veciahorra',
        'veciahorra-store-create'
    );

    remove_submenu_page(
        'veciahorra',
        'veciahorra-store-edit'
    );

    remove_submenu_page(
        'veciahorra',
        'veciahorra-store-delete'
    );
}

}