<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\Owner;

final class OwnerOnboardingRequest
{
    private const FIELDS = ['business_name'=>150,'legal_name'=>150,'owner_name'=>150,'rut'=>20,'email'=>150,'phone'=>30,'mobile'=>30,'address'=>255,'commune'=>120,'city'=>120,'region'=>120];

    public function __construct(private array $input) {}

    public function correction(): array
    {
        $allowed = [...array_keys(self::FIELDS), 'expected_updated_at'];
        if (array_diff(array_keys($this->input), $allowed) !== []) throw new \InvalidArgumentException('La correccion contiene campos inesperados.');
        $expected = $this->input['expected_updated_at'] ?? null;
        if (! is_string($expected) || ! $this->timestamp($expected)) throw new \InvalidArgumentException('expected_updated_at es obligatorio.');
        $data = [];
        foreach (self::FIELDS as $field => $limit) {
            if (! array_key_exists($field, $this->input)) continue;
            if (! is_string($this->input[$field])) throw new \InvalidArgumentException('Los antecedentes deben ser texto.');
            $value = trim($field === 'address' ? sanitize_textarea_field($this->input[$field]) : sanitize_text_field($this->input[$field]));
            if (strlen($value) > $limit) throw new \InvalidArgumentException('Un antecedente supera el largo permitido.');
            if (in_array($field, ['business_name','legal_name','owner_name','rut','email','phone'], true) && $value === '') throw new \InvalidArgumentException('Los antecedentes obligatorios no pueden quedar vacios.');
            if ($field === 'email' && ! is_email($value)) throw new \InvalidArgumentException('El correo no es valido.');
            $data[$field] = $value === '' ? null : $value;
        }
        if ($data === []) throw new \InvalidArgumentException('No se informaron correcciones.');
        return ['fields'=>$data, 'expected_updated_at'=>$expected];
    }

    public function resubmit(): string
    {
        if (array_keys($this->input) !== ['expected_updated_at'] || ! is_string($this->input['expected_updated_at']) || ! $this->timestamp($this->input['expected_updated_at'])) throw new \InvalidArgumentException('El reenvio requiere expected_updated_at.');
        return $this->input['expected_updated_at'];
    }

    private function timestamp(string $value): bool
    {
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value);
        return $date!==false&&$date->format('Y-m-d H:i:s')===$value;
    }
}
