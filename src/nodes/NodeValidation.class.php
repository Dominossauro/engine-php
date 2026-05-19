<?php
enum OutputValidation: string
{
    case SUCCESS = 'success';
    case FAILURE = 'failure';
}

class NodeValidation
{
    public function execute(array $config, array $context): array
    {
        $validationResult = $this->validateData($config, $context);

        if ($validationResult['isValid']) {
            $this->handleSuccess($config, $context, $validationResult);
            return [
                'output'  => OutputValidation::SUCCESS->value,
                'data'    => $validationResult['data'],
                'isValid' => true
            ];
        } else {
            $this->handleFailure($config, $context, $validationResult);
            return [
                'output'        => OutputValidation::FAILURE->value,
                'errors'        => $validationResult['errors'],
                'errorMessages' => $validationResult['errorMessages'],
                'isValid'       => false
            ];
        }
    }

    private function validateData(array $config, array $context): array
    {
        $data = $config['data'] ?? [];
        $rules = $data['rules'] ?? [];
        $valueToValidate = $this->getValue($data, $context);
        $fieldName = $data['variableName'] ?? 'unknown';
        $errors = [];
        $errorMessages = [];
        $isValid = true;

        foreach ($rules as $rule) {
            $ruleResult = $this->applyRule($rule, $valueToValidate, $context);
            if (!$ruleResult['valid']) {
                $isValid = false;
                $errorMessages[] = $ruleResult['message'];
                $errors[] = [
                    'rule'     => $rule['type'] ?? 'required',
                    'message'  => $ruleResult['message'],
                    'field'    => $fieldName,
                    'received' => $valueToValidate,
                    'expected' => $rule['value'] ?? $rule['values'] ?? $rule['pattern'] ?? null
                ];
            }
        }

        return [
            'isValid'       => $isValid,
            'data'          => $valueToValidate,
            'errors'        => $errors,
            'errorMessages' => $errorMessages
        ];
    }

    private function getValue(array $config, array $context): mixed
    {
        global $flowCtx;

        if (isset($config['variableName'])) {
            $variableName = $this->parseVariableName($config['variableName']);
            return $flowCtx->getVariable($variableName);
        }

        if (isset($config['value'])) {
            return $config['value'];
        }

        if (isset($config['contextPath'])) {
            return $this->getValueFromPath($context, $config['contextPath']);
        }

        return null;
    }

    private function parseVariableName(string $variableName): string
    {
        $cleaned = trim($variableName);

        if (preg_match('/^\{\{(.+?)\}\}$/', $cleaned, $matches)) {
            return trim($matches[1]);
        }

        return $cleaned;
    }

    private function getValueFromPath(array $context, string $path): mixed
    {
        $keys = explode('.', $path);
        $value = $context;

        foreach ($keys as $key) {
            if (is_array($value) && isset($value[$key])) {
                $value = $value[$key];
            } else {
                return null;
            }
        }

        return $value;
    }

    private function applyRule(array $rule, mixed $value, array $context): array
    {
        $type = $rule['type'] ?? 'required';

        return match ($type) {
            // Type checks
            'required'       => $this->validateRequired($value, $rule),
            'is_string'      => $this->validateIsString($value, $rule),
            'is_numeric'     => $this->validateNumeric($value, $rule),
            'is_integer'     => $this->validateIsInteger($value, $rule),
            'is_boolean'     => $this->validateIsBoolean($value, $rule),
            'is_array'       => $this->validateIsArray($value, $rule),

            // Text rules
            'min_length', 'minLength' => $this->validateMinLength($value, $rule),
            'max_length', 'maxLength' => $this->validateMaxLength($value, $rule),
            'length_between' => $this->validateLengthBetween($value, $rule),
            'regex'          => $this->validateRegex($value, $rule),

            // Format checks
            'email'          => $this->validateEmail($value, $rule),
            'url'            => $this->validateUrl($value, $rule),
            'uuid'           => $this->validateUuid($value, $rule),
            'ip'             => $this->validateIp($value, $rule),
            'date'           => $this->validateDate($value, $rule),
            'json'           => $this->validateJson($value, $rule),

            // Number rules
            'min_value', 'min' => $this->validateMinValue($value, $rule),
            'max_value', 'max' => $this->validateMaxValue($value, $rule),
            'between'        => $this->validateBetween($value, $rule),
            'numeric'        => $this->validateNumeric($value, $rule),

            // Comparison
            'equals'         => $this->validateEquals($value, $rule),
            'not_equals'     => $this->validateNotEquals($value, $rule),
            'in'             => $this->validateIn($value, $rule),
            'not_in'         => $this->validateNotIn($value, $rule),

            // Array
            'array_min'      => $this->validateArrayMin($value, $rule),
            'array_max'      => $this->validateArrayMax($value, $rule),

            // Custom
            'custom'         => $this->validateCustom($value, $rule, $context),

            default          => ['valid' => true, 'message' => '']
        };
    }

