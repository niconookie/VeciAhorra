# Auditoría final UX de roles secundarios de capacitación

Fecha normativa: 2026-08-14

Alcance: Courier, Prestador de servicios y navegación global por rol.

Veredicto: **TRAINING SECONDARY ROLES UX CONDICIONAL**.

## 1. Baseline

Se preservó el working tree recibido. `git status --short`, `git diff --stat` y `git diff --check` fueron ejecutados antes de intervenir. No hubo reset, checkout, stash, clean, commit ni push. No se modificaron A11, Panel Minimarket, pagos, pedidos, deliveries ni los dos scripts del dataset certificado.

## 2. Courier

- Usuario WordPress: `va_demo_diego`; `WP_USER_ID=209`.
- Nombre: Diego Morales; `COURIER_ID=16`; `COURIER_STATUS=approved` (mostrado como **Aprobado**).
- `COURIER_DEMO_DELIVERIES_EXPECTED=no`: el seed de capacitación crea la identidad Courier, pero no Orders ni Deliveries.
- Disponible: 0; asignada: 0; en curso: 0.
- `COURIER_TRAINING_MODE=empty_but_functional`.

El panel y sus endpoints son funcionales, pero el dataset vigente no permite demostrar estados de una entrega. Esto impide un veredicto incondicional de utilidad demostrativa.

## 3. Courier data

Hay cinco deliveries históricas ajenas al seed de capacitación. Todas son `pending`, sin courier y ligadas a Orders pagadas con fulfillment `delivery`, pero sus cuatro campos obligatorios de snapshot de destinatario están en `NULL`. La consulta normativa las excluye correctamente; no constituyen datos demo válidos ni autorizan una corrección de binding.

```text
TRAINING_COURIER_AVAILABLE=0
TRAINING_COURIER_ASSIGNED=0
TRAINING_COURIER_IN_PROGRESS=0
COURIER_DEMO_CONTENT=EMPTY_BUT_FUNCTIONAL
```

Recomendación futura, no implementada: crear por autoridad explícita tres Orders demo pagadas de fulfillment delivery, asociadas a una Store y Customer demo, cada una con snapshot completo y una Delivery: una `pending` sin courier, una `assigned` a Courier 16 y una `picked_up` a Courier 16. Deben conservar integridad Order–Store–Checkout–Delivery, timestamps normativos y cleanup/idempotencia del seed.

## 4. Courier UX

Se dejó un único `h1`: **Panel de repartidor**, con explicación breve. El resumen pasó de texto plano a tres cards. Los empty states ahora indican “No hay entregas disponibles en este momento” y “Aún no tienes entregas asignadas”. Los estados internos se localizan con fallback seguro.

Acciones realmente visibles y respaldadas por endpoints:

```text
COURIER_VISIBLE_ACTIONS=[Aceptar entrega, Marcar como retirada, Marcar como entregada]
```

No existen ni se agregaron acciones independientes `start pickup`, `start transit` o `fail`.

## 5. Provider intent

`PROVIDER_DEMO_INTENT=configured_provider`. Es una conclusión única y directa del seed y su validador: José integra los seis providers publicados, debe ser visible en el catálogo público y se siembra con plan destacado, timestamps de envío/aprobación/publicación y ficha completa. No representa onboarding nuevo.

`PROVIDER_TRAINING_MODE=configured_profile`.

## 6. Provider data binding

```text
WP_USER_ID=210
PROVIDER_ID=7
PROVIDER_STATUS=published
PROVIDER_PLAN=featured
PROVIDER_BUSINESS_NAME=Gasfitería Martínez
PROVIDER_CATEGORY=veciarregla / gasfiteria
PROVIDER_COMMUNE=San Miguel
PROVIDER_PHONE=PRESENT
PROVIDER_EMAIL=PRESENT (dominio veciahorra.test)
PROVIDER_RUT_PRESENT=yes
```

La regresión estaba en frontend: `/service-provider/me` retornaba el sobre REST, pero `hydrate()` recibía el sobre completo en vez de `response.data`. Se corrigió esa única frontera. El panel ahora muestra los valores persistidos en una ficha configurada de solo lectura; no crea ni duplica providers. Estado y plan se presentan como **Publicado** y **Plan Destacado**, sin exponer enums.

## 7. Provider onboarding/profile

`PROVIDER_ONBOARDING_STEPS_TOTAL=5`. El flujo existe para nuevos perfiles, aunque José no lo recorre:

1. `STEP_1=Elige tu plan`: visible; radios Local/Destacado; plan obligatorio por validación de servicio; persiste al guardar; siguiente acción Continuar.
2. `STEP_2=Crea tu cuenta`: nombre, RUT, correo y teléfono; campos HTML required/email y validación backend; persiste al guardar; siguiente acción Continuar.
3. `STEP_3=Presenta tu servicio`: negocio, categoría, subcategoría, comuna, experiencia, horario, descripción, cobertura y especialidades; validaciones HTML/catálogo/límites; persiste al guardar; siguiente acción Continuar.
4. `STEP_4=Verifica tu identidad`: WhatsApp, correo público, foto, urgencias y términos; required/email/imagen y validación backend; persiste al guardar; siguiente acción Continuar.
5. `STEP_5=Revisa y confirma`: resumen visible; validación integral; `Guardar perfil` persiste draft y `Enviar a revisión` transiciona a `in_review`.

No se enviaron formularios persistentes. `PROVIDER_PUBLISHED_VIEW_EXISTS=yes`: el panel muestra estado y ficha configurada. `PROVIDER_PUBLIC_PROFILE_EXISTS=yes`; catálogo público: `https://localhost/Minimarket/servicios/`. La UI abre el detalle dentro de esa superficie y no implementa URL profunda por provider.

## 8. Navigation by role

| ROLE | MIS_COMPRAS | MI_PANEL | CERRAR_SESION |
|---|---|---|---|
| customer | visible | `/mis-compras/` | separado |
| store | oculto | `/panel-minimarket/` | separado |
| courier | oculto | `/panel-repartidor/` | separado |
| provider | oculto | `/panel-prestador/` | separado |
| administrator | visible, intención actual conservada | dashboard VeciAhorra | separado |

La exclusión se centralizó mínimamente para los tres roles comerciales. Administrador conserva la navegación existente porque puede inspeccionar la superficie customer y no había autoridad para cambiarla. `ROLE_NAVIGATION_CONSISTENT=PASS`.

## 9. Browser runtime

Chrome headless real, con perfil temporal aislado:

```text
COURIER_BROWSER_RENDER=PASS
PROVIDER_BROWSER_RENDER=PASS
ROLE_NAV_BROWSER_RENDER=PASS
COURIER_JS_ERRORS=0
PROVIDER_JS_ERRORS=0
COURIER_PANEL_HTTP=200
PROVIDER_PANEL_HTTP=200
HTTP_5XX_COUNT=0
```

La excepción global conocida de `Bikrimart-Delivery.json` se observó separadamente en el preflight general. También hubo recursos externos de fuentes, Stats y Gravatar bloqueados por la red del entorno; no son errores JS de estos paneles.

## 10. Regressions

```text
CUSTOMER=PASS
STORE=PASS
COURIER=PASS
PROVIDER=PASS
ADMIN=PASS
REGISTRATION=PASS
STORE_ISOLATION=PASS
STORE_IMAGES=20/20
```

También pasan lint PHP, Courier MVP `R01-R14`, Provider MVP `S01-S18`, training P0 HTTP/role runtime y validación certificada del dataset. El usuario temporal del preflight final fue eliminado.

## 11. Remaining training risks

El único riesgo de alcance es demostrativo: Courier está funcional, autorizado y comprensible, pero vacío por decisión real del dataset. Para capacitación debe explicarse esta limitación o aprobarse posteriormente el escenario mínimo de tres deliveries descrito en la sección 3. La excepción TLS local de XAMPP y el JSON global conocido permanecen fuera de este alcance.

## 12. Git

El delta propio se limita a:

- `app/Modules/Couriers/CourierModule.php`
- `app/Modules/CustomerAccess/CustomerAccessModule.php`
- `app/Modules/ServiceProviders/ServiceProviderModule.php`
- `assets/frontend/js/courier-panel.js`
- `assets/frontend/js/service-providers.js`
- `assets/frontend/css/courier-panel.css`
- `assets/frontend/css/service-providers.css`
- `tests/manual/training-secondary-roles-ux-preflight.py`
- `tests/manual/training-final-role-preflight.py`
- `docs/training-secondary-roles-final-ux-audit-2026-08-14.md`

`GIT_DIFF_CHECK=PASS`. `COMMIT=NO`. `PUSH=NO`.
