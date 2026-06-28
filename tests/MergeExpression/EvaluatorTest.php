<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Tests\MergeExpression;

use MSpaceMedia\Newsletter\Service\MergeExpression\Evaluator;
use MSpaceMedia\Newsletter\Service\MergeExpression\Parser;
use SilverStripe\Dev\SapphireTest;

/**
 * Exercises the non-ORM behaviour of the evaluator (arithmetic, comparisons,
 * functions, filters, built-ins and the defined-field resolver). Relation
 * traversal against a real database is covered by MergeFieldEngineTest.
 */
class EvaluatorTest extends SapphireTest
{
    protected $usesDatabase = false;

    private function eval(string $expression, array $builtins = [], ?callable $resolver = null): mixed
    {
        $evaluator = new Evaluator([], $builtins, $resolver, '£');

        return $evaluator->evaluate((new Parser())->parse($expression), null);
    }

    public function testArithmeticPrecedence(): void
    {
        $this->assertSame(14.0, $this->eval('2 + 3 * 4'));
        $this->assertSame(2.5, $this->eval('10 / 4'));
        $this->assertSame(5.0, $this->eval('(2 + 3)'));
    }

    public function testDivisionByZeroIsNull(): void
    {
        $this->assertNull($this->eval('5 / 0'));
    }

    public function testConcatAndCaseFunctions(): void
    {
        $builtins = ['firstname' => 'Jane', 'surname' => 'Doe'];
        $this->assertSame('Jane Doe', $this->eval("Concat(FirstName, ' ', Surname)", $builtins));
        $this->assertSame('JANE', $this->eval('FirstName | upper', $builtins));
        $this->assertSame('doe', $this->eval('Surname | lower', $builtins));
    }

    public function testCurrencyAndNumberFilters(): void
    {
        $this->assertSame('£1,330.00', $this->eval('1330 | currency'));
        $this->assertSame('1,330', $this->eval('1330 | number'));
        $this->assertSame('3.14', $this->eval('3.14159 | round(2) | number(2)'));
    }

    public function testDefaultFilterAppliesOnlyToEmpty(): void
    {
        $this->assertSame('n/a', $this->eval("'' | default('n/a')"));
        $this->assertSame('set', $this->eval("'set' | default('n/a')"));
    }

    public function testConditionalFunctionAndComparisons(): void
    {
        $builtins = ['orders' => 35];
        $this->assertSame('donor', $this->eval("If(Orders > 0, 'donor', 'none')", $builtins));
        $this->assertSame('none', $this->eval("If(Orders > 100, 'donor', 'none')", $builtins));
    }

    public function testCoalesceReturnsFirstNonEmpty(): void
    {
        $this->assertSame('fallback', $this->eval("Coalesce('', 'fallback')"));
        $this->assertSame('first', $this->eval("Coalesce('first', 'second')"));
    }

    public function testDefinedFieldResolverIsConsulted(): void
    {
        $resolver = fn (string $tag): mixed => strtoupper($tag) === 'ORDERCOUNT' ? 35 : null;

        $this->assertSame(35, $this->eval('ORDERCOUNT', [], $resolver));
        // A defined field can feed into arithmetic.
        $this->assertSame(70.0, $this->eval('ORDERCOUNT * 2', [], $resolver));
    }

    public function testEmptyOrErroringDefinedFieldFallsThroughToBuiltin(): void
    {
        // A defined field named like a built-in that yields nothing (empty) or
        // throws must not blank the built-in — it falls through to it.
        $emptyResolver = fn (string $tag): mixed => strtoupper($tag) === 'FIRSTNAME' ? '' : null;
        $throwingResolver = function (string $tag): mixed {
            if (strtoupper($tag) === 'FIRSTNAME') {
                throw new \MSpaceMedia\Newsletter\Service\MergeExpression\ExpressionException('boom');
            }
            return null;
        };

        $this->assertSame('Jane', $this->eval('FirstName', ['firstname' => 'Jane'], $emptyResolver));
        $this->assertSame('Jane', $this->eval('FirstName', ['firstname' => 'Jane'], $throwingResolver));

        // A non-empty defined field still overrides the built-in.
        $overrideResolver = fn (string $tag): mixed => strtoupper($tag) === 'FIRSTNAME' ? 'Override' : null;
        $this->assertSame('Override', $this->eval('FirstName', ['firstname' => 'Jane'], $overrideResolver));
    }

    public function testTruthyHelper(): void
    {
        $evaluator = new Evaluator();
        $this->assertTrue($evaluator->truthy(1));
        $this->assertTrue($evaluator->truthy('yes'));
        $this->assertFalse($evaluator->truthy(0));
        $this->assertFalse($evaluator->truthy('0'));
        $this->assertFalse($evaluator->truthy(''));
        $this->assertFalse($evaluator->truthy(null));
    }
}
