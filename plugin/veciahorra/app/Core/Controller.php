<?php

declare(strict_types=1);

namespace VeciAhorra\Core;

/**
 * Controlador base del framework.
 */
abstract class Controller
{
    /**
     * Renderiza una vista.
     *
     * @param string $view
     * @param array $data
     */
    /**
 * Renderiza una vista.
 */
protected function render(
    string $view,
    array $data = []
): void {

    extract($data);

    require $this->viewPath . '/' . $view . '.php';
}

    /**
     * Redirecciona a una página del administrador.
     *
     * @param string $page
     */
    protected function redirect(string $page): void
    {
        wp_safe_redirect(
            admin_url(
                'admin.php?page=' . $page
            )
        );

        exit;
    }

    /**
     * Redirecciona con parámetros adicionales.
     *
     * @param string $page
     * @param array $query
     */
    protected function redirectWithQuery(
        string $page,
        array $query
    ): void {

        $url = add_query_arg(
            $query,
            admin_url(
                'admin.php?page=' . $page
            )
        );

        wp_safe_redirect($url);

        exit;
    }

    /**
 * Ruta de las vistas del módulo.
 */
protected string $viewPath;
}