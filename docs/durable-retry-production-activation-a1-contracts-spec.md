# Especificación normativa A1: contratos de autoridad Durable Retry

## 1. Carácter normativo

Este documento es la autoridad normativa exclusiva para implementar el
Microhito A1 de activación productiva de Durable Retry. Cierra nombres, FQCN,
estados, razones, firmas, invariantes, archivos y pruebas. A1 no debe tomar
decisiones adicionales ni reinterpretar
`docs/durable-retry-production-activation-design.md`.

Esta especificación no implementa PHP y no habilita tráfico. Su base auditada es
`main` en `71acff2addc840c38aaebf750ed1c8fced667f69`, con schema `0.24.0`
(`app/Core/Config.php:22`).

Las decisiones previas que permanecen vigentes son:

- `reconciliation` es la primera etapa
  (`docs/durable-retry-production-activation-design.md:32-59`);
- el productor futuro será
  `WebpayReconciliationMaterializer::materialize()`, no A1
  (`docs/durable-retry-production-activation-design.md:105-124`);
- la generación durable 1 es el marcador permanente de transferencia
  (`docs/durable-retry-production-activation-design.md:63-100`);
- legacy falla cerrado ante incertidumbre;
- A1 sólo crea vocabulario puro: sin SQL, WordPress, Action Scheduler,
  persistencia, producer, flags, wiring ni cambios legacy
  (`docs/durable-retry-production-activation-design.md:453-462`).

## 2. Convenciones técnicas vinculantes

La auditoría del módulo establece estas convenciones:

- Namespace raíz: `VeciAhorra\Modules\Orders`.
- Interfaces: `VeciAhorra\Modules\Orders\Contracts`.
- Value objects, catálogos y resultados Durable Retry:
  `VeciAhorra\Modules\Orders\Domain\DurableRetry`.
- Excepciones del módulo:
  `VeciAhorra\Modules\Orders\Exceptions`.
- Se usarán `final class` y constantes públicas cerradas, no enums PHP. Así
  operan `DurableRetryStage`, `DurableRetryStatus`,
  `DurableRetryPersistenceResult` y los resultados certificados
  (`app/Modules/Orders/Domain/DurableRetry/DurableRetryStage.php:9-31`,
  `app/Modules/Orders/Domain/DurableRetry/DurableRetryPersistenceResult.php:9-52`).
- Todos los archivos tendrán `declare(strict_types=1)`.
- Estado interno privado y `readonly`; no setters, herencia ni propiedades
  públicas.
- Las factories protegen matrices de estado. Los constructores serán privados
  cuando aceptar valores crudos permita combinaciones imposibles.
- Las APIs reciben objetos tipados. Ningún resultado público será un array
  asociativo libre.
- Los mensajes de excepción y diagnóstico son literales estables, sin SQL,
  nombres de tablas, rutas, stack traces, tokens ni payloads.

No existe hoy un value object que represente exactamente
`(stage, subject_id, generation)`. `DurableRetryScheduleSnapshot` contiene el
estado completo de una fila y se construye desde un array de 19 campos
(`app/Modules/Orders/Domain/DurableRetry/DurableRetryScheduleSnapshot.php:13-53`);
no se reutilizará como identidad. A1 creará las dos identidades definidas aquí.

## 3. Tres conceptos separados

### 3.1 Identidad funcional de autoridad

FQCN normativo:

```text
VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryAuthorityIdentity
```

Semántica:

```text
(stage, subject_id)
```

Para A1:

- `stage` siempre es `DurableRetryStage::RECONCILIATION`;
- `subject_id` es el ID canónico positivo de la reconciliación;
- no contiene `completion_id`, `generation`, `attempt_number`,
  `scheduled_for`, action ID, public ID ni token;
- su igualdad compara exactamente stage y subject ID;
- una transferencia confirmada afecta esta identidad completa y es permanente.

La única factory pública es `reconciliation(int $subjectId)`. No existe
`fromStage()` genérica en A1: impedir otros stages por construcción evita que el
alcance documental se convierta accidentalmente en activación multi-stage.

### 3.2 Identidad persistente de generación

FQCN normativo:

```text
VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryGenerationIdentity
```

Semántica:

```text
(stage, subject_id, generation)
```

- referencia una `DurableRetryAuthorityIdentity`;
- `generation` es un entero positivo canónico;
- `initial()` fija generación `1`;
- `next()` no forma parte de A1;
- `fromAuthority()` admite una generación positiva para representar lecturas
  de generaciones existentes, no para crearlas;
- cambiar generation no cambia autoridad funcional;
- ninguna generación terminal o sucesora devuelve autoridad a legacy.

El índice persistente existente coincide con esta identidad
(`app/Database/Schemas/DurableRetryScheduleSchema.php:39-43`). La consulta
actual usa los tres campos primitivos
(`app/Modules/Orders/Repositories/DurableRetryScheduleRepository.php:162-176`),
pero A1 no modifica ese repository.

### 3.3 Datos de creación inicial y `completion_id`

`completion_id` no participa en:

- identidad funcional;
- identidad de generación;
- deduplicación `(stage, subject_id, generation)`;
- igualdad de ninguna de las dos identidades.

Sí es metadata obligatoria de
`DurableRetryInitialTransferRequest`. Para reconciliation debe ser un entero
positivo y exactamente igual a `subject_id`. El snapshot actual exige esa
igualdad
(`app/Modules/Orders/Domain/DurableRetry/DurableRetryScheduleSnapshot.php:134-138`)
y el repository trata
`completion_id` como write-once, no como identidad
(`app/Modules/Orders/Repositories/DurableRetryScheduleRepository.php:51-66`).

Si ya existe la autoridad funcional transferida:

- mismo `completion_id` y mismos datos autoritativos: idempotencia
  `ALREADY_TRANSFERRED`;
- `completion_id` diferente: `DURABLE_INCONSISTENCY` con razón
  `EXISTING_TRANSFER_INCOMPATIBLE`;
