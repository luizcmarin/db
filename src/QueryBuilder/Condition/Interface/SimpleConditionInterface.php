<?php

declare(strict_types=1);

namespace Yiisoft\Db\QueryBuilder\Condition\Interface;

use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\QueryInterface;

interface SimpleConditionInterface extends ConditionInterface
{
    /**
     * @psalm-return string|Expression|QueryInterface The column name. If it is an array, a composite `IN` condition
     * will be generated.
     */
    public function getColumn(): string|Expression|QueryInterface;

    /**
     * @return string The operator to use. Anything could be used e.g. `>`, `<=`, etc.
     */
    public function getOperator(): string;

    /**
     * @return mixed The value to the right of the {@see operator}.
     */
    public function getValue(): mixed;
}