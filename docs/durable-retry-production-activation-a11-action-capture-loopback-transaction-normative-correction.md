# Undécima corrección normativa A11: transacción loopback sin capture delta

## 1. Veredicto

`A11 ACTION CAPTURE LOOPBACK TRANSACTION IMPLEMENTABLE TRAS UNDÉCIMA CORRECCIÓN NORMATIVA`
## 2. Alcance y decisión humana

Esta corrección resuelve exclusivamente la ausencia de fuente normativa para un `capture_delta` loopback. Elimina ese parámetro de la integración loopback y no implementa EA6 ni modifica autoridades, PHP, harnesses, child, stub, listeners o protocolos.

## 3. Precedencia limitada

Esta corrección sustituye únicamente estas disposiciones de la décima corrección:

- sección 10, firma de `integrateLoopbackBundle()`;
- sección 11, definición del loopback bundle;
- sección 12, referencia a snapshots resultantes de “ambos deltas” en loopback;
- sección 13, ejemplo de llamada loopback;
- sección 18, pasos que validaban o aplicaban capture delta loopback;
- sección 25, firma loopback anterior;
- filas adversariales dependientes de capture delta loopback.

Permanecen vigentes sin cambio la clase, phase, estado de nueve campos, pureza, preparación, commit, rollback, excepciones, relación EA5 y toda obligación no contradictoria. No se modifica transporte.

## 4. Clase y firmas definitivas

```php
namespace VeciAhorra\Tests\Manual\A11;

final class DurableRetryA11ActionCaptureTransaction
{
    public static function integratePhaseBundle(
        array $currentState,
        array $captureDelta,
        array $actionDeltas,
        array $operationResult
    ): array;

    public static function integrateLoopbackBundle(
        array $currentState,
        array $actionDeltas,
        array $loopbackResult
    ): array;
}
```

Phase tiene cuatro parámetros; loopback tiene exactamente tres. No existen opcionales, variadic, referencias, overloads o cuarto parámetro loopback. Pasar capture delta, `null` o array vacío como argumento adicional está prohibido.

## 5. Parser y descomposición

Phase valida `phase_result`, extrae `capture_delta`, `action_deltas` y `operation_result`, y llama `integratePhaseBundle($currentState, $captureDelta, $actionDeltas, $operationResult)`.

Loopback valida el `loopback_result` completo, extrae de él `action_deltas`, conserva el mismo objeto completo como `$loopbackResult`, y llama `integrateLoopbackBundle($currentState, $actionDeltas, $loopbackResult)`.

El parser loopback no busca, produce, sintetiza ni reutiliza capture delta; no consulta snapshots externos ni fabrica auxiliares.

## 6. Estado combinado de nueve campos

```php
array{
    schema: string,
    execution_id: string,
    invocation_id: string,
    capture_snapshot: array,
    action_snapshot: array,
    phase_integrated: bool,
    operation_result: ?array,
    loopback_integrations: int,
    last_loopback_result: ?array
}
```

`schema` es `veciahorra-a11-action-capture-transaction-state/v1`. El coordinator es el único propietario; el estado permanece en memoria y no cruza procesos.

## 7. Matriz de campos

| Campo | Phase puede modificar | Input phase | Loopback puede modificar | Input loopback | Regla cuando se preserva |
|---|:---:|---|:---:|---|---|
| `schema` | no | ninguno | no | ninguno | `===` |
| `execution_id` | no | ninguno | no | ninguno | `===` |
| `invocation_id` | no | ninguno | no | ninguno | `===` |
| `capture_snapshot` | sí | `captureDelta` válido | no | ninguno | loopback: `===` |
| `action_snapshot` | sí | `actionDeltas` válidos | sí | `actionDeltas` válidos | `===` si lista vacía |
| `phase_integrated` | sí, false→true | commit phase | no | ninguno | loopback: `===` |
| `operation_result` | sí | `operationResult` | no | ninguno | loopback: `===` |
| `loopback_integrations` | no | ninguno | sí, 0→1 | bundle loopback válido | phase: `===` |
| `last_loopback_result` | no | ninguno | sí, null→result | `loopbackResult` válido | phase: `===` |

No existen otros campos ni normalización.

## 8. Conservación runtime capture en loopback

