<?php

declare(strict_types=1);

namespace VeciAhorra\Admin\Tables;

use VeciAhorra\Modules\Stores\Services\StoreService;

/**
 * Tabla de Minimarkets.
 */
final class StoresTable extends BaseListTable
{
    /**
     * Renderiza como texto las columnas provenientes de la base de datos.
     */
    public function column_default(
        $item,
        $column_name
    ): string {
        return esc_html(
            (string) ($item[$column_name] ?? '')
        );
    }

    public function get_columns(): array
    {
        return [
            'cb' => '<input type="checkbox">',
        ] + parent::get_columns();
    }

    public function column_cb($item): string
    {
        return sprintf(
            '<input type="checkbox" name="store_ids[]" value="%d">',
            absint($item['id'])
        );
    }

    protected function get_bulk_actions(): array
    {
        return [
            'pending'  => 'Marcar pendiente',
            'active'   => 'Activar',
            'inactive' => 'Desactivar',
            'rejected' => 'Rechazar',
        ];
    }

    public function __construct()
    {
        parent::__construct(
            $this->build()
        );
    }

    /**
     * Construye la definición de la tabla.
     */
    private function build(): Table
    {
        return Table::make()

            ->column(
                Column::make('id', 'ID')
                    ->sortable()
            )

            ->column(
                Column::make('business_name', 'Nombre')
                    ->sortable()
            )

            ->column(
                Column::make('owner_name', 'Propietario')
            )

            ->column(
                Column::make('status', 'Estado')
            )

            ->column(
                Column::make('actions', 'Acciones')
            );
    }

    /**
     * Carga los registros.
     */
    public function prepare_items(): void
    {
        $this->_column_headers = [
            $this->get_columns(),
            [],
            $this->get_sortable_columns(),
        ];

        $service = new StoreService();

        $perPage = 2;

        $currentPage = max(
            1,
            (int) ($_REQUEST['paged'] ?? 1)
        );

        $search = sanitize_text_field(
            $_REQUEST['s'] ?? ''
        );

        $status = sanitize_key(
            $_REQUEST['status'] ?? ''
        );

        $allowedStatuses = [
            'pending',
            'active',
            'inactive',
            'rejected',
        ];

        if (!in_array($status, $allowedStatuses, true)) {
            $status = null;
        }

        $orderBy = sanitize_key(
            $_REQUEST['orderby'] ?? 'id'
        );

        $direction = strtoupper(
            sanitize_text_field(
                $_REQUEST['order'] ?? 'DESC'
            )
        );

        $this->items = $service
            ->paginate(
                $currentPage,
                $perPage,
                $search,
                $status,
                $orderBy,
                $direction
            )
            ->toArray();

        $totalItems = $service->count(
            $search,
            $status
        );

        $this->set_pagination_args([
            'total_items' => $totalItems,
            'per_page'    => $perPage,
            'total_pages' => (int) ceil(
                $totalItems / $perPage
            ),
        ]);
    }

    /**
     * Renderiza la columna Acciones.
     */
    public function column_actions($item): string
    {
        $edit = admin_url(
            'admin.php?page=veciahorra-store-edit&id=' . $item['id']
        );

        $delete = wp_nonce_url(
            admin_url(
                'admin.php?page=veciahorra-store-delete&id=' . $item['id']
            ),
            'veciahorra_delete_store'
        );

        return sprintf(
            '<a href="%s">Editar</a> | <a href="%s" onclick="return confirm(\'¿Eliminar este minimarket?\')">Eliminar</a>',
            esc_url($edit),
            esc_url($delete)
        );
    }
}
