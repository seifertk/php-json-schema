<?php

namespace Swaggest\JsonSchema\Tests\PHPUnit;


use Swaggest\JsonSchema\InvalidValue;
use Swaggest\JsonSchema\Schema;

class StringTest extends \PHPUnit_Framework_TestCase
{
    public function testStringSchema()
    {
        //debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        //die();
        $schema = Schema::string();
        $schema->import('123');
    }

    public function testStringSchemaException()
    {
        $this->numIterations = 1000;
        $schema = Schema::string();
        $this->setExpectedException(get_class(new InvalidValue), 'String expected, 123 received');
        $schema->import(123);
    }

}