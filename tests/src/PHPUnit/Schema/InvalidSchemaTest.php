<?php

namespace Swaggest\JsonSchema\Tests\PHPUnit\Schema;

use Swaggest\JsonSchema\Exception;
use Swaggest\JsonSchema\InvalidValue;
use Swaggest\JsonSchema\Schema;

class InvalidSchemaTest extends \PHPUnit\Framework\TestCase
{

    public function testValidationFailedWithInvalidSchema()
    {
        $this->expectException(get_class(new Exception()));
        $data = __DIR__ . '/../../../resources/invalid_json.json';
        $schema = Schema::import($data);
        $schema->in(json_decode(<<<'JSON'
{
    "id": 1,
    "name":"John Doe",
    "orders":[
        {
            "id":1
        },
        {
            "price":1.0
        }
    ]
}
JSON
        ));
    }

}
