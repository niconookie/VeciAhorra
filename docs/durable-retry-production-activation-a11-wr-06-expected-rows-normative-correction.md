# Corrección normativa A11-WR-06 — `expected.rows`

## 1. Propósito

Esta corrección fija el shape y la semántica de `expected.rows`, audita en
orden funcional sus tablas candidatas y se detiene en la primera cardinalidad
que no puede determinarse para `A11-WR-06`.

## 2. Alcance

No audita `expected.actions`, `expected.result`, `expected.mutations` ni
`fixture_ids`, excepto por referencias necesarias para separar responsabilidades.
No implementa A11, no crea ejecutables, no modifica producto, pruebas o
documentos anteriores y no realiza commit ni push.

## 3. Antecedentes

`expected` conserva exactamente las claves raíz `rows`, `actions`, `result`,
`mutations`. Esta corrección no altera el payload, ownership, buy order,
fingerprint o transaction reference cerrados para WR-06.

## 4. Bloqueo previo

El bloqueo recibido era la falta de tipo, keys, nesting, tablas y conteos por
fase para `expected.rows`. La inspección confirma que el contrato anterior solo
asignaba conceptualmente cardinalidades a `expected`, sin objeto literal.

## 5. Decisión de shape

`expected.rows` es un array asociativo PHP y objeto JSON con exactamente estas
dos claves, en este orden:

```php
[
    'first_delivery' => [/* cardinalidades absolutas finales */],
    'replay' => [/* cardinalidades absolutas finales */],
]
```

`first_delivery` es el snapshot persistido después de completar y recargar la
primera devolución, antes del replay. `replay` es el snapshot persistido después
de completar y recargar el replay. Ambos contienen cardinalidades absolutas,
nunca deltas, IDs, filas, estados o mutaciones.

Quedan prohibidas las claves `before`, `after`, `created`, `updated`, `deleted`,
`delta`, `minimum`, `maximum`, `at_least`, `at_most` y `unchanged`.

## 6. Separación de categorías

- `fixture_ids` identifica recursos concretos; `expected.rows` no contiene IDs.
- `rows_to_create` prepara filas antes del caso; `expected.rows` observa el final.
- `initial_state` describe el estado previo; no se anida en snapshots finales.
- `expected.mutations` describe cambios concretos; no duplica cardinalidades.
- `allowed_mutations` y `forbidden_mutations` fijan límites operacionales; no
  forman parte de `rows`.

## 7. Tablas incluidas determinadas

Hasta el primer bloqueo quedan acreditadas como candidatas incluidas:

```text
checkouts
payment_sessions
payment_origin_contexts
webpay_returns
payment_reconciliations
```

Todas son tablas lógicas VeciAhorra, contienen identidad o evidencia propia de
WR-06 y podrían revelar duplicación en replay. El orden sigue dependencia y
pipeline productivo, no orden alfabético.

No se declara todavía cerrado el key set completo: el candidato funcional
siguiente, `durable_retry_schedules`, permanece indeterminado.

## 8. Tablas excluidas

Quedan excluidos de `expected.rows`:

- almacenamiento interno WooCommerce (`wp_posts`, `wp_postmeta`, HPOS, lookups,
  cache, transients y metadatos auxiliares);
- `orders` y `checkout_orders` VeciAhorra: WR-06 crea un recurso
  `WC_Order`, no una fila de esos recursos lógicos;
- `payments`, `payment_orders`, business/delivery/fulfillment completions: no
  existe autoridad que los cree dentro de las dos devoluciones H4;
- logs, cache, tablas ajenas y conteos globales;
- Action Scheduler en este punto: sus acciones pertenecen a `expected.actions`
  o `external_actions` salvo autoridad de cardinalidad expresa.

La exclusión WooCommerce es portable entre datastore clásico y HPOS. El pedido
se validará mediante `expected.result` y `expected.mutations`.

## 9. Nombres canónicos

Las claves usan los nombres lógicos estables del catálogo A11, sin prefijo
WordPress. El nombre físico se resuelve como:

```text
$wpdb->prefix . Config::TABLE_PREFIX . <logical_name>
```

No se aceptan `wp_*`, `va_*`, prefijos concretos ni aliases. La tabla bloqueada
usa el nombre lógico `durable_retry_schedules`.

## 10. Predicados de ownership

| logical_name | Autoridad física | Predicado específico del fixture |
|---|---|---|
| `checkouts` | `CheckoutSchema`/repositorio | `public_id = chk_AYRwe4YWQMHZbnqyEnF0eaTUlwzGWbzQ0Ybx8Q0eJ5Q` |
| `payment_sessions` | `PaymentSessionSchema`/repositorio | checkout propio y `idempotency_key = a11-pay-675fde995a93ca473d69aff6e22fc1488e1e7d9a1ba3afc0b0bad48e63b5341f` |
| `payment_origin_contexts` | `PaymentOriginContextSchema`/repositorio | PK capturada y `buy_order = VA1A708661538E05673B9988BE`, session ID y merchant hash cerrados |
| `webpay_returns` | `WebpayReturnSchema`/repositorio | PK capturada y `token_hash` exacto del token WR-06 |
| `payment_reconciliations` | `PaymentReconciliationSchema`/repositorio | PK capturada, return/origin propios y fingerprint `1d599ae282242c619aed248200f23d22d670a5326db48739462338929565af83` |
| `durable_retry_schedules` | `DurableRetryScheduleSchema`/repositorio | stage reconciliation + subject ID propio + generation; inclusión/conteo bloqueados |

