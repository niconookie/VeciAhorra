# Novena corrección normativa A11: constructor PHP de `DurableRetryA11Invocation`

## 1. Veredicto

`A11 ACTION INVOCATION CONSTRUCTOR IMPLEMENTABLE TRAS NOVENA CORRECCIÓN NORMATIVA`
## 2. Alcance

Esta corrección fija exclusivamente la firma PHP, propiedades, modos, validaciones, compatibilidad y precedencia del constructor de `DurableRetryA11Invocation`. No implementa EA6, no modifica PHP, harnesses, child, stub, listeners, catálogo ni autoridades existentes.

## 3. Autoridades anteriores

Se reconocen estas fuentes:

1. La corrección complementaria publicó un constructor anterior de seis parámetros.
2. El soporte EA5 actual contiene el constructor de tres parámetros y su caller protegido.
3. La cuarta corrección definió cinco conceptos lógicos para EA6.
4. La octava corrección publicó los 62 `invocation_id` exactos.

Esta novena corrección es la decisión humana externa que faltaba para consolidarlas.

## 4. Contradicción cerrada

Queda cerrada la coexistencia incompatible entre `operation/caseId/runId/payload/crashPoint/timeoutSeconds`, `executionId/entrypoint/timeoutSeconds` y el constructor lógico EA6. Existe una única firma PHP. La llamada EA5 se preserva mediante defaults; no existe un segundo constructor.

## 5. Firma PHP literal

La única firma autorizada es:

```php
public function __construct(
    public readonly string $executionId,
    public readonly string $entrypoint,
    public readonly int $timeoutSeconds = 30,
    public readonly ?string $invocationId = null,
    public readonly ?array $actionInvocationPlan = null
) {
}
```

No se autoriza ninguna variación de visibilidad, tipos, nombres, orden, defaults o promoción.

## 6. Namespace y declaración de clase

```php
namespace VeciAhorra\Tests\Manual\A11;

final class DurableRetryA11Invocation
```

La clase es `final`. No es una `readonly class`; sus cinco propiedades promovidas son individualmente `public readonly`.

## 7. Tabla de parámetros

| Posición | Parámetro | Tipo | Nullable | Default | Propiedad | Semántica |
|---:|---|---|:---:|---|---|---|
| 1 | `$executionId` | `string` | no | ninguno | `public readonly` promovida | Identidad EA5/EA6; reemplaza `runId` |
| 2 | `$entrypoint` | `string` | no | ninguno | `public readonly` promovida | Entrypoint EA5/EA6; reemplaza `operation` |
| 3 | `$timeoutSeconds` | `int` | no | `30` | `public readonly` promovida | Timeout positivo; conserva posición EA5 |
| 4 | `$invocationId` | `?string` | sí | `null` | `public readonly` promovida | ID literal EA6; null en EA5 |
| 5 | `$actionInvocationPlan` | `?array` | sí | `null` | `public readonly` promovida | Plan completo EA6; null en EA5 |

No hay variadic, named constructor, factory, adapter ni parámetros adicionales.

## 8. Representación exacta del plan

`$actionInvocationPlan` es exclusivamente `?array`. Se prohíben DTO, interfaz, clase, readonly object, iterable, colección, JSON string y wrapper.

Cuando no es null representa el objeto completo `action_invocation_plan`:

- schema exacto `veciahorra-a11-action-invocation-plan/v1`;
- kind y shape fijados por la cuarta corrección;
- exactamente 62 entries;
- catálogo literal fijado por la octava corrección;
- `$invocationId` presente exactamente una vez.

Una entry aislada no es un plan completo.

## 9. Modo EA5

El modo EA5 existe exactamente cuando:

```php
$invocationId === null
&& $actionInvocationPlan === null
```

La llamada histórica permanece válida:

```php
new DurableRetryA11Invocation(
    $executionId,
    $entrypoint,
    $timeoutSeconds
);
```

Este modo sólo preserva Runtime Capture EA5. No pertenece a Action Transport, no realiza binding, no resuelve IDs y no produce `action_delta`.

## 10. Modo EA6

El modo EA6 existe exactamente cuando:

```php
$invocationId !== null
&& $actionInvocationPlan !== null
```

Todo caller EA6 proporciona explícitamente cinco argumentos:

```php
new DurableRetryA11Invocation(
    $executionId,
    $entrypoint,
    $timeoutSeconds,
    $invocationId,
    $actionInvocationPlan
);
```

El ID debe ser uno de los 62 literales y el plan debe estar profundamente validado antes de construir la invocation. No existe degradación a EA5 ni inferencia del ID.

## 11. Invariante de pareja

`$invocationId` y `$actionInvocationPlan` son indivisibles. Sólo son válidos `null/null` y `non-null/non-null`.

`non-null/null` o `null/non-null` lanza `\InvalidArgumentException` con mensaje exacto `action_invocation_constructor_mode_mismatch`, antes de procesos, listeners, binding, snapshots o efectos.

## 12. Validaciones

El constructor valida directamente y en este orden:

1. `$executionId !== ''`;
2. `$entrypoint !== ''`;
3. `$timeoutSeconds > 0`;
4. consistencia de la pareja ID/plan;
5. en EA6, `$invocationId !== ''`;
6. schema exacto del plan;
7. exactamente 62 entries;
8. existencia exactamente una vez del ID.

La validación profunda previa de tipos, gramática, catálogo, ordinales, segmentos y concordancia algoritmo/tabla continúa siendo obligatoria y no es reemplazada.

## 13. Excepciones y mensajes

| Condición | Excepción exacta | Mensaje exacto |
|---|---|---|
| `executionId === ''` | `\InvalidArgumentException` | `execution_id_invalid` |
| `entrypoint === ''` | `\InvalidArgumentException` | `entrypoint_invalid` |
| `timeoutSeconds <= 0` | `\InvalidArgumentException` | `timeout_seconds_invalid` |
| Pareja inconsistente | `\InvalidArgumentException` | `action_invocation_constructor_mode_mismatch` |
| ID vacío en EA6 | `\InvalidArgumentException` | `action_invocation_id_invalid` |
| Schema incorrecto | `\InvalidArgumentException` | `action_invocation_plan_schema_mismatch` |
| Cardinalidad distinta de 62 | `\InvalidArgumentException` | `action_invocation_plan_cardinality_mismatch` |
| ID ausente o repetido | `\InvalidArgumentException` | `action_invocation_id_catalog_mismatch` |

Sólo se emite el primer fallo según el orden de la sección 12.

## 14. Sustitución del constructor complementario

El constructor de seis parámetros `operation, caseId, runId, payload, crashPoint, timeoutSeconds` queda **REEMPLAZADO Y PROHIBIDO PARA `DurableRetryA11Invocation`**.

- `runId` es reemplazado por `executionId`.
- `operation` es reemplazado por `entrypoint`.
- `caseId`, `payload` y `crashPoint` no pertenecen al constructor vigente.
- Si otro contrato los requiere, viajan sólo en su request o bundle autorizado.

No se conservan como argumentos, aliases, named arguments, variadic, propiedades ocultas, factory, adapter ni overload.

## 15. Absorción del constructor EA5

El constructor EA5 de tres parámetros no subsiste como API distinta. Su forma de llamada queda absorbida por los defaults finales:

```php
$invocationId = null;
$actionInvocationPlan = null;
```

La semántica EA5 permanece intacta y debe seguir certificada por los dos harnesses EA5 protegidos.

## 16. Materialización del constructor lógico EA6

Los conceptos de la cuarta corrección se materializan, sin reinterpretación, en este orden PHP:

1. `executionId`;
2. `entrypoint`;
3. `timeoutSeconds`;
4. `invocationId`;
5. `actionInvocationPlan`.

La expresión “constructor lógico” deja de ser insuficiente exclusivamente respecto de esta firma.

## 17. Compatibilidad permitida

Se permite únicamente compatibilidad de llamada EA5 mediante los defaults de las posiciones 4 y 5. La llamada existente de tres argumentos no debe migrarse a EA6 ni cambiar de significado.

## 18. Compatibilidad prohibida

Se prohíben constructor alternativo, named constructor, factory, adapter, trait, `func_get_args()`, variadic, overload simulado, detección dinámica, normalización de parámetros anteriores y conversión runtime de `runId` u `operation`.

También se prohíbe utilizar el modo EA5 dentro de Action Transport o presentar compatibilidad EA5 como compatibilidad retroactiva EA6.

