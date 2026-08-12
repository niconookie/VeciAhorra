# Séptima corrección normativa A11 de harnesses de certificación EA6

Estado: contrato documental cerrado. Fecha: 2026-08-05.

## 1. Objeto y precedencia limitada

Esta corrección resuelve exclusivamente la ausencia de archivos autorizados para certificar EA6. Amplía la allowlist anterior de cuatro componentes con siete harnesses independientes. No modifica schemas, protocolos, producto, Runtime Capture, Action Capture, loopback, shutdown, orphan closure o bootstrap.

La prohibición previa de crear un quinto archivo queda sustituida únicamente para los siete harnesses exactos enumerados aquí. No se autoriza un octavo harness, quinto componente de soporte, runner, helper, fixture, manifest o log persistente.

## 2. Diagnóstico físico reconocido

Antes de esta corrección existen únicamente:

```text
tests/manual/durable-retry-a11-runtime-capture-test.php
tests/manual/durable-retry-a11-runtime-capture-infrastructure-test.php
tests/manual/support/durable-retry-a11-runtime-capture-contract.php
tests/manual/support/durable-retry-a11-coordinator.php
```

Los dos primeros son harnesses EA5 protegidos; los otros son soporte. No existen harnesses independientes para Action Capture, child, stub, invocation plan, consumo, orphan closure, shutdown/loopback o matriz EA6. Soporte o suites embebidas en soporte no cuentan como harness.

## 3. Allowlist futura completa

Componentes de soporte modificables/creables:

```text
tests/manual/support/durable-retry-a11-runtime-capture-contract.php
tests/manual/support/durable-retry-a11-coordinator.php
tests/manual/support/durable-retry-a11-child-worker.php
tests/manual/support/durable-retry-a11-http-webpay-stub.php
```

Harnesses nuevos autorizados:

```text
tests/manual/durable-retry-a11-action-capture-test.php
tests/manual/durable-retry-a11-action-capture-infrastructure-test.php
tests/manual/durable-retry-a11-child-protocol-test.php
tests/manual/durable-retry-a11-http-webpay-stub-protocol-test.php
tests/manual/durable-retry-a11-action-invocation-plan-test.php
tests/manual/durable-retry-a11-orphan-closure-test.php
tests/manual/durable-retry-a11-ea6-matrix-test.php
```

Total exacto: once archivos EA6. Los nombres y ubicaciones no admiten aliases, fusión, abreviación o reemplazo.

## 4. Protección EA5

Estos harnesses permanecen byte por byte intactos y fuera de la allowlist de modificación:

```text
tests/manual/durable-retry-a11-runtime-capture-test.php
tests/manual/durable-retry-a11-runtime-capture-infrastructure-test.php
```

Ambos se ejecutan como regresión obligatoria, pero no sustituyen ninguno de los siete nuevos.

## 5. Action Capture funcional

`durable-retry-a11-action-capture-test.php` certifica schema/key sets, seis puertos, fases, delta unitario, counts, cero/múltiples actions, orden observable, tipos y puertos inválidos, overflow, ausencia de aplicación parcial y comparación con expected actions caso-específicas.

No deriva expectations ni modifica fixtures.

## 6. Action Capture infraestructura

`durable-retry-a11-action-capture-infrastructure-test.php` certifica allowlist/nombres, namespaces, ausencia de filesystem/environment/pipes extra, stdout exclusivo, aislamiento WordPress, bootstrap solo child a nivel 6, reason `a11_support_wp_load_preflight_failed`, ausencia de fallback, temporales, procesos y listeners.

## 7. Protocolo child

`durable-retry-a11-child-protocol-test.php` certifica `phase_request→phase_result`: un request/result, JSON/framing/EOF/exit, capture y actions dentro del bundle, stdout limpio, bootstrap/preflight y cierre sin child residual.

## 8. Protocolo stub

`durable-retry-a11-http-webpay-stub-protocol-test.php` certifica `loopback_request→loopback_result`, lookup por invocation ID, challenge/proof, HTTP lifecycle, readiness, shutdown permitido/prematuro/duplicado, requests desconocidas, consumo duplicado, stdout único, aislamiento WordPress y cero listeners.

Las matrices de shutdown y loopback se prueban aquí y en la matriz integral; no requieren otro archivo.

## 9. Invocation plan

`durable-retry-a11-action-invocation-plan-test.php` certifica schema v1, seis keys por entry, 62 entries, unicidad, bindings, fases/entrypoints, validación previa, readonly/memoria, lookup exclusivo por invocation ID, consumo único, segundo consumo rechazado y ausencia de campos o catálogos paralelos.

## 10. Orphan closure

