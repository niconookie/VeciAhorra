# VeciAhorra 28.7.0 — Public Checkout Functional Design

## 1. Propósito

Este documento define la experiencia funcional del checkout público antes de
implementar interfaz, contratos o persistencia adicional. El checkout transforma
el carrito de una identidad en una operación global, separa sus productos en un
pedido por minimarket, reserva stock durante 15 minutos y conduce al cliente
hasta un resultado de pago recuperable.

El checkout no es una fuente alternativa de precios, stock ni totales. Cart,
Checkout, Reservations, Orders y Payments conservan la autoridad sobre esos
datos. Delivery solo participa cuando el método validado sea despacho.

Quedan fuera de esta fase de diseño:

- implementar UI, JavaScript, PHP, tablas, migraciones o configuración visual;
- definir costos, zonas, horarios o promesas de despacho;
- crear endpoints públicos nuevos o cambiar contratos REST;
- seleccionar un proveedor o método de pago concreto;
- implementar seguimiento público de Delivery.

## 2. Capacidades existentes y brechas

La implementación deberá apoyarse en la arquitectura existente, sin asumir que
todas sus capacidades son hoy públicas:

- Cart identifica por `user_id` al usuario autenticado y por `session_id` al
  invitado, y conserva cantidad y `unit_price_snapshot`.
- Checkout dispone actualmente de validación e inicialización, vuelve a validar
  carrito, inventario, producto, stock y precio, crea reservas y separa pedidos
  por `minimarket_id`.
- Reservations bloquea stock y utiliza una duración de 15 minutos.
- Orders conserva precio unitario y subtotal derivados del snapshot validado.
- Payments soporta creación, asociación con pedidos, sesión de proveedor y
  confirmación idempotente en servicios internos. Sus rutas actuales requieren
  administración y no constituyen una API pública para el navegador.
- Customer Panel permite a usuarios autenticados consultar sus pedidos y su
  estado de pago.
- Delivery exige un pedido pagado y sus rutas actuales son administrativas.
- La inicialización actual de Checkout rechaza invitados. El soporte funcional
  para invitado descrito aquí es una brecha de implementación posterior, no una
  capacidad que este documento dé por existente.

Las fases posteriores deben decidir cómo exponer de forma segura las operaciones
que falten. Este documento no asigna nombres ni payloads a endpoints inexistentes.

## 3. Principios funcionales

1. Existe un checkout global por intento observable del cliente.
2. El checkout global puede producir varios pedidos, uno por minimarket.
3. El total global es la suma exacta de los subtotales válidos de todos los
   productos de todos los pedidos, antes de costos de despacho.
4. Una sola decisión de método de entrega aplica al checkout global en 28.7,
   salvo que una fase futura defina explícitamente un modelo mixto.
5. Precio, stock, cantidad efectiva, subtotales, total y elegibilidad se calculan
   y validan definitivamente en backend.
6. Una operación incierta se recupera; no se repite a ciegas.
7. La UI representa estados del dominio y nunca interpreta un timeout como
   fracaso definitivo.

## 4. Datos del cliente

Datos siempre solicitados o confirmados:

- nombre;
- apellido;
- teléfono;
- correo electrónico;
- método de entrega: `pickup` o `delivery`.

Datos solicitados únicamente si el método disponible y elegido es `delivery`:

- dirección;
- comuna;
- referencia;
- observaciones.

Dirección, comuna y los demás campos de despacho deben ocultarse y quedar fuera
del envío cuando se seleccione pickup. Referencia y observaciones son texto
auxiliar, no instrucciones capaces de modificar precios, productos, cantidades,
destino comercial ni reglas de negocio. La obligatoriedad exacta y límites de
longitud se fijarán en 28.7.2 y se validarán tanto en UI como en backend.

## 5. Invitado y usuario autenticado

### Invitado

- Mantiene la identidad opaca de sesión ya utilizada por Cart.
- Introduce sus datos de contacto en el checkout.
- Debe poder recuperar la operación ambigua dentro de la misma identidad y con
  una referencia pública no predecible cuando esa capacidad sea implementada.
- No obtiene acceso a pedidos ajenos ni a Customer Panel autenticado.
- La implementación deberá cerrar la brecha actual que impide inicializar
  Checkout sin `user_id`, sin convertir un ID recibido del navegador en autoridad.

### Autenticado

- La identidad procede de la sesión WordPress, nunca de un `user_id` del payload.
- El formulario puede precargar datos conocidos, pero el cliente debe poder
  confirmarlos o editarlos según las reglas futuras.
