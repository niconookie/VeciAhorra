# Corrección normativa A11 — aceptación operacional real de Durable Retry

## 1. Autoridad y veredicto

Este documento resuelve los cuatro bloqueadores de
`durable-retry-production-activation-a11-readiness-audit.md` y complementa, sin
reabrir, los contratos A1–A10.

**A11 IMPLEMENTABLE TRAS CORRECCIÓN NORMATIVA**

Bloqueadores resueltos: **4**. Bloqueadores restantes: **0**.

## 2. Naturaleza definitiva

A11 es un microhito mixto y cerrado:

```text
corrección productiva mínima de exclusión legacy
+ infraestructura de pruebas aislada
+ cinco harnesses de aceptación operacional
```

No añade schema, migraciones, hooks, payloads, autoridad, fallback ni failpoints
productivos. La instrumentación de crash vive exclusivamente en wrappers de
tests sobre dependencias ya inyectables.

## 3. Corrección productiva mínima

La corrección se limita a la entrada legacy de reconciliation. A10 solo produce
autoridad durable inicial para stage `reconciliation`; no existe un productor
versionado de generation 1 para los otros tres stages. Los workers downstream
históricos conservan su conducta. Extender A3 a stages no producidos reabriría A1
sin necesidad y queda fuera.

Clases afectadas:

- `DurableCompletionWorkers` procesa en `reconciliation(int $id): void` y
  reprograma mediante `DurableCompletionScheduler::retry()`;
- `DurableCompletionOrchestration::register()` construye y registra workers;
- `Application::run()` construye orchestration y posee `$wpdb` válido desde el
  constructor.

## 4. Contrato exacto de guardia legacy

Se reutilizan sin cambios:

```php
DurableRetryLegacyExclusionInterface::class
DurableRetryLegacyAuthorityResult::class
DurableRetryLegacyAuthorityRepository::class
```

No se crea puerto ni resultado nuevo. El catálogo cerrado existente es:
`legacy`, `durable`, `indeterminate`. Errores y excepciones del repositorio ya se
convierten en `indeterminate` con reason A3 tipado.

Constructor definitivo de workers:

```php
public function __construct(
    private readonly DurableRetryLegacyExclusionInterface $legacyAuthority,
    private readonly DurableCompletionScheduler $scheduler = new DurableCompletionScheduler(),
    private readonly CompletionBranchPolicy $branches = new CompletionBranchPolicy()
) {}
```

`$legacyAuthority` es obligatorio y primero. No hay default, service locator ni
construcción interna.

## 5. Punto y conducta de consulta

La primera operación de `reconciliation(int $id): void`, antes de construir
claims/processors o leer evidencia funcional, es:

```php
$authority = $this->legacyAuthority->classify(
    DurableRetryAuthorityIdentity::reconciliation($id)
);

if (! $authority->isLegacyAuthorized()) {
    return;
}
```

Reglas:

- `legacy`: continúa el método histórico, máximo un procesamiento y un retry;
- `durable`: retorno normal, cero claim/proceso/retry/cancelación/mutación;
- `indeterminate`: mismo retorno fail-closed, cero efectos;
- input inválido: la excepción de identity se propaga, cero efectos;
- excepción imposible de un double: se propaga, nunca continúa;
- no logs, métricas, hooks o reasons nuevos;
- callback WordPress recibe retorno `void` normal para durable/indeterminate;
- no se cancela evidencia durable ni acción legacy desde el worker;
- una acción legacy preexistente queda consumida por Action Scheduler después del
  callback no-op y no se reprograma.

La regla obligatoria es: **el worker legacy solo procesa o reprograma cuando A3
confirma autoritativamente `legacy`.**

## 6. Wiring exacto de la guardia

Constructor definitivo de orchestration:

```php
public function __construct(
    private readonly DurableRetryLegacyExclusionInterface $legacyAuthority
) {}
```

`register()` crea una vez:

```php
$workers = new DurableCompletionWorkers($this->legacyAuthority);
```

`Application::run()` reemplaza la construcción sin argumentos por:

```php
(new DurableCompletionOrchestration(
    new DurableRetryLegacyAuthorityRepository($database)
))->register();
```

`$database` debe conservarse como propiedad privada `wpdb` inicializada desde la
misma referencia canónica validada durante `registerDurableRetryGraph()`. No se
vuelve a leer el global en `run()`. La propiedad no se expone en container.

