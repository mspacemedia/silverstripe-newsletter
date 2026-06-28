<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Service\MergeExpression;

/**
 * Lexes and parses the newsletter merge-field expression language into an array
 * AST. Pure and side-effect free (no ORM/DB access) so it is cheap to cache and
 * trivial to unit-test; {@see Evaluator} walks the AST against a real record.
 *
 * Grammar (lowest precedence first):
 *
 *   pipe       := comparison ('|' filter)*
 *   filter     := IDENT ['(' args ')']
 *   comparison := add ( ('='|'=='|'!='|'>'|'<'|'>='|'<=') add )?
 *   add        := mul ( ('+'|'-') mul )*
 *   mul        := unary ( ('*'|'/') unary )*
 *   unary      := '-' unary | primary
 *   primary    := NUMBER | STRING | '(' pipe ')' | call | path
 *   call       := FUNC '(' args ')'                       // Concat/Round/If/Coalesce/Upper/Lower
 *   path       := IDENT ( '.' step )*
 *   step       := IDENT                                   // relation or field
 *               | AGG [ '(' [IDENT] ')' ]                 // Sum/Avg/Min/Max/Count/First
 *               | 'Where' '(' IDENT CMP literal ')'       // filtered relation
 *
 * AST node shapes (all arrays keyed by 't'):
 *   ['t'=>'num','v'=>float]
 *   ['t'=>'str','v'=>string]
 *   ['t'=>'bin','op'=>'+|-|*|/','l'=>node,'r'=>node]
 *   ['t'=>'cmp','op'=>'=|!=|>|<|>=|<=','l'=>node,'r'=>node]
 *   ['t'=>'neg','v'=>node]
 *   ['t'=>'call','fn'=>'concat|round|if|coalesce|upper|lower','args'=>node[]]
 *   ['t'=>'filter','name'=>string,'args'=>node[],'v'=>node]
 *   ['t'=>'path','steps'=>step[]]
 *     name step:  ['op'=>'name','name'=>string]
 *     agg step:   ['op'=>'agg','fn'=>'sum|avg|min|max|count|first','arg'=>?string]
 *     where step: ['op'=>'where','field'=>string,'cmp'=>string,'value'=>scalar]
 */
final class Parser
{
    // 'if' is a retained alias for 'select' (the documented inline picker).
    private const FUNCTIONS = ['concat', 'round', 'select', 'if', 'coalesce', 'upper', 'lower'];

    private const AGGREGATES = ['sum', 'avg', 'min', 'max', 'count', 'first'];

    private const COMPARISONS = ['=', '==', '!=', '>', '<', '>=', '<='];

    /** @var array<int, array{type:string, value:string}> */
    private array $tokens = [];

    private int $pos = 0;

    /**
     * Parse an expression string into an AST.
     *
     * @return array<string, mixed>
     * @throws ExpressionException on malformed input
     */
    public function parse(string $expression): array
    {
        $this->tokens = $this->tokenise($expression);
        $this->pos = 0;

        if ($this->tokens === []) {
            throw new ExpressionException('Empty expression.');
        }

        $node = $this->parsePipe();

        if ($this->peek() !== null) {
            throw new ExpressionException('Unexpected "' . $this->peek()['value'] . '" after expression.');
        }

        return $node;
    }

    // ---- Lexer ----------------------------------------------------------

    /**
     * @return array<int, array{type:string, value:string}>
     */
    private function tokenise(string $input): array
    {
        $tokens = [];
        $length = strlen($input);
        $i = 0;

        while ($i < $length) {
            $char = $input[$i];

            if (ctype_space($char)) {
                $i++;
                continue;
            }

            // String literal (single or double quoted), with \' \" \\ escapes.
            if ($char === "'" || $char === '"') {
                [$value, $i] = $this->readString($input, $i, $char);
                $tokens[] = ['type' => 'string', 'value' => $value];
                continue;
            }

            // Number (integer or decimal).
            if (ctype_digit($char) || ($char === '.' && $i + 1 < $length && ctype_digit($input[$i + 1]))) {
                $start = $i;
                while ($i < $length && (ctype_digit($input[$i]) || $input[$i] === '.')) {
                    $i++;
                }
                $tokens[] = ['type' => 'number', 'value' => substr($input, $start, $i - $start)];
                continue;
            }

            // Identifier (letter/underscore then alphanumerics/underscore).
            if (ctype_alpha($char) || $char === '_') {
                $start = $i;
                while ($i < $length && (ctype_alnum($input[$i]) || $input[$i] === '_')) {
                    $i++;
                }
                $tokens[] = ['type' => 'ident', 'value' => substr($input, $start, $i - $start)];
                continue;
            }

            // Two-character comparison operators.
            $two = substr($input, $i, 2);
            if (in_array($two, ['==', '!=', '>=', '<='], true)) {
                $tokens[] = ['type' => 'op', 'value' => $two];
                $i += 2;
                continue;
            }

            // Single-character operators / punctuation.
            if (str_contains('+-*/().,|=<>', $char)) {
                $tokens[] = ['type' => 'op', 'value' => $char];
                $i++;
                continue;
            }

            throw new ExpressionException('Unexpected character "' . $char . '".');
        }

        return $tokens;
    }