    // --- Type checks ---

    private function validateRequired(mixed $value, array $rule): array
    {
        $isValid = $value !== null &&
                   $value !== '' &&
                   (!is_array($value) || !empty($value)) &&
                   (!is_string($value) || trim($value) !== '');

        if ($value === 0 || $value === '0') {
            $isValid = true;
        }

        return [
            'valid' => $isValid,
            'message' => $isValid ? '' : ($rule['message'] ?? 'Campo obrigatório')
        ];
    }

    private function validateIsString(mixed $value, array $rule): array
    {
        $isValid = is_string($value);
        return [
            'valid' => $isValid,
            'message' => $isValid ? '' : ($rule['message'] ?? 'Must be a string')
        ];
    }

    private function validateNumeric(mixed $value, array $rule): array
    {
        $isValid = is_numeric($value);
        return [
            'valid' => $isValid,
            'message' => $isValid ? '' : ($rule['message'] ?? 'Must be numeric')
        ];
    }

    private function validateIsInteger(mixed $value, array $rule): array
    {
        $isValid = filter_var($value, FILTER_VALIDATE_INT) !== false;
        return [
            'valid' => $isValid,
            'message' => $isValid ? '' : ($rule['message'] ?? 'Must be an integer')
        ];
    }

    private function validateIsBoolean(mixed $value, array $rule): array
    {
        $isValid = is_bool($value) || in_array($value, [0, 1, '0', '1', 'true', 'false'], true);
        return [
            'valid' => $isValid,
            'message' => $isValid ? '' : ($rule['message'] ?? 'Must be a boolean')
        ];
    }

    private function validateIsArray(mixed $value, array $rule): array
    {
        $isValid = is_array($value);
        return [
            'valid' => $isValid,
            'message' => $isValid ? '' : ($rule['message'] ?? 'Must be an array')
        ];
    }

    // --- Text rules ---

    private function validateMinLength(mixed $value, array $rule): array
    {
        $length = is_string($value) ? mb_strlen($value, 'UTF-8') : 0;
        $minLength = $rule['value'] ?? 0;
        $isValid = $length >= $minLength;

        return [
            'valid' => $isValid,
            'message' => $isValid ? '' : ($rule['message'] ?? "Minimum {$minLength} characters")
        ];
    }

    private function validateMaxLength(mixed $value, array $rule): array
    {
        $length = is_string($value) ? mb_strlen($value, 'UTF-8') : 0;
        $maxLength = $rule['value'] ?? 0;
        $isValid = $length <= $maxLength;

        return [
            'valid' => $isValid,
            'message' => $isValid ? '' : ($rule['message'] ?? "Maximum {$maxLength} characters")
        ];
    }

    private function validateLengthBetween(mixed $value, array $rule): array
    {
        $length = is_string($value) ? mb_strlen($value, 'UTF-8') : 0;
        $min = $rule['min'] ?? 0;
        $max = $rule['max'] ?? PHP_INT_MAX;
        $isValid = $length >= $min && $length <= $max;

        return [
            'valid' => $isValid,
            'message' => $isValid ? '' : ($rule['message'] ?? "Must be between {$min} and {$max} characters")
        ];
    }

    private function validateRegex(mixed $value, array $rule): array
    {
        $pattern = $rule['pattern'] ?? '';

        if (empty($pattern)) {
            return [
                'valid' => false,
                'message' => $rule['message'] ?? 'Regex pattern not defined'
            ];
        }

        $isValid = preg_match($pattern, (string) $value) === 1;

        return [
            'valid' => $isValid,
            'message' => $isValid ? '' : ($rule['message'] ?? 'Invalid format')
        ];
    }

    // --- Format checks ---

    private function validateEmail(mixed $value, array $rule): array
    {
        $isValid = filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
        return [
            'valid' => $isValid,
            'message' => $isValid ? '' : ($rule['message'] ?? 'Must be a valid email')
        ];
    }

    private function validateUrl(mixed $value, array $rule): array
    {
        $isValid = filter_var($value, FILTER_VALIDATE_URL) !== false;
        return [
            'valid' => $isValid,
            'message' => $isValid ? '' : ($rule['message'] ?? 'Must be a valid URL')
        ];
    }

    private function validateUuid(mixed $value, array $rule): array
    {
        $isValid = (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            (string) $value
        );
        return [
            'valid' => $isValid,
            'message' => $isValid ? '' : ($rule['message'] ?? 'Must be a valid UUID')
        ];
    }

    private function validateIp(mixed $value, array $rule): array
    {
        $isValid = filter_var($value, FILTER_VALIDATE_IP) !== false;
        return [
            'valid' => $isValid,
            'message' => $isValid ? '' : ($rule['message'] ?? 'Must be a valid IP address')
        ];
    }

