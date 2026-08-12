# Corrección normativa complementaria A11-WR-06 — identidad mercantil

## 1. Propósito

Esta corrección cierra exclusivamente la identidad mercantil sintética de
`A11-WR-06`, su forma canónica y su `merchant_identity_hash`. Después reevalúa,
en orden productivo, si ya existe una preimagen financiera completa.

## 2. Alcance

No implementa A11, no crea fixtures ejecutables, validadores, auxiliares ni
harnesses, no modifica producto, pruebas o documentos anteriores y no autoriza
commit ni push. La identidad aquí elegida pertenece solo a `A11-WR-06`; no es
una identidad global de VeciAhorra ni una recomendación para comercios reales.

## 3. Antecedentes vinculantes

Permanecen cerrados:

```text
CONTRATO BALANCE WEBPAY WR-06 CERRADO
CONTRATO WEBPAYCOMMITRESULT TOARRAY WR-06 CERRADO
JSON FINANCIERO WR-06 CERRADO
CONTRATO FINANCIAL FINGERPRINT ENVIRONMENT WR-06 CERRADO
```

`@fixture.financial_fingerprint_environment` es el string `integration`, de 11
bytes ASCII, incluido en el fingerprint e invariante entre entrega y replay.

## 4. Evidencia inspeccionada

Se inspeccionaron estas autoridades, sin leer valores configurados ni secretos:

| FQCN | Archivo | Método o firma relevante | Evidencia |
|---|---|---|---|
| `VeciAhorra\Modules\Payments\Gateway\WebpayGatewayConfiguration` | `app/Modules/Payments/Gateway/WebpayGatewayConfiguration.php` | `__construct(string $environment, string $commerceCode, string $apiKey, string $returnUrl)` | recorta comercio y API key; valida comercio |
| `VeciAhorra\Modules\Payments\WooCommerce\WooCommercePaymentAttemptService` | `app/Modules/Payments/WooCommerce/WooCommercePaymentAttemptService.php` | `create(WC_Order $order, WebpayGatewayConfiguration $configuration, PaymentSessionContext $paymentContext, string $paymentAttemptId): WooCommercePaymentAttempt` | entrega `hash('sha256', $configuration->commerceCode)` al origen durable |
| `VeciAhorra\Modules\Payments\Service\PaymentSessionService` | `app/Modules/Payments/Service/PaymentSessionService.php` | construcción privada del origen dentro del flujo `start(...)` | aplica la misma derivación SHA-256 |
| `VeciAhorra\Modules\Payments\Reconciliation\DTO\DurablePaymentOrigin` | `app/Modules/Payments/Reconciliation/DTO/DurablePaymentOrigin.php` | `__construct(..., string $merchantIdentityHash, ...)` | exige hash hexadecimal minúsculo de 64 caracteres |
| `VeciAhorra\Modules\Payments\Reconciliation\Repository\PaymentOriginContextRepository` | `app/Modules/Payments/Reconciliation/Repository/PaymentOriginContextRepository.php` | `create(DurablePaymentOrigin $origin): int` y su hidratación | persiste y relee `merchant_identity_hash` |
| `VeciAhorra\Modules\Payments\Reconciliation\Service\WebpayReconciliationMaterializer` | `app/Modules/Payments/Reconciliation/Service/WebpayReconciliationMaterializer.php` | `materialize(string $tokenHash, DurablePaymentOrigin $origin, WebpayCommitResult $commit, string $financialStatus): MaterializedReconciliation` | copia el hash del origen a los componentes |
| `VeciAhorra\Modules\Payments\Reconciliation\DTO\FinancialFingerprintComponents` | `app/Modules/Payments/Reconciliation/DTO/FinancialFingerprintComponents.php` | `__construct(string $environment, string $merchantIdentityHash, ...)` | valida e incluye el hash en `canonicalData()` |
| `VeciAhorra\Modules\Payments\Reconciliation\Repository\ValidatedFinancialResultRepository` | `app/Modules/Payments/Reconciliation/Repository/ValidatedFinancialResultRepository.php` | `create(...)`, `materializeExisting(...)`, `findByTokenHash(...)` | persiste hash, JSON canónico y fingerprint; la hidratación verifica coherencia |

## 5. Autoridades productivas

La autoridad del dominio es `WebpayGatewayConfiguration`: primero ejecuta
`trim($commerceCode)` y luego exige `preg_match('/^\d{6,32}$/D', $commerceCode)
=== 1`. No transforma casing, no agrega prefijo y no acepta `null`, espacios,
signos ni caracteres no ASCII. La API key se valida por separado y nunca forma
parte de la derivación inspeccionada.

Las dos rutas de creación de origen inspeccionadas aplican exactamente:

```php
hash('sha256', $configuration->commerceCode)
```

PHP usa aquí salida hexadecimal minúscula (`binary=false` implícito). No hay
HMAC, salt, prefijo, separador, truncamiento ni incorporación de API key.

## 6. Dominio de la identidad

El valor de entrada productivo es un commerce code público representado como
string de 6 a 32 dígitos ASCII. La selección contractual debe sobrevivir
exactamente al `trim` y al regex productivo. Que un valor satisfaga este dominio
no lo convierte en comercio configurado ni en credencial.

