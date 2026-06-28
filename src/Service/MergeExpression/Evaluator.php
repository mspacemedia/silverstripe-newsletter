<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Service\MergeExpression;

use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;

/**
 * Walks a parsed AST against a single anchor record and returns a scalar value.
 *
 * Relation/field traversal is gated by an allowlist (class => relations/fields),
 * so an editor can never reach a property the project has not explicitly exposed
 * — the UI restricts what can be composed, and this re-validates server-side.
 * Aggregates and Where() run as parameterised ORM queries; there is no eval and
 * no string-built SQL.
 */
class Evaluator
{
    /**
     * @param array<string, array{relations?: array<int,string>, fields?: array<int,string>}> $allowlist
     * @param array<string, mixed> $builtins lowercased token => value (FirstName, Email, …)
     * @param (callable(string): mixed)|null $fieldResolver resolves a defined merge-field tag, or null if unknown
     */
    public function __construct(
        private array $allowlist = [],
        private array $builtins = [],
        private $fieldResolver = null,
        private string $currencySymbol = '£',
    ) {
    }

    /**
     * @param array<string, mixed> $ast
     */
    public function evaluate(array $ast, ?DataObject $anchor): mixed
    {
        return match ($ast['t']) {
            'num' => $ast['v'],
            'str' => $ast['v'],
            'neg' => $this->negate($this->evaluate($ast['v'], $anchor)),
            'bin' => $this->arithmetic($ast['op'], $this->evaluate($ast['l'], $anchor), $this->evaluate($ast['r'], $anchor)),
            'cmp' => $this->compare($ast['op'], $this->evaluate($ast['l'], $anchor), $this->evaluate($ast['r'], $anchor)),
            'call' => $this->call($ast['fn'], $ast['args'], $anchor),
            'filter' => $this->filter($ast['name'], $this->evaluate($ast['v'], $anchor), $ast['args'], $anchor),
            'path' => $this->path($ast['steps'], $anchor),
            default => throw new ExpressionException('Unknown node "' . ($ast['t'] ?? '?') . '".'),
        };
    }