Loopback no integra runtime capture. `schema`, `execution_id`, `invocation_id`, `capture_snapshot`, `phase_integrated` y `operation_result` del retorno deben ser estrictamente idénticos mediante PHP `===` a los del `$currentState`.

En particular, `capture_snapshot` conserva estructura, valores, tipos, orden de inserción observable y hashes ya contenidos. La ausencia de capture delta no es delta vacío, operación capture, avance de snapshot, cambio de hash o autorización de normalización.

## 9. Algoritmo phase preservado

Phase conserva exactamente inputs, orden, reasons, cardinalidad, duplicados, preparación, atomicidad, commit y rollback de la décima corrección. Integra atómicamente `captureDelta + actionDeltas + operationResult`.

## 10. Orden total phase

1. Shape y schema de `$currentState`.
2. `execution_id`.
3. `invocation_id`.
4. Shape de `$captureDelta`.
5. Shape y naturaleza de lista de `$actionDeltas`.
6. Cada action delta.
7. Orden de actions.
8. Duplicados.
9. Binding de deltas con invocation.
10. Shape de `$operationResult`.
11. `phase_integrated === false`.
12. Capture candidato.
13. Actions candidatas.
14. Validación cruzada.
15. Retorno completo.

Ante múltiples defectos sólo se emite el primero.

## 11. Algoritmo loopback normativo

```php
// Pseudocódigo normativo; no es implementación.
validateTransactionState($currentState);
validateExecutionId($currentState['execution_id']);
validateInvocationId($currentState['invocation_id']);
validateActionDeltaList($actionDeltas);
validateEachActionDelta($actionDeltas);
validateActionOrder($actionDeltas);
validateInternalActionDuplicates($actionDeltas);
validateActionBindings($currentState, $actionDeltas);
validateLoopbackResult($currentState, $actionDeltas, $loopbackResult);
validatePreviousLoopback($currentState, $loopbackResult);
validateLoopbackCardinality($currentState);

$candidateActionSnapshot = calculateActionSnapshot(
    $currentState['action_snapshot'],
    $actionDeltas
);
$candidateState = [
    'schema' => $currentState['schema'],
    'execution_id' => $currentState['execution_id'],
    'invocation_id' => $currentState['invocation_id'],
    'capture_snapshot' => $currentState['capture_snapshot'],
    'action_snapshot' => $candidateActionSnapshot,
    'phase_integrated' => $currentState['phase_integrated'],
    'operation_result' => $currentState['operation_result'],
    'loopback_integrations' => 1,
    'last_loopback_result' => $loopbackResult,
];
validateCombinedLoopbackCandidate($currentState, $candidateState);
assert($candidateState['capture_snapshot'] === $currentState['capture_snapshot']);
return $candidateState;
```

La asignación autoritativa ocurre únicamente fuera del método.

## 12. Orden total loopback

1. Shape/schema/invariantes del estado.
2. `execution_id`.
3. `invocation_id`.
4. `$actionDeltas` es lista con cardinalidad 0..4096.
5. Validación individual en orden.
6. Orden de cadena.
7. Duplicados internos.
8. Binding de cada action con execution/invocation/case/phase normativos.
9. Shape, hash, key set y coherencia de `$loopbackResult`.
10. Duplicado previo por `result_hash`.
11. Cardinalidad previa: `loopback_integrations === 0`.
12. Preparación completa del action snapshot candidato.
13. Reconstrucción de nueve campos.
14. Validación cruzada y conservación estricta.
15. Retorno del candidato.

No se aplican candidatos antes del paso 12. Ante defectos simultáneos prevalece el primer paso fallido y se emite un solo reason.

## 13. Commit y rollback

El único commit autorizado es:

```php
$candidateState = DurableRetryA11ActionCaptureTransaction::integrateLoopbackBundle(
    $currentState,
    $actionDeltas,
    $loopbackResult
);
$currentState = $candidateState;
```

Antes de la última asignación no cambia estado, contador, hash, snapshot, action o result. PHP arrays se reciben por valor y se prohíben referencias. Ante excepción no hay retorno ni asignación: ése es el rollback completo, sin compensación.

## 14. Catálogo final de reasons

