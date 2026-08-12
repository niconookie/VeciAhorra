# Corrección normativa A5 — productor inicial de autoridad durable

## 1. Estado del documento

Este documento corrige exclusivamente la definición normativa de A5. Su alcance es documental y no activa código productivo, wiring, callbacks, hooks, workers ni scheduling.

La autoridad normativa corregida define A5 como **productor inicial de autoridad durable**. A5 compone A3, A2 y A4 para decidir, por una identidad A1 cerrada, si la invocación debe conservar autoridad legacy, reconocer autoridad durable o intentar la transferencia inicial.

## 2. Veredicto normativo

A5 es implementable bajo el contrato cerrado en este documento y bajo la allowlist de cinco archivos indicada en la sección 30.

Este veredicto no autoriza todavía la implementación. La implementación queda condicionada a recertificar y versionar selectivamente esta corrección en una tarea posterior y separada, sin modificar contratos A1, A2, A2.1, A3 o A4.

## 3. Corrección de la definición histórica

Queda invalidada para A5 toda definición histórica que lo describa como productor de schedules, coordinador de ejecución, adaptador del scheduler legacy, resolvedor de schedules transferidos o punto de wiring productivo.

Las referencias históricas a `DurableRetryReconciliationInitialScheduleProducer`, a un coordinador durable, a un scheduler legacy o a un resolvedor de schedules transferidos no constituyen contrato implementable de A5.

Esta corrección prevalece sobre esas referencias únicamente respecto del alcance y contrato de A5. No renumera ni redefine microhitos posteriores.

El identificador de un eventual productor de scheduling queda sin asignar por esta corrección. Las responsabilidades ya reservadas a microhitos posteriores conservan su numeración existente.

## 4. Responsabilidad única

A5 recibe un `DurableRetryInitialTransferRequest` ya construido y:

1. consulta A3 exactamente una vez;
2. consulta A2 únicamente si A3 devolvió `legacy`;
3. invoca A4 únicamente si A3 devolvió `legacy` y A2 habilitó la transferencia;
4. traduce los resultados existentes a un resultado A5 cerrado;
5. declara expresamente si la producción legacy está permitida.

A5 no crea schedules directamente y no ejecuta trabajo reconciliatorio.

## 5. Límites con los microhitos existentes

A1 conserva la autoridad sobre identidad, solicitud inicial y catálogo contractual de transferencia.

A2 conserva la decisión determinista de elegibilidad.

A2.1 conserva la lectura productiva de configuración y permanece encapsulado detrás de A2.

A3 conserva la clasificación read-only de autoridad legacy o durable.

A4 conserva la transferencia transaccional y la materialización de `generation = 1`.

A5 solo orquesta esos contratos sin duplicarlos.

## 6. Orden normativo de decisión

El orden es obligatorio:

`validar entrada → A3 → [solo legacy: A2] → [solo elegible: A4] → resultado A5`

No se permite consultar A2 antes de A3. No se permite invocar A4 antes de una clasificación `legacy` y una decisión A2 positiva.

## 7. Entrada contractual

A5 recibe directamente:

```php
DurableRetryInitialTransferRequest $request
```

No se autoriza un DTO de solicitud adicional. La solicitud existente contiene la identidad A1 cerrada, `completion_id`, `generation = 1`, `attempt_number = 0` y el instante UTC estable requerido por A4.

La construcción de esa solicitud por un futuro caller queda fuera de A5. Dicho caller deberá derivar el instante estable desde el dato persistido de la reconciliación y no desde el reloj de cada reinvocación.

La identidad aceptada tiene obligatoriamente:

- `stage = reconciliation`;
- `subject_id = payment_reconciliations.id`;
- `completion_id = subject_id`;
- `generation = 1`, derivada internamente por el request y no recibida como parámetro libre;
- `attempt_number = 0`, derivado internamente;
- cohorte A2 derivada exclusivamente de `(stage, subject_id)`.

A5 no acepta otro stage ni información adicional del resultado persistido. Debe comprobar estos invariantes antes de invocar dependencias. El propio factory `DurableRetryInitialTransferRequest::reconciliation()` impide construir normalmente una solicitud incompatible.

