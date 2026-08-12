# Cierre final de preparación para capacitación VeciAhorra

Fecha de ejecución: 10 de agosto de 2026. Capacitación objetivo: 14 de agosto de 2026.

## 1. Executive verdict

**TRAINING READINESS CONDICIONAL**

Los recorridos principales funcionan: alta de cliente, primer panel, catálogo, minimarket con 20 ofertas e imágenes, courier, provider y administración. No queda un blocker/high funcional. La condición es operacional: el certificado XAMPP de localhost es autofirmado y expirado, por lo que cada equipo/navegador puede exigir aceptar una excepción antes de ingresar. Además, una animación decorativa antigua de la portada falla por mixed-content/CORS; no impide navegación ni operación.

La respuesta técnica a la pregunta final es: **sí, se pueden mostrar todos los roles sin pantallas funcionales vacías, siempre que Nicolás acepte previamente la excepción TLS en el navegador utilizado**.

## 2. Authorities

- Auditoría inicial: `training-readiness-visual-functional-audit-2026-08-14.md`; SHA-256 `88f19278a972600aeeeb0e47fb31b9af67575e740d5079e7aaff4eab6de45bd8`, verificado.
- Remediación P0: `training-readiness-p0-remediation-validation-2026-08-14.md`; SHA-256 `e2546056e4013352faf1a6ab64fc47df2c5582e4f05900783c5ab25fca7cd01c`, verificado.
- Evidencia final: HTTP PHP con TLS local aceptado, Chrome 151 headless con certificado inseguro permitido, HTML/DOM/JS real, WordPress/DB read-only y harnesses aislados con cleanup.

## 3. P0 regression

```text
TR-B01=PASS
TR-B02=PASS
TR-H01=PASS
TR-H03=PASS
P0_REGRESSION=PASS
```

- Registro volvió a crear y eliminar una cuenta customer correctamente.
- HTTP sigue operativo, sin 5xx ni 000 mediante el cliente validado.
- Inicio conserva Registrarse, Iniciar sesión y Catálogo.
- Customer/store/courier/provider conservan destinos propios y logout visible.
- `PREFLIGHT_TEST_USERS=0`; ningún usuario demo fue alterado.

## 4. TR-H02 remediation

Definición vinculante:

```text
ID=TR-H02
SEVERITY=HIGH
ROLE=minimarket
SURFACE=/panel-minimarket/ / listado de inventory
OBSERVED_BEHAVIOR=API entrega imagen/unidad/updated_at, tarjeta no los renderiza; Product status ausente
EXPECTED_TRAINING_BEHAVIOR=identificación visual completa de la oferta y vigencia
EVIDENCE=code
REMEDIATION_CATEGORY=frontend wiring
```

Diagnóstico: `MinimarketRepository::inventories()` une Inventory con Product y entrega `p.image_id`. `MinimarketRoutes::decorate()` ya convertía ese ID mediante `wp_get_attachment_image_url(..., 'thumbnail')` a `image_url`. La pérdida ocurría exclusivamente en `assets/frontend/js/minimarket-panel.js::loadInventory()`, que ignoraba `image_url` al construir la tarjeta.

Corrección mínima: la tarjeta crea un `<img>` desde `image_url`, acepta sólo HTTP(S), escapa contenido al usar propiedades DOM, añade alt, lazy loading y fallback “Sin imagen” ante null, URL inválida o error. CSS incorpora una miniatura 72×72 con `object-fit: contain` y adaptación móvil. La imagen continúa perteneciendo al Product; Inventory no recibió columnas ni copias.

```text
TR-H02=PASS
LOS_VECINOS_INVENTORY_ROWS=20
LOS_VECINOS_ROWS_WITH_EXPECTED_IMAGE=20/20
LOS_VECINOS_IMAGE_HTML_RENDERED=20/20
IMAGE_HTTP_SUCCESS=20
IMAGE_HTTP_FAILURE=0
EVIDENCE=HTTP_VERIFIED + BROWSER_DOM_VERIFIED
```

El navegador desplazó cada imagen lazy al viewport y comprobó `complete=true` y `naturalWidth>0`.

## 5. Visitor/new customer

```text
VISITOR_HOME=PASS (HTTP 200 + browser)
REGISTER_DISCOVERABLE=PASS
LOGIN_DISCOVERABLE=PASS
CATALOG_DISCOVERABLE=PASS
REGISTRATION_PAGE_HTTP=200
VALID_REGISTRATION=PASS
NEW_CUSTOMER_ROLE=customer
AUTO_LOGIN=PASS
POST_REGISTRATION_REDIRECT=/mis-compras/
TEST_USER_REMOVED=PASS
PREFLIGHT_TEST_USERS=0
```

