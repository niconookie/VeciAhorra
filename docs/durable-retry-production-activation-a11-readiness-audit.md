# Auditoría de readiness A11 — aceptación operacional real de Durable Retry

## 1. Veredicto

**A11 BLOQUEADO POR AMBIGÜEDAD DOCUMENTAL**

La infraestructura local permite diseñar y ejecutar gran parte de A11, pero el
alcance no puede cerrarse como exclusivamente de pruebas. El worker legacy
productivo procesa identidades sin consultar la autoridad Durable Retry. Esto
contradice el invariante que A11 debe demostrar y obliga a decidir normativamente
si se corrige producto, cómo se cierra una acción legacy preexistente y qué
repositorio/contrato puede consultar el worker.

Bloqueadores de readiness: **4**. A1–A10 no fueron modificados.

## 2. Base certificada

| Control | Resultado |
|---|---|
| Rama | `main` |
| HEAD | `1238358534083c589ec03df479ce28f6d8f46d9c` (`1238358`) |
| Commit | `docs(orders): audit durable retry production acceptance` |
| Divergencia | `0 behind / 57 ahead` |
| Staging / tracked | vacío / 0 |
| Suite | `76/76`, 121 casos, 5.999 assertions, 0 diagnostics |
| `artifacts/` | 504 archivos |
| Fuente normativa | `durable-retry-production-activation-final-acceptance-audit.md` |
| Veredicto preservado | `DURABLE RETRY PRODUCTIVO BLOQUEADO` |

## 3. Naturaleza de A11

La opción preferida era infraestructura de pruebas sin cambios productivos. El
código demuestra que A11 es potencialmente **mixto**:

1. cinco harnesses operacionales nuevos;
2. un orquestador y workers de prueba multiproceso;
3. posiblemente una corrección productiva de exclusión legacy/durable;
4. especificación normativa previa para autorizar esa corrección.

No se autoriza modificar A1–A10 desde esta auditoría. Una corrección debe ser un
microhito normativo separado o una corrección A11 explícita.

## 4. Inventario validado del entorno

| Componente | Estado | Evidencia / uso A11 |
|---|---|---|
| PHP CLI | disponible y validado | 8.2.12 ZTS, `C:\xampp\php\php.exe` |
| WordPress | disponible y validado | 7.0.2, `C:\xampp\htdocs\Minimarket\wp-load.php` |
| WooCommerce | disponible y validado | 10.8.1 cargado por `wp-load.php` |
| Action Scheduler | disponible y validado | 3.9.3; APIs schedule/find/cancel/recurring disponibles |
| MariaDB | disponible y validado | 10.4.32; conexión mediante `$wpdb`/`wp-config.php` |
| Schema | disponible | base WordPress configurada; no se documentan credenciales |
| Prefijo | validado | `wp_`; tablas VeciAhorra usan `wp_va_` |
| Motores | validado | durable schedules, reconciliations y AS actions: InnoDB |
| WP-CLI | ausente | no puede ser runner obligatorio |
| `proc_open`, `popen`, `shell_exec` | disponibles | multiproceso posible |
| `pcntl_fork` | ausente | workers deben ser PHP CLI independientes |
| cURL PHP | disponible | HTTP local posible si hay servidor |
| Apache | instalado, no activo durante auditoría | HTTP real no validado |
| MySQL CLI | instalado fuera de PATH | no necesario; `$wpdb` es mecanismo autorizado |
| Tiempo PHP CLI | ilimitado (`max_execution_time=0`) | harness impone timeouts propios |
| Transacciones | disponibles | InnoDB; visibilidad exige commits entre procesos |
| HTTPS local | configurado en `home_url`, no validado | no debe ser dependencia hasta fijar servidor/certificado |
| Webpay externo | prohibido | fixture fiel obligatorio |

