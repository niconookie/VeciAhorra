# Auditoría normativa de `expected.actions` para A11-WR-06

## 1. Propósito

Esta auditoría determina si existe autoridad suficiente para cerrar `expected.actions` de `A11-WR-06`. Aplica el orden y el criterio de detención exigidos: definición y shape antes de acciones Webpay, WooCommerce, reconciliación o Durable Retry.

## 2. Alcance

Se auditan únicamente la definición normativa de “action” y el shape de `expected.actions`. Al encontrarse el primer vacío material, no se auditan catálogos, nombres, conteos, resultados, mutaciones ni `fixture_ids`.

No se implementa A11, no se crean fixtures ejecutables, validadores, auxiliares o harnesses y no se modifica producto ni pruebas.

## 3. Antecedentes

La raíz de `expected` contiene, en este orden, `rows`, `actions`, `result` y `mutations`. `expected.rows` está cerrado con seis cardinalidades `1/1`. La ruta durable WR-06 también está cerrada: A3 inicial `legacy`, A5 `durable_created`, primera A8 `durable_synchronized`, replay A8 `durable_already_synchronized`, generation 1 y worker no ejecutado.

Esos resultados no definen por sí mismos qué llamada, efecto, transición u observación debe convertirse en una action contabilizable.

## 4. Definición de action

No existe una definición normativa suficiente.

```text
authoritative_document: durable-retry-production-activation-a11-fixture-contract-normative-correction.md
section: §9, Perfiles nominales
definition: expected contiene solo rows, actions, result, mutations con enteros
            no negativos o catálogos del caso
php_representation: no determinada para actions
json_representation: no determinada para actions
ordering_semantics: no determinada
counting_semantics: no determinada
phase_semantics: no determinada
```

La autoridad confirma presencia y un universo de tipos alternativos, pero no decide si una action es llamada, operación externa, operación de dominio, transición, publicación, programación, hook, observación del harness, resultado o combinación cerrada.

## 5. Separación de categorías

Conceptualmente, `expected.actions` debe describir acciones observables esperadas; `external_actions` prepara o controla interacciones externas del harness; `processes` describe topología operacional; `expected.rows` cardinalidades; `expected.result` resultados funcionales; `expected.mutations` cambios concretos; y `allowed_mutations`/`forbidden_mutations` límites.

Esta separación negativa no aporta el catálogo positivo de `expected.actions`. El contrato de fixtures §21 identifica Action Scheduler por API como `external_actions`, mientras documentos posteriores dicen que sus acciones podrían pertenecer a `expected.actions` o `external_actions`. Esa bifurcación sigue sin regla de proyección.

## 6. Shape

El shape permanece indeterminado. No hay autoridad para elegir entre:

- mapa `action_name => count`;
- lista ordenada de objetos;
- secuencia temporal;
- catálogo de resultados;
- nesting por dependencia;
- nesting por subsistema.

La propuesta por fases incluida en la solicitud es subsidiaria y solo puede adoptarse “salvo contradicción” después de establecer qué es una action. El vacío definicional impide convertirla en contrato completo.

## 7. Autoridades inspeccionadas

| Autoridad | Hallazgo | Alcance probatorio |
|---|---|---|
| Fixture contract §9 | raíz y alternativa “enteros o catálogos” | no define action ni nesting |
| Fixture contract §§16 y 21 | presupuesto de actions y Action Scheduler como `external_actions` | cleanup/infraestructura, no expected |
| Expected normative audit §§8–9 | raíz `rows/actions/result/mutations` | no define contenido de actions |
| Expected normative correction §10 | actions expresamente no auditado | confirma reserva, no shape |
| Expected rows correction §§8 y 24 | AS puede corresponder a actions o external_actions | mantiene bifurcación |
| Durable routing correction §§16 y 25 | programación durable cerrada; actions era unidad futura | resultado de ruta, no catálogo |
| A11 readiness audit §19 | salida JSONL contiene `"actions":{}` | envelope de reporte, no manifest WR-06 |
| Coordinator local `OPERATIONS` | publish/callback/legacy/as_action/recovery/http | código incompleto no normativo y no catálogo expected |

## 8. Catálogo candidato

No ejecutado tras el primer incumplimiento material. Las operaciones enumeradas por la solicitud son insumos de una autoridad futura, no claves aprobadas.

## 9. Catálogo incluido

No determinado. No se incluye ninguna clave y tampoco se declara un catálogo vacío.

## 10. Catálogo excluido

No determinado de forma exhaustiva. Solo se preserva la exclusión general ya vinculante de constructores, getters, DTOs y transformaciones puras cuando no exista autoridad expresa; esto no basta para cerrar el catálogo positivo.