## 8. Interfaz propuesta

Ruta:

`app/Modules/Orders/Contracts/DurableRetryInitialAuthorityProducerInterface.php`

FQCN:

`VeciAhorra\Modules\Orders\Contracts\DurableRetryInitialAuthorityProducerInterface`

Firma única:

```php
public function produceReconciliation(
    DurableRetryInitialTransferRequest $request
): DurableRetryInitialAuthorityProductionResult;
```

## 9. Implementación propuesta

Ruta:

`app/Modules/Orders/Services/DurableRetryInitialAuthorityProducer.php`

FQCN:

`VeciAhorra\Modules\Orders\Services\DurableRetryInitialAuthorityProducer`

La clase será `final` y no contendrá resolución de dependencias desde contenedores, globals o factories.

## 10. Constructor cerrado

El constructor tendrá exactamente tres dependencias:

```php
public function __construct(
    private readonly DurableRetryLegacyExclusionInterface $authority,
    private readonly DurableRetryActivationPolicyInterface $activation,
    private readonly DurableRetryInitialTransferAuthorityInterface $transfer
);
```

No se admiten dependencias de A2.1, `$wpdb`, clocks, scheduler, coordinador, repositorio concreto, logger, métricas, hooks ni workers.

## 11. Aislamiento de A2.1

A5 consume `DurableRetryActivationPolicyInterface`, no la fuente de configuración A2.1.

La única lectura de snapshot u opción permitida sucede internamente durante la única evaluación A2. A5 no conoce claves de opciones, porcentajes, algoritmos ni cohortes.

## 12. Resultado A5

Ruta:

`app/Modules/Orders/Domain/DurableRetry/DurableRetryInitialAuthorityProductionResult.php`

FQCN:

`VeciAhorra\Modules\Orders\Domain\DurableRetry\DurableRetryInitialAuthorityProductionResult`

Será un value object `final`, inmutable, con constructor privado y factories nombradas. Debe preservar, cuando corresponda, el resultado A3 y el resultado A4 originales.

## 13. API observable del resultado

El resultado expondrá:

```php
public function state(): string;
public function reason(): string;
public function authorityResult(): ?DurableRetryLegacyAuthorityResult;
public function transferResult(): ?DurableRetryInitialTransferResult;
public function permitsLegacyProduction(): bool;
public function durableAuthorityConfirmed(): bool;
public function requiresRecovery(): bool;
```

No expondrá mutadores ni permitirá combinaciones de estado y razón no enumeradas.

## 14. Catálogo exacto de estados

El catálogo A5 contiene exactamente estos doce estados:

1. `legacy_allowed`
2. `legacy_in_flight`
3. `durable_existing`
4. `durable_created`
5. `durable_converged`
6. `functionally_ineligible`
7. `authority_indeterminate`
8. `durable_inconsistency`
9. `configuration_invalid`
10. `persistence_error`
11. `outcome_uncertain`
12. `operational_failure`

No se permiten alias, `absent`, estados genéricos de éxito ni estados de scheduling.

Los nombres de constantes PHP serán respectivamente `LEGACY_ALLOWED`, `LEGACY_IN_FLIGHT`, `DURABLE_EXISTING`, `DURABLE_CREATED`, `DURABLE_CONVERGED`, `FUNCTIONALLY_INELIGIBLE`, `AUTHORITY_INDETERMINATE`, `DURABLE_INCONSISTENCY`, `CONFIGURATION_INVALID`, `PERSISTENCE_ERROR`, `OUTCOME_UNCERTAIN` y `OPERATIONAL_FAILURE`; sus valores serializados son los doce valores minúsculos anteriores.

## 15. Semántica de legacy permitido

Solo `legacy_allowed` devuelve `true` en `permitsLegacyProduction()`.

Todos los demás estados devuelven `false`. En particular, una incertidumbre, inconsistencia, configuración inválida o falla operacional nunca degrada silenciosamente a legacy.