Clasificación: WordPress/WooCommerce/AS/MariaDB son obligatorios para aceptación;
HTTP real y procesos independientes también. Apache/HTTPS están disponibles pero
no validados. WP-CLI es ausente y sustituible por el runner público PHP de Action
Scheduler. No se inspeccionaron ni documentaron secretos.

## 5. Bootstrap real y Action Scheduler

“Real” significa cargar `wp-load.php`, permitir que WordPress inicialice plugins,
usar WooCommerce y sus tablas reales, crear pedidos marcados A11, registrar hooks
con `Application::run()` y usar Action Scheduler 3.9.3 real. Una invocación directa
de servicio solo prueba integración interna; REST interno prueba routing WordPress;
HTTP por cURL contra servidor local prueba la frontera observable.

APIs autorizadas:

- `as_schedule_single_action()` para crear;
- `as_get_scheduled_actions()` para listar por hook, args, grupo y status;
- `as_has_scheduled_action()` para existencia;
- `as_unschedule_action()` para limpieza/cancelación;
- `ActionScheduler_QueueRunner::instance()->run('A11')` o
  `process_action($id, 'A11')` para ejecución determinista.

La elección entre `run()` y `process_action()` es una ambigüedad: `run()` puede
consumir acciones ajenas vencidas; `process_action()` apunta a una acción exacta
pero su condición de API pública estable debe fijarse normativamente. No se
accederá a tablas internas AS. La unicidad pendiente se verifica mediante
`as_get_scheduled_actions()` y el catálogo/grupo propio.

## 6. Hallazgo productivo: exclusión legacy no aplicada

`DurableCompletionWorkers::reconciliation()` adquiere directamente el lease
funcional y ejecuta `PaymentReconciliationProcessor`; business, delivery y
fulfillment hacen lo mismo con sus processors. Ningún método consulta
`DurableRetryLegacyAuthorityRepository`, `DurableRetryInitialTransferRepository`,
el schedule durable ni un puerto de autoridad. Después de un estado retryable,
`DurableCompletionScheduler::retry()` puede crear otra acción legacy.

La creación inicial está protegida por A8, pero una acción legacy preexistente
puede ejecutarse después de transferir autoridad a durable. Los leases funcionales
pueden evitar dos efectos simultáneos en algunos stages, pero no convierten al
worker legacy en no ejecutable ni garantizan que no reprograme. Por ello el
invariante “para una identidad durable no existe autoridad legacy ejecutable” no
está aplicado operacionalmente por el worker.

Esto es un defecto productivo demostrado por inspección, no una mera ausencia de
evidencia. Su corrección requiere decisiones sobre contrato, comportamiento ante
indeterminate, cancelación de acciones preexistentes y los cuatro stages.

## 7. Modelo seguro de datos A11

Cada ejecución tendrá `run_id = a11_<UTC YYYYMMDDHHMMSS>_<16 hex>` generado por
el coordinador. Este valor se incorpora en public IDs, buy order, session ID,
worker IDs, dispatch tokens y metadatos WooCommerce. No se reservan rangos
numéricos globales: se conservan IDs retornados por repositorios en un manifiesto
temporal fuera del repositorio.

Tablas potencialmente afectadas:

- `wp_va_payment_origin_contexts`, `wp_va_webpay_returns`;
- `wp_va_payment_reconciliations` y claims/evidencia relacionados;
- `wp_va_durable_retry_initial_transfers`, `wp_va_durable_retry_schedules`;
- business/delivery/fulfillment completion;
- `wp_posts`, `wp_postmeta` y tablas WooCommerce aplicables;
- almacenamiento encapsulado de Action Scheduler.

Timestamps UTC y microsegundos cero. Los datos que deben ser visibles entre
procesos se committean antes de liberar la barrera. No se usa `TRUNCATE`. Cleanup
por IDs y `run_id`, en orden inverso de FK, cancela acciones propias vía API,
elimina pedidos A11 y verifica cero filas/acciones residuales. Al comenzar se
rechaza ejecutar si `WP_ENVIRONMENT_TYPE` no es `local`/`development` o si la URL
no es localhost.