| Reason | Método | Validación | Resultado | Commit | Precedencia |
|---|---|---|---|:---:|---:|
| Reasons superiores de schema/tipo/gramática | ambos | subobjeto individual | excepción, sin retorno | no | paso específico 1–10 |
| Reasons superiores de catálogo/ID/binding | ambos | identidad o binding | excepción, sin retorno | no | antes de candidatos |
| Reasons superiores de capture delta | phase | paso 4 | excepción, sin retorno | no | 4 |
| Reasons superiores de action delta/orden/duplicado | ambos | lista/entry/cadena | excepción, sin retorno | no | phase 5–9; loopback 4–8 |
| Reasons superiores de operation result | phase | paso 10 | excepción, sin retorno | no | 10 |
| Reasons superiores de loopback result | loopback | paso 9 | excepción, sin retorno | no | 9 |
| `action_capture_transaction_state_invalid` | ambos | estado inválido | `InvalidArgumentException` | no | 1 |
| `phase_bundle_already_integrated` | phase | phase ya true | `InvalidArgumentException` | no | 11 |
| `phase_bundle_transaction_invalid` | phase | combinación inconsistente | `InvalidArgumentException` | no | 14 |
| `loopback_bundle_transaction_invalid` | loopback | combinación o segundo result distinto | `InvalidArgumentException` | no | 11 o 14 |
| `loopback_bundle_duplicate` | loopback | mismo `result_hash` previo | `InvalidArgumentException` | no | 10 |

Los cinco reasons de la décima corrección permanecen; ninguno recibe alias.

## 15. Cardinalidad

Phase: `actionDeltas` contiene 0..4096 entries y phase integra con éxito 0..1 veces por invocation. Lista vacía es válida. Segunda phase es `phase_bundle_already_integrated`.

Loopback: `actionDeltas` contiene 0..4096 entries; lista vacía sólo es válida cuando `requests_observed=0`, `observations=[]` y el result lo permite. `loopback_integrations` pertenece a 0..1 por invocation porque existe un único stub y un único `loopback_result` final. Enteros acumulados de action no exceden 2147483647.

Lista/shape se valida antes de entries; entries antes de orden; orden antes de duplicados; duplicados antes de binding; result antes de cardinalidad previa.

## 16. Duplicados

Identidad de action delta: JSON canónico completo del objeto con sus keys exactas, comparado byte por byte. Dos cruces legítimos del mismo puerto con distinto `base_action_hash` no son duplicados. Dos objetos canónicos idénticos dentro del bundle producen el reason superior `double_capture_detected` y descartan todo.

Identidad loopback: `result_hash` lowercase de 64 hex validado contra el objeto. Si coincide con `last_loopback_result.result_hash`, se emite `loopback_bundle_duplicate`. Si ya existe una integración pero el hash difiere, se emite `loopback_bundle_transaction_invalid` por máximo 1.

Duplicados phase/loopback se detectan además por la cadena de `base_action_hash`; un delta ya representado en `action_snapshot` se rechaza por el reason superior de replay/base hash. Nunca se deduplica silenciosamente.

## 17. Protocolos inalterados

No cambian key set o shape de `loopback_result`, request/response loopback, binding, hashes, stdin/stdout, HTTP, stub, child, cantidad de mensajes ni ownership. No se añade `capture_delta` a loopback y no existe canal auxiliar.

## 18. Relación con EA5

EA6 no usa `integrateDelta()` ni `integrateActionDelta()`. Ambas APIs y los dos harnesses EA5 permanecen byte por byte. Esta corrección no modifica sus archivos ni migra el caller EA5.

## 19. Matriz adversarial definitiva 36/36