## 16. Semántica de autoridad durable confirmada

`durableAuthorityConfirmed()` devuelve `true` exclusivamente para:

- `durable_existing`;
- `durable_created`;
- `durable_converged`.

Esto confirma autoridad, no scheduling ni ejecución de un intento.

## 17. Semántica de recuperación

`requiresRecovery()` devuelve `true` exclusivamente para:

- `legacy_in_flight`;
- `authority_indeterminate`;
- `durable_inconsistency`;
- `persistence_error`;
- `outcome_uncertain`;
- `operational_failure`.

Devuelve `false` para `legacy_allowed`, los tres estados durables, `functionally_ineligible` y `configuration_invalid`.

La marca de recuperación no autoriza por sí misma reintentos, sleeps, scheduling ni hooks.

## 18. Razones normativas

A5 preserva sin traducción los reason codes contractuales entregados por A3 y A4.

Para la decisión negativa normal de A2, A5 usa exactamente:

`activation_policy_rejected`

Para autoridad durable ya demostrada por A3, A5 usa exactamente:

`durable_authority_already_exists`

Las excepciones de configuración conservan exactamente uno de estos códigos:

- `invalid_activation_configuration_value`;
- `invalid_percentage`;
- `unsupported_algorithm_version`;
- `invalid_configuration_snapshot`.

Una indisponibilidad declarada de la fuente conserva:

`activation_configuration_source_unavailable`

Una excepción inesperada de dependencia usa:

`dependency_failure`

No se autoriza texto de excepción como reason code.

`DurableRetryActivationPolicyException` es el único tipo de excepción de policy catalogado; sus códigos de configuración son `invalid_percentage`, `unsupported_algorithm_version` e `invalid_configuration_snapshot`. `unsupported_stage` no es una alternativa normal de configuración A5: una solicitud A5 válida ya fija `reconciliation`.

`DurableRetryActivationConfigurationSourceException` es el único tipo de excepción de fuente catalogado; `invalid_activation_configuration_value` produce configuración inválida y `activation_configuration_source_unavailable` produce falla operacional.

## 19. Excepciones y cierre seguro

Una excepción de configuración conocida se convierte en `configuration_invalid`, sin invocar A4 y sin permitir legacy.

La indisponibilidad de la fuente de configuración se convierte en `operational_failure` con su reason code contractual.

Cualquier otra excepción inesperada de A3, A2 o A4 se convierte en `operational_failure` con `dependency_failure`.

A5 no propaga una excepción operacional como permiso implícito para continuar por legacy.

## 20. Matriz normativa completa

Cada fila representa una invocación independiente. “Efectos indirectos máximos” describe el límite que A4 puede producir cuando es invocado; A5 no ejecuta SQL directamente.

