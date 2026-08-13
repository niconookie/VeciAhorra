# Décima corrección normativa A11: transacción de bundles de Action Capture

## 1. Veredicto

`A11 ACTION CAPTURE BUNDLE TRANSACTION IMPLEMENTABLE TRAS DÉCIMA CORRECCIÓN NORMATIVA`
## 2. Alcance

Esta corrección fija exclusivamente la clase, firmas PHP, estado combinado, bundles, retorno, atomicidad, commit, rollback, cardinalidad y relación EA5 de la integración transaccional EA6. No implementa EA6 ni modifica PHP, harnesses, child, stub, listeners o autoridades existentes.

## 3. Bloqueo anterior

La autoridad anterior sólo describía conceptualmente `integratePhaseBundle(array $captureDelta, array $actionDeltas, array $operationResult): array`, sin clase propietaria ni estado explícito. Esta corrección elimina esa indeterminación.

## 4. Decisión humana

Este documento materializa la decisión humana externa: una clase estática sin estado, dos métodos separados y un estado combinado propiedad exclusiva del coordinator. No queda autorizada otra selección.

## 5. Archivo autorizado

La futura clase se declara exclusivamente en:

`tests/manual/support/durable-retry-a11-runtime-capture-contract.php`

Es el archivo de contrato EA6 ya autorizado para transacciones y validadores compuestos. No se crea un duodécimo archivo EA6.

## 6. Namespace y clase

```php
namespace VeciAhorra\Tests\Manual\A11;

final class DurableRetryA11ActionCaptureTransaction
{
}
```

La clase es `final`, no es interfaz, excepción ni `readonly class`, y no puede extenderse.

## 7. Ausencia de constructor y estado

La clase no declara constructor, propiedades ni estado mutable. Expone exclusivamente `integratePhaseBundle()` e `integrateLoopbackBundle()`, ambos públicos y estáticos.

La integración no pertenece a `DurableRetryA11ActionCapture`, `DurableRetryA11RuntimeCaptureStore`, `DurableRetryA11TransportEnvelopeValidator`, coordinator, child, stub, listener o process runner.

## 8. Shape del estado combinado

Representación PHP exclusiva:

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

`schema` vale exactamente `veciahorra-a11-action-capture-transaction-state/v1`. `execution_id` es no vacío; `invocation_id` pertenece al catálogo de 62; ambos snapshots usan sus representaciones normativas. El estado inicial usa `false`, `null`, `0`, `null` para los últimos cuatro campos.

El coordinator es su único propietario. Permanece sólo en memoria, no se serializa ni cruza procesos, y child, stub y listeners nunca lo reciben.

## 9. Firma phase

```php
public static function integratePhaseBundle(
    array $currentState,
    array $captureDelta,
    array $actionDeltas,
    array $operationResult
): array
```

Es la única firma. No admite instancia, menor visibilidad, otro nombre, DTO, parámetros separados de identidad/snapshot, opcionales, variadic, referencias ni otro retorno.

## 10. Firma loopback

```php
public static function integrateLoopbackBundle(
    array $currentState,
    array $captureDelta,
    array $actionDeltas,
    array $loopbackResult
): array
```

Phase y loopback permanecen separados. Se prohíbe un método común con discriminator, union, flag o `kind`.

## 11. Formas de bundle

En phase, los tres últimos argumentos son el bundle completo: `capture_delta`, lista PHP ordenada de cero o más deltas unitarios y `operation_result`.

En loopback, los tres últimos argumentos son `capture_delta`, la misma clase de lista y `loopback_result`.

No existe array exterior `phase_bundle` o `loopback_bundle`; tampoco se entrega el envelope completo. El parser valida el envelope y extrae subobjetos antes de invocar la transacción.

## 12. Shape de retorno

Ambos métodos retornan exclusivamente el mismo shape completo de la sección 8.

Phase exitoso fija `phase_integrated=true` y `operation_result=$operationResult`, preservando los campos loopback anteriores.

Loopback exitoso preserva los campos phase, incrementa `loopback_integrations` exactamente uno, fija `last_loopback_result=$loopbackResult` y retorna ambos snapshots resultantes.

Se prohíben boolean, DTO, store, tuple, lista, delta, envelope, referencia o resultado parcial.

## 13. Semántica transaccional

