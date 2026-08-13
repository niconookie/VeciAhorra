# Duodécima corrección normativa A11: API PHP del coordinator Action Capture

## 1. Veredicto

`A11 ACTION CAPTURE COORDINATOR API IMPLEMENTABLE TRAS DUODÉCIMA CORRECCIÓN NORMATIVA`
## 2. Alcance

Esta corrección fija exclusivamente almacenamiento, cinco métodos, validación, retorno, commit, lifecycle y cleanup del estado combinado EA6 en `DurableRetryA11Coordinator`. No implementa PHP ni modifica transacciones, transporte, EA5 o harnesses.

## 3. Propietarios

`DurableRetryA11Coordinator` es el único propietario stateful. `DurableRetryA11ActionCaptureTransaction` continúa puro y sin estado. Se prohíben tercer propietario, repository, store, singleton, global, archivo, shared memory o proceso auxiliar.

## 4. Propiedad exacta

```php
private array $actionCaptureBundleStates = [];
```

Representación exclusiva:

```php
$this->actionCaptureBundleStates[$executionId][$invocationId] = $combinedState;
```

Primer nivel execution, segundo invocation, valor estado completo de nueve campos. No composite keys, hashes, aliases, referencias, duplicación o static.

Una instancia admite exactamente un execution activo, 0..62 estados y máximo uno por par.

## 5. Firmas públicas exactas

```php
public function initializeActionCaptureBundleState(
    string $executionId,
    string $invocationId,
    array $captureSnapshot,
    array $actionSnapshot
): array;

public function integrateActionCapturePhaseBundle(
    string $executionId,
    string $invocationId,
    array $captureDelta,
    array $actionDeltas,
    array $operationResult
): array;

public function integrateActionCaptureLoopbackBundle(
    string $executionId,
    string $invocationId,
    array $actionDeltas,
    array $loopbackResult
): array;

public function actionCaptureBundleState(
    string $executionId,
    string $invocationId
): array;

public function cleanupActionCaptureBundleState(
    string $executionId,
    string $invocationId
): void;
```

No existen defaults, variadic, referencias, wrappers o aliases.

## 6. Inicialización

Construye exactamente:

```php
[
    'schema' => 'veciahorra-a11-action-capture-transaction-state/v1',
    'execution_id' => $executionId,
    'invocation_id' => $invocationId,
    'capture_snapshot' => $captureSnapshot,
    'action_snapshot' => $actionSnapshot,
    'phase_integrated' => false,
    'operation_result' => null,
    'loopback_integrations' => 0,
    'last_loopback_result' => null,
]
```

Valida IDs, binding con plan, snapshots, candidato, registro previo, execution y capacidad. Mismo par y candidato `===` retorna el existente sin asignar. Diferencia lanza `a11_action_capture_bundle_state_conflict`. Un estado nuevo realiza una asignación y retorna el almacenado.

## 7. Integración phase

Localiza un estado, contrasta binding, copia por valor y llama exactamente:

```php
$candidateState = DurableRetryA11ActionCaptureTransaction::integratePhaseBundle(
    $currentState,
    $captureDelta,
    $actionDeltas,
    $operationResult
);
$this->actionCaptureBundleStates[$executionId][$invocationId] = $candidateState;
return $candidateState;
```

La asignación sólo ocurre tras retorno exitoso. La excepción transaccional se propaga intacta. Se prohíben APIs EA5, referencias, mutación preliminar o campo por campo.

## 8. Integración loopback

Localiza, valida binding, copia y llama:

```php
$candidateState = DurableRetryA11ActionCaptureTransaction::integrateLoopbackBundle(
    $currentState,
    $actionDeltas,
    $loopbackResult
);
$this->actionCaptureBundleStates[$executionId][$invocationId] = $candidateState;
return $candidateState;
```

No busca, recibe, sintetiza o reutiliza capture delta. Rechazo conserva almacenamiento; éxito hace una asignación completa.

## 9. Lectura

`actionCaptureBundleState()` retorna por valor el array completo, sin normalizar, recalcular, mutar o crear. Ausencia lanza `LogicException('a11_action_capture_bundle_state_missing')`.

## 10. Cleanup

`cleanupActionCaptureBundleState()` elimina sólo el par exacto, elimina el nivel execution si queda vacío, es idempotente y retorna void. No modifica snapshots, filesystem u otras invocations.

## 11. Excepciones y mensajes cerrados

| Condición | Excepción | Mensaje exacto |
|---|---|---|
| execution vacío/inválido | `InvalidArgumentException` | `execution_id_invalid` |
| invocation vacío/gramática inválida | `InvalidArgumentException` | `action_invocation_id_invalid` |
| binding inválido | `InvalidArgumentException` | `loopback_authority_binding_mismatch` |
| capture snapshot inválido | `InvalidArgumentException` | `invalid_snapshot` |
| action snapshot inválido | `InvalidArgumentException` | `actions_map_invalid` |
| otro execution activo | `LogicException` | `a11_action_capture_bundle_execution_conflict` |
| capacidad >62 | `LogicException` | `a11_action_capture_bundle_capacity_exceeded` |
| reinicialización diferente | `LogicException` | `a11_action_capture_bundle_state_conflict` |
| estado ausente | `LogicException` | `a11_action_capture_bundle_state_missing` |

