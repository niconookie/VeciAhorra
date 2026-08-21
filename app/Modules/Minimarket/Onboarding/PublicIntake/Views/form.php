<?php
/** @var array{terms_url:string,privacy_url:string,version:string} $legal */
/** @var ?\VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake\PublicOnboardingResponse $response */
/** @var ?\VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake\PublicOnboardingRequest $request */
/** @var string $key */
$errors = $response?->fieldErrors ?? [];
$success = $response?->kind === 'accepted' && $response->publicId !== null;
?>
<section class="va-onboarding" aria-labelledby="va-onboarding-title" data-va-onboarding>
  <div class="va-onboarding__card">
    <header class="va-onboarding__header">
      <p class="va-onboarding__eyebrow">Comercios VeciAhorra</p>
      <h1 id="va-onboarding-title" tabindex="-1">Registro de minimarket</h1>
      <?php if (! $success): ?><p>Inicia la solicitud con los datos del responsable. La información comercial se solicitará en una etapa posterior.</p><?php endif; ?>
    </header>
    <?php if ($success): ?>
      <div class="va-onboarding__success" role="status" aria-live="polite" tabindex="-1" data-va-onboarding-result>
        <p>Recibimos tu solicitud. Guarda este código para futuras consultas.</p>
        <code class="va-onboarding__code" data-va-onboarding-code><?php echo esc_html($response->publicId); ?></code>
        <button type="button" class="va-button va-button--secondary" data-va-copy-code>Copiar código</button>
        <p><a href="<?php echo esc_url(get_permalink()); ?>">Enviar otra solicitud</a></p>
      </div>
    <?php else: ?>
      <?php if ($response !== null && $errors === []): ?>
        <div class="va-onboarding__alert" role="alert" tabindex="-1" data-va-onboarding-alert>
          <p><?php echo esc_html($response->kind === 'rate_limited' ? 'Has realizado varios intentos. Espera un momento antes de volver a intentar.' : 'No pudimos procesar la solicitud en este momento. Intenta nuevamente.'); ?></p>
        </div>
      <?php elseif ($errors !== []): ?>
        <div class="va-onboarding__alert" role="alert" tabindex="-1" data-va-onboarding-alert><p>Revisa los campos indicados:</p><ul>
          <?php foreach ($errors as $field => $message): ?><li><a href="#va-<?php echo esc_attr($field); ?>"><?php echo esc_html($message); ?></a></li><?php endforeach; ?>
        </ul></div>
      <?php endif; ?>
      <form method="post" action="" data-va-onboarding-form novalidate>
        <?php wp_nonce_field(\VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake\PublicRequestGuard::NONCE_ACTION, \VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake\PublicRequestGuard::NONCE_FIELD, false, true); ?>
        <input type="hidden" name="veciahorra_minimarket_onboarding" value="1">
        <input type="hidden" name="idempotency_key" value="<?php echo esc_attr($key); ?>">
        <div class="va-onboarding__field">
          <label for="va-account_email">Correo electrónico</label>
          <input id="va-account_email" name="account_email" type="email" required autocomplete="email" value="<?php echo esc_attr($request?->accountEmail ?? ''); ?>" <?php echo isset($errors['account_email']) ? 'aria-invalid="true" aria-describedby="va-account_email-error"' : ''; ?>>
          <?php if (isset($errors['account_email'])): ?><span id="va-account_email-error" class="va-onboarding__error"><?php echo esc_html($errors['account_email']); ?></span><?php endif; ?>
        </div>
        <div class="va-onboarding__field">
          <label for="va-owner_rut">RUT del responsable</label>
          <input id="va-owner_rut" name="owner_rut" type="text" required inputmode="text" value="" <?php echo isset($errors['owner_rut']) ? 'aria-invalid="true" aria-describedby="va-owner_rut-help va-owner_rut-error"' : 'aria-describedby="va-owner_rut-help"'; ?>>
          <span id="va-owner_rut-help" class="va-onboarding__help">Ejemplo: 12.345.678-5</span>
          <?php if (isset($errors['owner_rut'])): ?><span id="va-owner_rut-error" class="va-onboarding__error"><?php echo esc_html($errors['owner_rut']); ?></span><?php endif; ?>
        </div>
        <div class="va-onboarding__check">
          <input id="va-terms_accepted" name="terms_accepted" type="checkbox" value="1" required <?php checked($request?->termsAccepted ?? false); ?> <?php echo isset($errors['terms_accepted']) ? 'aria-invalid="true" aria-describedby="va-terms_accepted-error"' : ''; ?>>
          <label for="va-terms_accepted">Acepto los <a href="<?php echo esc_url($legal['terms_url']); ?>">Términos y Condiciones</a> y reconozco y acepto el tratamiento descrito en la <a href="<?php echo esc_url($legal['privacy_url']); ?>">Política de Privacidad</a>.</label>
          <?php if (isset($errors['terms_accepted'])): ?><span id="va-terms_accepted-error" class="va-onboarding__error"><?php echo esc_html($errors['terms_accepted']); ?></span><?php endif; ?>
        </div>
        <p class="va-onboarding__legal-version">Versión legal: <?php echo esc_html($legal['version']); ?></p>
        <button class="va-button" type="submit" data-va-submit><span data-va-submit-label>Enviar solicitud</span></button>
      </form>
    <?php endif; ?>
  </div>
</section>
