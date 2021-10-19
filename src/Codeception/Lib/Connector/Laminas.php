<?php

declare(strict_types=1);

namespace Codeception\Lib\Connector;

use Exception;
use Laminas\Http\Headers;
use Laminas\Http\PhpEnvironment\Request as LaminasRequest;
use Laminas\Http\Request as HttpRequest;
use Laminas\Mvc\Application;
use Laminas\Mvc\ApplicationInterface;
use Laminas\Stdlib\ArrayUtils;
use Laminas\Stdlib\Parameters;
use Laminas\Uri\Http as HttpUri;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\BrowserKit\AbstractBrowser;
use Symfony\Component\BrowserKit\Request as BrowserKitRequest;
use Symfony\Component\BrowserKit\Response;
use function array_map;
use function explode;
use function html_entity_decode;
use function implode;
use function is_null;
use function parse_str;
use function str_replace;
use function strpos;
use function strtolower;
use function strtoupper;
use function substr;

class Laminas extends AbstractBrowser
{
    /** @var ApplicationInterface */
    protected $application;

    /**
     * @var array
     */
    protected $applicationConfig = [];

    /** @var LaminasRequest */
    protected $laminasRequest;

    /**
     * @var array
     */
    private $persistentServices = [];

    /**
     * @var array
     */
    private $persistentFactories = [];

    public function setApplicationConfig(array $applicationConfig): void
    {
        $this->applicationConfig = $applicationConfig;

        $this->createApplication();
    }

    /**
     * @param BrowserKitRequest $request
     * @throws Exception
     */
    public function doRequest($request): Response
    {
        $this->createApplication();

        $laminasRequest = $this->application->getRequest();
        $uri            = new HttpUri($request->getUri());
        $queryString    = $uri->getQuery();
        $method         = strtoupper($request->getMethod());
        $query          = [];
        $post           = [];
        $content        = $request->getContent();

        if ($queryString) {
            parse_str($queryString, $query);
        }

        if ($method !== HttpRequest::METHOD_GET) {
            $post = $request->getParameters();
        }

        $laminasRequest->setCookies(new Parameters($request->getCookies()));
        $laminasRequest->setServer(new Parameters($request->getServer()));
        $laminasRequest->setQuery(new Parameters($query));
        $laminasRequest->setPost(new Parameters($post));
        $laminasRequest->setFiles(new Parameters($request->getFiles()));
        $laminasRequest->setContent(is_null($content) ? '' : $content);
        $laminasRequest->setMethod($method);
        $laminasRequest->setUri($uri);

        $requestUri = $uri->getPath();

        if (!empty($queryString)) {
            $requestUri .= '?' . $queryString;
        }

        $laminasRequest->setRequestUri($requestUri);

        $laminasRequest->setHeaders($this->extractHeaders($request));

        $this->application->run();

        // get the response *after* the application has run, because other Laminas
        //     libraries like API Agility may *replace* the application's response
        $laminasResponse = $this->application->getResponse();

        $this->laminasRequest = $laminasRequest;

        $exception = $this->application->getMvcEvent()->getParam('exception');
        if ($exception instanceof Exception) {
            throw $exception;
        }

        return new Response(
            $laminasResponse->getBody(),
            $laminasResponse->getStatusCode(),
            $laminasResponse->getHeaders()->toArray()
        );
    }

    public function getLaminasRequest(): LaminasRequest
    {
        return $this->laminasRequest;
    }

    public function grabServiceFromContainer(string $service)
    {
        $serviceManager = $this->application->getServiceManager();

        if (!$serviceManager->has($service)) {
            throw new AssertionFailedError(sprintf('Service %s is not available in container', $service));
        }

        return $serviceManager->get($service);
    }

    public function persistService(string $name): void
    {
        $service                         = $this->grabServiceFromContainer($name);
        $this->persistentServices[$name] = $service;
    }

    /**
     * @param array|object $service
     */
    public function addServiceToContainer(string $name, $service): void
    {
        $this->application->getServiceManager()->setAllowOverride(true);
        $this->application->getServiceManager()->setService($name, $service);
        $this->application->getServiceManager()->setAllowOverride(false);

        $this->persistentServices[$name] = $service;
    }

    public function addFactoryToContainer(string $name, $factory): void
    {
        $this->application->getServiceManager()->setAllowOverride(true);
        $this->application->getServiceManager()->setFactory($name, $factory);
        $this->application->getServiceManager()->setAllowOverride(false);

        $this->persistentFactories[$name] = $factory;
    }

    private function extractHeaders(BrowserKitRequest $browserKitRequest): Headers
    {
        $headers        = [];
        $server         = $browserKitRequest->getServer();
        $contentHeaders = ['Content-Length' => true, 'Content-Md5' => true, 'Content-Type' => true];

        foreach ($server as $header => $val) {
            $header = html_entity_decode(
                implode(
                    '-',
                    array_map(
                        'ucfirst',
                        explode(
                            '-',
                            strtolower(str_replace('_', '-', $header))
                        )
                    )
                ),
                ENT_NOQUOTES
            );

            if (strpos($header, 'Http-') === 0) {
                $headers[substr($header, 5)] = $val;
            } elseif (isset($contentHeaders[$header])) {
                $headers[$header] = $val;
            }
        }

        $httpHeaders = new Headers();
        $httpHeaders->addHeaders($headers);

        return $httpHeaders;
    }

    private function createApplication(): void
    {
        $this->application = Application::init(
            ArrayUtils::merge(
                $this->applicationConfig,
                [
                    'service_manager' => [
                        'services' => $this->persistentServices
                    ]
                ]
            )
        );

        $serviceManager       = $this->application->getServiceManager();
        $sendResponseListener = $serviceManager->get('SendResponseListener');
        $eventManager               = $this->application->getEventManager();

        $eventManager->detach([$sendResponseListener, 'sendResponse']);

        $serviceManager->setAllowOverride(true);
        $serviceManager->configure(['factories' => $this->persistentFactories]);
        $serviceManager->setAllowOverride(false);
    }
}
