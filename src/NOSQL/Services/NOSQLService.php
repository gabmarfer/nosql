<?php
namespace NOSQL\Services;

use MongoDB\Model\BSONDocument;
use NOSQL\Dto\CollectionDto;
use NOSQL\Dto\Validation\EnumPropertyDto;
use NOSQL\Dto\Validation\JsonSchemaDto;
use NOSQL\Dto\Validation\NumberPropertyDto;
use NOSQL\Dto\Validation\StringPropertyDto;
use NOSQL\Services\Base\NOSQLBase;
use PSFS\base\Cache;
use PSFS\base\dto\Field;
use PSFS\base\Logger;
use PSFS\base\Service;
use PSFS\base\Template;
use PSFS\base\types\helpers\GeneratorHelper;

/**
* Class NOSQLService
* @package NOSQL\Services
* @author Fran López <fran.lopez84@hotmail.es>
* @version 1.0
* Autogenerated service [2019-01-03 15:23:58]
*/
class NOSQLService extends Service {

    /**
     * @Injectable
     * @var \PSFS\base\Cache
     */
    protected $cache;

    /**
     * @var array
     */
    private $types = [];

    /**
     * @return array
     */
    public function getTypes()
    {
        return $this->types;
    }

    /**
     * @param array $types
     */
    public function setTypes($types)
    {
        $this->types = $types;
    }

    /**
     * @throws \ReflectionException
     */
    private function extractTypes() {
        $baseClass = new \ReflectionClass(NOSQLBase::class);
        if(null !== $baseClass) {
            $types = [];
            foreach($baseClass->getConstants() as $constant) {
                $types[] = $constant;
            }
            $this->setTypes($types);
        }
    }

    /**
     * @throws \ReflectionException
     */
    public function init()
    {
        parent::init();
        $this->extractTypes();
    }

    /**
     * @return array
     */
    public function getDomains() {
        $domains = [];
        $storedDomains = $this->cache->getDataFromFile(CONFIG_DIR . DIRECTORY_SEPARATOR . 'domains.json', Cache::JSON, TRUE);
        if(!empty($storedDomains)) {
            foreach($storedDomains as $domain => $data) {
                $domainLabel = str_replace(['@', '/'], '', $domain);
                if('ROOT' !== $domainLabel) {
                    $domains[] = $domainLabel;
                }
            }
        }
        return $domains;
    }

    /**
     * @param string $module
     * @return array
     */
    public function getCollections($module) {
        $collections = [];
        $schemaFilename = CORE_DIR . DIRECTORY_SEPARATOR . $module . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR . 'schema.json';
        if(file_exists($schemaFilename)) {
            $collections = $this->cache->getDataFromFile($schemaFilename, Cache::JSON, TRUE);
        }
        return $collections;
    }

    /**
     * @param string $module
     * @param array $collections
     * @throws \PSFS\base\exception\GeneratorException
     */
    public function setCollections($module, $collections) {
        $schemaFilename = CORE_DIR . DIRECTORY_SEPARATOR . $module . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR . 'schema.json';
        $this->cache->storeData($schemaFilename, $collections, Cache::JSON, true);
        $tpl = Template::getInstance();
        $tpl->addPath(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'Templates', 'NOSQL');
        $files = [
            '@NOSQL/generator/model.base.php.twig' => CORE_DIR . DIRECTORY_SEPARATOR . $module . DIRECTORY_SEPARATOR . 'Models' . DIRECTORY_SEPARATOR . 'Base',
            '@NOSQL/generator/model.php.twig' => CORE_DIR . DIRECTORY_SEPARATOR . $module . DIRECTORY_SEPARATOR . 'Models',
            '@NOSQL/generator/api.php.twig' => CORE_DIR . DIRECTORY_SEPARATOR . $module . DIRECTORY_SEPARATOR . 'Api',
            '@NOSQL/generator/api.base.php.twig' => CORE_DIR . DIRECTORY_SEPARATOR . $module . DIRECTORY_SEPARATOR . 'Api' . DIRECTORY_SEPARATOR . 'Base',
            '@NOSQL/generator/dto.php.twig' => CORE_DIR . DIRECTORY_SEPARATOR . $module . DIRECTORY_SEPARATOR . 'Dto' . DIRECTORY_SEPARATOR . 'Models',
        ];
        foreach($collections as $raw) {
            $collection = new CollectionDto(false);
            $collection->fromArray($raw);
            foreach($files as $template => $path) {
                GeneratorHelper::createDir($path);
                $templateDump = $tpl->dump($template, [
                    'domain' => $module,
                    'model' => $collection->name,
                    'properties' => $collection->properties,
                ]);
                $force = false;
                if(false !== strpos($template, 'dto') || false !== strpos(strtolower($template), 'base')) {
                    $force = true;
                }
                $this->writeTemplateToFile($templateDump, $path . DIRECTORY_SEPARATOR . $collection->name . '.php', $force);
            }
        }
    }

