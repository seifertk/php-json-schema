<?php

namespace Swaggest\JsonSchema;


use PhpLang\ScopeExit;
use Swaggest\JsonDiff\JsonDiff;
use Swaggest\JsonDiff\JsonPointer;
use Swaggest\JsonSchema\Constraint\Content;
use Swaggest\JsonSchema\Constraint\Format;
use Swaggest\JsonSchema\Constraint\Properties;
use Swaggest\JsonSchema\Constraint\Type;
use Swaggest\JsonSchema\Constraint\UniqueItems;
use Swaggest\JsonSchema\Exception\ArrayException;
use Swaggest\JsonSchema\Exception\ConstException;
use Swaggest\JsonSchema\Exception\EnumException;
use Swaggest\JsonSchema\Exception\LogicException;
use Swaggest\JsonSchema\Exception\NumericException;
use Swaggest\JsonSchema\Exception\ObjectException;
use Swaggest\JsonSchema\Exception\StringException;
use Swaggest\JsonSchema\Exception\TypeException;
use Swaggest\JsonSchema\Meta\MetaHolder;
use Swaggest\JsonSchema\Path\PointerUtil;
use Swaggest\JsonSchema\Structure\ClassStructure;
use Swaggest\JsonSchema\Structure\Egg;
use Swaggest\JsonSchema\Structure\ObjectItem;
use Swaggest\JsonSchema\Structure\ObjectItemContract;

/**
 * Class Schema
 * @package Swaggest\JsonSchema
 */
class Schema extends JsonSchema implements MetaHolder, SchemaContract
{
    const ENUM_NAMES_PROPERTY = 'x-enum-names';
    const CONST_PROPERTY = 'const';

    const DEFAULT_MAPPING = 'default';

    const VERSION_AUTO = 'a';
    const VERSION_DRAFT_04 = 4;
    const VERSION_DRAFT_06 = 6;
    const VERSION_DRAFT_07 = 7;

    const PROP_REF = '$ref';
    const PROP_ID = '$id';
    const PROP_ID_D4 = 'id';

    // Object
    /** @var null|Properties|Schema[]|Schema */
    public $properties;
    /** @var Schema|bool */
    public $additionalProperties;
    /** @var Schema[]|Properties */
    public $patternProperties;
    /** @var string[][]|Schema[]|\stdClass */
    public $dependencies;

    // Array
    /** @var null|Schema|Schema[] */
    public $items;
    /** @var null|Schema|bool */
    public $additionalItems;

    /** @var Schema[] */
    public $allOf;
    /** @var Schema */
    public $not;
    /** @var Schema[] */
    public $anyOf;
    /** @var Schema[] */
    public $oneOf;

    /** @var Schema */
    public $if;
    /** @var Schema */
    public $then;
    /** @var Schema */
    public $else;


    public $objectItemClass;
    private $useObjectAsArray = false;

    private $__dataToProperty = array();
    private $__propertyToData = array();

    private $__booleanSchema;

    public function addPropertyMapping($dataName, $propertyName, $mapping = self::DEFAULT_MAPPING)
    {
        $this->__dataToProperty[$mapping][$dataName] = $propertyName;
        $this->__propertyToData[$mapping][$propertyName] = $dataName;

        if ($mapping === self::DEFAULT_MAPPING && $this->properties instanceof Properties) {
            $this->properties->__defaultMapping[$propertyName] = $dataName;
        }
        return $this;
    }

    /**
     * @param mixed $data
     * @param Context|null $context
     * @return SchemaContract
     * @throws Exception
     * @throws InvalidValue
     * @throws \Exception
     */
    public static function import($data, Context $context = null)
    {
        if (null === $context) {
            $context = new Context();
        }

        $context->applyDefaults = false;

        if (isset($context->schemasCache) && is_object($data)) {
            if ($context->schemasCache->contains($data)) {
                return $context->schemasCache->offsetGet($data);
            } else {
                $schema = parent::import($data, $context);
                $context->schemasCache->attach($data, $schema);
                return $schema;
            }
        }

        // string $data is expected to be $ref uri
        if (is_string($data)) {
            $data = (object)array(self::PROP_REF => $data);
        }

        $data = self::unboolSchema($data);
        if ($data instanceof SchemaContract) {
            return $data;
        }

        return parent::import($data, $context);
    }

    /**
     * @param mixed $data
     * @param Context|null $options
     * @return array|mixed|null|object|\stdClass
     * @throws Exception
     * @throws InvalidValue
     * @throws \Exception
     */
    public function in($data, Context $options = null)
    {
        if (null !== $this->__booleanSchema) {
            if ($this->__booleanSchema) {
                return $data;
            }

            if (empty($options->skipValidation)) {
                $this->fail(new InvalidValue('Denied by false schema'), '#');
            }
        }

        if ($options === null) {
            $options = new Context();
        }

        $options->import = true;
        $options->result = null;
        $options->path = '#';

        if ($options->refResolver === null) {
            $options->refResolver = new RefResolver($data);
        } else {
            $options->refResolver->setRootData($data);
        }

        if ($options->remoteRefProvider) {
            $options->refResolver->setRemoteRefProvider($options->remoteRefProvider);
        }

        $options->refResolver->preProcessReferences($data, $options);

        return $this->process($data, $options);
    }


