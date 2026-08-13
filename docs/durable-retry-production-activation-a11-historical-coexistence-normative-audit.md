# Auditoría normativa de coexistencia histórica del árbol local A11

Estado: auditoría exclusivamente documental y fail-closed. Fecha: 2026-08-03.

Veredicto: los ocho paths auditados cuentan con autoridad independiente y pueden reconocerse como coexistencia externa mediante comparación exacta, sin alterar el alcance funcional de los harnesses históricos.

## 1. Estado base

La auditoría se inició sobre `main`, HEAD `847879d509864c6ad077bfecb6dfa05537fbb899`, divergencia `0 behind / 61 ahead`, staging vacío y `git diff --check` satisfactorio. `.git/index.lock` y `.a11-runtime` estaban ausentes; no había procesos PHP residuales ni manifest runtime persistente; `artifacts/` contenía 504 archivos.

WR-06 no estaba materializado, no había IDs de casos asignados y no se realizó commit ni push. El accessor tipado A11 aparecía exactamente una vez.

## 2. Alcance y exclusiones

El objeto exclusivo son cuatro cambios tracked preexistentes y cuatro archivos Runtime Capture untracked. No se auditan ni autorizan documentos, fixtures futuros, manifests, temporales, artifacts, directorios completos ni otros paths.

Esta auditoría no modifica código productivo, harnesses, support files ni los ocho paths. Tampoco normaliza la regresión: determina si una corrección futura de las 13 guardias es normativamente posible.

## 3. Inventario canónico de ocho paths

Orden canónico obligatorio de `A11_LOCAL_COEXISTENCE_PATHS`:

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

Los cuatro primeros son tracked modificados. Los cuatro últimos son untracked en el árbol actual. Esa diferencia de estado Git no altera su autoridad documental ni permite agregar un noveno path.

## 4. Autoridad documental de los cuatro paths tracked

| Path | Autoridad independiente | Resultado |
|---|---|---|
| `app/Core/Application.php` | `docs/durable-retry-production-activation-a11-complementary-normative-correction.md:3-16`; allowlist definitiva de doce rutas | autorizable |
| `app/Modules/Fulfillment/Orchestration/DurableCompletionOrchestration.php` | mismo documento `:10-16`; además contrato principal A11 `:407-415` | autorizable |
| `app/Modules/Fulfillment/Orchestration/DurableCompletionWorkers.php` | mismo documento `:10-16`; contrato principal A11 `:407-415` | autorizable |
| `tests/manual/durable-completion-orchestration-test.php` | contrato principal `docs/durable-retry-production-activation-a11-normative-correction.md:139` y allowlist `:407-415`; complementaria `:10-16` | autorizable |

La corrección de bootstrap confirma en `docs/durable-retry-production-activation-a11-bootstrap-path-normative-correction.md:146-150` que la allowlist sigue compuesta por cuatro modificables y ocho nuevos. Por ello estos cambios no son meramente “presentes”: forman parte integral y protegida de A11.

## 5. Autoridad documental de los cuatro paths Runtime Capture

| Path | Autoridad independiente | Resultado |
|---|---|---|
| `tests/manual/support/durable-retry-a11-coordinator.php` | corrección runtime `docs/durable-retry-production-activation-a11-runtime-capture-transport-normative-correction.md:422` | autorizable como modificado |
| `tests/manual/support/durable-retry-a11-runtime-capture-contract.php` | corrección runtime `:423` | autorizable como nuevo |
| `tests/manual/durable-retry-a11-runtime-capture-test.php` | corrección runtime `:424` | autorizable como nuevo |
| `tests/manual/durable-retry-a11-runtime-capture-infrastructure-test.php` | corrección runtime `:425` | autorizable como nuevo |

La misma corrección limita dependencias y prohíbe producto, fixtures, JSON, manifests, schema, configuración, artifacts y `.a11-runtime` (`:427-429`). La coexistencia no amplía esa autorización.

## 6. Anatomía de las allowlists históricas

Existen tres patrones:

1. Guardias de paths restringidos: ejecutan `git diff --name-only HEAD -- <paths>` y exigen resultado vacío. Protegen áreas ajenas al microhito histórico.
2. Guardias de mantenimiento tracked: comparan todo `git diff --name-only` con una lista cerrada de mantenimiento.
3. Guardias de estado tracked/untracked: analizan `git status --short --untracked-files=all` y exigen que cambios de `app/` o `tests/` pertenezcan a su allowlist propia.

Ningún patrón distingue hoy “cambio propio” de “coexistencia externa A11”. La corrección futura debe conservar la allowlist original y sustraer únicamente coincidencias exactas de `A11_LOCAL_COEXISTENCE_PATHS` antes de afirmar que no quedan cambios ajenos.

## 7. Matriz individual de harnesses 1–7

| Harness | Primera condición fallida | Rechazados | Allowlist/guardia original | Coexistencia actual → necesaria | Riesgo y cambio mínimo futuro | Veredicto |
|---|---|---|---|---|---|---|
| `durable-retry-action-callback-infrastructure-test.php` | `restricted paths unchanged` | dos Orchestration tracked | `$restricted`, líneas 51-68 | ninguna → paths 2-3 | bajo; filtrar coincidencias exactas después del diff | COEXISTENCIA NORMATIVAMENTE AUTORIZABLE |
| `durable-retry-action-hook-registrar-infrastructure-test.php` | `restricted paths unchanged` | paths 2-3 | `$restricted`, `:51-64` | ninguna → 2-3 | bajo; mismo filtro exacto | COEXISTENCIA NORMATIVAMENTE AUTORIZABLE |
| `durable-retry-business-completion-processor-infrastructure-test.php` | `restricted certified paths remain unchanged` | paths 2-3 | `$restricted`, `:84-105` | ninguna → 2-3 | bajo; no cambiar assertions funcionales | COEXISTENCIA NORMATIVAMENTE AUTORIZABLE |
| `durable-retry-composition-infrastructure-test.php` | `forbidden paths unchanged` | paths 2-3 | `$forbiddenPaths`, `:66-85` | ninguna → 2-3 | bajo; exclusión exacta posterior al diff | COEXISTENCIA NORMATIVAMENTE AUTORIZABLE |
| `durable-retry-delivery-completion-processor-infrastructure-test.php` | `restricted certified paths remain unchanged` | paths 2-3 | `$restricted`, `:84-105` | ninguna → 2-3 | bajo; preservar schema y aislamiento | COEXISTENCIA NORMATIVAMENTE AUTORIZABLE |
| `durable-retry-executor-infrastructure-test.php` | `restricted certified paths remain unchanged` | paths 2-3 | `$restricted`, `:71-85` | ninguna → 2-3 | bajo; filtro exacto, sin directorios | COEXISTENCIA NORMATIVAMENTE AUTORIZABLE |
| `durable-retry-external-scheduler-infrastructure-test.php` | `restricted certified paths remain unchanged` | paths 2-3 | `$restricted`, `:63-82` | ninguna → 2-3 | bajo; no tocar integración real | COEXISTENCIA NORMATIVAMENTE AUTORIZABLE |

“Paths 2-3” remite exclusivamente a los dos paths de Orchestration numerados en la sección 3, no a su directorio.

## 8. Matriz individual de harnesses 8–13