Los métodos son puros respecto de sus argumentos: no modifican estado, deltas ni results recibidos. Validan todo, construyen un candidato local, aplican conceptualmente los deltas, validan el conjunto y sólo entonces retornan el candidato.

Uso phase:

```php
$candidateState = DurableRetryA11ActionCaptureTransaction::integratePhaseBundle(
    $currentState,
    $captureDelta,
    $actionDeltas,
    $operationResult
);
$currentState = $candidateState;
```

Loopback usa el patrón idéntico con `integrateLoopbackBundle()`. Ante excepción no hay retorno ni mutación.

## 14. Preparación

La preparación valida estado, subobjetos, lista, cada action, IDs, orden y duplicados; calcula ambos snapshots candidatos y valida el resultado conjunto. Todo ocurre sobre valores locales sin autoridad observable.

## 15. Commit

El único commit es una asignación del array candidato completo efectuada por el coordinator después del retorno exitoso. No existe commit interno ni dos asignaciones observables.

## 16. Rollback por ausencia de asignación

No existe método de rollback. Si el método lanza, el coordinator no asigna el candidato y el estado anterior conserva autoridad. Se prohíben restore público, compensación, mutación seguida de reversión y transacción distribuida.

## 17. Orden phase

`integratePhaseBundle()` valida exactamente:

1. shape/schema del estado;
2. `execution_id`;
3. `invocation_id`;
4. capture delta;
5. lista de action deltas;
6. cada action;
7. orden;
8. duplicados;
9. binding de todos los deltas con la invocation;
10. operation result;
11. `phase_integrated === false`;
12. aplicación candidata de capture;
13. aplicación candidata de actions;
14. validación cruzada;
15. retorno final.

Ningún delta se aplica conceptualmente antes del paso 12.

## 18. Orden loopback

`integrateLoopbackBundle()` valida exactamente:

1. shape/schema del estado;
2. `execution_id`;
3. `invocation_id`;
4. capture delta;
5. lista de action deltas;
6. cada action;
7. orden;
8. duplicados;
9. binding con la invocation;
10. loopback result;
11. aplicación candidata de capture;
12. aplicación candidata de actions;
13. validación cruzada;
14. incremento candidato;
15. retorno final.

Puede ocurrir antes o después de phase cuando el protocolo lo permita, sin modificar `phase_integrated`.

## 19. Cardinalidad

Por invocation EA6, phase completa exitosamente exactamente una vez; un fallo no consume cardinalidad y un segundo éxito se rechaza.

Loopback se integra una vez por cada result lógico aceptado, en orden de recepción validada. Un fallo no incrementa el contador y el mismo resultado lógico no se integra dos veces.

## 20. Excepciones

Ambos métodos publican exclusivamente `\InvalidArgumentException` para contrato o transición inválidos. No crean excepciones propias, no retornan error o `false` y no silencian errores normativos.

## 21. Reasons

Se preservan los reasons superiores específicos de schema, tipo, gramática, catálogo, ID, capture, action, operation, loopback, orden y duplicados.

Esta corrección agrega únicamente:

| Reason | Condición |
|---|---|
| `action_capture_transaction_state_invalid` | Estado con shape, schema o invariantes inválidos |
| `phase_bundle_already_integrated` | Phase ya integrada |
| `phase_bundle_transaction_invalid` | Subobjetos válidos, combinación phase inconsistente |
| `loopback_bundle_transaction_invalid` | Subobjetos válidos, combinación loopback inconsistente |
| `loopback_bundle_duplicate` | Resultado lógico loopback ya integrado |

La duplicidad usa exclusivamente la identidad normativa contenida en `loopback_result`; no se crea otro ID.

## 22. Relación con EA5

`integrateDelta(...)` e `integrateActionDelta(...)` permanecen byte por byte para EA5. En EA6 se prohíbe llamarlos directa o secuencialmente, en cualquier orden, como sustitución de la transacción.

La clase nueva puede reutilizar validadores puros, nunca APIs públicas mutables EA5 para commit. No se migra el caller EA5 ni se transforma su modo en EA6.

## 23. Relación con validadores

`DurableRetryA11TransportEnvelopeValidator` valida el envelope y extrae subobjetos; nunca integra estado.

`DurableRetryA11ActionCaptureTransaction` valida la transición y calcula el candidato; nunca parsea stdin/stdout, inicia procesos, registra listeners, resuelve rutas, hace bootstrap o escribe archivos.

## 24. Relación con stores