## 11. Webpay

No auditado. No se decide si recepción, reconstrucción, persistencia, fingerprint, conciliación o gateway commit son actions.

## 12. WooCommerce

No auditado. No se decide si `payment_complete()`, transaction ID, date paid, `save()` o hooks son claves ni si el replay las ejecuta nuevamente.

## 13. Reconciliación

No auditado. La existencia de una reconciliación `1/1` pertenece a `expected.rows`; no autoriza contar creación, reutilización o publicación como actions.

## 14. Publicación

No auditado. El producto publica un candidato en ambas rutas no nulas del materializer, pero no existe autoridad que decida si contar publicación, invocación A8, ambas o ninguna.

## 15. A3–A8

Los resultados normativos permanecen vinculantes:

```text
A3 first_delivery=legacy
A4=transferred / initial_transfer_created
A5 first_delivery=durable_created
A8 first_delivery=durable_synchronized
A5 replay=durable_existing
A8 replay=durable_already_synchronized
```

No se convierten automáticamente en actions contabilizables.

## 16. Scheduler

La primera entrega programa y asocia una acción externa; el replay verifica y reutiliza la misma programación. No hay autoridad para decidir si la clave debe representar llamada a `schedule`, operación Action Scheduler, asociación durable o resultado A8, ni para resolver su separación con `external_actions`.

## 17. Worker

El worker está prohibido dentro de WR-06. Sin catálogo cerrado no puede inventarse una clave de worker con valor cero. Generation 2, claim, consume y backoff siguen ausentes como hechos de ruta, no como claves aprobadas.

## 18. Primera entrega

La secuencia productiva durable ya está cerrada, pero no se materializa como `expected.actions`: evidencia Webpay, reconciliación, efecto WooCommerce, publicación, transferencia, generation 1, schedule, asociación y término sin worker contienen operaciones de categorías potencialmente distintas.

## 19. Replay

El replay reutiliza evidencia, reconciliación, generation 1 y programación externa. No se determina cuáles relecturas son observables, cuáles operaciones se omiten y cuáles se ejecutan idempotentemente a efectos del manifest.

## 20. Idempotencia

Está demostrada la no duplicación de fila durable, generation y programación incompatible. Falta la autoridad que proyecte esos hechos a un conjunto de claves y conteos por fase. Por ello no se publican pares `first_delivery_count/replay_count`.

## 21. Relación con `external_actions`

| Operación | included_in_expected_actions | belongs_to_external_actions | Razón |
|---|---|---|---|
| configurar scheduler | indeterminado | candidato | no hay proyección cerrada |
| ejecutar Action Scheduler manualmente | indeterminado | candidato | operación del harness |
| simular Webpay | indeterminado | candidato | frontera temporal no cerrada |
| invocar callback | indeterminado | candidato | además está prohibido en WR-06 |
| cambiar opción WordPress | indeterminado | candidato | preparación del fixture |
| ejecutar replay | indeterminado | candidato | inicia fase, no necesariamente efecto |
| inspeccionar procesos | no demostrado | candidato de processes | no es historial funcional |

No se cierra `external_actions`.

## 22. Manifest

No se materializa. Un manifest parcial violaría la obligación de catálogo, shape y conteos completos.

## 23. PHP

Única representación honesta del estado de auditoría, no apta para fixture:

```php
'actions' => /* INDETERMINADO: falta definición, shape, catálogo y conteos */,
```

No se presenta un objeto PHP ejecutable.

## 24. JSON

No existe representación JSON normativa cerrada. `{}`, `[]` y `null` son decisiones distintas y ninguna está autorizada.

## 25. Validación

Solo puede validarse que la clave raíz `actions` debe existir después de `rows` y antes de `result`. No pueden validarse fases, key set, orden interno, nesting, tipos de valores, ceros obligatorios ni conteos sin inventar contrato.

## 26. Matriz adversarial

La matriz se limita al estado auditable. `indeterminado` no significa aceptación.