- nunca se crea una segunda transferencia.

## 4. Catálogo de tipos normativos

| FQCN | Tipo | Responsabilidad | Dependencias permitidas | Prohibidas | Consumidores futuros | Implementación |
|---|---|---|---|---|---|---|
| `VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryAuthorityIdentity` | final value object | `(reconciliation, subject_id)` | `DurableRetryStage`, excepción A1 | Infraestructura | consultas, transfer authority, guardias | A1 |
| `VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryGenerationIdentity` | final value object | `(authority, generation)` | Authority identity, excepción A1 | Repository concreto | transfer result/recovery futuro | A1 |
| `VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryAuthorityIdentityCollection` | final collection VO | Entrada batch única y ordenada | Authority identity, excepción A1 | Arrays asociativos públicos | contrato batch | A1 |
| `VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryIndeterminateReason` | final catálogo | Razones fail-closed | excepción A1 | Strings libres | authority result | A1 |
| `VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryLegacyAuthorityResult` | final result | Estado individual | reason catalog, excepción A1 | Boolean como respuesta única | scheduler/workers/recovery | A1 |
| `VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryLegacyAuthorityEntry` | final value object | Par identidad/resultado | identity y result | Claves string libres | batch result | A1 |
| `VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryLegacyAuthorityBatchResult` | final result | Cobertura exacta de una colección | collection y entries | Completar faltantes como legacy | recovery legacy | A1 |
| `VeciAhorra\Modules\Orders\Contracts\DurableRetryLegacyExclusionInterface` | interface | Consulta individual y batch | tipos anteriores | SQL, AS, flags | scheduler/workers/recovery | A1 |
| `VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialTransferRequest` | final value object | Solicitud inicial válida | identities, DateTimeImmutable, reason | Generación arbitraria | transfer authority futuro | A1 |
| `VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialTransferReason` | final catálogo | Razones de transferencia | excepción A1 | Strings libres | transfer result | A1 |
| `VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialTransferResult` | final result | Outcome de transferencia válida | generation identity, reason, excepción | Invalid input como estado | productor futuro | A1 |
| `VeciAhorra\Modules\Orders\Contracts\DurableRetryInitialTransferAuthorityInterface` | interface | Solicitar legacy→durable | request y result | arrays, rollback, force | productor futuro | A1 |
| `VeciAhorra\Modules\Orders\Exceptions\DurableRetryActivationContractException` | final exception | Argumento/contrato A1 inválido | `InvalidArgumentException` | Datos sensibles | todos los VOs A1 | A1 |

## 5. Excepción normativa

FQCN:

```text
VeciAhorra\Modules\Orders\Exceptions\DurableRetryActivationContractException
```

Será `final` y extenderá `InvalidArgumentException`. Tendrá estos códigos y
mensajes públicos exactos:

| Constante | Valor | Mensaje exacto |
|---|---|---|
| `INVALID_AUTHORITY_IDENTITY` | `invalid_authority_identity` | `Invalid durable retry authority identity.` |
| `INVALID_GENERATION_IDENTITY` | `invalid_generation_identity` | `Invalid durable retry generation identity.` |
| `INVALID_IDENTITY_COLLECTION` | `invalid_identity_collection` | `Invalid durable retry authority identity collection.` |
| `INVALID_AUTHORITY_RESULT` | `invalid_authority_result` | `Invalid durable retry legacy authority result.` |
| `INVALID_AUTHORITY_BATCH` | `invalid_authority_batch` | `Invalid durable retry legacy authority batch result.` |
| `INVALID_INITIAL_TRANSFER_REQUEST` | `invalid_initial_transfer_request` | `Invalid durable retry initial transfer request.` |
| `INVALID_INITIAL_TRANSFER_RESULT` | `invalid_initial_transfer_result` | `Invalid durable retry initial transfer result.` |
| `CONTRACT_VIOLATION` | `contract_violation` | `Durable retry activation contract violation.` |

API exacta:

```php
namespace VeciAhorra\Modules\Orders\Exceptions;

use InvalidArgumentException;

final class DurableRetryActivationContractException
    extends InvalidArgumentException
{
    public const INVALID_AUTHORITY_IDENTITY = 'invalid_authority_identity';
    public const INVALID_GENERATION_IDENTITY = 'invalid_generation_identity';
    public const INVALID_IDENTITY_COLLECTION = 'invalid_identity_collection';
    public const INVALID_AUTHORITY_RESULT = 'invalid_authority_result';
    public const INVALID_AUTHORITY_BATCH = 'invalid_authority_batch';
    public const INVALID_INITIAL_TRANSFER_REQUEST = 'invalid_initial_transfer_request';
    public const INVALID_INITIAL_TRANSFER_RESULT = 'invalid_initial_transfer_result';
    public const CONTRACT_VIOLATION = 'contract_violation';

    public static function forCode(string $code): self;
    public function reasonCode(): string;
}
```

`forCode()` sólo acepta las ocho constantes; un código desconocido lanza
`InvalidArgumentException('Invalid durable retry activation exception code.')`.
No se admite mensaje proporcionado por el caller.

Fronteras:

- valores estructuralmente inválidos lanzan esta excepción antes de delegar;
- violaciones al construir resultados/batches también la lanzan;
- fallo recuperable de consulta se representa como `indeterminate`;
- fallo persistente conocido se representa en transfer result;
- incompatibilidad persistente se representa como `DURABLE_INCONSISTENCY`;
- un adapter futuro transforma excepciones de infraestructura esperables en
  resultados cerrados; errores de programación no se silencian.

## 6. API de identidades

### 6.1 `DurableRetryAuthorityIdentity`