`DurableRetryA11RuntimeCaptureStore` y `DurableRetryA11ActionCapture` no poseen la transacción combinada. Sus contratos continúan para EA5 y representaciones internas autorizadas.

El estado EA6 pertenece al coordinator y sólo se transforma mediante la nueva clase estática. No se agregan constructores o dependencias entre stores.

## 25. Sustitución de API conceptual

La firma conceptual de tres parámetros queda reemplazada y prohibida como API pública. La firma definitiva phase incluye `$currentState` en primera posición. Loopback queda cerrado por su método separado de cuatro parámetros.

## 26. Matriz adversarial

| # | Escenario | Resultado |
|---:|---|---|
| 1 | Clase propietaria distinta | `prohibido` |
| 2 | Método de instancia | `prohibido` |
| 3 | Método private o protected | `prohibido` |
| 4 | Nombre de método distinto | `prohibido` |
| 5 | Phase con tres parámetros | `prohibido` |
| 6 | Phase con bundle exterior | `prohibido` |
| 7 | Método común phase/loopback | `prohibido` |
| 8 | Estado como DTO | `TypeError o rechazo contractual` |
| 9 | Estado como objeto store | `TypeError o rechazo contractual` |
| 10 | Estado por referencia | `prohibido` |
| 11 | Retorno booleano | `prohibido` |
| 12 | Retorno DTO | `prohibido` |
| 13 | Schema de estado incorrecto | `action_capture_transaction_state_invalid` |
| 14 | execution_id vacío | `reason superior de execution ID` |
| 15 | invocation_id desconocido | `reason superior de invocation ID` |
| 16 | Capture delta inválido | `reason específico superior` |
| 17 | Action deltas no-lista | `reason específico superior` |
| 18 | Action delta inválido | `reason específico superior` |
| 19 | Action deltas desordenados | `reason de orden superior` |
| 20 | Action delta duplicado | `reason de duplicado superior` |
| 21 | Action delta de otra invocation | `reason de binding superior` |
| 22 | Operation result inválido | `reason específico superior` |
| 23 | Loopback result inválido | `reason específico superior` |
| 24 | Segunda integración phase | `phase_bundle_already_integrated` |
| 25 | Loopback duplicado | `loopback_bundle_duplicate` |
| 26 | Mutación parcial de capture | `prohibida; estado anterior intacto` |
| 27 | Mutación parcial de actions | `prohibida; estado anterior intacto` |
| 28 | Excepción tras mutar estado original | `prohibida` |
| 29 | integrateDelta() directo en EA6 | `prohibido` |
| 30 | integrateActionDelta() directo en EA6 | `prohibido` |
| 31 | Método público de rollback | `prohibido` |
| 32 | Commit mediante dos asignaciones observables | `prohibido` |
| 33 | Loopback altera phase_integrated | `loopback_bundle_transaction_invalid` |
| 34 | Fallo incrementa loopback_integrations | `prohibido; contador intacto` |
| 35 | Modificación del caller EA5 | `prohibida` |
| 36 | Archivo EA6 adicional | `prohibido` |

## 27. Criterios de aceptación

La implementación futura debe declarar exactamente la clase y dos firmas, reproducir shapes y órdenes, demostrar pureza, un único commit, ausencia de rollback público, cardinalidad, reasons, preservación EA5 y los 36 adversariales sin crear archivos adicionales.

## 28. Precedencia

Esta corrección prevalece específicamente para clase propietaria, firmas, estado, retorno, atomicidad, commit, rollback, cardinalidad y relación con APIs EA5.

Permanecen vigentes la novena corrección para `DurableRetryA11Invocation`, la octava para los 62 IDs y las autoridades previas para snapshots y deltas no contradictorios.

## 29. Allowlist

El único archivo autorizado por este encargo es:

`docs/durable-retry-production-activation-a11-action-capture-bundle-transaction-normative-correction.md`

Se prohíben modificaciones de archivos existentes y la creación de PHP, harnesses, child, stub, listener o artifacts.

## 30. Integridad final

La adopción documental preserva autoridades, harnesses EA5, rama, HEAD, staging, cambios preexistentes, artifacts y ausencia de procesos/listeners/runtime. No autoriza PHP, commit ni push.

`A11 ACTION CAPTURE BUNDLE TRANSACTION IMPLEMENTABLE TRAS DÉCIMA CORRECCIÓN NORMATIVA`
