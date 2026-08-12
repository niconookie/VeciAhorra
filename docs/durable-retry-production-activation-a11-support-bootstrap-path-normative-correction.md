# Sexta corrección normativa A11 de la ruta bootstrap en support

Estado: contrato documental cerrado y fail-closed. Fecha: 2026-08-05.

## 1. Objeto y precedencia contextual

Esta corrección resuelve exclusivamente la profundidad de `wp-load.php` para entrypoints A11 ubicados físicamente en `tests/manual/support/`. Sustituye, solo en ese contexto, toda orden que exija `dirname(__DIR__, 5) . '/wp-load.php'`.

La autoridad previa de bootstrap no queda anulada globalmente. Nivel 5 continúa siendo correcto para archivos directamente en `tests/manual/`. Ninguna regla sustantiva de Runtime Capture, Action Capture, loopback, shutdown, orphan closure, bundles, hashes o EA6 cambia.

## 2. Hecho filesystem autoritativo

```text
Raíz plugin:
C:\xampp\htdocs\Minimarket\wp-content\plugins\veciahorra

Directorio entrypoints EA6:
C:\xampp\htdocs\Minimarket\wp-content\plugins\veciahorra\tests\manual\support

WordPress root:
C:\xampp\htdocs\Minimarket

wp-load.php:
C:\xampp\htdocs\Minimarket\wp-load.php
```

Desde `tests/manual/support`, nivel 5 resuelve `C:\xampp\htdocs\Minimarket\wp-content` y produciría el inexistente `C:\xampp\htdocs\Minimarket\wp-content\wp-load.php`. Esa expresión queda prohibida allí.

Nivel 6 resuelve `C:\xampp\htdocs\Minimarket` y produce la ruta real. Para todo entrypoint directamente situado en support, la expresión exclusiva es:

```php
dirname(__DIR__, 6) . '/wp-load.php'
```

## 3. Resolución contextual cerrada

| Ubicación | Expresión | Resultado | Veredicto |
|---|---|---|---|
| `tests/manual/` | `dirname(__DIR__, 5)` | `C:\xampp\htdocs\Minimarket` | válida |
| `tests/manual/` | `dirname(__DIR__, 6)` | `C:\xampp\htdocs` | inválida |
| `tests/manual/support/` | `dirname(__DIR__, 5)` | `C:\xampp\htdocs\Minimarket\wp-content` | inválida |
| `tests/manual/support/` | `dirname(__DIR__, 6)` | `C:\xampp\htdocs\Minimarket` | válida |

La profundidad se determina por el `__DIR__` del archivo que ejecuta `require_once`, nunca por el proceso padre, cwd o directorio de invocación.

## 4. Preflight fail-closed

Un entrypoint support que requiera WordPress debe construir primero exactamente:

```php
$wpLoadPath = dirname(__DIR__, 6) . '/wp-load.php';
```

Luego evalúa, en este orden, `is_file($wpLoadPath)` e `is_readable($wpLoadPath)`. Si cualquiera es false, no ejecuta `require_once`, no inicia efectos ni procesos descendientes y termina con reason exacto:

```text
a11_support_wp_load_preflight_failed
```

No existe fallback, búsqueda o segundo reason. Tras un preflight válido ejecuta exactamente una vez:

```php
require_once $wpLoadPath;
```

Un error producido por el propio bootstrap conserva su error fatal/controlado conforme al supervisor; no se oculta ni se intenta otra ruta.

## 5. Bootstrap único y prohibiciones

Se prohíbe probar nivel 5 y luego 6, globbing, ascenso dinámico, recursión, path absoluto hardcoded, `getcwd()`, DocumentRoot, environment, configuración externa, cambio de cwd, copia/symlink/junction, loader intermedio, `wp-blog-header.php`, carga directa de `wp-config.php` y más de un bootstrap.

También se prohíbe usar nivel 6 indiscriminadamente en `tests/manual/`, mover entrypoints para conservar otra profundidad, modificar WordPress/XAMPP o afirmar que una expresión sirve para cualquier ubicación.

