# Intento durable WooCommerce y materializacion de conciliacion

VeciAhorra 28.7.4.6.3.1 conecta el intento WooCommerce con evidencia Webpay
sin ejecutar efectos de negocio.

## Identidades

- `site_scope = wp-blog:{blog_id positivo}` se construye exclusivamente con
  `WordPressSiteScope`.
- `payment_attempt_id = attempt_{128 bits aleatorios hexadecimales}` se genera
  antes de `createSession()` y cambia para cada intento remoto nuevo.
- El pedido conserva solo el attempt ID, el ID interno del origen y el gateway
  bajo claves privadas `_veciahorra_*_v1`.
- `transaction_reference_v1 = "va-wp-v1-" + financial_fingerprint_v1`.
  Es determinista, no secreto y no depende de token, lease o reconciliation ID.

## Orden y recuperacion

1. Se crea el origen con token nulo y se asocia al pedido.
2. Se ejecuta el `createSession()` ya existente.
3. El hash del token se vincula con un `UPDATE` condicionado por ID, attempt ID,
   token nulo y origen vigente. Un segundo valor no puede sobrescribirlo.
4. El retorno ya validado localiza el origen por token hash y completa las
   columnas financieras de la misma fila `webpay_returns` reclamada.
5. Se crea una conciliacion `pending`. Los indices unicos de fingerprint,
   retorno y origin key convierten retornos repetidos en la misma identidad.

Si create falla, el origen queda no vinculado y no existe resultado financiero.
Si el bind falla, no se crea una asociacion alternativa. Si el proceso cae tras
materializar evidencia financiera, `resume()` reutiliza esa fila y crea o
reconoce una unica conciliacion. Evidencia incompatible produce conflicto y
nunca overwrite.

Este subhito no llama `payment_complete()`, no marca pedidos como pagados y no
crea Payment, Delivery, fulfillment, stock, correos, rutas ni hooks propios.