## 8. Barreras y multiproceso

El coordinador usa un directorio de `sys_get_temp_dir()` llamado por `run_id`.
Cada worker abre un canal JSONL propio y crea `ready.<pid>` después de cargar WP y
abrir su conexión. El coordinador espera todos los ready, escribe atómicamente
`release`, y los workers bloquean con `flock()` sobre un archivo barrera; no se
usa sleep como única sincronización. Cada worker registra tiempos monotónicos de
entrada/salida y un marcador `entered` antes de la operación CAS. El solapamiento
se demuestra por intervalos superpuestos y porque ambos están `entered` antes de
liberar la segunda barrera.

Timeout por worker: 30 s; gracia de terminación: 5 s; luego `proc_terminate()` y
verificación de cierre. Resultado por stdout JSONL, stderr separado, exit 0 PASS,
2 SKIP local, 10 fallo funcional, 20 infraestructura, 30 cleanup. Siempre se
cierran pipes, procesos y temporales.

## 9. Harness 1 — aceptación operacional end-to-end

**Ruta:** `tests/manual/durable-retry-production-acceptance-wordpress-action-scheduler-test.php`

Usa WP/WC/AS/MySQL y Application/A9/A8/A10 reales; Webpay se sustituye con un
gateway fixture fiel que nunca hace red. Crea origin, return y pedido A11, procesa
`WebpayReturnService`, observa reconciliation y schedule generation 1, localiza
una única acción mediante API AS, ejecuta la acción exacta, y verifica callback,
claim, processor y cierre/siguiente generación.

Punto inicial: tablas disponibles y cero identidad `run_id`. Punto final: estado
terminal conocido o un successor exacto asociado. Assertions mínimas por caso:
persistencia funcional antes de schedule; misma identity; authority durable;
acción/args/grupo exactos; asociación local; un claim; un intento; transición
válida; cero legacy; cleanup completo. Timeout 60 s. SKIP solo local por dependencia
ausente; prohibido para certificación.

Tablas y cleanup siguen §7. No usa HTTP y resuelve la integración funcional B1 y
la combinación de infraestructura B3, pero no el replay HTTP.

## 10. Harness 2 — concurrencia multiproceso

**Ruta:** `tests/manual/durable-retry-production-acceptance-multiprocess-test.php`

Coordinador real con al menos dos workers CLI y conexiones `$wpdb` separadas.
Ejecuta carreras de dos publicaciones y dos callbacks. La duplicate key de
generation 1 se provoca liberando ambos productores sobre la misma identity. Se
observan una autoridad, un active slot, máximo una acción equivalente, un claim y
un consumo funcional; legacy queda en cero.

Workers y barreras: §8. Inputs: manifiesto firmado por hash con `run_id`, IDs y
operación enumerada; el worker rechaza rutas/FQCN dinámicos. Timeout 90 s total.
Cleanup incluso ante un hijo fallido. Evidencia: PIDs, intervalos, códigos, filas
antes/después y acciones consultadas por API. Resuelve B2.

## 11. Harness 3 — crash recovery

**Ruta:** `tests/manual/durable-retry-production-acceptance-crash-recovery-test.php`

Ejecuta hijos reales y los termina abruptamente en cinco puntos: acción externa
creada antes de asociación; claim antes de intento; intento funcional antes de
persistir resultado; resultado persistido antes de retorno de callback; commit
local incierto. Una excepción controlada es caso separado y no acredita crash.

No existen failpoints productivos. Los puntos internos “después de efecto, antes
de persistencia” no pueden interceptarse de forma determinista usando solo APIs
versionadas. Matar por polling de DB cubre claim/resultado, pero no la ventana
entre llamada externa y asociación ni entre efecto funcional y CAS sin instrumentar
dependencias. Debe decidirse si se permiten doubles de frontera en un proceso real,
un adapter decorador solo de tests o failpoints productivos. Esta ambigüedad es B3
de readiness.

