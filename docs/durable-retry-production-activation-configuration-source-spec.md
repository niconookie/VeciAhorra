# Especificación normativa A2.1: fuente productiva de configuración de activación

## 1. Estado

**IMPLEMENTABLE SIN DECISIONES PENDIENTES**

Esta especificación cierra la adaptación productiva entre una option WordPress
por sitio y el snapshot inmutable consumido por la política A2.

No autoriza wiring, transferencia, consultas SQL, rollout ni escritura de la
option.

## 2. Objetivo

Implementar posteriormente una fuente productiva que satisfaga:

```php
DurableRetryActivationConfigurationSourceInterface::snapshot()
    : DurableRetryActivationConfiguration;
```

La fuente debe traducir exactamente una lectura física de configuración en un
snapshot A2 válido o en una excepción cerrada.

## 3. Identificador del microhito

El identificador normativo es:

```text
A2.1 — Fuente productiva de configuración de activación
```

`A3` conserva exclusivamente su significado histórico:

```text
Lectura de marcador y clasificación batch
```

Ningún archivo, clase, harness o informe de A2.1 debe denominar la fuente
“A3”.

## 4. Posición en la secuencia

La secuencia normativa relevante es:

1. A2: política determinista y contrato abstracto de source;
2. A2.1: source productivo aislado, todavía no registrado;
3. A3: lectura de marcador y clasificación batch;
4. A5: productor inicial aislado;
5. A10: wiring productivo y selección exclusiva;
6. A11: certificación integral;
7. A12: canario y comienzo del rollout.

A2.1 puede implementarse antes de A3 porque no consulta autoridad ni marcador.
No puede recibir tráfico hasta A10 y no puede habilitar porcentaje distinto de
cero como parte de este microhito.

## 5. Base normativa

La especificación se redacta sobre:

- rama `main`;
- commit `1950cda203d3abe228f68f2be79fbf27610eff9e`;
- schema `0.24.0`;
- PHP mínimo 8.2;
- WordPress mínimo 6.8;
- A1 y A2 ya versionados;
- A2 todavía sin wiring productivo.

## 6. Autoridades

Se inspeccionaron:

- `docs/durable-retry-production-activation-design.md`;
- `docs/durable-retry-production-activation-a2-readiness-audit.md`;
- `docs/durable-retry-production-activation-a2-flag-policy-spec.md`;
- `docs/durable-retry-production-activation-a3-configuration-source-readiness-audit.md`;
- implementación y harnesses A2;
- `app/Core/Config.php`;
- `app/Core/Application.php`;
- `app/Core/Container.php`;
- patrones de configuración de Payments;
- harnesses de composición y configuración.

Esta especificación, y no el patrón de Payments, es la autoridad exclusiva para
A2.1.

## 7. Alcance positivo

A2.1 debe crear:

1. un contrato de lectura cruda;
2. un resultado tipado de lectura cruda;
3. un adapter de WordPress Options;
4. la fuente productiva que implementa el contrato A2;
5. una excepción específica de fuente;
6. tres harnesses;
7. una actualización mínima de la guardia histórica de dominio.

## 8. Exclusiones

A2.1 no debe:

- registrar servicios en container o bootstrap;
- modificar `Application`;
- construir la política A2 productivamente;
- modificar producers;
- iniciar transferencias;
- leer marcadores durable;
- clasificar autoridad individual o batch;
- ejecutar SQL;
- modificar schema o migraciones;
- registrar hooks;
- programar acciones;
- depender de Action Scheduler;
- implementar rollout;
- añadir UI, REST o WP-CLI;
- crear, actualizar o eliminar options;
- añadir métricas, logs o eventos;
- modificar `Config`;
- modificar documentación durante la implementación.

## 9. Contrato heredado de A2

La fuente concreta implementa sin cambios:

```php
namespace VeciAhorra\Modules\Orders\Contracts;

interface DurableRetryActivationConfigurationSourceInterface
{
    public function snapshot(
    ): DurableRetryActivationConfiguration;
}
```