| ID | Método | Precondición | Inputs/defecto | Validación | Posición | Reason | Estado esperado | Pueden cambiar | Deben permanecer iguales | Cardinalidad | Commit |
|---|---|---|---|---|---:|---|---|---|---|---|:---:|
| A01 | reflection | clase cargada | clase distinta | declaración | 1 | prohibido | sin estado | ninguno | todos | n/a | no |
| A02 | reflection | clase exacta | phase no static/public | firma phase | 1 | prohibido | sin estado | ninguno | todos | n/a | no |
| A03 | reflection | clase exacta | loopback con 4 parámetros | firma loopback | 1 | prohibido | sin estado | ninguno | todos | n/a | no |
| A04 | reflection | clase exacta | loopback exacto 3 parámetros | firma loopback | 1 | none | sin ejecución | ninguno | todos | n/a | no |
| A05 | parser phase | phase_result válido | extrae tres subobjetos | descomposición phase | 1 | none | sin mutación | ninguno | todos | 1 bundle | no |
| A06 | parser loopback | loopback_result válido | extrae actions+result | descomposición loopback | 1 | none | sin mutación | ninguno | todos | 1 bundle | no |
| A07 | parser loopback | loopback_result válido | busca capture_delta | prohibición capture | 1 | protocol_failure | estado intacto | ninguno | todos | 0 | no |
| A08 | loopback | estado válido | sintetiza delta vacío | prohibición capture | 1 | loopback_bundle_transaction_invalid | estado intacto | ninguno | todos | 0 | no |
| A09 | loopback | phase previo | reutiliza captureDelta phase | prohibición capture | 1 | loopback_bundle_transaction_invalid | estado intacto | ninguno | todos | 0 | no |
| A10 | phase | estado inicial válido | bundle válido sin actions | validación total phase | 1-15 | none | phase integrado | capture/action/phase/operation | otros | 0 actions,1 phase | sí |
| A11 | phase | estado inicial válido | bundle válido con 1 action | validación total phase | 1-15 | none | phase integrado | capture/action/phase/operation | otros | 1 action,1 phase | sí |
| A12 | phase | estado inicial válido | segunda integración | phase_integrated | 11 | phase_bundle_already_integrated | estado intacto | ninguno | todos | 1 previa | no |
| A13 | phase | estado inválido | schema state incorrecto | state shape/schema | 1 | action_capture_transaction_state_invalid | estado intacto | ninguno | todos | 0 | no |
| A14 | phase | state válido | capture inválido | capture shape | 4 | reason capture superior | estado intacto | ninguno | todos | 0 | no |
| A15 | phase | state válido | actions no-lista | lista actions | 5 | reason tipo/lista superior | estado intacto | ninguno | todos | 0 | no |
| A16 | phase | state válido | action inválido | action individual | 6 | reason action superior | estado intacto | ninguno | todos | 0 | no |
| A17 | phase | state válido | actions desordenados | orden | 7 | reason orden superior | estado intacto | ninguno | todos | 0 | no |
| A18 | phase | state válido | action duplicado | duplicados internos | 8 | double_capture_detected | estado intacto | ninguno | todos | 0 | no |
| A19 | phase | state válido | action otra invocation | binding | 9 | wrong_owner | estado intacto | ninguno | todos | 0 | no |
| A20 | phase | subobjetos válidos | combinación inconsistente | cruzada | 14 | phase_bundle_transaction_invalid | estado intacto | ninguno | todos | 0 | no |
| A21 | loopback | estado inicial válido | lista vacía/result 0 válido | validación loopback | 1-15 | none | loopback integrado | action/loopback fields | capture+phase fields | 0 actions,1 loopback | sí |
| A22 | loopback | estado inicial válido | bundle válido con actions | validación loopback | 1-15 | none | loopback integrado | action/loopback fields | capture+phase fields | 1..4096,1 | sí |
| A23 | loopback | state válido | actions no-lista | lista actions | 4 | reason tipo/lista superior | estado intacto | ninguno | todos | 0 | no |
| A24 | loopback | state válido | action inválido | action individual | 5 | reason action superior | estado intacto | ninguno | todos | 0 | no |
| A25 | loopback | state válido | actions desordenados | orden | 6 | reason orden superior | estado intacto | ninguno | todos | 0 | no |
| A26 | loopback | state válido | duplicado interno byte-equivalent | duplicados internos | 7 | double_capture_detected | estado intacto | ninguno | todos | 0 | no |
| A27 | loopback | state válido | action otra invocation | binding | 8 | wrong_owner | estado intacto | ninguno | todos | 0 | no |
| A28 | loopback | state válido | loopback_result inválido | result shape/hash | 9 | reason loopback superior | estado intacto | ninguno | todos | 0 | no |
| A29 | loopback | una integración previa | mismo result_hash | duplicado previo | 10 | loopback_bundle_duplicate | estado intacto | ninguno | todos | 1 previa | no |
| A30 | loopback | una integración previa | result_hash distinto | máximo loopback | 11 | loopback_bundle_transaction_invalid | estado intacto | ninguno | todos | 1 previa | no |
| A31 | loopback | subobjetos válidos | fallo cruzado posterior | validación cruzada | 12 | loopback_bundle_transaction_invalid | estado intacto | ninguno | todos | 0 | no |
| A32 | loopback | bundle inválido | actions candidatas antes del fallo | atomicidad | 12 | loopback_bundle_transaction_invalid | estado intacto | ninguno | todos | 0 | no |
| A33 | loopback | bundle válido | capture_snapshot comparado === | conservación capture | 14 | none | capture idéntico | action/loopback fields | capture+phase fields | 1 | sí |
| A34 | loopback | bundle válido | commit único | asignación coordinator | 15 | none | candidato completo | action/loopback fields | capture+phase fields | 1 | sí |
| A35 | EA6 | cualquier bundle | usa integrateDelta/ActionDelta | API prohibida | pre-bootstrap | prohibido | estado intacto | ninguno | todos | 0 | no |
| A36 | EA5 | harness protegido | APIs y bytes preservados | regresión EA5 | final | none | EA5 sin cambio | según EA5 | todo EA6 ajeno | EA5 | según EA5 |

