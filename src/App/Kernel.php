<?php

namespace eLife\App;

use Closure;
use ComposerLocator;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\AnnotationRegistry;
use Doctrine\Common\Annotations\CachedReader;
use Doctrine\Common\Cache\FilesystemCache;
use eLife\ApiClient\HttpClient\Guzzle6HttpClient;
use eLife\ApiSdk\ApiSdk;
use eLife\ApiValidator\MessageValidator\JsonMessageValidator;
use eLife\ApiValidator\SchemaFinder\PathBasedSchemaFinder;
use eLife\Logging\LoggingFactory;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use JMS\Serializer\EventDispatcher\EventDispatcher;
use JMS\Serializer\SerializationContext;
use JMS\Serializer\SerializerBuilder;
use JsonSchema\Validator;
use Kevinrob\GuzzleCache\CacheMiddleware;
use Kevinrob\GuzzleCache\Storage\DoctrineCacheStorage;
use Kevinrob\GuzzleCache\Strategy\PublicCacheStrategy;
use Silex\Application;
use Silex\Provider;
use Silex\Provider\VarDumperServiceProvider;
use Symfony\Bridge\PsrHttpMessage\Factory\DiactorosFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class Kernel implements MinimalKernel
{
    const ROOT = __DIR__.'/../..';

    public static $routes = [
    '/' => 'indexAction',
  ];

    private $app;

    public function __construct($config = [])
    {
        $app = new Application();
    // Load config
    $app['config'] = array_merge([
      'cli' => false,
      'api_url' => 'http://0.0.0.0:1234',
      'debug' => false,
      'validate' => false,
      'annotation_cache' => true,
      'ttl' => 3600,
      'file_logs_path' => self::ROOT.'/var/logs',
    ], $config);
    // Annotations.
    AnnotationRegistry::registerAutoloadNamespace(
      'JMS\Serializer\Annotation', self::ROOT.'/vendor/jms/serializer/src'
    );
        if ($app['config']['debug']) {
            $app->register(new VarDumperServiceProvider());
            $app->register(new Provider\HttpFragmentServiceProvider());
            $app->register(new Provider\ServiceControllerServiceProvider());
            $app->register(new Provider\TwigServiceProvider());
            $app->register(new Provider\WebProfilerServiceProvider(), [
        'profiler.cache_dir' => self::ROOT.'/cache/profiler',
        'profiler.mount_prefix' => '/_profiler', // this is the default
      ]);
          $app->register(new Provider\MonologServiceProvider(), array(
            'monolog.level' => Logger::DEBUG
          ));
        }
    // DI.
    $this->dependencies($app);
    // Add to class once set up.
    $this->app = $this->applicationFlow($app);
    }

    public function dependencies(Application $app)
    {

    //#####################################################
    // -------------------- Basics -----------------------
    //#####################################################

    // Serializer.
    $app['serializer'] = function () {
        return SerializerBuilder::create()
          ->configureListeners(function (EventDispatcher $dispatcher) {
              // Configure discriminators and subscribers here.
            // See search for example.
            // $dispatcher->addSubscriber(new ElasticsearchDiscriminator());
          })
          ->setCacheDir(self::ROOT.'/cache')
          ->build()
        ;
    };
        $app['serializer.context'] = function () {
            return SerializationContext::create();
        };
    // General cache.
    $app['cache'] = function () {
        return new FilesystemCache(self::ROOT.'/cache');
    };
    // Annotation reader.
    $app['annotations.reader'] = function (Application $app) {
        if ($app['config']['annotation_cache'] === false) {
            return new AnnotationReader();
        }

        return new CachedReader(
        new AnnotationReader(),
        $app['cache'],
        $app['config']['debug']
      );
    };
    // PSR-7 Bridge
    $app['psr7.bridge'] = function () {
        return new DiactorosFactory();
    };
    // Validator.
    $app['message-validator'] = function (Application $app) {
        return new JsonMessageValidator(
        new PathBasedSchemaFinder(ComposerLocator::getPath('elife/api').'/dist/model'),
        new Validator()
      );
    };

    $app['logger'] = function (Application $app) {
        $factory = new LoggingFactory($app['config']['file_logs_path'], 'starter');
        return $factory->logger();
    };


    //#####################################################
    // ------------------ Networking ---------------------
    //#####################################################

    $app['guzzle'] = function (Application $app) {
        // Create default HandlerStack
      $stack = HandlerStack::create();
        $stack->push(
        new CacheMiddleware(
          new PublicCacheStrategy(
            new DoctrineCacheStorage(
              $app['cache']
            )
          )
        ),
        'cache'
      );

        return new Client([
        'base_uri' => $app['config']['api_url'],
        'handler' => $stack,
      ]);
    };

        $app['api.sdk'] = function (Application $app) {
            return new ApiSdk(
        new Guzzle6HttpClient(
          $app['guzzle']
        )
      );
        };

        $app['default_controller'] = function (Application $app) {
            return new DefaultController($app['logger']);
        };
    }

    public function applicationFlow(Application $app) : Application
    {
        // Routes
    $this->routes($app);
    // Validate.
    if ($app['config']['validate']) {
        $app->after([$this, 'validate'], 2);
    }
    // Cache.
    if ($app['config']['ttl'] > 0) {
        $app->after([$this, 'cache'], 3);
    }
    // Error handling.
    if (!$app['config']['debug']) {
        $app->error([$this, 'handleException']);
    }
    // Return
    return $app;
    }

    public function routes(Application $app)
    {
        foreach (self::$routes as $route => $action) {
            $app->get($route, [$app['default_controller'], $action]);
        }
    }

    public function handleException($e) : Response
    {
    }

    public function withApp(callable $fn)
    {
        $boundFn = Closure::bind($fn, $this);
        $boundFn($this->app);

        return $this;
    }

    public function run()
    {
        return $this->app->run();
    }

    public function get($d)
    {
        return $this->app[$d];
    }

    public function validate(Request $request, Response $response)
    {
        try {
            if (strpos($response->headers->get('Content-Type'), 'json')) {
                $this->app['message-validator']->validate(
          $this->app['psr7.bridge']->createResponse($response)
        );
            }
        } catch (Throwable $e) {
            if ($this->app['config']['debug']) {
                throw $e;
            }
        }
    }

    public function cache(Request $request, Response $response)
    {
    }
}