    private function validateDate(mixed $value, array $rule): array
    {
        $format = $rule['format'] ?? null;

        if ($format) {
            $d = \DateTime::createFromFormat($format, (string) $value);
            $isValid = $d && $d->format($format) === (string) $value;
        } else {
            $isValid = strtotime((string) $value) !== false;
        }

        return [
            'valid' => $isValid,
            'message' => $isValid ? '' : ($rule['message'] ?? 'Must be a valid date')
        ];
    }

    private function validateJson(mixed $value, array $rule): array
    {
        if (!is_string($value)) {
            return ['valid' => false, 'message' => $rule['message'] ?? 'Must be a valid JSON string'];
        }

        json_decode($value);
        $isValid = json_last_error() === JSON_ERROR_NONE;

        return [
            'valid' => $isValid,
            'message' => $isValid ? '' : ($rule['message'] ?? 'Must be valid JSON')
        ];
    }

    // --- Number rules ---

    private function validateMinValue(mixed $value, array $rule): array
    {
        $min = $rule['value'] ?? 0;
        $isValid = is_numeric($value) && floatval($value) >= $min;

        return [
            'valid' => $isValid,
            'message' => $isValid ? '' : ($rule['message'] ?? "Must be at least {$min}")
        ];
    }

    private function validateMaxValue(mixed $value, array $rule): array
    {
        $max = $rule['value'] ?? 0;
        $isValid = is_numeric($value) && floatval($value) <= $max;

        return [
            'valid' => $isValid,
            'message' => $isValid ? '' : ($rule['message'] ?? "Must be at most {$max}")
        ];
    }

    private function validateBetween(mixed $value, array $rule): array
    {
        $min = $rule['min'] ?? 0;
        $max = $rule['max'] ?? PHP_INT_MAX;
        $isValid = is_numeric($value) && floatval($value) >= $min && floatval($value) <= $max;

        return [
            'valid' => $isValid,
            'message' => $isValid ? '' : ($rule['message'] ?? "Must be between {$min} and {$max}")
        ];
    }

    // --- Comparison ---

    private function validateEquals(mixed $value, array $rule): array
    {
        $expected = $rule['value'] ?? null;
        $strict = $rule['strict'] ?? false;
        $isValid = $strict ? ($value === $expected) : ((string) $value === (string) $expected);

        return [
            'valid' => $isValid,
            'message' => $isValid ? '' : ($rule['message'] ?? "Must equal {$expected}")
        ];
    }

    private function validateNotEquals(mixed $value, array $rule): array
    {
        $rejected = $rule['value'] ?? null;
        $isValid = (string) $value !== (string) $rejected;

        return [
            'valid' => $isValid,
            'message' => $isValid ? '' : ($rule['message'] ?? "Must not equal {$rejected}")
        ];
    }

    private function validateIn(mixed $value, array $rule): array
    {
        $allowed = array_map('strval', $rule['values'] ?? []);
        $isValid = in_array((string) $value, $allowed, true);

        return [
            'valid' => $isValid,
            'message' => $isValid ? '' : ($rule['message'] ?? 'Must be one of: ' . implode(', ', $allowed))
        ];
    }

    private function validateNotIn(mixed $value, array $rule): array
    {
        $rejected = array_map('strval', $rule['values'] ?? []);
        $isValid = !in_array((string) $value, $rejected, true);

        return [
            'valid' => $isValid,
            'message' => $isValid ? '' : ($rule['message'] ?? 'Must not be: ' . implode(', ', $rejected))
        ];
    }

    // --- Array ---

    private function validateArrayMin(mixed $value, array $rule): array
    {
        $min = $rule['value'] ?? 0;
        $isValid = is_array($value) && count($value) >= $min;

        return [
            'valid' => $isValid,
            'message' => $isValid ? '' : ($rule['message'] ?? "Must have at least {$min} items")
        ];
    }

    private function validateArrayMax(mixed $value, array $rule): array
    {
        $max = $rule['value'] ?? 0;
        $isValid = is_array($value) && count($value) <= $max;

        return [
            'valid' => $isValid,
            'message' => $isValid ? '' : ($rule['message'] ?? "Must have at most {$max} items")
        ];
    }

    // --- Custom ---

    private function validateCustom(mixed $value, array $rule, array $context): array
    {
        $function = $rule['function'] ?? null;

        if ($function && is_callable($function)) {
            return $function($value, $rule, $context);
        }

        return ['valid' => true, 'message' => ''];
    }

    private function handleSuccess(array $config, array $context, array $validationResult): void
    {
        global $flowCtx;
        $data = $config['data'] ?? [];

        if (isset($data['successVariable'])) {
            $flowCtx->setVariable($data['successVariable'], $validationResult['data']);
        }
    }

    private function handleFailure(array $config, array $context, array $validationResult): void
    {
        global $flowCtx;
        $data = $config['data'] ?? [];

        if (isset($data['errorVariable'])) {
            $flowCtx->setVariable($data['errorVariable'], $validationResult['errors']);
        }
    }
}
