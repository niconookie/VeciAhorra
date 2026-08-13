# Corrección normativa A11 EA6 del control-plane de barreras intermedias

Estado: contrato normativo cerrado. Fecha: 2026-08-05.

## 1. Solución única

El único control-plane autorizado es framing intermedio sobre stdout del proceso participante con crash plan activo.

Ese proceso emite exactamente un objeto `barrier_arrival`, LF y flush, queda bloqueado en el punto productivo y no emite `phase_result`. El coordinator valida la llegada y termina externamente el proceso.

Quedan rechazados archivos `entered/release`, pipes adicionales, sockets de control, variables de entorno, argumentos de control, señales originadas por el child y terminación autónoma como resultado normal.

## 2. Sustitución limitada

Esta corrección sustituye, exclusivamente para los cinco participantes de crash enumerados en §3:

1. la regla de Action Capture Transport que exige un único `phase_result` final por stdout;
2. la regla que prohíbe framing intermedio en stdout;
3. `enteredPath`, escritura con `flock()` y `awaitTermination` de la corrección complementaria.

La nueva regla permite un único `barrier_arrival` en lugar de `phase_result`. No permite ambos objetos. El EOF ocurre después del kill.

Permanecen prohibidos stdout intermedio para participantes sin crash plan, datos productivos en el control-plane, múltiples llegadas, logging, archivos, environment, argv, pipes extra, sockets laterales y shared memory.

## 3. Cinco ventanas cerradas

| Window ID | Case | Invocation | Phase | Operation/entrypoint | Participant | Punto exacto | Antes | Después prohibido | Estado tras kill |
|---|---|---|---|---|---|---|---|---|---|
| `CRASH_AFTER_EXTERNAL_ACTION_CREATED` | `A11-CR-01` | `a11_000000000011_fd` | `first_delivery` | `execute_phase` | `a11p_A11-CR-01_first_delivery_01`, `external_scheduler` | después de `inner->schedule()` con action ID positivo | action externa creada, fila dispatching sin asociación | retornar el action ID al coordinator productivo | action pendiente, asociación local ausente |
| `CRASH_AFTER_LOCAL_CLAIM` | `A11-CR-02` | `a11_000000000013_fd` | `first_delivery` | `execute_phase` | `a11p_A11-CR-02_first_delivery_01`, `stage_processor` | entrada a `process()` tras claim persistido | fila claimed, intento cero | invocar processor interno | claim persiste, intento cero |
| `CRASH_AFTER_FUNCTIONAL_ATTEMPT` | `A11-CR-03` | `a11_000000000015_fd` | `first_delivery` | `execute_phase` | `a11p_A11-CR-03_first_delivery_01`, `reconciliation_attempt` | después de `inner->process()` | evidencia funcional única | retornar al stage processor | evidencia única, fila claimed |
| `CRASH_AFTER_RESULT_PERSISTED` | `A11-CR-04` | `a11_000000000017_fd` | `first_delivery` | `execute_phase` | `a11p_A11-CR-04_first_delivery_01`, `schedule_repository` | después de transition APPLIED terminal | resultado terminal persistido | retornar al executor | terminal persiste |
| `CRASH_BEFORE_CALLBACK_RETURN` | `A11-CR-05` | `a11_000000000019_fd` | `first_delivery` | `execute_phase` | `a11p_A11-CR-05_first_delivery_01`, `executor` | después del retorno terminal del executor | ejecución terminal completa | retornar al callback | terminal persiste, response ausente |

Cada ventana tiene exactamente un participante, una llegada, un kill y cero releases.

## 4. Mensaje de llegada

Schema: `veciahorra-a11-barrier-control/v1`.

Key set exacto:

```json
{"schema":"veciahorra-a11-barrier-control/v1","kind":"barrier_arrival","ownership_token":"a11_20260803010101_1_0123456789abcdef","case_id":"A11-CR-01","phase":"first_delivery","participant_id":"a11p_A11-CR-01_first_delivery_01","participant_index":1,"window_id":"CRASH_AFTER_EXTERNAL_ACTION_CREATED","pid":1234,"binding_challenge":"0123456789abcdef0123456789abcdef","arrival_ordinal":1}
```

