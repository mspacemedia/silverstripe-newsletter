<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Tests\MergeExpression;

use MSpaceMedia\Newsletter\Service\MergeExpression\ExpressionException;
use MSpaceMedia\Newsletter\Service\MergeExpression\Parser;
use SilverStripe\Dev\SapphireTest;

class ParserTest extends SapphireTest
{
    protected $usesDatabase = false;

    /**
     * @dataProvider validExpressions
     */
    public function testParsesValidExpressions(string $expression, string $expectedRootType): void
    {
        $ast = (new Parser())->parse($expression);

        $this->assertSame($expectedRootType, $ast['t'], $expression);
    }

    /**
     * @return array<string, array{0:string, 1:string}>
     */
    public function validExpressions(): array
    {
        return [
            'aggregate sum' => ['Order.Sum(TotalDonation)', 'path'],
            'count no parens' => ['Order.Count', 'path'],
            'filtered aggregate' => ["Order.Where(Status = 'Paid').Sum(Amount)", 'path'],
            'arithmetic' => ['Order.Sum(Amount) / Order.Count', 'bin'],
            'function' => ["Concat(FirstName, ' ', Surname)", 'call'],
            'pipe filter' => ['Order.Sum(Amount) | currency', 'filter'],
            'comparison' => ['Order.Count > 0', 'cmp'],
            'nested function' => ["If(Order.Count > 0, 'donor', 'none')", 'call'],
            'precedence' => ['1 + 2 * 3', 'bin'],
        ];
    }

    public function testWhereStepCapturesFieldAndValue(): void
    {
        $ast = (new Parser())->parse("Order.Where(Status = 'Paid').Count");
        $where = $ast['steps'][1];

        $this->assertSame('where', $where['op']);
        $this->assertSame('Status', $where['field']);
        $this->assertSame('=', $where['cmp']);
        $this->assertSame('Paid', $where['value']);
    }

    public function testPipeFilterCapturesArguments(): void
    {
        $ast = (new Parser())->parse('Total | number(2)');

        $this->assertSame('filter', $ast['t']);
        $this->assertSame('number', $ast['name']);
        $this->assertSame(2.0, $ast['args'][0]['v']);
    }

    /**
     * @dataProvider invalidExpressions
     */
    public function testRejectsMalformedExpressions(string $expression): void
    {
        $this->expectException(ExpressionException::class);

        (new Parser())->parse($expression);
    }

    /**
     * @return array<string, array{0:string}>
     */
    public function invalidExpressions(): array
    {
        return [
            'empty' => [''],
            'trailing dot' => ['Order.'],
            'sum without field' => ['Order.Sum()'],
            'dangling operator' => ['1 +'],
            'unterminated string' => ["'oops"],
            'unbalanced paren' => ['Concat(FirstName'],
        ];
    }
}
