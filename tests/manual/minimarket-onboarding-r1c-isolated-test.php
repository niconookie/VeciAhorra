<?php

declare(strict_types=1);

require_once dirname(__DIR__, 5) . '/wp-load.php';

use VeciAhorra\Modules\Minimarket\Onboarding\Exceptions\OnboardingInputException;
use VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake\HmacRateLimitKeyDeriver;
use VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake\PublicOnboardingErrorTranslator;
use VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake\PublicOnboardingRequestFactory;
use VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake\PublicRequestGuard;
use VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake\RandomIdempotencyKeyIssuer;
use VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake\RemoteAddressResolver;

function r1c_assert(bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); }

$issuer = new RandomIdempotencyKeyIssuer();
$a = $issuer->issue(); $b = $issuer->issue();
r1c_assert((bool) preg_match('/^[a-f0-9]{64}$/', $a) && $a !== $b, 'Idempotency issuer inválido.');

$factory = new PublicOnboardingRequestFactory();
$request = $factory->fromPost([
    'veciahorra_minimarket_onboarding' => '1', '_va_minimarket_onboarding_nonce' => 'n',
    'account_email' => 'Owner@Example.com', 'owner_rut' => '12.345.678-5',
    'terms_accepted' => '1', 'idempotency_key' => $a,
]);
r1c_assert($request->accountEmail === 'Owner@Example.com' && $request->termsAccepted, 'Request factory alteró valores.');
foreach ([['account_email' => []], ['unexpected' => 'x'], ['owner_rut' => ['x']]] as $hostile) {
    try { $factory->fromPost($hostile); throw new RuntimeException('Shape hostil aceptado.'); } catch (\VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake\PublicIntakeException) {}
}

$resolver = new RemoteAddressResolver();
$ipv4 = $resolver->resolve(['REMOTE_ADDR' => '192.0.2.4', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9']);
$ipv6a = $resolver->resolve(['REMOTE_ADDR' => '2001:db8:abcd:12::1']);
$ipv6b = $resolver->resolve(['REMOTE_ADDR' => '2001:db8:abcd:12:ffff::2']);
r1c_assert($ipv4->prefixLength === 32 && $ipv6a->prefixLength === 64 && $ipv6a->networkBytes === $ipv6b->networkBytes, 'Normalización IP inválida.');
$mappedA = $resolver->resolve(['REMOTE_ADDR' => '::ffff:192.0.2.4']);
$mappedB = $resolver->resolve(['REMOTE_ADDR' => '::ffff:c000:0204']);
r1c_assert($mappedA->prefixLength === 32 && $mappedA->networkBytes === $ipv4->networkBytes && $mappedB->networkBytes === $ipv4->networkBytes, 'IPv4 mapped inválida.');

$deriver = new HmacRateLimitKeyDeriver(str_repeat('s', 64));
$digest = $deriver->derive('email', 'owner@example.com');
r1c_assert((bool) preg_match('/^[a-f0-9]{64}$/', $digest) && ! str_contains($digest, 'owner'), 'HMAC inválido.');

$translator = new PublicOnboardingErrorTranslator();
r1c_assert($translator->translate(new OnboardingInputException('invalid_email'))->httpStatus === 422, 'Traducción email inválida.');
r1c_assert($translator->translate(new OnboardingInputException('terms_version_unavailable'))->httpStatus === 503, 'Traducción legal inválida.');

$nonce = wp_create_nonce(PublicRequestGuard::NONCE_ACTION);
$post = ['veciahorra_minimarket_onboarding'=>'1',PublicRequestGuard::NONCE_FIELD=>$nonce];
$raw = http_build_query($post);
$server = ['REQUEST_METHOD'=>'POST','CONTENT_TYPE'=>'application/x-www-form-urlencoded','CONTENT_LENGTH'=>(string)strlen($raw),'HTTP_ORIGIN'=>home_url('/'),'REMOTE_ADDR'=>'127.0.0.1'];
(new PublicRequestGuard())->assertAllowed(true, $server, $post, home_url('/'), $raw, true);
foreach ([
    array_merge($server, ['HTTP_ORIGIN'=>'https://evil.example']),
    array_diff_key($server, ['HTTP_ORIGIN'=>true]),
    array_merge($server, ['CONTENT_TYPE'=>'application/json']),
    array_merge($server, ['CONTENT_LENGTH'=>'8193']),
] as $bad) {
    try { (new PublicRequestGuard())->assertAllowed(true, $bad, $post, home_url('/'), $raw, true); throw new RuntimeException('Guard hostil aceptado.'); } catch (\VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake\PublicIntakeException) {}
}
foreach ([['1',true],['8193',true],[(string)(strlen($raw)-1),true],[(string)(strlen($raw)+1),true],[(string)strlen($raw),false]] as [$length,$complete]) {
    try { (new PublicRequestGuard())->assertAllowed(true,array_merge($server,['CONTENT_LENGTH'=>$length]),$post,home_url('/'),$raw,$complete); throw new RuntimeException('Longitud hostil aceptada.'); } catch (\VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake\PublicIntakeException) {}
}
try { (new PublicRequestGuard())->assertAllowed(true,array_diff_key($server,['CONTENT_LENGTH'=>true]),$post,home_url('/'),$raw,true); } catch (Throwable) { throw new RuntimeException('Body verificable sin longitud rechazado.'); }
try { (new PublicRequestGuard())->assertAllowed(true,$server,$post,home_url('/'),$raw.'&veciahorra_minimarket_onboarding=1',true); throw new RuntimeException('Clave repetida aceptada.'); } catch (\VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake\PublicIntakeException) {}

echo "R1C_ISOLATED=PASS request=PASS guard=PASS origin=PASS nonce=PASS ip=PASS key=PASS privacy=PASS\n";