```php
namespace VeciAhorra\Modules\Orders\Domain\DurableRetry;

final class DurableRetryAuthorityIdentity
{
    private function __construct(
        private readonly string $stage,
        private readonly int $subjectId
    );

    public static function reconciliation(int $subjectId): self;
    public function stage(): string;
    public function subjectId(): int;
    public function equals(self $other): bool;
    public function diagnosticKey(): string;
}
```

Invariantes:

- stage exacto `reconciliation`;
- `subjectId >= 1`;
- no coerción;
- `diagnosticKey()` devuelve exactamente
  `reconciliation:<subjectId-en-decimal>`;
- representación sin PII, tokens ni IDs externos;
- dos instancias equivalen sólo si ambos campos coinciden.

El type hint `int` más `strict_types=1` rechaza strings numéricos, decimales,
arrays, objetos, null y boolean en llamadas PHP estrictas. La factory valida
cero y negativos.

### 6.2 `DurableRetryGenerationIdentity`

```php
namespace VeciAhorra\Modules\Orders\Domain\DurableRetry;

final class DurableRetryGenerationIdentity
{
    private function __construct(
        private readonly DurableRetryAuthorityIdentity $authority,
        private readonly int $generation
    );

    public static function initial(
        DurableRetryAuthorityIdentity $authority
    ): self;

    public static function fromAuthority(
        DurableRetryAuthorityIdentity $authority,
        int $generation
    ): self;

    public function authority(): DurableRetryAuthorityIdentity;
    public function stage(): string;
    public function subjectId(): int;
    public function generation(): int;
    public function isInitial(): bool;
    public function equals(self $other): bool;
    public function diagnosticKey(): string;
}
```

`initial()` fija `1`; `fromAuthority()` rechaza `< 1`.
`diagnosticKey()` devuelve
`<authority diagnostic key>:generation:<generation>`.

### 6.3 Colección de entrada batch

```php
namespace VeciAhorra\Modules\Orders\Domain\DurableRetry;

use Countable;
use IteratorAggregate;
use Traversable;

final class DurableRetryAuthorityIdentityCollection
    implements Countable, IteratorAggregate
{
    public const MAX_IDENTITIES = 500;

    private function __construct(private readonly array $identities);

    public static function fromIdentities(
        DurableRetryAuthorityIdentity ...$identities
    ): self;

    public function count(): int;
    public function isEmpty(): bool;
    public function contains(DurableRetryAuthorityIdentity $identity): bool;
    public function getIterator(): Traversable;
}
```

Reglas:

- acepta entre 0 y 500 identidades;
- conserva el orden de entrada;
- duplicado por `equals()` es inválido, no se deduplica;
- la colección vacía es válida y produce batch vacío;
- no expone el array interno ni permite mutación.

## 7. Resultado individual de autoridad

### 7.1 Estados

`DurableRetryLegacyAuthorityResult` tiene exactamente:

| Constante | Valor | Legacy autorizado | Bloquea legacy |
|---|---|---:|---:|
| `LEGACY` | `legacy` | Sí | No |
| `DURABLE` | `durable` | No | Sí |
| `INDETERMINATE` | `indeterminate` | No | Sí |

No existe cuarto estado. Sólo `isLegacyAuthorized()` puede habilitar scheduling
o ejecución legacy.

### 7.2 Razones cerradas

`DurableRetryIndeterminateReason` es un catálogo `final class`:

| Constante | Valor | Mensaje seguro |
|---|---|---|
| `QUERY_FAILED` | `query_failed` | `Durable retry authority query failed.` |
| `INCOMPATIBLE_DURABLE_STATE` | `incompatible_durable_state` | `Durable retry authority state is incompatible.` |
| `PERSISTED_DUPLICATE` | `persisted_duplicate` | `Duplicate durable retry authority evidence detected.` |
| `CORRUPT_IDENTITY` | `corrupt_identity` | `Durable retry authority identity is corrupt.` |
| `INCOMPLETE_RESULT` | `incomplete_result` | `Durable retry authority evidence is incomplete.` |
| `UNRESOLVED_RACE` | `unresolved_race` | `Durable retry authority race is unresolved.` |
| `CONSISTENCY_ERROR` | `consistency_error` | `Durable retry authority consistency check failed.` |

API:

```php
namespace VeciAhorra\Modules\Orders\Domain\DurableRetry;

final class DurableRetryIndeterminateReason
{
    public const QUERY_FAILED = 'query_failed';
    public const INCOMPATIBLE_DURABLE_STATE = 'incompatible_durable_state';
    public const PERSISTED_DUPLICATE = 'persisted_duplicate';
    public const CORRUPT_IDENTITY = 'corrupt_identity';
    public const INCOMPLETE_RESULT = 'incomplete_result';
    public const UNRESOLVED_RACE = 'unresolved_race';
    public const CONSISTENCY_ERROR = 'consistency_error';

    public static function all(): array;
    public static function assert(string $reason): void;
    public static function message(string $reason): string;

    private function __construct();
}
```

`all()` conserva el orden de la tabla. `assert()` rechaza valores desconocidos
con `DurableRetryActivationContractException::INVALID_AUTHORITY_RESULT`.

### 7.3 API del resultado

```php
namespace VeciAhorra\Modules\Orders\Domain\DurableRetry;

final class DurableRetryLegacyAuthorityResult
{
    public const LEGACY = 'legacy';
    public const DURABLE = 'durable';
    public const INDETERMINATE = 'indeterminate';

    private function __construct(
        private readonly string $state,
        private readonly ?string $reason
    );

    public static function legacy(): self;
    public static function durable(): self;
    public static function indeterminate(string $reason): self;

    public function state(): string;
    public function reason(): ?string;
    public function diagnosticMessage(): string;
    public function isLegacyAuthorized(): bool;
    public function isDurable(): bool;
    public function isIndeterminate(): bool;
    public function blocksLegacy(): bool;
}
```

Matrices:

- `legacy`: reason `null`, mensaje `Legacy scheduling authority confirmed.`,
  sólo aquí `isLegacyAuthorized() === true`;
