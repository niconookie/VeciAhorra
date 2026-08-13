# Corrección normativa complementaria A11-WR-06 — ownership y buy order

## 1. Propósito

Esta corrección fija el `ownership_token` contractual de `A11-WR-06`, deriva
con autoridades existentes sus referencias Webpay y reevalúa los componentes
9–15 del fingerprint financiero.

## 2. Alcance

No implementa A11, no crea fixtures ejecutables, validadores, auxiliares ni
harnesses, no modifica producto, pruebas o documentos anteriores y no realiza
commit ni push. Toda precedencia nueva se limita al caso `A11-WR-06`.

## 3. Antecedentes vinculantes

Permanecen cerrados los contratos de balance, `WebpayCommitResult::toArray()`,
JSON financiero, ambiente, identidad mercantil y su hash. Los componentes 1–8
son:

```text
schema=webpay-financial-v1
provider=webpay_plus
environment=integration
merchant_identity_hash=d057527e474218f5825890879be68d020de7e76c1a39cb580b5fe0b626cf42b6
provider_status=AUTHORIZED
response_code=0
amount_clp=15990
currency=CLP
```

## 4. Evidencia inspeccionada

| Autoridad | Archivo/método | Evidencia |
|---|---|---|
| contrato A11 §4 | `docs/durable-retry-production-activation-a11-fixture-contract-normative-correction.md` | dominio general: 32 bytes aleatorios como 64 hex lowercase; manifest `ownership_token` |
| contrato A11 §25 | mismo archivo, algoritmos de checkout/sesión | derivaciones domain-separated desde `case_id` y ownership |
| `VeciAhorra\Modules\Payments\Gateway\WebpayTransactionReference` | `app/Modules/Payments/Gateway/WebpayTransactionReference.php`; `buyOrder(string $checkoutId, string $idempotencyKey): string` | SHA-256, prefijo `VA`, uppercase, primeros 24 hex |
| misma clase | `sessionId(string $checkoutId): string` | SHA-256, prefijo `VA-`, uppercase, primeros 58 hex |
| `VeciAhorra\Modules\Payments\Reconciliation\DTO\DurablePaymentOrigin` | constructor y accessors | valida y conserva buy order/session ID |
| `VeciAhorra\Modules\Payments\Reconciliation\Repository\PaymentOriginContextRepository` | `create()` e hidratación | persiste/relee `buy_order` y `financial_session_id` |
| `VeciAhorra\Modules\Payments\Service\WebpayReturnService` | `finalize()`, `repeated()` y reconstrucción | compara referencias esperadas y relee JSON financiero en replay |
| `VeciAhorra\Modules\Payments\Gateway\WebpayCommitResult` | `toArray(): array` | incluye siempre `buy_order` y `session_id` |
| `VeciAhorra\Modules\Payments\Reconciliation\DTO\FinancialFingerprintComponents` | `canonicalData(): array` | posiciones canónicas 9–15 |
| `VeciAhorra\Modules\Payments\Reconciliation\Support\FinancialFingerprint` | `canonicalJson()` y `make()` | JSON sin slash escaping y SHA-256 lowercase |

## 5. Autoridades y precedencia

1. Esta corrección es la autoridad de asignación del literal WR-06.
2. Los algoritmos de §25 y `WebpayTransactionReference` son las autoridades de
   derivación; el harness no puede imitarlos ni sustituir resultados.
3. Los repositorios y el manifest son autoridades de persistencia.
4. La evidencia persistida es autoridad durante replay.

La regla general de §4 genera `ownership_token` mediante `random_bytes(32)`.
Para `A11-WR-06` exclusivamente, esta corrección prevalece y reemplaza esa
generación por el literal siguiente. Los otros 30 casos conservan la regla
general. Así se elimina aleatoriedad sin cambiar el dominio ni el producto.

## 6. Dominio del ownership token

Contrato: string ASCII/UTF-8 byte-equivalente, exactamente 64 caracteres y 64
bytes, regex `/^[a-f0-9]{64}$/D`, no nulo, sin espacios ni normalización. No es
secreto ni credencial. Es independiente de reloj, PK, equipo, WordPress,
entorno, commerce code, API key y ejecución.

## 7. Token contractual

```text
@fixture.ownership_token=a110000000000000000000000000000000000000000000000000000000000006
```

PHP y JSON:

```php
'a110000000000000000000000000000000000000000000000000000000000006'
```

