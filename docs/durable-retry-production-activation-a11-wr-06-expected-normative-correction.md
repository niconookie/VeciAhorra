# Corrección normativa A11-WR-06 — shape y precedencia de `expected`

## 1. Propósito

Esta corrección resuelve la precedencia entre §9 y §22.2, fija el shape y
nesting de `expected` para `A11-WR-06` y audita sus campos en el orden requerido.

## 2. Alcance

No reabre `payload`, no avanza a `fixture_ids`, no audita los otros 30 casos,
no implementa A11, no crea ejecutables y no modifica documentos, producto o
pruebas existentes. No realiza commit ni push.

## 3. Antecedentes

El orden vinculante mantiene `expected` en la posición 6, inmediatamente después
de `payload` y antes de `fixture_ids`. Para WR-06 están cerradas `case_id`,
`harness`, `profile`, `variations` y `payload`; la auditoría antecedente dejó
`expected` bloqueada por dos shapes incompatibles.

## 4. Contradicción corregida

§9 limitaba la raíz a `rows`, `actions`, `result`, `mutations`. §22.2 mostraba
en la raíz seis expectativas Webpay diferentes. Se prohíben ambos shapes como
alternativas, su unión plana y cualquier interpretación por equivalencia.

## 5. Regla de precedencia

La regla vinculante nueva es:

> §9 conserva autoridad sobre el envoltorio común y obligatorio de la
> categoría `expected` para todos los casos A11. Para A11-WR-06, las seis claves
> enumeradas en §22.2 no reemplazan ni amplían el nivel raíz; constituyen el
> contenido exacto y cerrado de `expected.result`.

En consecuencia:

1. §9 gobierna exclusivamente la raíz;
2. §22.2 gobierna exclusivamente las claves de `expected.result` para WR-06;
3. el ejemplo anterior con seis claves en la raíz queda sustituido;
4. no existen aliases, flattening, shapes alternativos ni claves opcionales.

## 6. Shape raíz

`expected` es un array asociativo PHP y un objeto JSON. Contiene exactamente,
en este orden:

```text
rows
actions
result
mutations
```

La ausencia, adición, clave numérica, `null` o tipo distinto es contrato
inválido. El orden es obligatorio para construcción, serialización reproducible
y validación estructural A11; no se afirma que el orden de miembros cambie la
semántica abstracta de cualquier objeto JSON externo.

Shape PHP estructural cerrado:

```php
[
    'rows' => /* contrato literal todavía pendiente */,
    'actions' => /* no auditado tras el primer bloqueo */,
    'result' => /* objeto exacto de §7 */,
    'mutations' => /* no auditado tras el primer bloqueo */,
]
```

Los comentarios no son valores ni placeholders autorizados en un manifest.

## 7. Nesting de `result`

`expected.result` es un array asociativo PHP/objeto JSON con exactamente estas
seis claves y este orden contractual:

```text
public_result
woocommerce_order_is_paid
woocommerce_order_date_paid_present
woocommerce_order_transaction_id
fingerprint_reconciled
reconciliation_status
```

Shape estructural:

```php
'result' => [
    'public_result' => /* valor de fase por auditar */,
    'woocommerce_order_is_paid' => /* por auditar */,
    'woocommerce_order_date_paid_present' => /* por auditar */,
    'woocommerce_order_transaction_id' => /* por auditar */,
    'fingerprint_reconciled' => /* por auditar */,
    'reconciliation_status' => /* por auditar */,
],
```

No se declara ningún comentario anterior como valor materializable. Las seis
claves quedan cerradas en ubicación y nesting, no todavía en valores, porque la
auditoría se detiene antes de alcanzarlas.

## 8. Autoridades inspeccionadas

| Autoridad | Archivo/FQCN/método | Contrato encontrado | Replay |
|---|---|---|---|
| shape común | corrección de fixtures §9 | `rows/actions/result/mutations`; tipos narrados como conteos o catálogos | manifest inmutable |
| observable WR-06 | mismo documento §22.2 | seis claves y valores propuestos | relectura WooCommerce/DB |
| proyección | mismo documento §23.5 | resultados, fingerprint, reconciliation y cardinalidad pertenecen a expected | no define nesting de rows |
| manifest | corrección complementaria §4 | `expected:object` obligatorio | child verifica hash |
| resultado WooCommerce | `WooCommercePaymentCompletionResult`; `isValid(string): bool` | catálogo productivo `wc_*` | el outcome puede ser idempotente |
| aplicación | `WooCommercePaymentCompletionHandler`; `process(int): WooCommercePaymentCompletionOutcome` | aplica/verifica pedido y fingerprint | preserva referencia |
| retorno | `WebpayReturnService`; `finalize()`/`repeated()` | primera entrega y respuesta repetida | relee `result_json` |
| pedido | `WC_Order::is_paid()`, `get_date_paid('edit')`, `get_transaction_id('edit')` | observables públicos | se releen |
| conciliación | `PaymentReconciliation` y repositorio | status durable | se persiste |

