# Corrección normativa complementaria A11-WR-06 — ambiente financiero

## 1. Propósito y alcance

Esta corrección cierra exclusivamente `financial_fingerprint.environment` para
`A11-WR-06`, documenta la cadena productiva del fingerprint y detiene el cierre
en el primer componente posterior no determinado. No implementa A11, no altera
documentos anteriores y no autoriza fixtures, harnesses, commit o push.

## 2. Antecedentes vinculantes

Permanecen cerrados los contratos de identidades checkout/payment session,
token, authorization code, fechas, card last four, balance,
`WebpayCommitResult::toArray()` y JSON financiero del documento principal. El
bloqueo de entrada era `financial_fingerprint.environment`.

## 3. Evidencia productiva inspeccionada

- `VeciAhorra\Modules\Payments\Reconciliation\DTO\FinancialFingerprintComponents`
  en `app/Modules/Payments/Reconciliation/DTO/FinancialFingerprintComponents.php`:
  constructor, catálogo, normalización y `canonicalData()`.
- `VeciAhorra\Modules\Payments\Reconciliation\Support\FinancialFingerprint`
  en `app/Modules/Payments/Reconciliation/Support/FinancialFingerprint.php`:
  `canonicalJson()` y `make()`.
- `VeciAhorra\Modules\Payments\Reconciliation\Support\WooCommerceTransactionReferenceFactory`
  en `app/Modules/Payments/Reconciliation/Support/WooCommerceTransactionReferenceFactory.php`:
  `fromFinancialFingerprint(string $fingerprint): string`.
- `ValidatedFinancialResultRepository`, `WebpayReturnService`, schemas y pruebas
  de fingerprint/payment completion.

## 4. Autoridad normativa

La autoridad del valor es este contrato de fixture. Para WR-06:

```text
@fixture.financial_fingerprint_environment = integration
```

Es una constante normativa, no una inferencia desde WP_ENV,
`WP_ENVIRONMENT_TYPE`, host, URL, XAMPP, gateway mock, configuración, variables,
WordPress, base de datos o modo del harness.

## 5. Justificación contractual

A11-WR-06 representa una transacción financiera de integración determinista y
no evidencia productiva real. Por ello selecciona `integration` con independencia
del entorno físico. No se establece la regla general “mock implica integration”.

## 6. Contrato del valor

```text
symbol=@fixture.financial_fingerprint_environment
PHP="integration"; JSON="integration"; type=string; length=11;
regex=/^integration$/D; casing=lowercase; nullable=false;
manifest_field=financial_fingerprint_environment;
fingerprint=included; first_delivery=integration; replay=integration
```

`production`, null, string vacío, casing distinto o valor fuera del catálogo
fallan cerradamente. El valor no se descubre ni recalcula desde el ambiente.

**CONTRATO FINANCIAL FINGERPRINT ENVIRONMENT WR-06 CERRADO**

## 7. Firma y catálogo productivos

El constructor productivo recibe, en orden:

```php
new FinancialFingerprintComponents(
    string $environment,
    string $merchantIdentityHash,
    string $providerStatus,
    int $responseCode,
    mixed $amountClp,
    string $buyOrder,
    string $financialSessionId,
    ?string $transactionDate,
    ?string $authorizationHash,
    ?string $paymentTypeCode,
    ?int $installmentsNumber,
    ?string $accountingDate
)
```

`environment` admite únicamente `integration|production`; esta corrección
selecciona el primero para WR-06.

## 8. Componentes canónicos y orden

`canonicalData()` fija este orden exacto:

```text
schema, provider, environment, merchant_identity_hash, provider_status,
response_code, amount_clp, currency, buy_order, financial_session_id,
transaction_date, authorization_hash, payment_type_code,
installments_number, accounting_date
```

Constantes productivas: `schema=webpay-financial-v1`, `provider=webpay_plus`,
`currency=CLP`. Los valores ya cerrados son `environment=integration`,
`provider_status=AUTHORIZED`, `response_code=0`, `amount_clp=15990`, las
referencias buy order/session, fechas, hash de autorización, `VD`, `0` y fecha
contable. El componente siguiente aún abierto es `merchant_identity_hash`.

## 9. Algoritmo exacto del fingerprint

`FinancialFingerprint::canonicalJson()` ejecuta:

```php
json_encode(
    $components->canonicalData(),
    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
)
```

`FinancialFingerprint::make()` retorna:

```php
hash('sha256', FinancialFingerprint::canonicalJson($components))
```

El resultado es lowercase hex `/^[a-f0-9]{64}$/D`, longitud 64. La autoridad es
invocable; el fixture no copia ni altera el algoritmo.

## 10. Preimagen reproducible y límite actual

La preimagen tendrá exactamente esta forma ordenada una vez cerrado el comercio:

```json
{"schema":"webpay-financial-v1","provider":"webpay_plus","environment":"integration","merchant_identity_hash":"<BLOQUEADO>","provider_status":"AUTHORIZED","response_code":0,"amount_clp":15990,"currency":"CLP","buy_order":"@derived.webpay_buy_order","financial_session_id":"@derived.webpay_session_id","transaction_date":"@derived.webpay_transaction_date","authorization_hash":"sha256(@fixture.webpay_authorization_code)","payment_type_code":"VD","installments_number":0,"accounting_date":"@derived.webpay_accounting_date"}
```

No es lícito publicar un fingerprint literal mientras exista `<BLOQUEADO>` y
las referencias por manifest aún no tengan valores asignados. Un hash calculado
sobre placeholders no es evidencia normativa.

## 11. Transaction reference productiva

La única autoridad es:

```php
WooCommerceTransactionReferenceFactory::fromFinancialFingerprint(
    string $fingerprint
): string
```

Valida 64 lowercase hex y retorna `va-wp-v1-` seguido del fingerprint exacto.
Tipo string, longitud 73, regex `/^va-wp-v1-[a-f0-9]{64}$/D`. No hay truncamiento.
Sin fingerprint literal válido tampoco existe transaction reference literal.

## 12. Manifest contractual

Queda cerrado:

```text
financial_fingerprint_environment = integration
```

Los campos `financial_fingerprint` y `webpay_transaction_reference` serán
obligatorios después de resolver todos los componentes; por ahora permanecen
no asignables y no deben escribirse con null, vacío o placeholder. El manifest
distingue ausencia contractual bloqueada de valor calculado.

## 13. Persistencia

El environment se persiste en `webpay_returns.environment`; el fingerprint en
`webpay_returns.financial_fingerprint` y
`payment_reconciliations.financial_fingerprint`; la transaction reference se
persiste como transaction ID del `WC_Order`. Environment también integra la
preimagen. Ninguno se descubre mediante consultas abiertas.

## 14. Replay

La primera entrega usa la constante `integration`. El replay toma la evidencia
ya persistida y no sustituye el environment usando configuración mutable. Un
cambio de host, WP environment o variable no autoritativa no modifica la
evidencia inicial. Tras cerrar el fingerprint, replay deberá conservar hash y
transaction reference por igualdad estricta.

## 15. Matriz adversarial

| Escenario | Entrada/autoridad | Resultado | Persistencia/replay | Motivo |
|---|---|---|---|---|
| primera entrega | constante `integration` | acepta | persiste integration | contrato WR-06 |
| replay inmediato | evidencia inicial | acepta igual | no recalcula ambiente | idempotencia |
| cambio físico local | host/WP cambia | ignora | conserva integration | no autoritativo |
| cambio de env var | variable cambia | ignora | conserva integration | no autoritativo |
| intento production | `production` | rechaza fixture | sin mutación | selector cerrado |
| ausente | sin campo | rechaza | sin persistencia | obligatorio |
| null | null | rechaza | sin persistencia | no nullable |
| vacío | `""` | rechaza | sin persistencia | literal exacto |
| casing inválido | `INTEGRATION` | rechaza | sin persistencia | case-sensitive |
| fuera de catálogo | otro string | rechaza | sin persistencia | catálogo productivo |
| fingerprint alterado | hash distinto | rechaza | evidencia original | igualdad estricta |
| transaction reference alterada | prefijo/hash distinto | rechaza | pedido intacto | derivación exacta |

## 16. Estado de categorías

`case_id`, `harness`, `profile` y `variations` están cerradas. `payload` continúa
bloqueada. No se auditan categorías posteriores en esta corrección. No hay
categorías nuevas declaradas no aplicables.

## 17. Primer bloqueo restante

```text
case: A11-WR-06
category: payload
field: financial_fingerprint.merchant_identity_hash
reason: el componente exige un hash SHA-256 de identidad de comercio, pero A11
        no fija commerce code/merchant identity sintético ni algoritmo de fixture
required_authority: contrato normativo del comercio sintético WR-06 y su hash
```

Las pruebas usan valores derivados de configuraciones distintas; elegir uno,
usar el comercio local o derivarlo del ambiente físico violaría determinismo.

## 18. Veredictos

**CONTRATO FINANCIAL FINGERPRINT ENVIRONMENT WR-06 CERRADO**

**A11-WR-06 CONTINÚA BLOQUEADO POR PAYLOAD WEBPAY INDETERMINADO**

No se declaran cerrados el fingerprint, transaction reference, payload ni
categorías posteriores.

**A11-WR-06 CONTINÚA BLOQUEADO POR CATEGORÍAS DE FIXTURE INCOMPLETAS**

**A11 CONTINÚA BLOQUEADO POR MATRIZ DE FIXTURES INCOMPLETA**

## 19. Integridad del repositorio

Esta ejecución crea únicamente este documento. No modifica producto, pruebas,
coordinator, normas anteriores o `artifacts/`; no crea procesos, fixtures,
temporales o `.a11-runtime`; no realiza staging, commit ni push.