    /**
     * @param mixed $data
     * @param Context|null $context
     * @return array|mixed|null|object|\stdClass
     * @throws InvalidValue
     * @throws \Exception
     */
    public function out($data, Context $context = null)
    {
        if ($context === null) {
            $context = new Context();
        }

        $context->circularReferences = new \SplObjectStorage();
        $context->import = false;
        $context->result = null;
        $context->path = '#';

        return $this->process($data, $context);
    }

    /**
     * @param mixed $data
     * @param Context $context
     * @throws InvalidValue
     * @throws \Exception
     */
    private function processType($data, Context $context)
    {
        if ($context->tolerateStrings && is_string($data)) {
            $valid = Type::readString($this->type, $data);
        } else {
            $valid = Type::isValid($this->type, $data, $context->version);
        }
        if (!$valid) {
            $this->fail(new TypeException(ucfirst(
                    implode(', ', is_array($this->type) ? $this->type : array($this->type))
                    . ' expected, ' . json_encode($data) . ' received')
            ), $context->path);
        }
    }

    /**
     * @param mixed $data
     * @param Context $context
     * @throws InvalidValue
     * @throws \Exception
     */
    private function processEnum($data, Context $context)
    {
        $enumOk = false;
        foreach ($this->enum as $item) {
            if ($item === $data) {
                $enumOk = true;
                break;
            } else {
                if (is_array($item) || is_object($item)) {
                    $diff = new JsonDiff($item, $data, JsonDiff::STOP_ON_DIFF);
                    if ($diff->getDiffCnt() === 0) {
                        $enumOk = true;
                        break;
                    }
                }
            }
        }
        if (!$enumOk) {
            $this->fail(new EnumException('Enum failed, enum: ' . json_encode($this->enum) . ', data: ' . json_encode($data)), $context->path);
        }
    }

    /**
     * @param mixed $data
     * @param Context $context
     * @throws InvalidValue
     * @throws \Swaggest\JsonDiff\Exception
     */
    private function processConst($data, Context $context)
    {
        if ($this->const !== $data) {
            if ((is_object($this->const) && is_object($data))
                || (is_array($this->const) && is_array($data))) {
                $diff = new JsonDiff($this->const, $data,
                    JsonDiff::STOP_ON_DIFF);
                if ($diff->getDiffCnt() != 0) {
                    $this->fail(new ConstException('Const failed'), $context->path);
                }
            } else {
                $this->fail(new ConstException('Const failed'), $context->path);
            }
        }
    }

    /**
     * @param mixed $data
     * @param Context $context
     * @throws InvalidValue
     * @throws \Exception
     * @throws \Swaggest\JsonDiff\Exception
     */
    private function processNot($data, Context $context)
    {
        $exception = false;
        try {
            $newContext = clone $context;
            $newContext->result = null;
            $newContext->path .= '->not';
            self::unboolSchema($this->not)->process($data, $newContext);
        } catch (InvalidValue $exception) {
            // Expected exception
        }
        if ($exception === false) {
            $this->fail(new LogicException('Not ' . json_encode($this->not) . ' expected, ' . json_encode($data) . ' received'), $context->path);
        }
    }

    /**
     * @param string $data
     * @param Context $context
     * @throws InvalidValue
     */
    private function processString($data, Context $context)
    {
        if ($this->minLength !== null) {
            if (mb_strlen($data, 'UTF-8') < $this->minLength) {
                $this->fail(new StringException('String is too short', StringException::TOO_SHORT), $context->path);
            }
        }
        if ($this->maxLength !== null) {
            if (mb_strlen($data, 'UTF-8') > $this->maxLength) {
                $this->fail(new StringException('String is too long', StringException::TOO_LONG), $context->path);
            }
        }
        if ($this->pattern !== null) {
            if (0 === preg_match(Helper::toPregPattern($this->pattern), $data)) {
                $this->fail(new StringException(json_encode($data) . ' does not match to '
                    . $this->pattern, StringException::PATTERN_MISMATCH), $context->path);
            }
        }
        if ($this->format !== null) {
            $validationError = Format::validationError($this->format, $data);
            if ($validationError !== null) {
                if (!($this->format === "uri" && substr($context->path, -3) === ':id')) {
                    $this->fail(new StringException($validationError), $context->path);
                }
            }
        }
    }

