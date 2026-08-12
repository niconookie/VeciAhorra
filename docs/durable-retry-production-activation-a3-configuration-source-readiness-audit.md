# Auditoría de preparación A3: fuente productiva de configuración de activación

## 1. Estado

**BLOQUEADO POR AMBIGÜEDAD DOCUMENTAL**

El contrato abstracto y el snapshot consumido por A2 están cerrados, pero las
autoridades vigentes no fijan una implementación productiva completa. En
particular, no existe una decisión normativa sobre fuente física, claves,
precedencia, normalización, semántica de ausencia, excepción de adaptación,
cache, constructor, administración ni allowlist.

Implementar una clase concreta en este estado exigiría elegir arquitectura y
política operacional no autorizadas por A2.

## 2. Objetivo

Determinar si puede implementarse sin decisiones pendientes una clase productiva
que satisfaga:

```php
DurableRetryActivationConfigurationSourceInterface::snapshot()
```

La auditoría separa el contrato ya versionado de A2 de la adaptación pendiente
entre una fuente operativa y
`DurableRetryActivationConfiguration`.

## 3. Base auditada

- Rama: `main`.
- Commit: `1950cda203d3abe228f68f2be79fbf27610eff9e`.
- Divergencia: `0` commits atrás y `32` adelante de `origin/main`.
- Schema: `0.24.0`.
- Staging inicial: vacío.
- Modificaciones tracked iniciales: ninguna.
- Documentos protegidos untracked: doce.
- Archivos protegidos bajo `artifacts/`: 504.
- PHP mínimo: 8.2.
- WordPress mínimo: 6.8.

## 4. Alcance

Se auditan:

- contrato abstracto de fuente A2;
- snapshot A2 y sus validaciones;
- política determinista A2;
- diseño de activación productiva;
- auditoría y especificación A2;
- patrones reales de configuración;
- `Config`;
- bootstrap, container y composition root;
- harnesses de configuración, composición, durable retry y activation;
- referencias productivas actuales a los símbolos A2.

## 5. Exclusiones

Esta auditoría no implementa:

- fuente productiva;
- wiring;
- transferencia inicial;
- rollout;
- consultas SQL;
- schema o migraciones;
- hooks o scheduling;
- batch;
- cambios de autoridad;
- UI, REST o WP-CLI;
- logging, métricas o eventos.

## 6. Autoridades inspeccionadas

### 6.1 Documentos

- `docs/durable-retry-production-activation-design.md`;
- `docs/durable-retry-production-activation-a2-readiness-audit.md`;
- `docs/durable-retry-production-activation-a2-flag-policy-spec.md`.

### 6.2 Código A2

- `DurableRetryActivationConfigurationSourceInterface`;
- `DurableRetryActivationConfiguration`;
- `DurableRetryActivationCohort`;
- `DurableRetryDeterministicActivationPolicy`;
- `DurableRetryActivationPolicyException`;
- contratos y harnesses A2 relacionados.

### 6.3 Configuración y composición

- `app/Core/Config.php`;
- `app/Core/Application.php`;
- `app/Core/Container.php`;
- `app/Modules/Payments/Gateway/PaymentGatewayConfiguration.php`;
- usos de `get_option()`, constantes y variables de entorno;
- harness de composición durable retry.

## 7. Arquitectura actual

`Config` es un catálogo estático de metadatos del plugin: versiones, nombre,
text domain, prefijo de tablas y mínimos de plataforma. No contiene feature
flags, claves de activación, lectores dinámicos ni política de precedencia.

`Application` es el composition root real y registra el grafo durable mediante
`registerDurableRetryGraph()`. El commit A2 no registra
`DurableRetryActivationConfigurationSourceInterface`, no construye
`DurableRetryDeterministicActivationPolicy` y no los expone a consumidores
productivos.

Existe un patrón de configuración en Payments que combina:

1. constante PHP;
2. variable de entorno;
3. option de WooCommerce;
4. defaults propios.

Ese patrón es específico del gateway. No hay documento ni contrato que lo
declare convención transversal, y sus decisiones de coerción y precedencia no
pueden trasladarse a Orders por semejanza.

