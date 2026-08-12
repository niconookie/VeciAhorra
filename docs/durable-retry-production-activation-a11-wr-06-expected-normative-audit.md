# Auditoría normativa A11-WR-06 — categoría `expected`

## 1. Propósito

Esta auditoría localiza la primera categoría posterior a `payload` y evalúa
exclusivamente su contrato para `A11-WR-06`.

## 2. Alcance

No reabre `payload`, no audita los otros 30 casos, no avanza a categorías
posteriores, no implementa A11, no crea ejecutables y no modifica documentos,
producto o pruebas existentes. No realiza commit ni push.

## 3. Antecedentes vinculantes

Para WR-06 están cerradas `case_id`, `harness`, `profile`, `variations` y
`payload`. Se preservan literalmente ownership, checkout, idempotency key, buy
order, fingerprint y transaction reference de las correcciones antecedentes.
El JSON canónico financiero conserva 594 bytes.

## 4. Documentos inspeccionados

Se inspeccionaron:

- `durable-retry-production-activation-a11-fixture-contract-normative-correction.md`,
  especialmente §§5, 9, 18, 22 y 23;
- `durable-retry-production-activation-a11-complementary-normative-correction.md`,
  especialmente su shape de manifest y contratos de resultados;
- las cuatro correcciones WR-06 de ambiente, comercio, buy order y payload;
- `WooCommercePaymentCompletionResult`, `WooCommercePaymentCompletionHandler`,
  `WebpayReturnService`, `PaymentReconciliation` y sus repositorios como
  autoridades productivas auxiliares.

## 5. Orden autoritativo de categorías

La autoridad es §18 de
`durable-retry-production-activation-a11-fixture-contract-normative-correction.md`.
Enumera exactamente, en este orden, quince categorías obligatorias:

```text
1. case_id
2. harness
3. profile
4. variations
5. payload
6. expected
7. fixture_ids
8. rows_to_create
9. initial_state
10. external_actions
11. processes
12. allowed_mutations
13. forbidden_mutations
14. cleanup
15. budget
```

`payload` ocupa la posición 5 y `expected` la 6. Ninguna categoría intermedia
está marcada como no aplicable. No se encontró otro orden completo incompatible
en los documentos A11 inspeccionados.

## 6. Categoría siguiente identificada

La primera unidad auditable posterior a `payload` es exactamente `expected`.
§23.5 confirma que resultado inicial, replay, fingerprint final,
reconciliation final y cardinalidad de filas pertenecen a `expected`, no a
`variations` ni a `payload`.

## 7. Definición de la categoría

La categoría debería contener exclusivamente resultados esperados y
observables de aceptación; no crea recursos ni autoriza mutaciones. Debe estar
incluida como objeto JSON `expected` dentro del manifest durable y ser inmutable
durante primera entrega y replay.

No obstante, el documento autoritativo contiene dos definiciones incompatibles
de su conjunto de claves.

## 8. Campos candidatos y contradicción

§9 declara literalmente:

```text
expected contiene solo rows, actions, result, mutations
```

Por tanto su catálogo candidato A es exactamente:

```php
['rows' => ..., 'actions' => ..., 'result' => ..., 'mutations' => ...]
```

§22.2 declara como “forma exacta de expected para WR-06”:

```php
[
    'public_result' => 'APPLIED_NOW',
    'woocommerce_order_is_paid' => true,
    'woocommerce_order_date_paid_present' => true,
    'woocommerce_order_transaction_id' => [
        'operator' => 'strict_equals',
        'expected' => '@allocated.woocommerce_transaction_reference',
    ],
    'fingerprint_reconciled' => true,
    'reconciliation_status' => 'completed',
]
```

Este catálogo candidato B no contiene `rows`, `actions`, `result` o
`mutations`; todas sus seis claves están prohibidas por el “solo” de §9.

## 9. Autoridades

| Autoridad | Responsabilidad | Estado |
|---|---|---|
| §18 | orden de las quince categorías | inequívoca |
| §23.5 | proyección conceptual de observables hacia `expected` | inequívoca |
| §9 | shape común cerrado de cuatro claves | vinculante |
| §22.2 | shape exacto WR-06 de seis claves | vinculante |
| §22, frase de precedencia | prevalece sobre §19 solo para observable final | no resuelve §9 |
| producto | valores observables y estados | no decide el shape del manifest |