Para cada punto se captura evidencia previa, se mata el PID, se inicia un nuevo
proceso, se reanuda y se exige convergencia sin doble efecto. Timeout 120 s. Logs
se conservan solo durante diagnóstico del fallo y se borran tras PASS.

## 12. Harness 4 — replay Webpay HTTP

**Ruta:** `tests/manual/durable-retry-production-acceptance-webpay-replay-test.php`

Debe arrancar o validar un servidor local, enviar POST por cURL a
`/wp-json/veciahorra/v1/payments/webpay/return`, content type de formulario y un
`token_ws` A11. El gateway Webpay real está prohibido: se necesita seleccionar un
gateway fixture mediante configuración aislada de test sin persistir opciones.

Casos: mismo token/respuesta inmediata; después de persistencia; después de
excepción A8 post-persistencia; dos POST concurrentes; return encontrado; recovery;
flujo WooCommerce. Registra status/body sin token, reconciliations, publicaciones,
generation 1, acciones pendientes, consumo y estado pedido/pago/sesión.

Ambigüedades: no hay Apache activo ni mecanismo normativo para sustituir el gateway
en una petición HTTP de otro proceso; `home_url` HTTPS y `site_url` HTTP difieren;
no se definió certificado/puerto. REST interno no resuelve el bloqueador HTTP. Esta
decisión bloquea implementación reproducible (B4). Timeout provisional 120 s.

## 13. Harness 5 — exclusión legacy/durable

**Ruta:** `tests/manual/durable-retry-production-acceptance-legacy-exclusion-test.php`

Escenarios obligatorios: authority legacy; transferida; durable programada;
indeterminate; incertidumbre AS; generation consumida; acción legacy preexistente;
ejecución concurrente; replay después de decisión durable; cero degradación.

Usa AS/MySQL/worker legacy/router/callback reales. Para una identity durable debe
ejecutar deliberadamente la acción legacy preexistente y demostrar que no adquiere
lease funcional, no procesa y no reprograma. El código actual no ofrece esa guardia,
por lo que el caso debe fallar. No puede escribirse una expectativa PASS sin la
corrección normativa de §6.

Timeout 90 s; cleanup cancela ambos grupos por APIs públicas y elimina solo
fixtures `run_id`. Resuelve la exclusión indicada en la auditoría final, una vez
corregido producto.

## 14. Orquestador y helpers provisionales

Se necesita un sexto archivo de infraestructura, no uno de los cinco ámbitos:

`tests/manual/support/DurableRetryA11Orchestrator.php`

Clase final CLI-only con argumentos `--run-id`, `--scenario`, `--manifest`,
`--timeout`; crea workers, barreras, recoge JSONL, mata residuos y ejecuta cleanup.
No modifica configuración permanente.

Worker único parametrizado:

`tests/manual/support/durable-retry-a11-worker.php`

Acepta solo operaciones enumeradas (`publish`, `callback`, `legacy`, `recovery`,
`http`) y manifiesto dentro del temp A11. Un helper de fixtures:

`tests/manual/support/DurableRetryA11Fixture.php`

crea/enumera/limpia datos por `run_id`. Estos tres archivos son necesarios para
evitar duplicación. La allowlist de tests sería 8 rutas, pero no es definitiva
porque falta la decisión productiva legacy y la sustitución HTTP del gateway.

## 15. Matriz cerrada provisional de escenarios