Orden lógico: `schema`, `kind`, `ownership_token`, `case_id`, `phase`, `participant_id`, `participant_index`, `window_id`, `pid`, `binding_challenge`, `arrival_ordinal`. JSON real se canonicaliza por bytes de clave.

Tipos:

- los dos primeros campos son literales;
- ownership usa la gramática execution EA5;
- case, phase, participant y window pertenecen a §3;
- participant index es integer `1`;
- PID es integer positivo igual a `getmypid()`;
- challenge son 32 hex lowercase;
- arrival ordinal es integer `1`.

No contiene invocation ID, entrypoint ID, plan, timestamp, captura, action, proposal, result, snapshot, hash productivo o extensiones. Máximo 1024 bytes incluyendo LF, UTF-8 sin BOM, profundidad máxima 2.

## 5. Binding y autenticación

El coordinator genera `binding_challenge=bin2hex(random_bytes(16))` después de congelar invocation y antes del spawn. Lo incluye exclusivamente en `phase_request.barrier_control` junto con participant ID, index y window ID.

Shape exacto de esa proyección:

```json
{"binding_challenge":"0123456789abcdef0123456789abcdef","participant_id":"a11p_A11-CR-01_first_delivery_01","participant_index":1,"window_id":"CRASH_AFTER_EXTERNAL_ACTION_CREATED"}
```

Para participantes sin barrera, `barrier_control` es exactamente `null`. Esta clave queda añadida al key set futuro de `phase_request`; no aparece en otros envelopes.

El coordinator conserva localmente invocation ID, PID esperado y challenge. Compara strings con `hash_equals()`, integers estrictamente y los restantes campos byte a byte. El process resource debe ser el mismo registrado para el PID.

Challenge repetido, segundo mensaje, otra invocation, PID, participant o window producen rechazo. El challenge se consume al aceptar la primera llegada y nunca se reutiliza.

## 6. Dirección y ownership

El canal es stdout estándar, descriptor 1, unidireccional child→coordinator. `proc_open` crea el pipe de stdout junto con stdin y stderr. No se crea descriptor adicional.

El coordinator posee el extremo lector; el child posee el escritor heredado. El coordinator cierra copias no usadas inmediatamente tras spawn. El child no abre handles, archivos o sockets.

Stub y participantes sin crash plan no usan este control-plane. El coordinator es el único owner del lifecycle, binding, deadline y kill.

## 7. Framing

El participante escribe exactamente:

```text
canonical_json(barrier_arrival) + "\n"
```

Una llamada completa a `fwrite(STDOUT, $json . "\n")` debe retornar la longitud total; luego `fflush(STDOUT)` debe retornar true. No escribe un segundo byte después del LF.

El coordinator lee de forma no bloqueante hasta el primer LF, con máximo 1024 bytes. Valida que exista exactamente un JSON. No espera EOF antes de validar porque el proceso debe permanecer vivo.

CRLF, LF ausente, prefix/suffix, segunda línea, bytes después del LF disponibles antes del kill, JSON parcial o mensaje mayor al límite son frame inválido.

Después del kill, el coordinator drena stdout: debe observar EOF sin bytes adicionales.

## 8. Bloqueo interno

Después del flush válido, el decorator ejecuta:

```php
while (true) {
    usleep(100000);
}
```

No retorna al caller, no ejecuta operación posterior, no construye result y no inicia cleanup productivo. El consumo máximo es diez wakeups por segundo.

El proceso permanece terminable por el coordinator. Si no es terminado dentro del deadline absoluto de §10, el supervisor considera el escenario fallido y aplica kill fallback. No existe release.

## 9. Kill externo

Para las cinco ventanas la única semántica conforme es kill obligatorio sin release.

El coordinator, tras validar la llegada:

1. llama `proc_terminate($process)` una vez;
2. espera hasta 2000 ms consultando `proc_get_status()` cada 20 ms;
3. si continúa vivo en Windows, ejecuta exactamente `taskkill /F /T /PID <pid>` mediante `proc_open` con command array, `bypass_shell=true`, stdout/stderr drenados y timeout 2000 ms;
4. espera otros 2000 ms;
5. drena stdout/stderr y llama `proc_close()`;
6. exige proceso no running y EOF completo.

El exit no se interpreta como operation result. En Windows no se exige `signaled=true`; se exige `running=false`. El participant PID no puede crear descendientes.

## 10. Deadlines

Todos usan `hrtime(true)` monotónico.