    /**
     * Public truthiness so the template renderer can evaluate {{#if}} conditions.
     */
    public function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === null) {
            return false;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value !== 0.0;
        }

        $string = trim((string) $value);

        return $string !== '' && $string !== '0';
    }

    // ---- Paths ----------------------------------------------------------

    /**
     * @param array<int, array<string, mixed>> $steps
     */
    private function path(array $steps, ?DataObject $anchor): mixed
    {
        $first = $steps[0];

        // A single bare identifier may be a defined merge field or a built-in
        // (FirstName, Email, …) before it is treated as an anchor property.
        if (count($steps) === 1 && $first['op'] === 'name') {
            $key = strtolower($first['name']);

            if ($this->fieldResolver !== null) {
                // A defined field with the same name as a built-in must not blank
                // it: if the field yields nothing (null/empty) or errors, fall
                // through to the built-in / anchor field instead of returning ''.
                try {
                    $resolved = ($this->fieldResolver)($first['name']);
                } catch (ExpressionException $e) {
                    $resolved = null;
                }
                if ($resolved !== null && $resolved !== '') {
                    return $resolved;
                }
            }

            if (array_key_exists($key, $this->builtins)) {
                return $this->builtins[$key];
            }
        }

        // Everything else traverses the anchor record. No anchor → null so the
        // caller's fallback/default can take over.
        if ($anchor === null || !$anchor->exists()) {
            return null;
        }

        $current = $anchor;

        foreach ($steps as $step) {
            if ($current === null) {
                return null;
            }

            $current = match ($step['op']) {
                'name' => $this->traverseName($current, $step['name']),
                'agg' => $this->aggregate($current, $step['fn'], $step['arg'] ?? null),
                'where' => $this->where($current, $step['field'], $step['cmp'], $step['value']),
                default => throw new ExpressionException('Unknown path step.'),
            };
        }

        if ($current instanceof DataObject || $current instanceof DataList) {
            throw new ExpressionException('Expression must resolve to a value, not a record or list. Add a field or aggregate (e.g. .Count).');
        }

        return $current;
    }

    private function traverseName(mixed $current, string $name): mixed
    {
        if (!$current instanceof DataObject) {
            throw new ExpressionException('Cannot read "' . $name . '" from a list. Use an aggregate such as .Sum(' . $name . ') or .Count.');
        }

        $class = get_class($current);

        if ($this->isAllowed($class, 'relations', $name)) {
            return $current->$name();
        }

        if ($this->isAllowed($class, 'fields', $name)) {
            return $current->getField($name);
        }

        throw new ExpressionException('"' . $name . '" is not an exposed field or relation on ' . self::shortName($class) . '.');
    }

    private function aggregate(mixed $current, string $fn, ?string $field): mixed
    {
        if (!$current instanceof DataList) {
            throw new ExpressionException(ucfirst($fn) . '() can only be used on a relation list.');
        }

        if ($fn === 'count') {
            return $current->count();
        }

        if ($fn === 'first') {
            return $current->first();
        }

        // sum/avg/min/max need an allowed numeric field on the list's class.
        if ($field === null) {
            throw new ExpressionException(ucfirst($fn) . '() requires a field.');
        }
        $this->assertAllowed($current->dataClass(), 'fields', $field);

        return match ($fn) {
            'sum' => $current->sum($field),
            'avg' => $current->avg($field),
            'min' => $current->min($field),
            'max' => $current->max($field),
            default => throw new ExpressionException('Unknown aggregate "' . $fn . '".'),
        };
    }

    private function where(mixed $current, string $field, string $cmp, mixed $value): DataList
    {
        if (!$current instanceof DataList) {
            throw new ExpressionException('Where() can only filter a relation list.');
        }
        $this->assertAllowed($current->dataClass(), 'fields', $field);

        $modifier = match ($cmp) {
            '=' => $field,
            '!=' => $field . ':not',
            '>' => $field . ':GreaterThan',
            '<' => $field . ':LessThan',
            '>=' => $field . ':GreaterThanOrEqual',
            '<=' => $field . ':LessThanOrEqual',
            default => throw new ExpressionException('Unsupported comparison in Where().'),
        };

        // Array filter is fully parameterised by the ORM.
        return $current->filter([$modifier => $value]);
    }

    // ---- Functions & filters -------------------------------------------

    /**
     * @param array<int, array<string, mixed>> $args
     */
    private function call(string $fn, array $args, ?DataObject $anchor): mixed
    {
        $values = array_map(fn ($node) => $this->evaluate($node, $anchor), $args);

        return match ($fn) {
            'concat' => implode('', array_map(static fn ($v) => (string) ($v ?? ''), $values)),
            'upper' => strtoupper((string) ($values[0] ?? '')),
            'lower' => strtolower((string) ($values[0] ?? '')),
            'round' => round((float) ($values[0] ?? 0), (int) ($values[1] ?? 0)),
            'if' => $this->truthy($values[0] ?? null) ? ($values[1] ?? null) : ($values[2] ?? null),
            'coalesce' => $this->coalesce($values),
            default => throw new ExpressionException('Unknown function "' . $fn . '".'),
        };
    }

    /**
     * @param array<int, mixed> $values
     */
    private function coalesce(array $values): mixed
    {
        foreach ($values as $value) {
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return $values === [] ? null : end($values);
    }

    /**
     * @param array<int, array<string, mixed>> $args
     */
    private function filter(string $name, mixed $value, array $args, ?DataObject $anchor): mixed
    {
        $argValues = array_map(fn ($node) => $this->evaluate($node, $anchor), $args);

        return match ($name) {
            'default' => ($value === null || $value === '') ? ($argValues[0] ?? '') : $value,
            'currency' => $this->currencySymbol . number_format((float) $value, (int) ($argValues[0] ?? 2)),
            'number' => number_format((float) $value, (int) ($argValues[0] ?? 0)),
            'round' => round((float) $value, (int) ($argValues[0] ?? 0)),
            'upper' => strtoupper((string) $value),
            'lower' => strtolower((string) $value),
            'date' => $this->formatDate($value, (string) ($argValues[0] ?? 'd/m/Y')),
            default => throw new ExpressionException('Unknown filter "| ' . $name . '".'),
        };
    }

    private function formatDate(mixed $value, string $format): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $timestamp = is_numeric($value) ? (int) $value : strtotime((string) $value);

        return $timestamp === false ? (string) $value : date($format, $timestamp);
    }

    // ---- Arithmetic / comparison ---------------------------------------

    private function negate(mixed $value): mixed
    {
        return $value === null ? null : -(float) $value;
    }

    private function arithmetic(string $op, mixed $left, mixed $right): mixed
    {
        if ($left === null || $right === null) {
            return null;
        }

        $l = (float) $left;
        $r = (float) $right;

        return match ($op) {
            '+' => $l + $r,
            '-' => $l - $r,
            '*' => $l * $r,
            '/' => $r == 0.0 ? null : $l / $r,
            default => throw new ExpressionException('Unknown operator "' . $op . '".'),
        };
    }

    private function compare(string $op, mixed $left, mixed $right): bool
    {
        if (is_numeric($left) && is_numeric($right)) {
            $left = (float) $left;
            $right = (float) $right;
        } else {
            $left = (string) ($left ?? '');
            $right = (string) ($right ?? '');
        }

        return match ($op) {
            '=' => $left == $right,
            '!=' => $left != $right,
            '>' => $left > $right,
            '<' => $left < $right,
            '>=' => $left >= $right,
            '<=' => $left <= $right,
            default => throw new ExpressionException('Unknown comparison "' . $op . '".'),
        };
    }

    // ---- Allowlist ------------------------------------------------------

    private function isAllowed(string $class, string $kind, string $name): bool
    {
        foreach ($this->classCandidates($class) as $candidate) {
            $names = $this->allowlist[$candidate][$kind] ?? [];
            foreach ($names as $allowed) {
                if (strcasecmp($allowed, $name) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    private function assertAllowed(string $class, string $kind, string $name): void
    {
        if (!$this->isAllowed($class, $kind, $name)) {
            $label = $kind === 'fields' ? 'field' : 'relation';
            throw new ExpressionException('"' . $name . '" is not an exposed ' . $label . ' on ' . self::shortName($class) . '.');
        }
    }

    /**
     * Allow an allowlist entry on a parent class to cover subclasses.
     *
     * @return array<int, string>
     */
    private function classCandidates(string $class): array
    {
        $candidates = [$class];
        foreach (array_keys($this->allowlist) as $candidate) {
            if ($candidate !== $class && is_a($class, $candidate, true)) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    private static function shortName(string $class): string
    {
        $parts = explode('\\', $class);

        return end($parts) ?: $class;
    }
}