Chrome completó el formulario con una cuenta identificable y desechable, llegó automáticamente a Mis compras, observó el empty-state y verificó catálogo/logout. El bloque `finally` eliminó la cuenta incluso en intentos fallidos.

## 6. Customer

```text
CUSTOMER_PANEL_HTTP=200
CUSTOMER_EMPTY_STATE=PASS (new customer)
CUSTOMER_CATALOG_CTA=PASS
CUSTOMER_LOGOUT=PASS
EXISTING_CUSTOMER_LOGIN=PASS
EXISTING_CUSTOMER_LANDING=/mis-compras/
```

No se creó compra ni se ejecutó pago.

## 7. Minimarket

```text
STORE_LOGIN=PASS
POST_LOGIN_DESTINATION=/panel-minimarket/
STORE_PANEL_HTTP=200
STORE_INVENTORY_ROWS=20
STORE_IMAGES=PASS 20/20
STORE_PRICES=PASS
STORE_STOCK=PASS
STORE_STATUS_DISPLAY=PASS
STORE_LOGOUT=PASS
STORE_ISOLATION=PASS
```

La sesión HTTP/Chrome fue la del owner de Minimarket Los Vecinos. REST devolvió exclusivamente sus 20 IDs; contraste DB encontró cero filas de otras stores. No se emitió PATCH/POST ni se manipuló precio/stock.

## 8. Los Vecinos

| Producto | PRODUCT_VISIBLE | PRICE | STOCK | IMAGE_REFERENCE | IMAGE_HTML |
|---|---|---|---|---|---|
| Coca-Cola Original 1,5 L | PASS | 2.190 PASS | 12 PASS | PASS | PASS |
| Tallarines Carozzi 400 g | PASS | 1.050 PASS | 17 PASS | PASS | PASS |
| Salsa de tomates Carozzi | PASS | 750 PASS | 18 PASS | PASS | PASS |
| Super 8 | PASS | 500 PASS | 11 PASS | PASS | PASS |

Los cuatro selects mostraron `active`. Las 20 imágenes, no sólo las oficiales, respondieron HTTP 200 y cargaron en Chrome.

## 9. Courier

```text
COURIER_LOGIN=PASS
COURIER_POST_LOGIN_DESTINATION=/panel-repartidor/
COURIER_PANEL_HTTP=200
COURIER_PANEL_VISIBLE_CONTENT=PASS
COURIER_LOGOUT=PASS
```

El preflight detectó y corrigió una regresión objetiva: el panel encolaba un JS dependiente de `window.VeciAhorra.api`, pero no inicializaba `FrontendAssets`. Con el enqueue mínimo, Chrome mostró Diego Morales, Resumen, Entregas disponibles y Mis entregas. No se aceptó ni cambió ninguna entrega.

## 10. Provider

```text
PROVIDER_LOGIN=PASS
PROVIDER_POST_LOGIN_DESTINATION=/panel-prestador/
PROVIDER_PANEL_HTTP=200
PROVIDER_PANEL_VISIBLE_CONTENT=PASS
PROVIDER_LOGOUT=PASS
```

Chrome abrió el perfil demo de José Martínez y confirmó el panel, estado Publicado y logout. No se guardó ni envió el formulario.

## 11. Admin

```text
ADMIN_LOGIN=PASS mediante sesión WordPress firmada de sólo validación
ADMIN_VECIAHORRA_MENU=PASS
ADMIN_STORES=PASS
ADMIN_PRODUCTS=PASS
ADMIN_INVENTORY=PASS
ADMIN_ORDERS=PASS
```

Chrome autenticado abrió, sin 403/5xx: dashboard VeciAhorra, `veciahorra-stores`, `veciahorra-products`, `veciahorra-inventory` y `veciahorra-orders`. No ejecutó formularios, transiciones ni ediciones. La contraseña admin no fue leída ni modificada; el harness produjo cookies temporales con APIs WordPress para la inspección read-only.

## 12. Registration page 881

```text
PAGE_881_EXISTS=PASS
PAGE_881_STATUS=publish
PAGE_881_SLUG=registro-cliente
PAGE_881_CONTENT_VALID=PASS ([veciahorra_customer_registration])
PAGE_881_HTTP=200
LINKED_DISCOVERABLE=PASS
REGISTRATION_PAGE_CODE_REPRODUCIBILITY=CODE_PROVISIONED
```

`CustomerAccessModule::ensureRegistrationPage()` provisiona la página si falta cuando un administrador carga WordPress. La página existente no se eliminó ni recreó.

## 13. HTTPS/certificate risk

```text
XAMPP_CERTIFICATE_ISSUE=self-signed and expired localhost certificate; Windows curl Schannel fails
TRAINING_IMPACT=operational workaround required before attendees navigate
```

