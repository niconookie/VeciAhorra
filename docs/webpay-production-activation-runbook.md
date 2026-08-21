# Runbook de activación Webpay productivo

## Preflight

1. Mantener `VECIAHORRA_WEBPAY_PRODUCTION_ENABLED` ausente o distinto de `1`.
2. Confirmar versión desplegada, SDK 5.1.0 y esquema vigente sin migraciones
   pendientes.
3. Inyectar commerce code y API key desde el gestor de secretos.
4. Configurar el origen público HTTPS estable, nunca localhost ni un túnel.
5. Verificar DNS, certificado, cadena TLS, reloj, proxy y ruta REST canónica.
6. Confirmar Action Scheduler, workers durables, logs redactados, métricas y
   alertas.
7. Ejecutar pruebas unitarias sin red y la certificación sandbox.
8. Inspeccionar de forma administrativa
   `woocommerce_veciahorra_webpay_plus_settings`: si el indicador `mode` es
   `production`, mantener el gate cerrado, reconciliar sus intentos, respaldar
   sólo identificadores y evidencia no secreta, retirar la API key histórica por
   un operador autorizado y confirmar que el runtime productivo funciona con
   `wp_options` ausente. Nunca imprimir el valor de la clave.

## Bloqueo previo a la activación

```text
PILOT_PRODUCTION_ACTIVATION=BLOCKED_UNTIL_ROTATION_DRAIN_AUTHORITY_RESOLVED
```

El código puede instalarse y verificarse con el gate cerrado, las pruebas sandbox
pueden continuar y las credenciales productivas pueden prepararse mediante el
sistema de despliegue sin abrir el gate. Hasta resolver el bloqueo anterior queda
prohibido abrir el piloto, ejecutar el primer cobro productivo, establecer
`VECIAHORRA_WEBPAY_PRODUCTION_ENABLED=1`, rotar la credencial o retirarla.

Una aceptación genérica de riesgo o la autorización de una ventana temporal no
levantan este bloqueo. Primero debe existir una autoridad total y verificable que
demuestre cero sesiones productivas abiertas, estados inciertos, retornos
pendientes, reconciliaciones pendientes o manuales, retries activos y leases
vigentes que puedan depender de la credencial. Después debe realizarse una
auditoría separada y autorizarse expresamente la activación productiva.

## Activación

1. Establecer manualmente el gate en `1` sólo en el entorno autorizado.
2. Reiniciar o recargar el proceso por el procedimiento normal de despliegue.
3. Confirmar que el gateway está disponible sin mostrar credenciales.
4. Ejecutar una validación productiva controlada sólo con autorización financiera.
5. Verificar una sola sesión, retorno, reconciliación y materialización.
6. Abrir el piloto gradualmente.

## Monitoreo y detención

Cerrar inmediatamente el gate ante errores de TLS/configuración, aumento de
sesiones ambiguas, retornos sin contexto, conciliaciones en revisión manual,
discrepancias financieras, duplicidad o degradación del scheduler. Cerrar el gate
impide sesiones nuevas; no elimina ni invalida intentos existentes.

## Rollback

1. Cambiar manualmente el gate a un valor distinto de `1`.
2. No cambiar a integración o mock.
3. Mantener bundle y endpoint productivos para procesar retornos existentes.
4. Drenar y reconciliar todas las sesiones abiertas o inciertas.
5. Revertir código sólo si la versión anterior entiende las autoridades abiertas.
6. Retirar secretos únicamente después del drenaje completo.

## Rotación de credenciales

No se rota con sesiones abiertas. El orden obligatorio es: cerrar gate, impedir
nuevas sesiones, reconciliar y demostrar cero intentos abiertos o inciertos,
cambiar el bundle, validar la configuración y reabrir manualmente. No hay claves
simultáneas, versionado de claves, recuperación desde hashes ni fallback.

### Autoridad de drenaje disponible

Las autoridades inspeccionadas son las tablas con prefijo WordPress y prefijo
`Config::TABLE_PREFIX`: `payment_sessions`, `payment_origin_contexts`,
`payment_reconciliations` y `durable_retry_schedules`.

- Sesiones abiertas o inciertas: `pending`, `create_processing`,
  `create_retryable`, `create_ambiguous` y `ready`. Los timestamps relevantes
  son `created_at`, `updated_at`, `expires_at`, `create_started_at`,
  `create_remote_started_at` y `create_lease_expires_at`.
- Reconciliaciones no drenadas: `pending`, `processing`, `retryable` y
  `manual_review`. Deben revisarse `lease_owner`, `lease_acquired_at`,
  `lease_expires_at`, `last_attempt_at`, `last_error_at` y `updated_at`.
- Retries activos: filas de `durable_retry_schedules` con `active_slot=1` o
  estado `dispatching`, `scheduled` o `claimed`; deben revisarse
  `scheduled_for`, `claimed_at` y `updated_at`.
- `payment_origin_contexts.environment='production'` es la autoridad que marca
  el ambiente de un intento y `expires_at` delimita su vigencia.

La versión actual no conserva una relación total y verificable desde cada
`payment_session` y cada retry durable hasta un `payment_origin_contexts` con
`environment`. Por ello no existe hoy una consulta read-only capaz de demostrar
que **todos** los contadores anteriores pertenecientes a producción son cero.

```text
ROTATION_DRAIN_PROCEDURE=BLOCKED_BY_MISSING_AUTHORITY
```

La rotación queda prohibida. Antes de la primera apertura productiva, el
responsable de pagos y el responsable de operaciones deben autorizar una ventana
temporal no menor que el mayor `expires_at` observado más el drenaje de leases y
retries activos. El criterio literal para una rotación futura será: cero sesiones
abiertas o inciertas, cero reconciliaciones no drenadas, cero retries activos y
cero leases vigentes, todos trazados inequívocamente a `environment=production`.
