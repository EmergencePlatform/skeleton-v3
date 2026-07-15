<?php

namespace Emergence\Connectors;

use ActiveRecord;
use HandleBehavior;
use Psr\Log\LogLevel;
use Psr\Log\LoggerInterface;
use Emergence\KeyedDiff;
use Emergence\Logger;
use Emergence\Site\Storage;

class Job extends ActiveRecord implements IJob
{
    use \Psr\Log\LoggerTrait;

    public $logEntries;
    public $muteLog = false;
    private $tmpLogPath;
    private $logger;

    // ActiveRecord configuration
    public static $tableName = 'connector_jobs';
    public static $singularNoun = 'connector job';
    public static $pluralNoun = 'connector jobs';

    // required for shared-table subclassing support
    public static $rootClass = self::class;
    public static $defaultClass = self::class;
    public static $subClasses = [self::class];

    public static $fields = [
        'Title' => [
            'default' => null
        ]
        ,'Handle' => [
            'unique' => true
        ]

        ,'Status' => [
            'type' => 'enum'
            ,'values' => ['Template','Pending','InProgress','Completed','Failed','Abandoned']
            ,'default' => 'Pending'
        ]

        ,'Connector'
        ,'TemplateID' => [
            'type' => 'uint'
            ,'notnull' => false
        ]

        ,'Direction' => [
            'type' => 'enum'
            ,'values' => ['In','Out','Both']
            ,'notnull' => false
        ]

        ,'Config' => [
            'type' => 'json'
        ]
        ,'Results' => [
            'type' => 'json'
            ,'default' => null
        ]
    ];

    public static $relationships = [
        'Template' => [
            'type' => 'one-one'
            ,'class' => self::class
        ]
        ,'TemplatedJobs' => [
            'type' => 'one-many'
            ,'class' => self::class
            ,'foreign' => 'TemplateID'
            ,'order' => ['ID' => 'DESC']
        ]
    ];


    public function save($deep = true)
    {
        // set handle
        if (!$this->Handle) {
            $this->Handle = HandleBehavior::generateRandomHandle($this);
        }

        // call parent
        return parent::save();
    }

    public function getConnectorTitle()
    {
        $className = $this->Connector;
        return $className::getTitle();
    }

    public function getLogger()
    {
        return $this->logger;
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function logRecordDelta(ActiveRecord $Record, $options = [])
    {
        $ignoreFields = is_array($options['ignoreFields']) ? $options['ignoreFields'] : [];
        $labelRenderers = is_array($options['labelRenderers']) ? $options['labelRenderers'] : [];
        $valueRenderers = is_array($options['valueRenderers']) ? $options['valueRenderers'] : [];
        $messageRenderer = is_callable($options['messageRenderer']) ? $options['messageRenderer'] : function ($logEntry) use ($options) {
            $title = $options['title'] ?: $logEntry['record']->getTitle();
            $class = $logEntry['record']->Class;

            if (!str_contains($title, $class)) {
                $title = "$class \"$title\"";
            }

            return $logEntry['action'].' '.$title;
        };

        $logEntry = [
            'changes' => new KeyedDiff()
            ,'level' => array_key_exists('level', $options) ? $options['level'] : LogLevel::NOTICE
            ,'record' => &$Record
        ];

        foreach ($Record->originalValues as $field => $from) {
            if (in_array($field, $ignoreFields)) {
                continue;
            }

            if (is_callable($labelRenderers[$field])) {
                $fieldLabel = call_user_func($labelRenderers[$field], $logEntry, $field);
            } elseif (is_string($labelRenderers[$field])) {
                $fieldLabel = $labelRenderers[$field];
            } else {
                $fieldLabel = $field;
            }

            $to = $Record->getValue($field);

            if (is_callable($valueRenderers[$field])) {
                $from = call_user_func($valueRenderers[$field], $from, $logEntry, $field, 'from');
                $to = call_user_func($valueRenderers[$field], $to, $logEntry, $field, 'to');
            } elseif ($fieldConfig = $Record->getFieldOptions($field)) {
                if ($fieldConfig['type'] === 'timestamp') {
                    $from = date('Y-m-d H:i:s', $from);
                    $to = date('Y-m-d H:i:s', $to);
                }
            }

            $logEntry['changes']->addChange($fieldLabel, $to, $from);
        }

        if ($Record->isPhantom || $Record->isNew) {
            $logEntry['action'] = 'create';
        } elseif ($Record->isDirty && $logEntry['changes']->hasChanges()) {
            $logEntry['action'] = 'update';
        } else {
            return null;
        }

        $logEntry['message'] = call_user_func($messageRenderer, $logEntry);

        $this->log(
            $logEntry['level'],
            $logEntry['message'],
            [
                'changes' => $logEntry['changes'],
                'record' => $Record
            ]
        );

        return $logEntry;
    }

    public function logInvalidRecord(\ActiveRecord $Record, $title = null)
    {
        return $this->log(
            LogLevel::WARNING,
            'Invalid {recordClass} record: {recordTitle}',
            [
                'validationErrors' => $Record->validationErrors,
                'recordClass' => $Record::class,
                'recordTitle' => $title ?: $Record->getTitle()
            ]
        );
    }

    public function logException(\Exception $e)
    {
        return $this->log(
            LogLevel::ERROR,
            'Exception({exceptionClass}): {exceptionMessage}',
            [
                'exception' => $e,
                'exceptionClass' => $e::class,
                'exceptionMessage' => $e->getMessage()
            ]
        );
    }

    public function getLogPath()
    {
        $logBase = Storage::getLocalStorageRoot().'/connector-jobs';

        if (!$this->isPhantom) {
            return "{$logBase}/{$this->ID}.json";
        }

        if (!$this->tmpLogPath) {
            $this->tmpLogPath = tempnam($logBase, 'phantom');
        }

        return $this->tmpLogPath;
    }

    public function writeLog($compress = true)
    {
        $logPath = $this->getLogPath();

        if (!$logPath) {
            return;
        }

        $logDirectory = dirname((string) $logPath);
        if (!is_dir($logDirectory)) {
            mkdir($logDirectory, 0777, true);
        }

        file_put_contents($logPath, json_encode($this->logEntries));
        if ($compress === true) {
            exec("bzip2 $logPath");
        }
    }

    public function log($level, $message, array $context = [])
    {
        if ($this->logger) {
            return $this->logger->log($level, $message, $context);
        }

        $entry = [
            'time' => date('Y-m-d H:i:s'),
            'message' => $message,
            'context' => $context,
            'level' => $level
        ];

        if (!$this->muteLog) {
            $this->logEntries[] = $entry;
        }

        return $entry;
    }
}
