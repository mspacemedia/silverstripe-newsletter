<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Service\MergeExpression;

use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;

/**
 * Describes a DataObject's allowlisted relations and fields for the visual merge
 * field builder. The builder only offers what the project has exposed (allowlist
 * ∩ actual schema), and {@see Evaluator} re-checks the same allowlist at runtime,
 * so the UI is a convenience, never the security boundary.
 */
class Introspector
{
    use Injectable;

    /**
     * @param array<string, array{relations?: array<int,string>, fields?: array<int,string>}> $allowlist
     */
    public function __construct(private array $allowlist = [])
    {
    }

    /**
     * Describe a class for the builder.
     *
     * @return array{
     *   class: string,
     *   fields: array<int, array{name:string, type:string}>,
     *   relations: array<int, array{name:string, type:string, class:string}>
     * }
     */
    public function describe(string $class): array
    {
        if (!is_subclass_of($class, DataObject::class)) {
            return ['class' => $class, 'fields' => [], 'relations' => []];
        }

        return [
            'class' => $class,
            'fields' => $this->fields($class),
            'relations' => $this->relations($class),
        ];
    }

    /**
     * @return array<int, array{name:string, type:string}>
     */
    private function fields(string $class): array
    {
        $allowed = $this->allowedNames($class, 'fields');
        if ($allowed === []) {
            return [];
        }

        $schema = DataObject::getSchema();
        $out = [];

        foreach ($allowed as $name) {
            // Built-in pseudo-fields (ID, Created, LastEdited) won't appear in the
            // db spec but are still readable; default their type to a sensible label.
            $spec = $schema->fieldSpec($class, $name) ?? 'Field';
            $out[] = ['name' => $name, 'type' => $this->simplifyType((string) $spec)];
        }

        return $out;
    }

    /**
     * @return array<int, array{name:string, type:string, class:string}>
     */
    private function relations(string $class): array
    {
        $allowed = $this->allowedNames($class, 'relations');
        if ($allowed === []) {
            return [];
        }

        $schema = DataObject::getSchema();
        $out = [];

        foreach ($allowed as $name) {
            [$type, $relatedClass] = $this->relationMeta($schema, $class, $name);
            if ($relatedClass === null) {
                continue;
            }
            $out[] = ['name' => $name, 'type' => $type, 'class' => $relatedClass];
        }

        return $out;
    }

    /**
     * @return array{0:string, 1:?string} relation kind and related class
     */
    private function relationMeta(object $schema, string $class, string $name): array
    {
        if ($related = $schema->hasOneComponent($class, $name)) {
            return ['has_one', $related];
        }
        if ($related = $schema->hasManyComponent($class, $name)) {
            return ['has_many', $related];
        }
        if ($related = $schema->manyManyComponent($class, $name)) {
            // manyManyComponent returns an array describing the relation.
            $childClass = is_array($related) ? ($related['childClass'] ?? null) : $related;
            return ['many_many', is_string($childClass) ? $childClass : null];
        }

        return ['relation', null];
    }

    /**
     * Allowlisted names for a class, honouring parent-class entries.
     *
     * @return array<int, string>
     */
    private function allowedNames(string $class, string $kind): array
    {
        $names = [];
        foreach ($this->allowlist as $candidate => $config) {
            if (is_a($class, $candidate, true)) {
                foreach (($config[$kind] ?? []) as $name) {
                    $names[$name] = true;
                }
            }
        }

        return array_keys($names);
    }

    private function simplifyType(string $spec): string
    {
        $base = strtok($spec, '(');

        return $base === false ? $spec : $base;
    }
}