La cláusula de §22 no dice que prevalezca sobre §9, ni define si las seis
claves reemplazan, anidan o amplían las cuatro comunes. Elegir cualquiera de
esas interpretaciones inventaría estructura contractual.

## 10. Manifest

La entrada superior `expected:object` es obligatoria tanto en el contrato de
fixtures como en el complementario. Su contenido no puede materializarse de
forma inequívoca.

Campos candidatos B, sin declararlos canónicos:

```text
name: expected.public_result
classification: expected
php_type: string
json_type: string
literal_or_rule: APPLIED_NOW
authority: §22.2, incompatible con §9
persisted: manifest
replay_source: manifest + resultado productivo
mutable: false
fingerprint_participation: false

name: expected.woocommerce_order_is_paid
classification: expected
php_type/json_type: bool/boolean
literal_or_rule: true
authority: §22.2, incompatible con §9
persisted: manifest; observable derivado del pedido
replay_source: WC_Order::is_paid()
mutable: false
fingerprint_participation: false

name: expected.woocommerce_order_date_paid_present
classification: expected
php_type/json_type: bool/boolean
literal_or_rule: true
authority: §22.2, incompatible con §9
persisted: manifest; fecha real en WooCommerce
replay_source: WC_Order::get_date_paid('edit')
mutable: false
fingerprint_participation: false

name: expected.woocommerce_order_transaction_id
classification: expected
php_type/json_type: array/object
literal_or_rule: strict_equals transaction reference cerrada
authority: §22.2 + corrección payload
persisted: manifest; transaction ID en WooCommerce
replay_source: WC_Order::get_transaction_id('edit')
mutable: false
fingerprint_participation: derived from fingerprint, not a component

name: expected.fingerprint_reconciled
classification: expected
php_type/json_type: bool/boolean
literal_or_rule: true
authority: §22.2
persisted: manifest; fingerprint en evidencia productiva
replay_source: pedido/reconciliation persistidos
mutable: false
fingerprint_participation: assertion only

name: expected.reconciliation_status
classification: expected
php_type/json_type: string/string
literal_or_rule: completed
authority: §22.2 + estado productivo
persisted: manifest y payment_reconciliations.reconciliation_status
replay_source: fila por ID capturado
mutable: false como expectativa
fingerprint_participation: false
```

No se agregan estos campos al contrato: se registran como la alternativa B que
una corrección futura debe reconciliar con `rows/actions/result/mutations`.

## 11. Primera entrega

Si B resultara autoritativo, antes de ejecutar estarían disponibles los seis
valores esperados y la transaction reference literal cerrada. La primera
entrega debería producir `APPLIED_NOW`, pedido pagado, fecha presente,
transaction ID exacto, fingerprint reconciliado y reconciliation `completed`.

Pero la auditoría no puede determinar si esos observables deben ubicarse
directamente, bajo `result`/`mutations`, o junto a contadores `rows/actions`.
Por ello no existe objeto de manifest válido demostrable y no se autoriza
ejecución.

## 12. Replay

La intención documentada es preservar pedido, transaction ID, fingerprint y
reconciliation, sin segunda aplicación funcional. El replay releería manifest,
pedido por ID capturado y filas persistidas; no recalcularía expectativas desde
configuración mutable.

La fuente productiva puede comprobarse, pero el child no puede validar el shape
ambiguo sin aceptar claves prohibidas o exigir claves ausentes. Por eso tampoco
puede cerrarse el resultado esperado del replay.

## 13. Persistencia

`expected` se persiste en `manifest.json`, no en una tabla A11. Los observables
se acreditan en WooCommerce y tablas productivas: transaction ID y fecha en el
pedido, fingerprint en evidencia reconciliada y status en
`payment_reconciliations.reconciliation_status`. `APPLIED_NOW` es resultado de
procesamiento, no columna durable independiente. La ambigüedad es del contrato
del manifest, no de la capacidad de almacenar esos efectos.

## 14. Invariantes

Permanecen vinculantes: transaction reference exacta
`va-wp-v1-1d599ae282242c619aed248200f23d22d670a5326db48739462338929565af83`,
fingerprint exacto, una sola aplicación, pedido propio, fecha pagada presente y
reconciliation completed. Status final WooCommerce concreto no es observable
de aceptación. Ninguna de estas invariantes resuelve el conjunto de claves.

## 15. Matriz adversarial