| ID | Entrada o salida A3 | A2 | A4 | Llamadas y orden | Estado A5 | Autoridad final | Legacy | A4 invocado | Razón | Efectos indirectos máximos |
|---|---|---|---|---|---|---|---|---|---|---|
| A5-01 | solicitud inválida localmente | no | no | validar | `operational_failure` | no demostrada | no | no | `dependency_failure` | cero |
| A5-02 | `durable` | no | no | validar, A3 | `durable_existing` | durable | no | no | `durable_authority_already_exists` | una lectura A3 |
| A5-03 | `indeterminate` por inconsistencia | no | no | validar, A3 | `authority_indeterminate` | indeterminada | no | no | razón A3 intacta | una lectura A3 |
| A5-04 | `indeterminate` por error de lectura | no | no | validar, A3 | `authority_indeterminate` | indeterminada | no | no | razón A3 intacta | una lectura A3 |
| A5-05 | excepción inesperada A3 | no | no | validar, A3 | `operational_failure` | no demostrada | no | no | `dependency_failure` | hasta una lectura A3 |
| A5-06 | `legacy` | false | no | validar, A3, A2 | `legacy_allowed` | legacy | sí | no | `activation_policy_rejected` | una lectura A3 y una snapshot A2 |
| A5-07 | `legacy` | configuración inválida | no | validar, A3, A2 | `configuration_invalid` | legacy sin permiso | no | no | razón de configuración intacta | una lectura A3 y una snapshot A2 |
| A5-08 | `legacy` | fuente no disponible | no | validar, A3, A2 | `operational_failure` | legacy sin permiso | no | no | `activation_configuration_source_unavailable` | una lectura A3 y una snapshot A2 |
| A5-09 | `legacy` | excepción inesperada | no | validar, A3, A2 | `operational_failure` | legacy sin permiso | no | no | `dependency_failure` | una lectura A3 y una snapshot A2 |
| A5-10 | `legacy` | true | `transferred` | validar, A3, A2, A4 | `durable_created` | durable | no | sí | razón A4 intacta | una transacción, un lock, lecturas A4, un INSERT, un commit |
| A5-11 | `legacy` | true | `already_transferred` | validar, A3, A2, A4 | `durable_converged` | durable | no | sí | razón A4 intacta | una transacción, un lock, lecturas A4, cero INSERT, un commit |
| A5-12 | `legacy` | true | `legacy_in_flight` | validar, A3, A2, A4 | `legacy_in_flight` | legacy bloqueada | no | sí | razón A4 intacta | una transacción, un lock, lecturas A4, cero INSERT, cierre A4 |
| A5-13 | `legacy` | true | `functionally_ineligible` | validar, A3, A2, A4 | `functionally_ineligible` | legacy sin permiso | no | sí | razón A4 intacta | una transacción, un lock, lecturas A4, cero INSERT, cierre A4 |
| A5-14 | `legacy` | true | `durable_inconsistency` | validar, A3, A2, A4 | `durable_inconsistency` | indeterminada | no | sí | razón A4 intacta | una transacción, un lock, lecturas A4, cero INSERT, rollback máximo uno |
| A5-15 | `legacy` | true | `persistence_error` | validar, A3, A2, A4 | `persistence_error` | no demostrada | no | sí | razón A4 intacta | una transacción, un lock, un INSERT máximo, rollback máximo uno |
| A5-16 | `legacy` | true | `outcome_uncertain` | validar, A3, A2, A4 | `outcome_uncertain` | indeterminada | no | sí | razón A4 intacta | una transacción, un lock, un INSERT máximo, commit máximo uno |
| A5-17 | `legacy` | true | excepción inesperada A4 | validar, A3, A2, A4 | `operational_failure` | no demostrada | no | sí | `dependency_failure` | hasta el presupuesto completo de una invocación A4 |

Los escenarios de reinvocación, concurrencia y cambio de configuración se fijan además así:

| Escenario compuesto | Llamadas y orden | Resultado permitido | Autoridad final | Legacy | A4 | Razón | Máximo indirecto |
|---|---|---|---|---|---|---|---|
| reinvocación tras creación visible | validar, A3 | `durable_existing` | durable | no | no | `durable_authority_already_exists` | una lectura A3 |
| dos A5 concurrentes elegibles | cada una: validar, A3, A2, A4 | una creación y convergencia compatible, o posterior `durable_existing` | una única generation 1 durable | no | máximo una por invocación | razones A4 intactas | presupuesto A4 por invocación; unicidad global A4 |
| duplicate key compatible | validar, A3, A2, A4 | `durable_converged` | durable | no | sí | razón A4 intacta | máximo un intento de INSERT A4 |
| duplicate key incompatible | validar, A3, A2, A4 | `durable_inconsistency` | indeterminada | no | sí | razón A4 intacta | máximo un intento de INSERT A4 |
| proceso falla después de A4 creado | la invocación terminó durable; la siguiente empieza por A3 | `durable_created`, luego `durable_existing` | durable | no | una en la primera, cero en la siguiente | razones propias de cada camino | no duplica generation 1 |
| commit incierto con fila compatible visible | validar, A3, A2, A4 | resultado de convergencia que A4 determine | durable demostrada | no | sí | razón A4 intacta | presupuesto A4 único |
| commit incierto sin evidencia suficiente | validar, A3, A2, A4 | `outcome_uncertain` | indeterminada | no | sí | razón A4 intacta | presupuesto A4 único |
| porcentaje cambia entre invocaciones | una snapshot en cada invocación | decisión correspondiente a cada snapshot, sujeta primero a A3 | durable prevalece si ya existe | solo con `legacy_allowed` | máximo una por invocación | razón del camino | nunca se relee dentro de la misma invocación |