- Tras el pago, Customer Panel es el punto natural para consultar pedidos y
  estado, respetando su aislamiento actual por usuario.

En ambos casos, cambiar de identidad durante el proceso obliga a abandonar la
vista anterior y volver a cargar/validar el carrito de la identidad vigente.

## 6. Flujo de extremo a extremo

1. **Entrada desde Cart (`editing`).** Se carga el carrito vigente desde backend.
   Una copia visual previa no es suficiente.
2. **Edición de datos.** Se recopilan contacto y método de entrega. Delivery no
   se muestra si el resumen validado no cumple RB-CHK-001.
3. **Prevalidación (`validating`).** Backend reobtiene el carrito por identidad,
   valida cada ítem y calcula el total global.
4. **Resumen previo.** La UI presenta productos agrupados por minimarket,
   cantidad, precio congelado, subtotal, total por minimarket, total global y,
   cuando exista reserva, tiempo restante.
5. **Confirmación.** Justo antes de crear pedidos, backend vuelve a validar el
   carrito, el total global y el método de entrega.
6. **Creación (`creating_orders`).** Se bloquea stock, se crean reservas y se crea
   un pedido por minimarket. Si falla el conjunto, se revierte/libera lo creado;
   no se presenta éxito parcial como checkout válido.
7. **Reserva (`reserved`).** Se informa la expiración común efectiva, basada en
   la más temprana de las reservas.
8. **Pago (`waiting_payment` / `payment_pending`).** Una operación de pago agrupa
   los pedidos del checkout. Crear o recuperar la sesión del proveedor no debe
   duplicar el pago.
9. **Confirmación.** La confirmación válida consume reservas, confirma stock y
   marca pedidos pagados mediante la orquestación existente.
10. **Resultado (`payment_confirmed` o estado recuperable).** Se muestra el estado
    real consultado al backend. Para autenticados se ofrece Customer Panel.
11. **Delivery.** Solo después de cumplir las condiciones de la sección 13 se
    prepara o crea el flujo de despacho. Pickup no lo crea.

## 7. Separación por minimarket y modelo agregado

Los ítems validados se agrupan por `minimarket_id`, en orden determinista. Cada
grupo genera exactamente un pedido con:

- sus productos y reservas;
- su total por minimarket;
- la misma referencia al checkout/pago global cuando esa asociación sea
  implementada;
- una expiración compatible con la reserva global.

El checkout global conserva la relación conceptual:

```text
checkout global
  ├─ pedido minimarket A ─ reservas A
  ├─ pedido minimarket B ─ reservas B
  └─ pago global asociado a todos los pedidos
                              └─ Delivery solo si corresponde
```

No se divide el pago visible por minimarket. El cliente confirma una compra y un
total global, aunque el dominio persista varios pedidos. Un fallo al crear uno de
los grupos invalida la operación conjunta y exige compensación de pedidos,
reservas y bloqueos creados durante ese intento.

## 8. Resumen previo al pago

El resumen debe mostrar, por minimarket:

- nombre público del minimarket;
- producto e imagen pública cuando estén disponibles;
- cantidad validada;
- precio congelado (`unit_price_snapshot`) validado;
- subtotal de cada línea;
- total del pedido/minimarket.

Además debe mostrar:

- total global antes de despacho;
- método de entrega seleccionado;
- costos adicionales solo cuando exista un contrato futuro que los determine;
- tiempo restante de la reserva y hora de expiración absoluta.

La UI formatea valores del backend; no multiplica ni suma para decidir importes o
elegibilidad. Si el resumen cambia, debe explicar qué cambió y pedir una nueva
confirmación antes del pago.

## 9. RB-CHK-001 — Elegibilidad para despacho

Debe existir la configuración de negocio:

```text
minimum_delivery_amount = 8000 CLP
```

`minimum_delivery_amount` no se hardcodea en la lógica del checkout. Su mecanismo
de almacenamiento y administración se definirá en una fase posterior; 28.7.0 no
crea pantalla de configuración.

La base de evaluación es:

```text
checkout_total = suma de productos válidos de todos los pedidos por minimarket
```

`checkout_total` se calcula antes de cualquier costo de despacho:

- Si `checkout_total < minimum_delivery_amount`, solo se permite pickup y la UI
  no ofrece delivery.
- Si `checkout_total >= minimum_delivery_amount`, se permiten pickup y delivery.