| ID | Precondición / acción | Procesos y barrera | Resultado y máximos | Harness |
|---|---|---|---|---|
| A11-OP-01 | materialize approved con durable on | 1, sin barrera | 1 reconciliation, gen1, acción, claim, intento | H1 |
| A11-OP-02 | durable off | 1 | 1 legacy, 0 durable | H1 |
| A11-OP-03 | AS unavailable controlado | 1 | durable external unavailable, 0 legacy | H1 |
| A11-OP-04 | callback redelivered | 2 secuenciales | 1 consumo | H1 |
| A11-OP-05 | retryable processor | 1 | 1 successor, predecessor superseded | H1 |
| A11-CON-01 | dos publish misma identity | 2 publish/release | 1 authority/gen1/action | H2 |
| A11-CON-02 | dos callbacks mismo ID | 2 callback/release | 1 claim/intento | H2 |
| A11-CON-03 | duplicate gen1 | 2 producer/release | 1 insert compatible | H2 |
| A11-CON-04 | callback generation vieja/nueva | 2 | vieja stale, nueva elegible | H2 |
| A11-CON-05 | cancel vs execute | 2 | un cierre coherente, 0 duplicado | H2 |
| A11-CR-01 | kill tras acción externa | child + recovery | pending hallada/compensada, 0 duplicado | H3 |
| A11-CR-02 | kill tras claim | child + recovery | lease respetado/recuperado, 1 intento | H3 |
| A11-CR-03 | kill tras efecto funcional | child + replay | evidencia releída, 1 efecto | H3 |
| A11-CR-04 | kill tras persistir resultado | child + redelivery | estado terminal, 0 intento | H3 |
| A11-CR-05 | commit local incierto | child + recovery | reread convergente, 0 legacy | H3 |
| A11-WR-01 | POST token nuevo | HTTP | status definido, 1 reconciliation | H4 |
| A11-WR-02 | replay inmediato | 2 POST secuenciales | already processed, 1 evidence | H4 |
| A11-WR-03 | replay post-routing exception | 2 POST | evidence estable, routing converge | H4 |
| A11-WR-04 | replay concurrente | 2 HTTP/release | 1 commit/reconciliation | H4 |
| A11-WR-05 | recovery de return encontrado | HTTP + recovery | mismo ID, 1 publicación por ejecución | H4 |
| A11-WR-06 | WooCommerce order | HTTP | pedido A11 idempotente | H4 |
| A11-EX-01 | legacy authority | 1 legacy | legacy 1, durable 0 | H5 |
| A11-EX-02 | transfer durable | 1 publish | durable 1, legacy 0 | H5 |
| A11-EX-03 | durable ya scheduled | replay | misma gen/action | H5 |
| A11-EX-04 | indeterminate | 1 | cierre/intervención, 0 schedule | H5 |
| A11-EX-05 | AS uncertain | 1 | uncertain, 0 fallback | H5 |
| A11-EX-06 | durable consumed | redelivery | 0 efecto | H5 |
| A11-EX-07 | acción legacy preexistente tras transfer | legacy worker | no claim/proceso/retry (hoy falla) | H5 |
| A11-EX-08 | legacy/durable concurrentes | 2/release | una autoridad ejecutable | H5 |
| A11-EX-09 | replay durable decision | 2 publish | 0 legacy | H5 |
| A11-EX-10 | cuatro stages legacy frente durable | 8 procesos | 0 worker legacy funcional | H5 |

Mínimo: **31 casos**. Cada caso debe declarar assertions antes de implementación:
una por precondición, efecto máximo, persistencia, AS, autoridad, resultado,
cleanup y diagnostics aplicables. El total exacto se obtiene sumando el manifiesto
de assertions del código terminado; no se inventa ahora. Aceptación exige que no
falte ninguna assertion mínima de la matriz.

## 16. Repetición HTTP exacta pendiente

Opción recomendada para corrección normativa: servidor Apache local explícitamente
iniciado fuera del harness, URL `http://127.0.0.1/Minimarket/wp-json/...`, o un
host A11 dedicado sin HTTPS. POST form-urlencoded, header `X-A11-Run-Id`, timeout
15 s, dos procesos cURL para concurrencia. Sin autenticación porque el retorno
Webpay es público; el header solo correlaciona y no autoriza. Debe prohibirse red
fuera de loopback.

No es implementable hasta definir cómo el proceso HTTP obtiene un gateway fixture
sin tocar opciones ni llamar Webpay. Alternativas: mu-plugin temporal fuera del
repo con bootstrap controlado; constante de entorno test-only leída por composition;
REST interno. Se recomienda un bootstrap test-only explícito, pero su ruta y
contrato deben normarse.