Se admite una segunda instancia stateless del repositorio A3; consulta la misma
fuente autoritativa y no reconstruye A2/A4/A5. No se comparte la composición A9
ni se llama otra vez `router()`.

## 7. Compatibilidad histórica

Sin fila generation 1, A3 retorna legacy y la conducta histórica permanece. Con
fila durable, el no-op es obligatorio. Indeterminate/error bloquea legacy. No se
modifican scheduler, recovery, policies, router A8 ni processors.

Solo `tests/manual/durable-completion-orchestration-test.php` debe adaptarse: crea
un double `DurableRetryLegacyExclusionInterface`, certifica legacy/durable/
indeterminate y conserva scheduling/backoff/recovery existentes.

## 8. Arquitectura A11 definitiva

```text
A11 coordinator
→ environment guard
→ fixture allocator
→ WordPress/MySQL bootstrap
→ PHP loopback router + Webpay stub
→ child workers with barriers
→ exact Action Scheduler action runner
→ observations/assertions
→ cleanup
```

Todos los procesos reciben un manifiesto JSON por ruta absoluta temporal y su
SHA-256. Ningún secreto aparece en argumentos, stdout o archivos del repositorio.

## 9. `run_id`, temporales y barrera

Formato canónico:

```text
a11_YYYYMMDDHHMMSS_<pid>_<16 lowercase hex>
```

Longitud máxima 48; regex
`/^a11_[0-9]{14}_[1-9][0-9]*_[a-f0-9]{16}$/D`. Se usa en grupos AS, public IDs,
tokens hasheados, metadatos, temp dir y logs. IDs autoincrementales se capturan en
el manifiesto, nunca se eligen manualmente.

Directorio: `sys_get_temp_dir()/veciahorra-a11/<run_id>`, permisos privados. Por
worker: `ready.<role>.<pid>`, `entered.<role>.<pid>`, `result.<role>.<pid>.json` y
stderr. `release.<case>` se crea mediante rename atómico bajo `flock()`.
Polling máximo cada 20 ms con deadline monotónico; sleep breve no es garantía.
Proceso muerto o timeout aborta, termina todos los hijos y ejecuta cleanup.

## 10. Tres helpers definitivos

1. `tests/manual/support/durable-retry-a11-coordinator.php`: CLI, valida entorno,
   asigna fixture, servidor/puerto, procesos, barreras, resultados y cleanup.
2. `tests/manual/support/durable-retry-a11-child-worker.php`: bootstrap WP por
   proceso; operaciones enumeradas `publish`, `callback`, `legacy`, `as_action`,
   `recovery`, `http`; contiene el runner AS exacto y decorators de crash.
3. `tests/manual/support/durable-retry-a11-http-webpay-stub.php`: router para PHP
   built-in server; health, endpoint REST A11 y gateway stub determinista.

No se requiere cuarto helper. Clases auxiliares se declaran dentro de estos tres
archivos con prefijo `DurableRetryA11`; no se autoloadan en producto.

## 11. Runner determinista de Action Scheduler

El child `as_action` carga `wp-load.php`, exige AS 3.9.3 o versión compatible y
localiza acciones únicamente con `as_get_scheduled_actions()` filtrando hook,
args exactos, grupo derivado del `run_id`, status pending, per_page 2 y orden ID
ascendente. Debe encontrar exactamente un ID.

Ejecuta únicamente:

```php
ActionScheduler_QueueRunner::instance()->process_action(
    $actionId,
    'VeciAhorra A11'
);
```

`process_action()` es método público del queue runner y valida status antes de
ejecutar. No se usa `run()`, WP-Cron, tráfico incidental, store interno ni tablas
AS. Claims concurrentes de AS no son objeto de este runner exacto; la carrera de
callback se ejecuta contra el callback real y queda protegida por CAS durable.

Reloj real UTC. Las acciones A11 se crean vencidas deliberadamente en máximo 5 s.
Máximo una acción por invocación; timeout 30 s. Exit: 0 PASS, 10 funcional, 20
infraestructura, 30 cleanup. Stdout JSONL, stderr sin diagnostics permitidos.
Pendientes se comprueban y cancelan con `as_get_scheduled_actions()` y
`as_unschedule_action()` usando identidad exacta.

