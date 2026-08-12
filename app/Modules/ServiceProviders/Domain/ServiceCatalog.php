<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\ServiceProviders\Domain;
final class ServiceCatalog
{
 public const CATEGORIES=[
  'veciarregla'=>['label'=>'🔧 VeciArregla','subcategories'=>['gasfiteria'=>'Gasfitería','electricidad'=>'Electricidad','cerrajeria'=>'Cerrajería','carpinteria'=>'Carpintería','pintura'=>'Pintura','albanileria-reparaciones'=>'Albañilería y reparaciones','instalacion-muebles'=>'Instalación de muebles y accesorios','reparaciones-hogar'=>'Reparaciones generales del hogar']],
  'vecilimpia'=>['label'=>'🧹 VeciLimpia','subcategories'=>['aseo-domiciliario'=>'Aseo domiciliario','limpieza-profunda'=>'Limpieza profunda','limpieza-oficinas'=>'Limpieza de oficinas','limpieza-vidrios'=>'Limpieza de vidrios','alfombras-tapices'=>'Lavado de alfombras y tapices','jardineria'=>'Jardinería y mantención']],
  'vecitec'=>['label'=>'💻 VeciTec','subcategories'=>['reparacion-computadores'=>'Reparación de computadores','configuracion-computadores'=>'Instalación y configuración de computadores','redes-wifi'=>'Redes y Wi-Fi','soporte-tecnologico'=>'Soporte tecnológico','celulares-tablets'=>'Reparación de celulares y tablets','camaras-seguridad'=>'Instalación de cámaras y seguridad']],
  'vecimueve'=>['label'=>'🚚 VeciMueve','subcategories'=>['fletes'=>'Fletes','mudanzas'=>'Mudanzas','traslado-muebles'=>'Retiro y traslado de muebles','carga-menor'=>'Transporte de carga menor']],
  'vecibelleza'=>['label'=>'💇 VeciBelleza','subcategories'=>['peluqueria'=>'Peluquería','barberia'=>'Barbería','manicure-pedicure'=>'Manicure y pedicure','maquillaje'=>'Maquillaje','estetica'=>'Estética y cuidado personal']],
  'vecimascota'=>['label'=>'🐾 VeciMascota','subcategories'=>['paseo-perros'=>'Paseo de perros','cuidado-mascotas'=>'Cuidado de mascotas','peluqueria-mascotas'=>'Peluquería de mascotas','adiestramiento'=>'Adiestramiento']],
  'veciaprende'=>['label'=>'📚 VeciAprende','subcategories'=>['clases-particulares'=>'Clases particulares','apoyo-escolar'=>'Apoyo escolar','idiomas'=>'Idiomas','computacion'=>'Computación','musica'=>'Música','talleres'=>'Capacitación y talleres']],
  'vecieventos'=>['label'=>'🎉 VeciEventos','subcategories'=>['fotografia'=>'Fotografía','video'=>'Video','animacion'=>'Animación de eventos','decoracion'=>'Decoración','banqueteria'=>'Banquetería','sonido-musica'=>'Sonido y música']],
  'veciespecialista'=>['label'=>'🛠️ VeciEspecialista','subcategories'=>['climatizacion'=>'Climatización','reparacion-electrodomesticos'=>'Reparación de electrodomésticos','instalacion-electrodomesticos'=>'Instalación de electrodomésticos','mantencion-calefont'=>'Mantención de calefont','portones'=>'Portones y automatización','soldadura'=>'Soldadura']],
  'veciayuda'=>['label'=>'🤝 VeciAyuda','subcategories'=>['adultos-mayores'=>'Cuidado de adultos mayores','acompanamiento'=>'Acompañamiento','compras-tramites'=>'Compras y trámites','organizacion-hogar'=>'Armado y organización del hogar','otros'=>'Otros servicios locales']]
 ];
 public static function valid(string $category,string $subcategory):bool{return isset(self::CATEGORIES[$category]['subcategories'][$subcategory]);}
 public static function publicData():array{return self::CATEGORIES;}
}
