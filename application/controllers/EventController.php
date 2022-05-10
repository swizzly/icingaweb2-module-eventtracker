<?php

namespace Icinga\Module\Eventtracker\Controllers;

use gipfl\Json\JsonEncodeException;
use gipfl\Json\JsonString;
use gipfl\Web\Widget\Hint;
use Icinga\Exception\NotFoundError;
use Icinga\Module\Eventtracker\Db\ConfigStore;
use Icinga\Module\Eventtracker\Engine\Input;
use Icinga\Module\Eventtracker\Engine\Input\RestApiInput;
use Psr\Log\NullLogger;

class EventController extends Controller
{
    protected $requiresAuthentication = false;

    public function indexAction()
    {
        if (! $this->getRequest()->isApiRequest()) {
            $this->showApiOnly();
            return;
        }

        $this->runForApi(function () {
            $this->createEvent();
        });
    }

    protected function createEvent()
    {
        $token = null;
        foreach ($this->getServerRequest()->getHeader('Authorization') as $line) {
            if (preg_match('/^Bearer\s+([A-z0-9-]+)$/', $line, $match)) {
                $token = $match[1];
            }
        }
        if ($token === null) {
            $this->sendJsonError('Bearer token is required', 401);
            return;
        }

        $store = new ConfigStore($this->db(), new NullLogger());
        $input = $this->findInputForToken($store, $token);
        if ($input === null) {
            $this->sendJsonError('Bearer token is not valid', 403);
            return;
        }
        $body = (string) $this->getServerRequest()->getBody();
        if (strlen($body) === 0) {
            $this->sendJsonError('JSON body is required', 400);
        }
        $wanted = false;
        foreach ($store->loadChannels() as $channel) {
            if ($channel->wantsInput($input)) {
                $wanted = true;
                $channel->addInput($input);
            }
        }
        $input->processObject(JsonString::decode($body));

        $response = [
            'success' => $wanted ? 'Event accepted' : 'Request valid, found no related Channel'
        ];

        $this->sendJsonResponse($response);
    }

    /**
     * @param ConfigStore $store
     * @param $token
     * @return RestApiInput|null
     */
    protected function findInputForToken(ConfigStore $store, $token)
    {
        $input = null;

        $inputs = $store->loadInputs([
            'implementation' => 'restApi',
        ]);

        /** @var Input $possibleInput */
        foreach ($inputs as $possibleInput) {
            if (
                $possibleInput instanceof RestApiInput
                && $possibleInput->getSettings()->get('token') === $token
            ) {
                $input = $possibleInput;
            }
        }

        return $input;
    }

    protected function runForApi($callback)
    {
        try {
            $callback();
        } catch (NotFoundError $e) {
            $this->sendJsonError($e->getMessage(), 404);
        } catch (\Throwable $e) {
            $this->sendJsonError($e);
        }
    }

    /**
     * @param \Throwable|string $error
     * @param int $code
     */
    protected function sendJsonError($error, $code = 500)
    {
        $data = [];

        if ($error instanceof \Exception) {
            $message = $error->getMessage();
            $data['trace'] = iconv('UTF-8', 'UTF-8//IGNORE', $error->getTraceAsString());
        } else {
            $message = (string) $error;
        }

        $data['error'] = iconv('UTF-8', 'UTF-8//IGNORE', $message);

        $this->sendJsonResponse($data, $code);
    }

    protected function sendJsonResponse($object, $code = 200)
    {
        $this->getResponse()->setHttpResponseCode($code);
        $this->getResponse()->setHeader('Content-Type', 'application/json', true);
        try {
            echo JsonString::encode($object, JSON_PRETTY_PRINT);
        } catch (JsonEncodeException $e) {
            $this->sendJsonError($e);
        }
        exit; // TODO: shutdown
    }

    protected function showApiOnly()
    {
        $this->addSingleTab($this->translate('Error'));
        $this->addTitle($this->translate('API only'));
        $this->content()->add(Hint::error($this->translate('This URL is available for API requests only')));
    }
}