## 8. Contrato heredado de A2

Está cerrado:

```php
interface DurableRetryActivationConfigurationSourceInterface
{
    public function snapshot(): DurableRetryActivationConfiguration;
}
```

La fuente no recibe parámetros y debe devolver el value object tipado; no puede
devolver arrays, escalares o `null`.

También está cerrado que el snapshot contiene exactamente:

- `stage`: `DurableRetryStage::RECONCILIATION`;
- `percentage`: `int` entre 0 y 100;
- `algorithmVersion`:
  `DurableRetryActivationCohort::ALGORITHM_VERSION`.

`disabled()` equivale a reconciliación al 0 %. El algoritmo vigente es
`sha256-24bit-mod100-v1`.

## 9. Lectura única en A2

La política A2 llama una vez a `$source->snapshot()` por decisión y mantiene el
objeto recibido durante esa invocación.

Esta garantía no define:

- cuántas lecturas físicas realiza internamente la fuente;
- si distintas llamadas a `snapshot()` releen configuración;
- si la fuente conserva cache;
- cuánto dura esa cache.

Por tanto, la atomicidad interna de la adaptación sigue abierta.

## 10. Fuente canónica

No está definida.

El diseño contiene el nombre conceptual:

```text
durable_retry.initial_transfer.reconciliation
```

y establece default `false`, pero no declara que esa cadena sea:

- una option de WordPress;
- una clave dentro de un array;
- una variable de entorno;
- una constante;
- una propiedad de `Config`;
- una entrada de archivo;
- un filtro;
- una clave administrable.

Tampoco define precedencia entre mecanismos.

## 11. Claves exactas

A2 fija los campos del snapshot, no las claves de almacenamiento.

No existen nombres literales normativos para:

- option;
- subclave de porcentaje;
- constante;
- variable de entorno;
- versión de algoritmo;
- stage.

El stage y la versión pueden derivarse de constantes cerradas de A2, pero falta
una decisión explícita que prohíba configurarlos externamente en A3.

## 12. Tipos de entrada

El tipo final del porcentaje es `int`, pero no está especificado el tipo crudo
entregado por la fuente futura.

No se sabe si la adaptación recibirá:

- `int`;
- `string`;
- `bool`;
- `null`;
- ausencia;
- array;
- objeto;
- valor serializado por WordPress.

Tampoco existe una matriz normativa de normalización.

## 13. Porcentaje productivo

Está cerrado:

- rango final `0..100`;
- precisión de un punto porcentual;
- comparación A2 estricta;
- floats y escalares no enteros no son válidos para la factory tipada.

No está cerrado cómo una fuente productiva trata:

- `"0"` o `"100"`;
- espacios;
- signo;
- ceros iniciales;
- decimales;
- notación científica;
- booleanos;
- arrays u objetos;
- valores mayores o menores al rango.

Pasar el valor crudo a la factory, parsearlo estrictamente o rechazar todos los
strings son políticas diferentes y ninguna tiene autoridad documental.

## 14. Default apagado

A2 define `DurableRetryActivationConfiguration::disabled()` como default lógico.

No define cuándo debe usarlo la fuente productiva:

- clave ausente;
- option inexistente;
- valor vacío;
- `null`;
- valor inválido;
- fallo del backend;
- sólo configuración inicial explícita.

Ausencia e invalidez no pueden equipararse sin una regla normativa. Hacerlo
podría ocultar corrupción operacional.

## 15. Configuración inválida

`DurableRetryActivationPolicyException` cubre porcentaje, stage, versión y
snapshot inválidos dentro del contrato A2.

No está decidido si A3 debe:

- reutilizar esa excepción;
- introducir una excepción de fuente;
- propagar `TypeError`;
- preservar una causa del backend;
- distinguir ausencia, tipo inválido e indisponibilidad;
- incluir la clave o el valor en el mensaje;
- sanear datos antes de diagnosticar.

Tampoco está especificada la conducta del caller ante fallo de lectura.

## 16. Snapshot y cache

El resultado debe ser inmutable porque el value object A2 lo es.

Permanecen abiertos:

- relectura en cada `snapshot()`;
- captura en constructor;
- cache por objeto;
- cache por request;
- cache global;
- cache persistente;
- invalidación;
- coherencia si los campos proceden de más de una lectura.

No puede inferirse una estrategia desde la lectura única realizada por la
política.

## 17. Consistencia entre decisiones

A2 permite que la fuente cambie entre decisiones y prohíbe releerla dentro de
una decisión. No determina cuándo debe hacerse visible una modificación
operativa.

Falta escoger entre:

- nueva lectura por llamada;
- snapshot estable durante el request;
- snapshot estable durante la vida del objeto;
- reconstrucción mediante un composition root por request.

## 18. Constructor y dependencias

No hay FQCN ni constructor normativos.

Las opciones técnicamente posibles —sin que ninguna esté autorizada— incluyen:

- constructor sin argumentos y acceso directo a WordPress;
- dependencia en un lector abstracto;
- callable inyectado;
- adapter de options;
- dependencia en una estructura construida por `Application`;
- lectura estática de constante o entorno.

Elegir una determinaría aislamiento, testabilidad, ciclo de vida y wiring.

## 19. WordPress

No se ha decidido usar WordPress Options.

Si se eligiera, todavía faltarían:

- función exacta (`get_option()` o `get_site_option()`);
- nombre literal;
- shape almacenado;
- valor default pasado a la API;
- scope por sitio o red;
- política multisite;
- autoload;
- disponibilidad durante bootstrap;
- comportamiento en CLI y fuera de WordPress;
- capacidad administrativa para escribir;
- responsable de crear o actualizar el valor.

La presencia de `get_option()` en otros módulos no cierra estas decisiones.

## 20. Constantes y entorno

El repositorio contiene precedentes de constantes y `getenv()` en Payments, pero
no hay nombres A3 ni precedencia aprobada.

Además, el precedente existente convierte constantes no string en cadena vacía
y trata variables de entorno vacías como ausentes. Adoptar esas reglas para un
porcentaje de activación sería una decisión nueva con impacto fail-closed.

## 21. Seguridad

El porcentaje no es un secreto, pero la fuente sigue siendo una entrada de
control operacional.

La especificación complementaria debe cerrar:

- quién puede modificarla;
- si filtros de terceros pueden intervenir;
- rechazo de arrays y objetos;
- tratamiento seguro de valores serializados;
- no inclusión de valores crudos en logs o excepciones;
- comportamiento ante backend indisponible;
- protección frente a coerciones PHP;
- scope correcto en multisite.

No hay base para introducir filtros o administración pública.

## 22. Compatibilidad

Está cerrado:

- PHP 8.2;
- WordPress 6.8;
- PSR-4 `VeciAhorra\` hacia `app/`;
- uso de `final` y propiedades `readonly`;
- factory A2 estrictamente tipada;
- ejecución de harnesses mediante Composer autoload.

Una fuente que invoque APIs globales de WordPress requeriría doubles explícitos
para CLI. Una fuente basada en dependencia inyectada tendría otra forma de
harness. La elección pendiente impide cerrar compatibilidad concreta.

## 23. Composición productiva futura

El punto de conexión futuro observable es el grafo registrado por
`Application::registerDurableRetryGraph()`.

La secuencia debe mantenerse separada:

1. microhito de fuente: leer y adaptar configuración;
2. microhito de wiring: registrar source y policy;
3. microhito de productor: consultar policy antes de una transferencia nueva;
4. microhito operativo: modificar porcentaje y ejecutar rollout.

Esta auditoría no autoriza modificar `Application`.

## 24. Colisión de nombre A3

El diseño versionado denomina:

```text
A3. Lectura de marcador y clasificación batch
```

La especificación A2 preserva expresamente ese significado y reserva la fuente
productiva para un “microhito posterior” sin número.

La solicitud actual llama A3 a la fuente productiva. Antes de especificar o
implementar debe resolverse el identificador para evitar dos alcances distintos
con el mismo nombre.

## 25. Observabilidad

No existe autorización para logs, métricas, eventos o diagnósticos emitidos por
la fuente.

La opción segura para el alcance actual es no inferir observabilidad. Una futura
norma debe indicar expresamente si A3 es silencioso y read-only o si reporta
fallos mediante otro contrato.

## 26. Mutabilidad y administración

No se ha decidido si el porcentaje será:

- fijo en código;
- proporcionado por despliegue;
- option editable;
- administrable por UI futura;
- administrable por WP-CLI;
- actualizado por otro proceso.

Sí puede recomendarse que la fuente sea read-only, pero esa preferencia no
constituye autoridad para elegir almacenamiento o mecanismo de escritura.

## 27. Harnesses requeridos

Una especificación futura debe nombrar harnesses exactos y exigir matrices para:

- fuente ausente y default apagado;
- lectura canónica;
- precedencia;
- tipos crudos;
- límites 0 y 100;
- valores fuera de rango;
- vacío y `null`;
- strings, espacios, signo, decimales y notación científica;
- booleanos, arrays y objetos;
- excepción, código, mensaje y causa;
- snapshot inmutable;
- número de lecturas físicas;
- estabilidad y cambio entre llamadas;
- aislamiento entre instancias o requests;
- comportamiento CLI/fuera de WordPress;
- compatibilidad con A2;
- ausencia de SQL, hooks, scheduling, batch, transferencia y wiring.

No pueden fijarse rutas ni doubles exactos hasta decidir la dependencia
productiva.

## 28. Allowlist futura

No puede proponerse una allowlist nominal implementable.

No están cerrados:

- nombre de clase;
- FQCN;
- namespace;
- ruta;
- excepción adicional;
- dependencia o adapter;
- necesidad de modificar `Config`;
- harnesses y sus rutas;
- actualización de guardias históricas.

Asignar nombres o cantidades ahora produciría una allowlist ficticia.

## 29. Ambigüedades bloqueantes

1. Identificador del microhito en conflicto con A3 del diseño.
2. FQCN, namespace y ruta de la implementación.
3. Fuente canónica de configuración.
4. Claves físicas literales.
5. Precedencia entre fuentes.
6. Configurabilidad externa de stage y versión.
7. Tipos crudos y normalización.
8. Semántica exacta de ausencia.
9. Diferencia entre ausencia, invalidez e indisponibilidad.
10. Excepción, código, mensaje y preservación de causa.
11. Frecuencia de lectura y atomicidad.
12. Cache, alcance e invalidación.
13. Constructor y dependencias.
14. Reglas WordPress y multisite, si aplica.
15. Modalidad de administración y escritura.
16. Observabilidad.
17. Harnesses nominales.
18. Allowlist exacta.

Ninguna puede resolverse usando sólo el código existente: los precedentes son
locales a otros dominios y no constituyen una norma de activación durable.

## 30. Veredicto

**BLOQUEADO POR AMBIGÜEDAD DOCUMENTAL**

No puede definirse con precisión suficiente la clase solicitada sin tomar
decisiones nuevas. El contrato de salida es implementable; la adaptación
productiva no lo es todavía.

## 31. Siguiente paso recomendado

Versionar una especificación complementaria exclusiva para la fuente productiva.
Debe cerrar, como mínimo:

1. nombre inequívoco del microhito;
2. FQCN, namespace y ruta;
3. constructor y ciclo de vida;
4. fuente canónica;
5. claves literales y precedencia;
6. tipos crudos y parser exacto;
7. ausencia, default e invalidez;
8. excepción, código y mensajes;
9. estrategia de lectura, snapshot y cache;
10. WordPress, multisite y CLI;
11. seguridad y administración;
12. ausencia o presencia de observabilidad;
13. harnesses exactos;
14. allowlist nominal y cantidades;
15. separación explícita respecto de wiring y rollout.

Sólo después de versionar esa norma debe solicitarse implementación.

## 32. Validaciones finales

La auditoría debe finalizar comprobando:

- este documento como único archivo nuevo de la tarea;
- cero cambios de código y pruebas;
- cero modificaciones a documentación existente;
- `git diff --check` limpio;
- `git diff --no-index --check` limpio para este archivo;
- staging vacío;
- worktree tracked limpio;
- A2 y documentos protegidos intactos;
- 504 archivos en `artifacts/`;
- ausencia de commit y push.