El resultado contiene:

```text
stage = reconciliation
percentage = int 0..100
algorithmVersion = sha256-24bit-mod100-v1
```

## 10. Arquitectura elegida

La arquitectura tiene dos límites:

```text
WordPress option
→ WordPressOptionDurableRetryActivationConfigurationValueReader
→ DurableRetryActivationConfigurationValue
→ DurableRetryProductionActivationConfigurationSource
→ DurableRetryActivationConfiguration
```

La fuente de dominio de configuración no llama funciones WordPress. Recibe un
lector tipado. El adapter WordPress es el único archivo autorizado para invocar
`get_option()`.

## 11. FQCN de la fuente

Clase:

```text
DurableRetryProductionActivationConfigurationSource
```

FQCN:

```text
VeciAhorra\Modules\Orders\Infrastructure\DurableRetry\
DurableRetryProductionActivationConfigurationSource
```

Ruta:

```text
app/Modules/Orders/Infrastructure/DurableRetry/
DurableRetryProductionActivationConfigurationSource.php
```

Debe ser `final`.

## 12. Firma de la fuente

La declaración normativa es:

```php
final class DurableRetryProductionActivationConfigurationSource
    implements DurableRetryActivationConfigurationSourceInterface
{
    public function __construct(
        private readonly
        DurableRetryActivationConfigurationValueReaderInterface $reader
    );

    public function snapshot(
    ): DurableRetryActivationConfiguration;
}
```

El constructor es público. No se permiten argumentos opcionales ni dependencias
adicionales.

## 13. Contrato del lector

Nombre:

```text
DurableRetryActivationConfigurationValueReaderInterface
```

FQCN:

```text
VeciAhorra\Modules\Orders\Contracts\
DurableRetryActivationConfigurationValueReaderInterface
```

Ruta:

```text
app/Modules/Orders/Contracts/
DurableRetryActivationConfigurationValueReaderInterface.php
```

Firma única:

```php
public function read(
): DurableRetryActivationConfigurationValue;
```

No recibe clave: A2.1 posee una sola clave canónica.

## 14. Resultado de lectura

Nombre:

```text
DurableRetryActivationConfigurationValue
```

FQCN:

```text
VeciAhorra\Modules\Orders\Domain\DurableRetry\
DurableRetryActivationConfigurationValue
```

Ruta:

```text
app/Modules/Orders/Domain/DurableRetry/
DurableRetryActivationConfigurationValue.php
```

Debe ser `final` y contener:

```php
private function __construct(
    private readonly bool $present,
    private readonly mixed $value
);

public static function absent(): self;
public static function present(mixed $value): self;
public function isPresent(): bool;
public function value(): mixed;
```

`absent()` almacena `false` y `null`. `present()` almacena `true` y conserva el
tipo y valor exactos, sin coerción.

Invocar `value()` sobre un resultado ausente lanza:

```php
LogicException(
    'Absent durable retry activation configuration value has no payload.'
);
```

No hay setters ni estados adicionales.

## 15. Adapter WordPress

Nombre:

```text
WordPressOptionDurableRetryActivationConfigurationValueReader
```

FQCN:

```text
VeciAhorra\Modules\Orders\Infrastructure\DurableRetry\
WordPressOptionDurableRetryActivationConfigurationValueReader
```

Ruta:

```text
app/Modules/Orders/Infrastructure/DurableRetry/
WordPressOptionDurableRetryActivationConfigurationValueReader.php
```

Debe ser `final`, implementar
`DurableRetryActivationConfigurationValueReaderInterface` y tener constructor
público sin argumentos.

## 16. Fuente canónica

La única autoridad física es una WordPress Option por sitio leída con:

```php
get_option(self::OPTION_NAME, $absentSentinel);
```

No hay fallback a:

- `Config`;
- constantes PHP;
- variables de entorno;
- options de red;
- archivos;
- filtros llamados explícitamente por el plugin;
- defaults almacenados;
- otros settings.

## 17. Clave literal

El adapter expone:

```php
public const OPTION_NAME =
    'veciahorra_durable_retry_activation_reconciliation_percentage';
```

Ésta es la única clave externa.

No existen claves externas para stage o algoritmo.

## 18. Stage y algoritmo

La fuente fija internamente:

```text
stage = DurableRetryStage::RECONCILIATION
algorithmVersion = DurableRetryActivationCohort::ALGORITHM_VERSION
```

No los lee, normaliza ni permite sobrescribirlos.

La creación de un snapshot presente debe usar:

```php
DurableRetryActivationConfiguration::reconciliation(
    $percentage,
    DurableRetryActivationCohort::ALGORITHM_VERSION
);
```

## 19. Precedencia

La precedencia total contiene un solo nivel:

```text
WordPress site option
```

No se consulta ninguna segunda fuente. Ausencia no activa una búsqueda
alternativa.

## 20. Detección de ausencia

El adapter crea un objeto sentinel nuevo por llamada:

```php
$absentSentinel = new stdClass();
```

Realiza exactamente:

```php
$raw = get_option(self::OPTION_NAME, $absentSentinel);
```

Si `$raw === $absentSentinel`, devuelve:

```php
DurableRetryActivationConfigurationValue::absent();
```

Cualquier otro valor, incluido `null`, es presente:

```php
DurableRetryActivationConfigurationValue::present($raw);
```

La comparación es por identidad estricta.

## 21. Tipos crudos

WordPress o sus filtros pueden entregar cualquier `mixed`.

La matriz de clasificación es:

| Tipo | Clasificación inicial |
|---|---|
| sentinel interno | Ausente |
| `int` | Presente, candidato |
| `string` | Presente, candidato |
| `null` | Presente, inválido |
| `bool` | Presente, inválido |
| `float` | Presente, inválido |
| array | Presente, inválido |
| objeto distinto del sentinel | Presente, inválido |
| resource | Presente, inválido |

No hay coerción PHP implícita.

## 22. Normalización de enteros

Un `int` nativo se acepta como candidato sin conversión. Después se valida el
rango inclusivo `0..100`.

Un `string` sólo se acepta si coincide byte por byte con:

```regex
\A(?:0|[1-9][0-9]{0,2})\z
```

La comprobación debe usar:

```php
preg_match('/\A(?:0|[1-9][0-9]{0,2})\z/D', $raw) === 1
```

Sólo después se convierte mediante `(int) $raw` y se valida `0..100`.

## 23. Valores aceptados

Se aceptan:

- enteros nativos `0` a `100`;
- strings `"0"` a `"100"` en decimal canónico;
- `"1"`, `"9"`, `"10"`, `"50"`, `"99"` y `"100"`.

No se altera el valor numérico aceptado.

## 24. Valores rechazados

Se rechazan:

- enteros menores que 0 o mayores que 100;
- strings con ceros iniciales, salvo `"0"`;
- signo `+` o `-`;
- espacios;
- tabs;
- CR o LF;
- string vacío;
- decimales;
- notación científica;
- hexadecimal;
- booleanos;
- `null` presente;
- floats;
- arrays;
- objetos;
- resources.

Ejemplos rechazados:

```text
"01"
"+1"
"-1"
" 50 "
"50\t"
"50\n"
"50.0"
"5e1"
"0x32"
```

## 25. Ausencia

Ausencia legítima produce exactamente:

```php
DurableRetryActivationConfiguration::disabled()
```

No lanza y no crea la option.

Ausencia sólo significa que `get_option()` devolvió el sentinel exacto.

## 26. Invalidez

Un valor presente que no cumple tipo, forma o rango es inválido.

La invalidez:

- nunca se transforma en ausencia;
- nunca se degrada a 0;
- nunca se clampa;
- nunca consulta otra fuente;
- lanza la excepción A2.1 `INVALID_VALUE`.

## 27. Indisponibilidad

La fuente se considera no disponible cuando:

- `get_option` no existe;
- el lector lanza cualquier `Throwable`;
- la llamada subyacente no puede completarse por un error interno.

