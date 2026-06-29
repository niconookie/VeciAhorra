<?php

declare(strict_types=1);

namespace VeciAhorra\Admin\Tables;

if (! class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

use WP_List_Table;

/**
 * Adaptador entre Table Builder y WP_List_Table.
 */
abstract class BaseListTable extends WP_List_Table
{
    protected Table $table;

    public function __construct(
        Table $table
    ) {

        $this->table = $table;

        parent::__construct([
            'singular' => 'item',
            'plural'   => 'items',
            'ajax'     => false,
        ]);
    }

    /**
     * Devuelve las columnas.
     */
    public function get_columns(): array
    {
        $columns = [];

        foreach ($this->table->columns() as $column) {

            $columns[$column->name()] = $column->label();
        }

        return $columns;
    }

    /**
     * Columnas ordenables.
     */
    public function get_sortable_columns(): array
    {
        $sortable = [];

        foreach ($this->table->columns() as $column) {

            if ($column->sortableState()) {

                $sortable[$column->name()] = [
                    $column->name(),
                    false
                ];
            }
        }

        return $sortable;
    }

    /**
     * Render por defecto.
     */
    public function column_default(
        $item,
        $column_name
    ) {

        return $item[$column_name] ?? '';
    }

    /**
     * Sin registros.
     */
    public function no_items(): void
    {
        esc_html_e(
            'No existen registros.',
            'veciahorra'
        );
    }
}