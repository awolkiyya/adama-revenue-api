<?php

namespace App\Services\Calculations;

use App\Models\TariffFormulaVariable;
use App\Models\TariffRule;
use InvalidArgumentException;
use RuntimeException;

class FormulaCalculationEngine
{
    /*
    |--------------------------------------------------------------------------
    | Public API
    |--------------------------------------------------------------------------
    |
    | Calculates a FORMULA tariff rule using:
    |
    |     1. TariffRule::formula
    |     2. tariff_formula_variables
    |     3. AssessmentService values resolved by BaseField UUID
    |
    | Example:
    |
    |     LAND_AREA * 3.70 * LIZZ_PERIOD
    |
    */

    /**
     * Calculate a formula tariff.
     *
     * @param  TariffRule  $rule
     * @param  array<string, mixed>  $values
     */
    public function calculate(
        TariffRule $rule,
        array $values
    ): float {
        if ($rule->calculation_type !== 'FORMULA') {
            throw new InvalidArgumentException(
                "Tariff rule {$rule->id} is not a FORMULA rule."
            );
        }

        $formula = trim((string) $rule->formula);

        if ($formula === '') {
            throw new RuntimeException(
                "FORMULA tariff rule {$rule->id} requires a formula."
            );
        }

        /*
         * Ensure formula variables are available.
         */
        $rule->loadMissing('formulaVariables');

        $variables = $this->resolveVariables(
            $rule->formulaVariables,
            $values
        );

        /*
         * Evaluate the formula using our restricted parser.
         *
         * No eval().
         * No PHP execution.
         * No arbitrary function calls.
         */
        $result = $this->evaluate(
            formula: $formula,
            variables: $variables,
        );

        if (!is_finite($result)) {
            throw new RuntimeException(
                "Formula for tariff rule {$rule->id} produced a non-finite result."
            );
        }

        return $result;
    }

    /**
     * Resolve formula variables into:
     *
     * [
     *     'LAND_AREA' => 1000,
     *     'LIZZ_PERIOD' => 12,
     *     'RATE' => 3.70,
     * ]
     *
     * @param  iterable<TariffFormulaVariable>  $formulaVariables
     * @param  array<string, mixed>  $values
     * @return array<string, float>
     */
    private function resolveVariables(
        iterable $formulaVariables,
        array $values
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
             * Variable names are case-insensitive.
             *
             * LAND_AREA
             * land_area
             * Land_Area
             *
             * are treated as the same formula variable.
             */
            $normalizedName = strtoupper($name);

            if (array_key_exists($normalizedName, $resolved)) {
                throw new RuntimeException(
                    "Duplicate formula variable '{$name}' found for tariff rule {$variable->tariff_rule_id}."
                );
            }

            $resolved[$normalizedName] = $this->resolveVariableValue(
                $variable,
                $values
            );
        }

        return $resolved;
    }

    /**
     * Resolve one formula variable.
     *
     * BASE_FIELD:
     *     Resolve value from assessment values using base_field_id.
     *
     * CONSTANT:
     *     Resolve value from default_value.
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
                $variable,
                $values
            ),

            'CONSTANT' => $this->resolveConstantVariable(
                $variable
            ),

            default => throw new RuntimeException(
                "Unsupported formula variable source type '{$variable->source_type}' " .
                "for variable '{$variable->variable_name}'."
            ),
        };
    }

    /**
     * Resolve BASE_FIELD variable.
     */
    private function resolveBaseFieldVariable(
        TariffFormulaVariable $variable,
        array $values
    ): float {
        $variableName = (string) $variable->variable_name;

        if (!$variable->base_field_id) {
            throw new RuntimeException(
                "Formula variable '{$variableName}' is configured as BASE_FIELD " .
                'but has no base_field_id.'
            );
        }

        $baseFieldId = (string) $variable->base_field_id;

        /*
         * Assessment values are already normalized by
         * TariffCalculator::buildValueMap().
         *
         * Therefore the expected structure is:
         *
         * [
         *     '<base_field_uuid>' => value
         * ]
         */
        if (array_key_exists($baseFieldId, $values)) {
            return $this->normalizeNumericValue(
                $values[$baseFieldId],
                $variableName
            );
        }

        /*
         * Optional variable with a configured default.
         */
        if (!$variable->is_required && $variable->default_value !== null) {
            return $this->normalizeNumericValue(
                $variable->default_value,
                $variableName
            );
        }

        if (!$variable->is_required) {
            return 0.0;
        }

        throw new RuntimeException(
            "Required formula variable '{$variableName}' " .
            "could not be resolved from base field '{$baseFieldId}'."
        );
    }

    /**
     * Resolve CONSTANT variable.
     */
    private function resolveConstantVariable(
        TariffFormulaVariable $variable
    ): float {
        $variableName = (string) $variable->variable_name;

        if ($variable->default_value === null) {
            if (!$variable->is_required) {
                return 0.0;
            }

            throw new RuntimeException(
                "Required CONSTANT formula variable '{$variableName}' " .
                'has no default_value.'
            );
        }

        return $this->normalizeNumericValue(
            $variable->default_value,
            $variableName
        );
    }

    /**
     * Convert a resolved value to a numeric value.
     */
    private function normalizeNumericValue(
        mixed $value,
        string $variableName
    ): float {
        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }

        if (is_int($value) || is_float($value)) {
            $numeric = (float) $value;
        } elseif (is_string($value) && is_numeric(trim($value))) {
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
    |     variables outside configured variables
    |     strings
    |     arrays
    |     assignments
    |     eval()
    |
    */

    /**
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
             * 10
             * 10.5
             * .5
             * 10.
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
             * LAND_AREA
             * LIZZ_PERIOD
             * RATE
             *
             * Allowed:
             * A-Z
             * a-z
             * 0-9
             * _
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
             * Operators.
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

            throw new RuntimeException(
                "Invalid character '{$character}' in formula."
            );
        }

        return $tokens;
    }

    /**
     * Parse addition/subtraction.
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
     * Parse multiplication/division.
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
     * Parse numbers, variables, parentheses and unary +/-.
     *
     * factor:
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

            return $operator === '-'
                ? -$value
                : $value;
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
         * Variable.
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
