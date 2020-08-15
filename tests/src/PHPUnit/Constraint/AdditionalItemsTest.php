<?php

namespace Swaggest\JsonSchema\Tests\PHPUnit\Constraint;


use Swaggest\JsonSchema\Exception\TypeException;
use Swaggest\JsonSchema\InvalidValue;
use Swaggest\JsonSchema\Schema;

class AdditionalItemsTest extends \PHPUnit\Framework\TestCase
{
    public function testAdditionalItemsAreNotAllowed()
    {
        $schema = Schema::import(
            (object)array(
                'items' => array(
                    new \stdClass(),
                    new \stdClass(),
                    new \stdClass(),
                ),
                'additionalItems' => false,
            )
        );

        $this->expectException(get_class(new InvalidValue()));
        $schema->in(array(1,2,3,4));
    }

    public function testEmptyPropertyName()
    {
        $schema = new Schema();
        $schema->additionalProperties = Schema::integer();

        if (PHP_VERSION_ID < 70100) {
            $this->expectException(get_class(new InvalidValue()));
            $this->expectExceptionMessage('Empty property name');
        }

        $data = (object)array('' => 1, 'a' => 2, 1 => 3);
        $schema->in($data);
        $schema->out($data);
        $this->assertTrue(true);
    }

}