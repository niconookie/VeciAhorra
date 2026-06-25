<?php
/**
 * Plugin Name: VeciAhorra
 * Plugin URI: https://veciahorra.cl
 * Description: Marketplace para múltiples minimarkets desarrollado sobre WordPress y WooCommerce.
 * Version: 1.0.0
 * Requires at least: 6.7
 * Requires PHP: 8.2
 * Author: Nicolás Ávila
 * License: GPL v2 or later
 * Text Domain: veciahorra
 */
<?php
/**
 * Plugin Name: VeciAhorra
 * Plugin URI: https://veciahorra.cl
 * Description: Marketplace para múltiples minimarkets desarrollado sobre WordPress y WooCommerce.
 * Version: 1.0.0-alpha.1
 * Requires at least: 6.7
 * Requires PHP: 8.2
 * Author: Nicolás Ávila
 * License: GPL-2.0-or-later
 * Text Domain: veciahorra
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('VA_VERSION', '1.0.0-alpha.1');
define('VA_PLUGIN_FILE', __FILE__);
define('VA_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('VA_PLUGIN_URL', plugin_dir_url(__FILE__));
define('VA_PLUGIN_BASENAME', plugin_basename(__FILE__));

/*
|--------------------------------------------------------------------------
| Composer Autoload
|--------------------------------------------------------------------------
*/

$autoload = VA_PLUGIN_PATH . 'vendor/autoload.php';

if (file_exists($autoload)) {
    require_once $autoload;
} else {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>VeciAhorra:</strong> Debes ejecutar <code>composer install</code> antes de activar el plugin.</p></div>';
    });

    return;
}

/*
|--------------------------------------------------------------------------
| Inicio del Framework
|--------------------------------------------------------------------------
*/

VeciAhorra\Core\Bootstrap::boot();