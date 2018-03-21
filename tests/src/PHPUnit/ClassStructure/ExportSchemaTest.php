<?php

namespace Swaggest\JsonSchema\Tests\PHPUnit\ClassStructure;


use Swaggest\JsonSchema\Schema;
use Swaggest\JsonSchema\Tests\Helper\DbId;

class ExportSchemaTest extends \PHPUnit_Framework_TestCase
{
    public function testSchemaExport()
    {
        $schema = DbId::schema();
        $schemaData = Schema::export($schema);
        print_r($schemaData);
    }

}