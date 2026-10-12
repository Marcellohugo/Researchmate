<?php

declare(strict_types=1);

namespace App\Services\AI;

use RuntimeException;
use stdClass;

class JsonSchemaValidator
{
    /** @param array<string, mixed> $schema */
    public function validate(mixed $value, array $schema, string $path = '$'): void
    {
        foreach (['anyOf', 'oneOf'] as $combination) {
            if (isset($schema[$combination])) {
                $matches = 0;
                foreach ($schema[$combination] as $candidate) {
                    try {
                        $this->validate($value, $candidate, $path);
                        $matches++;
                    } catch (RuntimeException) {
                    }
                }
                if ($matches === 0 || ($combination === 'oneOf' && $matches !== 1)) {
                    throw new RuntimeException("Struktur JSON tidak cocok pada {$path}.");
                }

                return;
            }
        }

        $types = (array) ($schema['type'] ?? []);
        $valid = $types === [];
        foreach ($types as $type) {
            $valid = $valid || match ($type) {
                'object' => $value instanceof stdClass,
                'array' => is_array($value) && array_is_list($value),
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'null' => $value === null,
                default => throw new RuntimeException("Tipe skema JSON tidak didukung: {$type}."),
            };
        }
        if (! $valid || (isset($schema['enum']) && ! in_array($value, $schema['enum'], true))) {
            throw new RuntimeException("Nilai JSON tidak valid pada {$path}.");
        }
        if ($value instanceof stdClass) {
            $properties = $schema['properties'] ?? [];
            foreach ($schema['required'] ?? [] as $field) {
                if (! property_exists($value, $field)) {
                    throw new RuntimeException("Kolom JSON wajib tidak ditemukan: {$path}.{$field}.");
                }
            }
            foreach (get_object_vars($value) as $field => $item) {
                if (isset($properties[$field])) {
                    $this->validate($item, $properties[$field], "{$path}.{$field}");
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    throw new RuntimeException("Kolom JSON tidak dikenal: {$path}.{$field}.");
                }
            }
        }
        if (is_array($value)) {
            if (count($value) < ($schema['minItems'] ?? 0) || count($value) > ($schema['maxItems'] ?? PHP_INT_MAX)) {
                throw new RuntimeException("Jumlah elemen JSON tidak valid pada {$path}.");
            }
            if (isset($schema['items'])) {
                foreach ($value as $index => $item) {
                    $this->validate($item, $schema['items'], "{$path}[{$index}]");
                }
            }
        }
        if (is_string($value) && (mb_strlen($value) < ($schema['minLength'] ?? 0) || mb_strlen($value) > ($schema['maxLength'] ?? PHP_INT_MAX))) {
            throw new RuntimeException("Panjang teks JSON tidak valid pada {$path}.");
        }
        if ((is_int($value) || is_float($value)) && ($value < ($schema['minimum'] ?? -INF) || $value > ($schema['maximum'] ?? INF))) {
            throw new RuntimeException("Angka JSON di luar batas pada {$path}.");
        }
    }
}