## 7. Exclusión de credenciales reales

Esta corrección no consulta WordPress, variables ambientales, XAMPP, gateway,
base de datos ni archivos de configuración. Prohíbe commerce codes reales, API
keys, secretos, tokens y hashes derivados de ellos. La API key no interviene en
la preimagen. El literal elegido está reservado normativamente como dato
sintético del caso y no afirma existencia ante Webpay.

## 8. Identidad sintética seleccionada

```text
@fixture.webpay_merchant_identity = 00000000000000000000000000000006
```

Es un string, no un entero: 32 caracteres y 32 bytes ASCII. Los ceros iniciales
son significativos. El sufijo `06` separa semánticamente este fixture, mientras
la secuencia completa de ceros evita presentar un commerce code conocido. Es
estable, no secreta, sin espacios y sin dependencia ambiental.

Representaciones:

```php
'00000000000000000000000000000006'
```

```json
"00000000000000000000000000000006"
```

```text
webpay_merchant_identity=00000000000000000000000000000006
```

## 9. Normalización exacta

Entrada original y salida normalizada son idénticas:

```text
trim("00000000000000000000000000000006")
= "00000000000000000000000000000006"
```

Encoding: ASCII, compatible byte por byte con UTF-8. No hay BOM ni terminador
NUL. Longitud: 32 caracteres, 32 bytes. Regex de entrada:
`/^\d{6,32}$/D`.

## 10. Preimagen exacta

La preimagen de `merchant_identity_hash` es exactamente, sin comillas ni salto
de línea:

```text
00000000000000000000000000000006
```

Bytes hexadecimales exactos:

```text
3030303030303030303030303030303030303030303030303030303030303036
```

Cadena reproducible:

```text
00000000000000000000000000000006
→ trim sin cambio
→ 32 bytes ASCII indicados arriba
→ SHA-256, salida hexadecimal
→ d057527e474218f5825890879be68d020de7e76c1a39cb580b5fe0b626cf42b6
```

## 11. Algoritmo

La operación vinculante es:

```php
$merchantIdentityHash = hash(
    'sha256',
    '00000000000000000000000000000006'
);
```

No se permite `hash_hmac`, SHA alternativo, forma binaria, mayúsculas,
truncamiento, concatenación de ambiente o uso de material secreto.

## 12. Hash literal

```text
@derived.financial_fingerprint_merchant_identity_hash = d057527e474218f5825890879be68d020de7e76c1a39cb580b5fe0b626cf42b6
```

Tipo: string. Longitud: 64 caracteres/bytes ASCII. Casing: minúsculas. Regex:
`/^[a-f0-9]{64}$/D`.

PHP y JSON:

```php
'd057527e474218f5825890879be68d020de7e76c1a39cb580b5fe0b626cf42b6'
```

```json
"d057527e474218f5825890879be68d020de7e76c1a39cb580b5fe0b626cf42b6"
```

## 13. Manifest

Los nombres canónicos de esta corrección son:

```text
webpay_merchant_identity=00000000000000000000000000000006
webpay_merchant_identity_hash=d057527e474218f5825890879be68d020de7e76c1a39cb580b5fe0b626cf42b6
```

La identidad original aparece en manifest como autoridad auditable de
construcción, pero no se persiste en las tablas financieras. El hash también
aparece en manifest y es el valor que integra origen, resultado y fingerprint.
No se autorizan aliases ni sustitución desde runtime.

## 14. Persistencia

`PaymentOriginContextRepository` persiste el hash directamente en
`payment_origin_contexts.merchant_identity_hash`; la identidad original no se
persiste. `ValidatedFinancialResultRepository` lo copia a
`webpay_returns.merchant_identity_hash`, también lo incorpora en
`normalized_payload_json` y persiste el fingerprint derivado. La identidad
original queda solo en el manifest contractual.

La materialización obtiene el hash de `DurablePaymentOrigin`, no vuelve a leer
commerce code ni API key. Una discordancia entre evidencia persistida y
fingerprint reconstruido produce el error de coherencia ya existente.

## 15. Replay

`WebpayReconciliationMaterializer::resume()` recupera el resultado por token
hash. El repositorio hidrata los componentes desde la fila persistida, incluido
`merchant_identity_hash`, reconstruye el fingerprint y exige `hash_equals` con
el persistido. La transaction reference se deriva de ese fingerprint estable.

Por tanto, cambiar commerce code, gateway, variables o equipo después de la
primera entrega no altera la evidencia. El replay no recalcula este hash desde
configuración mercantil mutable.

## 16. Matriz adversarial

