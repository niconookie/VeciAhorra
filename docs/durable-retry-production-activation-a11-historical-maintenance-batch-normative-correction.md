# Corrección normativa del lote de mantenimiento histórico A11

Estado: corrección documental, cerrada y fail-closed. Fecha: 2026-08-03.

Veredicto de partida: `A11 REGRESIÓN HISTÓRICA CONTINÚA BLOQUEADA`. Esta corrección autoriza un lote de mantenimiento separado; no modifica la implementación A11 ni amplía su coexistencia de ocho paths.

## 1. Estado base

Base observada: rama `main`, HEAD `847879d509864c6ad077bfecb6dfa05537fbb899`, divergencia `0 behind / 61 ahead`, staging vacío y `git diff --check` satisfactorio. Los cambios finales de R3 eran cero y los 13 harnesses conservaban sus hashes restaurados.

Persistían cuatro cambios tracked A11, cuatro archivos Runtime Capture untracked y el documento R2 untracked. `artifacts/` tenía 504 archivos; `.a11-runtime`, `.git/index.lock`, procesos PHP, temporales A11 y manifest runtime persistente estaban ausentes. El accessor tipado aparecía una vez. WR-06 no estaba materializado y no había IDs asignados, commit ni push.

## 2. Antecedentes normativos

Son autoridades acumulativas:

- `docs/durable-retry-production-activation-a11-normative-correction.md`, contrato principal y allowlist A11;
- `docs/durable-retry-production-activation-a11-complementary-normative-correction.md:3-29`, cuatro modificables y ocho nuevos;
- `docs/durable-retry-production-activation-a11-runtime-capture-transport-normative-correction.md:422-429`, cuatro paths Runtime Capture;
- `docs/durable-retry-production-activation-a11-historical-coexistence-normative-audit.md`, R2, SHA-256 `38F1DE24F1CC583A1FE55278C87E5C2DE162513846189F9520369C9CC73EF495`;
- contratos ejecutables de los 13 harnesses;
- evidencia R1: 65/78, trece fallos de guardia, scheduler transitorio no reproducido;
- evidencia R3: prueba provisional 10/13, tres guardias globales bloqueantes y restauración 13/13.

## 3. Contradicción descubierta

La regla R2:

```text
HISTORICAL_HARNESS_PATHS ∪ A11_LOCAL_COEXISTENCE_PATHS
```

es suficiente para guardias de diff restringido, pero insuficiente durante una modificación simultánea de 13 harnesses tracked. Las guardias globales ven los otros archivos R3 modificados. Estos no son propios del harness ni paths A11, aunque sí forman parte de la misma transacción de mantenimiento autorizada.

## 4. Evidencia provisional A11-R3

- 13 harnesses ejecutados antes de editar: 13/13 fallaron en su primera guardia.
- Todos los paths inicialmente rechazados pertenecían a los ocho A11.
- Edición provisional con lista A11 exacta: `php -l` 13/13.
- Harnesses verdes: 10/13.
- Bloqueados: initial authority producer, initial transfer authority y production composition infrastructure.
- Causa: observación global de otros harnesses R3 modificados.
- Ediciones revertidas íntegramente; hashes restaurados 13/13; cambios finales R3: cero.

La prueba es evidencia diagnóstica y no implementación parcial.

## 5. Invariante de ocho paths A11

`A11_LOCAL_COEXISTENCE_PATHS` permanece exactamente:

```text
app/Core/Application.php
app/Modules/Fulfillment/Orchestration/DurableCompletionOrchestration.php
app/Modules/Fulfillment/Orchestration/DurableCompletionWorkers.php
tests/manual/durable-completion-orchestration-test.php
tests/manual/support/durable-retry-a11-coordinator.php
tests/manual/support/durable-retry-a11-runtime-capture-contract.php
tests/manual/durable-retry-a11-runtime-capture-test.php
tests/manual/durable-retry-a11-runtime-capture-infrastructure-test.php
```

Los 13 harnesses de mantenimiento no son paths A11, fixtures, Runtime Capture ni una ampliación de este conjunto.

## 6. Definición del lote de 13 harnesses

`A11_HISTORICAL_MAINTENANCE_PATHS`, en orden canónico:

```text
tests/manual/durable-retry-action-callback-infrastructure-test.php
tests/manual/durable-retry-action-hook-registrar-infrastructure-test.php
tests/manual/durable-retry-business-completion-processor-infrastructure-test.php
tests/manual/durable-retry-composition-infrastructure-test.php
tests/manual/durable-retry-delivery-completion-processor-infrastructure-test.php
tests/manual/durable-retry-executor-infrastructure-test.php
tests/manual/durable-retry-external-scheduler-infrastructure-test.php
tests/manual/durable-retry-initial-authority-producer-infrastructure-test.php
tests/manual/durable-retry-initial-transfer-authority-infrastructure-test.php
tests/manual/durable-retry-next-generation-infrastructure-test.php
tests/manual/durable-retry-processing-nullable-attempt-infrastructure-test.php
tests/manual/durable-retry-production-composition-infrastructure-test.php
tests/manual/durable-retry-reconciliation-processor-infrastructure-test.php
```

Exactamente 13 archivos bajo `tests/manual/`; ningún producto, support, documento, fixture, manifest, wildcard, directorio o path número 14.

## 7. Tres conjuntos normativos separados

1. `HISTORICAL_HARNESS_PATHS`: inventario/allowlist propia previa de cada harness; permanece semánticamente intacta.
2. `A11_LOCAL_COEXISTENCE_PATHS`: ocho cambios externos A11 autorizados; identidad permanente dentro de esta certificación.
3. `A11_HISTORICAL_MAINTENANCE_PATHS`: trece archivos modificados conjuntamente para mantener guardias; identidad contextual y temporal.

Un mismo harness puede estar en su allowlist propia y en el lote. La duplicación se elimina determinísticamente sin mezclar identidades.

## 8. Regla de unión corregida

Durante el microhito posterior:

```text
ALLOWED_PATHS = HISTORICAL_HARNESS_PATHS
              ∪ A11_LOCAL_COEXISTENCE_PATHS
              ∪ A11_HISTORICAL_MAINTENANCE_PATHS
```

La unión es exacta. Ningún otro harness recibe esta regla automáticamente; solo los 13 pueden implementarla. Autorizar mantenimiento no convierte sus paths en A11. Todo path desconocido permanece rechazado.

## 9. Justificación de atomicidad

Las guardias inspeccionan el árbol Git global. Un harness observa cambios ya efectuados en otros harnesses del mismo microhito; editarlos secuencialmente no cambia el estado final visible. Revertir cada archivo después de probarlo impide producir el lote final. Por ello los 13 constituyen una transacción atómica de mantenimiento.

La autorización cruzada permite verificar el lote; no debilita assertions funcionales ni autoriza que cada harness se limite a reconocerse a sí mismo.

## 10. Alcance temporal

El lote se autoriza exclusivamente para implementación posterior, validación individual, adversariales, regresión completa y certificación previa a commit. Conceptualmente deja de ser una excepción activa una vez versionado el microhito, aunque la lógica resultante conserve los tres conjuntos literales para validar un worktree compatible.

No se aplica a futuros cambios, otros microhitos, fixtures, WR-06, producto, documentos, refactors, nuevos archivos, eliminaciones ni helpers externos.

## 11. Semántica dentro de cada harness

Cada harness conserva su allowlist, archivos propios, assertions, arquitectura, aislamiento, exits y errores materiales. Añade separadamente las listas de ocho y trece. La única diferencia es que la guardia resta coincidencias exactas autorizadas antes de afirmar que quedan cambios desconocidos.

No se permiten skips, early returns, supresión de stderr, reducción de assertions ni autorización por directorio.

## 12. Normalización y comparación