## 21. Mapeo exhaustivo de A4

Los siete resultados A4 se mapean sin omisiones:

| Estado A4 | Estado A5 |
|---|---|
| `transferred` | `durable_created` |
| `already_transferred` | `durable_converged` |
| `legacy_in_flight` | `legacy_in_flight` |
| `functionally_ineligible` | `functionally_ineligible` |
| `durable_inconsistency` | `durable_inconsistency` |
| `persistence_error` | `persistence_error` |
| `outcome_uncertain` | `outcome_uncertain` |

A5 no renombra ni pierde la razón contenida en el resultado A4.

## 22. Equivalencia de reinvocación

Con la misma solicitud estable y el mismo estado persistido, A5 debe producir una decisión equivalente.

Después de una transferencia exitosa, una reinvocación puede observar `durable` en A3 y devolver `durable_existing`; si vuelve a entrar en A4 y este converge por concurrencia, devuelve `durable_converged`. Ambos confirman la misma autoridad durable, aunque distinguen el camino observado.

Un cambio de configuración entre invocaciones solo puede afectar una identidad que A3 todavía clasifique `legacy`. Una `generation = 1` durable visible prevalece siempre y evita reevaluar A2.

## 23. Concurrencia

A5 no implementa locks. La serialización funcional, el lease legacy, el duplicate key, la relectura posterior y el commit incierto pertenecen a A4.

A5 debe preservar exactamente la conclusión de A4: nunca convierte un conflicto o resultado incierto en `legacy_allowed`.

Un duplicate key compatible converge según A4. Uno incompatible termina en inconsistencia durable. Si un commit incierto deja una fila compatible visible, A4 determina la convergencia; si no existe evidencia suficiente, A5 conserva `outcome_uncertain`. Un fallo del proceso después de A4 no autoriza legacy en la reinvocación.

## 24. Presupuesto de invocaciones

Por invocación A5:

- validación local: una;
- A3: exactamente una invocación;
- A2: cero o una, únicamente tras `legacy`;
- A2.1: cero o una snapshot indirecta, únicamente dentro de A2;
- A4: cero o una, únicamente tras A2 positivo.

No existe segundo intento automático de ninguna dependencia.

El máximo de validaciones locales es una secuencia determinista sobre la solicitud. No hay polling ni revalidación después de A4.

## 25. Presupuesto SQL y transaccional

A5 ejecuta directamente:

- cero lecturas SQL;
- cero escrituras SQL;
- cero aperturas de transacción;
- cero locks;
- cero commits;
- cero rollbacks.

Indirectamente, A3 puede ejecutar como máximo su única lectura física. A4 puede consumir como máximo el presupuesto certificado por su contrato: una transacción, un lock funcional, sus lecturas acotadas, como máximo un `INSERT`, como máximo un commit y como máximo un rollback según el camino terminal.

## 26. Prohibiciones operacionales

A5 tiene prohibido:

- crear, actualizar o borrar schedules directamente;
- ejecutar loops de retry o sleeps;
- usar Action Scheduler;
- registrar hooks o callbacks;
- adelantar intentos;
- procesar reconciliaciones;
- emitir logs o métricas;
- consultar opciones directamente;
- resolver dependencias globalmente;
- conectar o detener el worker legacy.
- recibir un coordinator o scheduler en el constructor;
- invocar funciones `as_*`;
- cancelar acciones;
- invocar producers de stages posteriores;
- ejecutar stages o processors;
- consultar un registry de processors;
- crear o modificar una generación distinta de 1.

## 27. Frontera con producción legacy

El booleano `permitsLegacyProduction()` es una decisión contractual para un caller futuro. A5 no llama al scheduler legacy.

