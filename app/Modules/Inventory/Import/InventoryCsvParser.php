<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Inventory\Import;

final class InventoryCsvParser
{
    public const HEADERS = ['sku', 'precio', 'stock', 'estado'];
    public const MAX_BYTES = 1048576;
    public const MAX_ROWS = 1000;

    public function parse(string $contents): array
    {
        if ($contents === '' || strlen($contents) > self::MAX_BYTES) throw new \InvalidArgumentException('El CSV está vacío o supera 1 MB.');
        if (! mb_check_encoding($contents, 'UTF-8') || str_contains($contents, "\0")) throw new \InvalidArgumentException('El archivo debe ser texto UTF-8 válido.');
        if (str_starts_with($contents, "\xEF\xBB\xBF")) $contents = substr($contents, 3);
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) throw new \RuntimeException('No fue posible procesar el archivo.');
        fwrite($stream, $contents); rewind($stream);
        $header = fgetcsv($stream, 0, ',', '"', '\\');
        if ($header !== self::HEADERS) { fclose($stream); throw new \InvalidArgumentException('El encabezado debe ser exactamente: sku,precio,stock,estado.'); }
        $rows = []; $errors = []; $seen = []; $line = 1;
        while (($values = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
            $line++;
            if ($values === [null] || $values === []) continue;
            if ($line > self::MAX_ROWS + 1) { fclose($stream); throw new \InvalidArgumentException('El CSV supera el máximo de 1000 filas.'); }
            $skuForError = isset($values[0]) ? trim((string) $values[0]) : '';
            if (count($values) !== 4) { $errors[] = ['line' => $line, 'sku' => $skuForError, 'message' => 'La fila no tiene exactamente 4 columnas.']; continue; }
            [$sku, $price, $stock, $status] = array_map(static fn ($value): string => trim((string) $value), $values);
            $messages = [];
            if ($sku === '' || preg_match('/[\x00-\x1F\x7F]/u', $sku) === 1 || preg_match('/^[=+\-@]/', $sku) === 1) $messages[] = 'SKU vacío o inseguro.';
            $skuKey = mb_strtolower($sku, 'UTF-8');
            if ($sku !== '' && isset($seen[$skuKey])) $messages[] = 'SKU duplicado dentro del CSV.';
            if (preg_match('/^[1-9][0-9]*$/D', $price) !== 1) $messages[] = 'Precio debe ser un entero CLP mayor que cero.';
            if (preg_match('/^(0|[1-9][0-9]*)$/D', $stock) !== 1) $messages[] = 'Stock debe ser un entero mayor o igual a cero.';
            if (! in_array($status, ['active', 'inactive'], true)) $messages[] = 'Estado debe ser active o inactive.';
            $seen[$skuKey] = true;
            if ($messages !== []) { $errors[] = ['line' => $line, 'sku' => $sku, 'message' => implode(' ', $messages)]; continue; }
            $rows[] = ['line' => $line, 'sku' => $sku, 'price' => (int) $price, 'stock' => (int) $stock, 'status' => $status];
        }
        fclose($stream);
        return ['rows' => $rows, 'errors' => $errors];
    }
}
