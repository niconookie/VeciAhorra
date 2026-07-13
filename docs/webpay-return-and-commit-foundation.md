# Webpay Return and Commit Foundation

## Endpoint

`POST /wp-json/veciahorra/v1/payments/webpay/return` es el contrato principal
esperado y acepta formularios `application/x-www-form-urlencoded`. El mismo
path admite `GET` como compatibilidad defensiva porque un retorno directo con
query string fue reproducido en Webpay Integration, aunque GET no es el
contrato oficial documentado por Transbank.

POST lee exclusivamente parametros del cuerpo y GET exclusivamente parametros
de query. Ambos delegan al mismo `WebpayReturnController` y
`WebpayReturnService`; no existe una segunda logica de commit ni idempotencia.

- Retorno normal: `token_ws`.
- Aborto: `TBK_TOKEN` y, opcionalmente, `TBK_ORDEN_COMPRA` y
  `TBK_ID_SESION`.
- Ambos tokens o ninguno producen `400 invalid_webpay_return` sin invocar el
  gateway.

Los campos no reconocidos se ignoran y nunca se consideran confiables.

El retorno GET implica que el token puede quedar en el historial del navegador,
access logs o infraestructura intermedia. VeciAhorra no registra ni refleja la
URL, query string o token completo. Este riesgo residual debe validarse de
nuevo especificamente antes de habilitar produccion.

## Resultados

El resultado distingue `approved`, `rejected`, `aborted`, `inconsistent`,
`gateway_error` y `already_processed`. Un resultado procesado usa HTTP 200;
una inconsistencia usa 409 y un error tecnico del gateway usa 502.

La respuesta incluye una referencia SHA-256 parcial, nunca `token_ws`, e
indica siempre `business_state_updated: false`. Los datos financieros se
normalizan a tipos internos y sólo conservan los ultimos cuatro digitos de la
tarjeta cuando Transbank los entrega.

## Validacion e idempotencia

Un commit aprobado exige `AUTHORIZED`, `response_code === 0`, monto CLP entero
positivo y coincidencia exacta de monto, `buy_order` y `session_id` con la
sesion interna. El contexto se resuelve desde una `PaymentSession` VeciAhorra
existente o, para una transaccion iniciada por WooCommerce, desde un transient
temporal indexado exclusivamente por el hash SHA-256 del token. Este transient
solo conserva ambiente, commerce code, referencias financieras, monto y
expiracion; no contiene el token, API Key, identificadores del pedido ni datos
personales, y no depende de la sesion WooCommerce durante el retorno.

Ambas fuentes delegan al mismo `WebpayPaymentGateway::commit()` y a la misma
conciliacion e idempotencia. El contexto WooCommerce se elimina despues de un
resultado final y se conserva ante un error tecnico reintentable. Un codigo
financiero distinto de cero es rechazo, no error tecnico.

`va_webpay_returns` conserva exclusivamente el hash SHA-256 del token, la
sesion asociada, el estado tecnico y el resultado normalizado. Su indice unico
sobre `token_hash` hace atomica la toma del retorno. Los errores de transporte
quedan reintentables; los resultados finales no vuelven a ejecutar commit.

El aborto nunca ejecuta `commit()` y valida orden/sesion cuando Transbank los
incluye.

## Pruebas

Pruebas sin red:

```powershell
php tests/manual/webpay-return-foundation-test.php
```

Prueba sandbox, sólo después de crear una sesion real mediante
`POST /veciahorra/v1/payments/session`, completar el formulario de Integracion
y capturar localmente `token_ws`:

```powershell
$env:VECIAHORRA_RUN_WEBPAY_RETURN_SMOKE='1'
$env:VECIAHORRA_WEBPAY_RETURN_TOKEN='<token_ws local>'
php tests/manual/webpay-return-sandbox-test.php
```

El token debe eliminarse de la variable de entorno al terminar. La prueba no
lo imprime ni lo persiste.

## Limites

No se renderiza una pagina final y no se ejecutan efectos de negocio. **Un
resultado financiero aprobado todavia no modifica Order, Payment ni Delivery.
Esa integracion pertenece a un hito posterior.** Tampoco cambia reservas,
inventario, carrito o Checkout. El siguiente hito debe consumir el resultado
normalizado dentro del flujo transaccional de negocio.
