# Auditoría final de readiness de materialización A11-WR-06

## 1. Propósito

Esta auditoría determina si `A11-WR-06` puede materializarse completa, aislada y determinísticamente sin inventar IDs, estados, timestamps, resultados u observables.

La revisión es fail-closed y sigue el orden normativo: `fixture_ids` antes de estado inicial, payload, clock, harness o manifest.

## 2. Estado normativo adoptado

Se preservan sin reapertura:

```text
woocommerce_payment_completion = 0/0
durable_worker_execution = 0/0
PaymentReconciliationProcessor y complete() fuera de las fases
transición funcional WooCommerce fuera del resultado temporal
single_application = initial_durable_authority generation 1
```

Los shapes cerrados de `expected.actions` y `expected.result` permanecen como antecedentes; no se materializan aquí.

## 3. Autoridades inspeccionadas antes de la detención

| Clasificación | Ruta/símbolo | Rango | Hallazgo |
|---|---|---:|---|
| antecedente documental | `durable-retry-production-activation-a11-fixture-contract-normative-correction.md`, manifest | 60–76 | shape raíz incluye `fixture_ids:object` |
| antecedente documental | mismo documento, §6 | 78–93 | catálogo exacto de quince claves, cada una lista de enteros positivos |
| antecedente documental | mismo documento, matriz WR | 174–187 | WR-06 solo tiene descripción narrativa, no objeto literal |
| antecedente documental | mismo documento, diagnóstico de implementabilidad | 261–279 | declara 0/31 registros completos y categorías sin asignar |
| antecedente documental | mismo documento, recurso WooCommerce | 706–768 | añade referencia y descriptor `woocommerce_order_id` |
| antecedente documental | mismo documento, integración limitada | 826–832 | confirma que no completa las demás categorías WR-06 |
| contrato productivo | PKs de repositorios VeciAhorra | múltiples repositorios | IDs SQL se generan/capturan durante creación |
| contrato productivo | `WC_Order::get_id()` | contrato WooCommerce §20 | ID positivo disponible solo post-save |
| contrato productivo | scheduler externo | A7/adapter | action ID positivo retornado en runtime |

No se usaron tests como autoridad normativa. Las referencias `@allocated.*` acreditan asignación simbólica, no valores completos del objeto de caso.

## 4. Shape global de `fixture_ids`

§6 exige exactamente estas claves, todas listas de enteros positivos ordenadas por inserción:

```text
orders
checkouts
checkout_orders
payment_sessions
payment_origin_contexts
webpay_returns
payment_reconciliations
durable_retry_schedules
business_completions
business_completion_orders
payments
payment_orders
delivery_completions
fulfillment_completions
action_scheduler_actions
```

El shape no contiene `woocommerce_order_id` ni un catálogo de recursos API paralelo dentro de `fixture_ids`.

## 5. Shape WooCommerce posterior

§20.4 muestra:

```php
'woocommerce_order_id' => [
    'source' => '@allocated.woocommerce_order_id',
    'type' => 'positive_int',
    'resource_type' => 'api_resource',
    'logical_resource' => 'woocommerce_order',
    'primary_identifier' => 'WC_Order::get_id()',
    'cardinality' => 1,
    'ownership_required' => true,
    'cleanup_required' => true,
],
```

Este valor es un descriptor con strings y booleanos, no una lista de enteros positivos. La sección no publica un reemplazo completo de las quince claves ni establece si el descriptor pertenece al manifest schema, a un catálogo de referencias o al valor runtime de `fixture_ids`.

## 6. Contradicción material

Existen dos representaciones incompatibles:

1. `fixture_ids` runtime con key set exacto y valores `list<positive-int>`.
2. Una clave adicional `woocommerce_order_id` cuyo valor es un descriptor asociativo.

No hay una regla inequívoca que:

- amplíe formalmente el key set global;
- ubique el recurso WooCommerce fuera de `fixture_ids` sin perder cleanup;
- separe descriptor de catálogo y valor runtime;
- publique el objeto literal completo WR-06;
- determine qué listas de las quince claves deben contener un ID y cuáles quedar vacías;
- asegure unicidad entre los 31 casos.

Materializar cualquiera de estas opciones sería diseñar un contrato nuevo.

## 7. Auditoría de IDs WR-06

`—` significa que el valor exacto o su ubicación normativa no está cerrado.

