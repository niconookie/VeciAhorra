# Soporte Webpay Plus productivo

El soporte productivo está cerrado por defecto. La configuración productiva no
usa opciones de WordPress ni los nombres legacy empleados por sandbox.

## Autoridad de configuración

El proceso PHP debe recibir, desde el sistema de despliegue o gestor de secretos:

```text
VECIAHORRA_PAYMENT_GATEWAY=webpay
VECIAHORRA_WEBPAY_ENVIRONMENT=production
VECIAHORRA_WEBPAY_PRODUCTION_ENABLED=1
VECIAHORRA_WEBPAY_PRODUCTION_COMMERCE_CODE=[SECRET_STORE]
VECIAHORRA_WEBPAY_PRODUCTION_API_KEY=[SECRET_STORE]
VECIAHORRA_PUBLIC_ORIGIN=https://host-productivo-autorizado.example
```

Los nombres productivos se suministran exclusivamente como variables de entorno
del proceso; las constantes PHP no son una autoridad productiva. Los secretos productivos no se ingresan en el panel WooCommerce,
no se guardan en `wp_options` y no se incluyen en logs o mensajes de error.

`VECIAHORRA_PUBLIC_ORIGIN` contiene sólo `https://` y un nombre DNS ASCII. El
host se normaliza a minúsculas y se admite un único `/` final. No admite puerto,
path, query, fragmento, credenciales, localhost, loopback, IP privada ni `.local`.
La aplicación deriva exactamente:

```text
https://host-productivo-autorizado.example/wp-json/veciahorra/v1/payments/webpay/return
```

Un túnel temporal puede servir para una prueba sandbox autorizada, pero nunca es
un origen productivo.

## Comportamiento

- `integration` conserva constantes, variables legacy y opciones WooCommerce.
- `production` lee exclusivamente el bundle productivo.
- El gate acepta únicamente el valor literal `1`.
- Con el gate cerrado no se construye el SDK para crear una sesión nueva.
- Un retorno iniciado previamente puede ejecutar `commit/status` con el bundle
  todavía configurado, aunque el gate de nuevas sesiones esté cerrado.
- No existe fallback de producción a integración o mock.
- El SDK usa `Options::ENVIRONMENT_PRODUCTION` y timeout de 30 segundos.
- Las URLs de pago se validan contra el host productivo de la allowlist.

La primera versión no admite múltiples claves ni selección por sesión. Por ello,
una credencial no puede rotarse mientras existan sesiones productivas abiertas o
inciertas.

## Prerrequisitos externos

Antes de certificar runtime se requieren credenciales Transbank productivas, DNS
y TLS públicos estables, proxy correctamente configurado, Action Scheduler,
monitoreo y autorización para una validación financiera controlada.