    /**
     * @param float|int $data
     * @param Context $context
     * @throws InvalidValue
     */
    private function processNumeric($data, Context $context)
    {
        if ($this->multipleOf !== null) {
            $div = $data / $this->multipleOf;
            if ($div != (int)$div) {
                $this->fail(new NumericException($data . ' is not multiple of ' . $this->multipleOf, NumericException::MULTIPLE_OF), $context->path);
            }
        }

        if ($this->exclusiveMaximum !== null && !is_bool($this->exclusiveMaximum)) {
            if ($data >= $this->exclusiveMaximum) {
                $this->fail(new NumericException(
                    'Value less or equal than ' . $this->exclusiveMaximum . ' expected, ' . $data . ' received',
                    NumericException::MAXIMUM), $context->path);
            }
        }

        if ($this->exclusiveMinimum !== null && !is_bool($this->exclusiveMinimum)) {
            if ($data <= $this->exclusiveMinimum) {
                $this->fail(new NumericException(
                    'Value more or equal than ' . $this->exclusiveMinimum . ' expected, ' . $data . ' received',
                    NumericException::MINIMUM), $context->path);
            }
        }

        if ($this->maximum !== null) {
            if ($this->exclusiveMaximum === true) {
                if ($data >= $this->maximum) {
                    $this->fail(new NumericException(
                        'Value less or equal than ' . $this->maximum . ' expected, ' . $data . ' received',
                        NumericException::MAXIMUM), $context->path);
                }
            } else {
                if ($data > $this->maximum) {
                    $this->fail(new NumericException(
                        'Value less than ' . $this->maximum . ' expected, ' . $data . ' received',
                        NumericException::MAXIMUM), $context->path);
                }
            }
        }

        if ($this->minimum !== null) {
            if ($this->exclusiveMinimum === true) {
                if ($data <= $this->minimum) {
                    $this->fail(new NumericException(
                        'Value more or equal than ' . $this->minimum . ' expected, ' . $data . ' received',
                        NumericException::MINIMUM), $context->path);
                }
            } else {
                if ($data < $this->minimum) {
                    $this->fail(new NumericException(
                        'Value more than ' . $this->minimum . ' expected, ' . $data . ' received',
                        NumericException::MINIMUM), $context->path);
                }
            }
        }
    }

    /**
     * @param mixed $data
     * @param Context $context
     * @return array|mixed|null|object|\stdClass
     * @throws InvalidValue
     * @throws \Exception
     * @throws \Swaggest\JsonDiff\Exception
     */
    private function processOneOf($data, Context $context)
    {
        $successes = 0;
        $failures = '';
        $subErrors = [];
        $skipValidation = false;
        if ($context->skipValidation) {
            $skipValidation = true;
            $context->skipValidation = false;
        }

        $result = $data;
        foreach ($this->oneOf as $index => $item) {
            try {
                $newContext = clone $context;
                $newContext->result = null;
                $newContext->path .= '->oneOf[' . $index . ']';
                $result = self::unboolSchema($item)->process($data, $newContext);
                $successes++;
                if ($successes > 1 || $context->skipValidation) {
                    break;
                }
            } catch (InvalidValue $exception) {
                $subErrors[$index] = $exception;
                $failures .= ' ' . $index . ': ' . Helper::padLines(' ', $exception->getMessage()) . "\n";
                // Expected exception
            }
        }
        if ($skipValidation) {
            $context->skipValidation = true;
            if ($successes === 0) {
                $newContext = clone $context;
                $newContext->result = null;
                $newContext->path .= '->oneOf[0]';
                $result = self::unboolSchema($this->oneOf[0])->process($data, $newContext);
            }
        }

        if (!$context->skipValidation) {
            if ($successes === 0) {
                $exception = new LogicException('No valid results for oneOf {' . "\n" . substr($failures, 0, -1) . "\n}");
                $exception->error = 'No valid results for oneOf';
                $exception->subErrors = $subErrors;
                $this->fail($exception, $context->path);
            } elseif ($successes > 1) {
                $exception = new LogicException('More than 1 valid result for oneOf: '
                    . $successes . '/' . count($this->oneOf) . ' valid results for oneOf {'
                    . "\n" . substr($failures, 0, -1) . "\n}");
                $exception->error = 'More than 1 valid result for oneOf';
                $exception->subErrors = $subErrors;
                $this->fail($exception, $context->path);
            }
        }
        return $result;
    }

    /**
     * @param mixed $data
     * @param Context $context
     * @return array|mixed|null|object|\stdClass
     * @throws InvalidValue
     * @throws \Exception
     * @throws \Swaggest\JsonDiff\Exception
     */
    private function processAnyOf($data, Context $context)
    {
        $successes = 0;
        $failures = '';
        $subErrors = [];
        $result = $data;
        foreach ($this->anyOf as $index => $item) {
            try {
                $newContext = clone $context;
                $newContext->result = null;
                $newContext->path .= '->anyOf[' . $index . ']';
                $result = self::unboolSchema($item)->process($data, $newContext);
                $successes++;
                if ($successes) {
                    break;
                }
            } catch (InvalidValue $exception) {
                $subErrors[$index] = $exception;
                $failures .= ' ' . $index . ': ' . $exception->getMessage() . "\n";
                // Expected exception
            }
        }
        if (!$successes && !$context->skipValidation) {
            $exception = new LogicException('No valid results for anyOf {' . "\n"
                . substr(Helper::padLines(' ', $failures, false), 0, -1)
                . "\n}");
            $exception->error = 'No valid results for anyOf';
            $exception->subErrors = $subErrors;
            $this->fail($exception, $context->path);
        }
        return $result;
    }

    /**
     * @param mixed $data
     * @param Context $context
     * @return array|mixed|null|object|\stdClass
     * @throws InvalidValue
     * @throws \Exception
     * @throws \Swaggest\JsonDiff\Exception
     */
    private function processAllOf($data, Context $context)
    {
        $result = $data;
        foreach ($this->allOf as $index => $item) {
            $newContext = clone $context;
            $newContext->result = null;
            $newContext->path .= '->allOf[' . $index . ']';
            $result = self::unboolSchema($item)->process($data, $newContext);
        }
        return $result;
    }

