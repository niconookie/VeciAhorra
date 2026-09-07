# Resolución financiera de entregas devueltas

`CheckoutRefundPolicy` es la autoridad única. Acepta los totales originales y acumulados explícitos; la operación financiera siempre los proporciona. Los argumentos opcionales mantienen compatibilidad con los consumidores anteriores. No se prorratean cargos: las operaciones parciales devuelven productos y el cierre devuelve los remanentes exactos. La suma se comprueba antes de cualquier desbordamiento entero.

El administrador usa la incidencia existente y el POST REST `admin/deliveries/{id}/cancel-and-refund`, protegido por autorización WordPress y nonce REST de la sesión. El servicio vuelve a exigir administrador, versión, nota y confirmación. Reintentar un rechazo requiere una nueva clave y `retry_of` igual al intento fallido actual. El replay conserva clave y contenido, incluso después de perder una respuesta.

La operación bloquea Checkout, comprueba las relaciones persistentes, la recepción física y el pago confirmado, y guarda la intención y un propietario con lease. Otra transacción registra el comienzo remoto mediante CAS. La llamada al gateway ocurre sin transacción SQL abierta. Un resultado confirmado finaliza ledger, refund, Order cancelada, Delivery `return_closed`, agregado financiero de Checkout y tracking en una sola transacción. El estado original de Payment y los demás pedidos se conservan.

Los eventos de intento sólo se insertan. Un lease expirado sin comienzo remoto produce fallo definitivo al consultar la misma solicitud; con comienzo remoto produce incertidumbre. No hay cron ni reintentos automáticos. Una excepción de red, respuesta ambigua o fallo al guardar la confirmación no autoriza otra llamada. Si la base de datos no permite guardar siquiera la incertidumbre, permanece el marcador remoto durable y el replay posterior lo cierra como incierto. Su resolución excepcional queda fuera de esta fase.

El SDK local bloqueado en composer.lock ofrece `refund(token, amount)`. El adaptador acepta NULLIFIED con código cero y monto entero exacto; REVERSED sólo permite completar una devolución del importe íntegro del pago. Un REVERSED recibido para una devolución parcial queda incierto. Las excepciones del SDK se descartan sin encadenarlas, pues pueden contener el token en una URL. No se realizaron llamadas reales.

Un Checkout con registros contables antiguos no atribuibles a una devolución confirmada de una Order queda bloqueado para revisión: no se reconstruyen asignaciones. No existe módulo productivo de liquidaciones a minimarkets. La Order cancelada deja de pertenecer al conjunto de Orders entregadas; se conserva intacta una Order correctamente entregada. Esto no certifica un sistema de pagos a comercios que aún no existe. Las búsquedas de `payable` existentes se refieren al cobro al cliente.

El cliente recibe una proyección con lista explícita de campos, limitada por propiedad del Checkout y de la Order. El monto pendiente se etiqueta como solicitado, y sólo una confirmación se etiqueta como devuelto. La administración conserva el historial y la advertencia de regularización física pendiente. Inventario y reservas no se modifican.

## Pruebas locales

Ejecutar en una copia desechable con el vendor del paquete bloqueado, nunca cargando `wp-load.php` ni `wp-config.php`:

```powershell
& C:\xampp\php\php.exe tests/manual/refund-policy-test.php
python tests/manual/refund-policy-mutations.py
& C:\xampp\php\php.exe tests/manual/refund-gateway-test.php
$env:VA_REFUND_TEST='1'
& C:\xampp\php\php.exe tests/manual/return-refund-mysql-test.php
$env:VA_REFUND_MUTATIONS='1'
python tests/manual/return-refund-mutations.py
```

MariaDB escucha exclusivamente en 127.0.0.1:3306 para estas pruebas. El arnés crea y elimina `va_refund_test_<hex>`, usa esquemas productivos InnoDB y Orders desde 1001. El gateway simulado abre otra conexión para comprobar que la intención y el comienzo remoto ya están confirmados; registra llamadas sin tokens. Dos procesos PHP esperan simultáneamente en el mismo lock de Checkout, verificado en PROCESSLIST, antes de liberar la carrera. Triggers provocan fallos reales de persistencia.

Las pruebas de interfaz JavaScript usan dobles DOM/red en V8: no constituyen certificación de navegadores. Los callbacks REST usan WP_REST_Request y WP_REST_Response nativos con identidades simuladas; no sustituyen una prueba de autenticación WordPress de extremo a extremo. El paquete extraído ejecuta nuevamente los servicios reales usando `VA_PROOF_PLUGIN_ROOT` y su autoloader Composer.

Las diez mutaciones de política y las catorce financieras restauran siempre los bytes originales. Las mutaciones son defectos deliberados en una copia desechable; su detección es un resultado satisfactorio del arnés.