La modificación de `WebpayReconciliationMaterializer`, `DurableCompletionRecovery`, `DurableCompletionWorkers` o `DurableCompletionScheduler` queda expresamente fuera de A5.

El punto semántico obligatorio de una futura integración es:

```text
persistencia autoritativa de reconciliation
→ decisión A5
→ exactamente una rama externa:
   legacy o durable o cierre seguro
```

A5 se invoca únicamente después de persistir autoritativamente la reconciliación y antes de cualquier producción legacy, exactamente una vez por intento lógico del caller y con la identidad de esa misma fila persistida. Si hoy existe un efecto legacy anterior, su reubicación pertenece al microhito posterior de integración.

## 28. Frontera con producción durable

Confirmar autoridad durable no equivale a programar trabajo durable.

La coordinación, materialización de schedules posteriores, callbacks, worker wiring, recovery y canary permanecen reservados a los microhitos posteriores ya definidos para autoridades durables. Esta corrección no adelanta esas responsabilidades ni altera su numeración.

A5 no crea, busca, cancela ni programa acciones externas. Decisión de autoridad, persistencia de autoridad en A4, scheduling externo y ejecución del worker son cuatro responsabilidades distintas.

## 29. Compatibilidad y ausencia de estados históricos

A5 no devuelve `absent`, `scheduled`, `not_scheduled`, `coordinated` ni estados del scheduler legacy.

No existe fallback desde un error A2/A2.1, una clasificación A3 indeterminada o un resultado A4 incierto hacia producción legacy.

## 30. Allowlist futura exacta

Una implementación posterior de A5 podrá crear exclusivamente estos cinco archivos:

1. `app/Modules/Orders/Contracts/DurableRetryInitialAuthorityProducerInterface.php`
2. `app/Modules/Orders/Domain/DurableRetry/DurableRetryInitialAuthorityProductionResult.php`
3. `app/Modules/Orders/Services/DurableRetryInitialAuthorityProducer.php`
4. `tests/manual/durable-retry-initial-authority-producer-test.php`
5. `tests/manual/durable-retry-initial-authority-producer-infrastructure-test.php`

La implementación no podrá modificar archivos existentes.

## 31. Harness funcional requerido

El harness funcional deberá materializar como casos independientes, al menos, las diecisiete filas de la matriz normativa.

Cada caso tendrá fixtures y doubles nuevos, ejecutará exactamente una producción, verificará estado, razón, resultados A3/A4 preservados, los tres predicados y los contadores exactos de dependencias.

Guardias globales deberán comprobar cantidad de filas, identificadores únicos, cobertura de los doce estados y cobertura de los siete resultados A4.

El mismo harness incorpora integración mediante doubles/implementaciones reales controladas de A2, A3 y A4: orden exacto, snapshot único, cortocircuitos, configuración inválida, excepciones, reinvocación y concurrencia delegada. No se crean tres archivos de integración adicionales porque ampliarían sin necesidad la allowlist certificada.

## 32. Harness de infraestructura requerido

El harness de infraestructura certificará:

- rutas, namespaces, FQCN y firma exactos;
- constructor con exactamente tres interfaces;
- ausencia de `$wpdb`, opciones y A2.1;
- ausencia de SQL, transacciones y escrituras directas;
- ausencia de scheduler, coordinador, hooks, callbacks, logs y métricas;
- ausencia de wiring productivo;
- allowlist exacta de cinco archivos;
- inmutabilidad y catálogo cerrado del resultado.

## 33. Regresiones obligatorias

La certificación posterior deberá ejecutar:

- harness funcional A5;
- harness de infraestructura A5;
- harnesses A1;
- harnesses A2 y A2.1;
- harnesses A3, incluido MySQL;
- harnesses A4, incluido MySQL;
- integraciones existentes de executors;
- suite Durable Retry completa;
- `php -l` sobre los cinco archivos A5;
- guardias Git y filesystem.

No se requiere un tercer harness MySQL de A5 porque A5 carece de acceso SQL propio; la cobertura física corresponde a A3 y A4.