    /**
     * @param mixed $data
     * @param Context $context
     * @return array|mixed|null|object|\stdClass
     * @throws InvalidValue
     * @throws \Exception
     * @throws \Swaggest\JsonDiff\Exception
     */
    private function processIf($data, Context $context)
    {
        $valid = true;
        try {
            $newContext = clone $context;
            $newContext->result = null;
            $newContext->path .= '->if';
            self::unboolSchema($this->if)->process($data, $newContext);
        } catch (InvalidValue $exception) {
            $valid = false;
        }
        if ($valid) {
            if ($this->then !== null) {
                $newContext = clone $context;
                $newContext->result = null;
                $newContext->path .= '->then';
                return self::unboolSchema($this->then)->process($data, $newContext);
            }
        } else {
            if ($this->else !== null) {
                $newContext = clone $context;
                $newContext->result = null;
                $newContext->path .= '->else';
                return self::unboolSchema($this->else)->process($data, $newContext);
            }
        }
        return null;
    }

    /**
     * @param \stdClass $data
     * @param Context $context
     * @throws InvalidValue
     */
    private function processObjectRequired($data, Context $context)
    {
        if (isset($this->__dataToProperty[$context->mapping])) {
            if ($context->import) {
                foreach ($this->required as $item) {
                    if (isset($this->__propertyToData[$context->mapping][$item])) {
                        $item = $this->__propertyToData[$context->mapping][$item];
                    }
                    if (!property_exists($data, $item)) {
                        $this->fail(new ObjectException('Required property missing: ' . $item . ', data: ' . json_encode($data, JSON_UNESCAPED_SLASHES), ObjectException::REQUIRED), $context->path);
                    }
                }
            } else {
                foreach ($this->required as $item) {
                    if (isset($this->__dataToProperty[$context->mapping][$item])) {
                        $item = $this->__dataToProperty[$context->mapping][$item];
                    }
                    if (!property_exists($data, $item)) {
                        $this->fail(new ObjectException('Required property missing: ' . $item . ', data: ' . json_encode($data, JSON_UNESCAPED_SLASHES), ObjectException::REQUIRED), $context->path);
                    }
                }
            }

        } else {
            foreach ($this->required as $item) {
                if (!property_exists($data, $item)) {
                    $this->fail(new ObjectException('Required property missing: ' . $item . ', data: ' . json_encode($data, JSON_UNESCAPED_SLASHES), ObjectException::REQUIRED), $context->path);
                }
            }
        }
    }