## 6. Clasificación de los cuatro archivos EA6

| Ruta relativa | Ubicación | ¿Carga WordPress? | Profundidad | Regla |
|---|---|:---:|:---:|---|
| `tests/manual/support/durable-retry-a11-runtime-capture-contract.php` | support | no | ninguna | librería pura; aislada |
| `tests/manual/support/durable-retry-a11-coordinator.php` | support | no | ninguna | supervisor/protocolo; aislado |
| `tests/manual/support/durable-retry-a11-child-worker.php` | support | sí | 6 | preflight y `require_once` único |
| `tests/manual/support/durable-retry-a11-http-webpay-stub.php` | support | no | ninguna | servidor loopback controlado; aislado |

El child requiere WordPress porque ejecuta el escenario productivo controlado y cruza puertos reales. El stub solo implementa HTTP loopback y Action Capture local; cargar WordPress alteraría su aislamiento. Contract y coordinator no ejecutan producto.

## 7. Orden de lifecycle

Para child: validar framing/request que pueda validarse sin WordPress; resolver y comprobar `$wpLoadPath`; cargar una vez; completar validación dependiente de producto; ejecutar. Ningún efecto productivo comienza antes del preflight y bootstrap.

Coordinator valida plan y requests antes de iniciar child o stub. El stub nunca carga WordPress. Un fallo de preflight child se clasifica como fallo temprano, no permite shutdown normal, no integra evidencia y activa cleanup normativo.

## 8. Matriz normativa

| # | Escenario | Decisión | Reason/resultado |
|---:|---|---|---|
| 1 | entrypoint directo en manual, nivel 5 | acepta | WordPress root |
| 2 | entrypoint directo en support, nivel 6 | acepta | WordPress root |
| 3 | support con nivel 5 | rechaza | ruta inexistente/prohibida |
| 4 | manual con nivel 6 | rechaza | nivel excesivo |
| 5 | `wp-load.php` ausente | rechaza antes de require | `a11_support_wp_load_preflight_failed` |
| 6 | archivo no legible | rechaza antes de require | `a11_support_wp_load_preflight_failed` |
| 7 | ruta válida y legible | acepta | un require |
| 8 | segundo `require_once` explícito | rechaza implementación | bootstrap no único |
| 9 | fallback 5→6 | rechaza | fallback prohibido |
| 10 | `getcwd()` | rechaza | dependencia contextual prohibida |
| 11 | ruta absoluta | rechaza | hardcode prohibido |
| 12 | child worker | carga nivel 6 | proceso productivo controlado |
| 13 | stub/contract/coordinator | no carga | aislamiento obligatorio |
| 14 | proceso antes del preflight | rechaza | lifecycle inválido |
| 15 | demás autoridades | preserva | sin cambio sustantivo |

## 9. Reconciliación expresa

La expresión nivel 5 fue aplicada fuera de contexto al reutilizarse desde un directorio con el nivel adicional `support`. Esta corrección desplaza exactamente un nivel para esos entrypoints y nada más.

Toda autoridad o encargo posterior que exija nivel 5 desde `tests/manual/support/` queda sustituido solo en ese aspecto. Las cinco autoridades Action Capture protegidas y la autoridad histórica para archivos directamente en manual permanecen vigentes.

## 10. Allowlist futura

EA6 conserva sus cuatro rutas. Esta corrección no autoriza loader, helper o quinto archivo. Solo child contiene la resolución nivel 6 y bootstrap; los otros tres permanecen WordPress-free.

## 11. Límites

Este documento no implementa EA6, no crea child, stub, helper o harness, y no ejecuta PHP, WordPress, MySQL, matrices o integración. No modifica archivos existentes.

## 12. Veredicto

La profundidad queda ligada a la ubicación física del llamador, con preflight único, error cerrado y clasificación explícita de procesos.

`A11 SUPPORT BOOTSTRAP PATH IMPLEMENTABLE TRAS SEXTA CORRECCIÓN NORMATIVA`
