# Definition of Done

Una tarea se considera terminada sólo cuando cumple todos los criterios
aplicables. Si alguno no corresponde, el pull request debe explicar por qué.

## Alcance y comportamiento

- Los criterios de aceptación están cumplidos y existe evidencia verificable.
- El cambio resuelve el alcance acordado sin incorporar trabajo no relacionado.
- Los casos exitosos, errores, límites y reintentos relevantes están definidos.
- Las transiciones de estado e invariantes del dominio permanecen consistentes.

## Implementación

- El diseño respeta la arquitectura modular y la responsabilidad de cada capa.
- La entrada se valida y sanitiza; la salida se escapa según su contexto.
- Autorización, capacidades, nonces y permisos REST se aplican donde corresponde.
- Operaciones sensibles a reintentos o concurrencia son idempotentes o están
  protegidas explícitamente.
- No se incluyen secretos, credenciales, datos personales ni artefactos locales.
- Las migraciones son versionadas, reejecutables y compatibles con actualización.

## Verificación

- Se agregan o actualizan pruebas proporcionales al riesgo del cambio.
- Las pruebas existentes y nuevas pasan en el entorno soportado.
- Se verifican manualmente los recorridos que aún no tienen automatización.
- Cuando exista un workflow de CI aplicable, todos sus analizadores, estándares
  y pruebas terminan sin errores.
- Mientras no exista CI, se ejecutan y documentan localmente el lint disponible,
  las pruebas manuales aplicables y la regresión relevante para el cambio.
- Para cambios de interfaz se revisan accesibilidad, estados vacíos y errores.
- Para cambios de datos se prueba instalación, actualización y recuperación.

## Documentación y entrega

- La documentación funcional, técnica u operativa afectada está actualizada.
- Los enlaces locales y ejemplos son válidos y no duplican una fuente canónica.
- El CHANGELOG se actualiza cuando el cambio es visible para usuarios u
  operadores.
- El pull request describe motivación, solución, pruebas, riesgos y rollback.
- La revisión requerida está aprobada y las observaciones están resueltas.
- El cambio puede desplegarse y revertirse con un procedimiento conocido.

## Cierre

- El cambio está integrado en la rama objetivo mediante el proceso acordado.
- El issue está vinculado al pull request y refleja la decisión final.
- No quedan defectos críticos conocidos; cualquier riesgo aceptado está
  documentado con responsable y seguimiento.
