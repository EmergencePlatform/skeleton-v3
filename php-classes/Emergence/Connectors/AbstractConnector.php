<?php

namespace Emergence\Connectors;

use Site;
use DB;
use ActiveRecord;

use Emergence\EventBus;
use Emergence\Logger;
use Emergence\Util\Url;

use Psr\Log\LoggerInterface;


abstract class AbstractConnector extends \RequestHandler implements IConnector
{
    public static $title;
    public static $connectorId;

    public static $accountLevelBrowse = false;
    public static $accountLevelSynchronize = 'Administrator';
    public static $synchronizeTimeLimit = 0;
    public static $globalRecordCaching = true;

    public static function getTitle()
    {
        return static::$title ? static::$title : static::class;
    }

    public static function getConnectorId()
    {
        return static::$connectorId ? static::$connectorId : static::class;
    }

    public static function getBaseUrl($external = false)
    {
        $path = '/connectors/' . static::getConnectorId();

        return $external ? Url::buildAbsolute($path) : $path;;
    }

    public static function handleRequest($action = null)
    {
        switch ($action ? $action : $action = static::shiftPath()) {
            case 'synchronize':
                if (!is_a(static::class, 'Emergence\Connectors\ISynchronize', true)) {
                    return static::throwError('Connector does not implement synchronize');
                }

                return static::handleSynchronizeRequest();
            case '':
            case false:
                return static::handleConnectorRequest();
            default:
                return static::throwInvalidRequestError();
        }
    }

    public static function handleConnectorRequest(array $responseData = [])
    {
        if (static::$accountLevelBrowse) {
            $GLOBALS['Session']->requireAccountLevel(static::$accountLevelBrowse);
        }

        $responseData['class'] = static::class;
        $responseData['title'] = static::getTitle();

        return static::respond('connector', $responseData);
    }

    // TODO: this should be moved to SychronizeTrait
    public static function handleSynchronizeRequest()
    {
        // read request/response configuration
        $pretend = !empty($_REQUEST['pretend']);

        if (static::peekPath() == 'json' || static::peekPath() == 'text') {
            static::$responseMode = static::shiftPath();
        }

        if ($jobHandle = static::shiftPath()) {
            if (!$Job = Job::getByHandle($jobHandle)) {
                return static::throwNotFoundError('Job not found');
            }

            if (static::$accountLevelSynchronize) {
                $GLOBALS['Session']->requireAccountLevel(static::$accountLevelSynchronize);
            }

            if (static::peekPath() == 'log') {
                $logPath = $Job->getLogPath().'.bz2';

                if (file_exists($logPath)) {
                    header('Content-Type: application/json; charset=utf-8');
                    header(sprintf('Content-Disposition: attachment; filename="%s-%u.json"', $Job->Connector, $Job->ID));
                    passthru("bzcat $logPath");
                    exit();
                }
                return static::throwNotFoundError('Log not available');
            }

            return static::respond('jobStatus', [
                'data' => $Job
            ]);
        }

        // authenticate and create job or copy template
        if (!empty($_REQUEST['template'])) {
            $TemplateJob = Job::getByHandle($_REQUEST['template']);

            if (!$TemplateJob || $TemplateJob->Status != 'Template' || $TemplateJob->Connector != static::class) {
                return static::throwNotFoundError('Template job not found');
            }

            $jobClass = $TemplateJob->Class;
            $Job = $jobClass::create([
                'Connector' => $TemplateJob->Connector
                ,'Template' => $TemplateJob
                ,'Config' => $TemplateJob->Config
            ]);
        } else {
            if (static::$accountLevelSynchronize) {
                $GLOBALS['Session']->requireAccountLevel(static::$accountLevelSynchronize);
            }

            $Job = static::_createJob(static::_getJobConfig($_REQUEST));

            if (!empty($_REQUEST['createTemplate'])) {
                if ($pretend) {
                    return static::throwInvalidRequestError('Cannot combine pretend and createTemplate');
                }

                $Job->Status = 'Template';
                $Job->save();

                return static::respond('templateCreated', [
                    'data' => $Job
                ]);
            }
        }

        // show template if not a post
        if ($_SERVER['REQUEST_METHOD'] != 'POST') {
            return static::respond('createJob', [
                'data' => $Job
                ,'templates' => Job::getAllByWhere([
                    'Status' => 'Template'
                    ,'Connector' => static::class
                ])
            ]);
        }


        // save job in pending state before starting
        if (!$pretend) {
            $Job->save();
        }


        // close connection to client
        if (!empty($_REQUEST['fork'])) {
            header('Location: '.static::_getConnectorBaseUrl(true).'/'.$Job->Handle, true, 201);
            print(json_encode(['data' => $Job->getData()]));
            fastcgi_finish_request();
        }


        // update execution time limit
        set_time_limit(static::$synchronizeTimeLimit);


        // configure logging and caching
        DB::suspendQueryLogging();

        if (static::$globalRecordCaching) {
            ActiveRecord::$useCache = true;
        }


        // execute synchronization
        try {
            $success = static::synchronize($Job, $pretend);
        } catch (Exception $e) {
            $Job->logException($e);
            $success = false;
        }

        if (!$success) {
            $Job->Status = 'Failed';
        }


        // restore logging
        DB::resumeQueryLogging();


        // save job if not pretend
        if (!$pretend) {
            $Job->save();
            $Job->writeLog();

            // email report
            if (!empty($Job->Config['reportTo'])) {
                \Emergence\Mailer\Mailer::sendFromTemplate($Job->Config['reportTo'], 'syncronizeComplete', [
                    'Job' => $Job
                    ,'connectorBaseUrl' => static::_getConnectorBaseUrl(true)
                ]);
            }
        }


        // all done, respond
        return static::respond('syncronizeComplete', [
            'data' => $Job
            ,'success' => $success
            ,'pretend' => $pretend
        ]);
    }

    public static function respond($responseID, $responseData = [], $responseMode = false)
    {
        $responseData['connectorBaseUrl'] = static::_getConnectorBaseUrl();

        return parent::respond($responseID, $responseData, $responseMode);
    }

    protected static function _getConnectorBaseUrl($external = false)
    {
        return static::getBaseUrl($external);
    }

    protected static function _createJob(array $config = [])
    {
        return Job::create([
            'Connector' => static::class,
            'Config' => $config
        ]);
    }

    protected static function _getJobConfig(array $requestData)
    {
        return [
            'reportTo' => empty($requestData['reportTo']) ? null : $requestData['reportTo']
        ];
    }

    protected static function _fireEvent($name, array $payload)
    {
        return EventBus::fireEvent($name, __NAMESPACE__ . '\\' . static::getConnectorId(), $payload);
    }

    protected static function getLogger(LoggerInterface $logger = null)
    {
        return $logger ?: Logger::getLogger();
    }
}