| Harness | Primera condición fallida | Rechazados | Allowlist/guardia original | Coexistencia actual → necesaria | Riesgo y cambio mínimo futuro | Veredicto |
|---|---|---|---|---|---|---|
| `durable-retry-initial-authority-producer-infrastructure-test.php` | `Tracked changes must remain inside the maintenance allowlist` | tracked paths 1-4 | `$maintenanceAllowlist`, `:53-65` | ninguna → 1-4 | medio; unión exacta sin aceptar untracked indiscriminadamente | COEXISTENCIA NORMATIVAMENTE AUTORIZABLE |
| `durable-retry-initial-transfer-authority-infrastructure-test.php` | `Tracked changes must remain inside the infrastructure maintenance allowlist` | tracked paths 1-4 | `$maintenanceAllowlist`, `:45-61` | ninguna → 1-4 | medio; preservar staging/diff checks | COEXISTENCIA NORMATIVAMENTE AUTORIZABLE |
| `durable-retry-next-generation-infrastructure-test.php` | `restricted certified paths remain unchanged` | paths 2-3 | `$restricted` y excepción Executor, `:129-155` | excepción histórica propia → 2-3 adicionales | medio; no ensanchar excepción Executor | COEXISTENCIA NORMATIVAMENTE AUTORIZABLE |
| `durable-retry-processing-nullable-attempt-infrastructure-test.php` | `restricted certified paths remain unchanged` | paths 2-3 | `$restricted` y excepción Executor, `:81-107` | excepción histórica propia → 2-3 adicionales | medio; conservar assertions derivadas de `$diff` | COEXISTENCIA NORMATIVAMENTE AUTORIZABLE |
| `durable-retry-production-composition-infrastructure-test.php` | `changed product/test paths stay in A9 allowlist` | tracked 1-4 y runtime 5-8 | `$allowlist`, `:18-24`; status `:87-115` | ninguna → ocho paths | alto; separar coexistencia de inventario A9 sin alterar expected A9 | COEXISTENCIA NORMATIVAMENTE AUTORIZABLE |
| `durable-retry-reconciliation-processor-infrastructure-test.php` | `legacy worker unchanged` | path 3 | guardia singular `:131-137`; restringidos `:160-181` | ninguna → path 3 (y 2 si aparece en guardia posterior) | medio; filtrar solo path exacto, conservar SQL/schema guards | COEXISTENCIA NORMATIVAMENTE AUTORIZABLE |

Los 13 fallos ocurren antes de la salida de assertions. No hay evidencia de fallo funcional ni autoridad que obligue a tratarlos como regresión del microhito histórico.

## 9. Modelo exacto de coexistencia

Modelo conceptual futuro:

```text
allowed_changes = HISTORICAL_OWN_ALLOWLIST ∪ A11_LOCAL_COEXISTENCE_PATHS
unexpected_changes = observed_changes - allowed_changes
pass_guard ⇔ git_command_succeeded ∧ unexpected_changes = []
```

La coexistencia A11 es externa: no convierte los ocho paths en producto del microhito histórico, no altera su inventario esperado y no satisface assertions que exijan la presencia de archivos propios. Solo evita que cambios A11 ya autorizados sean clasificados como ajenos.

## 10. Orden, normalización y comparación