## 12. Aislamiento Action Scheduler

El hook productivo permanece el del catálogo; Action Scheduler no permite grupos
dinámicos desde el adapter productivo, por lo que el grupo durable continúa
siendo el catálogo productivo. El aislamiento fuerte se obtiene por args únicos
`schedule_id/generation` y ejecución por action ID exacto. El `run_id` identifica
la fila durable/dispatch y, por tránsito, el action ID. “Grupo único por run_id”
de la readiness queda sustituido normativamente: cambiar el grupo alteraría A7.

El helper rechaza cualquier action cuyo hook, args o grupo no coincida exactamente
con el snapshot A11. Así no ejecuta hooks ajenos.

## 13. Instrumentación de crash sin producto

No se añaden failpoints productivos. Se usan decorators solo de tests:

- scheduler externo: delega `schedule()`, escribe entered después de obtener ID,
  bloquea antes de devolver; kill prueba acción externa sin asociación;
- stage processor: señala al entrar después del claim y bloquea antes del intento;
- attempt processor: delega el intento, señala al retornar y bloquea antes de que
  el stage processor clasifique/persista;
- schedule repository: delega transición terminal, señala APPLIED y bloquea antes
  de devolver al executor;
- executor interface: delega `execute()`, señala resultado y bloquea antes de
  devolver al callback.

Son wrappers de interfaces existentes, construidos manualmente por child worker;
no cambian contratos. Cada wrapper acepta solo enum de crash point del manifiesto.
El coordinator espera `entered`, verifica evidencia DB/AS y usa
`proc_terminate()`; exit/excepción capturada no acreditan crash real.

## 14. Puntos de crash cerrados

| Punto | Anterior / pendiente | Estado esperado | Recuperación |
|---|---|---|---|
| `CRASH_AFTER_EXTERNAL_ACTION_CREATED` | AS devolvió ID / asociación local | action pending, local action ID null | nueva coordinación encuentra/cancela/converge |
| `CRASH_AFTER_LOCAL_CLAIM` | CAS pending→claimed / processor | claimed, 0 intento | lease/claim recovery y redelivery controlada |
| `CRASH_AFTER_FUNCTIONAL_ATTEMPT` | attempt retornó / clasificación cierre | evidencia funcional puede existir, schedule claimed | replay relee evidencia, 1 efecto |
| `CRASH_AFTER_RESULT_PERSISTED` | transición terminal APPLIED / executor return | consumed/failed/orphaned persistido | redelivery clasifica estado, 0 intento |
| `CRASH_BEFORE_CALLBACK_RETURN` | executor retornó / callback return | resultado ya cerrado | redelivery idempotente |

Al menos los cinco casos terminan proceso real. Excepciones controladas se prueban
separadamente y no cuentan como crash.

## 15. Servidor HTTP determinista

Se usa PHP built-in server porque el router A11 debe sustituir el gateway sin
alterar WordPress global. Command:

```text
php -S 127.0.0.1:<ephemeral-port>
    -t C:/xampp/htdocs/Minimarket
    tests/manual/support/durable-retry-a11-http-webpay-stub.php
```

El coordinator reserva un puerto loopback, lanza el servidor, registra PID y
espera hasta 10 s por `GET /__a11/health?run_id=...`. Conflicto asigna otro puerto
antes de crear fixtures; después de 3 intentos es fallo infra. HTTP, no HTTPS:
Webpay externo no participa. Base URL `http://127.0.0.1:<port>`.

El router acepta solo loopback, header `X-VeciAhorra-A11-Run-Id` exacto y rutas
health o `/wp-json/veciahorra/v1/payments/webpay/return`. Para el return carga
`wp-load.php`, crea una nueva `Application`, obtiene su materializer singleton y
construye `WebpayReturnService`/controller con repositorios reales y gateway stub.
No usa el controller ya registrado por el bootstrap para evitar el gateway real;
sí usa A9/A10 reales del nuevo Application.

Timeout request 15 s; server total 120 s. Cierre con `proc_terminate`, espera 5 s,
kill final y verificación de puerto libre. PID/logs solo en temp A11.

## 16. Gateway Webpay stub multiproceso

