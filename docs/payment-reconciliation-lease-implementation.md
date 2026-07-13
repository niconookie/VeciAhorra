# Lease exclusivo de conciliacion

Esta nota documenta la infraestructura implementada en VeciAhorra 28.7.4.6.2.
Complementa el diseño normativo y no habilita efectos de negocio.

## Contrato durable

El lease reside exclusivamente en `va_payment_reconciliations`:

- `lease_owner`: `worker_` seguido de 128 bits aleatorios hexadecimales;
- `lease_acquired_at`: instante UTC de la adquisicion;
- `lease_expires_at`: instante UTC hasta el que existe autoridad;
- `lease_version`: generacion monotona incrementada por cada adquisicion;
- `attempt_count`: contador monotono de reclamaciones adquiridas.

MariaDB determina vigencia y expiracion mediante `UTC_TIMESTAMP()`. El reloj del
proceso PHP no decide ninguna escritura. La duracion predeterminada es 600
segundos y el contrato interno acepta solamente enteros entre 1 y 3600 segundos.

## Operaciones

1. `acquireLease()` ejecuta un unico `UPDATE` condicional. Reclama `pending` o
   `retryable`, o recupera `processing` cuando su lease ya expiro. Cambia el
   estado a `processing` e incrementa los dos contadores.
2. `renewLease()` exige ID, estado `processing`, propietario, generacion
   coincidente y lease vigente. Reemplaza la expiracion con una ventana nueva
   calculada desde el tiempo UTC actual de la base de datos; renovaciones rapidas
   no acumulan periodos.
3. `compareAndSetStatus()` exige estado esperado `processing`, propietario y
   generacion vigentes, y lease no expirado. Solo permite `completed`, `retryable`,
   `permanent_failure` o `manual_review`, y limpia el lease al aplicar.
4. `releaseLease()` limpia el lease solo cuando coinciden propietario y
   generacion. Repetir la liberacion de la misma generacion devuelve
   `already_released`; nunca libera un lease readquirido, incluso si reutiliza el
   mismo owner.
5. Al agotar cinco intentos, un CAS separado lleva una conciliacion reclamable a
   `manual_review` sin ejecutar handlers.

Las lecturas posteriores a un `UPDATE` que afecto cero filas se usan solamente
para clasificar `busy`, `not_owner`, `expired`, `not_found` u otros resultados.
Nunca deciden si una adquisicion o transicion fue aplicada.

## Orden futuro de uso

El orden previsto es adquirir, inspeccionar evidencia previa, renovar antes de
una fase controlada si corresponde y finalmente aplicar CAS o liberar. Este
subhito no invoca inspecciones de pedidos, handlers, `payment_complete()`,
Payments, Deliveries, stock, rutas HTTP, Transbank ni cualquier otro efecto de
negocio.

## Limites

No se mantiene una transaccion SQL abierta durante trabajo externo. Un proceso
que exceda la expiracion pierde autoridad aunque siga ejecutandose. Una etapa
futura debe inspeccionar evidencia durable antes de repetir efectos luego de
recuperar un lease expirado. La infraestructura no se conecta todavia al retorno
Webpay ni a workers automaticos.