    /**
     * @param \stdClass $data
     * @param Context $context
     * @return array|null|ClassStructure|ObjectItemContract
     * @throws InvalidValue
     * @throws \Exception
     * @throws \Swaggest\JsonDiff\Exception
     */
    private function processObject($data, Context $context)
    {
        $import = $context->import;

        if (!$context->skipValidation && $this->required !== null) {
            $this->processObjectRequired($data, $context);
        }

        if ($import) {
            if (!$context->validateOnly) {

                if ($this->useObjectAsArray) {
                    $context->result = array();
                } elseif (!$context->result instanceof ObjectItemContract) {
                    //* todo check performance impact
                    if (null === $this->objectItemClass) {
                        $context->result = new ObjectItem();
                    } else {
                        $className = $this->objectItemClass;
                        if ($context->objectItemClassMapping !== null) {
                            if (isset($context->objectItemClassMapping[$className])) {
                                $className = $context->objectItemClassMapping[$className];
                            }
                        }
                        $context->result = new $className;
                    }
                    //*/


                    if ($context->result instanceof ClassStructure) {
                        if ($context->result->__validateOnSet) {
                            $context->result->__validateOnSet = false;
                            /** @noinspection PhpUnusedLocalVariableInspection */
                            /* todo check performance impact
                            $validateOnSetHandler = new ScopeExit(function () use ($result) {
                                $result->__validateOnSet = true;
                            });
                            //*/
                        }
                    }

                    //* todo check performance impact
                    if ($context->result instanceof ObjectItemContract) {
                        $context->result->setDocumentPath($context->path);
                    }
                    //*/
                }
            }
        }

        // @todo better check for schema id

        if ($import
            && isset($data->{Schema::PROP_ID_D4})
            && ($context->version === Schema::VERSION_DRAFT_04 || $context->version === Schema::VERSION_AUTO)
            && is_string($data->{Schema::PROP_ID_D4})) {
            $id = $data->{Schema::PROP_ID_D4};
            $refResolver = $context->refResolver;
            $parentScope = $refResolver->updateResolutionScope($id);
            /** @noinspection PhpUnusedLocalVariableInspection */
            $defer = new ScopeExit(function () use ($parentScope, $refResolver) {
                $refResolver->setResolutionScope($parentScope);
            });
        }

        if ($import
            && isset($data->{self::PROP_ID})
            && ($context->version >= Schema::VERSION_DRAFT_06 || $context->version === Schema::VERSION_AUTO)
            && is_string($data->{self::PROP_ID})) {
            $id = $data->{self::PROP_ID};
            $refResolver = $context->refResolver;
            $parentScope = $refResolver->updateResolutionScope($id);
            /** @noinspection PhpUnusedLocalVariableInspection */
            $defer = new ScopeExit(function () use ($parentScope, $refResolver) {
                $refResolver->setResolutionScope($parentScope);
            });
        }

        if ($import) {
            try {

                $refProperty = null;
                $dereference = true;

                if (isset($data->{self::PROP_REF})) {
                    if (null === $refProperty = $this->properties[self::PROP_REF]) {
                        if (isset($this->__dataToProperty[$context->mapping][self::PROP_REF])) {
                            $refProperty = $this->properties[$this->__dataToProperty[$context->mapping][self::PROP_REF]];
                        }
                    }

                    if (isset($refProperty) && ($refProperty->format !== Format::URI_REFERENCE)) {
                        $dereference = false;
                    }
                }

                if (
                    isset($data->{self::PROP_REF})
                    && is_string($data->{self::PROP_REF})
                    && $dereference
                ) {
                    $refString = $data->{self::PROP_REF};

                    // todo check performance impact
                    if ($refString === 'http://json-schema.org/draft-04/schema#'
                        || $refString === 'http://json-schema.org/draft-06/schema#'
                        || $refString === 'http://json-schema.org/draft-07/schema#') {
                        return Schema::schema();
                    }

                    // TODO consider process # by reference here ?
                    $refResolver = $context->refResolver;
                    $preRefScope = $refResolver->getResolutionScope();
                    /** @noinspection PhpUnusedLocalVariableInspection */
                    $deferRefScope = new ScopeExit(function () use ($preRefScope, $refResolver) {
                        $refResolver->setResolutionScope($preRefScope);
                    });

                    $ref = $refResolver->resolveReference($refString);
                    $data = self::unboolSchemaData($ref->getData());
                    if (!$context->validateOnly) {
                        if ($ref->isImported()) {
                            $refResult = $ref->getImported();
                            return $refResult;
                        }
                        $ref->setImported($context->result);
                        try {
                            $newContext = clone $context;
                            $newContext->result = null;
                            $newContext->path .= '->$ref:' . $refString;
                            $refResult = $this->process($data, $newContext);
                            if ($refResult instanceof ObjectItemContract) {
                                if ($refResult->getFromRefs()) {
                                    $refResult = clone $refResult; // @todo check performance, consider option
                                }
                                $refResult->setFromRef($refString);
                            }
                            $ref->setImported($refResult);
                        } catch (InvalidValue $exception) {
                            $ref->unsetImported();
                            throw $exception;
                        }
                        return $refResult;
                    } else {
                        $newContext = clone $context;
                        $newContext->result = null;
                        $newContext->path .= '->$ref:' . $refString;
                        $this->process($data, $newContext);
                    }
                }
            } catch (InvalidValue $exception) {
                $this->fail($exception, $context->path);
            }
        }

        /** @var Schema[]|null $properties */
        $properties = null;

        $nestedProperties = null;
        if ($this->properties !== null) {
            $properties = $this->properties->toArray(); // todo call directly
            if ($this->properties instanceof Properties) {
                $nestedProperties = $this->properties->nestedProperties;
            } else {
                $nestedProperties = array();
            }
        }

        $array = array();
        if (!empty($this->__dataToProperty[$context->mapping])) { // todo skip on $options->validateOnly
            foreach ((array)$data as $key => $value) {
                if ($import) {
                    if (isset($this->__dataToProperty[$context->mapping][$key])) {
                        $key = $this->__dataToProperty[$context->mapping][$key];
                    }
                } else {
                    if (isset($this->__propertyToData[$context->mapping][$key])) {
                        $key = $this->__propertyToData[$context->mapping][$key];
                    }
                }
                $array[$key] = $value;
            }
        } else {
            $array = (array)$data;
        }

        if (!$context->skipValidation) {
            if ($this->minProperties !== null && count($array) < $this->minProperties) {
                $this->fail(new ObjectException("Not enough properties", ObjectException::TOO_FEW), $context->path);
            }
            if ($this->maxProperties !== null && count($array) > $this->maxProperties) {
                $this->fail(new ObjectException("Too many properties", ObjectException::TOO_MANY), $context->path);
            }
            if ($this->propertyNames !== null) {
                $propertyNames = self::unboolSchema($this->propertyNames);
                foreach ($array as $key => $tmp) {
                    $newContext = clone $context;
                    $newContext->result = null;
                    $newContext->path .= '->propertyNames:' . $key;

                    $propertyNames->process($key, $newContext);
                }
            }
        }

        $defaultApplied = array();
        if ($import
            && !$context->validateOnly
            && $context->applyDefaults
            && $properties !== null
        ) {
            foreach ($properties as $key => $property) {
                // todo check when property is \stdClass `{}` here (RefTest)
                if ($property instanceof SchemaContract && null !== $default = $property->getDefault()) {
                    if (isset($this->__dataToProperty[$context->mapping][$key])) {
                        $key = $this->__dataToProperty[$context->mapping][$key];
                    }
                    if (!array_key_exists($key, $array)) {
                        $defaultApplied[$key] = true;
                        $array[$key] = $default;
                    }
                }
            }
        }

        foreach ($array as $key => $value) {
            if ($key === '' && PHP_VERSION_ID < 71000) {
                $this->fail(new InvalidValue('Empty property name'), $context->path);
            }

            $found = false;

            if (!$context->skipValidation && !empty($this->dependencies)) {
                $deps = $this->dependencies;
                if (isset($deps->$key)) {
                    $dependencies = $deps->$key;
                    $dependencies = self::unboolSchema($dependencies);
                    if ($dependencies instanceof SchemaContract) {
                        $newContext = clone $context;
                        $newContext->result = null;
                        $newContext->path .= '->dependencies:' . $key;
                        $dependencies->process($data, $newContext);
                    } else {
                        foreach ($dependencies as $item) {
                            if (!property_exists($data, $item)) {
                                $this->fail(new ObjectException('Dependency property missing: ' . $item,
                                    ObjectException::DEPENDENCY_MISSING), $context->path);
                            }
                        }
                    }
                }
            }

            $propertyFound = false;
            if (isset($properties[$key])) {
                /** @var Schema[] $properties */
                $prop = self::unboolSchema($properties[$key]);
                $propertyFound = true;
                $found = true;
                if ($prop instanceof SchemaContract) {
                    $newContext = clone $context;
                    $newContext->result = null;
                    $newContext->path .= '->properties:' . $key;
                    if (isset($defaultApplied[$key])) {
                        $newContext->skipValidation = true;
                        $newContext->applyDefaults = false;
                    }
                    $value = $prop->process(
                        $value,
                        $newContext
                    );
                }
            }

            /** @var Egg[] $nestedEggs */
            $nestedEggs = null;
            if (isset($nestedProperties[$key])) {
                $found = true;
                $nestedEggs = $nestedProperties[$key];
                // todo iterate all nested props?
                $newContext = clone $context;
                $newContext->result = null;
                $newContext->path .= '->nestedProperties:' . $key;
                $value = self::unboolSchema($nestedEggs[0]->propertySchema)->process($value, $newContext);
            }

            if ($this->patternProperties !== null) {
                foreach ($this->patternProperties as $pattern => $propertySchema) {
                    if (preg_match(Helper::toPregPattern($pattern), $key)) {
                        $found = true;
                        $newContext = clone $context;
                        $newContext->result = null;
                        $newContext->path .= '->patternProperties[' . strtr($pattern, array('~' => '~1', ':' => '~2')) . ']:' . $key;
                        $value = self::unboolSchema($propertySchema)->process($value, $newContext);
                        if (!$context->validateOnly && $import) {
                            $context->result->addPatternPropertyName($pattern, $key);
                        }
                        //break; // todo manage multiple import data properly (pattern accessor)
                    }
                }
            }
            if (!$found && $this->additionalProperties !== null) {
                if (!$context->skipValidation && $this->additionalProperties === false) {
                    $this->fail(new ObjectException('Additional properties not allowed: ' . $key), $context->path);
                }

                if ($this->additionalProperties instanceof SchemaContract) {
                    $newContext = clone $context;
                    $newContext->result = null;
                    $newContext->path .= '->additionalProperties:' . $key;
                    $value = $this->additionalProperties->process($value, $newContext);
                }

                if ($import && !$this->useObjectAsArray && !$context->validateOnly) {
                    $context->result->addAdditionalPropertyName($key);
                }
            }

            if (!$context->validateOnly && $nestedEggs && $import) {
                foreach ($nestedEggs as $nestedEgg) {
                    $context->result->setNestedProperty($key, $value, $nestedEgg);
                }
                if ($propertyFound) {
                    $context->result->$key = $value;
                }
            } else {
                if ($this->useObjectAsArray && $import) {
                    $context->result[$key] = $value;
                } else {
                    if ($found || !$import) {
                        $context->result->$key = $value;
                    } elseif (!isset($context->result->$key)) {
                        $context->result->$key = $value;
                    }
                }
            }
        }

        return $context->result;
    }