`durable-retry-a11-orphan-closure-test.php` certifica exclusivamente la quinta corrección: no anticipación, sets declared/consumed, cierre limpio con cero/una/varias pendientes, diferencia de sets, último lugar de precedencia, preservación del error anterior y prohibición de consumos/invocations artificiales.

## 11. Matriz integral

`durable-retry-a11-ea6-matrix-test.php` certifica los 31 casos únicos, 62 entries, first delivery/replay, Runtime/Action Capture, child/stub, lookup/consumo, shutdown/loopback, orphan, snapshots, atomicidad, precedencia, expected rows/actions/result, cleanup y ausencia de residuos.

Incluye positivos y adversariales sin reemplazar los seis harnesses focalizados.

## 12. Independencia y salida

Cada harness es entrypoint PHP directo, comienza sin estado compartido, limpia lo creado y devuelve 0 únicamente con todas sus verificaciones satisfechas. Ante el primer fallo material devuelve nonzero y reporta suite, casos, assertions, failures, warnings, notices y deprecations en un summary JSON final compatible con los harnesses EA5.

Ninguno depende del orden previo, modifica producto/docs/fixtures, comparte mutable state o deja procesos/listeners/runtime. No existe harness maestro que oculte ejecuciones independientes.

## 13. Clasificación WordPress

Los siete harnesses, situados en `tests/manual/`, permanecen aislados de WordPress y no contienen `wp-load.php` ni `dirname(__DIR__, 5)`. Invocan soporte según necesidad.

Solo `tests/manual/support/durable-retry-a11-child-worker.php` carga WordPress usando nivel 6. Contract, coordinator y stub permanecen aislados. Esta regla preserva la sexta corrección.

## 14. Orden futuro de certificación

Orden fail-fast obligatorio:

1. lint de once archivos EA6;
2. Runtime Capture functional EA5;
3. Runtime Capture infrastructure EA5;
4. Action Invocation Plan;
5. Action Capture functional;
6. Orphan Closure;
7. Child Protocol;
8. HTTP Webpay Stub Protocol;
9. Action Capture Infrastructure;
10. matriz EA6 integral;
11. regresión histórica Durable Retry autorizada;
12. guardias Git/filesystem/PIDs/listeners/runtime.

No se continúa después del primer fallo.

## 15. Criterio de certificación

`A11 ACTION CAPTURE EA6 IMPLEMENTADO Y CERTIFICADO` requiere siete harnesses nuevos verdes individualmente, dos EA5 intactos verdes, matriz/infraestructura/regresión verdes, cero warnings/notices/deprecations/fatals/timeouts materiales, cero residuos, hashes normativos intactos y `git diff --check` limpio.

Una suite embebida, pruebas manuales o solo focalizadas no satisfacen el criterio.

## 16. Matriz normativa

| # | Escenario | Decisión |
|---:|---|---|
| 1 | harnesses EA6 inicialmente ausentes | diagnóstico confirmado |
| 2 | soporte usado como harness | prohibido |
| 3 | suite embebida en soporte | no contabiliza |
| 4 | siete nombres exactos | autorizados |
| 5 | octavo harness | prohibido |
| 6 | dos EA5 | protegidos y ejecutados |
| 7 | Action Capture funcional | harness 1 |
| 8 | Action Capture infraestructura | harness 2 |
| 9 | child protocol | harness 3 |
| 10 | stub protocol | harness 4 |
| 11 | invocation plan | harness 5 |
| 12 | lookup/consumo | harness 5 |
| 13 | orphan closure | harness 6 |
| 14 | shutdown | harness 4 + integral |
| 15 | loopback | harness 4 + integral |
| 16 | 31 casos | harness 7 |
| 17 | 62 entries | harness 5 + 7 |
| 18 | ejecución independiente | obligatoria 7/7 |
| 19 | orden | sección 14 |
| 20 | regresión EA5 | 2/2 intactos |
| 21 | regresión histórica | posterior a matriz |
| 22 | procesos | cero al cierre |
| 23 | listeners | cero al cierre |
| 24 | `.a11-runtime` | ausente al cierre |
| 25 | fuera de allowlist | cero cambios |

## 17. Prohibiciones

Se prohíbe modificar EA5, crear octavo harness/quinto soporte/runner, fusionar harnesses, tests dentro de child/stub contabilizados como harness, nombres alternativos, fixtures/logs persistentes, WordPress en harnesses, canales adicionales, omisiones por cobertura duplicada o cambios documentales previos.

## 18. Alcance actual

Este microhito crea solo este documento. No implementa EA6 ni crea child, stub o harnesses.

## 19. Veredicto

La allowlist, responsabilidades, independencia, bootstrap, orden y criterio de certificación quedan cerrados sin archivos implícitos.

`A11 ACTION CAPTURE EA6 CERTIFICATION HARNESSES IMPLEMENTABLE TRAS SÉPTIMA CORRECCIÓN NORMATIVA`