El backend obtiene la configuración vigente, recalcula `checkout_total` con
aritmética decimal segura y valida el método solicitado. No confía en total,
umbral, elegibilidad ni método calculados por el navegador.

Una solicitud manipulada que pida delivery bajo el mínimo se rechaza como error
de validación. El rechazo ocurre antes de crear pedidos, nuevas reservas, pagos o
entregas. Si una fase utiliza una pre-reserva anterior, debe liberarla o conservarla
según un estado explícito, nunca crear recursos adicionales a partir del intento
inválido.

## 10. Cambios durante el checkout

Backend recalcula elegibilidad antes de crear pedidos y antes de aceptar el método
de entrega. Debe detectar:

- producto eliminado o desactivado;
- cantidad ajustada;
- precio actual distinto antes de reservar;
- inventario o minimarket inválido/inactivo;
- stock insuficiente;
- reserva expirada;
- carrito modificado en otra pestaña o dispositivo de la misma identidad.

Si cambia el total:

1. se invalida el resumen confirmado;
2. se devuelve el nuevo resumen o errores por línea;
3. se recalculan métodos disponibles con RB-CHK-001;
4. si delivery deja de ser elegible, se cambia la UI a pickup sin enviarlo
   silenciosamente: el cliente debe reconocer el cambio;
5. no se continúa al pago hasta una nueva confirmación.

Una vez creados pedidos y reservas, el carrito deja de ser la fuente de esa
operación. Recargar el checkout debe recuperar pedidos/reservas existentes, no
crear otros desde un carrito reconstruido.

## 11. Reserva de 15 minutos

- **Inicio:** comienza cuando Reservations bloquea stock y persiste las reservas,
  no cuando se abre el formulario.
- **Duración:** 15 minutos conforme a la constante actual del dominio.
- **Reloj:** backend entrega `expires_at`; la UI muestra una cuenta regresiva como
  ayuda, pero la hora del servidor es autoridad.
- **Advertencias:** aviso visible y anunciado al quedar 5 minutos y 1 minuto; los
  umbrales son UX y no extienden la reserva.
- **Expiración:** al llegar a cero se bloquean nuevos intentos de pago, se consulta
  el estado real y se muestra `expired` si backend lo confirma.
- **Liberación:** el proceso de expiración libera stock y marca/libera reservas de
  acuerdo con Reservations; la UI no libera stock por sí misma.
- **Durante el pago:** si el proveedor confirma dentro o después del límite, la
  confirmación backend decide según el estado terminal real. La UI no declara
  fracaso únicamente por su temporizador.

## 12. Ambigüedad, recuperación e idempotencia observable

Timeout, pérdida de conexión, cierre, recarga, botón atrás, doble clic o respuesta
tardía pueden ocurrir después de que backend haya creado recursos. Por ello:

- el botón principal queda bloqueado mientras exista una solicitud activa;
- cada intento utiliza una clave/id de operación estable generado para ese intento
  cuando la fase de implementación incorpore el mecanismo;
- repetir la misma intención recupera el resultado existente;
- un checkout con pedidos/reservas válidos no vuelve a crearlos;
- un pago ya existente para el mismo conjunto de pedidos se recupera y valida;
- una confirmación repetida devuelve el estado terminal actual;
- nunca se crea otro checkout solo porque el navegador no recibió la respuesta.

Ante resultado ambiguo, la UI entra en `recovery_required`, conserva la referencia
no sensible disponible y consulta el estado real. Debe ofrecer “Comprobar estado”,
no “Pagar otra vez” como primera acción.

Escenarios:

- **Recarga/atrás:** recuperar estado antes de habilitar acciones.
- **Cierre del navegador:** al volver con la misma identidad/referencia, recuperar
  si aún es válido.
- **Doble clic:** una sola operación observable.
- **Pago confirmado sin respuesta visible:** la recuperación muestra confirmado.
- **Confirmación tardía:** backend aplica transiciones idempotentes y devuelve el
  estado real, sin duplicar consumo de stock ni pedidos.

## 13. Integración con Delivery

Delivery solo se crea o prepara cuando se cumplen conjuntamente:

1. método seleccionado `delivery`;
2. total global recalculado mayor o igual a `minimum_delivery_amount`;
3. checkout validado correctamente;
4. pedidos y reservas existentes y válidos;
5. datos de despacho válidos;
6. momento de creación compatible con la regla actual que exige pedido pagado.

