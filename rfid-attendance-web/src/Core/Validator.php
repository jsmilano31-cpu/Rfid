<?php

declare(strict_types=1);

namespace App\Core;

final class Validator
{
    public static function required(array $input, array $fields): array
    {
        $errors = [];
        foreach ($fields as $field => $label) {
            if (!isset($input[$field]) || trim((string) $input[$field]) === '') {
                $errors[$field] = "{$label} is required.";
            }
        }
        return $errors;
    }
}