| # | Entrada | Autoridad | Resultado/persistencia/replay | Decisión |
|---:|---|---|---|---|
| 1 | candidato B exacto | §22.2 | viola `solo` de §9 | rechaza por contradicción |
| 2 | primera entrega | §9 vs §22.2 | no hay manifest validable | no ejecutar |
| 3 | replay inmediato | mismas autoridades | shape indeterminado | no ejecutar |
| 4 | replay otro proceso | manifest requerido | child no puede elegir shape | rechaza |
| 5 | replay otro equipo | manifest requerido | misma contradicción | rechaza |
| 6 | cambia configuración | no autoritativa | invariantes estables, shape sigue abierto | rechaza cierre |
| 7 | campo ausente | ambos catálogos exigen sus claves | contrato incierto | rechaza |
| 8 | campo null | no autorizado | no persistir | rechaza |
| 9 | tipo incorrecto | tipos candidatos cerrados | no persistir | rechaza |
| 10 | valor vacío | fuera de catálogos | no persistir | rechaza |
| 11 | valor fuera de dominio | autoridad productiva/contractual | no aceptar | rechaza |
| 12 | valor altera manifest | hash durable cambia | fail-closed existente | rechaza |
| 13 | persistencia incompatible | observable difiere | fallo funcional | rechaza |
| 14 | evidencia inicial incompleta | no acredita expected | replay no repara | rechaza |
| 15 | segundo replay | debería converger | no certificable con shape abierto | no ejecutar |
| 16 | conflicto payload/expected | referencia o fingerprint difiere | coherencia falla | rechaza |
| 17 | dependencia no asignada | literal cerrado ya existe | no es el bloqueo actual | no aplica |
| 18 | recalcular desde mutable | prohibido | preservar manifest/evidencia | rechaza |
| 19 | solo cuatro claves A | satisface §9 | omite forma exacta §22.2 | rechaza |
| 20 | unión de diez claves | inferencia sin precedencia | claves extra bajo ambas lecturas | rechaza |
| 21 | seis claves anidadas en result | posible diseño no escrito | altera shape §22.2 | rechaza |

No se inventan excepciones o reason codes. La evidencia contractual inválida
correspondería al fallo de contrato/infraestructura ya definido, pero esta
auditoría no ejecuta el validador.

## 16. Dependencias

`expected` depende referencialmente de payload, transaction reference,
fingerprint, pedido y reconciliation. Todos esos valores o reglas ya están
cerrados para WR-06. La única dependencia pendiente es una autoridad de
precedencia que determine el shape exacto de `expected` y la ubicación de sus
observables y cardinalidades.

## 17. Bloqueo

```text
case: A11-WR-06
category: expected
field: expected (exact key set and nesting)
reason: §9 limita expected exclusivamente a rows/actions/result/mutations,
        mientras §22.2 declara una forma exacta WR-06 con seis claves distintas;
        la precedencia de §22 solo menciona §19 y no resuelve §9
required_authority: corrección normativa que establezca precedencia expresa y
                    un único shape literal, incluido nesting y campos comunes
```

Es el primer bloqueo material. No se auditan valores posteriores del registro
ni la categoría `fixture_ids`.

## 18. Categorías restantes

Cerradas para WR-06: `case_id`, `harness`, `profile`, `variations`, `payload`.
Auditada pero no cerrada: `expected`. Pendientes y no auditadas:
`fixture_ids`, `rows_to_create`, `initial_state`, `external_actions`, `processes`,
`allowed_mutations`, `forbidden_mutations`, `cleanup`, `budget`.

Los otros 30 casos no avanzan por esta auditoría y la matriz continúa
incompleta. La siguiente unidad normativa exacta es corregir el shape y la
precedencia de `expected` para WR-06; no es auditar `fixture_ids`.

## 19. Veredictos

**A11-WR-06 CONTINÚA BLOQUEADO POR CATEGORÍA EXPECTED INDETERMINADA**

**A11 CONTINÚA BLOQUEADO POR MATRIZ DE FIXTURES INCOMPLETA**

No se emite `CONTRATO EXPECTED WR-06 CERRADO` ni se declara cerrada la categoría.

## 20. Integridad

Esta ejecución crea exclusivamente este documento de auditoría. Conserva los
cuatro cambios tracked, los cinco hashes protegidos, todos los documentos
antecedentes, staging, `artifacts/` y accessor tipado. No implementa A11 ni
realiza commit o push.