Los cinco reasons transaccionales no se reutilizan como mensajes coordinator. Excepciones de la transacción se propagan sin captura o traducción.

## 12. Orden initialize

1. Tipos por firma.
2. execution ID.
3. invocation ID.
4. binding.
5. capture snapshot.
6. action snapshot.
7. candidato inicial.
8. si el par existe: igualdad estricta; retorna o state conflict.
9. si otro execution está activo: execution conflict.
10. si existen 62 estados: capacity exceeded.
11. asignación única.
12. retorno.

## 13. Orden integración phase/loopback

Para ambos:

1. Tipos por firma.
2. execution ID.
3. invocation ID.
4. binding.
5. estado existente; si falta, missing.
6. copia por valor.
7. invocación transaccional con su precedencia propia.
8. candidato retornado.
9. asignación única.
10. retorno exacto.

Loopback nunca introduce una validación capture.

## 14. Orden lectura y cleanup

Lectura: tipos, execution ID, invocation ID, binding, existencia, copia, retorno.

Cleanup: tipos, execution ID, invocation ID; si falta retorna void; si existe elimina par; si el nivel queda vacío lo elimina; retorna void. La idempotencia no crea estado.

## 15. Retorno y discriminador

El discriminador es PHP: retorno array significa éxito autorizable; excepción significa rechazo. No existe status adicional.

Initialize/read retornan el estado completo; las integraciones retornan exactamente el array transaccional; cleanup retorna void. En éxito, almacenamiento `===` retorno. En rechazo, almacenamiento `===` estado anterior.

## 16. Parser

Phase extrae `captureDelta`, `actionDeltas`, `operationResult` y llama una vez `integrateActionCapturePhaseBundle()`.

Loopback extrae `actionDeltas`, conserva `loopbackResult` completo y llama una vez `integrateActionCaptureLoopbackBundle()`. Nunca produce capture delta.

El coordinator no reparsa o reconstruye subobjetos validados.

## 17. Relación con runPhase()

La firma y retorno públicos EA5 de `runPhase()` permanecen byte por byte. La ruta EA5 no entra a estos métodos.

En invocation EA6, después de recibir y validar completamente `phase_result`, y antes de construir `DurableRetryA11ProcessResult` o exponer resultado final, `runPhase()` entrega conjuntamente los tres subobjetos una sola vez a `integrateActionCapturePhaseBundle()`. No existe commit EA6 antes del bundle completo.

La rama se determina exclusivamente por el modo cerrado de `DurableRetryA11Invocation`.

## 18. Lifecycle

1. Validar plan e invocation.
2. Inicializar estado antes de enviar solicitud productiva.
3. Integrar phase máximo una vez.
4. Integrar loopback máximo una vez cuando corresponda.
5. Construir retorno público final.
6. Sin loopback: cleanup inmediatamente después de construir el retorno phase.
7. Con loopback: cleanup inmediatamente después de construir el retorno loopback.
8. No limpiar antes del retorno.
9. Fallo terminal de proceso descarta memoria como cleanup final.
10. No persistir ni recuperar.
11. Cierre normal deja cero estados.

Call sites exactos: la rama EA6 de `runPhase()` llama cleanup después de construir su resultado phase sin loopback; la rama supervisor loopback del coordinator llama cleanup después de construir el resultado loopback final. Toda ruta terminal llama cleanup en `finally` si el proceso continúa vivo.

## 19. Atomicidad

La transacción pura prepara el candidato. El coordinator hace exactamente una asignación completa. Se prohíben propiedad por referencia, asignaciones preliminares, integración separada, almacenamiento separado de results y rollback compensatorio. Fallo equivale a ausencia de asignación.

## 20. Relación EA5

`runPhase()`, `integrateDelta()`, `integrateActionDelta()` y los harnesses EA5 permanecen byte por byte en su API vigente. EA6 no llama las dos integraciones incrementales directa o indirectamente.

## 21. Matriz coordinator 18/18

