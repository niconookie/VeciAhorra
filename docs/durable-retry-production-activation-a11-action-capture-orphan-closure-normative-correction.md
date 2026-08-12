# Quinta corrección normativa A11 del cierre de entries huérfanas

Estado: contrato documental cerrado y fail-closed. Fecha: 2026-08-05.

## 1. Objeto y precedencia

Esta corrección resuelve exclusivamente el momento de evaluación de `loopback_authority_orphan_entry`. Sustituye toda exigencia posterior que coloque la detección de orfandad dentro de la validación previa al bootstrap. No sustituye schema, binding, transporte, endpoint, readiness, shutdown, atomicidad, snapshots, hashes, sellado, cleanup ni otras precedencias de las cuatro correcciones anteriores.

Las reglas reconciliadas de la cuarta corrección permanecen vigentes: línea 60 define huérfana por falta de consumo al finalizar; línea 125 la determina al cierre; línea 133 le asigna el último lugar de precedencia; línea 211 rechaza el caso adversarial al cierre. Esta corrección confirma que esas cuatro reglas son la única interpretación normativa.

## 2. Tres etapas obligatorias

La validación se divide, sin solapamiento, en:

1. validación estática previa al bootstrap;
2. resolución y consumo dinámico por invocation;
3. validación de completitud al cierre limpio.

«Plan completamente validado antes de bootstrap» significa completamente validado respecto de todas las invariantes observables antes de bootstrap. No autoriza anticipar invariantes dependientes del consumo futuro.

## 3. Validación estática previa al bootstrap

Antes de abrir child, stub, puerto o listener se validan íntegramente:

- schema, versión y kind exactos;
- key sets cerrados y ausencia de aliases/campos adicionales;
- tipos, cardinalidad y valores permitidos;
- formato y unicidad de `invocation_id`;
- unicidad de identidades normativamente únicas;
- phase, `entrypoint_id`, bindings y referencias estáticas;
- consistencia interna y ausencia de duplicados.

El paso 11 de cualquier lista de validación previa queda limitado a confirmar esas invariantes y registrar internamente `orphan_check_pending=true` como estado de implementación, no como campo del plan. Está prohibido decidir qué entries serán consumidas o emitir `loopback_authority_orphan_entry` en esta etapa.

Un plan válido puede contener una entry que finalmente no sea solicitada. Esa posibilidad no invalida su estructura.

## 4. Resolución y consumo dinámico

Cada solicitud se resuelve exclusivamente mediante comparación byte por byte de su `invocation_id` con la key correspondiente del `action_invocation_plan`. Posición, orden, execution ID, case ID, phase, entrypoint o combinaciones de ellos no son mecanismos alternativos de lookup.

Tras resolver por `invocation_id`, se validan los demás bindings conforme a la cuarta corrección. Una entry puede consumirse como máximo una vez y únicamente por la invocation homónima. El segundo consumo falla con `loopback_authority_duplicate_entry` y conserva el primero.

El coordinator mantiene en memoria dos sets separados:

```text
declared_invocation_ids = keys readonly del plan validado
consumed_invocation_ids = set inicialmente vacío
```

Consumir añade el ID al segundo set después de validar la entry y antes de iniciar sus procesos; no modifica la entry, el plan ni su hash. Los sets no se transportan y desaparecen en `CLEANED`. No se autorizan archivos, pipes, environment, memoria compartida, campos, canales laterales o catálogo externo.

## 5. Naturaleza del plan

`action_invocation_plan` sigue siendo la única autoridad readonly, se crea antes de bootstrap, permanece solo en memoria y se une únicamente mediante `invocation_id`. Contiene entries autorizadas, pero no es un catálogo independiente de invocations necesariamente ejecutadas y no permite predecir cuáles serán solicitadas.

No se añade `required`, `expected`, `will_execute`, `optional` ni equivalente. La mera presencia de una entry no prueba consumo futuro.

## 6. Validación de completitud al cierre

`loopback_authority_orphan_entry` es exclusivamente una invariante de cierre limpio. Una entry es huérfana si y solo si, tras completar todas las invocations que el flujo efectivamente solicitó, permanece declarada y no consumida.

Secuencia exacta:

1. completar las invocations solicitadas;
2. registrar cada consumo exacto;
3. alcanzar cierre limpio de ejecución;
4. calcular `pending = declared_invocation_ids − consumed_invocation_ids`;
5. si `pending` no está vacío y no existe error anterior, emitir una sola vez `loopback_authority_orphan_entry`;
6. si `pending` está vacío, aceptar la completitud de autoridad y continuar cleanup.

El set `pending` se ordena por bytes únicamente para diagnóstico interno no autoritativo. El reason code no cambia según cardinalidad. El chequeo no puede omitirse en un cierre limpio.

## 7. Error anterior y precedencia