## 19. Callers

El caller EA5 actual conserva exactamente tres argumentos y valores null implícitos en las dos posiciones finales. Los harnesses EA5 protegidos certifican su preservación.

Todo caller EA6 usa exactamente cinco argumentos, un ID literal y el plan completo previamente validado. No puede usar sólo una entry, JSON, objeto ni tres argumentos.

## 20. Precedencia

La precedencia específica es:

1. esta corrección para firma, parámetros, propiedades, modos, compatibilidad, validaciones e invariantes del constructor;
2. octava corrección para los 62 IDs literales;
3. cuarta corrección para el requisito funcional de ID y plan;
4. soporte EA5 sólo como evidencia del caller preservado;
5. constructor complementario de seis parámetros, reemplazado y no aplicable.

Toda definición anterior contradictoria del constructor queda sustituida. Las obligaciones no contradictorias permanecen vigentes.

## 21. Matriz adversarial

| # | Escenario | Resultado normativo |
|---:|---|---|
| 1 | EA5 con tres argumentos válidos | acepta modo EA5 |
| 2 | EA6 con cinco argumentos válidos | acepta modo EA6 |
| 3 | Constructor complementario de seis argumentos | prohibido |
| 4 | ID sin plan | `action_invocation_constructor_mode_mismatch` |
| 5 | Plan sin ID | `action_invocation_constructor_mode_mismatch` |
| 6 | ID vacío en EA6 | `action_invocation_id_invalid` |
| 7 | Execution ID vacío | `execution_id_invalid` |
| 8 | Entrypoint vacío | `entrypoint_invalid` |
| 9 | Timeout cero | `timeout_seconds_invalid` |
| 10 | Timeout negativo | `timeout_seconds_invalid` |
| 11 | Schema incorrecto | `action_invocation_plan_schema_mismatch` |
| 12 | 61 entries | `action_invocation_plan_cardinality_mismatch` |
| 13 | 63 entries | `action_invocation_plan_cardinality_mismatch` |
| 14 | ID ausente | `action_invocation_id_catalog_mismatch` |
| 15 | ID repetido | `action_invocation_id_catalog_mismatch` |
| 16 | Plan como objeto | `TypeError` por firma PHP |
| 17 | Plan como JSON string | `TypeError` por firma PHP |
| 18 | Entry individual como plan | `action_invocation_plan_schema_mismatch` |
| 19 | Factory | prohibida |
| 20 | Adapter | prohibido |
| 21 | Variadic | prohibido |
| 22 | Overload simulado | prohibido |
| 23 | Mutación posterior de propiedad | `Error` por readonly |
| 24 | Modo EA5 dentro de Action Transport | rechazo antes de efectos |
| 25 | Caller EA5 migrado indebidamente a cinco argumentos | prohibido |
| 26 | Caller EA6 usando tres argumentos | rechazo antes de Action Transport |

## 22. Criterios de aceptación

Una implementación es conforme sólo si reproduce byte por byte la firma, conserva la llamada EA5, exige cinco argumentos en EA6, aplica las validaciones en orden, usa los mensajes exactos, no incorpora API paralela y mantiene preflight profundo antes de construir EA6.

La certificación futura debe cubrir los dos harnesses EA5 protegidos, Action Invocation Plan, infraestructura EA6 y matriz integral.

## 23. Allowlist

Esta tarea autoriza exclusivamente:

`docs/durable-retry-production-activation-a11-action-invocation-constructor-normative-correction.md`

Se prohíben cambios en PHP, harnesses, autoridades anteriores, producto, fixtures, child, stub, listeners y artifacts.

## 24. Integridad del repositorio

La adopción documental debe preservar rama, HEAD, staging, tracked modificados preexistentes, autoridades protegidas, harnesses EA5, artifacts y ausencia de runtime/procesos/listeners. No autoriza ejecutar PHP, commit ni push.

## 25. Veredicto final

La firma PHP literal, los dos modos, la invariante, excepciones, sustituciones, callers y precedencia quedan cerrados sin alternativas.

`A11 ACTION INVOCATION CONSTRUCTOR IMPLEMENTABLE TRAS NOVENA CORRECCIÓN NORMATIVA`