```json
"a110000000000000000000000000000000000000000000000000000000000006"
```

El prefijo `a11` y sufijo `06` separan el caso; los 59 ceros intermedios son
significativos. El token es completamente sintético y no se obtiene de una
ejecución anterior, base de datos o fuente ambiental.

## 8. Normalización

No existe normalización: original, valor canónico y bytes de entrada coinciden.
No se aplican `trim`, case folding, decode, prefijo adicional ni NUL final. Para
las derivaciones, `case_id` es el literal ASCII `A11-WR-06`.

## 9. Derivación del buy order

Primero se derivan las dos entradas productivas cerradas por §25:

```php
$checkoutMaterial = "veciahorra-a11.checkout-public-id.v1\0"
    . 'A11-WR-06' . "\0"
    . 'a110000000000000000000000000000000000000000000000000000000000006';
$checkoutPublicId = 'chk_' . rtrim(strtr(
    base64_encode(hash('sha256', $checkoutMaterial, true)),
    '+/', '-_'
), '=');

$idempotencyMaterial = "veciahorra-a11.payment-session-idempotency.v1\0"
    . 'A11-WR-06' . "\0"
    . 'a110000000000000000000000000000000000000000000000000000000000006';
$paymentSessionIdempotencyKey = 'a11-pay-'
    . hash('sha256', $idempotencyMaterial, false);
```

Resultados exactos:

```text
checkout_public_id=chk_AYRwe4YWQMHZbnqyEnF0eaTUlwzGWbzQ0Ybx8Q0eJ5Q
payment_session_idempotency_key=a11-pay-675fde995a93ca473d69aff6e22fc1488e1e7d9a1ba3afc0b0bad48e63b5341f
```

La autoridad productiva ejecuta:

```php
'VA' . strtoupper(substr(hash(
    'sha256',
    $checkoutPublicId . '|' . $paymentSessionIdempotencyKey
), 0, 24))
```

La preimagen exacta del SHA-256 del buy order es, sin comillas ni newline:

```text
chk_AYRwe4YWQMHZbnqyEnF0eaTUlwzGWbzQ0Ybx8Q0eJ5Q|a11-pay-675fde995a93ca473d69aff6e22fc1488e1e7d9a1ba3afc0b0bad48e63b5341f
```

El separador es un byte `|`; se conservan los primeros 24 caracteres hex del
digest, se convierten a uppercase y se antepone `VA`. No hay truncamiento de
bytes binarios ni base64 en esta fase.

## 10. Literal final

```text
@derived.webpay_buy_order=VA1A708661538E05673B9988BE
```

Tipo string, 26 caracteres/bytes ASCII, regex `/^VA[A-F0-9]{24}$/D`, casing
uppercase. PHP y JSON:

```php
'VA1A708661538E05673B9988BE'
```

```json
"VA1A708661538E05673B9988BE"
```

Es exactamente el componente canónico 9. No se usa directamente ownership,
payment ID, token Webpay ni transaction reference.

## 11. Manifest

```text
ownership_token=a110000000000000000000000000000000000000000000000000000000000006
checkout_public_id=chk_AYRwe4YWQMHZbnqyEnF0eaTUlwzGWbzQ0Ybx8Q0eJ5Q
payment_session_idempotency_key=a11-pay-675fde995a93ca473d69aff6e22fc1488e1e7d9a1ba3afc0b0bad48e63b5341f
webpay_buy_order=VA1A708661538E05673B9988BE
```

`ownership_token` es fixture y no integra directamente el fingerprint.
`webpay_buy_order` es derived, integra el fingerprint, aparece en JSON y se
conserva en replay. No se introducen aliases.

## 12. Persistencia

El ownership vive en `manifest.json` y en las dos metas privadas de ownership
del pedido WooCommerce definidas por el contrato; no existe columna financiera
`ownership_token`. Checkout public ID se persiste en `checkouts.public_id` y la
idempotency key en `payment_sessions.idempotency_key`.

El buy order se persiste en:

- `payment_origin_contexts.buy_order`;
- `webpay_returns.buy_order`;
- `webpay_returns.result_json.financial.buy_order`;
- `webpay_returns.normalized_payload_json` dentro del canonical data.

`WebpayCommitResult::toArray()` lo incluye siempre como `buy_order`. El
materializador exige igualdad con el origen durable antes de persistir.

## 13. Primera entrega