## 17. Crash injection pendiente

Una caída real usa `proc_terminate()`/terminación del child, no `exit` dentro del
proceso principal. Barreras externas pueden detener antes/después de APIs públicas,
pero ventanas internas exigen instrumentación. Alternativas:

1. decorators de puertos A7/processors en composition de test (sin wiring A10 real);
2. failpoints productivos protegidos por constante (cambio productivo indeseable);
3. proxy AS/MySQL externo (complejo y no disponible).

Se recomienda separar: crash real en fronteras observables sin cambios producto,
y pruebas deterministas de ventana con composition de test. Debe aclararse qué
nivel basta para aceptación; hoy bloquea firmas y allowlist.

## 18. Skips, diagnostics y códigos

En desarrollo local un harness puede `SKIP` con exit 2 si falta WP/WC/AS/MySQL,
servidor loopback o permiso de procesos. En certificación final cualquier skip es
fallo de infraestructura y prohíbe aceptación. Dependencia disponible pero rota:
exit 20; contradicción funcional: 10; cleanup incompleto: 30. Cero warnings,
notices, deprecations y diagnostics. La salida no contiene tokens/credenciales.

## 19. Reproducibilidad y reporte

Precondición: working tree tracked limpio, UTC, semilla registrada, puertos
preflight, DB local, cero fixture/action A11 previa. Orden: H1, H2, H3, H4, H5;
cada uno individual y luego runner conjunto. Timeouts: H1 60 s, H2 90 s, H3
120 s, H4 120 s, H5 90 s; total máximo 480 s incluyendo cleanup.

Salida JSONL por caso:

```text
{"harness":"...","case":"A11-...","pid":123,"started_utc":"...",
 "finished_utc":"...","state":"PASS","assertions":8,
 "rows":{},"actions":{},"error":null,"cleanup":"clean","exit":0}
```

Logs temporales persisten solo si falla para diagnóstico durante esa ejecución;
la recertificación final exige copiarlos fuera del repo o eliminarlos tras revisión.
Nunca se escriben en `artifacts/`.

## 20. Seguridad operacional

Guardias obligatorias: host DB y HTTP loopback; `WP_ENVIRONMENT_TYPE` local;
merchant/gateway fixture; bloqueo de DNS/red externa desde harness; emails,
webhooks y cron externo desactivados solo en bootstrap de prueba reversible;
pedidos/usuarios/sesiones/reservas con `run_id`; no tocar stock ajeno; snapshot de
opciones modificadas y restauración en `finally`; cero cobros o Webpay real.

Si existen fixtures A11 previas, cleanup dirigido antes de iniciar; nunca truncado.
El runner AS procesa solo action ID/hook/grupo A11 conocido. Cache se invalida solo
para keys creadas por el test.

## 21. Criterios cuantitativos de aceptación

- exactamente 5 harnesses de ámbito y, si se aprueban, 3 helpers/orquestador;
- mínimo 31 casos de §15;
- assertions exactas derivadas y congeladas antes del primer commit A11;
- 5/5 ámbitos ejecutados, cero SKIP de certificación;
- 0 fallos/warnings/notices/deprecations/diagnostics;
- 0 hijos vivos, temporales, acciones o fixtures A11 residuales;
- suite Durable Retry histórica `76/76`, 121 casos, 5.999 assertions intacta;
- cero cambios productivos salvo corrección normativa explícita;
- timeouts por harness y total de §19;
- cada carrera prueba solapamiento, no secuencia simulada;
- exclusión legacy/durable pasa en los cuatro stages.

## 22. Allowlist provisional, no definitiva

Rutas de tests determinables:

```text
tests/manual/durable-retry-production-acceptance-wordpress-action-scheduler-test.php
tests/manual/durable-retry-production-acceptance-multiprocess-test.php
tests/manual/durable-retry-production-acceptance-crash-recovery-test.php
tests/manual/durable-retry-production-acceptance-webpay-replay-test.php
tests/manual/durable-retry-production-acceptance-legacy-exclusion-test.php
tests/manual/support/DurableRetryA11Orchestrator.php
tests/manual/support/durable-retry-a11-worker.php
tests/manual/support/DurableRetryA11Fixture.php
```

No hay archivos históricos a modificar ni fixtures persistentes previstos. La
allowlist no es definitiva porque la corrección legacy puede requerir producto y
el bootstrap HTTP/gateway fixture no tiene ruta/contrato normado. Por ello no se
cumple el requisito para `A11 IMPLEMENTABLE`.

## 23. Ambigüedades que requieren corrección normativa

### A11-B1 — Guardia de autoridad en workers legacy

- Fuente: invariante A11 frente a `DurableCompletionWorkers.php`.
- Decisión: puerto/repository consultado, cuatro stages, estados legacy/durable/
  indeterminate, cancelación y retry.
- Alternativas: guardia por worker; wrapper orchestration; cancelar toda acción al
  transferir. Recomendación: puerto explícito inyectado y fail-closed para durable/
  indeterminate, más cancelación best-effort.
- Bloquea allowlist, firmas y aceptación: sí.

### A11-B2 — Ejecución determinista de Action Scheduler

- Fuente: WP-CLI ausente y riesgo de `QueueRunner::run()` sobre acciones ajenas.
- Decisión: autorizar `process_action(action_id)` o runner aislado.
- Recomendación: acción exacta por API pública documentada, preflight de versión.
- Bloquea criterios/runner: sí; producto: no.

### A11-B3 — Failpoints de crash

- Fuente: ventanas internas no observables desde fuera.
- Decisión: nivel de evidencia aceptable y mecanismo test-only.
- Recomendación: decorators de puertos en composition de prueba + kills reales en
  fronteras observables; ningún failpoint productivo.
- Bloquea helpers, casos CR y allowlist: sí.

### A11-B4 — HTTP y gateway fixture en otro proceso

- Fuente: Apache inactivo, URL HTTP/HTTPS inconsistente y gateway productivo.
- Decisión: servidor/URL/certificado y bootstrap seguro del gateway fixture.
- Recomendación: host loopback HTTP A11 y bootstrap test-only normado sin option.
- Bloquea H4, allowlist y seguridad: sí.

## 24. Secuencia propuesta tras corrección

1. emitir corrección normativa A11-B1–B4;
2. implementar y certificar por separado la guardia legacy si se autoriza;
3. crear fixture/helper, worker y orquestador;
4. implementar H1 y congelar formato/assertions;
5. implementar H2 con barreras;
6. implementar H3 en dos niveles de crash;
7. habilitar servidor/gateway y H4;
8. implementar H5 tras la corrección legacy;
9. ejecutar individual, conjunto, suite histórica y cleanup audit;
10. realizar una nueva aceptación final; no aceptar con SKIP.

## 25. Conclusión obligatoria

**A11 BLOQUEADO POR AMBIGÜEDAD DOCUMENTAL**

Bloqueadores: **4**: guardia legacy ausente, runner AS no normado, crash injection
no observable y HTTP/gateway fixture no definido. Alcance definitivo aún no puede
cerrarse; el alcance provisional son cinco harnesses y tres helpers de §22, más
una corrección productiva separada potencial. Entorno obligatorio: WP 7.0.2,
WooCommerce 10.8.1, AS 3.9.3, MariaDB 10.4.32/InnoDB, PHP CLI multiproceso y HTTP
loopback. Matriz: 31 casos de §15. Criterios: §21. Riesgos y exclusiones: §§19–20.
Secuencia de implementación/recertificación: §24.

No se implementó A11, no se crearon harnesses/procesos/fixtures, no se modificó
producto ni pruebas, A1–A10 permanecen intactos y no se realizó commit ni push.
