<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Stores\Controllers;

use VeciAhorra\Modules\Stores\Requests\StoreRequest;
use VeciAhorra\Modules\Stores\Services\StoreService;
use VeciAhorra\Core\Controller;
use VeciAhorra\Core\Flash;

/**
 * Controlador del módulo Minimarkets.
 */
final class StoresController extends Controller
{
    private StoreService $service;

    public function __construct()
    {
        $this->service = new StoreService();

        $this->viewPath = dirname(__DIR__) . '/Views';
    }

    /**
     * Listado de minimarkets.
     */
    public function index(): void
{
    $table = new \VeciAhorra\Admin\Tables\StoresTable();

$table->prepare_items();

$this->render(
    'index',
    [
        'table' => $table
    ]
);
}

    /**
     * Formulario de creación.
     */
    public function create(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    switch ($_POST['action'] ?? '') {

        case 'veciahorra_store_create':
            $this->store();
            return;

        case 'veciahorra_store_update':
            $this->update();
            return;
    }
}

        $this->render('form');
    }

    /**
     * Guarda un nuevo minimarket.
     */
   private function store(): void
{
    $request = new StoreRequest();

    $data = $request->validatedForCreate();

    $this->service->create($data);

    Flash::success(
        'Minimarket creado correctamente.'
    );

    $this->redirect('veciahorra-stores');
}

    /**
 * Formulario de edición.
 */
/**
 * Formulario de edición.
 */
public function edit(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        if (($_POST['action'] ?? '') === 'veciahorra_store_update') {
            $this->update();
            return;
        }
    }

    $id = (int) ($_GET['id'] ?? 0);

    if ($id <= 0) {
        wp_die('ID de minimarket no válido.');
    }

    $store = $this->service->find($id);

    if ($store === null) {
        wp_die('Minimarket no encontrado.');
    }

    $this->render('form', [
        'store' => $store
    ]);
}

/**
 * Actualiza un minimarket.
 */
private function update(): void
{
    $request = new StoreRequest();

    $data = $request->validatedForUpdate();

    $id = (int) ($_POST['id'] ?? 0);

    $this->service->update($id, $data);

    Flash::success(
        'Minimarket actualizado correctamente.'
    );

    $this->redirect('veciahorra-stores');
}

/**
 * Elimina un minimarket.
 */
public function delete(): void
{
    check_admin_referer('veciahorra_delete_store');

    $id = (int) ($_GET['id'] ?? 0);

    if ($id <= 0) {
        wp_die('ID de minimarket no válido.');
    }

    if (! $this->service->delete($id)) {

        Flash::error(
            'No fue posible eliminar el minimarket.'
        );

        $this->redirect('veciahorra-stores');
    }

    Flash::success(
        'Minimarket eliminado correctamente.'
    );

    $this->redirect('veciahorra-stores');
}

}