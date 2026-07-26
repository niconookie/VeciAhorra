# Certificación final de la Serie 36.5 — Administración operativa de Inventory

## 1. Veredicto

La Serie 36.5 queda **certificada integralmente**. La certificación funcional
de extremo a extremo no detectó defectos funcionales nuevos ni requirió
correcciones posteriores al commit `623d0e2`.

Al finalizar la certificación no existían cambios funcionales pendientes. Por
ello no correspondía crear un commit funcional vacío: un commit sin contenido
no habría representado una modificación verificable ni habría mejorado la
trazabilidad del alcance certificado.

## 2. Alcance certificado

La certificación cubrió:

- los read models operacionales de Inventory;
- el listado administrativo operacional;
- el detalle administrativo operacional;
- la navegación listado ↔ detalle y su retorno tipado;
- la navegación desde Inventory hacia Product y Store;
- la integración con la creación y edición existentes;
- las integraciones públicas con catálogo, ficha de Product, marketplace,
  comparación de ofertas y Cart;
- la conservación de `inventory_id` como autoridad contractual de selección
  pública.

## 3. Commits funcionales certificados

Los siguientes commits forman el alcance funcional completo de la Serie 36.5:

| Hash completo | Hash abreviado | Mensaje |
| --- | --- | --- |
| `3aecacc680e88efeb906c097ea5c00d330e4d732` | `3aecacc` | `feat(inventory): add operational admin read models` |
| `6c86b51218d172996ed5f863b4252f58e3a22528` | `6c86b51` | `feat(inventory): add operational admin list` |
| `623d0e21b8ea1fc52956cab60186e951aa414582` | `623d0e2` | `feat(inventory): add operational admin detail` |

Antes de este cierre documental, el HEAD funcional permanecía en `623d0e2`,
sin cambios tracked pendientes y con el staging vacío.

## 4. Contratos REST y seguridad

Se certificó que:

- los endpoints administrativos exigen `manage_options`;
- el nonce REST es obligatorio;
- un nonce ausente produce 401 y uno inválido produce 403;
- las respuestas administrativas usan
  `Cache-Control: private, no-store`;
- las respuestas 404 y 422 mantienen contratos uniformes;
- los fallos de persistencia se traducen a errores públicos seguros;
- no se exponen SQL, stack traces, rutas locales, nombres internos de clases ni
  mensajes crudos del backend;
- la disponibilidad se deriva mediante la política `effective-v1`;
- el inspector referencial usa agregados acotados y no realiza consultas por
  fila;
- el PATCH de edición permanece limitado a `price`, `stock` y `status`;
- Product y Store permanecen inmutables durante la edición;
- no se añadió eliminación administrativa ni CAS.

## 5. Presupuesto de consultas certificado

| Lectura | Consultas |
| --- | ---: |
| Listado no vacío | 3 |
| Listado vacío | 2 |
| Detalle sin imagen | 2 |
| Detalle con imagen no cacheada | 4 |

Las mediciones coinciden exactamente con el presupuesto del diseño aprobado y
son constantes respecto de la cantidad de filas de la página.

## 6. Listado operacional

El listado conserva la ruta administrativa canónica de Inventory y realiza
exactamente una solicitud inicial a:

`GET /veciahorra/v1/inventory/admin`

Se certificaron:

- búsqueda, filtros, orden estable y paginación determinista;
- canonicalización segura y descarte individual de parámetros inválidos;
- estados `loading`, `empty`, `error` y `success`;
- representación de Product, Store, precio, stock, estado, disponibilidad
  pública y warnings;
- navegación hacia el detalle de Inventory, Product y Store;
- ausencia de solicitudes REST durante el renderizado de filas.

El harness ejecutó 76 aserciones en cada una de las resoluciones 1440, 1024,
768 y contenedor administrativo de 375 px. No se capturaron errores de consola,
errores de página, promesas rechazadas ni overflow horizontal injustificado.

## 7. Detalle administrativo

La ruta certificada es:

`admin.php?page=veciahorra-inventory&action=view&inventory_id={id}`

`inventory_id` exige un entero positivo canónico. Se rechazan cero, negativos,
decimales, exponentes, espacios, arrays, valores duplicados y valores
truncables.

La carga inicial realiza exactamente una solicitud a:

`GET /veciahorra/v1/inventory/{id}/admin`

El detalle representa:

- identidad de la oferta;
- Product y Store asociados;
- precio, stock registrado, estado, timestamps y concurrencia
  `last_write_wins`;
- disponibilidad pública, causa primaria, bloqueos adicionales y warnings como
  conceptos separados;
- agregados de Cart, Reservations y OrderItems;
- retorno al listado mediante parámetros `return_*` tipados.

Los estados 401, 403, 404, 422 y 500, los fallos de red y el JSON inválido
producen mensajes seguros. La vista no expone Delete, CAS, PII ni mensajes
internos.

El harness ejecutó 46 aserciones en cada resolución. Registró cero
`console.error`, cero `pageerror`, cero promesas rechazadas y cero overflow. La
prueba de 375 px usa un contenedor CSS real de 375 px dentro del viewport mínimo
de Chromium.

## 8. Navegación e invalidación asíncrona

Se certificaron:

- listado → detalle → listado;
- apertura directa del detalle;
- detalle → edición → detalle;
- cancelación de edición hacia el detalle;
- navegación mediante `popstate`;
- cambio rápido entre IDs;
- invalidación cruzada entre listado y detalle;
- abortos silenciosos;
- descarte de respuestas obsoletas incluso sin `AbortController`;
- destrucción sin mutaciones posteriores;
- ausencia de listeners o inicializaciones duplicadas.

Una respuesta tardía del listado no puede sobrescribir el detalle y una
respuesta tardía del detalle no puede sobrescribir el listado.

## 9. Regresiones

La certificación ejecutó:

- 12 pruebas directas de la Serie 36.5;
- 29 pruebas de backend e integraciones administrativas y públicas;
- 8 integraciones públicas adicionales.

La cobertura incluyó creación, edición, formulario y selectores de Inventory;
Product → Inventory y Store → Inventory; administración de Product y Store;
catálogo, ficha, marketplace y comparación de ofertas; add-to-cart, Cart REST y
carrito público; Customer Panel; rutas públicas y aislamiento de búsqueda.

`inventory_id` conserva su autoridad contractual pública. Los mensajes
`PersistenceException` observados pertenecían a escenarios negativos
deliberados del harness y no se expusieron en respuestas públicas.

## 10. Calidad y estado Git

- PHP lint: 18 archivos PHP de la serie, todos sin errores.
- `git diff --check`: limpio.
- `git diff --cached --check`: limpio.
- Antes de crear este documento no existían cambios tracked pendientes.
- El staging estaba vacío.
- `artifacts/` y los 11 documentos ajenos o preexistentes bajo `docs/`
  permanecieron fuera del alcance y del commit documental.

Esta certificación documental no modifica código productivo, pruebas, contratos
REST, esquemas, migraciones ni documentos preexistentes.