WordPress responde correctamente: cliente PHP validado y Chrome con `acceptInsecureCerts` completaron los recorridos. Antes de capacitar, abrir `https://localhost/Minimarket/` en cada navegador que se usará y aceptar la excepción. Para evitar interrupciones, usar un único equipo del relator ya preparado o renovar/confiar el certificado antes del viernes.

## 14. Role readiness matrix

| ROLE | LOGIN | LANDING | CORE PANEL | DATA | LOGOUT | TRAINING_READY |
|---|---|---|---|---|---|---|
| Visitor/New Customer | PASS | Mis compras | empty-state + catálogo | cuenta customer | PASS | YES_WITH_WORKAROUND |
| Existing Customer | PASS | Mis compras | compras | ownership vigente | PASS | YES_WITH_WORKAROUND |
| Minimarket | PASS | Panel Minimarket | 20 ofertas + imágenes | 4/4 oficiales; isolation PASS | PASS | YES_WITH_WORKAROUND |
| Courier | PASS | Panel Repartidor | resumen/entregas | API propia | PASS | YES_WITH_WORKAROUND |
| Service Provider | PASS | Panel Prestador | perfil publicado | ownership propio | PASS | YES_WITH_WORKAROUND |
| Administrator | PASS | Admin VeciAhorra | cinco superficies | stores/products/inventory/orders | core | YES_WITH_WORKAROUND |

El workaround común es TLS local, no funcionalidad del rol.

## 15. Manual browser checklist

1. Abrir `https://localhost/Minimarket/`. `EXPECTED_VISIBLE_RESULT=` aceptar la excepción TLS una sola vez y ver Inicio.
2. Revisar Inicio. `EXPECTED_VISIBLE_RESULT=` Registrarse, Iniciar sesión y Catálogo visibles.
3. Abrir Registro. `EXPECTED_VISIBLE_RESULT=` formulario con nombre, apellido, email y dos passwords.
4. Crear usuario nuevo autorizado. `EXPECTED_VISIBLE_RESULT=` redirección automática a Mis compras.
5. Revisar `/mis-compras/`. `EXPECTED_VISIBLE_RESULT=` empty-state, Ir al catálogo y Cerrar sesión.
6. Abrir catálogo. `EXPECTED_VISIBLE_RESULT=` productos demo y navegación funcional.
7. Cerrar sesión. `EXPECTED_VISIBLE_RESULT=` retorno a Inicio.
8. Login Los Vecinos. `EXPECTED_VISIBLE_RESULT=` destino Panel Minimarket.
9. Revisar inventario. `EXPECTED_VISIBLE_RESULT=` exactamente 20 ofertas.
10. Revisar miniaturas. `EXPECTED_VISIBLE_RESULT=` 20 imágenes visibles, ninguna rota.
11. Revisar cuatro oficiales. `EXPECTED_VISIBLE_RESULT=` Coca-Cola 2.190/12, Tallarines 1.050/17, Salsa 750/18, Super 8 500/11.
12. Cerrar sesión store. `EXPECTED_VISIBLE_RESULT=` retorno a Inicio.
13. Login courier. `EXPECTED_VISIBLE_RESULT=` Diego Morales, resumen, disponibles y propias.
14. Login provider. `EXPECTED_VISIBLE_RESULT=` perfil publicado y formulario propio.
15. Login admin. `EXPECTED_VISIBLE_RESULT=` Dashboard, Minimarkets, Productos, Inventario y Pedidos accesibles.

## 16. Remaining risks

- **Operacional:** certificado localhost expirado/autofirmado; causa el veredicto condicional.
- **LOW/cosmético:** la portada solicita `Bikrimart-Delivery.json` mediante HTTP desde HTTPS; Chrome bloquea la animación por CORS/mixed-content. Inicio, enlaces y CTA siguen utilizables. Se dejó fuera por ser contenido decorativo y no TR-H02.
- La ejecución Chrome fue real/headless; la inspección humana final aún debe confirmar percepción, tamaños y facilidad de explicación.
- No se probó Webpay, compra, cambios de Inventory ni transiciones, por alcance explícito.

## 17. Git/delta

```text
DELTA_P0_ANTERIOR=preservado
DELTA_PROPIO_ESTA_TAREA=[
  assets/frontend/js/minimarket-panel.js,
  assets/frontend/css/minimarket-panel.css,
  app/Modules/Couriers/CourierModule.php,
  tests/manual/training-p0-http-role-runtime-test.php,
  tests/manual/training-final-role-preflight.py,
  docs/training-readiness-final-preflight-2026-08-14.md
]
DATASET_DELTA=[]
A11_DELTA=[]
COMMIT=NO
PUSH=NO
```
