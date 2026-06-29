<?php

declare(strict_types=1);

namespace VeciAhorra\Admin\Tables\Drivers;

use VeciAhorra\Admin\Tables\Table;

/**
 * Driver para renderizar tablas usando WP_List_Table.
 */
final class WordpressTableDriver
{
    public function __construct(
        private readonly Table $table
    ) {
    }

    /**
     * Obtiene la definición de la tabla.
     */
    public function table(): Table
    {
        return $this->table;
    }
}