El adapter debe comprobar `function_exists('get_option')`. Si no existe, lanza:

```php
RuntimeException(
    'WordPress option API is unavailable.'
);
```

La fuente captura ese `Throwable` únicamente alrededor de `$reader->read()` y
lanza la excepción A2.1 `SOURCE_UNAVAILABLE`, preservando la causa.

## 28. Default apagado

El porcentaje 0 se aplica automáticamente sólo ante ausencia legítima.

También puede resultar de valores presentes válidos `0` o `"0"`.

No se aplica como recuperación ante:

- valor inválido;
- reader indisponible;
- excepción interna;
- API WordPress ausente.

## 29. Excepción A2.1

Nombre:

```text
DurableRetryActivationConfigurationSourceException
```

FQCN:

```text
VeciAhorra\Modules\Orders\Exceptions\
DurableRetryActivationConfigurationSourceException
```

Ruta:

```text
app/Modules/Orders/Exceptions/
DurableRetryActivationConfigurationSourceException.php
```

Debe ser `final` y extender `RuntimeException`.

## 30. Catálogo de excepción

Constantes públicas:

```php
public const INVALID_VALUE =
    'invalid_activation_configuration_value';
public const SOURCE_UNAVAILABLE =
    'activation_configuration_source_unavailable';
```

API pública:

```php
public static function forCode(
    string $code,
    ?Throwable $previous = null
): self;

public function reasonCode(): string;
```

Mensajes exactos:

| Código | Mensaje |
|---|---|
| `invalid_activation_configuration_value` | `Invalid durable retry activation configuration value.` |
| `activation_configuration_source_unavailable` | `Durable retry activation configuration source is unavailable.` |

Un código desconocido lanza:

```php
InvalidArgumentException(
    'Invalid durable retry activation configuration source exception code.'
);
```

## 31. Causas y datos públicos

`SOURCE_UNAVAILABLE` conserva el `Throwable` original como `$previous`.

`INVALID_VALUE` no tiene causa, porque representa clasificación determinista del
payload recibido.

Ningún mensaje incluye:

- nombre de option;
- valor crudo;
- tipo serializado;
- stack externo;
- datos de usuario.

La clave no es secreta, pero tampoco debe duplicarse en mensajes públicos.

## 32. Semántica de snapshot

Cada llamada a `snapshot()`:

1. llama exactamente una vez a `$this->reader->read()`;
2. captura el resultado en una variable local;
3. si está ausente, crea y devuelve `disabled()`;
4. si está presente, lee `value()` exactamente una vez;
5. valida tipo y forma;
6. valida rango;
7. crea un nuevo `DurableRetryActivationConfiguration`;
8. devuelve ese objeto.

No se reutiliza una instancia anterior.

## 33. Lecturas físicas

Cada llamada al reader WordPress:

- crea un sentinel local;
- comprueba disponibilidad de la función;
- ejecuta exactamente un `get_option()`;
- devuelve exactamente un resultado tipado.

Por cada `snapshot()` hay:

```text
1 reader.read()
1 get_option()
```

salvo que `get_option` no exista; en ese caso hay una comprobación y cero
llamadas físicas antes de la excepción.

## 34. Atomicidad

La option contiene un único escalar. Una sola llamada a `get_option()` captura
todo el estado necesario.

Stage y algoritmo son constantes de código y no requieren lecturas físicas.

No se permiten options separadas ni lecturas múltiples, por lo que no existe una
ventana de mezcla entre porcentaje, stage y versión.

## 35. Caché y estabilidad

A2.1 no implementa cache propia:

- no hay cache por objeto;
- no hay cache por request;
- no hay static cache;
- no hay transients;
- no hay cache persistente.

Cada llamada a `snapshot()` relee el reader. Un cambio de option puede verse en
la llamada siguiente.

Dentro de una llamada, el valor local es estable.

La cache interna normal de WordPress Options no es una cache de A2.1 y no
autoriza a omitir la llamada normativa a `get_option()`.