Si antes del cierre limpio ocurre un error de mayor precedencia, ese error permanece como único resultado autoritativo. No se ejecutan invocations adicionales para consumir entries. Las entries pendientes causadas por la detención no producen ni sustituyen el reason primario.

Puede calcularse `pending` para cleanup diagnóstico, pero no genera segundo veredicto, excepción encadenada autoritativa, snapshot, hash o sellado. `loopback_authority_orphan_entry` conserva el último lugar absoluto de precedencia fijado por la cuarta corrección.

## 8. Casos normativos obligatorios

Caso limpio: las 62 entries superan validación estática; el flujo solicita las 62 invocations; cada ID se resuelve y consume una vez; al cierre `pending=[]`; no se emite orphan.

Caso huérfano: las 62 entries superan validación estática; una entry declarativamente válida nunca se solicita; las otras 61 terminan; al cierre limpio `pending` contiene exactamente ese ID; se emite `loopback_authority_orphan_entry`. La entry no se rechaza antes.

Caso con error anterior: tras validar el plan ocurre `wrong_owner` durante una invocation; se detiene fail-closed y quedan entries pendientes; el resultado autoritativo es exclusivamente `wrong_owner`; no se fuerzan consumos y orphan no lo sobrescribe.

## 9. Prohibiciones cerradas

Se prohíbe declarar orphan antes del cierre limpio, predecir consumos, inferir obligatoriedad por presencia, ampliar schema, crear segundo catálogo, consumir preventivamente, marcar todo consumido al inicio, ejecutar invocations artificiales, omitir el chequeo final, redefinir huérfana, cambiar precedencia o introducir canales.

## 10. Matriz normativa

| # | Escenario | Etapa | Decisión | Reason autoritativo | Procesos/efecto |
|---:|---|---|---|---|---|
| 1 | schema inválido | estática | rechaza | `loopback_authority_structure_invalid` | cero procesos |
| 2 | invocation ID duplicado | estática | rechaza | `loopback_authority_duplicate_entry` | cero procesos |
| 3 | binding estático inválido | estática | rechaza | `loopback_authority_binding_mismatch` | cero procesos |
| 4 | plan válido | estática | acepta estructura | `none` | orphan pendiente |
| 5 | resolución exacta | dinámica | acepta entry | `none` | puede iniciar invocation |
| 6 | primer consumo | dinámica | registra ID | `none` | entry intacta |
| 7 | segundo consumo mismo ID | dinámica | rechaza | `loopback_authority_duplicate_entry` | fail-closed |
| 8 | cierre limpio, cero pendientes | cierre | acepta completitud | `none` | cleanup normal |
| 9 | cierre limpio, una pendiente | cierre | rechaza | `loopback_authority_orphan_entry` | cleanup |
| 10 | cierre limpio, varias pendientes | cierre | rechaza una vez | `loopback_authority_orphan_entry` | cleanup |
| 11 | error anterior y pendientes | fallo | preserva primero | reason anterior | no consume artificialmente |
| 12 | intento de anticipar orphan | estática | rechaza implementación | `loopback_authority_inference_forbidden` | cero procesos |
| 13 | catálogo adicional | estática | rechaza | `loopback_authority_conflict` | cero procesos |
| 14 | orphan intenta reemplazar error | cierre no limpio | prohibido | reason anterior | cleanup |
| 15 | canal adicional de consumo | cualquier | rechaza | `loopback_authority_structure_invalid` | fail-closed |

## 11. Reconciliación del paso 11

Toda instrucción que enumere «entries huérfanas» dentro de la validación previa queda sustituida únicamente en su aspecto temporal. Antes del bootstrap se valida que el plan sea estructuralmente completo, no duplicado y estáticamente consistente, y se deja la comprobación de orfandad pendiente.

No existe información legítima para afirmar antes de ejecución que una entry declarativamente válida quedará sin solicitar. El cálculo se realiza solo al cierre limpio usando los sets declarados y consumidos.

## 12. Compatibilidad y allowlist futura

La corrección no cambia `action_invocation_plan/v1`, sus seis campos, hashes, requests, results, estados, endpoint o shutdown. La implementación futura sigue limitada a los cuatro archivos EA6: contract conserva/valida el plan; coordinator mantiene los sets y ejecuta el cierre; child y stub no conocen la comprobación global.

## 13. Límites

Este documento no implementa EA6, no crea child, stub, helpers, harnesses o H1–H5 y no ejecuta matrices. No modifica código, tests, fixtures, artifacts ni autoridades anteriores.

## 14. Veredicto

Las etapas estática, dinámica y de cierre quedan separadas; la definición y precedencia de orphan permanecen intactas y ninguna invariant futura se anticipa.

`A11 ACTION CAPTURE LOOPBACK ORPHAN VALIDATION IMPLEMENTABLE TRAS QUINTA CORRECCIÓN NORMATIVA`