El stub implementa `WebpayReturnGatewayInterface` dentro del router helper. Lee
solo el manifiesto validado por `run_id` y SHA. Mapea hash de token a respuesta
`WebpayCommitResult` determinista; nunca conserva token plano ni abre red.

Escenarios enumerados: approved, rejected, error_before_commit, delayed_approved.
La excepción post-persistencia se inyecta en el router A8/decorator de servicio,
no en gateway. Delay se sincroniza por barrera, no tiempo arbitrario. Registro
JSONL contiene token hash truncado, scenario y count bajo `flock()`.

Antes de bootstrap, child define `VECIAHORRA_PAYMENT_GATEWAY=mock` y la guardia
comprueba `PaymentGatewayConfiguration::gateway() === mock`. El servicio HTTP usa
explícitamente el stub, nunca el binding productivo Dummy/Webpay. Si se construye
`WebpayPaymentGateway` o se observa host no loopback, abort fatal exit 20. No se
modifican options.

## 17. Cinco harnesses definitivos

1. `tests/manual/durable-retry-a11-operational-acceptance-test.php`: flujo HTTP
   seguro → persistencia → A10/A5–A8 → AS exacto → callback/executor/processor.
2. `tests/manual/durable-retry-a11-multiprocess-concurrency-test.php`: publicaciones
   y callbacks superpuestos, duplicate key, una autoridad/acción/consumo.
3. `tests/manual/durable-retry-a11-crash-recovery-test.php`: cinco kills de §14 y
   convergencia posterior.
4. `tests/manual/durable-retry-a11-webpay-replay-test.php`: replay HTTP secuencial,
   post-persistencia, post-excepción, concurrente y recovery.
5. `tests/manual/durable-retry-a11-legacy-exclusion-test.php`: acción legacy
   preexistente frente a authority legacy/durable/indeterminate y carrera real.

Las responsabilidades no se superponen: H1 acredita cadena feliz; H2 carreras;
H3 procesos caídos; H4 frontera HTTP; H5 exclusión corregida.

## 18. Fixtures y seguridad

Guardia inicial exige: `WP_ENVIRONMENT_TYPE` local/development; DB y HTTP
loopback/local; env `VECIAHORRA_A11_CERTIFICATION=1`; gateway mock; manifiesto
válido; tablas InnoDB; cero fixture previa con run_id. No documenta credenciales.

Prohibidos cobros, emails, webhooks, cron externo, inventario/pedidos ajenos,
Transbank, DNS externo y truncate. Pedidos, origins, returns, reconciliations,
schedules y completions llevan run_id en public IDs/metadatos permitidos. Cleanup
usa IDs capturados, nunca LIKE amplio ni rangos elegidos.

## 19. Cleanup normativo

En `finally`: terminar hijos/servidor; cancelar acciones exactas por API; eliminar
en orden inverso filas durable, completions, reconciliation, return, origin,
session/pedido A11; restaurar solo env del proceso; borrar barreras/logs PASS y
locks. Un fallo conserva logs temporalmente fuera del repo e informa ruta.

Certificación requiere: 0 hijos, 0 server/stub, 0 actions A11 pending, 0 filas
fixture, 0 temp, 0 locks. No se escribe `artifacts/`.

## 20. Política de SKIP y diagnostics

Local diario puede exit 2 SKIP por dependencia ausente. Certificación con
`VECIAHORRA_A11_CERTIFICATION=1` convierte cualquier skip en exit 20. Ningún skip
cuenta como PASS. Exit 0 PASS, 10 funcional, 20 infraestructura, 30 cleanup.
Cero warning, notice, deprecated o diagnostic en stdout/stderr.

## 21. Matriz normativa cerrada (31 casos)

En todos: filas y actions esperadas son exactas, publicación/proceso son máximos
indicados, cleanup termina en cero y el harness señalado es único.

