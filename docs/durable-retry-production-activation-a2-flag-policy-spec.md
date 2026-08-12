# Especificación normativa A2: Deterministic Transfer Flag Policy

## 1. Veredicto

**IMPLEMENTABLE SIN DECISIONES PENDIENTES**

Esta especificación resuelve A2-AMB-01 a A2-AMB-08 de
`docs/durable-retry-production-activation-a2-readiness-audit.md`. A2 queda
limitado a una política pura, determinista e inmutable que decide si una
identidad funcional válida pertenece a la cohorte habilitada para intentar una
nueva transferencia inicial.

Esta especificación es la autoridad normativa exclusiva para implementar A2.
No autoriza integración productiva.

## 2. Objetivo y responsabilidad única

A2 debe implementar exactamente una política que, usando un único snapshot
inmutable de configuración, devuelve si una
`DurableRetryAuthorityIdentity` de `reconciliation` pertenece a la cohorte
habilitada para una futura transferencia `legacy → durable`.

La política:

- no determina autoridad actual;
- no confirma que legacy pueda actuar;
- no consulta marcadores;
- no transfiere filas;
- no crea generaciones;
- no ejecuta scheduling.

`true` significa exclusivamente: “esta identidad satisface la precondición de
cohorte para que un componente futuro intente transferirla”.

`false` significa exclusivamente: “esta identidad no satisface ahora esa
precondición”. No significa `legacy`, `durable`, error ni resultado terminal.

## 3. Alcance positivo

A2 debe crear:

1. contrato de política individual;
2. contrato puro de fuente de snapshots;
3. snapshot inmutable de configuración;
4. algoritmo de cohorting versionado;
5. implementación pura de la política;
6. excepción contractual específica;
7. tres harnesses A2;
8. actualización exacta de la allowlist histórica de dominio.

A2 debe certificar:

- default 0 %;
- porcentaje entero 0..100;
- snapshot leído una vez;
- sólo stage `reconciliation`;
- vectores SHA-256 normativos;
- determinismo, monotonía y portabilidad;
- configuración inválida fail-closed mediante excepción;
- pureza y API pública exacta.

## 4. Alcance negativo

A2 no debe implementar ni modificar:

- fuente productiva de configuración;
- WordPress Options, constantes globales, variables de entorno o `Config`;
- container, bootstrap, `Application` o wiring;
- A3: lectura de marcador y clasificación batch;
- transferencia real, repository, SQL, tablas, locks o transacciones;
- `WebpayReconciliationMaterializer`;
- scheduler legacy o durable;
- Action Scheduler, hooks, callbacks o cron;
- generation 1, `completion_id` o request de transferencia;
- resultados/catálogos A1;
- rollback, delete, overwrite, force-transfer o retorno a legacy;
- logging, métricas, telemetría, REST, WP-CLI, UI o JavaScript;
- schema, migraciones o documentación adicional.

La fuente concreta productiva, wiring y rollout efectivo quedan reservados a
microhitos posteriores. El diseño denomina A3 a “Lectura de marcador y
clasificación batch”
(`docs/durable-retry-production-activation-design.md:475-484`); A2 no asume
otro significado para A3.

## 5. Glosario

| Término | Definición normativa |
|---|---|
| Flag | Porcentaje configurado que limita nuevas transferencias; no es autoridad |
| Política | Servicio puro que combina identidad y snapshot para devolver bool |
| Cohorte | Conjunto de identidades cuyo bucket es menor al porcentaje |
| Bucket | Entero determinista entre 0 y 99 |
| Porcentaje | Entero canónico de 0 a 100, unidad: puntos porcentuales enteros |
| Snapshot | Objeto inmutable con stage, porcentaje y versión de algoritmo |
| Identidad | `DurableRetryAuthorityIdentity(stage, subject_id)` de A1 |
| Nueva transferencia | Intento futuro de crear por primera vez autoridad durable |
| Autoridad persistida | Hecho durable consultable; A2 no lo lee ni modifica |

## 6. Dependencias con A1

### Tipos reutilizados

- `VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryAuthorityIdentity`
- `VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryStage`

La política debe recibir el objeto A1. Queda reemplazada normativamente para A2
la firma preliminar con `string, int` del diseño
(`production-activation-design.md:351-354`).

