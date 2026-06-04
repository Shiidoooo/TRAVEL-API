<?php
declare(strict_types=1);

function nullIfEmpty(string $value): ?string
{
    $trimmed = trim($value);
    return $trimmed === '' ? null : $trimmed;
}

function parseDateInput(string $value): ?DateTime
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $formats = ['m/d/Y', 'm/d/y', 'Y-m-d'];
    foreach ($formats as $format) {
        $parsed = DateTime::createFromFormat($format, $value);
        if ($parsed instanceof DateTime) {
            return $parsed;
        }
    }

    try {
        return new DateTime($value);
    } catch (Throwable $e) {
        return null;
    }
}