    /**
     * @return array{0:string, 1:int} the decoded value and the index after the closing quote
     */
    private function readString(string $input, int $i, string $quote): array
    {
        $length = strlen($input);
        $value = '';
        $i++; // opening quote

        while ($i < $length) {
            $char = $input[$i];

            if ($char === '\\' && $i + 1 < $length) {
                $next = $input[$i + 1];
                $value .= match ($next) {
                    'n' => "\n",
                    't' => "\t",
                    default => $next,
                };
                $i += 2;
                continue;
            }

            if ($char === $quote) {
                return [$value, $i + 1];
            }

            $value .= $char;
            $i++;
        }

        throw new ExpressionException('Unterminated string literal.');
    }

    // ---- Parser ---------------------------------------------------------

    /**
     * @return array{type:string, value:string}|null
     */
    private function peek(): ?array
    {
        return $this->tokens[$this->pos] ?? null;
    }

    private function isOp(string $value): bool
    {
        $token = $this->peek();

        return $token !== null && $token['type'] === 'op' && $token['value'] === $value;
    }

    /**
     * @return array{type:string, value:string}
     */
    private function consume(): array
    {
        $token = $this->peek();
        if ($token === null) {
            throw new ExpressionException('Unexpected end of expression.');
        }
        $this->pos++;

        return $token;
    }

    private function expectOp(string $value): void
    {
        if (!$this->isOp($value)) {
            $found = $this->peek()['value'] ?? 'end of expression';
            throw new ExpressionException('Expected "' . $value . '" but found "' . $found . '".');
        }
        $this->pos++;
    }

