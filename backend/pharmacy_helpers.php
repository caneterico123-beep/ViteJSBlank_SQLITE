<?php
declare(strict_types=1);

function require_pharmacy_user(PDO $pdo, array $roles): array
{
    $user = require_role($pdo, $roles);
    if ($user['business']['industry'] !== 'pharmacy') {
        json_response(403, ['message' => 'This pharmacy module is only available to pharmacy businesses.']);
    }
    return $user;
}

function pharmacy_date(mixed $value, string $field, bool $required = true): ?string
{
    if ($value === null || $value === '') {
        if ($required) json_response(422, ['message' => 'A valid date is required.', 'errors' => [$field => 'Enter a date using YYYY-MM-DD.']]);
        return null;
    }
    if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
        json_response(422, ['message' => 'Dates must use YYYY-MM-DD.', 'errors' => [$field => 'Use YYYY-MM-DD.']]);
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d') !== $value) {
        json_response(422, ['message' => 'Enter a real calendar date.', 'errors' => [$field => 'Enter a valid date.']]);
    }
    return $value;
}

function pharmacy_text(mixed $value, int $maxLength, string $field, bool $required = false): ?string
{
    if ($value === null && !$required) return null;
    if (!is_string($value)) {
        json_response(422, ['message' => 'Please correct the highlighted fields.', 'errors' => [$field => 'Enter valid text.']]);
    }
    $value = trim($value);
    if (($required && $value === '') || mb_strlen($value, 'UTF-8') > $maxLength) {
        json_response(422, ['message' => 'Please correct the highlighted fields.', 'errors' => [$field => $required ? 'This field is required.' : 'Text is too long.']]);
    }
    return $value === '' ? null : $value;
}
