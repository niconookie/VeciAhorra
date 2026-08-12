# Validación final del branding de login VeciAhorra

Fecha: 2026-08-14

Veredicto: **TRAINING LOGIN BRANDING APROBADO**.

## 1. Baseline

Se ejecutaron `git status --short`, `git diff --stat` y `git diff --check` antes de intervenir. El working tree preexistente fue conservado; no hubo reset, checkout, stash, clean, commit ni push. El alcance se limitó a la presentación de `/wp-login.php`.

## 2. Implementación

`LoginBrandingModule` registra únicamente APIs estándar: `login_enqueue_scripts`, `login_headerurl`, `login_headertext`, `login_message`, `login_site_html_link` y `login_footer`. El módulo se inicia desde el bootstrap del plugin. No modifica `wp-login.php`, WordPress core, autenticación, cookies, sesiones, nonces, usuarios, roles, capabilities, errores o recuperación de contraseña.

El stylesheet `assets/login/login-branding.css` se encola exclusivamente durante la superficie login. No se carga en frontend ni administración.

## 3. Logo

```text
LOGIN_LOGO_SOURCE=WordPress custom_logo attachment 314, Logo_Veciahorra.png
LOGIN_LOGO_URL_STRATEGY=wp_get_attachment_image_url + set_url_scheme según is_ssl
LOGIN_LOGO_LINK=home_url('/')
LOGIN_LOGO_TEXT=Ir a VeciAhorra
```

Se reutiliza el asset oficial de medios; no se generó, copió ni enlazó un logo externo. Chrome confirmó background HTTPS, visibilidad y enlace accesible. El logo WordPress dejó de dominar la pantalla.

## 4. Title/subtitle

Antes del formulario se muestra **Bienvenido a VeciAhorra** y **Ingresa para acceder a tu panel.** mediante `login_message`. Permanecen visibles después de un login inválido y no sustituyen mensajes core.

## 5. Form

El formulario estándar conserva `user_login`, `user_pass`, `rememberme`, `wp-submit`, control mostrar contraseña, submit y autofill nativos. Solo cambiaron card, espaciado, bordes, focus y botón. Recuperación de contraseña conserva el enlace core `action=lostpassword`. El enlace inferior es **← Volver a VeciAhorra** y usa `home_url('/')`.

Un intento inválido mostró `#login_error` estándar sin alterar su contenido ni introducir enumeración de usuarios.

## 6. Role help

Bajo los controles aparece: **Clientes, minimarkets, repartidores y prestadores acceden desde aquí.** No existe selector de rol; el rol autenticado continúa siendo la única autoridad para decidir el panel de destino.

## 7. Responsive

Chrome validó 1440×1000 y 390×844. El formulario permaneció visible y el documento no produjo overflow horizontal. Se usan los colores frontend certificados: verde `#176b45`, azul `#245b78`, texto `#202522`, muted `#5e6963` y borde `#d5ddd8`. No hay animaciones, imágenes de fondo pesadas ni fuentes nuevas.

## 8. Runtime browser

```text
VECIAHORRA_LOGO_VISIBLE=PASS
WELCOME_TITLE_VISIBLE=PASS
SUBTITLE_VISIBLE=PASS
LOGIN_FORM_VISIBLE=PASS
FORGOT_PASSWORD_VISIBLE=PASS
BACK_TO_SITE_VISIBLE=PASS
ROLE_HELP_TEXT_VISIBLE=PASS
LOGIN_BROWSER_RENDER=PASS
LOGIN_JS_ERRORS=0
LOGIN_MIXED_CONTENT_ERRORS=0
LOGIN_HTTP_STATUS=200
HTTP_5XX_COUNT=0
```

La inspección visual confirma jerarquía clara, card legible, logo reconocible, botones accesibles y continuidad de marca.

## 9. Login redirects

Autenticación real en Chrome, sin imprimir credenciales:

```text
CUSTOMER_LOGIN_REDIRECT=PASS -> /mis-compras/
STORE_LOGIN_REDIRECT=PASS -> /panel-minimarket/
COURIER_LOGIN_REDIRECT=PASS -> /panel-repartidor/
PROVIDER_LOGIN_REDIRECT=PASS -> /panel-prestador/
ADMIN_LOGIN_REDIRECT=PASS -> wp-admin/admin.php?page=veciahorra
```

La lógica de redirect no fue modificada. El administrador fue validado contra el filtro certificado y su superficie autenticada en el preflight integral.

## 10. Security/regression

```text
WORDPRESS_CORE_CHANGED=no
AUTHENTICATION_CHANGED=no
ROLE_LOGIC_CHANGED=no
REDIRECT_LOGIC_CHANGED=no
REGISTRATION=PASS
STORE_ISOLATION=PASS
STORE_IMAGES=20/20
COURIER_AVAILABLE=1
COURIER_ASSIGNED=1
COURIER_IN_PROGRESS=1
CUSTOMER=PASS
STORE=PASS
COURIER=PASS
PROVIDER=PASS
ADMIN=PASS
```

No se agregaron scripts, CDNs, analytics, cookies, localStorage, tracking ni recursos remotos. Los datasets, pagos, paneles y datos demo no fueron modificados.

## 11. TLS note

La personalización no intenta corregir el certificado local XAMPP. La excepción TLS de capacitación debe aceptarse previamente. Las incidencias globales documentadas de Bikrimart Delivery y recursos externos bloqueados se mantienen separadas y no aparecen como dependencias del login.

```text
TLS_CERTIFICATE_FIX_OUT_OF_SCOPE=yes
TRAINING_TLS_EXCEPTION_STILL_REQUIRED=yes
```

## 12. Git/delta

Delta propio exclusivo:

- `app/Modules/LoginBranding/LoginBrandingModule.php`
- `assets/login/login-branding.css`
- `veciahorra.php` (solo registro del módulo)
- `tests/manual/training-login-branding-preflight.py`
- `docs/training-login-branding-final-validation-2026-08-14.md`

`COMMIT=NO`. `PUSH=NO`.