    /**
     * @param string $fileContent
     * @param string $filename
     * @param bool $force
     * @return bool
     */
    private function writeTemplateToFile($fileContent, $filename, $force = false)
    {
        $created = false;
        if ($force || !file_exists($filename)) {
            try {
                $this->cache->storeData($filename, $fileContent, Cache::TEXT, true);
                $created = true;
            } catch (\Exception $e) {
                Logger::log($e->getMessage(), LOG_ERR);
            }
        } else {
            Logger::log($filename . t(' not exists or cant write'), LOG_ERR);
        }
        return $created;
    }

    /**
     * @param $module
     * @return bool
     * @throws \PSFS\base\exception\GeneratorException
     */
    public function syncCollections($module) {
        $db = ParserService::getInstance()->createConnection($module);
        $collections = $this->getCollections($module);
        $success = true;
        foreach($collections as $raw) {
            $jsonSchema = $this->parseCollection($raw);
            try {
                /** @var BSONDocument $result */
                $result = $db->createCollection($raw['name'], [
                    'validation' => [
                        '$jsonSchema' => $jsonSchema->toArray(),
                    ]
                ]);
                $response = $result->getArrayCopy();
                $success = array_key_exists('ok', $response) && $response['ok'] > 0;
            } catch(\Exception $exception) {
                if($exception->getCode() !== 48) {
                    $success = false;
                }
            }
        }
        return $success;
    }

    /**
     * @param array $raw
     * @return JsonSchemaDto
     * @throws \PSFS\base\exception\GeneratorException
     */
    private function parseCollection($raw)
    {
        $jsonSchema = new JsonSchemaDto(false);
        foreach ($raw['properties'] as $rawProperty) {
            switch ($rawProperty['type']) {
                case NOSQLBase::NOSQL_TYPE_INTEGER:
                case NOSQLBase::NOSQL_TYPE_DOUBLE:
                case NOSQLBase::NOSQL_TYPE_LONG:
                    $property = new NumberPropertyDto(false);
                    break;
                case NOSQLBase::NOSQL_TYPE_ENUM:
                    $property = new EnumPropertyDto(false);
                    $property->enum = explode('|', $rawProperty['enum']);
                    break;
                default:
                    $property = new StringPropertyDto(false);
                    break;
            }
            if(array_key_exists('type', $rawProperty)) {
                $property->bsonType = $rawProperty['type'];
            }
            if(array_key_exists('description', $rawProperty)) {
                $property->description = $rawProperty['description'];
            }
            if (array_key_exists('required', $rawProperty) && $rawProperty['required']) {
                $jsonSchema->required[] = $rawProperty['name'];
            }
            $jsonSchema->properties[$rawProperty['name']] = $property->toArray();
        }
        return $jsonSchema;
    }

    /**
     * @return array
     * @throws \ReflectionException
     */
    public function getValidations() {
        $fieldTypes = new \ReflectionClass(Field::class);
        $validations = [];
        foreach($fieldTypes->getConstants() as $validation) {
            $validations[] = $validation;
        }
        return $validations;
    }
}
