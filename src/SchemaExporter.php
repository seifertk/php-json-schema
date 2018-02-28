<?php

namespace Swaggest\JsonSchema;


interface SchemaExporter
{
    /**
     * @return SchemaContract
     */
    public function exportSchema();
}