### Tipos que A2 no debe modificar ni invocar

- `DurableRetryLegacyAuthorityResult`;
- `DurableRetryIndeterminateReason`;
- `DurableRetryLegacyExclusionInterface`;
- `DurableRetryInitialTransferRequest`;
- `DurableRetryInitialTransferResult`;
- `DurableRetryInitialTransferAuthorityInterface`;
- `DurableRetryGenerationIdentity`;
- `DurableRetryActivationContractException`.

A2 no amplía catálogos A1, no clasifica autoridad, no crea generaciones, no
valida `completion_id` y no ejecuta `transferReconciliation()`.

## 7. Modelo de configuración

### 7.1 Fuente abstracta

FQCN definitivo:

```text
VeciAhorra\Modules\Orders\Contracts\
DurableRetryActivationConfigurationSourceInterface
```

La fuente debe exponer sólo:

```php
public function snapshot(): DurableRetryActivationConfiguration;
```

No recibe parámetros. No devuelve arrays, null ni escalares. A2 sólo implementa
la interfaz; las pruebas usan doubles. La implementación productiva queda fuera.

### 7.2 Snapshot

FQCN:

```text
VeciAhorra\Modules\Orders\Domain\DurableRetry\
DurableRetryActivationConfiguration
```

Contiene exactamente:

- `stage`: siempre `DurableRetryStage::RECONCILIATION`;
- `percentage`: entero 0..100;
- `algorithmVersion`: exactamente
  `DurableRetryActivationCohort::ALGORITHM_VERSION`.

Factories:

```php
public static function disabled(): self;

public static function reconciliation(
    int $percentage,
    string $algorithmVersion
): self;
```

`disabled()` equivale exactamente a:

```php
self::reconciliation(
    0,
    DurableRetryActivationCohort::ALGORITHM_VERSION
);
```

Getters:

```php
public function stage(): string;
public function percentage(): int;
public function algorithmVersion(): string;
public function isDisabled(): bool;
public function isFullyEnabled(): bool;
```

No existen setters ni factories genéricas por stage.

### 7.3 Porcentaje

- tipo PHP: `int`;
- unidad: punto porcentual entero;
- mínimo inclusivo: `0`;
- máximo inclusivo: `100`;
- precisión: 1 %;
- decimales/floats: rechazados por `strict_types`;
- strings numéricos: rechazados;
- bool/null/array/objeto: rechazados;
- `0`: ninguna identidad habilitada;
- `100`: toda identidad válida habilitada;
- default: `0`.

No hay modo textual `off/on`. Off es 0; on total es 100. Un valor fuera de
rango lanza la excepción A2; nunca se clampa.

### 7.4 Stage

A2 admite exclusivamente `DurableRetryStage::RECONCILIATION`.

- El snapshot sólo puede construirse mediante `reconciliation()`.
- La política valida que identity y snapshot sean reconciliation.
- Un stage distinto o desconocido lanza `UNSUPPORTED_STAGE`.
- No se devuelve `false` para ocultar un stage inválido.
- Añadir otro stage requiere otra versión normativa y nuevas pruebas.

### 7.5 Versionado

Constante:

```php
public const ALGORITHM_VERSION = 'sha256-24bit-mod100-v1';
```

El token que participa en la entrada hash es exactamente `v1`. La constante
completa identifica hash, ancho y módulo; el token corto mantiene la entrada
canónica.

A2 soporta exactamente esta versión. Una versión distinta lanza
`UNSUPPORTED_ALGORITHM_VERSION`.

## 8. Algoritmo de cohorting

### 8.1 Definición byte por byte

FQCN:

```text
VeciAhorra\Modules\Orders\Domain\DurableRetry\
DurableRetryActivationCohort
```

API:

```php
public const ALGORITHM_VERSION = 'sha256-24bit-mod100-v1';
public const BUCKET_COUNT = 100;

public static function bucket(
    DurableRetryAuthorityIdentity $identity
): int;
```

Entrada exacta, sin salto final:

```text
veciahorra|durable-retry|initial-transfer|cohort|v1|stage=reconciliation|subject_id=<ID>
```

Reglas:

