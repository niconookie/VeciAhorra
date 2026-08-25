<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Inventory\Admin;

use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Inventory\Import\InventoryBulkImportService;
use VeciAhorra\Modules\Inventory\Import\InventoryCsvParser;

final class InventoryBulkImportPage
{
    public const PAGE_SLUG = 'veciahorra-inventory-import';
    private const CAPABILITY = 'manage_options';
    private const TRANSIENT_PREFIX = 'va_inventory_import_';
    private ?string $pageHook = null;

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('admin_post_veciahorra_inventory_csv_preview', [$this, 'preview']);
        add_action('admin_post_veciahorra_inventory_csv_confirm', [$this, 'confirm']);
        add_action('admin_post_veciahorra_inventory_csv_template', [$this, 'template']);
        add_action('admin_post_veciahorra_inventory_csv_errors', [$this, 'errors']);
    }

    public function menu(): void
    {
        $hook = add_submenu_page('veciahorra', 'Carga masiva de inventario', 'Carga masiva CSV', self::CAPABILITY, self::PAGE_SLUG, [$this, 'render']);
        $this->pageHook = is_string($hook) ? $hook : null;
    }

    public function assets(string $hook): void
    {
        if ($this->pageHook !== null && $hook === $this->pageHook) wp_enqueue_style('veciahorra-inventory-import', VA_PLUGIN_URL . 'assets/admin/css/inventory-import.css', [], Config::PLUGIN_VERSION);
    }

    public function render(): void
    {
        $this->authorize();
        $service = new InventoryBulkImportService();
        $stores = $service->stores();
        $token = $this->requestToken();
        $preview = $token !== '' ? get_transient($this->key($token)) : false;
        $preview = is_array($preview) && (int) ($preview['user_id'] ?? 0) === get_current_user_id() ? $preview : null;
        $result = $this->resultFromQuery();
        $message = isset($_GET['va_message']) ? sanitize_text_field(wp_unslash((string) $_GET['va_message'])) : '';
        require dirname(__DIR__) . '/Views/import.php';
    }

    public function preview(): void
    {
        $this->authorize(); check_admin_referer('veciahorra_inventory_csv_preview');
        try {
            $storeId = isset($_POST['store_id']) ? absint($_POST['store_id']) : 0;
            if ($storeId < 1) throw new \InvalidArgumentException('Seleccione un minimarket.');
            $file = $_FILES['inventory_csv'] ?? null;
            if (! is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new \InvalidArgumentException('Seleccione un archivo CSV válido.');
            if ((int) ($file['size'] ?? 0) < 1 || (int) $file['size'] > InventoryCsvParser::MAX_BYTES) throw new \InvalidArgumentException('El archivo debe pesar como máximo 1 MB.');
            $name = (string) ($file['name'] ?? '');
            if (strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) !== 'csv') throw new \InvalidArgumentException('El archivo debe tener extensión .csv.');
            $path = (string) ($file['tmp_name'] ?? '');
            if ($path === '' || ! is_uploaded_file($path)) throw new \InvalidArgumentException('La carga del archivo no es válida.');
            $contents = file_get_contents($path);
            if (! is_string($contents)) throw new \RuntimeException('No fue posible leer el archivo.');
            $parsed = (new InventoryCsvParser())->parse($contents);
            $data = (new InventoryBulkImportService())->preview($storeId, $parsed['rows'], $parsed['errors']);
            $token = bin2hex(random_bytes(24));
            $data['user_id'] = get_current_user_id(); $data['created_at'] = time();
            set_transient($this->key($token), $data, 15 * MINUTE_IN_SECONDS);
            wp_safe_redirect($this->url(['va_token' => $token])); exit;
        } catch (\Throwable $exception) {
            wp_safe_redirect($this->url(['va_message' => $this->publicError($exception)])); exit;
        }
    }

    public function confirm(): void
    {
        $this->authorize(); check_admin_referer('veciahorra_inventory_csv_confirm');
        $token = $this->postedToken();
        $preview = $token !== '' ? get_transient($this->key($token)) : false;
        if (! is_array($preview) || (int) ($preview['user_id'] ?? 0) !== get_current_user_id() || ! empty($preview['completed'])) { wp_safe_redirect($this->url(['va_message' => 'La vista previa venció o ya fue aplicada. Vuelva a validar el CSV.'])); exit; }
        if (! empty($preview['errors'])) { wp_safe_redirect($this->url(['va_token' => $token, 'va_message' => 'El archivo contiene errores. Corrige todas las filas rechazadas y vuelve a cargarlo. No se aplicó ningún cambio.'])); exit; }
        try {
            $result = (new InventoryBulkImportService())->import($preview);
            $preview['completed'] = true; $preview['result'] = $result;
            set_transient($this->key($token), $preview, 15 * MINUTE_IN_SECONDS);
            wp_safe_redirect($this->url(['va_token' => $token, 'va_created' => $result['created'], 'va_updated' => $result['updated'], 'va_unchanged' => $result['unchanged'], 'va_rejected' => $result['rejected']])); exit;
        } catch (\Throwable $exception) {
            wp_safe_redirect($this->url(['va_token' => $token, 'va_message' => $this->publicError($exception)])); exit;
        }
    }

    public function template(): void
    {
        $this->authorize(); check_admin_referer('veciahorra_inventory_csv_template');
        $this->csvHeaders('plantilla-inventario-veciahorra.csv');
        echo "\xEF\xBB\xBFsku,precio,stock,estado\r\nSKU-EJEMPLO,1990,10,active\r\n"; exit;
    }

    public function errors(): void
    {
        $this->authorize();
        $token = $this->requestToken(); check_admin_referer('veciahorra_inventory_csv_errors_' . $token);
        $preview = $token !== '' ? get_transient($this->key($token)) : false;
        if (! is_array($preview) || (int) ($preview['user_id'] ?? 0) !== get_current_user_id()) wp_die(esc_html__('El informe venció.', 'veciahorra'));
        $this->csvHeaders('errores-inventario-veciahorra.csv');
        $out = fopen('php://output', 'wb'); echo "\xEF\xBB\xBF"; fputcsv($out, ['fila', 'sku', 'error']);
        foreach ($preview['errors'] as $error) fputcsv($out, [(int) $error['line'], $this->safeCsv((string) $error['sku']), $this->safeCsv((string) $error['message'])]);
        fclose($out); exit;
    }

    private function authorize(): void { if (! current_user_can(self::CAPABILITY)) wp_die(esc_html__('No autorizado.', 'veciahorra'), '', ['response' => 403]); }
    private function key(string $token): string { return self::TRANSIENT_PREFIX . get_current_user_id() . '_' . hash('sha256', $token); }
    private function requestToken(): string { $value = isset($_GET['va_token']) ? sanitize_text_field(wp_unslash((string) $_GET['va_token'])) : ''; return preg_match('/^[a-f0-9]{48}$/D', $value) === 1 ? $value : ''; }
    private function postedToken(): string { $value = isset($_POST['va_token']) ? sanitize_text_field(wp_unslash((string) $_POST['va_token'])) : ''; return preg_match('/^[a-f0-9]{48}$/D', $value) === 1 ? $value : ''; }
    private function url(array $args = []): string { return add_query_arg(array_merge(['page' => self::PAGE_SLUG], $args), admin_url('admin.php')); }
    private function publicError(\Throwable $error): string { return $error instanceof \InvalidArgumentException || $error instanceof \RuntimeException ? $error->getMessage() : 'No fue posible procesar la importación.'; }
    private function csvHeaders(string $name): void { nocache_headers(); header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="' . $name . '"'); header('X-Content-Type-Options: nosniff'); }
    private function safeCsv(string $value): string { return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value; }
    private function resultFromQuery(): ?array { foreach (['created', 'updated', 'unchanged', 'rejected'] as $key) if (! isset($_GET['va_' . $key])) return null; return ['created' => absint($_GET['va_created']), 'updated' => absint($_GET['va_updated']), 'unchanged' => absint($_GET['va_unchanged']), 'rejected' => absint($_GET['va_rejected'])]; }
}