Las PK capturadas son verificaciones adicionales, no valores de `rows`. No se
descubren filas mediante consultas globales, prefijos textuales o fechas.

## 11. Primera entrega

| Tabla | Antes | Operación | Final acreditable |
|---|---|---|---:|
| `checkouts` | fila preparada | se conserva/relaciona | 1 |
| `payment_sessions` | fila preparada | se conserva/actualiza | 1 |
| `payment_origin_contexts` | origen preparado | se vincula token y conserva | 1 |
| `webpay_returns` | ausente o inbox reservada por la entrega | completa una única fila por token | 1 |
| `payment_reconciliations` | ausente | materializa una por fingerprint/origen | 1 |
| `durable_retry_schedules` | sin autoridad única | router puede producir durable, legacy o ningún schedule durable | bloqueado |

El snapshot se toma después de commit y recarga desde persistencia, antes del
replay. Objetos en memoria no acreditan conteos.

## 12. Replay

Las cinco primeras autoridades releen y reutilizan evidencia persistida. Los
constraints y comparaciones por token, origen y fingerprint impiden una segunda
fila compatible; sus conteos finales acreditables son nuevamente `1`.

`WebpayReconciliationMaterializer::resume()` vuelve a llamar
`publishRetryAuthorityCandidate()`. El router puede converger sobre schedule
durable, usar legacy, encontrar indisponibilidad o cerrar autoridad. Sin perfil
de activación/scheduler WR-06 no existe un conteo durable literal para replay.

## 13. Cardinalidades determinadas

Fragmento determinado, no objeto completo:

| logical_name | first_delivery | replay |
|---|---:|---:|
| `checkouts` | 1 | 1 |
| `payment_sessions` | 1 | 1 |
| `payment_origin_contexts` | 1 | 1 |
| `webpay_returns` | 1 | 1 |
| `payment_reconciliations` | 1 | 1 |
| `durable_retry_schedules` | indeterminado | indeterminado |

Los valores `1` son enteros PHP/números JSON, no strings ni rangos. El
fragmento no autoriza omitir el candidato bloqueado ni materializar el manifest.

## 14. Unicidad

`checkouts.public_id` es unique; `payment_sessions` protege public ID y
`(checkout_id,idempotency_key)`; origin protege public ID, attempt, origin key y
token hash; returns protege el token/evidencia según schema; reconciliation
protege return, origin key y provider+version+fingerprint. Replay reutiliza las
mismas identidades y no puede convertir una colisión incompatible en segunda
fila aceptada.

## 15. Relaciones por intento

No se halló una tabla append-only de recepciones o intentos HTTP que WR-06 deba
contar una vez por POST. `webpay_returns` actualiza/relee evidencia por token y
`payment_reconciliations` converge por fingerprint. Los intentos de schedule
viven como estado/contador o generaciones en `durable_retry_schedules`; esa
cardinalidad no puede fijarse hasta cerrar la ruta de scheduling.

## 16. Manifest

La forma canónica es exclusivamente el objeto jerárquico
`expected.rows.first_delivery/replay`; no existen entradas planas equivalentes.
No puede materializarse completamente en esta ejecución porque falta decidir si
`durable_retry_schedules` pertenece al key set y con qué conteos.

## 17. PHP

Fragmento diagnóstico no materializable:

```php
'rows' => [
    'first_delivery' => [
        'checkouts' => 1,
        'payment_sessions' => 1,
        'payment_origin_contexts' => 1,
        'webpay_returns' => 1,
        'payment_reconciliations' => 1,
        // durable_retry_schedules: BLOQUEADO
    ],
    'replay' => [
        'checkouts' => 1,
        'payment_sessions' => 1,
        'payment_origin_contexts' => 1,
        'webpay_returns' => 1,
        'payment_reconciliations' => 1,
        // durable_retry_schedules: BLOQUEADO
    ],
],
```

Los comentarios no forman parte de un fixture ni autorizan el fragmento como
manifest parcial.

## 18. JSON

No existe JSON contractual completo. La proyección de evidencia determinada es:

```json
{"rows":{"first_delivery":{"checkouts":1,"payment_sessions":1,"payment_origin_contexts":1,"webpay_returns":1,"payment_reconciliations":1},"replay":{"checkouts":1,"payment_sessions":1,"payment_origin_contexts":1,"webpay_returns":1,"payment_reconciliations":1}}}
```

Esta proyección omite deliberadamente el candidato bloqueado y, por ello, no
puede escribirse en el manifest ni validarse como `expected.rows` completo.

