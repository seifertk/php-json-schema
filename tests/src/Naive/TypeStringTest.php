<?php

namespace Swaggest\JsonSchema\Tests\Naive;

use Swaggest\JsonSchema\Exception\TypeException;
use Swaggest\JsonSchema\Schema;

class TypeStringTest extends \PHPUnit\Framework\TestCase
{
    public function testValid()
    {
        $schema = Schema::import(
            (object)array(
                'type' => 'string',
            )
        );
        $this->assertSame('123', $schema->in('123'));
    }

    public function testInvalidInteger()
    {
        $schema = Schema::import(
            (object)array(
                'type' => 'integer',
            )
        );
        $this->expectException(get_class(new TypeException()));
        $this->expectExceptionMessage('Integer expected, "123" received');
        $this->assertSame(123, $schema->in('123'));
    }
}