| Plazo | Inicio | Duración | Reason al expirar |
|---|---|---:|---|
| llegada | cierre de stdin del child | 10000 ms | `barrier_arrival_timeout` |
| frame completo | primer byte | 1000 ms, dentro del anterior | `barrier_partial_frame` |
| validación | LF recibido | 1000 ms | `barrier_invalid_frame` |
| primer kill | llegada validada | 2000 ms | activa fallback |
| taskkill | inicio fallback | 2000 ms | `barrier_kill_failed` |
| wait final | retorno de kill | 2000 ms | `barrier_process_remained_alive` |
| cleanup total | primer fallo o kill | 5000 ms | `barrier_cleanup_failed` |

Ningún plazo amplía el timeout absoluto 1..30 segundos de la invocation. Expiración causa cero integración y cleanup.

## 11. Duplicados y orden

| Entrada | Reason |
|---|---|
| segunda llegada | `barrier_duplicate_arrival` |
| window distinta | `barrier_window_mismatch` |
| participant distinto | `barrier_participant_mismatch` |
| index distinto | `barrier_participant_mismatch` |
| PID distinto | `barrier_pid_mismatch` |
| challenge distinto | `barrier_binding_mismatch` |
| llegada antes de request completo | `barrier_unexpected_arrival` |
| llegada después de result | `barrier_unexpected_arrival` |
| bytes después del kill | `barrier_invalid_frame` |
| EOF antes de LF | `barrier_premature_eof` |
| salida del proceso antes de llegada | `barrier_process_exited_before_arrival` |

El primer defecto según este orden gobierna: channel, frame, schema, binding, participant, PID, window, duplicate, process state.

## 12. Separación data-plane/control-plane

`barrier_arrival` no transporta capture delta, action delta, participant action proposal, operation result, phase result, loopback result, snapshots o counts.

No autoriza logs ni diagnóstico. Stderr conserva las reglas vigentes. La excepción de stdout existe solo para los cinco procesos de §3 y reemplaza el result final; nunca coexiste con él.

El binding no transfiere action invocation plan, invocation ID o entrypoint ID al child.

## 13. Integración con topología

El participant descriptor futuro contiene exactamente:

```json
{"barrier":{"required":true,"window_id":"CRASH_AFTER_EXTERNAL_ACTION_CREATED","arrival_count":1,"release_count":0,"kill_required":true}}
```

Para todo otro participante, `barrier` es exactamente `null`.

Solo las cinco invocations first-delivery de §3 tienen barrera. Sus replay y las otras 57 invocations no tienen barrera. Loopback nunca tiene barrera.

Orden: congelar descriptor→crear pipes estándar→spawn→enviar request y EOF→ejecución→arrival→validación→kill→EOF→recolección de evidencia productiva por fase posterior. No existe result ni materialización para el participante matado en esa invocation.

## 14. API normativa del control-plane

Archivo futuro: `tests/manual/support/durable-retry-a11-coordinator.php`.

Namespace: `VeciAhorra\Tests\Manual\A11`.

```php
final class DurableRetryA11BarrierArrival
{
    public function __construct(
        public readonly string $ownershipToken,
        public readonly string $caseId,
        public readonly string $phase,
        public readonly string $participantId,
        public readonly int $participantIndex,
        public readonly string $windowId,
        public readonly int $pid,
        public readonly string $bindingChallenge,
        public readonly int $arrivalOrdinal
    ) {}

    public function toArray(): array
    {
        throw new \LogicException('barrier_api_contract_only');
    }
}

final class DurableRetryA11IntermediateBarrierControlPlane
{
    public function __construct(
        private readonly string $executionId,
        private readonly string $invocationId,
        private readonly array $participantDescriptor,
        private readonly int $expectedPid,
        private readonly string $bindingChallenge
    ) {}

    public function waitForArrival($stdout, $process, int $absoluteDeadlineNanoseconds): DurableRetryA11BarrierArrival
    {
        throw new \LogicException('barrier_api_contract_only');
    }

    public function terminateAndWait($process, array $pipes): void
    {
        throw new \LogicException('barrier_api_contract_only');
    }

    public function cleanup($process, array $pipes): void
    {
        throw new \LogicException('barrier_api_contract_only');
    }
}
```