- `durable`: reason `null`, mensaje
  `Durable retry scheduling authority confirmed.`;
- `indeterminate`: reason obligatoria del catálogo y mensaje del catálogo;
- `blocksLegacy()` equivale a `!isLegacyAuthorized()`;
- factories sin parámetros impiden razones en estados determinados;
- constructor privado impide estados desconocidos.

## 8. Resultado batch

### 8.1 Entrada tipada

El contrato batch recibe `DurableRetryAuthorityIdentityCollection`, nunca un
array. La clave semántica de una entrada es la identidad tipada; la clave
interna de validación puede ser `diagnosticKey()`, pero no forma parte de la API
de consulta.

### 8.2 Entry

```php
namespace VeciAhorra\Modules\Orders\Domain\DurableRetry;

final class DurableRetryLegacyAuthorityEntry
{
    public function __construct(
        private readonly DurableRetryAuthorityIdentity $identity,
        private readonly DurableRetryLegacyAuthorityResult $result
    );

    public function identity(): DurableRetryAuthorityIdentity;
    public function result(): DurableRetryLegacyAuthorityResult;
}
```

### 8.3 Batch result

```php
namespace VeciAhorra\Modules\Orders\Domain\DurableRetry;

use Countable;
use IteratorAggregate;
use Traversable;

final class DurableRetryLegacyAuthorityBatchResult
    implements Countable, IteratorAggregate
{
    private function __construct(
        private readonly DurableRetryAuthorityIdentityCollection $requested,
        private readonly array $entries
    );

    public static function fromEntries(
        DurableRetryAuthorityIdentityCollection $requested,
        DurableRetryLegacyAuthorityEntry ...$entries
    ): self;

    public static function indeterminateAll(
        DurableRetryAuthorityIdentityCollection $requested,
        string $reason
    ): self;

    public function requested(): DurableRetryAuthorityIdentityCollection;
    public function forIdentity(
        DurableRetryAuthorityIdentity $identity
    ): DurableRetryLegacyAuthorityResult;
    public function count(): int;
    public function isEmpty(): bool;
    public function getIterator(): Traversable;
}
```

Validación de `fromEntries()`:

1. Cada identidad solicitada aparece exactamente una vez.
2. No se acepta identidad no solicitada.
3. No se acepta duplicado.
4. Falta de entrada no se completa como legacy: la factory crea para esa
   identidad `indeterminate(INCOMPLETE_RESULT)`.
5. El orden final siempre es el orden de `requested`, no el de los entries.
6. Resultado adicional o duplicado lanza
   `INVALID_AUTHORITY_BATCH`; no se puede decidir a qué identidad atribuir la
   evidencia.
7. Batch vacío más cero entries produce batch vacío.
8. `forIdentity()` con identidad no solicitada lanza
   `INVALID_AUTHORITY_BATCH`.
9. `indeterminateAll()` valida la razón y crea exactamente una entrada por
   identidad.
10. Ningún método expone un array asociativo público.

Una falla parcial produce el resultado individual obtenido para identidades
resueltas e `INDETERMINATE` para cada faltante. Una falla total usa
`indeterminateAll(..., QUERY_FAILED)`. Ambas son fail-closed.

## 9. Contrato de consulta

Se conserva el nombre ya introducido por el diseño.

```php
namespace VeciAhorra\Modules\Orders\Contracts;

use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryAuthorityIdentity;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryAuthorityIdentityCollection;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryLegacyAuthorityBatchResult;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryLegacyAuthorityResult;

interface DurableRetryLegacyExclusionInterface
{
    public function classify(
        DurableRetryAuthorityIdentity $identity
    ): DurableRetryLegacyAuthorityResult;

    public function classifyBatch(
        DurableRetryAuthorityIdentityCollection $identities
    ): DurableRetryLegacyAuthorityBatchResult;
}
```

Semántica:

- no retorna boolean;
- lista vacía retorna batch vacío sin infraestructura;
- máximo 500 identidades por invocación;
- el implementador futuro debe conservar orden y cobertura;
- error recuperable individual: `indeterminate(QUERY_FAILED)`;
- error total: `indeterminateAll(QUERY_FAILED)`;
- no declara excepciones de infraestructura recuperables;
- puede propagar `DurableRetryActivationContractException` por violación del
  caller y errores no recuperables de programación;
- no consulta Action Scheduler ni feature flags;
- una feature flag nunca determina autoridad existente.

## 10. Solicitud de transferencia inicial

FQCN:

```text
VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialTransferRequest
```

API exacta:

```php
namespace VeciAhorra\Modules\Orders\Domain\DurableRetry;

use DateTimeImmutable;

final class DurableRetryInitialTransferRequest
{
    public const INITIAL_GENERATION = 1;
    public const INITIAL_ATTEMPT = 0;
    public const INITIAL_REASON = DurableRetryReason::RETRYABLE_FAILURE;

    private function __construct(
        private readonly DurableRetryAuthorityIdentity $authority,
        private readonly int $completionId,
        private readonly DateTimeImmutable $scheduledForUtc
    );

    public static function reconciliation(
        DurableRetryAuthorityIdentity $authority,
        int $completionId,
        DateTimeImmutable $scheduledForUtc
    ): self;

    public function authority(): DurableRetryAuthorityIdentity;
    public function generationIdentity(): DurableRetryGenerationIdentity;
    public function completionId(): int;
    public function generation(): int;
    public function attemptNumber(): int;
    public function scheduledForUtc(): DateTimeImmutable;
    public function scheduledForDatabase(): string;
    public function reasonCode(): string;
    public function equals(self $other): bool;
    public function diagnosticKey(): string;
}
```

Invariantes:

- authority debe ser reconciliation;
- `completionId >= 1` y debe ser idéntico a `authority->subjectId()`;
- generation no es input: siempre `1`;
- attempt no es input: siempre `0`;
- reason no es input: siempre `DurableRetryReason::RETRYABLE_FAILURE`, la única
  causa admitida para `dispatching`
  (`app/Modules/Orders/Domain/DurableRetry/DurableRetryReason.php:11-27`);
