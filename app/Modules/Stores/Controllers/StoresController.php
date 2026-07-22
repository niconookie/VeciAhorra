<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Stores\Controllers;

use InvalidArgumentException;
use VeciAhorra\Admin\Tables\StoresTable;
use VeciAhorra\Exceptions\PersistenceException;
use VeciAhorra\Exceptions\RecordNotFoundException;
use VeciAhorra\Modules\Stores\Requests\StoreRequest;
use VeciAhorra\Modules\Stores\Requests\StoreAdminPageRequest;
use VeciAhorra\Modules\Stores\Exceptions\StoreValidationException;
use VeciAhorra\Modules\Stores\Services\StoreService;
use VeciAhorra\Core\Controller;
use VeciAhorra\Core\Flash;
use VeciAhorra\Core\Config;

/**
 * Controlador del módulo Minimarkets.
 */
final class StoresController extends Controller
{
    private ?StoreService $service = null;

    public function __construct()
    {
        $this->viewPath = dirname(__DIR__) . '/Views';
    }

    private function service(): StoreService
    {
        return $this->service ??= new StoreService();
    }

    /**
     * Listado de minimarkets.
     */
    public function index(): void
    {
        $request = StoreAdminPageRequest::fromGlobals();
        if (! $request->isList()) {
            $this->detail($request);
            return;
        }

        $this->render('index', [
            'config' => [
                'restUrl' => esc_url_raw(rest_url('veciahorra/v1')),
                'nonce' => wp_create_nonce('wp_rest'),
                'adminUrl' => esc_url_raw(add_query_arg(
                    ['page' => 'veciahorra-stores'],
                    admin_url('admin.php')
                )),
                'createUrl' => esc_url_raw(add_query_arg(
                    ['page' => 'veciahorra-store-create'],
                    admin_url('admin.php')
                )),
                'editUrl' => esc_url_raw(add_query_arg(
                    ['page' => 'veciahorra-store-edit'],
                    admin_url('admin.php')
                )),
                'version' => Config::PLUGIN_VERSION,
            ],
        ]);
    }

    private function detail(StoreAdminPageRequest $request): void
    {
        $valid = $request->isValidDetail();
        $config = null;

        if ($valid) {
            $id = $request->storeId();
            $config = [
                'enabled' => true,
                'storeId' => $id,
                'detailUrl' => esc_url_raw(rest_url('veciahorra/v1/stores/' . $id)),
                'nonce' => wp_create_nonce('wp_rest'),
                'updateUrl' => esc_url_raw(add_query_arg(
                    ['page' => 'veciahorra-store-edit'],
                    admin_url('admin.php')
                )),
                'updateNonce' => wp_create_nonce('veciahorra_store'),
                'returnUrl' => esc_url_raw($request->returnUrl()),
            ];
        }

        $this->render('detail', [
            'config' => $config,
            'returnUrl' => $request->returnUrl(),
            'errorMessage' => $request->screen() === StoreAdminPageRequest::SCREEN_UNKNOWN_ACTION
                ? 'La acción administrativa solicitada no es válida.'
                : ($valid ? null : 'Minimarket inválido.'),
        ]);
    }