| # | Entrada/escenario | Autoridad y normalización | Resultado/persistencia/replay | Decisión |
|---:|---|---|---|---|
| 1 | identidad canónica exacta | fixture; `trim` sin cambio | hash literal; se persiste hash | acepta |
| 2 | primera entrega | manifest | origen y resultado reciben mismo hash | acepta |
| 3 | replay inmediato | filas persistidas | hash/fingerprint idénticos | acepta |
| 4 | cambia commerce code configurado | no autoritativo | replay conserva fila inicial | acepta replay |
| 5 | cambia gateway | no autoritativo | sin cambio de evidencia | acepta replay |
| 6 | otro equipo | manifest + persistencia | mismo hash | acepta |
| 7 | identidad ausente | falta autoridad | no materializar fixture | rechaza |
| 8 | identidad `null` | constructor recibe string | no es valor contractual | rechaza |
| 9 | string vacío | falla regex tras `trim` | no persistir | rechaza |
| 10 | espacio inicial | no coincide literal; `trim` lo ocultaría | manifest no canónico | rechaza |
| 11 | espacio final | no coincide literal; `trim` lo ocultaría | manifest no canónico | rechaza |
| 12 | casing diferente | no aplica a dígitos; cualquier otro byte difiere | no sustituir | rechaza |
| 13 | carácter no ASCII | falla regex | no persistir | rechaza |
| 14 | commerce code real | prohibición de seguridad | no leer ni persistir | rechaza |
| 15 | API key real | no pertenece a la derivación | no leer ni persistir | rechaza |
| 16 | hash incorrecto | manifest versus SHA-256 | conflicto; no continuar | rechaza |
| 17 | hash mayúsculo | falla forma canónica | no persistir | rechaza |
| 18 | hash truncado | falla longitud/regex | no persistir | rechaza |
| 19 | longitud distinta de 64 | validación de hash | no persistir | rechaza |
| 20 | algoritmo distinto de SHA-256 | contradice producto | resultado no autoritativo | rechaza |

No se crean reason codes nuevos. Aplican las validaciones y excepciones
productivas existentes; los rechazos contractuales previos a ejecución no
inventan una excepción runtime.

## 17. Reevaluación de los quince componentes

| Orden | Componente | Literal/tipo PHP y JSON | Origen | Estado |
|---:|---|---|---|---|
| 1 | `schema` | `webpay-financial-v1`; string/string | constante productiva | cerrado |
| 2 | `provider` | `webpay_plus`; string/string | constante productiva | cerrado |
| 3 | `environment` | `integration`; string/string | corrección antecedente | cerrado |
| 4 | `merchant_identity_hash` | `d057527e474218f5825890879be68d020de7e76c1a39cb580b5fe0b626cf42b6`; string/string | esta corrección | cerrado |
| 5 | `provider_status` | `AUTHORIZED`; string/string | payload WR-06 | cerrado |
| 6 | `response_code` | `0`; int/number | payload WR-06 | cerrado |
| 7 | `amount_clp` | `15990`; int/number | orden/payload WR-06 | cerrado |
| 8 | `currency` | `CLP`; string/string | constante productiva | cerrado |
| 9 | `buy_order` | `@derived.webpay_buy_order`; string/string | derivación que requiere `@manifest.ownership_token` asignado | bloqueado como literal global |
| 10 | `financial_session_id` | `@derived.webpay_session_id`; string/string | derivación que requiere asignación | no evaluado después del primer bloqueo |
| 11 | `transaction_date` | `@derived.webpay_transaction_date`; string/string | reloj sintético dependiente de asignación | no evaluado |
| 12 | `authorization_hash` | SHA-256 de `@fixture.webpay_authorization_code`; string/string | contrato antecedente | no evaluado |
| 13 | `payment_type_code` | `VD`; string/string | payload WR-06 | no evaluado |
| 14 | `installments_number` | `0`; int/number | payload WR-06 | no evaluado |
| 15 | `accounting_date` | `@derived.webpay_accounting_date`; string/string | reloj sintético dependiente de asignación | no evaluado |

Los ocho primeros componentes quedan determinados. No se publica array canónico,
JSON byte por byte, fingerprint o transaction reference sobre referencias
simbólicas: eso sería hashear placeholders, no evidencia del fixture asignado.

## 18. Primer bloqueo siguiente

```text
case: A11-WR-06
category: payload
field: financial_fingerprint.buy_order
reason: el contrato define una derivación desde la identidad asignada del fixture,
        pero no existe en esta ejecución normativa un ownership token materializado
        que permita publicar el literal final y la preimagen byte por byte
required_authority: manifest asignado de A11-WR-06 con ownership_token y
                    webpay_buy_order derivado exacto
```

Este es el primer componente indeterminado según el orden canónico. No se
adelanta el cierre de componentes posteriores ni de categorías posteriores.

## 19. Veredictos

**CONTRATO IDENTIDAD MERCANTIL SINTÉTICA WR-06 CERRADO**

**CONTRATO MERCHANT IDENTITY HASH WR-06 CERRADO**

**A11-WR-06 CONTINÚA BLOQUEADO POR PAYLOAD WEBPAY INDETERMINADO**

No quedan cerrados el financial fingerprint, transaction reference, payload ni
categoría `payload`. Tampoco se declaran cerradas categorías posteriores.

## 20. Integridad

Esta ejecución crea exclusivamente este documento. Conserva los documentos
antecedentes, los cuatro cambios tracked, los cinco hashes protegidos, staging,
`artifacts/` y el accessor tipado. No implementa A11 y no realiza commit ni push.
