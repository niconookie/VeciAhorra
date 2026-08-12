# Corrección normativa A3: tabla física y semántica de ausencia legacy

## 1. Veredicto

**A3 IMPLEMENTABLE TRAS CORRECCIÓN NORMATIVA**

Esta corrección es vinculante y complementa
`docs/durable-retry-production-activation-composition-spec.md`. Sustituye
exclusivamente la expresión incorrecta del nombre físico de tabla y cualquier
interpretación que trate “ausente” y “legacy persistido” como estados
independientes.

## 2. Base auditada

- Rama `main`.
- HEAD `f61e71c9c5ee14d76d7907f8ae8098e1145db869`.
- Divergencia `0` atrás / `35` adelante.
- Schema `0.24.0` (`app/Core/Config.php:22`).
- Especificación de composición: 984 líneas, 43 secciones y SHA-256
  `AF017A0E9D88528174D48AFF5B4F21EACB1C25CAA61D2E84DE82457D3C9882C6`.
- Auditoría de composición: 588 líneas, 26 secciones y SHA-256
  `A5751D01A046F7E02F97B2C5F6891F650466BBA60CA6BBD7953DA50B934B8411`.

## 3. Alcance exacto

La corrección sólo afecta:

1. `docs/durable-retry-production-activation-composition-spec.md:357`;
2. la interpretación conjunta de sus líneas 363 y 369-370;
3. las pruebas A3 que pedían simultáneamente “marcador ausente” y “marcador
   legacy”.

No modifica A2, A2.1, A4, A5, A10, A11, A12, schema, migraciones, contratos A1
ni catálogos existentes.

## 4. Autoridades inspeccionadas

- `app/Core/Config.php`;
- `app/Database/Database.php`;
- `app/Database/Repository.php`;
- `app/Database/Schemas/DurableRetryScheduleSchema.php`;
- `app/Database/Migrations/CreateDurableRetrySchedulesTable.php`;
- `app/Modules/Orders/Repositories/DurableRetryScheduleRepository.php`;
- `tests/manual/durable-retry-schedule-repository-mysql-test.php`;
- `tests/manual/durable-retry-schedule-infrastructure-test.php`;
- `app/Modules/Orders/Domain/DurableRetry/DurableRetryLegacyAuthorityResult.php`;
- `app/Modules/Orders/Domain/DurableRetry/DurableRetryIndeterminateReason.php`;
- `app/Modules/Fulfillment/Orchestration/DurableCompletionScheduler.php`;
- `app/Modules/Payments/Reconciliation/Service/WebpayReconciliationMaterializer.php`;
- documentos normativos citados por la especificación de composición.

## 5. Conflicto original del nombre de tabla

La disposición incorrecta es:

```text
{$wpdb->prefix}veciahorra_durable_retry_schedules
```

en `docs/durable-retry-production-activation-composition-spec.md:357`.

Ese nombre omite `Config::TABLE_PREFIX`, inventa el prefijo interno
`veciahorra_` y no coincide con migración, repositorio ni harness MySQL.

## 6. Nombre lógico autoritativo

El nombre lógico es exactamente:

```text
durable_retry_schedules
```

Evidencia:

- `DurableRetryScheduleSchema::name()` lo devuelve en
  `app/Database/Schemas/DurableRetryScheduleSchema.php:12-15`;
- `DurableRetryScheduleRepository::TABLE` lo fija en
  `app/Modules/Orders/Repositories/DurableRetryScheduleRepository.php:24-27`.

El nombre lógico no contiene prefijo WordPress ni prefijo interno del plugin.

## 7. Prefijo interno autoritativo

`Config::TABLE_PREFIX` es la autoridad única del prefijo interno y vale:

```text
va_
```

según `app/Core/Config.php:42`.

No se sustituye por el nombre comercial, slug del plugin ni un literal
alternativo.

## 8. Fórmula física corregida

A3 debe construir el nombre físico exactamente como:

```php
$wpdb->prefix . Config::TABLE_PREFIX . 'durable_retry_schedules'
```

Con la configuración certificada equivale a:

```php
$wpdb->prefix . 'va_durable_retry_schedules'
```

`$wpdb->prefix` permanece dinámico por sitio WordPress. No se asume `wp_`.

## 9. Evidencia de construcción física

La migración usa:

```php
$wpdb->prefix . Config::TABLE_PREFIX . $schema->name()
```

en
`app/Database/Migrations/CreateDurableRetrySchedulesTable.php:26`.

El helper general usa la misma fórmula en
`app/Database/Database.php:78-81`.

El repositorio durable usa:

```php
$database->prefix . Config::TABLE_PREFIX . self::TABLE
```