| Campo | Requerido | Valor exacto | Tipo | Autoridad | Único globalmente | Persistido antes de first_delivery |
|---|---:|---|---|---|---:|---:|
| `user_id` | no demostrado | — | — | ninguna para WR-06 | — | — |
| `store_id` | no demostrado | — | — | ninguna para WR-06 | — | — |
| `product_id` | no demostrado | — | — | ninguna para WR-06 | — | — |
| `inventory_id` | no demostrado | — | — | ninguna para WR-06 | — | — |
| `cart_id/cart_item_id` | no demostrado | — | — | ninguna para WR-06 | — | — |
| VeciAhorra `order_id` | no | — | — | rows correction excluye `orders` | — | — |
| Woo `woocommerce_order_id` | sí | `@allocated.woocommerce_order_id` sin número | positive int post-save o descriptor | §20.2/20.4 incompatibles con §6 | no cerrada | sí |
| `order_item_id` | no demostrado | — | — | fixture Woo declara items vacíos | — | — |
| `reservation_id` | no demostrado | — | — | ninguna para WR-06 | — | — |
| `checkout_id` | sí | runtime no asignado por caso | positive int | §6/§7 | no cerrada | sí |
| `checkout_order_id` | no según rows cerrado | — | — | rows excluye bridge | — | — |
| `payment_session_id` | sí | `@allocated.payment_session_id`, sin número | positive int | payload/§6 | no cerrada | sí |
| `payment_origin_context_id` | sí | runtime no asignado | positive int | §6/§7 | no cerrada | sí |
| `webpay_return_id` | sí | runtime no asignado | positive int | §6/§7 | no cerrada | no |
| `payment_reconciliation_id` | sí | `@allocated.payment_reconciliation_id`, sin número | positive int | rows/routing corrections | no cerrada | no |
| `business_completion_id` | no | — | — | worker fuera de fases | — | — |
| `schedule_id` | sí | generado/capturado, sin valor exacto | positive int | A4/A7 | no cerrada | no |
| `scheduled_action_id` | sí | retornado por scheduler, sin valor exacto | positive int | A7/adapter | no cerrada | no |
| `generation` | sí | `1` | int | request/transfer A4 | identidad única pendiente | no |
| `attempt_number` | sí | `0` | int | initial transfer | n/a | no |
| transaction reference | no dentro del resultado temporal | — | string derivable | completion fuera de fases | — | no |
| `buy_order` | sí | referencia derivada, no ID entero | string | payload correction | scope por ownership | sí |
| token/session | sí | referencias derivadas, literal secreto fuera del manifest | string/hash | payload correction | scope por ownership | sí |
| `worker_id` | no | — | — | worker prohibido | — | — |

Las referencias simbólicas son necesarias y válidas como relaciones, pero no resuelven el shape incompatible ni constituyen la asignación completa exigida para el registro WR-06.

## 8. Pregunta binaria

```text
¿Existe autoridad suficiente para materializar A11-WR-06 sin inventar?
NO
```

Falta la autoridad de `fixture_ids` antes de poder evaluar determinísticamente estado inicial, payload, clock, schedule/action IDs, manifest o cleanup integral.

## 9. Primer bloqueo

```text
case: A11-WR-06
category: fixture_ids
field: fixture_ids
reason: §6 exige un objeto de quince claves con valores list<positive-int>, pero
        §20.4 añade woocommerce_order_id como descriptor asociativo fuera de ese
        key set; no existe un objeto literal WR-06 ni una regla de precedencia que
        separe catálogo de referencias, recursos API y valores runtime capturados
required_authority: corrección normativa que publique el shape único definitivo
                    de fixture_ids, incluya o ubique fuera de él los recursos API,
                    y asigne para WR-06 cada clave, cardinalidad, fuente runtime,
                    unicidad intercaso y representación exacta en manifest
```

## 10. Detención normativa

Conforme a las condiciones de detención, no se auditaron como unidades de cierre:

- estado inicial;
- payload first delivery o replay;
- clock y timestamps;
- adaptación del harness;
- gateway/scheduler doubles;
- persistencia completa;
- manifest;
- allowlist futura;
- presupuesto operacional;
- assertions futuras;
- cleanup y aislamiento.

Los contratos ya cerrados de actions, result y `single_application` permanecen válidos como antecedentes, pero no bastan para materializar el caso.

## 11. Estado de readiness

| Unidad | Cerrada |
|---|:---:|
| Fixture IDs | no |
| Estado inicial | no auditado |
| Payload first delivery | no auditado |
| Payload replay | no auditado |
| Clock | no auditado |
| Expected actions | sí, antecedente |
| Expected result | sí, antecedente |
| Single application | sí, antecedente |
| Gateway double | no auditado |
| Scheduler double | no auditado |
| Persistencia esperada | no auditada integralmente |
| Manifest | no auditado |
| Allowlist futura | no auditada |
| Presupuesto operacional | no auditado |
| Assertions futuras | no auditadas |
| Cleanup y aislamiento | no auditados integralmente |

## 12. Veredicto principal

```text
A11-WR-06 MATERIALIZACIÓN BLOQUEADA POR FIXTURE_IDS INDETERMINADO
```

## 13. Integridad

Esta auditoría crea únicamente este documento. No materializa WR-06, no modifica código, harnesses, fixtures, JSON, manifest, matriz, schema, configuración o artifacts y no realiza staging, commit ni push.
