<?php

namespace Swaggest\JsonSchema\Tests\PHPUnit\Constraint;

use Swaggest\JsonSchema\Schema;

class DataTest extends \PHPUnit_Framework_TestCase
{
    public function testSimple()
    {
        $schemaData = json_decode(<<<'JSON'
{
  "properties": {
    "smaller": {
      "type": "number",
      "maximum": {"$data": "1/larger"}
    },
    "larger": {"type": "number"}
  }
}
JSON
        );

        $schema = Schema::import($schemaData);

        $val = (object)[
            'smaller' => 2,
            'larger' => 5,
        ];

        $schema->in($val);

    }

}