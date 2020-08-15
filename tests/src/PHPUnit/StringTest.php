<?php

namespace Swaggest\JsonSchema\Tests\PHPUnit;


use Swaggest\JsonSchema\InvalidValue;
use Swaggest\JsonSchema\Schema;

class StringTest extends \PHPUnit\Framework\TestCase
{
    public function testStringSchema()
    {
        $schema = Schema::string();
        $schema->in('123');
        $this->assertTrue(true);
    }

    public function testStringSchemaException()
    {
        $schema = Schema::string();
        $this->expectException(get_class(new InvalidValue));
        $this->expectExceptionMessage('String expected, 123 received');
        $schema->in(123);
        $this->assertTrue(true);
    }

}