- `scheduledForUtc` debe tener offset `+00:00` y cero microsegundos;
- no se normaliza silenciosamente otra zona ni precisión;
- formato DB exacto `Y-m-d H:i:s`;
- se guarda una copia inmutable del instante;
- igualdad compara authority, completion ID e instante exacto;
- diagnostic key concatena generation identity y scheduled time en formato DB;
- no incluye public ID, dispatch token, action ID, status, active slot, version
  ni timestamps de persistencia: corresponden a la futura factoría/productor.

El snapshot persistente inicial exige `dispatching`, generation mínima 1,
attempt mínimo 0, version mínima 1 y active slot 1
(`app/Modules/Orders/Domain/DurableRetry/DurableRetryScheduleSnapshot.php:153-168`).
A1 expresa sólo los datos
autoritativos que el futuro transfer authority recibe.

## 11. Resultado de transferencia

### 11.1 Estados exactos

Se conservan exactamente los siete estados del diseño
(`docs/durable-retry-production-activation-design.md:182-193`):

| Constante | Valor | Éxito | Idempotente | Scheduling inicial | Recovery | Bloquea legacy |
|---|---|---:|---:|---:|---:|---:|
| `TRANSFERRED` | `transferred` | Sí | No | Sí | No | Sí permanente |
| `ALREADY_TRANSFERRED` | `already_transferred` | Sí | Sí | No | No por sí solo | Sí permanente |
| `LEGACY_IN_FLIGHT` | `legacy_in_flight` | No | No | No | No | No; legacy ya ganó claim |
| `FUNCTIONALLY_INELIGIBLE` | `functionally_ineligible` | No | No | No | No | Sí para esta invocación; reconsultar |
| `DURABLE_INCONSISTENCY` | `durable_inconsistency` | No | No | No | Sí/manual | Sí |
| `PERSISTENCE_ERROR` | `persistence_error` | No | No | No | Reconsulta requerida | Sí hasta reconsulta |
| `OUTCOME_UNCERTAIN` | `outcome_uncertain` | No | No | No | Sí | Sí |

Sólo `TRANSFERRED` permite el primer scheduling externo. La convergencia de una
fila ya transferida será responsabilidad de coordinator/recovery futuro;
`ALREADY_TRANSFERRED` no autoriza una segunda programación ciega.

### 11.2 Razones exactas

`DurableRetryInitialTransferReason`:

| Constante | Estado permitido | Mensaje |
|---|---|---|
| `INITIAL_TRANSFER_CREATED` | transferred | `Initial durable retry authority transfer created.` |
| `EQUIVALENT_TRANSFER_EXISTS` | already_transferred | `Equivalent durable retry authority transfer already exists.` |
| `LEGACY_CLAIM_IN_FLIGHT` | legacy_in_flight | `Legacy scheduling authority is already in flight.` |
| `FUNCTIONAL_RECORD_ABSENT` | functionally_ineligible | `Functional authority record is absent.` |
| `FUNCTIONAL_STATE_INELIGIBLE` | functionally_ineligible | `Functional authority state is ineligible.` |
| `EXISTING_TRANSFER_INCOMPATIBLE` | durable_inconsistency | `Existing durable retry authority transfer is incompatible.` |
| `DUPLICATE_DURABLE_IDENTITY` | durable_inconsistency | `Duplicate durable retry generation identity detected.` |
| `PERSISTENCE_WRITE_FAILED` | persistence_error | `Durable retry authority transfer persistence failed.` |
| `PERSISTENCE_OUTCOME_UNCERTAIN` | outcome_uncertain | `Durable retry authority transfer outcome is uncertain.` |

API:

```php
namespace VeciAhorra\Modules\Orders\Domain\DurableRetry;

final class DurableRetryInitialTransferReason
{
    public const INITIAL_TRANSFER_CREATED = 'initial_transfer_created';
    public const EQUIVALENT_TRANSFER_EXISTS = 'equivalent_transfer_exists';
    public const LEGACY_CLAIM_IN_FLIGHT = 'legacy_claim_in_flight';
    public const FUNCTIONAL_RECORD_ABSENT = 'functional_record_absent';
    public const FUNCTIONAL_STATE_INELIGIBLE = 'functional_state_ineligible';
    public const EXISTING_TRANSFER_INCOMPATIBLE = 'existing_transfer_incompatible';
    public const DUPLICATE_DURABLE_IDENTITY = 'duplicate_durable_identity';
    public const PERSISTENCE_WRITE_FAILED = 'persistence_write_failed';
    public const PERSISTENCE_OUTCOME_UNCERTAIN = 'persistence_outcome_uncertain';

    public static function all(): array;
    public static function allowedFor(string $state): array;
    public static function assertFor(string $reason, string $state): void;
    public static function message(string $reason): string;
    private function __construct();
}
```

### 11.3 API exacta

```php
namespace VeciAhorra\Modules\Orders\Domain\DurableRetry;

final class DurableRetryInitialTransferResult
{
    public const TRANSFERRED = 'transferred';
    public const ALREADY_TRANSFERRED = 'already_transferred';
    public const LEGACY_IN_FLIGHT = 'legacy_in_flight';
    public const FUNCTIONALLY_INELIGIBLE = 'functionally_ineligible';
    public const DURABLE_INCONSISTENCY = 'durable_inconsistency';
    public const PERSISTENCE_ERROR = 'persistence_error';
    public const OUTCOME_UNCERTAIN = 'outcome_uncertain';

    private function __construct(
        private readonly string $state,
        private readonly string $reason,
        private readonly ?DurableRetryGenerationIdentity $generationIdentity
    );

    public static function transferred(
        DurableRetryGenerationIdentity $identity
    ): self;

    public static function alreadyTransferred(
        DurableRetryGenerationIdentity $identity
    ): self;

    public static function legacyInFlight(): self;
    public static function functionallyIneligible(string $reason): self;

    public static function durableInconsistency(
        string $reason,
        ?DurableRetryGenerationIdentity $identity = null
    ): self;

    public static function persistenceError(): self;

    public static function outcomeUncertain(
        ?DurableRetryGenerationIdentity $identity = null
    ): self;

    public function state(): string;
    public function reason(): string;
    public function diagnosticMessage(): string;
    public function generationIdentity(): ?DurableRetryGenerationIdentity;
    public function succeeded(): bool;
    public function idempotent(): bool;
    public function permitsInitialExternalScheduling(): bool;
    public function requiresRecovery(): bool;
    public function blocksLegacy(): bool;
}
```

