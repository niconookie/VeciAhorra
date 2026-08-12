<?php
declare(strict_types=1);
namespace VeciAhorra\Database\Tables;
use VeciAhorra\Database\Builder\TableBuilder;use VeciAhorra\Database\Contracts\TableInterface;
final class ServiceProvidersTable implements TableInterface
{
 public function name():string{return 'service_providers';}
 public function define(TableBuilder $t):void{$t->id()->string('full_name',150)->string('rut',20)->string('email',150)->string('phone',30)->string('plan',20)->default('local')->string('status',20)->default('draft')->tinyIntegerUnsigned('terms_accepted')->default('0')->bigIntegerUnsigned('photo_id')->nullable()->string('business_name',180)->nullable()->string('category_key',40)->nullable()->string('subcategory_key',80)->nullable()->text('description')->nullable()->string('commune',120)->nullable()->text('coverage')->nullable()->text('specialties')->nullable()->integerUnsigned('experience_years')->default('0')->string('schedule',180)->nullable()->tinyIntegerUnsigned('emergency_service')->default('0')->string('whatsapp',30)->nullable()->string('contact_email',150)->nullable()->text('admin_observation')->nullable()->datetime('submitted_at')->nullable()->datetime('approved_at')->nullable()->datetime('published_at')->nullable()->datetime('created_at')->datetime('updated_at')->index('status','service_providers_status_index')->index('plan','service_providers_plan_index')->index(['category_key','subcategory_key'],'service_providers_category_index')->index('commune','service_providers_commune_index');}
}
