# Modo de pre-lanzamiento

La autoridad central es `VeciAhorra\Core\LaunchGate`. Si las constantes no existen, los registros y el comercio permanecen habilitados en `local`, `development` y `staging`, y deshabilitados de forma segura en `production`. Sólo el booleano nativo `true` habilita una constante definida.

Antes de la línea final de WordPress en el `wp-config.php` productivo:

```php
define('VECIAHORRA_PUBLIC_REGISTRATION_ENABLED', false);
define('VECIAHORRA_PUBLIC_COMMERCE_ENABLED', false);
```

Para el lanzamiento del 1 de septiembre de 2026:

```php
define('VECIAHORRA_PUBLIC_REGISTRATION_ENABLED', true);
define('VECIAHORRA_PUBLIC_COMMERCE_ENABLED', true);
```

Después de cambiar los flags se deben purgar las cachés de página/CDN. No se almacenan en opciones ni pueden modificarse mediante parámetros HTTP.