| # | input | phase | observed_actions | expected_actions | accepted | reason |
|---:|---|---|---|---|:---:|---|
| 1 | shape propuesto | ambas | no auditado | indeterminado | no | shape sin autoridad |
| 2 | actions=[] | raíz | lista vacía | indeterminado | no | tipo no fijado |
| 3 | actions={} | raíz | objeto vacío | indeterminado | no | catálogo no fijado |
| 4 | actions=null | raíz | nulo | no nulo requerido | no | presencia no basta |
| 5 | primera fase ausente | raíz | incompleto | indeterminado | no | manifest parcial |
| 6 | replay ausente | raíz | incompleto | indeterminado | no | manifest parcial |
| 7 | fase adicional | raíz | desconocida | indeterminado | no | fases no cerradas |
| 8 | orden invertido | ambas | desconocido | indeterminado | no | orden no cerrado |
| 9 | key set distinto | ambas | desconocido | indeterminado | no | catálogo no cerrado |
| 10 | orden distinto | ambas | desconocido | indeterminado | no | orden no cerrado |
| 11 | acción runtime adicional | cualquiera | desconocida | indeterminado | no | discovery prohibido |
| 12 | acción contractual ausente | cualquiera | desconocida | indeterminado | no | catálogo no existe |
| 13 | conteo string | cualquiera | string | indeterminado | no | no es entero |
| 14 | conteo booleano | cualquiera | booleano | indeterminado | no | no es entero |
| 15 | conteo null | cualquiera | nulo | indeterminado | no | no es entero |
| 16 | conteo negativo | cualquiera | negativo | indeterminado | no | fuera de dominio |
| 17 | rango | cualquiera | intervalo | indeterminado | no | literal requerido |
| 18 | acumulativo | replay | acumulado | indeterminado | no | semántica no cerrada |
| 19 | primera entrega correcta | first_delivery | ruta durable | indeterminado | no | falta proyección |
| 20 | replay correcto | replay | ruta idempotente | indeterminado | no | falta proyección |
| 21 | segunda programación | replay | duplicada | indeterminado | no | contradice ruta |
| 22 | scheduler legacy llamado | cualquiera | legacy | indeterminado | no | contradice ruta |
| 23 | worker ejecutado | cualquiera | worker | indeterminado | no | prohibido WR-06 |
| 24 | generation 2 | cualquiera | nueva generación | indeterminado | no | prohibida WR-06 |
| 25 | segundo payment completion | replay | no auditado | indeterminado | no | WooCommerce no auditado |
| 26 | segunda llamada gateway | replay | no auditado | indeterminado | no | Webpay no auditado |
| 27 | segunda reconciliación | replay | fila incompatible | indeterminado | no | contradice rows 1/1 |
| 28 | A4 repetida | replay | no auditado | indeterminado | no | A5 durable_existing |
| 29 | A8 already synchronized | replay | resultado cerrado | indeterminado | no | resultado no es action |
| 30 | programación reutilizada | replay | hecho cerrado | indeterminado | no | clave no definida |
| 31 | acción pura incluida | cualquiera | operación interna | indeterminado | no | sin autoridad expresa |
| 32 | resultado como acción | cualquiera | resultado | indeterminado | no | pertenece a result |
| 33 | mutación como acción | cualquiera | mutación | indeterminado | no | pertenece a mutations |
| 34 | operación harness como efecto | cualquiera | externa | indeterminado | no | categorías confundidas |
| 35 | acción duplicada en categorías | cualquiera | duplicada | indeterminado | no | proyección no cerrada |
| 36 | manifest parcial | raíz | parcial | indeterminado | no | cierre prohibido |
| 37 | replay tras activación distinta | replay | durable existente | indeterminado | no | conteos no definidos |
| 38 | scheduler indisponible | replay | unavailable | indeterminado | no | no satisface WR-06 |
| 39 | replay en otro proceso | replay | misma ruta | indeterminado | no | processes separado |
| 40 | segundo replay | replay | idempotente | indeterminado | no | conteos no definidos |

## 27. Bloqueo siguiente

Este es el primer bloqueo material y permanece abierto:

```text
case: A11-WR-06
category: expected
field: expected.actions.definition
reason: las autoridades solo reservan la clave y permiten alternativamente
        enteros no negativos o catálogos; no definen qué operación es una action,
        su representación, fases, ordering ni counting semantics
required_authority: contrato WR-06 que defina action, elija un único shape y
                    establezca la regla de proyección entre efectos productivos,
                    expected.actions y external_actions
```

## 28. Categorías

```text
expected.rows: cerrado
expected.actions: indeterminado
expected.result: no auditado
expected.mutations: pendiente
external_actions: no cerrado
fixture_ids: no auditado
```

Los otros 30 casos permanecen sin avance.

## 29. Veredictos

```text
A11-WR-06 CONTINÚA BLOQUEADO POR EXPECTED ACTIONS INDETERMINADO
A11 CONTINÚA BLOQUEADO POR MATRIZ DE FIXTURES INCOMPLETA
```

No se declara `CONTRATO EXPECTED ACTIONS WR-06 CERRADO`, `CONTRATO EXPECTED WR-06 CERRADO` ni A11 implementado.

## 30. Integridad

Esta auditoría crea únicamente este documento. Preserva producto, pruebas, cuatro cambios tracked, hashes protegidos, documentos antecedentes, staging y artefactos. No autoriza implementación, commit ni push.