| ID | Precondición/acción/procesos | Resultado exacto | HTTP | Harness |
|---|---|---|---|---|
| A11-OP-01 | approved nuevo, HTTP+AS child | 1 recon, 1 publish, gen1/action/claim/process 1, consumed | 200 approved | H1 |
| A11-OP-02 | durable off | 1 recon, legacy action 1, durable 0 | 200 approved | H1 |
| A11-OP-03 | AS API unavailable double | durable external unavailable, legacy 0 | N/A | H1 |
| A11-OP-04 | redelivery action exacta | proceso total 1, action terminal | N/A | H1 |
| A11-OP-05 | processor retryable | predecessor superseded 1, successor 1 | N/A | H1 |
| A11-CON-01 | 2 publish release simultáneo | authority/gen1/action máximo 1 | N/A | H2 |
| A11-CON-02 | 2 callback mismo schedule | claim/proceso/consumo máximo 1 | N/A | H2 |
| A11-CON-03 | 2 insert generation 1 | una fila compatible active | N/A | H2 |
| A11-CON-04 | generation vieja y actual | vieja stale, actual procesa 1 | N/A | H2 |
| A11-CON-05 | cancel vs callback | un cierre, proceso máximo 1 | N/A | H2 |
| A11-CR-01 | kill external-created | action máximo 1 tras recovery, asociación convergente | N/A | H3 |
| A11-CR-02 | kill claimed | 0 intento antes kill, luego máximo 1 | N/A | H3 |
| A11-CR-03 | kill post-attempt | evidencia única, replay sin doble efecto | N/A | H3 |
| A11-CR-04 | kill post-result | terminal persiste, redelivery proceso 0 | N/A | H3 |
| A11-CR-05 | kill pre-callback-return | response ausente, schedule terminal, redelivery 0 | N/A | H3 |
| A11-WR-01 | token nuevo | recon/publish/gen1 1 | 200 approved | H4 |
| A11-WR-02 | replay inmediato | commit stub total 1, recon 1 | 200 already_processed | H4 |
| A11-WR-03 | replay post A8 exception | evidence/recon 1, segunda publish converge | primero 500, segundo 200 | H4 |
| A11-WR-04 | dos POST release | commit/recon máximo 1 | uno approved, otro already/processing | H4 |
| A11-WR-05 | return encontrado + recovery | mismo recon, publish por resume máximo 1 | 200 already_processed | H4 |
| A11-WR-06 | Woo order A11 | pedido/pago sin doble transición | 200 idempotente | H4 |
| A11-EX-01 | A3 legacy | worker claim/process/retry histórico máximo 1 | N/A | H5 |
| A11-EX-02 | A3 durable | claim/process/retry 0 | N/A | H5 |
| A11-EX-03 | durable ya scheduled | legacy 0, misma gen/action | N/A | H5 |
| A11-EX-04 | A3 indeterminate | claim/process/retry 0 | N/A | H5 |
| A11-EX-05 | A3 read exception double | excepción propaga, efectos 0 | N/A | H5 |
| A11-EX-06 | durable consumed | legacy 0, durable sin mutación | N/A | H5 |
| A11-EX-07 | legacy action pre-transfer luego durable | callback legacy no-op, retry 0 | N/A | H5 |
| A11-EX-08 | legacy callback vs durable callback | legacy 0, durable proceso máximo 1 | N/A | H5 |
| A11-EX-09 | replay decisión durable | publish converge, legacy 0 | N/A | H5 |
| A11-EX-10 | identity histórica sin gen1 | legacy continúa compatible | N/A | H5 |

## 22. Criterios cuantitativos

- 5 harnesses, 31/31 casos;
- mínimo 1 assertion primaria por caso + 5 cleanup globales por harness + 8 guardias
  de entorno compartidas por ejecución: mínimo **64 assertions** A11;
- el total final puede ser mayor pero debe reportarse individualmente;
- 0 fallos/skips certificación/warnings/notices/deprecations/diagnostics;
- 0 hijos, servers, actions, fixtures, temp y locks residuales;
- timeouts: H1 60 s, H2 90 s, H3 120 s, H4 120 s, H5 90 s;
- total runner conjunto máximo 480 s;
- suite base 76/76, 121 casos, 5.999 assertions intacta;
- carrera acredita intervals monotónicos superpuestos y ready previo a release.

## 23. Allowlist definitiva de implementación (12 rutas)

### Productivo (3 modificados)

```text
app/Core/Application.php
app/Modules/Fulfillment/Orchestration/DurableCompletionOrchestration.php
app/Modules/Fulfillment/Orchestration/DurableCompletionWorkers.php
```

### Harness histórico (1 modificado)

```text
tests/manual/durable-completion-orchestration-test.php
```

### Harnesses A11 (5 nuevos)