- paths relativos al root y separador canónico `/`;
- reemplazo previo exacto de `\` por `/`;
- comparación sensible a casing del path completo;
- orden canónico de secciones 5 y 6;
- deduplicación estable/determinista;
- rechazo de absolutos, `.`, `..`, sufijos, prefijos, parciales y case variants;
- sin globs, regex permisivas ni `str_starts_with()` como autorización.

## 13. Matriz individual 1–7

| Path | Hash base restaurado | Allowlist/inspección propia | A11 rechazado | Lote observado | Cambio mínimo | Riesgo FP/FN | Veredicto |
|---|---|---|---|---|---|---|---|
| `tests/manual/durable-retry-action-callback-infrastructure-test.php` | `12A73288F82F4F63657F66DE33742C7365288C83004EDDA22E815E2D9C34DF05` | restricted diff | Orchestration 2-3 | potencialmente 13 | unión exacta tras diff | bajo/bajo | LOTE DE MANTENIMIENTO AUTORIZABLE |
| `tests/manual/durable-retry-action-hook-registrar-infrastructure-test.php` | `0C85615D5868865CCD762DB8FB1B7B9ABD823F1207F02DD9C47CD53F65A80411` | restricted diff | Orchestration 2-3 | 13 | igual | bajo/bajo | LOTE DE MANTENIMIENTO AUTORIZABLE |
| `tests/manual/durable-retry-business-completion-processor-infrastructure-test.php` | `F97DD186D8E74709FD55BAE3984B5171869B4C246F0D2053568161BA78D3BFCC` | restricted diff | Orchestration 2-3 | 13 | igual, preservar schema | bajo/bajo | LOTE DE MANTENIMIENTO AUTORIZABLE |
| `tests/manual/durable-retry-composition-infrastructure-test.php` | `8B3D89B1E35C895FB824D53DE3F59B61B0B9F5D38E1757A6807D3FF372E91823` | forbidden-path diff | Orchestration 2-3 | 13 | unión literal | bajo/bajo | LOTE DE MANTENIMIENTO AUTORIZABLE |
| `tests/manual/durable-retry-delivery-completion-processor-infrastructure-test.php` | `E48A3D80230FD726DCAFD29FE68852797EE2F7DC3B48D8A2E856728FC84A54F2` | restricted diff | Orchestration 2-3 | 13 | unión literal | bajo/bajo | LOTE DE MANTENIMIENTO AUTORIZABLE |
| `tests/manual/durable-retry-executor-infrastructure-test.php` | `DED6062E5C13F4066D504A6682595A76BEA1CB312913E3BA21D7DBDF5D1A4A42` | restricted diff | Orchestration 2-3 | 13 | unión literal | bajo/bajo | LOTE DE MANTENIMIENTO AUTORIZABLE |
| `tests/manual/durable-retry-external-scheduler-infrastructure-test.php` | `D42F593B15EB63B452F16BC9E3EABB27A84D4F964CC336BAD8258382ED0C413E` | restricted diff | Orchestration 2-3 | 13 | unión literal | bajo/bajo | LOTE DE MANTENIMIENTO AUTORIZABLE |

## 14. Matriz individual 8–13

| Path | Hash base restaurado | Allowlist/inspección propia | A11 rechazado | Lote observado | Cambio mínimo | Riesgo FP/FN | Veredicto |
|---|---|---|---|---|---|---|---|
| `tests/manual/durable-retry-initial-authority-producer-infrastructure-test.php` | `6AA57A15889A1CBC3FB66190BD6E9E86E2C0E398D7D39F7CDE98AB97BBF4FF75` | maintenance allowlist + diff global | tracked 1-4 | todos los tracked del lote | tres conjuntos exactos | medio/bajo | LOTE DE MANTENIMIENTO AUTORIZABLE |
| `tests/manual/durable-retry-initial-transfer-authority-infrastructure-test.php` | `7511019293DD9D9CE71D8F4B9F4AA0D965CCF1DC1D1EAA858A058EDFC9222ADC` | maintenance allowlist + diff global | tracked 1-4 | todos los tracked del lote | tres conjuntos exactos | medio/bajo | LOTE DE MANTENIMIENTO AUTORIZABLE |
| `tests/manual/durable-retry-next-generation-infrastructure-test.php` | `B0FD56C300E5313BAA2BD5AB249CF6B63302FE6E9A09BD98AA45E6B898A89593` | restricted diff + excepción propia Executor | Orchestration 2-3 | 13 | conservar excepción y unir lote | medio/bajo | LOTE DE MANTENIMIENTO AUTORIZABLE |
| `tests/manual/durable-retry-processing-nullable-attempt-infrastructure-test.php` | `C43221B1F9ADB649A37F02270A431AA9ECAF808C36E1F1804427E69B2D5D2FF3` | restricted diff + excepción propia Executor | Orchestration 2-3 | 13 | conservar assertions derivadas | medio/bajo | LOTE DE MANTENIMIENTO AUTORIZABLE |
| `tests/manual/durable-retry-production-composition-infrastructure-test.php` | `E7FAD01AE795D907D8043E92FAB25B44B292C192F332AF5C318B7C10B4AF85D9` | status global + A9 own allowlist | ocho A11 | los otros doce harnesses | unión de tres conjuntos sin alterar expected A9 | alto/bajo | LOTE DE MANTENIMIENTO AUTORIZABLE |
| `tests/manual/durable-retry-reconciliation-processor-infrastructure-test.php` | `10D04B7585BD7D3FCC515E73BAFF9C4D8073E021FBDBB09C9AA9D7CF5B7C95DF` | worker singular + restricted diff | Workers | 13 | unión exacta en ambas guardias | medio/bajo | LOTE DE MANTENIMIENTO AUTORIZABLE |

“A11 1-4” y “Orchestration 2-3” remiten exclusivamente al orden de la sección 5.

## 15. Análisis especial de los tres bloqueantes

Initial authority producer y initial transfer authority inspeccionan todos los cambios tracked. En R3 provisional aceptaron los cuatro tracked A11, pero rechazaron harnesses R3 fuera de sus maintenance allowlists. Deben unir los 13 solo para la evaluación de cambios inesperados; sus inventarios propios permanecen intactos.

Production composition inspecciona status tracked y untracked bajo `app/` y `tests/`. Debe mantener `$allowlist` A9 y `$expectedChanged` sin alteración, y usar la unión de 21 paths solo al decidir si hay product/test paths desconocidos. Así no convierte R3 ni A11 en A9.

## 16. Pruebas adversariales futuras

La implementación deberá aceptar: ocho A11, trece maintenance y la unión completa de 21. Deberá rechazar: path 22, harness histórico fuera del lote, vecino de directorio, nombre similar, prefijo, sufijo, producto adicional, documento adicional cuando sea observable y variantes de casing.

La normalización `\`/`/` no cambia cardinalidad. Las simulaciones serán efímeras y no crearán permanentemente archivos adversariales.

## 17. Allowlist máxima

Durante el microhito posterior el universo extraordinario máximo es 21 paths: ocho coexistentes A11 más trece de mantenimiento. A ellos se suma, por harness, solo su allowlist histórica propia, que no es una ampliación global y conserva su significado previo.

Un path número 22 queda prohibido. Los 13 no se convierten en paths A11.

## 18. Exclusiones

No se autorizan producto, support externo, scheduler real, documentos, fixtures, manifests, schema, configuración, artifacts, `.a11-runtime`, helpers compartidos, archivos nuevos ni eliminaciones. No se materializa WR-06 ni se asignan IDs/expected actions.

## 19. Riesgos

Falso positivo: usar prefijo/directorio o mezclar listas y admitir un vecino. Mitigación: literales y `array_diff` exacto tras normalizar. Falso negativo: status Windows, CRLF o duplicación del propio harness. Mitigación: parseo correcto del path, normalización de separador y deduplicación determinista.

Riesgo semántico: confundir maintenance con inventario propio. Mitigación: mantener tres variables/listas separadas y usar la unión solo en guardias de worktree.

## 20. Criterios de implementación

- exactamente 13 harnesses modificados, cero archivos nuevos/eliminados;
- listas literales separadas de ocho y trece;
- allowlists históricas textualmente/semánticamente intactas;
- `php -l` e individual 13/13;
- adversariales de 21 aceptados/path 22 rechazado;
- A11 protegido, DurableCompletion y scheduler real verdes;
- regresión completa 78/78 con timeout externo 30 segundos.

## 21. Criterios de certificación y bloqueo

Certificación exige 78 pass, cero fallos/timeouts/warnings PHP/notices/deprecations, hashes A11/R2 intactos, staging vacío, diff limitado a 13 y cero residuos. Debe bloquearse si falta autoridad, se requiere archivo 14, producto, A11 > 8, lote > 13, path 22, wildcard, pérdida de assertion o regresión funcional.

## 22. Integridad final

Solo se creó este documento. No se modificaron R2, los ocho paths A11 ni los 13 harnesses. Rama, HEAD, divergencia, staging, `artifacts/`, guardias de runtime y hashes restaurados permanecieron invariantes. No hubo commit ni push.

## 23. Veredicto final

Los 13 paths tienen autoridad individual y constituyen un lote atómico cerrado. La regla de tres conjuntos resuelve la contradicción sin ampliar los ocho paths A11 ni debilitar las allowlists históricas.

```text
A11 LOTE DE MANTENIMIENTO HISTÓRICO IMPLEMENTABLE TRAS CORRECCIÓN NORMATIVA
```