    /**
     * Procesa los cambios masivos de estado.
     */
    private function processBulkAction(StoresTable $table): void
    {
        if (! current_user_can('manage_options')) {
            Flash::error(
                'No tienes permisos para realizar esta acción.'
            );

            $this->redirect('veciahorra-stores');
        }

        $nonce = sanitize_text_field(
            wp_unslash($_POST['veciahorra_bulk_nonce'] ?? '')
        );

        if (
            ! wp_verify_nonce(
                $nonce,
                'veciahorra_bulk_update_stores'
            )
        ) {
            Flash::error(
                'La solicitud no es válida o ha expirado.'
            );

            $this->redirect('veciahorra-stores');
        }

        $allowedStatuses = [
            'pending',
            'active',
            'inactive',
            'rejected',
        ];

        $status = sanitize_key(
            (string) $table->current_action()
        );

        if (! in_array($status, $allowedStatuses, true)) {
            Flash::error('Acción masiva no válida.');

            $this->redirect('veciahorra-stores');
        }

        $ids = array_values(
            array_filter(
                array_unique(
                    array_map(
                        'absint',
                        (array) ($_POST['store_ids'] ?? [])
                    )
                )
            )
        );

        if ($ids === []) {
            Flash::error(
                'Selecciona al menos un minimarket.'
            );

            $this->redirect('veciahorra-stores');
        }

        $affected = $this->executeAction(
            fn (): int => $this->service()->bulkUpdateStatus(
                $ids,
                $status
            ),
            function (): void {
                $this->redirect('veciahorra-stores');
            }
        );

        Flash::success(
            sprintf(
                '%d minimarkets actualizados.',
                $affected
            )
        );

        $this->redirectWithQuery(
            'veciahorra-stores',
            array_filter([
                'status' => sanitize_key(
                    $_POST['status'] ?? ''
                ),
                's' => sanitize_text_field(
                    wp_unslash($_POST['s'] ?? '')
                ),
            ])
        );
    }

    /**
     * Ejecuta una acción controlando errores esperados.
     */
    private function executeAction(
        callable $callback,
        callable $onError
    ): mixed {
        try {
            return $callback();
        } catch (
            InvalidArgumentException |
            PersistenceException |
            RecordNotFoundException $exception
        ) {
            Flash::error($exception->getMessage());

            $onError();
        }
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
        $this->executeAction(
            function (): void {
                $request = new StoreRequest();

                $data = $request->validatedForCreate();

                $this->service()->create($data);
            },
            function (): void {
                $this->redirect('veciahorra-store-create');
            }
        );

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

    $store = $this->service()->find($id);

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
    $id = (int) ($_POST['id'] ?? 0);

    if ($this->wantsJson()) {
        $this->updateJson($id);
        return;
    }

    $this->executeAction(
        function () use ($id): void {
            $request = new StoreRequest();

            $data = $request->validatedForUpdate();

            $this->service()->update($id, $data);
        },
        function () use ($id): void {
            if ($id > 0) {
                $this->redirectWithQuery(
                    'veciahorra-store-edit',
                    ['id' => $id]
                );
            }

            $this->redirect('veciahorra-stores');
        }
    );

    Flash::success(
        'Minimarket actualizado correctamente.'
    );

    $this->redirect('veciahorra-stores');
}

private function wantsJson(): bool
{
    return ($_SERVER['HTTP_X_VECIAHORRA_STORE_DETAIL'] ?? '') === 'commercial-update'
        && str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
}

private function updateJson(int $id): void
{
    nocache_headers();
    header('Cache-Control: private, no-store');
    if (! current_user_can('manage_options')) {
        wp_send_json_error(['code' => 'forbidden'], 403);
    }
    $nonce = is_string($_POST['_wpnonce'] ?? null) ? wp_unslash($_POST['_wpnonce']) : '';
    if (! wp_verify_nonce($nonce, 'veciahorra_store')) {
        wp_send_json_error(['code' => 'invalid_nonce'], 403);
    }
    if ($id <= 0 || $this->service()->find($id) === null) {
        wp_send_json_error(['code' => 'store_not_found'], 404);
    }
    try {
        $data = (new StoreRequest())->validatedForUpdate();
        $this->service()->update($id, $data);
    } catch (StoreValidationException $exception) {
        wp_send_json_error([
            'code' => 'validation_error',
            'fields' => $exception->errors(),
        ], 422);
    } catch (PersistenceException | RecordNotFoundException $exception) {
        wp_send_json_error(['code' => 'update_failed'], 500);
    }
    wp_send_json_success(['updated' => true], 200);
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

    $this->executeAction(
        function () use ($id): void {
            $this->service()->delete($id);
        },
        function (): void {
            $this->redirect('veciahorra-stores');
        }
    );

    Flash::success(
        'Minimarket eliminado correctamente.'
    );

    $this->redirect('veciahorra-stores');
}

}