- Orden canónico: exactamente el de la sección 3.
- Separadores: reemplazar `\` por `/` antes de comparar.
- Comparación: igualdad estricta, sensible a mayúsculas, del path completo relativo al root.
- Prohibidos: prefijos, `str_starts_with` para autorización, globs, regex de directorio y comodines.
- No se autoriza `app/Modules/Fulfillment/Orchestration` ni `tests/manual`; solo archivos completos enumerados.
- Duplicados y paths no normalizables hacen fallar la guardia.

## 11. Reglas para tracked y untracked

Los cuatro tracked se comparan contra el resultado normalizado de `git diff --name-only` y, cuando corresponda, `git status --short`. Los cuatro runtime untracked solo aparecen con `git status --short --untracked-files=all` o `git ls-files --others --exclude-standard`.

Una guardia que solo examina tracked no debe comenzar a ignorar todo untracked. Una guardia de status debe reconocer exactamente paths 5-8 y seguir rechazando cualquier quinto archivo untracked en `app/` o `tests/`. Staging continúa obligado a vacío.

## 12. Reglas para producto y tests

Los tres paths productivos 1-3 tienen autorización A11 explícita; reconocerlos no autoriza otros archivos productivos ni permite cambios futuros en el mismo directorio. Los cinco paths de prueba 4-8 tampoco autorizan otros harnesses, fixtures o support files.

Las verificaciones arquitectónicas, funcionales, de SQL, schema, hooks, reflection, aislamiento, staging y residuos se conservan sin cambios. No se eliminan assertions, no se convierten fallos en skips y no se acepta por extensión ningún path que contenga `A11`.

## 13. Riesgos de falsos positivos

El principal riesgo es permitir un archivo vecino mediante prefijo/directorio, o excluir todos los cambios A11 por patrón nominal. Se evita con ocho literales, key set exacto y resta posterior al inventario observado. Otro riesgo es confundir coexistencia con el inventario propio y hacer pasar una prueba aunque falte un archivo histórico; se evita manteniendo separadas ambas listas.

## 14. Riesgos de falsos negativos

Separadores Windows, CRLF emitido por Git, estados `??` y espacios del formato short pueden impedir una coincidencia legítima. La futura corrección debe parsear los tres primeros caracteres de status, normalizar solo separadores y filtrar líneas `warning:` fuera del conjunto de paths. No debe normalizar casing ni resolver aliases del filesystem.

## 15. Scheduler real como antecedente cerrado

Tres repeticiones aisladas terminaron con 14 assertions, exit 0 y stderr vacío:

| Run | Duración | Última salida/assertion | Intervalo hasta exit | Hijos |
|---:|---:|---:|---:|---:|
| 1 | 2.727 s | 1.684 s | 1.043 s | 0 |
| 2 | 1.643 s | 1.612 s | 0.031 s | 0 |
| 3 | 1.651 s | 1.621 s | 0.030 s | 0 |

No quedaron PHP residuales ni recursos pendientes reproducibles. Diagnóstico admisible: demora transitoria previa no reproducida. No se propone aumentar 30 segundos ni modificar `durable-retry-external-scheduler-real-test.php` sin nueva evidencia.

## 16. Allowlist futura máxima y plan de mantenimiento

La futura implementación puede modificar exactamente estos 13 harnesses, y ningún otro archivo, para incorporar localmente la constante/lista literal de ocho paths. No se crea helper compartido.

Cada cambio debe:

1. conservar su allowlist histórica original;
2. declarar los ocho paths completos o el subconjunto exacto que su mecanismo puede observar, sin autorizar un noveno;
3. restar coexistencia antes de evaluar cambios inesperados;
4. ejecutar `php -l` y el harness individual;
5. preservar el número y contenido de assertions funcionales;
6. terminar con regresión 78/78, cero timeout y guardias finales.

Si un harness observa los ocho mediante status, debe declarar los ocho. Si su comando restringido solo puede devolver uno o dos, puede comparar contra ese subconjunto, siempre derivado literalmente del catálogo y sin directorios.

## 17. Criterios de certificación y condiciones de bloqueo

Una implementación posterior será certificable solo con 78/78 scripts verdes, A11 Runtime Capture verde, DurableCompletion histórico verde, scheduler real bajo 30 segundos, cero procesos/residuos, staging vacío y diff limitado a los 13 harnesses autorizados.

Debe bloquearse si aparece un noveno path, se necesita producto/documentación/helper, cambia una assertion, se usa wildcard/prefijo, una guardia funcional falla después del mantenimiento, el scheduler vuelve a exceder 30 segundos o la unión exacta no conserva el aislamiento histórico.

## 18. Integridad final de esta auditoría

Solo se creó este documento. Los ocho paths auditados y los 13 harnesses permanecieron sin modificación durante A11-R2. Rama, HEAD y divergencia no cambiaron; staging siguió vacío; `artifacts/` permaneció en 504; `.a11-runtime`, `.git/index.lock`, manifest runtime, temporales A11 y procesos PHP permanecieron ausentes.

No se materializó WR-06, no se asignaron IDs, no hubo commit ni push.

## 19. Veredicto final

Los ocho paths tienen autoridad suficiente, trazable e independiente. La coexistencia puede implementarse como una excepción externa cerrada, separada de cada allowlist histórica y sin debilitar ninguna verificación funcional.

```text
A11 COEXISTENCIA HISTÓRICA IMPLEMENTABLE TRAS CORRECCIÓN NORMATIVA
```