```text
tests/manual/durable-retry-a11-operational-acceptance-test.php
tests/manual/durable-retry-a11-multiprocess-concurrency-test.php
tests/manual/durable-retry-a11-crash-recovery-test.php
tests/manual/durable-retry-a11-webpay-replay-test.php
tests/manual/durable-retry-a11-legacy-exclusion-test.php
```

### Helpers (3 nuevos)

```text
tests/manual/support/durable-retry-a11-coordinator.php
tests/manual/support/durable-retry-a11-child-worker.php
tests/manual/support/durable-retry-a11-http-webpay-stub.php
```

Documentación y este documento quedan fuera. No hay otros históricos, contratos,
resultados, schedulers o fixtures persistentes autorizados.

## 24. Preservación A1–A10

A1 identity/result, A2 cohorting, A2.1 source, A3 contract/repository/result, A4
transfer, A5 producer, A6 resolver, A7 coordinator, A8 router, A9 composition y
A10 direct wiring quedan byte-identical salvo `Application.php` para inyectar el
guard legacy. El guard consume A3: no decide, transfiere ni agenda. Solo impide
que un caller histórico contradiga autoridad ya persistida. No crea segunda
decisión, fallback, schedule durable o transferencia.

## 25. Guardias estructurales futuras

La suite A11 falla ante: worker funcional antes de classify; catch que continúe;
default legacy; consulta SQL directa en worker; nueva A2/A4/A5/A8; cambios a
scheduler/hook/payload/schema; failpoint productivo; tablas AS directas; gateway
Transbank; HTTP no loopback; runner `run()`; archivos fuera de 12 rutas.

## 26. Secuencia de implementación

1. conservar `$database` en Application e inyectar A3 repository;
2. adaptar orchestration y constructor worker;
3. insertar guard como primera operación reconciliation;
4. adaptar histórico y certificar tres estados/excepción;
5. crear coordinator, child y HTTP stub;
6. implementar H5 para cerrar corrección productiva;
7. implementar runner AS exacto en child;
8. H1 operacional; H2 concurrencia; H3 crash; H4 replay;
9. ejecutar cinco individuales y conjunto;
10. cleanup e inventario residual;
11. suites Durable Retry y pagos; recertificación final.

## 27. Secuencia de validación

Base Git exacta y staging vacío; `php -l` en 12 rutas; histórico individual;
5 A11 individuales; runner conjunto; solapamiento PID/intervalos; cinco kills;
HTTP real loopback; action ID exacto; consultas MySQL por repositorios; suite
Durable Retry; suite relacionada con payments; búsquedas estructurales; 0 actions,
fixtures, procesos, servidor, temp/locks; `git diff --check`; allowlist exacta.

## 28. Riesgos no bloqueantes

PHP built-in server no replica Apache, pero prueba frontera HTTP/WordPress. AS
`process_action()` no acredita claim interno del queue runner; la idempotencia
concurrente se acredita en callback/CAS. Polling añade latencia pero no autoridad.
Logs de fallo pueden persistir fuera del repo hasta revisión. Todos tienen criterio
explícito y no bloquean implementación.

## 29. Prohibiciones definitivas

Sin schema/migrations, tablas AS directas, Webpay real, cron/tráfico incidental,
sleep como barrera, skips certificación, datos ajenos, fallback legacy,
indeterminate→legacy, cambios A5–A8, hooks/payloads renombrados, servers/procesos
residuales, escritura en artifacts, commit o push durante implementación.

## 30. Conclusión obligatoria

**A11 IMPLEMENTABLE TRAS CORRECCIÓN NORMATIVA**

Bloqueadores resueltos: 4; restantes: 0. Naturaleza: microhito mixto con guardia
legacy mínima, instrumentación aislada y aceptación operacional. Contrato legacy:
A3 existente, fail-closed salvo `legacy`. Runner: action ID exacto mediante método
público `process_action()`. Crash: cinco decorators test-only y terminación real.
HTTP/Webpay: servidor PHP loopback y gateway stub explícito. Harnesses: cinco de
§17; helpers: tres de §10; matriz: 31 casos de §21; allowlist: 12 rutas de §23;
criterios: §22; riesgos: §28; prohibiciones: §29; implementación/recertificación:
§§26–27.

A1–A10 permanecen intactos en autoridad y conducta. No se implementó A11, no se
modificaron producto/pruebas, y no se realizó commit ni push.
