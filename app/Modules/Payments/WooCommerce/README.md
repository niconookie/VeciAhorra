# Adaptador WooCommerce Webpay Plus

El gateway `veciahorra_webpay_plus` adapta el checkout clasico de WooCommerce a
`PaymentGatewayInterface` y `WebpayPaymentGateway`. No utiliza directamente el SDK de
Transbank y no confirma pagos ni modifica pedidos, stock o entidades de negocio.

## Continuacion POST

`process_payment()` guarda temporalmente la URL validada y `token_ws` en la sesion
estandar de WooCommerce. La clave es un identificador aleatorio opaco de 128 bits; la
URL interna incluye solo ese identificador, el ID del pedido y su clave WooCommerce.
Los datos vencen a los 10 minutos y se eliminan antes de renderizar el formulario POST.
El acceso comprueba pedido, clave, total, moneda, necesidad de pago, vencimiento y host
oficial correspondiente al modo. No se escriben tablas, metadatos de pedido ni modelos
Order, Payment, Delivery o Reservation de VeciAhorra.

WooCommerce puede persistir su sesion de cliente en `wp_woocommerce_sessions`; esta es
la unica persistencia adicional utilizada para transportar el token. El riesgo residual
es el almacenamiento breve del token en esa sesion. Se minimiza con vencimiento corto,
acceso ligado a la cookie y clave del pedido, consumo unico y eliminacion inmediata.

## Idempotencia y limites

Mientras la continuacion siga pendiente en la misma sesion WooCommerce, llamadas
repetidas para el mismo pedido, clave, total y moneda reutilizan el flujo sin invocar de
nuevo a Webpay. Tras consumir o perder la sesion no existe idempotencia durable: un
hito posterior debera resolver nuevos intentos y retorno financiero sin reutilizar
tokens consumidos.

Este adaptador implementa el gateway PHP clasico. No declara soporte para WooCommerce
Checkout Blocks.