| # | Método | Preestado | Entrada | Resultado | Excepción/reason | Estado posterior | Asignaciones | Cleanup | Commit |
|---:|---|---|---|---|---|---|---:|:---:|:---:|
| 1 | C01 | initializeActionCaptureBundleState | sin execution activo | par+snapshots válidos | estado inicial completo | none | par almacenado | 1 | no | sí |
| 2 | C02 | initializeActionCaptureBundleState | mismo par existente | candidato === existente | devuelve existente | none | sin reasignación | 0 | no | no |
| 3 | C03 | initializeActionCaptureBundleState | mismo par existente | candidato !== existente | lanza | a11_action_capture_bundle_state_conflict | intacto | 0 | no | no |
| 4 | C04 | initializeActionCaptureBundleState | execution A activo | execution B | lanza | a11_action_capture_bundle_execution_conflict | intacto | 0 | no | no |
| 5 | C05 | initializeActionCaptureBundleState | 61 estados | invocation 62 válida | estado creado | none | 62 estados | 1 | no | sí |
| 6 | C06 | initializeActionCaptureBundleState | 62 estados | invocation 63 | lanza | a11_action_capture_bundle_capacity_exceeded | 62 intactos | 0 | no | no |
| 7 | C07 | actionCaptureBundleState | par existente | IDs exactos | copia completa | none | intacto | 0 | no | no |
| 8 | C08 | actionCaptureBundleState | par ausente | IDs válidos | lanza | a11_action_capture_bundle_state_missing | intacto | 0 | no | no |
| 9 | C09 | integrateActionCapturePhaseBundle | par ausente | bundle válido | lanza | a11_action_capture_bundle_state_missing | intacto | 0 | no | no |
| 10 | C10 | integrateActionCaptureLoopbackBundle | par ausente | bundle válido | lanza | a11_action_capture_bundle_state_missing | intacto | 0 | no | no |
| 11 | C11 | integrateActionCapturePhaseBundle | estado inicial | phase válido | retorna candidato | none | candidato almacenado | 1 | no | sí |
| 12 | C12 | integrateActionCapturePhaseBundle | estado inicial | phase rechazado | propaga excepción | reason transaccional | anterior === | 0 | no | no |
| 13 | C13 | integrateActionCaptureLoopbackBundle | estado válido | loopback válido | retorna candidato | none | candidato almacenado | 1 | no | sí |
| 14 | C14 | integrateActionCaptureLoopbackBundle | estado válido | loopback rechazado | propaga excepción | reason transaccional | anterior === | 0 | no | no |
| 15 | C15 | integrateActionCaptureLoopbackBundle | estado válido | tres args sin capture | retorna/propaga contrato | none o reason exacto | capture === | 0..1 | no | según bundle |
| 16 | C16 | cleanupActionCaptureBundleState | par existente | IDs exactos | void | none | sólo par eliminado | 1 delete | sí | no |
| 17 | C17 | cleanupActionCaptureBundleState | par ausente | IDs exactos | void | none | intacto | 0 | sí | no |
| 18 | C18 | cleanupActionCaptureBundleState | último par | IDs exactos | void | nivel execution eliminado | sin nivel | 2 deletes lógicos | sí | no |

La matriz transaccional 36/36 de la undécima corrección permanece íntegra y separada.

## 22. Harnesses

| Obligación | Harness |
|---|---|
| Cinco métodos, retornos y lifecycle | `tests/manual/durable-retry-a11-action-capture-test.php` |
| Propiedad private, firmas, no static/referencias | `tests/manual/durable-retry-a11-action-capture-infrastructure-test.php` |
| 36 transaccionales + 18 coordinator | `tests/manual/durable-retry-a11-ea6-matrix-test.php` |
| Incorporación phase | `tests/manual/durable-retry-a11-child-protocol-test.php` |
| Loopback sin capture delta | `tests/manual/durable-retry-a11-http-webpay-stub-protocol-test.php` |
| Binding y capacidad 62 | `tests/manual/durable-retry-a11-action-invocation-plan-test.php` |
| Cleanup normal, idempotente y terminal | `tests/manual/durable-retry-a11-orphan-closure-test.php` |
| Regresión | dos harnesses EA5 protegidos |

No se autoriza octavo harness.

## 23. Allowlist futura

Permanece exactamente: cuatro soportes (`runtime-capture-contract`, `coordinator`, `child-worker`, `http-webpay-stub`) y siete harnesses EA6 ya publicados, sin duodécimo archivo.

## 24. Precedencia

Esta corrección prevalece sólo para propiedad, almacenamiento, cinco métodos coordinator, excepciones, retorno, commit y lifecycle. Décima/undécima conservan autoridad sobre transacciones; novena sobre invocation; octava sobre catálogo; EA5 permanece intacto.

## 25. Allowlist documental

El único delta actual es:

`docs/durable-retry-production-activation-a11-action-capture-coordinator-api-normative-correction.md`

No se modifican archivos existentes.

## 26. Cierre

Propiedad, cardinalidad, cinco firmas, creación, lectura, integración, excepciones, commit, cleanup, parsers, `runPhase()`, lifecycle, matrices, harnesses y allowlist quedan cerrados.

`A11 ACTION CAPTURE COORDINATOR API IMPLEMENTABLE TRAS DUODÉCIMA CORRECCIÓN NORMATIVA`