## 36. Cambios concurrentes

Si la option cambia:

- antes de `get_option()`, puede observarse el valor nuevo;
- después de `get_option()`, no altera el snapshot en construcción;
- entre dos `snapshot()`, la segunda llamada puede devolver otro porcentaje.

La política A2 sigue viendo un solo snapshot por decisión.

## 37. WordPress y multisite

Se usa exclusivamente:

```php
get_option()
```

No se usa `get_site_option()`.

La configuración es por sitio/blog actual. En multisite, cada sitio tiene su
propio porcentaje y la conmutación de blog de WordPress determina qué option se
lee.

A2.1 no llama `switch_to_blog()` ni restaura blogs.

## 38. Autoload y escritura futura

A2.1 es estrictamente read-only y no puede crear la option.

Una herramienta operacional futura que la cree debe usar autoload deshabilitado
y capacidad:

```text
manage_options
```

La forma exacta de esa herramienta queda fuera de A2.1. A2.1 funciona aunque la
option no exista.

No se autoriza `add_option()`, `update_option()` ni `delete_option()` en la
allowlist de implementación.

## 39. CLI y harnesses

En WP-CLI con WordPress cargado, `get_option()` existe y la conducta es idéntica
a una request web.

En un proceso PHP sin WordPress:

- el adapter lanza `RuntimeException` con el mensaje normativo interno;
- la fuente lo convierte en `SOURCE_UNAVAILABLE`;
- no se usa un default silencioso.

Los harnesses pueden declarar un double global de `get_option()` antes de
cargar el autoloader.

## 40. Config

`app/Core/Config.php` no participa.

A2.1:

- no añade constantes a `Config`;
- no lee `Config`;
- no modifica versiones;
- no crea métodos de acceso;
- no añade precedencia con `Config`.

## 41. Seguridad

El porcentaje no contiene secretos ni PII.

Todos los valores devueltos por WordPress, incluidos los producidos por filtros
core o de terceros, se tratan como entrada no confiable y pasan por la misma
matriz estricta.

Se prohíbe:

- `unserialize()` manual;
- coerción de arrays u objetos;
- registrar valores crudos;
- incluir valores crudos en excepciones;
- aplicar filtros propios;
- aceptar aliases textuales;
- usar datos de request;
- permitir modificación sin `manage_options` en una herramienta futura.

## 42. Filtros externos

El adapter no llama `apply_filters()` ni registra filtros.

`get_option()` puede aplicar filtros propios de WordPress. A2.1 no intenta
evitarlos mediante SQL. Cualquier valor resultante se valida estrictamente.

Un filtro que lance produce `SOURCE_UNAVAILABLE`; uno que devuelva un tipo o
valor inválido produce `INVALID_VALUE`.

## 43. Observabilidad

A2.1 no emite:

- logs;
- métricas;
- eventos;
- hooks;
- trazas;
- diagnósticos laterales.

Su única señal de fallo es la excepción tipada.

## 44. Mutabilidad

A2.1 sólo lee.

No:

- crea defaults persistidos;
- escribe options;
- repara valores;
- normaliza almacenamiento;
- expone setters;
- administra configuración.

La mutabilidad operacional pertenece a tooling y rollout futuros.

## 45. Relación exacta con A2

La secuencia futura será:

```text
caller
→ DurableRetryDeterministicActivationPolicy::allowsInitialTransfer(identity)
→ DurableRetryActivationConfigurationSourceInterface::snapshot()
→ DurableRetryProductionActivationConfigurationSource
→ DurableRetryActivationConfigurationValueReaderInterface::read()
→ WordPressOptionDurableRetryActivationConfigurationValueReader
→ DurableRetryActivationConfiguration
→ policy cohort/bucket decision
```

La fuente no recibe `DurableRetryAuthorityIdentity`.

## 46. Prohibiciones de responsabilidad

La fuente no:

- calcula hash;
- calcula bucket;
- compara bucket con porcentaje;
- decide autoridad;
- solicita transferencia;
- persiste generation 1;
- inspecciona marcadores;
- selecciona legacy;
- programa acciones;
- conoce Action Scheduler.

## 47. Composición futura

A2.1 no modifica composición.

A10 deberá registrar, en una tarea separada:

```text
DurableRetryActivationConfigurationValueReaderInterface
→ WordPressOptionDurableRetryActivationConfigurationValueReader

DurableRetryActivationConfigurationSourceInterface
→ DurableRetryProductionActivationConfigurationSource

DurableRetryActivationPolicyInterface
→ DurableRetryDeterministicActivationPolicy
```

A10 debe certificar identidad y ciclo de vida del grafo sin cambiar la semántica
de A2.1.

## 48. Harness funcional

Ruta exacta:

```text
tests/manual/durable-retry-activation-configuration-source-test.php
```

Debe certificar:

- contrato del source;
- reader double;
- ausencia;
- 0, 1, 99 y 100;
- ints y strings canónicos;
- todos los tipos y formas inválidos;
- rangos;
- excepción y catálogo;
- causa preservada;
- snapshot nuevo por llamada;
- una lectura por llamada;
- cambio visible entre llamadas;
- sin cache.

## 49. Harness WordPress

Ruta exacta:

```text
tests/manual/durable-retry-activation-configuration-wordpress-test.php
```

Debe certificar:

- constante de option exacta;
- una llamada a `get_option()`;
- clave exacta;
- sentinel y ausencia;
- `null` presente;
- preservación exacta de tipo y valor;
- comportamiento CLI con double;
- API WordPress ausente en subproceso aislado;
- error propagado por filtros/double;
- cero escritura de options.

## 50. Harness de infraestructura

Ruta exacta:

```text
tests/manual/durable-retry-activation-configuration-source-infrastructure-test.php
```

Debe certificar:

- cinco archivos PHP nuevos;
- tres harnesses nuevos;
- FQCN y rutas;
- `final`, interfaces y firmas;
- APIs públicas cerradas;
- propiedades `readonly`;
- allowlist exacta;
- una sola llamada textual a `read()` y `get_option()`;
- ausencia de `Config`, SQL, filesystem, red y reloj;
- ausencia de hooks, scheduling, batch, transferencia, rollout y wiring;
- ningún cambio a A2;
- ningún archivo productivo existente modificado.

## 51. Harness histórico

Debe modificarse exclusivamente:

```text
tests/manual/durable-retry-schedule-infrastructure-test.php
```

Razón: el harness mantiene igualdad exacta entre filesystem y allowlist de
objetos puros de `Domain/DurableRetry`.

Debe:

- conservar los 28 archivos previos;
- añadir únicamente
  `DurableRetryActivationConfigurationValue.php`;
- esperar exactamente 29 archivos;
- cambiar el mensaje de
  `twenty-eight focused pure domain contracts` a
  `twenty-nine focused pure domain contracts`;
- conservar todas las comprobaciones de pureza existentes.

Los otros cuatro archivos productivos nuevos no pertenecen a la carpeta
auditada por ese harness.

## 52. Vectores normativos

En la tabla:

- `CONFIG(0)` significa un snapshot reconciliation, versión v1, porcentaje 0;
- `CONFIG(n)` usa el porcentaje indicado;
- `INVALID_VALUE` y `SOURCE_UNAVAILABLE` son códigos de la excepción A2.1;
- lecturas significa llamadas esperadas a `reader->read()`;
- para el adapter disponible hay además una llamada a `get_option()`.