Con la implementación actual, la creación efectiva debe ocurrir después del pago,
porque Delivery rechaza pedidos no pagados. Si hay varios pedidos, cada entrega
debe relacionarse con su pedido/minimarket sin perder la operación global. No se
asume una entrega única para pedidos de varios comercios.

Pickup no crea registros, preparación, asignación, tracking ni estados Delivery.
Las rutas administrativas actuales de Delivery no deben invocarse desde el
navegador público.

## 14. Estados de interfaz y dominio

| Estado | Significado | Acciones permitidas |
|---|---|---|
| `editing` | Datos y método editables, sin operación confirmada | Editar, validar |
| `validating` | Backend recalcula carrito y elegibilidad | Esperar/cancelar localmente |
| `creating_orders` | Creación atómica/compensable en curso | No reenviar |
| `reserved` | Pedidos y reservas vigentes | Revisar tiempo, iniciar/recuperar pago |
| `waiting_payment` | Listo para crear o abrir sesión de pago | Continuar una vez |
| `payment_pending` | Proveedor aún no confirma | Consultar estado |
| `payment_confirmed` | Pago terminal exitoso | Ver resultado/pedidos |
| `expired` | Reserva vencida confirmada | Volver al carrito/revalidar |
| `cancelled` | Operación cancelada de forma definitiva | Volver al carrito |
| `failed` | Fallo definitivo y compensado | Corregir o reiniciar conscientemente |
| `recovery_required` | Resultado incierto | Comprobar estado, no duplicar |

Las transiciones las determina backend. La UI no convierte automáticamente
`payment_pending` en `failed` ni `recovery_required` en un checkout nuevo.

## 15. Validaciones y resultado esperado

- Carrito vacío: detener antes de reservas; volver a Cart.
- Producto inexistente/inactivo: señalar línea y pedir corrección.
- Inventario inactivo: impedir confirmación.
- Minimarket inactivo: impedir el grupo y el checkout global.
- Stock insuficiente: mostrar cantidad afectada sin prometer disponibilidad.
- Precio inválido/cambiado: presentar resumen recalculado y requerir confirmación.
- Reserva vencida: impedir pago nuevo y recuperar/liberar según backend.
- Método no permitido: validación RB-CHK-001 sin efectos parciales.
- Pago duplicado: devolver/recuperar el pago existente.
- Checkout completado: mostrar resultado existente, sin nuevos pedidos.

Los mensajes deben indicar qué puede hacer el cliente. Los códigos internos,
consultas, trazas, IDs sensibles y datos privados del minimarket no se muestran.

## 16. Seguridad y privacidad

El backend no confía en valores del frontend para:

- precios o snapshot;
- stock o disponibilidad;
- cantidad efectiva no validada;
- subtotal, total por minimarket o total global;
- `minimum_delivery_amount`;
- elegibilidad o método de entrega;
- `user_id`, propiedad de carrito, pedidos, pagos o entregas;
- estado de reserva o pago.

Todos se resuelven desde identidad y datos persistidos. Los datos personales se
limitan a lo necesario, se escapan al mostrar y no se incluyen en logs de error ni
URLs. Las referencias públicas deben ser no predecibles y no reemplazan la
autorización. Rate limiting, protección CSRF/nonce para autenticados y controles
contra automatización se precisarán durante hardening.

## 17. Responsive y accesibilidad

- Flujo usable a 320 px sin desplazamiento horizontal de formularios.
- Resumen agrupado como tabla en escritorio y tarjetas en móvil.
- Orden de foco coherente y foco visible.
- Etiquetas persistentes, instrucciones y errores asociados mediante ARIA.
- Estados y cambios de total anunciados con `aria-live` sin interrumpir cada
  segundo del contador.
- El contador tiene texto equivalente y no depende solo de color.
- Acciones bloqueadas exponen `disabled`/`aria-busy` y texto de carga.
- Errores llevan el foco al resumen y ofrecen enlaces a campos concretos.
- La selección de entrega funciona con teclado y lectores de pantalla.
- Se respeta reducción de movimiento y contraste suficiente.

## 18. Mensajes UX

Ejemplos no técnicos:

- “Tu carrito está vacío. Agrega productos para continuar.”
- “Cambió el precio de un producto. Revisa el nuevo total.”
- “Este producto ya no está disponible.”
- “Solo queda una cantidad menor disponible.”
- “El despacho está disponible desde $8.000 en productos.”
- “Tu reserva vence en 5 minutos.”
- “No pudimos confirmar el resultado. Comprobaremos el estado antes de volver a
  intentar.”
