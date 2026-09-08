# Carrito agregado y retiro cercano — 0.3.23

Base: `65bf85f8fc691118aba04545f5f01f89d1b1fca6`. Schema: `0.40.0`.

El borrador existente se reutilizó desde esa base. La comparación de modalidad productiva estaba presente; faltaba una prueba que atravesara el callback REST de Checkout con todas las demás precondiciones satisfechas. La nueva prueba rechaza ambas contradicciones, comprueba las tablas completas y materializa ambas modalidades coincidentes. Al quitar únicamente la comparación en `materialize`, falla con `MODALITY_CONTRADICTION_ACCEPTED_pickup_VS_delivery`. El código se restaura antes de volver a ejecutar la base.

El carrito comparte una fila bloqueada entre ediciones, cambios de modalidad/zona, aceptación de propuesta y Checkout. Las mutaciones exigen versión; Checkout usa `expected_cart_version`. Las líneas legacy se adoptan transaccionalmente sin backfill global. Checkout consume el agregado y una lectura posterior obtiene un carrito nuevo. La regresión REST también conserva HTTP 201 y los campos `id`/`created` al agregar una línea nueva.

`CheckoutFeeConfiguration` conserva la autoridad del mínimo (valor predeterminado: $8.000). `CheckoutFeeCalculator` calcula cargos y valida montos CLP. JavaScript recibe el mínimo configurado. La alternativa solo se presenta con usuario autenticado, comercio habilitado, carrito activo no vacío y modalidad delivery bajo ese mínimo. Exactamente $8.000 autoriza despacho.

La búsqueda conserva productos y cantidades, exige una oferta completa en un solo minimarket activo, aprobado y perteneciente a la zona de `CurrentSector`. El orden es distancia Haversine ascendente y, en empate, identificador de tienda ascendente. Precio, distancia e identificadores enviados por el cliente no son autoridades. La propuesta muestra precios y subtotales comparativos y expira a los cinco minutos. La aceptación vuelve a comprobar carrito, ofertas, stock, precio, coordenadas, estado y territorio bajo bloqueo. Una falla en la segunda inserción revierte todas las líneas. El resultado es pickup y despacho $0; la búsqueda y aceptación no crean reservas, Checkout, Orders ni Payment Session.

La dirección escrita y las coordenadas de búsqueda solo viven en memoria del navegador y en el request de búsqueda; no se almacenan en la propuesta ni en historial. Las propuestas guardan distancia aproximada, hash de coordenadas de la tienda y ofertas para revalidación. Los campos se borran al cancelar o aceptar. Sin clave se mantiene geolocalización por acción expresa y entrada manual. La clave de navegador no se incluye en Git ni en valores predeterminados; se configura en **Retiro cercano**, restringida a dominios y APIs necesarias. Los minimarkets sin coordenadas quedan pendientes y fuera del ranking, conservando estado y zonas.

La geocodificación usa el servicio de navegador documentado por Google y carga el SDK únicamente al buscar una dirección: [Geocoding Service](https://developers.google.com/maps/documentation/javascript/geocoding), [Maps JavaScript API loader](https://developers.google.com/maps/documentation/javascript/load-maps-js-api). Las pruebas sustituyen Google y la geolocalización con dobles; no consultan esas APIs.

## Validación reproducible

No ejecutar estas pruebas contra WordPress del sitio. El runner carga clases del plugin y archivos base de WordPress, crea su propia base `va_cart_test_<aleatorio>` en MariaDB local y la elimina al salir. `VA_CART_TEST_PORT` selecciona una instancia desechable; no carga `wp-load.php` ni `wp-config.php`. Los IDs de fixtures comienzan sobre 1000.

| Suite | Casos | Aserciones |
| --- | ---: | ---: |
| Agregado, Checkout y seis concurrencias InnoDB | 25 | 201 |
| Retiro cercano y cuatro concurrencias InnoDB | 11 | 178 |
| Contrato de cargos | 1 | 35 |
| Contrato de habilitación de despacho | 1 | 20 |
| Transporte, DOM/Google y vistas PHP en Chrome | 4 | 54 |
| Total por ejecución completa | 42 | 488 |

Las concurrencias de B cubren aceptación/aceptación, aceptación/edición, aceptación/Checkout y reducción de stock mientras aceptación espera el bloqueo de Inventory. Como la aceptación no reserva ni vende, Checkout mantiene su revalidación posterior de disponibilidad.

```powershell
$env:VA_CART_TEST='1'
$env:VA_CART_TEST_PORT='<puerto de MariaDB desechable>'
php tests/manual/cart-aggregate-mysql-test.php
$env:VA_PICKUP_TEST='1'
php tests/manual/cart-aggregate-mysql-test.php
Remove-Item Env:VA_PICKUP_TEST
php tests/manual/checkout-fees-contract-test.php
php tests/manual/checkout-delivery-flags-contract-test.php
$env:VA_PICKUP_BROWSER='1'
python tests/manual/cart-aggregate-browser-test.py
```

El navegador requiere Selenium y ChromeDriver local compatible (`VA_CHROMEDRIVER` puede seleccionar el binario); usa un perfil temporal y un servidor local vacío. Las solicitudes REST y Google se simulan y el acceso externo del navegador se bloquea mediante proxy local sin servicio.

`VA_PROOF_PLUGIN_ROOT` permite repetir las mismas suites contra el runtime extraído del ZIP, manteniendo los scripts de pruebas fuera del paquete.

Los runners `cart-aggregate-mutations.py` y `pickup-proposal-mutations.py` aceptan exclusivamente un borrador llamado `cart-proximity-work`, restauran el código y se detienen si una mutación sobrevive. Cada matriz detecta 16/16 mutaciones. Las fallas esperadas de mutantes y los reintentos intermedios no se suman al contador de pruebas finales. No se modificaron pagos/refunds ni se ejecutaron proveedores reales, producción o despliegue.