en
`app/Modules/Orders/Repositories/DurableRetryScheduleRepository.php:76-90`.

El harness MySQL certificado usa
`$wpdb->prefix . 'va_durable_retry_schedules'` en
`tests/manual/durable-retry-schedule-repository-mysql-test.php:11-16`.

Las cuatro autoridades coinciden.

## 10. Fuente de construcción para A3

`DurableRetryLegacyAuthorityRepository`, cuyo constructor normativo recibe
`wpdb`, debe conservar una propiedad privada `table` construida una sola vez en
el constructor mediante:

```php
$database->prefix
    . Config::TABLE_PREFIX
    . 'durable_retry_schedules';
```

Esto reutiliza el mecanismo estable del repositorio durable sin añadir una
dependencia nueva ni modificar la allowlist. Construir el nombre no es una
lectura SQL y no cuenta contra el presupuesto de consultas.

## 11. Prohibiciones de naming

A3 no puede:

- hardcodear `wp_`;
- hardcodear `veciahorra_`;
- omitir `Config::TABLE_PREFIX`;
- usar fallback entre nombres;
- probar varias tablas;
- consultar `information_schema`;
- ejecutar `SHOW TABLES`;
- detectar dinámicamente cuál existe;
- crear una tabla paralela;
- cambiar schema o migraciones.

La ausencia física o fallo de consulta se clasifica como consulta fallida; no
activa búsqueda de otro nombre.

## 12. Evidencia sobre marcador legacy

No existe marcador legacy persistido independiente.

La tabla durable sólo contiene identidad y estado durable; su schema no define
columna `legacy`, `authority`, `owner` ni marcador equivalente
(`app/Database/Schemas/DurableRetryScheduleSchema.php:17-60`).

El scheduler legacy deduplica acciones externas mediante
`as_has_scheduled_action`; no escribe marcador de autoridad
(`app/Modules/Fulfillment/Orchestration/DurableCompletionScheduler.php:27-35`).

El materializador llama directamente al scheduler y tampoco persiste marcador
legacy (`WebpayReconciliationMaterializer.php:118-126,205-213`).

`DurableRetryLegacyAuthorityResult::legacy()` crea un resultado de dominio, no
una fila (`app/Modules/Orders/Domain/DurableRetry/DurableRetryLegacyAuthorityResult.php:19-22`).

## 13. Semántica corregida de ausencia

Una consulta completa y exitosa que no encuentra generation `1` para
`(reconciliation, subject_id)` produce:

```php
DurableRetryLegacyAuthorityResult::legacy()
```

`legacy` es una clasificación derivada de ausencia demostrada del marcador
durable. No es un marcador persistido.

A3:

- no devuelve un estado `absent`;
- no busca una fila legacy;
- no diferencia “ausente” de “legacy persistido”;
- no crea evidencia para recordar la clasificación;
- no transforma error o datos contradictorios en ausencia.

## 14. Catálogo A3 definitivo

El catálogo de estados permanece exactamente:

```text
legacy
durable
indeterminate
```

Está definido en
`app/Modules/Orders/Domain/DurableRetry/DurableRetryLegacyAuthorityResult.php:7-34`.

No se crean `absent`, `inconsistent` ni `uncertain` como estados adicionales.
Inconsistencia e incertidumbre se expresan como estado `indeterminate` con
reason code cerrado.

## 15. Condiciones por estado

| Estado | Condición | Resultado | A2 | A4 | Scheduling |
|---|---|---|---|---|---|
| `legacy` | Consulta completa, cero generation 1 y cero evidencia contradictoria | `legacy()` | Sólo si A5 además acredita `NEWLY_MATERIALIZED` | Sólo tras A2 true | A3 nunca agenda |
| `durable` | Una generation 1 completa y compatible | `durable()` | No | No | A3 nunca agenda |
| `indeterminate` | Error, duplicidad, corrupción, incompletitud o carrera no resuelta | `indeterminate(reason)` | No | No | Ninguno |

El consumidor futuro aborta selección de rama ante `indeterminate`.

## 16. Reasons de indeterminación

Se reutiliza exclusivamente
`DurableRetryIndeterminateReason`:

- `QUERY_FAILED`: error confirmado de consulta;
- `INCOMPATIBLE_DURABLE_STATE`: generation 1 legible pero incompatible;
- `PERSISTED_DUPLICATE`: más de una generation 1;
- `CORRUPT_IDENTITY`: identidad persistida contradictoria;
- `INCOMPLETE_RESULT`: evidencia parcial o faltante en batch;
- `UNRESOLVED_RACE`: resultado de lectura no determinable;
- `CONSISTENCY_ERROR`: coexistencia imposible o consistencia no demostrable.