`$stdout`, `$process` y entries de `$pipes` son resources validados con `is_resource()`. El bloque fail-closed fija estructura; los cuerpos futuros implementan §§7–11 sin cambiar firmas.

## 15. API de decorators

Archivo futuro: `tests/manual/support/durable-retry-a11-child-worker.php`.

```php
interface DurableRetryA11CrashBarrierInterface
{
    public function arriveAndAwaitKill(string $windowId): never;
}

final class DurableRetryA11CrashBarrier implements DurableRetryA11CrashBarrierInterface
{
    public function __construct(
        private readonly array $barrierControl,
        private readonly string $ownershipToken,
        private readonly string $caseId,
        private readonly string $phase
    ) {}

    public function arriveAndAwaitKill(string $windowId): never
    {
        throw new \LogicException('barrier_api_contract_only');
    }
}
```

Los cinco decorators sustituyen `crashPoint, enteredPath, awaitTermination` por un único último parámetro `DurableRetryA11CrashBarrierInterface $barrier`. En el punto de §3 llaman exactamente `$barrier->arriveAndAwaitKill(WINDOW_LITERAL)`.

Sin crash plan no se construye decorator. No existe implementación no-op, archivo o callback alternativo.

## 16. API coordinator y wiring

`DurableRetryA11Coordinator` añade métodos privados exactos:

```php
final class DurableRetryA11Coordinator
{
    private function barrierControlFor(array $participantDescriptor): array|null
    {
        throw new \LogicException('barrier_api_contract_only');
    }

    private function superviseBarrierParticipant(
        DurableRetryA11Invocation $invocation,
        array $participantDescriptor,
        $process,
        array $pipes,
        int $pid,
        int $absoluteDeadlineNanoseconds
    ): DurableRetryA11BarrierArrival {
        throw new \LogicException('barrier_api_contract_only');
    }
}
```

El primero retorna `null` o la proyección de §5. El segundo construye el control-plane, espera, valida, mata, drena, cierra y retorna evidencia. No integra ni crea process result exitoso.

Tras kill conforme, el scenario continúa únicamente con la fase de recovery/replay publicada por la topología. Fallo cancela participantes restantes y termina la invocation sin bundle.

## 17. Bootstrap y transporte

El child obtiene `barrier_control` únicamente dentro del `phase_request`. No usa descriptor adicional, argv, environment o archivo.

El request sigue llegando como un único JSON por stdin y EOF. El child valida barrier control antes de WordPress; después del bootstrap lo entrega por valor al crash barrier.

Stdout es el único canal de llegada. En Windows se usan únicamente descriptors 0, 1 y 2, porque PHP no ofrece acceso numerado portable a handles superiores a 2.

## 18. Compatibilidad Windows/PHP

`proc_open` recibe command array y `['bypass_shell'=>true,'blocking_pipes'=>false]`. Stdin/stdout/stderr son pipes estándar. El coordinator usa `stream_set_blocking($pipes[1], false)` y polling de 20 ms.

`fwrite`, `fflush`, `usleep`, `proc_get_status`, `proc_terminate`, `proc_close` y `hrtime` pertenecen a PHP CLI en Windows. No se usan POSIX signals, `pcntl`, `posix_kill`, fork o descriptor mayor a 2.

`proc_terminate` inicia el kill; `taskkill /F /T /PID` es fallback obligatorio. El command se ejecuta como array sin `cmd.exe`. Se valida PID positivo obtenido del process resource.

## 19. Catálogo cerrado de reasons

```text
barrier_channel_creation_failed
barrier_descriptor_unavailable
barrier_binding_mismatch
barrier_participant_mismatch
barrier_pid_mismatch
barrier_window_mismatch
barrier_duplicate_arrival
barrier_unexpected_arrival
barrier_partial_frame
barrier_invalid_frame
barrier_premature_eof
barrier_arrival_timeout
barrier_process_exited_before_arrival
barrier_kill_failed
barrier_process_remained_alive
barrier_cleanup_failed
barrier_residue_detected
```

Todos son no retryable dentro de la invocation, impiden result/bundle/integración, preservan estado previo y exigen cleanup. Solo kill conforme permite continuar al recovery normativo.

## 20. Atomicidad

Crear pipes, enviar request, recibir bytes y validar arrival solo crean estado supervisor efímero. No mutan snapshots, counts o estado combinado.