| Entrada cruda | Clasificación | Resultado | Código/mensaje | Lecturas |
|---|---|---|---|---:|
| option ausente | Ausente | `CONFIG(0)` | — | 1 |
| `0` int | Válido | `CONFIG(0)` | — | 1 |
| `1` int | Válido | `CONFIG(1)` | — | 1 |
| `99` int | Válido | `CONFIG(99)` | — | 1 |
| `100` int | Válido | `CONFIG(100)` | — | 1 |
| `-1` int | Inválido/rango | Excepción | `INVALID_VALUE` / `Invalid durable retry activation configuration value.` | 1 |
| `101` int | Inválido/rango | Excepción | `INVALID_VALUE` / `Invalid durable retry activation configuration value.` | 1 |
| `"0"` | Válido/canónico | `CONFIG(0)` | — | 1 |
| `"01"` | Inválido/forma | Excepción | `INVALID_VALUE` / `Invalid durable retry activation configuration value.` | 1 |
| `"100"` | Válido/canónico | `CONFIG(100)` | — | 1 |
| `" 50 "` | Inválido/espacios | Excepción | `INVALID_VALUE` / `Invalid durable retry activation configuration value.` | 1 |
| `"50.0"` | Inválido/decimal | Excepción | `INVALID_VALUE` / `Invalid durable retry activation configuration value.` | 1 |
| `"5e1"` | Inválido/científico | Excepción | `INVALID_VALUE` / `Invalid durable retry activation configuration value.` | 1 |
| `true` | Inválido/tipo | Excepción | `INVALID_VALUE` / `Invalid durable retry activation configuration value.` | 1 |
| `false` | Inválido/tipo | Excepción | `INVALID_VALUE` / `Invalid durable retry activation configuration value.` | 1 |
| `null` presente | Inválido/tipo | Excepción | `INVALID_VALUE` / `Invalid durable retry activation configuration value.` | 1 |
| `""` | Inválido/vacío | Excepción | `INVALID_VALUE` / `Invalid durable retry activation configuration value.` | 1 |
| `[]` | Inválido/tipo | Excepción | `INVALID_VALUE` / `Invalid durable retry activation configuration value.` | 1 |
| `new stdClass()` | Inválido/tipo | Excepción | `INVALID_VALUE` / `Invalid durable retry activation configuration value.` | 1 |
| reader lanza | Indisponible | Excepción con causa | `SOURCE_UNAVAILABLE` / `Durable retry activation configuration source is unavailable.` | 1 |

## 53. Vectores adicionales obligatorios

También deben probarse:

| Entrada | Resultado |
|---|---|
| `"1"` | `CONFIG(1)` |
| `"99"` | `CONFIG(99)` |
| `1.0` | `INVALID_VALUE` |
| `"+1"` | `INVALID_VALUE` |
| `"-0"` | `INVALID_VALUE` |
| `"00"` | `INVALID_VALUE` |
| `"100\n"` | `INVALID_VALUE` |
| resource | `INVALID_VALUE` |
| `get_option` inexistente | `SOURCE_UNAVAILABLE`, causa preservada |

## 54. Compatibilidad con A2

Los harnesses deben pasar el snapshot producido a
`DurableRetryDeterministicActivationPolicy` con un identity reconciliation y
certificar:

- 0 rechaza;
- 100 permite;
- el source A2 sigue leyéndose una vez por decisión;
- A2.1 no altera cohorting ni vectores SHA-256.

No se duplican cálculos de bucket en A2.1.

## 55. Allowlist futura exacta

Archivos productivos nuevos: 5.

```text
app/Modules/Orders/Contracts/DurableRetryActivationConfigurationValueReaderInterface.php
app/Modules/Orders/Domain/DurableRetry/DurableRetryActivationConfigurationValue.php
app/Modules/Orders/Infrastructure/DurableRetry/DurableRetryProductionActivationConfigurationSource.php
app/Modules/Orders/Infrastructure/DurableRetry/WordPressOptionDurableRetryActivationConfigurationValueReader.php
app/Modules/Orders/Exceptions/DurableRetryActivationConfigurationSourceException.php
```

Harnesses nuevos: 3.

```text
tests/manual/durable-retry-activation-configuration-source-test.php
tests/manual/durable-retry-activation-configuration-wordpress-test.php
tests/manual/durable-retry-activation-configuration-source-infrastructure-test.php
```

Harness modificado: 1.

```text
tests/manual/durable-retry-schedule-infrastructure-test.php
```