    /**
     * @param array $data
     * @param Context $context
     * @return mixed
     * @throws InvalidValue
     * @throws \Exception
     * @throws \Swaggest\JsonDiff\Exception
     */
    private function processArray($data, Context $context)
    {
        $count = count($data);
        if (!$context->skipValidation) {
            if ($this->minItems !== null && $count < $this->minItems) {
                $this->fail(new ArrayException('Not enough items in array'), $context->path);
            }

            if ($this->maxItems !== null && $count > $this->maxItems) {
                $this->fail(new ArrayException('Too many items in array'), $context->path);
            }
        }

        $pathItems = 'items';
        $this->items = self::unboolSchema($this->items);
        if ($this->items instanceof SchemaContract) {
            $items = array();
            $additionalItems = $this->items;
        } elseif ($this->items === null) { // items defaults to empty schema so everything is valid
            $items = array();
            $additionalItems = true;
        } else { // listed items
            $items = $this->items;
            $additionalItems = $this->additionalItems;
            $pathItems = 'additionalItems';
        }

        /**
         * @var Schema|Schema[] $items
         * @var null|bool|Schema $additionalItems
         */
        $itemsLen = is_array($items) ? count($items) : 0;
        $index = 0;
        foreach ($context->result as $key => $value) {
            if ($index < $itemsLen) {
                $itemSchema = self::unboolSchema($items[$index]);
                $newContext = clone $context;
                $newContext->result = null;
                $newContext->path .= '->items:' . $index;
                $context->result[$key] = $itemSchema->process($value, $newContext);
            } else {
                if ($additionalItems instanceof SchemaContract) {
                    $newContext = clone $context;
                    $newContext->result = null;
                    $newContext->path .= '->' . $pathItems . '[' . $index . ']:' . $index;
                    $context->result[$key] = $additionalItems->process($value, $newContext);
                } elseif (!$context->skipValidation && $additionalItems === false) {
                    $this->fail(new ArrayException('Unexpected array item'), $context->path);
                }
            }
            ++$index;
        }

        if (!$context->skipValidation && $this->uniqueItems) {
            if (!UniqueItems::isValid($data)) {
                $this->fail(new ArrayException('Array is not unique'), $context->path);
            }
        }

        if (!$context->skipValidation && $this->contains !== null) {
            /** @var Schema|bool $contains */
            $contains = $this->contains;
            if ($contains === false) {
                $this->fail(new ArrayException('Contains is false'), $context->path);
            }
            if ($count === 0) {
                $this->fail(new ArrayException('Empty array fails contains constraint'), $context->path);
            }
            if ($contains === true) {
                $contains = self::unboolSchema($contains);
            }
            $containsOk = false;
            foreach ($data as $key => $item) {
                try {
                    $newContext = clone $context;
                    $newContext->result = null;
                    $newContext->path .= '->' . $key;
                    $contains->process($item, $newContext);
                    $containsOk = true;
                    break;
                } catch (InvalidValue $exception) {
                }
            }
            if (!$containsOk) {
                $this->fail(new ArrayException('Array fails contains constraint'), $context->path);
            }
        }
        return $context->result;
    }

