<?php

namespace App\Services\Calculations;

use App\Models\TariffFormulaVariable;
use App\Models\TariffRule;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class FormulaCalculationEngine
{
    /*
    |--------------------------------------------------------------------------
    | Formula Calculation Engine
    |--------------------------------------------------------------------------
    |
    | Safely evaluates FORMULA tariff rules without eval().
    |
    | Example:
    |
    |     LAND_AREA * RATE * LIZZ_PERIOD
    |
    | Where:
    |
    |     LAND_AREA  -> BASE_FIELD
    |     RATE       -> CONSTANT (e.g. 3.70)
    |     LIZZ_PERIOD -> BASE_FIELD
    |
    | The engine is intentionally restricted to arithmetic expressions:
    |
    |     +  -  *  /
    |     (  )
    |     unary + / -
    |     numbers
    |     configured variables
    |
    | No PHP execution, functions, method calls, assignments, strings,
    | arrays, or eval() are allowed.
    |--------------------------------------------------------------------------
    */

    private const LOG_CHANNEL = 'stack';

    /**
     * Calculate a FORMULA tariff rule.
     *
     * @param  TariffRule  $rule
     * @param  array<string, mixed>  $values
     */
    public function calculate(
        TariffRule $rule,
        array $values
    ): float {
        $ruleId = (string) $rule->id;
        $formula = trim((string) $rule->formula);

        Log::debug('Starting tariff formula calculation.', [
            'channel' => self::LOG_CHANNEL,
            'rule_id' => $ruleId,
            'calculation_type' => $rule->calculation_type,
            'formula' => $formula,
            'input_value_count' => count($values),
        ]);

        try {
            /*
             * Formula engine must only process FORMULA rules.
             */
            if ($rule->calculation_type !== 'FORMULA') {
                throw new InvalidArgumentException(
                    "Tariff rule {$ruleId} is not a FORMULA rule."
                );
            }

            /*
             * Formula is mandatory for FORMULA rules.
             */
            if ($formula === '') {
                throw new RuntimeException(
                    "FORMULA tariff rule {$ruleId} requires a formula."
                );
            }

            /*
             * Ensure formula variables are available.
             */
            $rule->loadMissing('formulaVariables');

            $formulaVariables = $rule->formulaVariables;

            Log::debug('Tariff formula variables loaded.', [
                'rule_id' => $ruleId,
                'variable_count' => $formulaVariables->count(),
                'variables' => $formulaVariables
                    ->map(
                        fn (TariffFormulaVariable $variable): string =>
                            strtoupper(
                                trim((string) $variable->variable_name)
                            )
                    )
                    ->values()
                    ->all(),
            ]);

            /*
             * Resolve configured variables.
             *
             * Example result:
             *
             * [
             *     'LAND_AREA'   => 500.0,
             *     'RATE'        => 3.70,
             *     'LIZZ_PERIOD' => 12.0,
             * ]
             */
            $variables = $this->resolveVariables(
                $formulaVariables,
                $values,
                $ruleId,
            );

            /*
             * Evaluate formula using the restricted parser.
             */
            $result = $this->evaluate(
                formula: $formula,
                variables: $variables,
            );

            if (!is_finite($result)) {
                throw new RuntimeException(
                    "Formula for tariff rule {$ruleId} produced a non-finite result."
                );
            }

            Log::info('Tariff formula calculation completed successfully.', [
                'rule_id' => $ruleId,
                'formula' => $formula,
                'result' => $result,
                'variable_count' => count($variables),
            ]);

            return $result;
        } catch (Throwable $exception) {
            /*
             * Do not swallow calculation errors.
             *
             * The exception is logged with context and then re-thrown so
             * AssessmentCalculationService can mark the assessment service
             * as ERROR and expose the proper application-level failure.
             */
            Log::error('Tariff formula calculation failed.', [
                'rule_id' => $ruleId,
                'formula' => $formula,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
                'input_value_count' => count($values),
            ]);

            throw $exception;
        }
    }

    /**
     * Resolve all configured formula variables.
     *
     * @param  iterable<TariffFormulaVariable>  $formulaVariables
     * @param  array<string, mixed>  $values
     * @return array<string, float>
     */
    private function resolveVariables(
        iterable $formulaVariables,
        array $values,
        string $ruleId,
    ): array {
        $resolved = [];

        foreach ($formulaVariables as $variable) {
            if (!$variable instanceof TariffFormulaVariable) {
                throw new RuntimeException(
                    'Invalid tariff formula variable configuration.'
                );
            }

            $name = trim((string) $variable->variable_name);

            if ($name === '') {
                throw new RuntimeException(
                    "Tariff formula variable {$variable->id} has no variable name."
                );
            }

            /*
             * Formula variables are case-insensitive.
             *
             * LAND_AREA
             * land_area
             * Land_Area
             *
             * all resolve to LAND_AREA.
             */
            $normalizedName = strtoupper($name);

            if (array_key_exists($normalizedName, $resolved)) {
                throw new RuntimeException(
                    "Duplicate formula variable '{$name}' found for tariff rule {$variable->tariff_rule_id}."
                );
            }

            $resolved[$normalizedName] = $this->resolveVariableValue(
                variable: $variable,
                values: $values,
            );
        }

        /*
         * This log intentionally does not expose the actual assessment
         * values. It records only the resolved variable names and source
         * types, which is sufficient for diagnostics.
         */
        Log::debug('Tariff formula variables resolved.', [
            'rule_id' => $ruleId,
            'variables' => array_keys($resolved),
        ]);

        return $resolved;
    }

    /**
     * Resolve one formula variable.
     *
     * BASE_FIELD:
     *     Resolve from assessment/base-field values.
     *
     * CONSTANT:
     *     Resolve from default_value.
     */
    private function resolveVariableValue(
        TariffFormulaVariable $variable,
        array $values
    ): float {
        $sourceType = strtoupper(
            trim((string) $variable->source_type)
        );

        return match ($sourceType) {
            'BASE_FIELD' => $this->resolveBaseFieldVariable(
                variable: $variable,
                values: $values,
            ),

            'CONSTANT' => $this->resolveConstantVariable(
                variable: $variable,
            ),

            default => throw new RuntimeException(
                "Unsupported formula variable source type '{$variable->source_type}' " .
                "for variable '{$variable->variable_name}'."
            ),
        };
    }

    /**
     * Resolve a BASE_FIELD variable.
     *
     * Assessment values are expected to be indexed by BaseField UUID:
     *
     * [
     *     '<base_field_uuid>' => value
     * ]
     */
    private function resolveBaseFieldVariable(
        TariffFormulaVariable $variable,
        array $values
    ): float {
        $variableName = trim(
            (string) $variable->variable_name
        );

        if (!$variable->base_field_id) {
            throw new RuntimeException(
                "Formula variable '{$variableName}' is configured as BASE_FIELD " .
                'but has no base_field_id.'
            );
        }

        $baseFieldId = (string) $variable->base_field_id;

        /*
         * Primary resolution:
         * use the actual assessment value.
         */
        if (array_key_exists($baseFieldId, $values)) {
            $resolvedValue = $this->normalizeNumericValue(
                value: $values[$baseFieldId],
                variableName: $variableName,
            );

            Log::debug('BASE_FIELD formula variable resolved.', [
                'variable' => $variableName,
                'source_type' => 'BASE_FIELD',
                'base_field_id' => $baseFieldId,
                'resolution' => 'assessment_value',
            ]);

            return $resolvedValue;
        }

        /*
         * Optional BASE_FIELD variable with default value.
         */
        if (
            !$variable->is_required &&
            $variable->default_value !== null
        ) {
            $resolvedValue = $this->normalizeNumericValue(
                value: $variable->default_value,
                variableName: $variableName,
            );

            Log::debug('Optional BASE_FIELD formula variable resolved using default.', [
                'variable' => $variableName,
                'source_type' => 'BASE_FIELD',
                'base_field_id' => $baseFieldId,
                'resolution' => 'default_value',
            ]);

            return $resolvedValue;
        }

        /*
         * Optional missing variable defaults to zero.
         */
        if (!$variable->is_required) {
            Log::debug('Optional BASE_FIELD formula variable defaulted to zero.', [
                'variable' => $variableName,
                'source_type' => 'BASE_FIELD',
                'base_field_id' => $baseFieldId,
                'resolution' => 'zero',
            ]);

            return 0.0;
        }

        /*
         * Required value was not supplied.
         */
        throw new RuntimeException(
            "Required formula variable '{$variableName}' " .
            "could not be resolved from base field '{$baseFieldId}'."
        );
    }

    /**
     * Resolve a CONSTANT formula variable.
     *
     * Example:
     *
     *     RATE
     *     source_type = CONSTANT
     *     default_value = 3.70
     */
    private function resolveConstantVariable(
        TariffFormulaVariable $variable
    ): float {
        $variableName = trim(
            (string) $variable->variable_name
        );

        if ($variable->default_value === null) {
            if (!$variable->is_required) {
                Log::debug('Optional CONSTANT formula variable defaulted to zero.', [
                    'variable' => $variableName,
                    'source_type' => 'CONSTANT',
                    'resolution' => 'zero',
                ]);

                return 0.0;
            }

            throw new RuntimeException(
                "Required CONSTANT formula variable '{$variableName}' " .
                'has no default_value.'
            );
        }

        $resolvedValue = $this->normalizeNumericValue(
            value: $variable->default_value,
            variableName: $variableName,
        );

        Log::debug('CONSTANT formula variable resolved.', [
            'variable' => $variableName,
            'source_type' => 'CONSTANT',
            'resolution' => 'default_value',
        ]);

        return $resolvedValue;
    }

    /**
     * Normalize a value into a finite float.
     */
    private function normalizeNumericValue(
        mixed $value,
        string $variableName
    ): float {
        if (is_bool($value)) {
            $numeric = $value ? 1.0 : 0.0;
        } elseif (is_int($value) || is_float($value)) {
            $numeric = (float) $value;
        } elseif (
            is_string($value) &&
            is_numeric(trim($value))
        ) {
            $numeric = (float) trim($value);
        } else {
            throw new RuntimeException(
                "Formula variable '{$variableName}' must resolve to a numeric value."
            );
        }

        if (!is_finite($numeric)) {
            throw new RuntimeException(
                "Formula variable '{$variableName}' resolved to a non-finite value."
            );
        }

        return $numeric;
    }

    /*
    |--------------------------------------------------------------------------
    | Safe Formula Parser
    |--------------------------------------------------------------------------
    |
    | Supported:
    |
    |     10
    |     10.50
    |     .5
    |     LAND_AREA
    |     LAND_AREA * RATE
    |     LAND_AREA + EXTRA
    |     LAND_AREA - EXTRA
    |     LAND_AREA / RATE
    |     (LAND_AREA + EXTRA) * RATE
    |     -LAND_AREA
    |
    | Not supported:
    |
    |     PHP functions
    |     method calls
    |     property access
    |     assignments
    |     strings
    |     arrays
    |     arbitrary PHP expressions
    |     eval()
    |
    */

    /**
     * Evaluate a formula using the restricted parser.
     *
     * @param  array<string, float>  $variables
     */
    private function evaluate(
        string $formula,
        array $variables
    ): float {
        $tokens = $this->tokenize($formula);

        if ($tokens === []) {
            throw new RuntimeException(
                'Formula cannot be empty.'
            );
        }

        $position = 0;

        $result = $this->parseExpression(
            tokens: $tokens,
            position: $position,
            variables: $variables,
        );

        if ($position !== count($tokens)) {
            $token = $tokens[$position]['value'] ?? 'unknown';

            throw new RuntimeException(
                "Unexpected token '{$token}' in formula."
            );
        }

        return $result;
    }

    /**
     * Tokenize the formula.
     *
     * @return array<int, array{type:string,value:string}>
     */
    private function tokenize(string $formula): array
    {
        $length = strlen($formula);
        $position = 0;
        $tokens = [];

        while ($position < $length) {
            $character = $formula[$position];

            /*
             * Ignore whitespace.
             */
            if (ctype_space($character)) {
                $position++;
                continue;
            }

            /*
             * Numbers:
             *
             *     10
             *     10.5
             *     .5
             *     10.
             */
            if (
                ctype_digit($character) ||
                (
                    $character === '.' &&
                    isset($formula[$position + 1]) &&
                    ctype_digit($formula[$position + 1])
                )
            ) {
                $start = $position;
                $dotCount = 0;

                while ($position < $length) {
                    $current = $formula[$position];

                    if ($current === '.') {
                        $dotCount++;

                        if ($dotCount > 1) {
                            break;
                        }

                        $position++;
                        continue;
                    }

                    if (!ctype_digit($current)) {
                        break;
                    }

                    $position++;
                }

                $number = substr(
                    $formula,
                    $start,
                    $position - $start
                );

                if (!is_numeric($number)) {
                    throw new RuntimeException(
                        "Invalid numeric value '{$number}' in formula."
                    );
                }

                $tokens[] = [
                    'type' => 'NUMBER',
                    'value' => $number,
                ];

                continue;
            }

            /*
             * Identifiers:
             *
             *     LAND_AREA
             *     LIZZ_PERIOD
             *     RATE
             *
             * Allowed:
             *     A-Z
             *     a-z
             *     0-9
             *     _
             */
            if (
                ctype_alpha($character) ||
                $character === '_'
            ) {
                $start = $position;

                while ($position < $length) {
                    $current = $formula[$position];

                    if (
                        ctype_alnum($current) ||
                        $current === '_'
                    ) {
                        $position++;
                        continue;
                    }

                    break;
                }

                $identifier = substr(
                    $formula,
                    $start,
                    $position - $start
                );

                $tokens[] = [
                    'type' => 'IDENTIFIER',
                    'value' => strtoupper($identifier),
                ];

                continue;
            }

            /*
             * Arithmetic operators and parentheses.
             */
            if (in_array(
                $character,
                ['+', '-', '*', '/', '(', ')'],
                true
            )) {
                $tokens[] = [
                    'type' => 'OPERATOR',
                    'value' => $character,
                ];

                $position++;
                continue;
            }

            /*
             * Anything else is rejected.
             */
            throw new RuntimeException(
                "Invalid character '{$character}' in formula."
            );
        }

        return $tokens;
    }

    /**
     * Parse addition and subtraction.
     *
     * expression:
     *
     *     term
     *     term + term
     *     term - term
     *
     * @param  array<int, array{type:string,value:string}>  $tokens
     * @param  array<string, float>  $variables
     */
    private function parseExpression(
        array $tokens,
        int &$position,
        array $variables
    ): float {
        $result = $this->parseTerm(
            tokens: $tokens,
            position: $position,
            variables: $variables,
        );

        while (
            $position < count($tokens) &&
            in_array(
                $tokens[$position]['value'],
                ['+', '-'],
                true
            )
        ) {
            $operator = $tokens[$position]['value'];

            $position++;

            $right = $this->parseTerm(
                tokens: $tokens,
                position: $position,
                variables: $variables,
            );

            $result = match ($operator) {
                '+' => $result + $right,
                '-' => $result - $right,
            };

            $this->assertFiniteResult($result);
        }

        return $result;
    }

    /**
     * Parse multiplication and division.
     *
     * term:
     *
     *     factor
     *     factor * factor
     *     factor / factor
     *
     * @param  array<int, array{type:string,value:string}>  $tokens
     * @param  array<string, float>  $variables
     */
    private function parseTerm(
        array $tokens,
        int &$position,
        array $variables
    ): float {
        $result = $this->parseFactor(
            tokens: $tokens,
            position: $position,
            variables: $variables,
        );

        while (
            $position < count($tokens) &&
            in_array(
                $tokens[$position]['value'],
                ['*', '/'],
                true
            )
        ) {
            $operator = $tokens[$position]['value'];

            $position++;

            $right = $this->parseFactor(
                tokens: $tokens,
                position: $position,
                variables: $variables,
            );

            if ($operator === '/') {
                if ($right == 0.0) {
                    throw new RuntimeException(
                        'Division by zero is not allowed in tariff formulas.'
                    );
                }

                $result /= $right;
            } else {
                $result *= $right;
            }

            $this->assertFiniteResult($result);
        }

        return $result;
    }

    /**
     * Parse:
     *
     *     NUMBER
     *     IDENTIFIER
     *     ( expression )
     *     +factor
     *     -factor
     *
     * @param  array<int, array{type:string,value:string}>  $tokens
     * @param  array<string, float>  $variables
     */
    private function parseFactor(
        array $tokens,
        int &$position,
        array $variables
    ): float {
        if ($position >= count($tokens)) {
            throw new RuntimeException(
                'Unexpected end of formula.'
            );
        }

        $token = $tokens[$position];

        /*
         * Unary + / -
         */
        if (
            $token['type'] === 'OPERATOR' &&
            in_array(
                $token['value'],
                ['+', '-'],
                true
            )
        ) {
            $operator = $token['value'];

            $position++;

            $value = $this->parseFactor(
                tokens: $tokens,
                position: $position,
                variables: $variables,
            );

            $result = $operator === '-'
                ? -$value
                : $value;

            $this->assertFiniteResult($result);

            return $result;
        }

        /*
         * Number.
         */
        if ($token['type'] === 'NUMBER') {
            $position++;

            $value = (float) $token['value'];

            $this->assertFiniteResult($value);

            return $value;
        }

        /*
         * Configured formula variable.
         */
        if ($token['type'] === 'IDENTIFIER') {
            $position++;

            $name = strtoupper($token['value']);

            if (!array_key_exists($name, $variables)) {
                throw new RuntimeException(
                    "Unknown formula variable '{$name}'."
                );
            }

            return $variables[$name];
        }

        /*
         * Parenthesized expression.
         */
        if (
            $token['type'] === 'OPERATOR' &&
            $token['value'] === '('
        ) {
            $position++;

            $value = $this->parseExpression(
                tokens: $tokens,
                position: $position,
                variables: $variables,
            );

            if (
                $position >= count($tokens) ||
                $tokens[$position]['value'] !== ')'
            ) {
                throw new RuntimeException(
                    'Missing closing parenthesis in formula.'
                );
            }

            $position++;

            return $value;
        }

        throw new RuntimeException(
            "Unexpected token '{$token['value']}' in formula."
        );
    }

    /**
     * Make sure calculations never produce INF/NAN.
     */
    private function assertFiniteResult(float $value): void
    {
        if (!is_finite($value)) {
            throw new RuntimeException(
                'Tariff formula produced an invalid numeric result.'
            );
        }
    }
}