Matrices:

- `transferred()` y `alreadyTransferred()` exigen identidad inicial
  (`generation === 1`);
- sólo esos estados requieren identidad;
- inconsistencia/outcome incierto pueden incluir identidad cuando fue
  demostrable; los demás estados la prohíben;
- las factories sin razón fijan la única razón válida;
- `functionallyIneligible()` acepta sólo sus dos razones;
- `durableInconsistency()` acepta sólo sus dos razones;
- `succeeded()` es true para transferred/already;
- `idempotent()` sólo para already;
- `permitsInitialExternalScheduling()` sólo para transferred;
- `requiresRecovery()` para inconsistency, persistence error y uncertain;
- `blocksLegacy()` es false únicamente para legacy in flight. Para los demás
  resultados, un consumidor legacy debe realizar una consulta individual
  autoritativa antes de actuar; nunca inferir permiso desde el transfer result.

No existe estado `invalid_input`. Una solicitud inválida lanza antes de delegar.

## 12. Contrato de transferencia

```php
namespace VeciAhorra\Modules\Orders\Contracts;

use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialTransferRequest;
use VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialTransferResult;

interface DurableRetryInitialTransferAuthorityInterface
{
    public function transferReconciliation(
        DurableRetryInitialTransferRequest $request
    ): DurableRetryInitialTransferResult;
}
```

Restricciones:

- sólo recibe solicitud tipada;
- sólo representa `legacy → durable`;
- no recibe arrays, flag, destino, repository, conexión o lock;
- no tiene rollback, delete, release, force, update ni transfer-back;
- argumentos inválidos no llegan al implementador;
- A1 no proporciona implementación.

## 13. Matriz normativa de estados

| Operación | Estado observado | Resultado | Legacy autorizado | Durable autorizado | Scheduling externo | Recovery | Excepción | Siguiente acción |
|---|---|---|---:|---:|---:|---:|---|---|
| Consulta | Sin fila durable, evidencia completa | `legacy` | Sí | No | Legacy puede programar | No | No | Continuar legacy |
| Consulta | Generation 1 compatible | `durable` | No | Sí | Sólo pipeline durable | No | No | Bloquear legacy |
| Consulta | Fila incompatible | `indeterminate(INCOMPATIBLE_DURABLE_STATE)` | No | No para mutación nueva | No | Sí/manual | No | Alertar/remediar |
| Consulta | Duplicados | `indeterminate(PERSISTED_DUPLICATE)` | No | No para mutación nueva | No | Sí/manual | No | Alertar/remediar |
| Consulta | Lectura fallida | `indeterminate(QUERY_FAILED)` | No | No para mutación nueva | No | Sí | No | Reintentar consulta |
| Consulta batch | Falta identidad | `indeterminate(INCOMPLETE_RESULT)` | No | No | No | Sí | No | Completar/reintentar |
| Consulta batch | Resultado adicional | Sin resultado | No | No | No | No | `INVALID_AUTHORITY_BATCH` | Corregir implementador |
| Transfer | Creación inicial correcta | `TRANSFERRED` | No | Sí | Sí, una vez | No | No | Coordinator futuro |
| Transfer | Repetición equivalente | `ALREADY_TRANSFERRED` | No | Sí | No | No | No | No duplicar |
| Transfer | Metadata incompatible | `DURABLE_INCONSISTENCY` | No | No para nueva mutación | No | Sí/manual | No | Diagnóstico |
| Transfer | Duplicate persistente | `DURABLE_INCONSISTENCY` | No | No para nueva mutación | No | Sí/manual | No | Diagnóstico |
| Transfer | Persistencia falló con rollback conocido | `PERSISTENCE_ERROR` | No hasta reconsulta | No | No | Sí | No | Reconsultar autoridad |
| Transfer | Commit incierto | `OUTCOME_UNCERTAIN` | No | No para repetición ciega | No | Sí | No | Recovery durable |
| Transfer | Claim legacy ganó | `LEGACY_IN_FLIGHT` | Sí, sólo claim existente | No | No nuevo | No | No | Dejar terminar legacy |
| Transfer | Entrada inválida | Sin resultado | No | No | No | No | Excepción A1 | Corregir caller |

## 14. Tabla normativa de validaciones

Los type errors de PHP estricto son aceptables para tipos incompatibles que no
pueden entrar a la factory. Las validaciones semánticas lanzan
`DurableRetryActivationContractException` con el código indicado.