Ninguna autoridad inspeccionada define un objeto literal WR-06 para
`expected.rows`.

## 9. `expected.rows`

Es el primer campo auditado después de resolver el shape. §9 solo dice que
`expected` contiene `rows` con enteros no negativos o catálogos del caso. §23.5
asigna la “cardinalidad de filas” a `expected`. Las matrices narrativas describen
filas esperadas, pero no establecen:

- tipo exacto de `rows`;
- conjunto de claves/tablas para WR-06;
- nesting por fase o total;
- conteos literales de primera entrega y replay;
- si cuenta filas iniciales, finales, creadas o mutadas;
- tratamiento del recurso WooCommerce sin tabla lógica A11;
- relación con `fixture_ids` y `rows_to_create`;
- si se expresan referencias simbólicas o solo cardinalidades.

No puede asumirse `[]`, `{}`, cero, un mapa de las quince tablas ni una copia de
`fixture_ids`. `expected.rows` describe el observable final y no puede absorber
la preparación futura de `rows_to_create`.

Resultado: tipo, keys, nesting y valores permanecen indeterminados.

## 10. `expected.actions`

No ejecutado/auditado tras el primer bloqueo. La estructura queda reservada en
la raíz, pero esta corrección no decide si contiene conteos HTTP, gateway,
Action Scheduler, aplicaciones WooCommerce u otra evidencia. No se confunde con
la categoría posterior `external_actions` y no se supone vacía.

## 11. `public_result`

No ejecutado/auditado tras el primer bloqueo. §22.2 propone `APPLIED_NOW`, pero
el producto usa literales `wc_applied_now` y el replay puede exponer un resultado
idempotente distinto. Esta corrección no decide su representación por fase antes
de resolver `rows`.

## 12. Estado WooCommerce

No ejecutado/auditado tras el primer bloqueo. Se preservan como antecedentes
los observables propuestos `is_paid() === true` y fecha pagada presente tras
recarga. No se fija timestamp ni status WooCommerce final. Sus valores de
primera entrega/replay no se incorporan a un manifest parcial.

## 13. Transaction ID

No ejecutado/auditado tras el primer bloqueo. La referencia payload ya cerrada
permanece:

```text
va-wp-v1-1d599ae282242c619aed248200f23d22d670a5326db48739462338929565af83
```

No se reabre su derivación y no se declara aún el objeto exacto de expectativa.

## 14. Reconciliación

No ejecutado/auditado tras el primer bloqueo. `fingerprint_reconciled` y
`reconciliation_status` conservan su ubicación dentro de `result`, pero sus
valores y fuentes por fase no se materializan en esta corrección.

## 15. `expected.mutations`

No ejecutado/auditado tras el primer bloqueo. No se confunde con
`allowed_mutations` o `forbidden_mutations`, no se supone vacío y no se copian
las mutaciones narrativas de §22.3 sin un contrato de keys/nesting/conteos.

## 16. Manifest

Forma estructural exacta, no materializable mientras `rows` esté abierto:

```php
'expected' => [
    'rows' => /* BLOQUEADO */,
    'actions' => /* NO AUDITADO */,
    'result' => [
        'public_result' => /* NO AUDITADO */,
        'woocommerce_order_is_paid' => /* NO AUDITADO */,
        'woocommerce_order_date_paid_present' => /* NO AUDITADO */,
        'woocommerce_order_transaction_id' => /* NO AUDITADO */,
        'fingerprint_reconciled' => /* NO AUDITADO */,
        'reconciliation_status' => /* NO AUDITADO */,
    ],
    'mutations' => /* NO AUDITADO */,
],
```

Esquema JSON estructural, no un JSON válido de manifest:

```text
{"expected":{"rows":<BLOQUEADO>,"actions":<NO_AUDITADO>,"result":{"public_result":<NO_AUDITADO>,"woocommerce_order_is_paid":<NO_AUDITADO>,"woocommerce_order_date_paid_present":<NO_AUDITADO>,"woocommerce_order_transaction_id":<NO_AUDITADO>,"fingerprint_reconciled":<NO_AUDITADO>,"reconciliation_status":<NO_AUDITADO>},"mutations":<NO_AUDITADO>}}
```

Los marcadores angulares están prohibidos como datos. Se muestran solo para
acreditar nesting y detención. No existe representación PHP/JSON completa ni
manifest contractual completo, porque producirlos violaría la prohibición de
placeholders indefinidos.

## 17. Primera entrega

No se materializa una matriz funcional de primera entrega. La estructura exige
en el futuro: cardinalidades finales en `rows`, acciones observadas en
`actions`, seis resultados anidados y mutaciones concretas. El producto puede
aportar evidencia, pero la falta de contrato de `rows` impide definir assertions
completas y ejecutar el caso.

## 18. Replay

No se materializa una matriz funcional de replay. Permanecen vinculantes la
preservación del pedido, fecha, transaction ID, fingerprint, reconciliation y
ausencia de duplicados; falta definir cómo `rows` representa esos conteos entre
entrega inicial, primer replay y segundo replay.