## 19. Validación estructural

Quedan cerradas: presencia no nula de `rows`; exactamente dos fases en orden
`first_delivery`, `replay`; ambas objetos; mismo key set y orden; valores enteros
no negativos; cero claves extra; nombres lógicos; cero discovery; cero aliases;
y presencia literal de ceros cuando una tabla contractual tenga conteo cero.

La validación del key set final permanece bloqueada por scheduling.

## 20. Matriz adversarial

| # | Input/fase | Autoridad | Cardinalidad/resultado | Aceptado y razón |
|---:|---|---|---|---|
| 1 | shape dos fases | esta corrección | estructura válida | sí, shape |
| 2 | `rows=[]` | shape | no fases | no |
| 3 | `rows={}` | shape | no fases | no |
| 4 | `rows=null` | tipo | no objeto | no |
| 5 | falta first | shape | incompleto | no |
| 6 | falta replay | shape | incompleto | no |
| 7 | fase adicional | key set | extra | no |
| 8 | fases invertidas | orden | no canónico | no |
| 9 | keys distintas | invariancia | incompleto | no |
| 10 | tablas reordenadas | orden pipeline | no canónico | no |
| 11 | tabla descubierta | cero discovery | no contractual | no |
| 12 | tabla contractual ausente | key set | incompleto | no |
| 13 | nombre físico | nombres lógicos | alias dinámico | no |
| 14 | alias de tabla | catálogo | ambiguo | no |
| 15 | conteo string | tipo | no entero | no |
| 16 | booleano | tipo | no entero estricto | no |
| 17 | null | tipo | indeterminado | no |
| 18 | negativo | dominio | inválido | no |
| 19 | rango | literal requerido | no cardinalidad | no |
| 20 | placeholder | literal requerido | no cardinalidad | no |
| 21 | conteo global | ownership | mezcla ajenos | no |
| 22 | fila ajena | ownership | cardinalidad contaminada | no |
| 23 | tabla WC interna | portabilidad | datastore variable | no |
| 24 | primera entrega 1/1 cinco tablas | repositorios | fragmento correcto | sí, parcial |
| 25 | replay 1/1 cinco tablas | idempotencia | fragmento correcto | sí, parcial |
| 26 | return duplicado | token unique/coherencia | 2 en vez de 1 | no |
| 27 | origin duplicado | origin identities | 2 en vez de 1 | no |
| 28 | reconciliation duplicada | fingerprint/origin | 2 en vez de 1 | no |
| 29 | fila legítima por intento omitida | no existe autoridad WR-06 | no demostrada | no inventar |
| 30 | durable=1 asumido | router multestado | puede variar | no |
| 31 | objeto en memoria | persistencia | no acredita DB | no |
| 32 | antes de commit | fase | snapshot prematuro | no |
| 33 | antes de recarga | fase | snapshot prematuro | no |
| 34 | manifest parcial | completitud | candidato omitido | no |
| 35 | copia rows_to_create | separación | preparación≠resultado | no |
| 36 | copia initial_state | separación | before≠final | no |
| 37 | mutaciones en rows | separación | rol incorrecto | no |
| 38 | durable=0 asumido | router multestado | puede existir gen1 | no |

No se inventan excepciones ni reason codes.

## 21. Bloqueo siguiente

```text
case: A11-WR-06
category: expected
field: expected.rows.durable_retry_schedules
reason: la materialización publica siempre un candidato, pero el router admite
        resultados legacy, durable, unavailable y authority closed; WR-06 no fija
        activation/scheduler ni una ruta única que determine inclusión y conteos
required_authority: contrato WR-06 de activación, disponibilidad y procesamiento
                    que fije si existe schedule durable y sus cardinalidades
                    literales tras first_delivery y replay
```

La ambigüedad cambia el key set: omitir la tabla y declararla con `0` o `1` son
tres decisiones incompatibles. Se detiene aquí la inspección funcional.

## 22. Categorías

`expected.rows`: shape, fases, cinco tablas y cinco pares `1/1` determinados;
key set completo bloqueado. `expected.actions`, `expected.result` y
`expected.mutations`: pendientes y no auditados. `fixture_ids`: no auditado.
Los otros 30 casos no avanzan.

## 23. Veredictos

**A11-WR-06 CONTINÚA BLOQUEADO POR EXPECTED ROWS INDETERMINADO**

**A11 CONTINÚA BLOQUEADO POR MATRIZ DE FIXTURES INCOMPLETA**

No se emite `CONTRATO EXPECTED ROWS WR-06 CERRADO`, `CONTRATO EXPECTED WR-06
CERRADO` ni cierre de categoría. La siguiente unidad normativa exacta es
`expected.rows.durable_retry_schedules`, no `expected.actions`.

## 24. Integridad

Esta ejecución crea exclusivamente este documento. Conserva cuatro cambios
tracked, cinco hashes protegidos, documentos antecedentes, staging,
`artifacts/` y accessor tipado. No implementa A11 ni realiza commit o push.