Kill conforme registra evidencia local de ventana alcanzada. No integra evidencia del proceso muerto. Recovery posterior produce su propio result y se valida normalmente.

Todo fallo antes, durante o después del kill produce cero captures, actions, proposals, operation results o sellado para la invocation fallida.

## 21. Cleanup y cero residuos

Orden exacto:

1. cerrar stdin si sigue abierto;
2. terminar proceso si running;
3. aplicar fallback tras 2000 ms;
4. drenar stdout y stderr hasta EOF o límite;
5. cerrar cada pipe con `fclose`;
6. llamar `proc_close` una vez;
7. eliminar PID del registry;
8. comprobar `proc_get_status` no running antes de cerrar resource;
9. verificar cero PHP children y cero listeners propios;
10. verificar ausencia de archivos de barrera y `.a11-runtime`.

Cero residuos significa ningún process resource abierto, pipe abierto, PID registrado, child running, listener, socket, archivo, lock o estado barrier conservado.

## 22. Allowlist posterior exacta

Topología documental futura:

- `docs/durable-retry-production-activation-a11-action-capture-multiprocess-topology-normative-correction.md`.

Implementación EA6 modificable/creable:

- `tests/manual/support/durable-retry-a11-runtime-capture-contract.php`;
- `tests/manual/support/durable-retry-a11-coordinator.php`;
- `tests/manual/support/durable-retry-a11-child-worker.php`;
- `tests/manual/support/durable-retry-a11-http-webpay-stub.php`;
- `tests/manual/durable-retry-a11-action-capture-test.php`;
- `tests/manual/durable-retry-a11-action-capture-infrastructure-test.php`;
- `tests/manual/durable-retry-a11-child-protocol-test.php`;
- `tests/manual/durable-retry-a11-http-webpay-stub-protocol-test.php`;
- `tests/manual/durable-retry-a11-action-invocation-plan-test.php`;
- `tests/manual/durable-retry-a11-orphan-closure-test.php`;
- `tests/manual/durable-retry-a11-ea6-matrix-test.php`.

No se autoriza archivo productivo, helper adicional, harness adicional o modificación de infraestructura histórica.

## 23. Matriz de pruebas

`durable-retry-a11-child-protocol-test.php` certifica 25 casos y mínimo 100 assertions: cinco arrivals válidos, cinco puntos exactos, stdout único, LF/flush, bloqueo, ausencia de result, request projection y bootstrap.

`durable-retry-a11-action-capture-infrastructure-test.php` certifica 20 casos y mínimo 80 assertions: channel estándar, ausencia de fd extra/files/env/argv/socket, API, Windows, deadlines, reasons y allowlist.

`durable-retry-a11-ea6-matrix-test.php` certifica 22 casos y mínimo 110 assertions:

1. cinco kills exitosos;
2. binding incorrecto;
3. participant incorrecto;
4. PID incorrecto;
5. window incorrecta;
6. duplicado;
7. parcial;
8. EOF prematuro;
9. timeout;
10. exit antes de arrival;
11. kill fallback exitoso;
12. kill fallido;
13. proceso vivo tras fallback;
14. cleanup;
15. cero residuos;
16. cero evidencia parcial;
17. coexistencia con phase result normal.

PASS exige todos los casos y assertions, exit 0, stderr vacío en caminos conformes, cero warnings/notices/deprecations y cero residuos.

## 24. Precedencia normativa final

Esta corrección conserva los cinco puntos y kill externo de la corrección complementaria; sustituye sus archivos/locks/callback de espera por stdout barrier arrival.

Limita Action Capture Transport únicamente para permitir `barrier_arrival` en vez de `phase_result` en cinco procesos. No modifica request/result normal, loopback, capture, proposals, hashes, transaction o coordinator ownership.

Complementa Runtime Capture Transport, Action Capture Transport, shutdown order, participant proposal materialization y coordinator API. Deroga toda afirmación que exija `enteredPath`, release file, pipe adicional, autonomous crash o final result del participante matado.

No define la topología completa. La corrección de topología debe consumir este canal, catálogo, deadlines, APIs y reasons sin volver a seleccionarlos.

## 25. Veredicto

`A11 EA6 INTERMEDIATE BARRIER CONTROL-PLANE IMPLEMENTABLE TRAS CORRECCIÓN NORMATIVA`