    /**
     * @return array<string, mixed>
     */
    private function parsePipe(): array
    {
        $node = $this->parseComparison();

        while ($this->isOp('|')) {
            $this->consume();
            $name = $this->consume();
            if ($name['type'] !== 'ident') {
                throw new ExpressionException('Expected a filter name after "|".');
            }

            $args = [];
            if ($this->isOp('(')) {
                $args = $this->parseArgs();
            }

            $node = ['t' => 'filter', 'name' => strtolower($name['value']), 'args' => $args, 'v' => $node];
        }

        return $node;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseComparison(): array
    {
        $left = $this->parseAdd();

        $token = $this->peek();
        if ($token !== null && $token['type'] === 'op' && in_array($token['value'], self::COMPARISONS, true)) {
            $op = $this->consume()['value'];
            $right = $this->parseAdd();

            return ['t' => 'cmp', 'op' => $op === '==' ? '=' : $op, 'l' => $left, 'r' => $right];
        }

        return $left;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseAdd(): array
    {
        $node = $this->parseMul();

        while ($this->isOp('+') || $this->isOp('-')) {
            $op = $this->consume()['value'];
            $node = ['t' => 'bin', 'op' => $op, 'l' => $node, 'r' => $this->parseMul()];
        }

        return $node;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseMul(): array
    {
        $node = $this->parseUnary();

        while ($this->isOp('*') || $this->isOp('/')) {
            $op = $this->consume()['value'];
            $node = ['t' => 'bin', 'op' => $op, 'l' => $node, 'r' => $this->parseUnary()];
        }

        return $node;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseUnary(): array
    {
        if ($this->isOp('-')) {
            $this->consume();

            return ['t' => 'neg', 'v' => $this->parseUnary()];
        }

        return $this->parsePrimary();
    }

    /**
     * @return array<string, mixed>
     */
    private function parsePrimary(): array
    {
        $token = $this->peek();
        if ($token === null) {
            throw new ExpressionException('Unexpected end of expression.');
        }

        if ($token['type'] === 'number') {
            $this->consume();

            return ['t' => 'num', 'v' => (float) $token['value']];
        }

        if ($token['type'] === 'string') {
            $this->consume();

            return ['t' => 'str', 'v' => $token['value']];
        }

        if ($this->isOp('(')) {
            $this->consume();
            $node = $this->parsePipe();
            $this->expectOp(')');

            return $node;
        }

        if ($token['type'] === 'ident') {
            // Function call vs. path: a known function name immediately followed
            // by "(" is a call; anything else starts a relation/field path.
            if (
                in_array(strtolower($token['value']), self::FUNCTIONS, true)
                && ($this->tokens[$this->pos + 1]['value'] ?? null) === '('
                && ($this->tokens[$this->pos + 1]['type'] ?? null) === 'op'
            ) {
                return $this->parseCall();
            }

            return $this->parsePath();
        }

        throw new ExpressionException('Unexpected "' . $token['value'] . '".');
    }

    /**
     * @return array<string, mixed>
     */
    private function parseCall(): array
    {
        $name = strtolower($this->consume()['value']);
        $args = $this->parseArgs();

        return ['t' => 'call', 'fn' => $name, 'args' => $args];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseArgs(): array
    {
        $this->expectOp('(');
        $args = [];

        if (!$this->isOp(')')) {
            $args[] = $this->parsePipe();
            while ($this->isOp(',')) {
                $this->consume();
                $args[] = $this->parsePipe();
            }
        }

        $this->expectOp(')');

        return $args;
    }

    /**
     * @return array<string, mixed>
     */
    private function parsePath(): array
    {
        $first = $this->consume();
        $steps = [['op' => 'name', 'name' => $first['value']]];

        while ($this->isOp('.')) {
            $this->consume();
            $name = $this->consume();
            if ($name['type'] !== 'ident') {
                throw new ExpressionException('Expected a property name after ".".');
            }

            $lower = strtolower($name['value']);

            if ($lower === 'where') {
                $steps[] = $this->parseWhere();
                continue;
            }

            if (in_array($lower, self::AGGREGATES, true)) {
                $steps[] = $this->parseAggregate($lower);
                continue;
            }

            $steps[] = ['op' => 'name', 'name' => $name['value']];
        }

        return ['t' => 'path', 'steps' => $steps];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseAggregate(string $fn): array
    {
        $arg = null;

        // Count may be written with or without parentheses; the others take an
        // optional field argument: Sum(Amount).
        if ($this->isOp('(')) {
            $this->consume();
            if (!$this->isOp(')')) {
                $field = $this->consume();
                if ($field['type'] !== 'ident') {
                    throw new ExpressionException('Expected a field name inside ' . $fn . '().');
                }
                $arg = $field['value'];
            }
            $this->expectOp(')');
        }

        if ($fn !== 'count' && $fn !== 'first' && $arg === null) {
            throw new ExpressionException(ucfirst($fn) . '() requires a field, e.g. ' . ucfirst($fn) . '(Amount).');
        }

        return ['op' => 'agg', 'fn' => $fn, 'arg' => $arg];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseWhere(): array
    {
        $this->expectOp('(');

        $field = $this->consume();
        if ($field['type'] !== 'ident') {
            throw new ExpressionException('Where() must start with a field name.');
        }

        $cmpToken = $this->consume();
        $cmp = $cmpToken['value'] === '==' ? '=' : $cmpToken['value'];
        if ($cmpToken['type'] !== 'op' || !in_array($cmp, self::COMPARISONS, true)) {
            throw new ExpressionException('Where() needs a comparison operator (=, !=, >, <, >=, <=).');
        }

        $valueToken = $this->consume();
        if ($valueToken['type'] === 'string') {
            $value = $valueToken['value'];
        } elseif ($valueToken['type'] === 'number') {
            $value = $valueToken['value'] + 0;
        } else {
            throw new ExpressionException('Where() value must be a number or quoted string.');
        }

        $this->expectOp(')');

        return ['op' => 'where', 'field' => $field['value'], 'cmp' => $cmp, 'value' => $value];
    }
}