    /**
     * @param mixed|string $data
     * @param Context $context
     * @return bool|mixed|string
     * @throws InvalidValue
     */
    private function processContent($data, Context $context)
    {
        try {
            if ($context->unpackContentMediaType) {
                return Content::process($context, $this->contentEncoding, $this->contentMediaType, $data, $context->import);
            } else {
                Content::process($context, $this->contentEncoding, $this->contentMediaType, $data, true);
            }
        } catch (InvalidValue $exception) {
            $this->fail($exception, $context->path);
        }
        return $data;
    }

    /**
     * @param mixed $data
     * @param Context $context
     * @return array|mixed|null|object|\stdClass
     * @throws InvalidValue
     * @throws \Exception
     * @throws \Swaggest\JsonDiff\Exception
     */
    public function process($data, Context $context)
    {
        $import = $context->import;

        if (!$import && $data instanceof SchemaExporter) {
            $data = $data->exportSchema(); // Used to export ClassStructure::schema()
        }

        if (!$import && $data instanceof ObjectItemContract) {
            $result = new \stdClass();

            if ('#' === $context->path) {
                $injectDefinitions = new ScopeExit(function () use ($result, $context) {
                    foreach ($context->exportedDefinitions as $ref => $data) {
                        if ($data !== null) {
                            JsonPointer::add($result, JsonPointer::splitPath($ref), $data,
                                /*JsonPointer::SKIP_IF_ISSET + */
                                JsonPointer::RECURSIVE_KEY_CREATION);
                        }
                    }
                });
            }

            if ($context->isRef) {
                $context->isRef = false;
            } else {
                if ('#' !== $context->path && $refs = $data->getFromRefs()) {
                    $ref = $refs[0];
                    if (!array_key_exists($ref, $context->exportedDefinitions) && strpos($ref, '://') === false) {
                        $exported = null;
                        $context->exportedDefinitions[$ref] = &$exported;
                        $context->isRef = true;
                        $exported = $this->process($data, $context, $ref);
                        unset($exported);
                    }

                    for ($i = 1; $i < count($refs); $i++) {
                        $ref = $refs[$i];
                        if (!array_key_exists($ref, $context->exportedDefinitions) && strpos($ref, '://') === false) {
                            $exported = new \stdClass();
                            $exported->{self::PROP_REF} = $refs[$i - 1];
                            $context->exportedDefinitions[$ref] = $exported;
                        }
                    }

                    $result->{self::PROP_REF} = $refs[count($refs) - 1];
                    return $result;
                }
            }

            if ($context->circularReferences->contains($data)) {
                /** @noinspection PhpIllegalArrayKeyTypeInspection */
                $context->path = $context->circularReferences[$data];
                $result->{self::PROP_REF} = PointerUtil::getDataPointer($context->path, true);
                return $result;
            }
            $context->circularReferences->attach($data, $context->path);

            $data = $data->jsonSerialize();
        }

        $context->path .= $this->getFromRefPath();

        if (!$import && is_array($data) && $this->useObjectAsArray) {
            $data = (object)$data;
        }

        if (null !== $context->dataPreProcessor) {
            $data = $context->dataPreProcessor->process($data, $this, $import);
        }

        if ($context->result === null) {
            $context->result = $data;
        }

        if ($context->skipValidation) {
            goto skipValidation;
        }

        if ($this->type !== null) {
            $this->processType($data, $context);
        }

        if ($this->enum !== null) {
            $this->processEnum($data, $context);
        }

        if (array_key_exists(self::CONST_PROPERTY, $this->__arrayOfData)) {
            $this->processConst($data, $context);
        }

        if ($this->not !== null) {
            $this->processNot($data, $context);
        }

        if (is_string($data)) {
            $this->processString($data, $context);
        }

        if (is_int($data) || is_float($data)) {
            $this->processNumeric($data, $context);
        }

        if ($this->if !== null) {
            $context->result = $this->processIf($data, $context);
        }

        skipValidation:

        if ($this->oneOf !== null) {
            $context->result = $this->processOneOf($data, $context);
        }

        if ($this->anyOf !== null) {
            $context->result = $this->processAnyOf($data, $context);
        }

        if ($this->allOf !== null) {
            $context->result = $this->processAllOf($data, $context);
        }

        if ($data instanceof \stdClass) {
            $context->result = $this->processObject($data, $context);
        }

        if (is_array($data)) {
            $context->result = $this->processArray($data, $context);
        }

        if ($this->contentEncoding !== null || $this->contentMediaType !== null) {
            $context->result = $this->processContent($data, $context);
        }

        return $context->result;
    }