La ejecución debe registrar cero warnings, notices y deprecations.

## 34. Exclusiones de implementación

Quedan fuera:

- cambios de schema o migraciones;
- configuración nueva;
- interfaces o DTOs alternativos;
- repositorios nuevos;
- adapters de scheduler;
- resolvers de schedules;
- wiring o service providers;
- cambios a materializers, recovery o workers;
- hooks de activación;
- commit o push junto con la futura implementación local.

## 35. Criterios de certificabilidad

A5 será certificable solo si:

1. se crean exactamente los cinco archivos permitidos;
2. las diecisiete filas normativas están verdes;
3. A2 nunca se evalúa para `durable` o `indeterminate`;
4. A4 nunca se invoca salvo después de `legacy` y A2 positivo;
5. solo `legacy_allowed` permite producción legacy;
6. razones A3 y A4 se preservan;
7. no existen efectos directos ni wiring;
8. todas las regresiones permanecen verdes;
9. staging permanece vacío hasta una recertificación separada.

Una implementación no puede declararse A5 si recibe coordinator o scheduler; invoca `as_*`; programa o cancela acciones; llama al productor legacy; ejecuta stages o processors; contiene SQL; realiza más de una lectura de configuración; evalúa A2 antes de A3; invoca A4 cuando A3 no devolvió `legacy`; degrada incertidumbre a legacy; permite continuar ambos caminos; o modifica una generación distinta de 1.

## 36. Riesgos cerrados

Esta corrección cierra:

- la ambigüedad entre autoridad y scheduling;
- la dependencia directa indebida de A2.1;
- el riesgo de evaluar A2 para autoridades ya durables;
- el fallback inseguro hacia legacy;
- la pérdida de razones A3/A4;
- la duplicación del DTO A1/A4;
- la expansión prematura hacia workers y callbacks.

## 37. Secuencia futura de implementación

La secuencia autorizada para una tarea posterior será:

La secuencia autorizada completa es:

1. crear esta corrección normativa A5;
2. recertificarla documentalmente;
3. versionarla mediante commit selectivo;
4. auditar nuevamente la base exacta;
5. implementar A5 aislado, sin wiring;
6. certificar harnesses A5;
7. versionar la implementación A5;
8. diseñar y auditar por separado el punto de integración productivo.

Durante el paso 5, el orden interno será: resultado cerrado, interfaz, productor con tres dependencias, harness funcional matricial, harness de infraestructura, regresiones, allowlist y staging vacío. No se autoriza iniciar ese paso mientras esta corrección no haya sido recertificada y versionada.

## 38. Autoridad documental y trazabilidad

Esta corrección se fundamenta directamente en:

- `docs/durable-retry-production-activation-a5-readiness-audit.md`, secciones 1, 4–10 y 16–20, líneas 1–18, 107–149, 151–390 y 439–591: detecta la contradicción, fija el punto de integración, el contrato propuesto, la composición A3→A2→A4, el presupuesto y la allowlist de cinco archivos.
- `docs/durable-retry-production-activation-composition-spec.md`, secciones 5, 9–12, 20–24 y 37, líneas 70–88, 176–311, 500–504 y 838–867: contiene la definición histórica contradictoria del productor A5 de scheduling y su antigua allowlist; esas partes quedan corregidas para A5.
- `docs/durable-retry-production-activation-design.md`, secciones 1–7, líneas 34–60, 80–125 y 178–232: establece reconciliation como primera frontera, `generation = 1` como autoridad durable permanente y la exclusión mutua con legacy.
- `docs/durable-retry-production-wiring-design.md`, secciones de construcción, registro y activación citadas por el diseño en líneas 382–406 y 470–475: mantiene wiring y activación fuera del componente aislado.
- `docs/durable-retry-production-activation-a1-contracts-spec.md`, junto con `DurableRetryAuthorityIdentity.php:10–45`, `DurableRetryInitialTransferRequest.php:10–80` y `DurableRetryGenerationIdentity.php:10–59`: cierra identidad, request, stage, completion, generación, intento e instante.
- `docs/durable-retry-production-activation-a2-flag-policy-spec.md`, `DurableRetryActivationPolicyInterface.php:9–14` y `DurableRetryDeterministicActivationPolicy.php:19–23`: fija la decisión A2 y demuestra una única llamada a `snapshot()`.
- `docs/durable-retry-production-activation-configuration-source-spec.md`: fija A2.1 y sus errores de fuente sin convertirlo en dependencia directa de A5.
- `docs/durable-retry-production-activation-a3-normative-correction.md`, `DurableRetryLegacyExclusionInterface.php:12–20`, `DurableRetryLegacyAuthorityResult.php:9–11` y `DurableRetryIndeterminateReason.php:11–17`: fija clasificación y razones A3.
- `docs/durable-retry-production-activation-a4-equivalence-normative-correction.md`, `DurableRetryInitialTransferAuthorityInterface.php:10–15`, `DurableRetryInitialTransferResult.php:11–17` y `DurableRetryInitialTransferReason.php:11–19`: fija transferencia, convergencia, incertidumbre y razones A4.
- `DurableRetryInitialTransferAuthority.php` y `DurableRetryInitialTransferRepository.php`: conservan en A4 la atomicidad, locks, duplicate key, relectura e idempotencia; A5 no las reimplementa.
- `WebpayReconciliationMaterializer.php:19,29–125,129–212`, `DurableCompletionScheduler.php:7–35`, `DurableCompletionRecovery.php:11–20` y `DurableCompletionWorkers.php:26–75`: demuestran el flujo legacy actual que una integración posterior deberá reordenar.
- los harnesses manuales A1–A4 y las integraciones de executors existentes: constituyen la regresión obligatoria, no archivos a modificar en esta corrección.

Cuando una referencia histórica use “A5” para scheduling, debe leerse como responsabilidad todavía no asignada por esta corrección, nunca como autorización para incorporarla al productor de autoridad aquí definido.

## 39. Alcance documental de esta intervención

El único artefacto autorizado es este documento. No se modifica la especificación contradictoria: su definición A5 queda normativamente sustituida por precedencia expresa. Tampoco se modifican A1–A4, código, pruebas, índices, schema, migraciones, versión, artifacts ni wiring.

## 40. Resultado, terminalidad y reinvocación

| Estado A5 | Causa subyacente | Terminal para esta invocación | Reinvocación |
|---|---|---|---|
| `legacy_allowed` | A3 legacy + A2 false | sí; autoriza solo rama legacy externa | permitida como nuevo intento lógico antes de producir, con snapshot nuevo |
| `legacy_in_flight` | A4 homónimo | sí; cierre seguro | permitida tras resolver el lease |
| `durable_existing` | A3 durable | sí; bloquea legacy | segura e idempotente |
| `durable_created` | A4 transferred | sí; bloquea legacy | segura; A3 deberá reconocer durable |
| `durable_converged` | A4 already transferred | sí; bloquea legacy | segura e idempotente |
| `functionally_ineligible` | A4 homónimo | sí; ninguna rama | solo tras cambio funcional autoritativo |
| `authority_indeterminate` | A3 indeterminate | sí; ninguna rama | solo como recuperación explícita |
| `durable_inconsistency` | A4 homónimo | sí; ninguna rama | solo tras remediación |
| `configuration_invalid` | excepción catalogada A2/A2.1 | sí; ninguna rama | solo tras corregir configuración |
| `persistence_error` | A4 homónimo | sí; ninguna rama | solo bajo política externa de recuperación |
| `outcome_uncertain` | A4 homónimo | sí; ninguna rama | solo mediante recuperación que vuelva a demostrar autoridad |
| `operational_failure` | fuente no disponible o excepción inesperada | sí; ninguna rama | permitida como recuperación externa; nunca automática dentro de A5 |

## 41. Declaración final

La definición corregida permite implementar A5 como composición determinista y fail-closed de A3, A2 y A4, sin asumir responsabilidades de producción de schedules ni de integración productiva.

A5 IMPLEMENTABLE TRAS CORRECCIÓN NORMATIVA
