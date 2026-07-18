<?php

declare(strict_types=1);

use VeciAhorra\Core\Application;
use VeciAhorra\Modules\Frontend\Components\PublicRouteLink;
use VeciAhorra\Modules\Frontend\FrontendModule;
use VeciAhorra\Modules\Frontend\Support\PublicRouteResolver;

require_once dirname(__DIR__, 5) . '/wp-load.php';

function assertPublicRouteLink(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$application = new Application();
$container = $application->container();
$module = $container->make(FrontendModule::class);
add_shortcode(PublicRouteLink::SHORTCODE, static fn (): string => 'foreign');
$module->register();
$registeredCallback = $GLOBALS['shortcode_tags'][PublicRouteLink::SHORTCODE] ?? null;
$module->register();

assertPublicRouteLink(
    shortcode_exists(PublicRouteLink::SHORTCODE),
    'El shortcode de rutas publicas no fue registrado.'
);
assertPublicRouteLink(
    is_array($registeredCallback)
        && ($registeredCallback[0] ?? null) instanceof PublicRouteLink,
    'El modulo no conserva la autoridad de su shortcode ante una colision.'
);
assertPublicRouteLink(
    $registeredCallback === ($GLOBALS['shortcode_tags'][PublicRouteLink::SHORTCODE] ?? null),
    'El segundo registro reemplazo el componente ya instanciado.'
);
assertPublicRouteLink(
    substr_count(
        file_get_contents(dirname(__DIR__, 2) . '/app/Modules/Frontend/FrontendModule.php'),
        'PublicRouteLink::SHORTCODE'
    ) === 1,
    'El shortcode no tiene un unico punto de registro.'
);

$resolver = $container->make(PublicRouteResolver::class);
$catalogUrl = $resolver->catalog();
assertPublicRouteLink($catalogUrl !== '', 'La pagina canonica del catalogo no esta disponible.');

$html = do_shortcode(
    '[veciahorra_public_route_link route="catalog" label="Ver &lt;script&gt;alert(1)&lt;/script&gt; productos"]'
);
$document = new DOMDocument();
libxml_use_internal_errors(true);
$document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
libxml_clear_errors();
$links = $document->getElementsByTagName('a');

assertPublicRouteLink($links->length === 1, 'La salida no contiene un unico enlace real.');
$link = $links->item(0);
assertPublicRouteLink($link instanceof DOMElement, 'No se pudo inspeccionar el enlace.');
assertPublicRouteLink(
    $link->getAttribute('href') === esc_url($catalogUrl),
    'El href no proviene de PublicRouteResolver::catalog().'
);
assertPublicRouteLink(! $link->hasAttribute('target'), 'El enlace abre otra pestana.');
assertPublicRouteLink(! $link->hasAttribute('role'), 'El enlace redefine su rol semantico.');
assertPublicRouteLink($document->getElementsByTagName('script')->length === 0, 'La etiqueta permite inyectar scripts.');
assertPublicRouteLink(str_contains($link->textContent, 'alert(1)'), 'La etiqueta no fue tratada como texto.');
assertPublicRouteLink(
    str_contains(
        (new PublicRouteLink($resolver))->render(['route' => 'catalog', 'label' => '0']),
        '>0</a>'
    ),
    'La etiqueta "0" fue tratada como vacia.'
);
assertPublicRouteLink(
    ! str_contains(
        (new PublicRouteLink($resolver))->render([
            'route' => 'catalog',
            'label' => '<strong onclick="alert(1)">Catalogo</strong>',
        ]),
        '<strong'
    ),
    'La etiqueta permite inyectar HTML.'
);
assertPublicRouteLink(
    ! str_contains(
        (new PublicRouteLink($resolver))->render([
            'route' => 'catalog',
            'label' => 'Catalogo',
            'class' => 'arbitrary-class',
            'onclick' => 'alert(1)',
        ]),
        'arbitrary-class'
    ),
    'El shortcode admite atributos HTML no autorizados.'
);

foreach (
    [
        '[veciahorra_public_route_link]',
        '[veciahorra_public_route_link route=""]',
        '[veciahorra_public_route_link route="home"]',
        '[veciahorra_public_route_link route="catalog();phpinfo"]',
        '[veciahorra_public_route_link route="catalog" label=""]',
    ] as $invalidShortcode
) {
    assertPublicRouteLink(
        do_shortcode($invalidShortcode) === '',
        'Una ruta vacia o no permitida genero salida.'
    );
}

$unsafeResolver = new PublicRouteResolver();
$resolved = new ReflectionProperty($unsafeResolver, 'resolved');
$resolved->setValue($unsafeResolver, ['catalog' => 'https://example.test/?value=" onclick="alert(1)']);
$escapedHtml = (new PublicRouteLink($unsafeResolver))->render([
    'route' => 'catalog',
    'label' => 'Catalogo',
]);
assertPublicRouteLink(
    ! str_contains($escapedHtml, ' onclick='),
    'La URL no fue escapada como atributo HTML.'
);
$unsafeProtocolResolver = new PublicRouteResolver();
$resolved->setValue($unsafeProtocolResolver, ['catalog' => 'javascript:alert(1)']);
assertPublicRouteLink(
    (new PublicRouteLink($unsafeProtocolResolver))->render([
        'route' => 'catalog',
        'label' => 'Catalogo',
    ]) === '',
    'Un protocolo inseguro genero un enlace.'
);

$unavailableResolver = new PublicRouteResolver();
$publishedPages = new ReflectionProperty($unavailableResolver, 'publishedPages');
$publishedPages->setValue($unavailableResolver, []);
$unavailableLink = new PublicRouteLink($unavailableResolver);
assertPublicRouteLink(
    $unavailableLink->render(['route' => 'catalog', 'label' => 'Catalogo']) === '',
    'Una pagina ausente o no publicada genero un enlace roto.'
);

$source = file_get_contents(dirname(__DIR__, 2) . '/app/Modules/Frontend/Components/PublicRouteLink.php');
assertPublicRouteLink(! str_contains($source, '->{$route}'), 'El componente ejecuta metodos arbitrarios.');
assertPublicRouteLink(! str_contains($source, 'WooCommerce'), 'El componente depende de WooCommerce.');
assertPublicRouteLink(! str_contains(strtolower($html), 'javascript:'), 'La salida contiene JavaScript.');

echo "Public route link tests passed.\n";