No se añaden reason codes en A3.

## 17. Semántica de generation 1

1. Ninguna generation 1, consulta completa: `legacy`.
2. Una generation 1 válida: `durable`, aunque sea terminal.
3. Múltiples generation 1: `indeterminate(PERSISTED_DUPLICATE)`.
4. Generation posterior sin generation 1:
   `indeterminate(INCOMPATIBLE_DURABLE_STATE)`.
5. Generation 1 parcialmente legible:
   `indeterminate(INCOMPLETE_RESULT)` o `CORRUPT_IDENTITY` según la forma.
6. Error confirmado de consulta: `indeterminate(QUERY_FAILED)`.
7. Resultado no determinable: `indeterminate(UNRESOLVED_RACE)`.

Generation 1 es el marcador durable permanente. A3 no la crea, repara,
actualiza, supersede ni agenda.

## 18. Históricos, A2, A4 y A5

A3 clasifica autoridad persistida; no determina si la reconciliación es nueva.

La ausencia de generation 1 produce `legacy`, pero:

- una reconciliación preexistente no reevalúa A2;
- `legacy` por sí solo no autoriza A4;
- A5 debe recibir
  `DurableRetryReconciliationInitialScheduleRequest::NEWLY_MATERIALIZED`
  antes de evaluar A2;
- request `PREEXISTING` conserva scheduling legacy sin A2;
- no se necesita un marcador legacy ficticio para preservar históricos.

La corrección no cambia contratos A4 ni A5. Sólo corrige cómo A5 debe
interpretar el resultado `legacy` de A3.

## 19. Allowlist A3 confirmada

La allowlist original sigue siendo suficiente y exacta.

Productivo nuevo:

```text
app/Modules/Orders/Repositories/DurableRetryLegacyAuthorityRepository.php
```

Harnesses nuevos:

```text
tests/manual/durable-retry-legacy-authority-repository-test.php
tests/manual/durable-retry-legacy-authority-repository-mysql-test.php
tests/manual/durable-retry-legacy-authority-infrastructure-test.php
```

Total: cuatro archivos nuevos, cero modificados.

El uso de `Config::TABLE_PREFIX` ocurre dentro del único repositorio autorizado;
no requiere modificar `Config`, schema, migración o repository existente.

## 20. Matriz de pruebas corregida

Los harnesses A3 deben certificar:

1. nombre lógico exacto;
2. fórmula `$wpdb->prefix . Config::TABLE_PREFIX . logical`;
3. prefijo WordPress no hardcodeado;
4. `Config::TABLE_PREFIX === 'va_'`;
5. ausencia del literal `veciahorra_durable_retry_schedules`;
6. cero fallback o probing de tablas;
7. ausencia generation 1 → `legacy`;
8. inexistencia de estado `absent`;
9. generation 1 válida activa/terminal → `durable`;
10. generation posterior sin generation 1 → incompatible/indeterminate;
11. duplicate → persisted duplicate/indeterminate;
12. fila parcial o corrupta → indeterminate;
13. error SQL → query failed/indeterminate;
14. resultado no determinable → unresolved race/indeterminate;
15. individual: máximo una consulta;
16. batch no vacío: exactamente una consulta;
17. batch vacío: cero consultas;
18. cero lecturas en constructor;
19. cero caché entre invocaciones;
20. cero escrituras, reparación o scheduling;
21. cero referencias A2, A2.1 o A4;
22. preexisting legacy no implica evaluación A2.

Se elimina cualquier prueba que exija un marcador legacy persistido.

## 21. Disposiciones sustituidas y vigentes

Queda sustituida la línea 357 de la especificación de composición por:

```text
A3 lee exclusivamente
$wpdb->prefix . Config::TABLE_PREFIX . 'durable_retry_schedules'.
```

Las líneas 363 y 369-370 se interpretan conjuntamente: “sin fila generation 1”
y “legacy” son una sola clasificación cuando la lectura es completa.

Permanecen vigentes:

- constructor `wpdb`;
- interfaces y firmas A1;
- tres estados del resultado;
- reason codes;
- presupuestos de una consulta;
- read-only;
- allowlist de cuatro archivos;
- prohibiciones y gates;
- reglas A4-A12.

## 22. Criterio para reanudar A3

A3 puede reanudarse únicamente después de versionar esta corrección y verificar:

- tabla física mediante la fórmula corregida;
- ausencia de marcador legacy separado;
- catálogo de tres estados;
- indeterminación sin fallback;
- generation 1 permanente;
- allowlist 4/4;
- matriz corregida completa.

Hasta entonces no se autoriza implementación.