Archivos productivos existentes modificados: 0.

Documentación modificada durante implementación: 0.

Total:

```text
9 archivos
```

Composición:

```text
5 PHP productivos nuevos
3 harnesses nuevos
1 harness histórico modificado
```

## 56. Invariantes positivas

1. Existe una sola option canónica.
2. Stage y algoritmo son constantes cerradas.
3. Cada snapshot realiza una lectura del reader.
4. Cada lectura WordPress realiza un `get_option()`.
5. Ausencia legítima produce default apagado.
6. Invalidez lanza.
7. Indisponibilidad lanza con causa.
8. El snapshot es nuevo e inmutable.
9. Cambios pueden verse entre llamadas.
10. La fuente es read-only.
11. A2 mantiene la decisión de cohorte.

## 57. Invariantes negativas

Se prohíbe:

- más de una autoridad física;
- fallback a `Config`, constante o entorno;
- fallback silencioso de invalidez a cero;
- normalización no documentada;
- ceros iniciales;
- coerción de bool o float;
- más de una lectura por snapshot;
- cache propia;
- dependencia de identidad;
- cohorting;
- reloj o aleatoriedad;
- SQL;
- filesystem o red;
- hooks;
- scheduling;
- wiring;
- escritura;
- batch;
- transferencia;
- rollout;
- logs o métricas.

## 58. Fallo cerrado

Ante cualquier estado no clasificable como ausencia legítima o valor válido:

- no se devuelve bool;
- no se devuelve `disabled()`;
- no se consulta otra fuente;
- no se escribe reparación;
- se lanza la excepción A2.1 correspondiente.

El caller futuro no debe convertir `SOURCE_UNAVAILABLE` o `INVALID_VALUE` en
`true`.

## 59. Condiciones de implementación

Antes de implementar:

- esta especificación debe estar versionada;
- A2 debe permanecer intacto;
- staging debe estar vacío;
- la base debe ser declarada;
- la allowlist de nueve archivos debe estar autorizada;
- no debe existir wiring productivo de A2;
- los documentos protegidos y `artifacts/` deben estar intactos.

## 60. Condiciones de aceptación

La implementación futura se acepta sólo si:

- lint 9/9;
- tres harnesses nuevos verdes;
- guardia histórica verde con 29 objetos de dominio;
- harnesses A2 verdes;
- suite durable retry aislada verde;
- vectores normativos completos;
- una lectura por snapshot;
- cero cache propia;
- `git diff --check` limpio;
- `git diff --no-index --check` limpio para ocho archivos nuevos;
- exactamente ocho archivos nuevos y uno modificado;
- cero wiring, SQL, hooks, scheduling, escritura o rollout.

## 61. Rollback del microhito

Antes de wiring, el rollback consiste sólo en retirar los ocho archivos nuevos y
restaurar la entrada histórica.

No hay estado productivo que migrar porque A2.1:

- no está registrado;
- no escribe option;
- no transfiere trabajo;
- no altera autoridad.

## 62. Veredicto

**IMPLEMENTABLE SIN DECISIONES PENDIENTES**

Quedan definidos:

- identificador A2.1;
- FQCN y rutas;
- constructor y dependencias;
- fuente física;
- clave literal;
- precedencia;
- tipos y normalización;
- ausencia, default e invalidez;
- indisponibilidad;
- excepción y mensajes;
- lecturas, atomicidad y cache;
- WordPress, multisite y CLI;
- seguridad y observabilidad;
- harnesses;
- allowlist exacta.

## 63. Validación documental final

La tarea de especificación debe terminar con:

- este documento como único archivo nuevo;
- cero modificaciones tracked;
- staging vacío;
- `git diff --check` limpio;
- `git diff --no-index --check` limpio;
- trece documentos protegidos previos intactos;
- 504 archivos en `artifacts/`;
- especificación A2 intacta;
- auditoría A2 intacta;
- auditoría de fuente intacta;
- cero implementación;
- cero harnesses modificados;
- cero commit;
- cero push.