| Entrada | Objeto afectado | Resultado |
|---|---|---|
| Entero positivo | IDs/generation | Válido dentro de las relaciones requeridas |
| Cero | Identity/request/generation | Excepción invalid identity/request |
| Negativo | Identity/request/generation | Excepción invalid identity/request |
| Decimal | Parámetro `int` | `TypeError`; cero coerción con strict types |
| String numérico `"1"` | Parámetro `int` | `TypeError` |
| Notación científica `"1e3"` | Parámetro `int` | `TypeError` |
| String con espacios `" 1 "` | Parámetro `int` | `TypeError` |
| String con sufijo `"1x"` | Parámetro `int` | `TypeError` |
| `null` | Parámetro no nullable | `TypeError` |
| Boolean | Parámetro `int` | `TypeError` |
| Array | Parámetro tipado | `TypeError` |
| Objeto incompatible | Parámetro tipado | `TypeError` |
| Stage desconocido | No existe factory genérica | No representable por API |
| Stage distinto de reconciliation | Authority request | Excepción si objeto incompatible llega por violación |
| Combinación parcial | Request/result/batch | Factory no invocable o excepción |
| Generation distinta de 1 | Initial request/result | No es input; result rechaza identity no inicial |
| Completion ID incompatible | Initial request | `INVALID_INITIAL_TRANSFER_REQUEST` |
| Campo adicional | Sin array input | No representable por API |
| Zona no UTC | Initial request | `INVALID_INITIAL_TRANSFER_REQUEST` |
| Timestamp con microsegundos | Initial request | `INVALID_INITIAL_TRANSFER_REQUEST` |
| Razón desconocida | Authority/transfer result | Excepción del resultado correspondiente |
| Estado desconocido | Constructor privado | No representable; reflection harness debe probar rechazo interno |
| Identidad batch duplicada | Collection | `INVALID_IDENTITY_COLLECTION` |
| Más de 500 identidades | Collection | `INVALID_IDENTITY_COLLECTION` |
| Entry batch adicional/duplicada | Batch result | `INVALID_AUTHORITY_BATCH` |
| Entry batch faltante | Batch result | `indeterminate(INCOMPLETE_RESULT)` para esa identidad |

## 15. Idempotencia, permanencia y conflicto

1. `TRANSFERRED` significa que esta invocación creó por primera vez el marcador
   durable generation 1.
2. `ALREADY_TRANSFERRED` exige misma identidad funcional y metadata requerida
   equivalente. Es éxito idempotente, no creación.
3. Una diferencia de `completion_id` para la misma autoridad es
   `DURABLE_INCONSISTENCY`, nunca idempotencia.
4. `OUTCOME_UNCERTAIN` no autoriza fallback ni repetición ciega.
5. Generation posterior mantiene la misma autoridad funcional.
6. No existe método para borrar, degradar o transferir hacia legacy.
7. Una acción de Action Scheduler nunca prueba autoridad.
8. Un flag nunca prueba autoridad.
9. Sólo un `DurableRetryLegacyAuthorityResult::legacy()` autoriza legacy.
10. El resultado de transferencia no sustituye una consulta de autoridad para
    decisiones legacy posteriores.

## 16. Archivos exactos autorizados para A1

### 16.1 Código puro

| Ruta | FQCN / tipo | Responsabilidad | Harness |
|---|---|---|---|
| `app/Modules/Orders/Exceptions/DurableRetryActivationContractException.php` | excepción final | Catálogo seguro | authority + transfer |
| `app/Modules/Orders/Domain/DurableRetry/DurableRetryAuthorityIdentity.php` | VO final | Identidad funcional | authority |
| `app/Modules/Orders/Domain/DurableRetry/DurableRetryGenerationIdentity.php` | VO final | Identidad generación | transfer |
| `app/Modules/Orders/Domain/DurableRetry/DurableRetryAuthorityIdentityCollection.php` | collection VO | Entrada batch | authority |
| `app/Modules/Orders/Domain/DurableRetry/DurableRetryIndeterminateReason.php` | catálogo final | Razones authority | authority |
| `app/Modules/Orders/Domain/DurableRetry/DurableRetryLegacyAuthorityResult.php` | result final | Resultado individual | authority |
| `app/Modules/Orders/Domain/DurableRetry/DurableRetryLegacyAuthorityEntry.php` | VO final | Par batch | authority |
| `app/Modules/Orders/Domain/DurableRetry/DurableRetryLegacyAuthorityBatchResult.php` | result final | Resultado batch | authority |
| `app/Modules/Orders/Contracts/DurableRetryLegacyExclusionInterface.php` | interface | Consulta individual/batch | contracts |
| `app/Modules/Orders/Domain/DurableRetry/DurableRetryInitialTransferRequest.php` | VO final | Solicitud inicial | transfer |
| `app/Modules/Orders/Domain/DurableRetry/DurableRetryInitialTransferReason.php` | catálogo final | Razones transfer | transfer |
| `app/Modules/Orders/Domain/DurableRetry/DurableRetryInitialTransferResult.php` | result final | Resultado transfer | transfer |
| `app/Modules/Orders/Contracts/DurableRetryInitialTransferAuthorityInterface.php` | interface | Transfer request | contracts |

### 16.2 Harnesses

| Ruta | Certifica |
|---|---|
| `tests/manual/durable-retry-activation-authority-contract-test.php` | Identidades, colección, razones, resultado individual y batch |
| `tests/manual/durable-retry-activation-transfer-contract-test.php` | Request, generation identity, estados, razones, permanencia e idempotencia |
| `tests/manual/durable-retry-activation-contract-infrastructure-test.php` | Firmas exactas, pureza, doubles y prohibiciones estructurales |

Los doubles sólo pueden declararse dentro de los harnesses.

### 16.3 Componentes prohibidos

A1 no puede modificar ningún archivo existente ni ningún archivo fuera de las
16 rutas anteriores. En particular quedan prohibidos:

- repositories y schema;
- `Application`;
- `WebpayReconciliationMaterializer`;
- `DurableCompletionScheduler`, workers y recovery;
- scheduler externo, coordinator, callback y registrar;
- executor, registry, policy y processors;
- documentación;
- migraciones, configuración y feature flags;
- artifacts y once documentos protegidos.

## 17. Pruebas obligatorias de A1

### Harness authority

Debe probar:

- identity válida, igualdad, desigualdad y diagnostic key;
- rechazo de todos los tipos/valores de la tabla aplicables;
- generation identity inicial y arbitraria positiva;
- colección vacía, ordenada, duplicada y límite;
- tres estados exactos y siete razones;
- sólo legacy autorizado;
- invariantes de factories y readonly;
- batch completo, vacío, parcial, duplicado, adicional y lookup ajeno;
- orden determinista;
- inmutabilidad por reflection.

### Harness transfer

Debe probar:

- request válida, UTC, formato, constantes y getters;
- completion ID igual y diferente;
- generation/attempt/reason no configurables;
- siete estados exactos y nueve razones;
- factories y matrices identity/reason;
- success, idempotence, scheduling, recovery y blocksLegacy;
- ausencia de estado invalid input y de reversión;
- mensajes exactos y seguros.

### Harness contracts/infrastructure

Debe probar:

- FQCN y firmas mediante reflection;
- parámetros y retornos exactos;
- doubles utilizables sin infraestructura;
- `strict_types=1`, final/readonly state y cero setters;
- ausencia textual de `$wpdb`, SQL, `as_*`, `add_action`,
  `DurableRetryExecutor`, callback, registrar, processors, scheduler,
  workers, recovery, WordPress y feature flags;
- ausencia de delete, rollback, force, release, transfer-back;
- cero archivos adicionales a la allowlist A1.

Regresiones: identidad/snapshot durable, catálogo de stages, repository
contracts, scheduler externo, callback, registrar, registry, executor,
policy/result, next generation, nullable attempt y cuatro processors aislados.

## 18. Dependencias y excepciones por categoría

| Categoría | Representación A1 | Se lanza | Transformación futura |
|---|---|---:|---|
| Entrada inválida | Sin resultado | Sí, excepción A1 | Ninguna; corregir caller |
| Violación de contrato | Sin resultado | Sí, excepción A1 | No silenciar |
| Consulta de infraestructura recuperable | `indeterminate(QUERY_FAILED)` | No | Adapter captura y cierra |
| Consulta inconsistente | `indeterminate(<reason>)` | No | Alertar/remediar |
| Escritura fallida conocida | `PERSISTENCE_ERROR` | No | Reconsultar |
| Outcome persistente incierto | `OUTCOME_UNCERTAIN` | No | Recovery durable |
| Fila persistente incompatible | `DURABLE_INCONSISTENCY` | No | Remediación cerrada |
| Error de programación/fatal | Fuera de resultado | Sí | Propagar |

Ninguna excepción pública incluye datos sensibles. Los IDs internos sólo
aparecen en diagnostic keys cuando el caller decide registrarlos; los mensajes
son constantes.

## 19. Criterios de implementabilidad de A1

Quedan cerrados:

1. Identidad funcional: `DurableRetryAuthorityIdentity`.
2. Identidad persistente: `DurableRetryGenerationIdentity`.
3. `completion_id`: metadata obligatoria, igual a subject, no identidad.
4. FQCN: tabla de tipos y allowlist.
5. Namespaces definitivos: Contracts, Domain/DurableRetry, Exceptions.
6. Estados authority: legacy, durable, indeterminate.
7. Razones indeterminate: siete constantes exactas.
8. API individual: `classify(identity)`.
9. API batch: collection tipada y batch result validado.
10. Transfer states: siete estados preservados.
11. Invalid input: excepción previa, no outcome.
12. Firmas: capítulos 5-12.
13. Excepciones: catálogo único A1.
14. Archivos: 13 productivos puros + 3 harnesses.
15. Harnesses: alcance exacto en sección 17.

**Veredicto normativo: A1 es implementable.**

La aprobación sólo autoriza crear los archivos de la sección 16. No autoriza
implementar persistencia, productores, exclusiones, recovery, scheduling,
feature flags, wiring ni tráfico productivo. A1 debe terminar con contratos
puros certificados y sin integración.

## 20. Referencias técnicas auditadas

- Diseño de wiring y fases:
  `docs/durable-retry-production-wiring-design.md:382-475`.
- Lifecycle y orden de coexistencia:
  `docs/durable-retry-processing-lifecycle-design.md:635-654`.
- Stages canónicos:
  `app/Modules/Orders/Domain/DurableRetry/DurableRetryStage.php:9-31`.
- Shape y validación del snapshot:
  `app/Modules/Orders/Domain/DurableRetry/DurableRetryScheduleSnapshot.php:13-168`.
- Columnas de creación y lookup de identidad:
  `app/Modules/Orders/Repositories/DurableRetryScheduleRepository.php:29-66`,
  `:95-139`, `:162-176`.
- Índices persistentes:
  `app/Database/Schemas/DurableRetryScheduleSchema.php:39-48`.
- Scheduler externo detrás de adapter:
  `app/Modules/Orders/Services/DurableRetryExternalScheduleCoordinator.php:29-103`.
- Executor como coordinador:
  `app/Modules/Orders/Services/DurableRetryExecutor.php:26-321`.
- Productor funcional actual:
  `app/Modules/Payments/Reconciliation/Service/WebpayReconciliationMaterializer.php:29-125`,
  `:212`.
- Scheduler y retries legacy:
  `app/Modules/Fulfillment/Orchestration/DurableCompletionScheduler.php:7-35`.
- Workers legacy:
  `app/Modules/Fulfillment/Orchestration/DurableCompletionWorkers.php:23-76`.
- Recovery legacy:
  `app/Modules/Fulfillment/Orchestration/DurableCompletionRecovery.php:9-38`.
- Processors certificados:
  `app/Modules/Orders/Services/DurableRetryReconciliationProcessor.php:21-169`,
  `app/Modules/Orders/Services/DurableRetryBusinessCompletionProcessor.php:17-286`,
  `app/Modules/Orders/Services/DurableRetryDeliveryCompletionProcessor.php:17-296`,
  `app/Modules/Orders/Services/DurableRetryFulfillmentProcessor.php:17-288`.

Toda desviación de esta API requiere un microhito documental posterior. A1 no
puede resolverla mediante una decisión local de implementación.
