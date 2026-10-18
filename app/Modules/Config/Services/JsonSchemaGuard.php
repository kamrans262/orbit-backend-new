<?php

namespace App\Modules\Config\Services;

use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Fail-closed, deliberately bounded subset of JSON Schema.
 * Unsupported keywords are rejected instead of ignored.
 */
final class JsonSchemaGuard
{
    private const KEYS = ['type','properties','required','additionalProperties','items','enum',
        'minimum','maximum','minLength','maxLength','minItems','maxItems'];

    public function check(string $json, string $schemaJson, string $declaredType): array
    {
        try {
            $value = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages(['value_json' => 'Enter valid JSON.']);
        }
        try {
            $schema = json_decode($schemaJson, false, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages(['schema_json' => 'Enter valid schema JSON.']);
        }
        if (!$schema instanceof stdClass || ($schema->type ?? null) !== $declaredType) {
            throw ValidationException::withMessages(['schema_json' => 'Schema root type must match the selected value type.']);
        }
        $this->verifySchema($schema);
        $this->verifyValue($value, $schema, '$', 0);
        return [$value, json_decode($schemaJson, true, 64, JSON_THROW_ON_ERROR)];
    }

    private function verifySchema(stdClass $schema, int $depth = 0): void
    {
        if ($depth > 15) $this->invalidSchema('Schema nesting is too deep.');
        foreach (array_keys(get_object_vars($schema)) as $key) {
            if (!in_array($key, self::KEYS, true)) $this->invalidSchema('Unsupported schema keyword: '.$key);
        }
        if (!in_array($schema->type ?? null, ['object','array','string','number','boolean'], true)) {
            $this->invalidSchema('Every schema node needs a supported type.');
        }
        if (isset($schema->properties)) {
            if ($schema->type !== 'object' || !$schema->properties instanceof stdClass) {
                $this->invalidSchema('properties requires an object schema.');
            }
            foreach (get_object_vars($schema->properties) as $nested) {
                if (!$nested instanceof stdClass) $this->invalidSchema('Each property needs a schema object.');
                $this->verifySchema($nested, $depth + 1);
            }
        }
        if (isset($schema->required)) {
            if ($schema->type !== 'object' || !is_array($schema->required)) $this->invalidSchema('required must be an array for objects.');
            foreach ($schema->required as $name) if (!is_string($name)) $this->invalidSchema('Required keys must be strings.');
        }
        if (isset($schema->additionalProperties) && !is_bool($schema->additionalProperties)) {
            $this->invalidSchema('additionalProperties supports true or false.');
        }
        if (isset($schema->items)) {
            if ($schema->type !== 'array' || !$schema->items instanceof stdClass) $this->invalidSchema('items requires an array schema.');
            $this->verifySchema($schema->items, $depth + 1);
        }
        if (isset($schema->enum) && !is_array($schema->enum)) $this->invalidSchema('enum must be a JSON array.');
        foreach (['minimum','maximum'] as $key) {
            if (isset($schema->{$key}) && (!is_numeric($schema->{$key}) || $schema->type !== 'number')) {
                $this->invalidSchema($key.' applies only to numeric values.');
            }
        }
        foreach (['minLength','maxLength','minItems','maxItems'] as $key) {
            if (isset($schema->{$key}) && (!is_int($schema->{$key}) || $schema->{$key} < 0)) {
                $this->invalidSchema($key.' must be a nonnegative integer.');
            }
        }
    }

    private function verifyValue(mixed $value, stdClass $schema, string $path, int $depth): void
    {
        if ($depth > 15) $this->invalidValue('Value nesting exceeds supported limit.');
        $type = $schema->type;
        $valid = match ($type) {
            'object' => $value instanceof stdClass,
            'array' => is_array($value),
            'string' => is_string($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            default => false,
        };
        if (!$valid) $this->invalidValue($path.' must be '.$type.'.');
        if (isset($schema->enum) && !in_array(json_encode($value), array_map('json_encode', $schema->enum), true)) {
            $this->invalidValue($path.' is not an approved enum value.');
        }
        if ($type === 'object') {
            $properties = $schema->properties ?? new stdClass();
            foreach ($schema->required ?? [] as $key) {
                if (!property_exists($value, $key)) $this->invalidValue($path.'.'.$key.' is required.');
            }
            foreach (get_object_vars($value) as $key => $item) {
                if (property_exists($properties, $key)) {
                    $this->verifyValue($item, $properties->{$key}, $path.'.'.$key, $depth + 1);
                } elseif (($schema->additionalProperties ?? true) === false) {
                    $this->invalidValue($path.'.'.$key.' is not an allowed property.');
                }
            }
        } elseif ($type === 'array') {
            $count = count($value);
            if (isset($schema->minItems) && $count < $schema->minItems) $this->invalidValue($path.' has too few entries.');
            if (isset($schema->maxItems) && $count > $schema->maxItems) $this->invalidValue($path.' has too many entries.');
            if (isset($schema->items)) foreach ($value as $index => $item) {
                $this->verifyValue($item, $schema->items, $path.'['.$index.']', $depth + 1);
            }
        } elseif ($type === 'string') {
            $length = mb_strlen($value);
            if (isset($schema->minLength) && $length < $schema->minLength) $this->invalidValue($path.' is too short.');
            if (isset($schema->maxLength) && $length > $schema->maxLength) $this->invalidValue($path.' is too long.');
        } elseif ($type === 'number') {
            if (isset($schema->minimum) && $value < $schema->minimum) $this->invalidValue($path.' is too small.');
            if (isset($schema->maximum) && $value > $schema->maximum) $this->invalidValue($path.' is too large.');
        }
    }

    private function invalidSchema(string $message): never
    {
        throw ValidationException::withMessages(['schema_json' => $message]);
    }

    private function invalidValue(string $message): never
    {
        throw ValidationException::withMessages(['value_json' => $message]);
    }
}
