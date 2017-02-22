<?php

namespace Benchmarks;


use Swaggest\JsonSchema\PreProcessor\NameMapper;
use Swaggest\JsonSchema\Tests\Helper\Order;

/**
 * @Revs({1000})
 * @Iterations(10)
 */
class SomeBenchmark
{
    public function benchImport()
    {
        $mapper = new NameMapper();

        $order = new Order();
        $order->id = 1;
        $order->dateTime = '2015-10-28T07:28:00Z';
        $exported = Order::export($order, $mapper);
        $imported = Order::import($exported, $mapper);
    }

}