1. Los literales son bytes ASCII exactamente como aparecen.
2. El separador es `|` U+007C.
3. `=` es U+003D.
4. `<ID>` es el decimal canónico positivo de `subject_id`, sin signo, ceros
   iniciales, espacios ni locale.
5. La codificación completa es UTF-8; como todos los bytes son ASCII, coincide
   byte por byte en cualquier plataforma.
6. Se incluye stage y subject ID.
7. El namespace/salt estable es el prefijo completo hasta `stage=`.
8. Se calcula SHA-256 binario exacto, equivalente a
   `hash('sha256', $input, true)`.
9. Se usan sólo los bytes de índice 0, 1 y 2 del digest.
10. El entero big-endian de 24 bits es:

```text
value = byte0 * 65536 + byte1 * 256 + byte2
```

11. `bucket = value % 100`.
12. Bucket pertenece a 0..99.

El máximo intermedio es 16.777.215, seguro en PHP de 32 y 64 bits. No se usa
`crc32`, `hexdec`, floats, unpack con signedness incierto, locale, reloj,
aleatoriedad ni estado mutable.

### 8.2 Regla de decisión

```text
percentage == 0   → false
percentage == 100 → true
en otro caso      → bucket(identity) < percentage
```

La regla general `bucket < percentage` también produce correctamente los
extremos, pero la implementación debe realizar short-circuit para 0 y 100 antes
de calcular el bucket. Esto permite certificar que off/on total no dependen del
hash y evita trabajo innecesario.

### 8.3 Pseudocódigo normativo

```text
function allowsInitialTransfer(identity):
    snapshot = source.snapshot()             // exactamente una vez
    assert identity.stage == reconciliation
    assert snapshot.stage == reconciliation
    assert snapshot.algorithmVersion == sha256-24bit-mod100-v1
    assert 0 <= snapshot.percentage <= 100

    if snapshot.percentage == 0:
        return false

    if snapshot.percentage == 100:
        return true

    input = "veciahorra|durable-retry|initial-transfer|cohort|v1|" +
            "stage=reconciliation|subject_id=" +
            canonical_decimal(identity.subjectId)
    digest = SHA256_BINARY(input)
    value = ord(digest[0]) * 65536 +
            ord(digest[1]) * 256 +
            ord(digest[2])
    bucket = value mod 100
    return bucket < snapshot.percentage
```

### 8.4 Vectores normativos

Comando reproducible usado desde la raíz del repositorio:

```powershell
php -r "foreach ([1,2,17,31,100,999999] as `$id) { `$input='veciahorra|durable-retry|initial-transfer|cohort|v1|stage=reconciliation|subject_id='.`$id; `$hex=hash('sha256',`$input); `$raw=hex2bin(`$hex); `$value=(ord(`$raw[0])*65536)+(ord(`$raw[1])*256)+ord(`$raw[2]); echo `$id,'|',`$input,'|',`$hex,'|',`$value,'|',(`$value%100),PHP_EOL; }"
```

| Stage | ID | Digest SHA-256 esperado | 24-bit | Bucket |
|---|---:|---|---:|---:|
| reconciliation | 1 | `4828b6ff68e98ce830cd7a8c6bde59b6f1d84a714c9622cca589ae8306d1f77c` | 4729014 | 14 |
| reconciliation | 2 | `ae72ac374c43137f57c7c5785f913b22ab0de6a4ed04f0485c15c88fe0a69981` | 11432620 | 20 |
| reconciliation | 17 | `6df8a5df638e65213111ef61ad2ee4f3e1895ee935c0b6783ee4bf884ea2af0e` | 7207077 | 77 |
| reconciliation | 31 | `caed22379ae6cefcb03a00a0044b952b41b0365c4614a578caf4ddb77aaba27e` | 13298978 | 78 |
| reconciliation | 100 | `da75668e1f1e82fdbdfbf017be4283bbe00b6e5b307ca1fe2692490f94bffac3` | 14316902 | 2 |
| reconciliation | 999999 | `588b7ef378586e8785447e810134d300caf055bb92dc3f9120b61ae8c6f3a649` | 5802878 | 78 |

Cada harness debe comprobar también la entrada completa exacta. Para ID 1:

```text
veciahorra|durable-retry|initial-transfer|cohort|v1|stage=reconciliation|subject_id=1
```

Resultados de límite:

| ID | Bucket | 0 % | porcentaje = bucket | bucket + 1 | 100 % |
|---:|---:|---:|---:|---:|---:|
| 1 | 14 | false | false | true | true |
| 2 | 20 | false | false | true | true |
| 17 | 77 | false | false | true | true |
| 31 | 78 | false | false | true | true |
| 100 | 2 | false | false | true | true |
| 999999 | 78 | false | false | true | true |

## 9. API pública

### 9.1 Contrato de política

Ruta:

```text
app/Modules/Orders/Contracts/DurableRetryActivationPolicyInterface.php
```

API:

```php
namespace VeciAhorra\Modules\Orders\Contracts;

use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryAuthorityIdentity;

interface DurableRetryActivationPolicyInterface
{
    public function allowsInitialTransfer(
        DurableRetryAuthorityIdentity $identity
    ): bool;
}
```

### 9.2 Contrato de fuente

Ruta:

```text
app/Modules/Orders/Contracts/
DurableRetryActivationConfigurationSourceInterface.php
```

API:

```php
namespace VeciAhorra\Modules\Orders\Contracts;

use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryActivationConfiguration;

interface DurableRetryActivationConfigurationSourceInterface
{
    public function snapshot(): DurableRetryActivationConfiguration;
}
```

### 9.3 Snapshot

Ruta:

```text
app/Modules/Orders/Domain/DurableRetry/
DurableRetryActivationConfiguration.php
```

API:

```php
namespace VeciAhorra\Modules\Orders\Domain\DurableRetry;

final class DurableRetryActivationConfiguration
{
    public const MIN_PERCENTAGE = 0;
    public const MAX_PERCENTAGE = 100;
    public const DEFAULT_PERCENTAGE = 0;

    private function __construct(
        private readonly string $stage,
        private readonly int $percentage,
        private readonly string $algorithmVersion
    );

    public static function disabled(): self;

    public static function reconciliation(
        int $percentage,
        string $algorithmVersion
    ): self;

    public function stage(): string;
    public function percentage(): int;
    public function algorithmVersion(): string;
    public function isDisabled(): bool;
    public function isFullyEnabled(): bool;
}
```

### 9.4 Cohort

Ruta:

```text
app/Modules/Orders/Domain/DurableRetry/DurableRetryActivationCohort.php
```

`DurableRetryActivationCohort` es `final class`, tiene constructor privado y
expone exclusivamente las constantes y `bucket()` definidos en la sección 8.1.

### 9.5 Política concreta

Ruta:

```text
app/Modules/Orders/Domain/DurableRetry/
DurableRetryDeterministicActivationPolicy.php
```

API:

```php
namespace VeciAhorra\Modules\Orders\Domain\DurableRetry;

use VeciAhorra\Modules\Orders\Contracts\DurableRetryActivationConfigurationSourceInterface;
use VeciAhorra\Modules\Orders\Contracts\DurableRetryActivationPolicyInterface;

final class DurableRetryDeterministicActivationPolicy
    implements DurableRetryActivationPolicyInterface
{
    public function __construct(
        private readonly
        DurableRetryActivationConfigurationSourceInterface $source
    );

    public function allowsInitialTransfer(
        DurableRetryAuthorityIdentity $identity
    ): bool;
}
```

El orden de dependencias del constructor es exactamente uno: source.

### 9.6 Excepción A2

Ruta:

```text
app/Modules/Orders/Exceptions/DurableRetryActivationPolicyException.php
```

FQCN:

```text
VeciAhorra\Modules\Orders\Exceptions\
DurableRetryActivationPolicyException
```

Es `final`, extiende `InvalidArgumentException` y expone:

```php
public const INVALID_PERCENTAGE = 'invalid_percentage';
public const UNSUPPORTED_STAGE = 'unsupported_stage';
public const UNSUPPORTED_ALGORITHM_VERSION =
    'unsupported_algorithm_version';
public const INVALID_CONFIGURATION_SNAPSHOT =
    'invalid_configuration_snapshot';

public static function forCode(string $code): self;
public function reasonCode(): string;
```

Mensajes exactos:

| Código | Mensaje |
|---|---|
| invalid_percentage | `Invalid durable retry activation percentage.` |
| unsupported_stage | `Unsupported durable retry activation stage.` |
| unsupported_algorithm_version | `Unsupported durable retry activation algorithm version.` |
| invalid_configuration_snapshot | `Invalid durable retry activation configuration snapshot.` |

Código desconocido en `forCode()` lanza:

```php
InvalidArgumentException(
    'Invalid durable retry activation policy exception code.'
);
```

## 10. Validaciones y excepciones

| Entrada/estado | Resultado |
|---|---|
| Percentage int 0..100 | Válido |
| Percentage -1/101 | `INVALID_PERCENTAGE` al construir snapshot |
| Float/decimal | `TypeError` por strict types |
| String numérico/científico/con espacios | `TypeError` |
| Bool/null/array/objeto | `TypeError` |
| Version exacta | Válida |
| Version vacía/desconocida/mayúscula/espacios | `UNSUPPORTED_ALGORITHM_VERSION` |
| Identity reconciliation válida | Evaluación |
| Identity con stage distinto/desconocido | `UNSUPPORTED_STAGE` antes del hash |
| Snapshot con stage distinto a identity | `INVALID_CONFIGURATION_SNAPSHOT` |
| Source devuelve tipo incorrecto | `TypeError`; violación del implementador |
| Source lanza error interno | Se propaga; no se convierte en false |

La excepción se lanza:

- al construir snapshot: porcentaje o versión inválidos;
- al evaluar: stage no soportado o snapshot incoherente.

Nunca se degrada configuración inválida a 0 o 100. Una excepción impide que el
caller interprete la política como habilitada.

## 11. Invariantes

1. A2 recibe una identidad A1 tipada.
2. A2 devuelve exclusivamente bool.
3. True sólo habilita el intento futuro de transferencia.
4. False no confirma autoridad legacy.
5. La fuente se lee exactamente una vez por decisión.
6. El snapshot es inmutable.
7. El default normativo es 0 %.
8. El porcentaje es int 0..100.
9. Sólo reconciliation está soportado.
10. La versión es exactamente `sha256-24bit-mod100-v1`.
11. Misma identidad y snapshot producen la misma decisión.
12. El bucket es estable entre plataformas de 32/64 bits.
13. 0 % habilita ninguna identidad.
14. 100 % habilita toda identidad válida.
15. Aumentar porcentaje sólo agrega identidades.
16. Disminuir porcentaje no altera autoridad persistida.
17. El orden de evaluación no altera resultados.
18. Una configuración inválida lanza; nunca habilita silenciosamente.
19. A2 no consulta ni escribe persistencia.
20. A2 no programa ni transfiere.
21. A2 no modifica A1.
22. Cambiar algoritmo exige nueva versión normativa.

## 12. Matriz de decisiones

| Identidad | Config | Bucket | Resultado/excepción |
|---|---:|---:|---|
| reconciliation:1 | 0 | no calculado | false |
| reconciliation:1 | 14 | 14 | false |
| reconciliation:1 | 15 | 14 | true |
| reconciliation:1 | 100 | no calculado | true |
| reconciliation:100 | 2 | 2 | false |
| reconciliation:100 | 3 | 2 | true |
| reconciliation:17 | 77 | 77 | false |
| reconciliation:17 | 78 | 77 | true |
| reconciliation válida | -1 | n/a | INVALID_PERCENTAGE |
| reconciliation válida | 101 | n/a | INVALID_PERCENTAGE |
| reconciliation válida | version desconocida | n/a | UNSUPPORTED_ALGORITHM_VERSION |
| stage distinto | válida | n/a | UNSUPPORTED_STAGE |
| snapshot/identity stage mismatch | válida | n/a | INVALID_CONFIGURATION_SNAPSHOT |

Una futura transferencia ya persistida queda fuera de esta matriz. Si el
porcentaje baja, la política puede devolver false, pero la autoridad durable
permanece.

## 13. Catálogo exacto de archivos

### PHP nuevos: 6

| # | Ruta | Namespace / símbolo | Responsabilidad | Estado |
|---:|---|---|---|---|
| 1 | `app/Modules/Orders/Contracts/DurableRetryActivationPolicyInterface.php` | `VeciAhorra\Modules\Orders\Contracts\DurableRetryActivationPolicyInterface` | Contrato bool individual | Nuevo |
| 2 | `app/Modules/Orders/Contracts/DurableRetryActivationConfigurationSourceInterface.php` | `...\Contracts\DurableRetryActivationConfigurationSourceInterface` | Fuente abstracta de snapshot | Nuevo |
| 3 | `app/Modules/Orders/Domain/DurableRetry/DurableRetryActivationConfiguration.php` | `...\Domain\DurableRetry\DurableRetryActivationConfiguration` | Snapshot inmutable | Nuevo |
| 4 | `app/Modules/Orders/Domain/DurableRetry/DurableRetryActivationCohort.php` | `...\Domain\DurableRetry\DurableRetryActivationCohort` | Algoritmo versionado | Nuevo |
| 5 | `app/Modules/Orders/Domain/DurableRetry/DurableRetryDeterministicActivationPolicy.php` | `...\Domain\DurableRetry\DurableRetryDeterministicActivationPolicy` | Política concreta | Nuevo |
| 6 | `app/Modules/Orders/Exceptions/DurableRetryActivationPolicyException.php` | `...\Exceptions\DurableRetryActivationPolicyException` | Errores contractuales A2 | Nuevo |

Los prefijos abreviados de la tabla equivalen exactamente a
`VeciAhorra\Modules\Orders`.

### PHP modificados: 0

No se modifica código A1 ni productivo existente.

### Harnesses nuevos: 3

1. `tests/manual/durable-retry-activation-flag-policy-test.php`
2. `tests/manual/durable-retry-activation-flag-policy-vectors-test.php`
3. `tests/manual/durable-retry-activation-flag-policy-infrastructure-test.php`

### Harnesses modificados: 1

4. `tests/manual/durable-retry-schedule-infrastructure-test.php`

La modificación añade exactamente los tres nuevos archivos de
`Domain/DurableRetry` a su allowlist ordenada, que pasa de 25 a 28, y cambia
únicamente el mensaje a:

```text
twenty-eight focused pure domain contracts
```

### Total implementable

- 6 PHP nuevos;
- 0 PHP existentes modificados;
- 3 harnesses nuevos;
- 1 harness existente modificado;
- 10 archivos totales.

Cualquier archivo no enumerado queda fuera de A2.

## 14. Harnesses exactos

### 14.1 Policy

Ruta:

```text
tests/manual/durable-retry-activation-flag-policy-test.php
```

Matrices:

- snapshot disabled/default;
- porcentajes 0..100;
- tipos y límites inválidos;
- source leída exactamente una vez por llamada;
- 0/100 sin cálculo de bucket observable mediante resultados;
- decisiones límite bucket/bucket+1;
- monotonía sobre las 101 tasas;
- cambio de source entre llamadas, nunca dentro de una;
- stage/version inválidos;
- mensajes y códigos de excepción;
- doubles de source sin infraestructura.

### 14.2 Vectors

Ruta:

```text
tests/manual/durable-retry-activation-flag-policy-vectors-test.php
```

Matrices:

- seis entradas exactas de sección 8.4;
- seis digests;
- seis enteros de 24 bits;
- seis buckets;
- límites por vector;
- repetición y orden invertido;
- IDs 1 y `PHP_INT_MAX` para decimal canónico;
- ausencia de floats/signedness.

El harness puede recomputar el digest con `hash()` sólo para verificar el vector;
debe invocar también `DurableRetryActivationCohort::bucket()`.

### 14.3 Infrastructure

Ruta:

```text
tests/manual/durable-retry-activation-flag-policy-infrastructure-test.php
```

Certifica:

- seis archivos PHP y tres harnesses;
- FQCN, final/interface, firmas y tipos exactos;
- APIs públicas cerradas;
- properties readonly;
- source double compatible con autoload;
- catálogo exacto de excepción;
- ausencia de WordPress, SQL, filesystem, red, reloj, aleatoriedad, globals,
  logging, hooks, scheduling, repositories, transfer authority y wiring;
- allowlist exacta de diez archivos A2;
- no cambios a A1.

### 14.4 Harness histórico modificado

Debe conservar:

- igualdad exacta filesystem/allowlist;
- detección de faltantes e inesperados;
- los 25 archivos previos;
- todas las comprobaciones de pureza existentes.

### 14.5 Regresiones

Ejecutar:

- tres harnesses A1: 533 aserciones;
- cuatro harnesses A2/histórico;
- suite Durable Retry aislada completa;
- lint individual de los diez archivos A2 involucrados;
- `git diff --check`;
- `git diff --no-index --check` para nueve archivos nuevos.

No se fija un número artificial de aserciones A2; se exige cobertura de todas
las matrices.

## 15. Compatibilidad

- PHP mínimo: 8.2 (`app/Core/Config.php:47`).
- Cada PHP usa `declare(strict_types=1)`.
- PSR-4: `VeciAhorra\` → `app/`.
- Se usan `final class`, constantes cerradas, factories y readonly conforme al
  dominio Durable Retry.
- No se añaden dependencias Composer.
- `hash('sha256', ..., true)` y `ord()` son parte del runtime soportado.
- El cálculo evita enteros mayores a 24 bits y es portable.

## 16. Seguridad y fallo cerrado

- Config inválida lanza antes de devolver bool.
- Stage inválido lanza antes del hash.
- Source que viola su return type produce `TypeError`.
- Error interno de source se propaga.
- No existe fallback silencioso a 0 % o 100 %.
- Un caller no debe capturar la excepción para asumir `true`.
- La política no maneja secretos ni PII; sólo stage e ID interno.
- El namespace/salt es público y estable: cohorting no es una función de
  seguridad ni autorización.
- False nunca se usa como prueba de autoridad legacy.

## 17. Evolución del algoritmo

No se debe modificar entrada, hash, bytes, conversión, módulo o comparación bajo
`sha256-24bit-mod100-v1`.

Una evolución requiere:

1. nueva constante de versión;
2. nueva especificación normativa;
3. nuevos vectores;
4. decisión explícita de migración de cohortes;
5. compatibilidad productiva tratada en otro microhito.

A2 no implementa selección multiversión. Sólo acepta v1; cualquier otro valor
lanza.

## 18. Condiciones de implementación

- [ ] Esta especificación está versionada.
- [ ] Auditoría A2 está versionada o incluida en el mismo commit documental
      selectivo previo.
- [ ] Base Git exacta declarada por la solicitud de implementación.
- [ ] Staging vacío y tracked limpio.
- [ ] A1 versionado y 533 aserciones verdes.
- [ ] Allowlist exacta de diez archivos autorizada.
- [ ] Vectores copiados byte por byte.
- [ ] No se elige fuente productiva.
- [ ] No se modifica A1.
- [ ] No se implementa A3 ni wiring.
- [ ] No staging/commit/push durante implementación salvo instrucción posterior.

## 19. Condiciones de aceptación

- lint 10/10 archivos involucrados;
- tres harnesses A2 verdes;
- harness histórico verde con 28 archivos;
- tres harnesses A1 mantienen 533 aserciones;
- suite Durable Retry aislada completa verde;
- 0 referencias a WordPress, SQL, filesystem, red, reloj, aleatoriedad, hooks,
  scheduling, transferencia o wiring en seis PHP A2;
- vectores y buckets exactos;
- source leída una vez por decisión;
- 0 %/100 % y monotonía certificados;
- `git diff --check` limpio;
- exactamente nueve archivos nuevos y uno modificado;
- protected docs y artifacts intactos.

## 20. Alcance futuro

Quedan reservados:

- fuente productiva de configuración;
- integración con `Config`, options u otra fuente que una norma futura elija;
- registro en container/bootstrap;
- composición con producer;
- A3: lectura de marcador y clasificación batch;
- transferencia transaccional A4;
- producer A5;
- guardias legacy A6-A8;
- recovery durable A9;
- wiring A10;
- certificación/canario A11-A12;
- rollout operativo y observabilidad;
- soporte de otros stages;
- versión de algoritmo posterior.

La política A2 debe permanecer desconectada de producción al finalizar su
implementación.