Allocation usa el literal del manifest una sola vez, deriva checkout,
idempotency key y buy order, persiste las identidades y configura el stub con el
mismo buy order. El commit, el array `financial` y el componente canónico 9
contienen `VA1A708661538E05673B9988BE`.

## 14. Replay

Replay no genera ownership ni buy order nuevo. `WebpayReturnService` relee la
sesión y/o el contexto durable, compara el buy order esperado estrictamente y
reconstruye el commit desde `result_json.financial`. El repositorio financiero
relee el buy order persistido y verifica el fingerprint. Hora, equipo, comercio,
gateway y ambiente posteriores no cambian el valor inicial.

## 15. Unicidad y colisiones

`checkouts.public_id` tiene constraint unique; `payment_sessions` hace unique
`(checkout_id,idempotency_key)`. `payment_origin_contexts` no posee unique sobre
`buy_order`: sus unicidades son public ID, payment attempt, origin key y token
hash. `webpay_returns` y conciliaciones protegen token/fingerprint según sus
constraints; no se inventa unicidad global de buy order.

El mismo buy order en replay es reutilización idempotente. Una segunda
transacción distinta no puede apropiarse del contexto: debe tener identidades y
origen propios; un conflicto con evidencia existente falla por las autoridades
de identidad/fingerprint. Otro caso no puede reutilizar este ownership porque
el literal está reservado exclusivamente a WR-06.

## 16. Matriz adversarial

| # | Escenario | Normalización/resultado | Persistencia y replay | Decisión |
|---:|---|---|---|---|
| 1 | ownership exacto | ninguna; literal válido | manifest estable | acepta |
| 2 | buy order exacto | derivación productiva | todas las copias iguales | acepta |
| 3 | primera entrega | deriva una vez | persiste exacto | acepta |
| 4 | replay inmediato | no regenera | relee exacto | acepta |
| 5 | otro equipo | sin fuente local | mismo resultado | acepta |
| 6 | cambia hora | no participa | mismo resultado | acepta |
| 7 | cambia commerce code | no participa | mismo resultado | acepta |
| 8 | cambia gateway | no autoritativo | mismo resultado | acepta |
| 9 | ownership ausente | falta autoridad | no allocation | rechaza |
| 10 | ownership `null` | tipo inválido | no persistir | rechaza |
| 11 | ownership vacío | falla regex | no persistir | rechaza |
| 12 | espacio inicial | no hay trim; falla regex | no persistir | rechaza |
| 13 | espacio final | no hay trim; falla regex | no persistir | rechaza |
| 14 | casing distinto | difiere literal | no sustituir | rechaza |
| 15 | carácter no hex | falla regex | no persistir | rechaza |
| 16 | menos de 64 | falla longitud | no persistir | rechaza |
| 17 | más de 64 | falla longitud | no persistir | rechaza |
| 18 | buy order ausente | commit/origen inválido | no materializar | rechaza |
| 19 | buy order alterado | comparación estricta falla | conserva evidencia | rechaza |
| 20 | truncamiento incorrecto | no coincide función | no persistir | rechaza |
| 21 | prefijo incorrecto | falla contrato | no persistir | rechaza |
| 22 | casing incorrecto | falla igualdad/regex | no persistir | rechaza |
| 23 | otro caso usa ownership | reservado a WR-06 | no allocation | rechaza |
| 24 | transacción distinta usa buy order | origen/fingerprint incompatible | conflicto | rechaza |
| 25 | replay usa mismo buy order | evidencia idempotente | conserva filas | acepta |

No se crean reason codes ni excepciones. Aplican los conflictos y validaciones
productivas existentes.

## 17. Reevaluación canónica

El token fijo permite resolver todos los componentes restantes:

| # | Componente | Literal | PHP/JSON | Origen/persistencia | Estado |
|---:|---|---|---|---|---|
| 1 | `schema` | `webpay-financial-v1` | string/string | constante/canonical JSON | cerrado |
| 2 | `provider` | `webpay_plus` | string/string | constante/columnas | cerrado |
| 3 | `environment` | `integration` | string/string | contrato/origen+return | cerrado |
| 4 | `merchant_identity_hash` | `d057527e474218f5825890879be68d020de7e76c1a39cb580b5fe0b626cf42b6` | string/string | contrato/origen+return | cerrado |
| 5 | `provider_status` | `AUTHORIZED` | string/string | commit/return | cerrado |
| 6 | `response_code` | `0` | int/number | commit/return | cerrado |
| 7 | `amount_clp` | `15990` | int/number | origen+commit/return | cerrado |
| 8 | `currency` | `CLP` | string/string | constante/origen+return | cerrado |
| 9 | `buy_order` | `VA1A708661538E05673B9988BE` | string/string | esta corrección/origen+return+JSON | cerrado |
| 10 | `financial_session_id` | `VA-A5553BD410C9166C9DFC53CC7CE9FF51E59B979B3D961F12B5FF41D8E8` | string/string | `sessionId()`/origen+return+JSON | cerrado |
| 11 | `transaction_date` | `2031-08-04T12:00:00Z` | string/string | reloj WR-06/return+JSON | cerrado |
| 12 | `authorization_hash` | `2d44ac095099b08db1f00678d3b5eaf437fc88531f33ba7bfdf039bfdfa40d12` | string/string | SHA-256 de `A11E829E6CC9BB87`/return | cerrado |
| 13 | `payment_type_code` | `VD` | string/string | contrato/return+JSON | cerrado |
| 14 | `installments_number` | `0` | int/number | contrato/return+JSON | cerrado |
| 15 | `accounting_date` | `0804` | string/string | reloj WR-06/return+JSON | cerrado |

Derivados intermedios reproducibles:

```text
webpay_session_id=VA-A5553BD410C9166C9DFC53CC7CE9FF51E59B979B3D961F12B5FF41D8E8
webpay_authorization_code=A11E829E6CC9BB87
webpay_clock_digest=73bdcdcebcf8d2338bdb1bd310c2d71f2e6d23998b1eb11c55b02fcc2b28b3b1
webpay_clock_day_offset=580
webpay_clock_utc=2031-08-04T12:00:00Z
webpay_transaction_date=2031-08-04T12:00:00Z
webpay_accounting_date=0804
```

El array canónico exacto conserva el orden de la tabla. Su JSON exacto es:

```json
{"schema":"webpay-financial-v1","provider":"webpay_plus","environment":"integration","merchant_identity_hash":"d057527e474218f5825890879be68d020de7e76c1a39cb580b5fe0b626cf42b6","provider_status":"AUTHORIZED","response_code":0,"amount_clp":15990,"currency":"CLP","buy_order":"VA1A708661538E05673B9988BE","financial_session_id":"VA-A5553BD410C9166C9DFC53CC7CE9FF51E59B979B3D961F12B5FF41D8E8","transaction_date":"2031-08-04T12:00:00Z","authorization_hash":"2d44ac095099b08db1f00678d3b5eaf437fc88531f33ba7bfdf039bfdfa40d12","payment_type_code":"VD","installments_number":0,"accounting_date":"0804"}
```

Codificación: UTF-8/ASCII, sin BOM ni newline, mediante
`JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR`. Longitud exacta: 594 bytes.

```text
@derived.financial_fingerprint=1d599ae282242c619aed248200f23d22d670a5326db48739462338929565af83
@derived.webpay_transaction_reference=va-wp-v1-1d599ae282242c619aed248200f23d22d670a5326db48739462338929565af83
```

Ambos son idénticos en entrega y replay; la referencia usa exactamente
`WooCommerceTransactionReferenceFactory::fromFinancialFingerprint()`.

## 18. Bloqueo siguiente

No queda un componente indeterminado dentro de los quince ni dentro del payload
Webpay ya auditado por los antecedentes. El siguiente trabajo pendiente no es un
bloqueo de `payload`: corresponde reevaluar, en una ejecución normativa separada,
la siguiente categoría incompleta de la matriz integral de fixtures A11. Esta
corrección no la audita ni la declara cerrada.

## 19. Veredictos

**CONTRATO OWNERSHIP TOKEN WR-06 CERRADO**

**CONTRATO WEBPAY BUY ORDER WR-06 CERRADO**

**CONTRATO FINANCIAL FINGERPRINT WR-06 CERRADO**

**CONTRATO WEBPAY TRANSACTION REFERENCE WR-06 CERRADO**

**PAYLOAD WEBPAY A11-WR-06 NORMATIVAMENTE CERRADO**

**CATEGORÍA PAYLOAD A11-WR-06 CERRADA**

No se declaran cerradas categorías posteriores ni la matriz global A11.

## 20. Integridad

Esta ejecución crea exclusivamente este documento. Preserva documentos
antecedentes, cuatro cambios tracked, cinco hashes protegidos, staging,
`artifacts/` y accessor tipado. No implementa A11 ni realiza commit o push.
