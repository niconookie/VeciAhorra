# Corrección normativa A11 — ruta de bootstrap WordPress

## 1. Autoridad, precedencia y alcance

Este documento complementa los contratos versionados:

- `durable-retry-production-activation-a11-normative-correction.md`;
- `durable-retry-production-activation-a11-complementary-normative-correction.md`.

Para la resolución del bootstrap WordPress desde los harnesses A11 ubicados
directamente en `tests/manual`, esta corrección prevalece sobre cualquier
fragmento anterior incompatible. Su autoridad se limita exclusivamente al nivel
de resolución de `wp-load.php`; no modifica ningún otro contrato A11 ni reabre
autoridad o semántica A1–A10.

## 2. Diagnóstico físico exacto

La estructura física relevante es:

```text
C:\xampp\htdocs\Minimarket\
├── wp-load.php
└── wp-content\
    └── plugins\
        └── veciahorra\
            └── tests\
                └── manual\
```

El directorio de ejecución documental de los harnesses es:

```text
C:\xampp\htdocs\Minimarket\wp-content\plugins\veciahorra\tests\manual
```

Desde ese directorio, la evaluación nivel por nivel es:

1. `dirname(__DIR__, 1)` → `C:\xampp\htdocs\Minimarket\wp-content\plugins\veciahorra\tests`
2. `dirname(__DIR__, 2)` → `C:\xampp\htdocs\Minimarket\wp-content\plugins\veciahorra`
3. `dirname(__DIR__, 3)` → `C:\xampp\htdocs\Minimarket\wp-content\plugins`
4. `dirname(__DIR__, 4)` → `C:\xampp\htdocs\Minimarket\wp-content`
5. `dirname(__DIR__, 5)` → `C:\xampp\htdocs\Minimarket`

Por tanto, el antecedente siguiente resuelve a
`C:\xampp\htdocs\Minimarket\wp-content\wp-load.php`, que no existe:

```php
dirname(__DIR__, 4) . '/wp-load.php'
```

Ese antecedente queda expresamente prohibido para cualquier harness A11
ejecutado directamente desde `tests/manual/*.php`.

## 3. Construcción normativa definitiva

La construcción normativa definitiva es literalmente:

```php
new DurableRetryA11Coordinator(
    PHP_BINARY,
    dirname(__DIR__, 5) . '/wp-load.php',
    sys_get_temp_dir() . '/veciahorra-a11'
)
```

El único cambio normativo es:

```diff
- dirname(__DIR__, 4) . '/wp-load.php'
+ dirname(__DIR__, 5) . '/wp-load.php'
```

La expresión definitiva resuelve físicamente a
`C:\xampp\htdocs\Minimarket\wp-load.php`, archivo regular existente en la
instalación WordPress de certificación.

## 4. Contratos que permanecen intactos

Esta corrección no cambia:

- el constructor de `DurableRetryA11Coordinator`;
- el orden de sus argumentos;
- `PHP_BINARY`;
- `sys_get_temp_dir() . '/veciahorra-a11'`;
- FQCN, namespaces, clases, métodos o interfaces;
- los cinco decorators test-only;
- los protocolos JSON y JSONL;
- los códigos de salida;
- los timeouts de procesos, HTTP, harnesses o suite conjunta;
- el servidor HTTP loopback;
- la integración Webpay;
- los 31 casos normativos;
- las cinco ventanas de crash;
- la allowlist cerrada de doce rutas de implementación;
- las prohibiciones, exclusiones y condiciones de certificación existentes.

No se introduce firma pública, helper, DTO, runner, dependencia ni archivo de
implementación adicional.

## 5. Alcance cerrado de la regla de nivel cinco

El nivel cinco es normativo únicamente para archivos ejecutados directamente
desde:

```text
tests/manual/*.php
```

Si un harness se mueve en el futuro a otro directorio, queda prohibido conservar
ciegamente este nivel. El traslado exige recalcular la ruta desde el nuevo
`__DIR__` y, si contradice el contrato versionado, adoptar una nueva corrección
normativa antes de implementar o certificar.

A11 no incorpora búsqueda ascendente, autodetección, globbing, heurísticas ni
fallback para localizar `wp-load.php`. Salvo nueva corrección documental, queda
prohibido:

- recorrer directorios hasta encontrar `wp-load.php`;
- probar varias rutas candidatas;
- derivar la ruta desde el current working directory;
- usar una constante o variable ambiental no normada;
- degradar a una ruta alternativa;
- continuar cuando el archivo no existe;
- sustituir el bootstrap real por mocks en integraciones que exigen WordPress.

## 6. Validación fail-closed del bootstrap

La ruta definitiva debe señalar un archivo regular existente. Los harnesses solo
pueden validar anticipadamente esa condición cuando la validación forme parte de
la guardia de entorno ya exigida por A11; esta corrección no crea una firma
pública nueva para resolverla.

Si el archivo no existe o no es regular:

1. se considera un fallo material de infraestructura o contrato;
2. no se intenta ninguna otra ruta;
3. no se inicia `DurableRetryA11Coordinator` con una ruta inválida;
4. no se ejecutan procesos hijos con una ruta inexistente;
5. el caso termina con el código de salida normado aplicable;
6. stdout y stderr se conservan íntegros como evidencia;
7. el caso, harness y suite no pueden declararse PASS.

La existencia del archivo no autoriza a relajar la validación del manifiesto, el
hash, el entorno, WordPress, MySQL, Action Scheduler o las restantes guardias.

## 7. Relación con la allowlist y la implementación

La allowlist de implementación A11 continúa siendo exactamente la definida por
la corrección normativa complementaria: cuatro archivos existentes modificables
y ocho archivos A11 nuevos. Este documento no se agrega a esa allowlist y queda
protegido una vez versionado.

La corrección no autoriza durante su redacción:

- continuar la implementación productiva;
- crear los ocho archivos A11;
- modificar producto o pruebas;
- modificar los dos documentos A11 anteriores;
- ejecutar H1–H5, los 31 casos o las cinco ventanas de crash;
- realizar staging, commit o push.

## 8. Reapertura controlada de A11

La implementación A11 solo puede continuar después de completar, en orden:

1. certificar documentalmente esta corrección;
2. versionarla selectivamente;
3. verificar que el commit contiene exclusivamente este documento;
4. iniciar una nueva ejecución de implementación desde el commit documental
   resultante.

El accessor local existente
`Application::durableRetryWebpayMaterializer(): WebpayReconciliationMaterializer`
puede mantenerse intacto durante esa secuencia. Esta corrección no certifica su
implementación ni autoriza alterarlo.

## 9. Condiciones de adopción documental

La adopción requiere comprobar al menos:

- que este documento es el único archivo nuevo de este microhito;
- que los cuatro cambios tracked preexistentes conservan exactamente sus bytes;
- que los dos documentos A11 anteriores permanecen sin modificaciones;
- que la expresión de nivel cuatro aparece solo como antecedente prohibido;
- que la expresión de nivel cinco aparece como regla definitiva;
- que ambas rutas resultantes se contrastan con el filesystem;
- que el staging permanece vacío hasta una autorización posterior y separada;
- que no existen procesos, temporales, locks ni cambios fuera del alcance.

La versión documental posterior debe ser selectiva y no puede incorporar los
cambios locales de implementación.

## 10. Veredicto documental

**A11 IMPLEMENTABLE TRAS CORRECCIÓN NORMATIVA DE RUTA DE BOOTSTRAP**

Este veredicto elimina exclusivamente el bloqueo de resolución de `wp-load.php`.
No declara A11 implementado, probado ni certificado operacionalmente.