## 19. Matriz adversarial

| # | Entrada/shape | Autoridad | Primera entrega/replay | Decisión/motivo |
|---:|---|---|---|---|
| 1 | raíz de cuatro claves | esta corrección | estructura correcta | acepta shape |
| 2 | seis claves en raíz | regla de precedencia | no ejecutar | rechaza nesting |
| 3 | unión plana de diez | regla de precedencia | no ejecutar | rechaza extras |
| 4 | falta `result` | shape raíz | no manifest | rechaza |
| 5 | falta `rows` | shape raíz | no assertions | rechaza |
| 6 | falta `actions` | shape raíz | no assertions | rechaza |
| 7 | falta `mutations` | shape raíz | no assertions | rechaza |
| 8 | clave raíz extra | shape exacto | no manifest | rechaza |
| 9 | clave result extra | nesting exacto | no manifest | rechaza |
| 10 | orden distinto | orden contractual | serialization no canónica | rechaza |
| 11 | public result incorrecto | no auditado | no ejecutar | pendiente, no acepta |
| 12 | `is_paid=false` | antecedente §22 | contradice objetivo | rechaza conceptual |
| 13 | fecha ausente | antecedente §22 | contradice objetivo | rechaza conceptual |
| 14 | transaction ID distinto | payload cerrado | incoherente | rechaza |
| 15 | buy order como transaction ID | sin autoridad | incoherente | rechaza |
| 16 | fingerprint no reconciliado | antecedente §22 | fallo funcional | rechaza conceptual |
| 17 | status incompatible | producto/§22 | fallo funcional | rechaza conceptual |
| 18 | primera entrega funcional | rows abierto | no certificable | no ejecutar |
| 19 | replay inmediato | rows abierto | no certificable | no ejecutar |
| 20 | segundo replay | rows abierto | no certificable | no ejecutar |
| 21 | cambia configuración | no autoritativa | evidencia debe preservarse | no resuelve bloqueo |
| 22 | evidencia financiera alterada | payload/DB | incoherente | rechaza |
| 23 | fila duplicada | idempotencia | debería fallar | falta mapa rows |
| 24 | acción repetida | política WR-06 | debería fallar | actions no auditado |
| 25 | mutación reemplazada | evidencia inicial | debería fallar | mutations no auditado |
| 26 | manifest parcial | contrato cerrado | no child | rechaza |
| 27 | referencia sin catálogo | referencias simbólicas | no resolver | rechaza |
| 28 | fixture ID no asignado | dependencia futura | no discovery | rechaza/pendiente |
| 29 | `rows=[]` | sin autoridad | oculta cardinalidades | rechaza |
| 30 | `rows` copia fixture_ids | roles distintos | confunde IDs/conteos | rechaza |

Solo se cierran las decisiones estructurales de las filas 1–10. Las restantes
documentan consecuencias o campos no alcanzados, no valores nuevos.

## 20. Bloqueo siguiente

```text
case: A11-WR-06
category: expected
field: expected.rows
reason: las autoridades asignan la cardinalidad de filas a expected pero no
        definen tipo, key set, nesting, tablas ni conteos literales por fase WR-06
required_authority: contrato normativo literal de expected.rows que distinga
                    resultado observable de fixture_ids/rows_to_create y cierre
                    primera entrega y replay sin defaults ni discovery
```

Este es el primer campo sin autoridad en el orden obligatorio. No se auditan
`expected.actions`, los seis valores de `expected.result` ni
`expected.mutations`; tampoco se avanza a `fixture_ids`.

## 21. Categorías

Cerradas para WR-06: `case_id`, `harness`, `profile`, `variations`, `payload`.
`expected` tiene shape y precedencia cerrados, pero contenido incompleto.
Pendientes: `expected.rows` y, tras resolverlo, los campos restantes de expected;
luego `fixture_ids`, `rows_to_create`, `initial_state`, `external_actions`,
`processes`, `allowed_mutations`, `forbidden_mutations`, `cleanup`, `budget`.

Los otros 30 casos permanecen sin cambios. La siguiente unidad normativa exacta
es `expected.rows` de WR-06.

## 22. Veredictos

**CONTRATO SHAPE Y PRECEDENCIA EXPECTED WR-06 CERRADO**

**A11-WR-06 CONTINÚA BLOQUEADO POR CATEGORÍA EXPECTED INDETERMINADA**

**A11 CONTINÚA BLOQUEADO POR MATRIZ DE FIXTURES INCOMPLETA**

No se emite `CONTRATO EXPECTED WR-06 CERRADO`, no se declara cerrada la
categoría y no se declara A11 implementable.

## 23. Integridad

Esta ejecución crea exclusivamente este documento. Conserva cuatro cambios
tracked, cinco hashes protegidos, documentos antecedentes, staging,
`artifacts/` y accessor tipado. No implementa A11 ni realiza commit o push.