Esta es la única matriz adversarial de esta corrección.

## 20. Obligaciones de harnesses

| Obligación | Harness exacto |
|---|---|
| Reflexión de clase y firmas 4/3; ausencia de cuarto parámetro | `tests/manual/durable-retry-a11-action-capture-infrastructure-test.php` |
| Phase, deltas, atomicidad y conservación | `tests/manual/durable-retry-a11-action-capture-test.php` |
| Parser y protocolo loopback sin capture delta | `tests/manual/durable-retry-a11-http-webpay-stub-protocol-test.php` |
| Child/phase_result inalterado | `tests/manual/durable-retry-a11-child-protocol-test.php` |
| Catálogo/binding de invocation | `tests/manual/durable-retry-a11-action-invocation-plan-test.php` |
| Orphan y precedencia final | `tests/manual/durable-retry-a11-orphan-closure-test.php` |
| Matriz única 36/36, commit, rollback y residuos | `tests/manual/durable-retry-a11-ea6-matrix-test.php` |
| Prohibición de APIs EA5 en EA6 y allowlist | infraestructura + matriz EA6 |
| Regresión EA5 byte por byte | dos harnesses EA5 protegidos |

Ningún harness se crea o modifica en esta tarea.

## 21. Allowlist futura acumulativa

Soporte:

1. `tests/manual/support/durable-retry-a11-runtime-capture-contract.php`
2. `tests/manual/support/durable-retry-a11-coordinator.php`
3. `tests/manual/support/durable-retry-a11-child-worker.php`
4. `tests/manual/support/durable-retry-a11-http-webpay-stub.php`

Harnesses:

5. `tests/manual/durable-retry-a11-action-capture-test.php`
6. `tests/manual/durable-retry-a11-action-capture-infrastructure-test.php`
7. `tests/manual/durable-retry-a11-child-protocol-test.php`
8. `tests/manual/durable-retry-a11-http-webpay-stub-protocol-test.php`
9. `tests/manual/durable-retry-a11-action-invocation-plan-test.php`
10. `tests/manual/durable-retry-a11-orphan-closure-test.php`
11. `tests/manual/durable-retry-a11-ea6-matrix-test.php`

No se autoriza duodécimo archivo.

## 22. Allowlist del encargo documental

El único delta permitido ahora es:

`docs/durable-retry-production-activation-a11-action-capture-loopback-transaction-normative-correction.md`

Cero archivos existentes modificados, eliminados o renombrados; cero PHP/harnesses/auxiliares.

## 23. Criterio de aceptación

La implementación futura será conforme sólo si reproduce la firma loopback de tres parámetros, conserva phase, no crea capture delta loopback, preserva capture mediante `===`, aplica los órdenes y reasons, respeta 0..1 integración, ejecuta 36/36 y mantiene protocolos y EA5 intactos.

## 24. Cierre

La fuente inexistente de capture delta deja de ser necesaria sin alterar el envelope. Parser, estado, mutabilidad, conservación, algoritmos, precedencia, reasons, cardinalidad, duplicados, atomicidad, harnesses y allowlist quedan determinados.

`A11 ACTION CAPTURE LOOPBACK TRANSACTION IMPLEMENTABLE TRAS UNDÉCIMA CORRECCIÓN NORMATIVA`