    /**
     * @param boolean $useObjectAsArray
     * @return Schema
     */
    public function setUseObjectAsArray($useObjectAsArray)
    {
        $this->useObjectAsArray = $useObjectAsArray;
        return $this;
    }

    /**
     * @param InvalidValue $exception
     * @param string $path
     * @throws InvalidValue
     */
    private function fail(InvalidValue $exception, $path)
    {
        if ($path !== '#') {
            $exception->addPath($path);
        }
        throw $exception;
    }

    public static function integer()
    {
        $schema = new static();
        $schema->type = Type::INTEGER;
        return $schema;
    }

    public static function number()
    {
        $schema = new static();
        $schema->type = Type::NUMBER;
        return $schema;
    }

    public static function string()
    {
        $schema = new static();
        $schema->type = Type::STRING;
        return $schema;
    }

    public static function boolean()
    {
        $schema = new static();
        $schema->type = Type::BOOLEAN;
        return $schema;
    }

    public static function object()
    {
        $schema = new static();
        $schema->type = Type::OBJECT;
        return $schema;
    }

    public static function arr()
    {
        $schema = new static();
        $schema->type = Type::ARR;
        return $schema;
    }

    public static function null()
    {
        $schema = new static();
        $schema->type = Type::NULL;
        return $schema;
    }


    /**
     * @param Properties $properties
     * @return Schema
     */
    public function setProperties($properties)
    {
        $this->properties = $properties;
        return $this;
    }

    /**
     * @param string $name
     * @param Schema $schema
     * @return $this
     */
    public function setProperty($name, $schema)
    {
        if (null === $this->properties) {
            $this->properties = new Properties();
        }
        $this->properties->__set($name, $schema);
        return $this;
    }

    /**
     * @param string $name
     * @param SchemaContract $schema
     * @return $this
     * @throws Exception
     */
    public function setPatternProperty($name, $schema)
    {
        if (null === $this->patternProperties) {
            $this->patternProperties = new Properties();
        }
        $this->patternProperties->__set($name, $schema);
        return $this;
    }


    /** @var mixed[] */
    private $metaItems = array();

    public function addMeta($meta, $name = null)
    {
        if ($name === null) {
            $name = get_class($meta);
        }
        $this->metaItems[$name] = $meta;
        return $this;
    }

    public function getMeta($name)
    {
        if (isset($this->metaItems[$name])) {
            return $this->metaItems[$name];
        }
        return null;
    }

    /**
     * @param Context $options
     * @return ObjectItemContract
     */
    public function makeObjectItem(Context $options = null)
    {
        if (null === $this->objectItemClass) {
            return new ObjectItem();
        } else {
            $className = $this->objectItemClass;
            if ($options !== null) {
                if (isset($options->objectItemClassMapping[$className])) {
                    $className = $options->objectItemClassMapping[$className];
                }
            }
            return new $className;
        }
    }

    /**
     * @param mixed $schema
     * @return mixed|Schema
     */
    private static function unboolSchema($schema)
    {
        static $trueSchema;
        static $falseSchema;

        if (null === $trueSchema) {
            $trueSchema = new Schema();
            $trueSchema->__booleanSchema = true;
            $falseSchema = new Schema();
            $falseSchema->not = $trueSchema;
            $falseSchema->__booleanSchema = false;
        }

        if ($schema === true) {
            return $trueSchema;
        } elseif ($schema === false) {
            return $falseSchema;
        } else {
            return $schema;
        }
    }

    /**
     * @param mixed $data
     * @return \stdClass
     */
    private static function unboolSchemaData($data)
    {
        static $trueSchema;
        static $falseSchema;

        if (null === $trueSchema) {
            $trueSchema = new \stdClass();
            $falseSchema = new \stdClass();
            $falseSchema->not = $trueSchema;
        }

        if ($data === true) {
            return $trueSchema;
        } elseif ($data === false) {
            return $falseSchema;
        } else {
            return $data;
        }
    }

    public function getDefault()
    {
        return $this->default;
    }

    public function getProperties()
    {
        return $this->properties;
    }

    public function getObjectItemClass()
    {
        return $this->objectItemClass;
    }

    /**
     * @return string[]
     */
    public function getPropertyNames()
    {
        return array_keys($this->getProperties()->toArray());
    }

    /**
     * @return string[]
     */
    public function getNestedPropertyNames()
    {
        return $this->getProperties()->nestedPropertyNames;
    }

}