- “Tu pago fue recibido.”

El monto mostrado para delivery debe provenir de la configuración entregada por
backend, no de una constante de interfaz.

## 19. Casos límite

- Carrito con varios ítems del mismo inventario o minimarket.
- Muchos minimarkets y una reserva que expira antes que las demás.
- Total exactamente igual a 8000 CLP: delivery permitido.
- Total que cruza el umbral en cualquiera de las dos direcciones.
- Decimales, overflow y datos persistidos inválidos: rechazo seguro.
- Producto/minimarket eliminado entre resumen y confirmación.
- Stock tomado por otra compra durante validación.
- Dos pestañas confirman el mismo carrito.
- Login/logout durante checkout invitado.
- Pago confirmado cuando el reloj visual llegó a cero.
- Proveedor devuelve pending durante minutos.
- Una orden ya pagada y otra aparentemente pendiente dentro del mismo pago:
  recuperación y revisión, nunca una segunda cobranza.
- Fallo al crear un pedido intermedio, una reserva o Delivery: compensación y
  estado recuperable explícito.

## 20. Estrategia de pruebas

### Automatizables

- total global decimal con uno y varios minimarkets;
- separación determinista: un pedido por minimarket;
- RB-CHK-001 bajo, sobre y exactamente en el umbral;
- umbral leído de configuración y no del payload;
- manipulación de total/método rechazada sin efectos persistidos;
- revalidación ante precio, stock, estado y carrito modificado;
- rollback/compensación al fallar cada paso de creación;
- expiración y liberación a 15 minutos con reloj controlado;
- idempotencia de inicialización, pago y confirmación;
- recuperación después de timeout en cada frontera transaccional;
- aislamiento de invitado y autenticado;
- pickup sin registros Delivery y delivery solo tras pago válido;
- mensajes sanitizados y ausencia de datos privados;
- máquina de estados y acciones permitidas;
- accesibilidad DOM, navegación por teclado y responsive.

### Manuales

- recorrido invitado y autenticado con uno y varios minimarkets;
- cambio de método y aparición condicional de dirección;
- cuenta regresiva, avisos y expiración visible;
- doble clic, recarga, atrás, cierre y reconexión durante pago;
- retorno tardío del proveedor y resultado recuperado;
- edición simultánea del carrito en otra pestaña;
- lectores de pantalla, zoom 200 %, teclado y anchos móviles reales;
- mensajes comprensibles ante errores de negocio y red.

Cada prueba de error debe comprobar no solo la respuesta, sino también que no se
crearon pedidos, reservas adicionales, pagos o entregas y que el stock quedó en
el estado correcto.

## 21. Roadmap sugerido

### 28.7.1 — Public Checkout UI Foundation

Shortcode/vista, estados base, carga del resumen y accesibilidad, sin crear
pedidos ni pagos.

### 28.7.2 — Customer Data & Delivery Method

Formulario, diferencias invitado/autenticado, configuración
`minimum_delivery_amount` y RB-CHK-001 en presentación y backend.

### 28.7.3 — Checkout Validation Integration

Revalidación global, resumen por minimarket, detección de cambios y mensajes.

### 28.7.4 — Orders & Reservations Submission

Orquestación compensable, separación de pedidos, reserva de 15 minutos e
idempotencia de creación.

### 28.7.5 — Payment Session & Recovery

Acceso público seguro a la operación necesaria, sesión de proveedor, retorno,
consulta de estado y recuperación de resultados ambiguos sin duplicar pagos.

### 28.7.6 — Checkout Hardening

Concurrencia, seguridad, expiraciones, confirmaciones tardías, Delivery,
observabilidad, pruebas de fallos y revisión de privacidad.

## 22. Criterio de salida del diseño

La implementación futura solo debe comenzar cuando estén resueltos explícitamente:

- almacenamiento/lectura de `minimum_delivery_amount` con valor inicial 8000 CLP;
- contrato público seguro para invitado y recuperación de checkout/pago;
- clave y persistencia de idempotencia;
- modelo de datos de contacto y despacho;
- momento y cardinalidad de Delivery para múltiples pedidos;
- transacciones y compensaciones entre módulos;
- fuente de hora y estados terminales ante confirmación tardía.

Hasta entonces, este documento describe el comportamiento objetivo y las brechas;
no declara disponibles capacidades públicas que el código actual no expone.
