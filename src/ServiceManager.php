<?php
/**
 * @link      http://github.com/zendframework/zend-servicemanager for the canonical source repository
 * @copyright Copyright (c) 2005-2016 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 */

namespace Zend\ServiceManager;

use Exception;
use Interop\Container\ContainerInterface;
use Interop\Container\Exception\ContainerException;
use ProxyManager\Configuration as ProxyConfiguration;
use ProxyManager\Factory\LazyLoadingValueHolderFactory;
use ProxyManager\FileLocator\FileLocator;
use ProxyManager\GeneratorStrategy\EvaluatingGeneratorStrategy;
use ProxyManager\GeneratorStrategy\FileWriterGeneratorStrategy;
use Zend\ServiceManager\Exception\ContainerModificationsNotAllowedException;
use Zend\ServiceManager\Exception\CyclicAliasException;
use Zend\ServiceManager\Exception\InvalidArgumentException;
use Zend\ServiceManager\Exception\ServiceNotCreatedException;
use Zend\ServiceManager\Exception\ServiceNotFoundException;

use function array_merge_recursive;
use function class_exists;
use function get_class;
use function gettype;
use function in_array;
use function is_callable;
use function is_object;
use function is_string;
use function spl_autoload_register;
use function spl_object_hash;
use function sprintf;
use function trigger_error;
use Zend\ServiceManager\Factory\InvokableFactory;

/**
 * Service Manager.
 *
 * Default implementation of the ServiceLocatorInterface, providing capabilities
 * for object creation via:
 *
 * - factories
 * - abstract factories
 * - delegator factories
 * - lazy service factories (generated proxies)
 * - initializers (interface injection)
 *
 * It also provides the ability to inject specific service instances and to
 * define aliases.
 */
class ServiceManager implements ServiceLocatorInterface
{
    /**
     * @var Factory\AbstractFactoryInterface[]
     */
    protected $abstractFactories = [];

    /**
     * A list of aliases
     *
     * Should map one alias to a service name, or another alias (aliases are recursively resolved)
     *
     * @var string[]
     */
    protected $aliases = [];

    /**
     * Whether or not changes may be made to this instance.
     *
     * @param bool
     */
    protected $allowOverride = false;

    /**
     * @var ContainerInterface
     */
    protected $creationContext;

    /**
     * @var string[][]|Factory\DelegatorFactoryInterface[][]
     */
    protected $delegators = [];

    /**
     * A list of factories (either as string name or callable)
     *
     * @var string[]|callable[]
     */
    protected $factories = [];

    /**
     * A list of invokable classes
     *
     * @var string[]|callable[]
     */
    protected $invokables = [];

    /**
     * @var Initializer\InitializerInterface[]|callable[]
     */
    protected $initializers = [];

    /**
     * @var array
     */
    protected $lazyServices = [];

    /**
     * @var null|Proxy\LazyServiceFactory
     */
    private $lazyServicesDelegator;

    /**
     * A list of already loaded services (this act as a local cache)
     *
     * @var array
     */
    protected $services = [];

    /**
     * Enable/disable shared instances by service name.
     *
     * Example configuration:
     *
     * 'shared' => [
     *     MyService::class => true, // will be shared, even if "sharedByDefault" is false
     *     MyOtherService::class => false // won't be shared, even if "sharedByDefault" is true
     * ]
     *
     * @var boolean[]
     */
    protected $shared = [];

    /**
     * Should the services be shared by default?
     *
     * @var bool
     */
    protected $sharedByDefault = true;

    /**
     * Cached abstract factories from string.
     *
     * @var array
     */
    private $cachedAbstractFactories = [];

    /**
     * Constructor.
     *
     * See {@see \Zend\ServiceManager\ServiceManager::configure()} for details
     * on what $config accepts.
     *
     * @param array $config
     */
    public function __construct(array $config = [])
    {
        $this->creationContext = $this;

        if (! empty($this->aliases)) {
            $this->mapAliasesToTargets();
        }

        if (! empty($this->initializers)) {
            // null indicates resolveInitializers to initialize
            // from $this->initializers
            $this->resolveInitializers(null);
        }

        if (! empty($this->abstractFactories)) {
            // null indicates resolveAbstractFactory to initialize
            // from $this->abstractFactories
            $this->resolveAbstractFactories(null);
        }

        $this->configure($config);
    }

    /**
     * Implemented for backwards compatibility with previous plugin managers only.
     *
     * Returns the creation context.
     *
     * @deprecated since 3.0.0. Factories using 3.0 should use the container
     *     instance passed to the factory instead.
     * @return ContainerInterface
     */
    public function getServiceLocator()
    {
        trigger_error(sprintf(
            'Usage of %s is deprecated since v3.0.0; please use the container passed to the factory instead',
            __METHOD__
        ), E_USER_DEPRECATED);
        return $this->creationContext;
    }

    /**
     * {@inheritDoc}
     */
    public function get($name)
    {
        // We start by checking if we have cached the requested service;
        // this is the fastest method.
        if (isset($this->services[$name])) {
            return $this->services[$name];
        }

        // Determine if the service should be shared.
        $sharedService = isset($this->shared[$name]) ? $this->shared[$name] : $this->sharedByDefault;

        // We achieve better performance if we can let all alias
        // considerations out.
        if (! $this->aliases) {
            $object = $this->doCreate($name);

            // Cache the object for later, if it is supposed to be shared.
            if ($sharedService) {
                $this->services[$name] = $object;
            }
            return $object;
        }

        // We now deal with requests which may be aliases.
        $resolvedName = isset($this->aliases[$name]) ? $this->aliases[$name] : $name;

        // The following is only true if the requested service is a shared alias.
        $sharedAlias = $sharedService && isset($this->services[$resolvedName]);

        // If the alias is configured as a shared service, we are done.
        if ($sharedAlias) {
            $this->services[$name] = $this->services[$resolvedName];
            return $this->services[$resolvedName];
        }

        // At this point, we have to create the object.
        // We use the resolved name for that.
        $object = $this->doCreate($resolvedName);

        // Cache the object for later, if it is supposed to be shared.
        if ($sharedService) {
            $this->services[$resolvedName] = $object;
        }

        // Also cache under the alias name; this allows sharing based on the
        // service name used.
        if ($sharedAlias) {
            $this->services[$name] = $object;
        }

        return $object;
    }

    /**
     * {@inheritDoc}
     */
    public function build($name, array $options = null)
    {
        // We never cache when using "build".
        $name = $this->aliases[$name] ?? $name;
        return $this->doCreate($name, $options);
    }

    /**
     * {@inheritDoc}
     */
    public function has($name)
    {
        $resolvedName = $this->aliases[$name] ?? $name;
        // Check services and factories first to speedup the most common requests.
        if (isset($this->services[$resolvedName])
            || isset($this->factories[$resolvedName])
            || isset($this->invokables[$resolvedName])) {
            return true;
        }

        // Check abstract factories next.
        foreach ($this->abstractFactories as $abstractFactory) {
            if ($abstractFactory->canCreate($this->creationContext, $resolvedName)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Indicate whether or not the instance is immutable.
     *
     * @param bool $flag
     */
    public function setAllowOverride($flag)
    {
        $this->allowOverride = (bool) $flag;
    }

    /**
     * Retrieve the flag indicating immutability status.
     *
     * @return bool
     */
    public function getAllowOverride()
    {
        return $this->allowOverride;
    }

    /**
     * Configure the service manager
     *
     * Valid top keys are:
     *
     * - services: service name => service instance pairs
     * - invokables: service name => class name pairs for classes that do not
     *   have required constructor arguments; internally, maps the class to an
     *   InvokableFactory instance, and creates an alias if the service name
     *   and class name do not match.
     * - factories: service name => factory pairs; factories may be any
     *   callable, string name resolving to an invokable class, or string name
     *   resolving to a FactoryInterface instance.
     * - abstract_factories: an array of abstract factories; these may be
     *   instances of AbstractFactoryInterface, or string names resolving to
     *   classes that implement that interface.
     * - delegators: service name => list of delegator factories for the given
     *   service; each item in the list may be a callable, a string name
     *   resolving to an invokable class, or a string name resolving to a class
     *   implementing DelegatorFactoryInterface.
     * - shared: service name => flag pairs; the flag is a boolean indicating
     *   whether or not the service is shared.
     * - aliases: alias => service name pairs.
     * - lazy_services: lazy service configuration; can contain the keys:
     *   - class_map: service name => class name pairs.
     *   - proxies_namespace: string namespace to use for generated proxy
     *     classes.
     *   - proxies_target_dir: directory in which to write generated proxy
     *     classes; uses system temporary by default.
     *   - write_proxy_files: boolean indicating whether generated proxy
     *     classes should be written; defaults to boolean false.
     * - shared_by_default: boolean, indicating if services in this instance
     *   should be shared by default.
     *
     * @param  array $config
     * @return self
     * @throws ContainerModificationsNotAllowedException if the allow
     *     override flag has been toggled off, and a service instance
     *     exists for a given service.
     */
    public function configure(array $config)
    {
        $allowOverride = empty($this->services) || $this->allowOverride;

        if ($allowOverride) {
                // This is the fast track. We can just merge.
            if (! empty($config['services'])) {
                $this->services = $config['services'] + $this->services;
            }
            if (! empty($config['aliases'])) {
                $this->aliases = $config['aliases'] + $this->aliases;
                $this->mapAliasesToTargets();
            }
            if (! empty($config['delegators'])) {
                $this->delegators = array_merge_recursive($this->delegators, $config['delegators']);
            }
            if (! empty($config['factories'])) {
                $this->factories = $config['factories'] + $this->factories;
            }
            if (! empty($config['invokables'])) {
                $this->invokables = $config['invokables'] + $this->invokables;
            }
            if (! empty($config['shared'])) {
                $this->shared = $config['shared'] + $this->shared;
            }
            if (! empty($config['lazy_services']['class_map'])) {
                $this->lazyServices['class_map'] = isset($this->lazyServices['class_map'])
                    ? $config['lazy_services']['class_map'] + $this->lazyServices['class_map']
                    : $config['lazy_services']['class_map'];
                $this->lazyServicesDelegator = null;
                // we merge the rest of lazy_services later
                unset($config['lazy_services']['class_map']);
            }
        } else {
            if (! empty($config['services'])) {
                foreach ($config['services'] as $name => $service) {
                    // If allowOverride was false, we are here because $this->services was not empty,
                    // so checking $this->services only is sufficient obviously.
                    // If $this->services was not empty, we are here because $this->allowOverride was false,
                    // so checking $this->services only is sufficient, also.
                    // If this->service was not empty and allowOverride was false we are in the trivial case,
                    // so checking $this->services only is sufficient, also
                    if (isset($this->services[$name])) {
                        throw ContainerModificationsNotAllowedException::fromExistingService($name);
                    }
                    // @todo: Question is, if we are allowed to overwrite services
                    // registered in this loop from within the following clauses of this else
                    // branch or if this new services should become override protected immediately
                    // if $this->allowOverride is false.
                    $this->services[$name] = $service;
                }
            }
            // Assuming that the services registered above need override protection also, we continue
            // protecting the newly registered services from the loop above like the services we already
            // knew before configure() was called. This behaviour is different from the implementation
            // before, but all tests pass.
            if (! empty($config['aliases'])) {
                foreach ($config['aliases'] as $alias => $target) {
                    if (isset($this->services[$alias])) {
                        throw ContainerModificationsNotAllowedException::fromExistingService($alias);
                    }
                    $this->aliases[$alias] = $target;
                }
                $this->mapAliasesToTargets();
            }
            if (! empty($config['delegators'])) {
                foreach ($config['delegators'] as $name => $delegator) {
                    if (isset($this->services[$name])) {
                        throw ContainerModificationsNotAllowedException::fromExistingService($name);
                    }
                }
                // @todo: resolve that within the loop
                $this->delegators = array_merge_recursive($config['delegators'], $this->delegators);
            }
            if (! empty($config['factories'])) {
                foreach ($config['factories'] as $name => $factory) {
                    if (isset($this->services[$name])) {
                        throw ContainerModificationsNotAllowedException::fromExistingService($name);
                    }
                    $this->factories[$name] = $factory;
                }
            }
            if (! empty($config['invokables'])) {
                foreach ($config['invokables'] as $name => $service) {
                    if (isset($this->services[$name])) {
                        throw ContainerModificationsNotAllowedException::fromExistingService($name);
                    }
                    $this->invokables[$name] = $service;
                }
            }

            if (! empty($config['shared'])) {
                foreach ($config['shared'] as $name => $shared) {
                    if (isset($this->services[$name])) {
                        throw ContainerModificationsNotAllowedException::fromExistingService($name);
                    }
                    $this->shared[$name] = $shared;
                }
            }
            // If lazy service configuration was provided, reset the lazy services
            // delegator factory.
            if (! empty($config['lazy_services']['class_map'])) {
                foreach ($config['lazy_services']['class_map'] as $name => $service) {
                    if (isset($this->services[$name])) {
                        throw ContainerModificationsNotAllowedException::fromExistingService($name);
                    }
                    $this->lazyServices['class_map'][$name] = $service;
                }
                $this->lazyServicesDelegator = null;
                // we merge the rest of lazy_services later
                unset($config['lazy_services']['class_map']);
            }
        }
        // we merge the rest of supplied lazy_services if present
        if (! empty($config['lazy_services'])) {
            $this->lazyServices = $config['lazy_services'] + $this->lazyServices;
        }

        if (isset($config['shared_by_default'])) {
            $this->sharedByDefault = $config['shared_by_default'];
        }

        // For abstract factories and initializers, we always directly
        // instantiate them to avoid checks during service construction.
        if (! empty($config['abstract_factories'])) {
            $this->resolveAbstractFactories($config['abstract_factories']);
        }

        if (! empty($config['initializers'])) {
            $this->resolveInitializers($config['initializers']);
        }
        return $this;
    }

    /**
     * Add an alias.
     *
     * @param string $alias
     * @param string $target
     * @throws ContainerModificationsNotAllowedException if $alias already
     *     exists as a service and overrides are disallowed.
     */
    public function setAlias($alias, $target)
    {
        if (isset($this->services[$alias]) && ! $this->allowOverride) {
            throw ContainerModificationsNotAllowedException::fromExistingService($alias);
        }
        $this->mapAliasToTarget($alias, $target);
    }

    /**
     * Add an invokable class mapping.
     *
     * @param string $name Service name
     * @param null|string $class Class to which to map; if omitted, $name is
     *     assumed.
     * @throws ContainerModificationsNotAllowedException if $name already
     *     exists as a service and overrides are disallowed.
     */
    public function setInvokableClass($name, $class = null)
    {
        if (isset($this->services[$name]) && ! $this->allowOverride) {
            throw ContainerModificationsNotAllowedException::fromExistingService($name);
        }
        $this->invokables[$name] = $class ?? $name;
    }

    /**
     * Specify a factory for a given service name.
     *
     * @param string $name Service name
     * @param string|callable|Factory\FactoryInterface $factory Factory to which
     *     to map.
     * @throws ContainerModificationsNotAllowedException if $name already
     *     exists as a service and overrides are disallowed.
     */
    public function setFactory($name, $factory)
    {
        if (isset($this->services[$name]) && ! $this->allowOverride) {
            throw ContainerModificationsNotAllowedException::fromExistingService($name);
        }
        $this->factories[$name] = $factory;
    }

    /**
     * Create a lazy service mapping to a class.
     *
     * @param string $name Service name to map
     * @param null|string $class Class to which to map; if not provided, $name
     *     will be used for the mapping.
     */
    public function mapLazyService($name, $class = null)
    {
        if (isset($this->services[$name]) && ! $this->allowOverride) {
            throw ContainerModificationsNotAllowedException::fromExistingService($name);
        }
        $this->lazyServices = array_merge_recursive(['class_map' => [$name => $class ?? $name]]);
        $this->lazyServicesDelegator = null;
    }

    /**
     * Add an abstract factory for resolving services.
     *
     * @param string|Factory\AbstractFactoryInterface $factory Abstract factory
     *     instance or class name.
     */
    public function addAbstractFactory($factory)
    {
        $this->resolveAbstractFactories([$factory]);
    }

    /**
     * Add a delegator for a given service.
     *
     * @param string $name Service name
     * @param string|callable|Factory\DelegatorFactoryInterface $factory Delegator
     *     factory to assign.
     */
    public function addDelegator($name, $factory)
    {
        if (isset($this->services[$name]) && ! $this->allowOverride) {
            throw ContainerModificationsNotAllowedException::fromExistingService($name);
        }
        $this->delegators = array_merge_recursive($this->delegators, [$name => [$factory]]);
    }

    /**
     * Add an initializer.
     *
     * @param string|callable|Initializer\InitializerInterface $initializer
     */
    public function addInitializer($initializer)
    {
        $this->resolveInitializers([$initializer]);
    }

    /**
     * Map a service.
     *
     * @param string $name Service name
     * @param array|object $service
     * @throws ContainerModificationsNotAllowedException if $name already
     *     exists as a service and overrides are disallowed.
     */
    public function setService($name, $service)
    {
        if (isset($this->services[$name]) && ! $this->allowOverride) {
            throw ContainerModificationsNotAllowedException::fromExistingService($name);
        }
        $this->services[$name] = $service;
    }

    /**
     * Add a service sharing rule.
     *
     * @param string $name Service name
     * @param boolean $flag Whether or not the service should be shared.
     * @throws ContainerModificationsNotAllowedException if $name already
     *     exists as a service and overrides are disallowed.
     */
    public function setShared($name, $flag)
    {
        if (isset($this->services[$name]) && ! $this->allowOverride) {
            throw ContainerModificationsNotAllowedException::fromExistingService($name);
        }
        $this->shared[$name] = (bool) $flag;
    }

    /**
     * Instantiate initializers for to avoid checks during service construction.
     *
     * @param string[]|Initializer\InitializerInterface[]|callable[] $initializers
     *
     */
    private function resolveInitializers(array $initializers = null)
    {
        if ($initializers === null) {
            $initializers = $this->initializers;
            unset($this->initializers);
        }

        foreach ($initializers as $initializer) {
            if (is_string($initializer) && class_exists($initializer)) {
                $initializer = new $initializer();
            }

            if (is_callable($initializer)) {
                $this->initializers[] = $initializer;
                return;
            }

            throw InvalidArgumentException::fromInvalidInitializer($initializer);
        }
    }

    /**
     * Get a factory for the given service name
     *
     * @param  string $name
     * @return callable
     * @throws ServiceNotFoundException
     */
    private function getFactory(&$name)
    {
        $factory = $this->factories[$name] ?? null;

        $lazyLoaded = false;
        if (is_string($factory) && class_exists($factory)) {
            $factory = new $factory();
            $lazyLoaded = true;
        }

        if (is_callable($factory)) {
            if ($lazyLoaded) {
                $this->factories[$name] = $factory;
            }

            return $factory;
        } elseif (isset($this->invokables[$name])) {
            // The little trick to pass $name by reference to
            // getFactory was necessary to enable late resolution
            // of invokables here.
            $name = $this->invokables[$name];
            return new InvokableFactory();
        }

        // Check abstract factories
        foreach ($this->abstractFactories as $abstractFactory) {
            if ($abstractFactory->canCreate($this->creationContext, $name)) {
                return $abstractFactory;
            }
        }

        throw new ServiceNotFoundException(sprintf(
            'Unable to resolve service "%s" to a factory; are you certain you provided it during configuration?',
            $name
        ));
    }

    /**
     * @param  string     $name
     * @param  null|array $options
     * @return object
     */
    private function createDelegatorFromName($name, array $options = null)
    {
        $creationCallback = function () use ($name, $options) {
            // Code is inlined for performance reason, instead of abstracting the creation
            $factory = $this->getFactory($name);
            return $factory($this->creationContext, $name, $options);
        };

        foreach ($this->delegators[$name] as $index => $delegatorFactory) {
            $delegatorFactory = $this->delegators[$name][$index];

            if ($delegatorFactory === Proxy\LazyServiceFactory::class) {
                $delegatorFactory = $this->createLazyServiceDelegatorFactory();
            }

            if (is_string($delegatorFactory) && class_exists($delegatorFactory)) {
                $delegatorFactory = new $delegatorFactory();
            }

            if (! is_callable($delegatorFactory)) {
                if (is_string($delegatorFactory)) {
                    throw new ServiceNotCreatedException(sprintf(
                        'An invalid delegator factory was registered; resolved to class or function "%s"'
                        . ' which does not exist; please provide a valid function name or class name resolving'
                        . ' to an implementation of %s',
                        $delegatorFactory,
                        DelegatorFactoryInterface::class
                    ));
                }

                throw new ServiceNotCreatedException(sprintf(
                    'A non-callable delegator, "%s", was provided; expected a callable or instance of "%s"',
                    is_object($delegatorFactory) ? get_class($delegatorFactory) : gettype($delegatorFactory),
                    DelegatorFactoryInterface::class
                ));
            }

            $this->delegators[$name][$index] = $delegatorFactory;

            $creationCallback = function () use ($delegatorFactory, $name, $creationCallback, $options) {
                return $delegatorFactory($this->creationContext, $name, $creationCallback, $options);
            };
        }

        return $creationCallback($this->creationContext, $name, $creationCallback, $options);
    }

    /**
     * Create a new instance with an already resolved name
     *
     * This is a highly performance sensitive method, do not modify if you have not benchmarked it carefully
     *
     * @param  string     $resolvedName
     * @param  null|array $options
     * @return mixed
     * @throws ServiceNotFoundException if unable to resolve the service.
     * @throws ServiceNotCreatedException if an exception is raised when
     *     creating a service.
     * @throws ContainerException if any other error occurs
     */
    private function doCreate($resolvedName, array $options = null)
    {
        try {
            if (! isset($this->delegators[$resolvedName])) {
                // Let's create the service by fetching the factory
                $factory = $this->getFactory($resolvedName);
                $object  = $factory($this->creationContext, $resolvedName, $options);
            } else {
                $object = $this->createDelegatorFromName($resolvedName, $options);
            }
        } catch (ContainerException $exception) {
            throw $exception;
        } catch (Exception $exception) {
            throw new ServiceNotCreatedException(sprintf(
                'Service with name "%s" could not be created. Reason: %s',
                $resolvedName,
                $exception->getMessage()
            ), (int) $exception->getCode(), $exception);
        }

        foreach ($this->initializers as $initializer) {
            $initializer($this->creationContext, $object);
        }

        return $object;
    }

    /**
     * Create the lazy services delegator factory.
     *
     * Creates the lazy services delegator factory based on the lazy_services
     * configuration present.
     *
     * @return Proxy\LazyServiceFactory
     * @throws ServiceNotCreatedException when the lazy service class_map
     *     configuration is missing
     */
    private function createLazyServiceDelegatorFactory()
    {
        if ($this->lazyServicesDelegator) {
            return $this->lazyServicesDelegator;
        }

        if (! isset($this->lazyServices['class_map'])) {
            throw new ServiceNotCreatedException('Missing "class_map" config key in "lazy_services"');
        }

        $factoryConfig = new ProxyConfiguration();

        if (isset($this->lazyServices['proxies_namespace'])) {
            $factoryConfig->setProxiesNamespace($this->lazyServices['proxies_namespace']);
        }

        if (isset($this->lazyServices['proxies_target_dir'])) {
            $factoryConfig->setProxiesTargetDir($this->lazyServices['proxies_target_dir']);
        }

        if (! isset($this->lazyServices['write_proxy_files']) || ! $this->lazyServices['write_proxy_files']) {
            $factoryConfig->setGeneratorStrategy(new EvaluatingGeneratorStrategy());
        } else {
            $factoryConfig->setGeneratorStrategy(new FileWriterGeneratorStrategy(
                new FileLocator($factoryConfig->getProxiesTargetDir())
            ));
        }

        spl_autoload_register($factoryConfig->getProxyAutoloader());

        $this->lazyServicesDelegator = new Proxy\LazyServiceFactory(
            new LazyLoadingValueHolderFactory($factoryConfig),
            $this->lazyServices['class_map']
        );

        return $this->lazyServicesDelegator;
    }

    /**
     * Create aliases and factories for invokable classes.
     *
     * If an invokable service name does not match the class it maps to, this
     * creates an alias to the class (which will later be mapped as an
     * invokable factory).
     *
     * @param array $invokables
     */
    private function createAliasesAndFactoriesForInvokables(array $invokables)
    {
        foreach ($invokables as $name => $class) {
            $this->factories[$class] = Factory\InvokableFactory::class;
            if ($name !== $class) {
                $this->aliases[$name] = $class;
            }
        }
    }

    /**
     * Assuming that the alias name is valid (see above) resolve/add it.
     *
     * This is done differently from bulk mapping aliases for performance reasons, as the
     * algorithms for mapping a single item efficiently are different from those of mapping
     * many.
     *
     * @see mapAliasesToTargets() below
     *
     * @param string $alias
     * @param string $target
     */
    private function mapAliasToTarget($alias, $target)
    {
        // localize to avoid continous dereferencing
        $aliases = &$this->aliases;

        // $target is either an alias or something else
        // if it is an alias, resolve it
        $aliases[$alias] = $aliases[$target] ?? $target;

        // a self-referencing alias indicates a cycle
        if ($alias === $aliases[$alias]) {
            throw CyclicAliasException::fromCyclicAlias($alias, $aliases);
        }

        // finally we have to check if existing incomplete alias definitions
        // exist which can get resolved by the new alias
        if (in_array($alias, $aliases)) {
            $r = array_intersect($aliases, [ $alias ]);
            // found some, resolve them
            foreach ($r as $name => $_) {
                $aliases[$name] = $target;
            }
        }
    }

    /**
     * Assuming that all provided alias keys are valid resolve them.
     *
     * This function maps $this->aliases in place.
     *
     * This algorithm is an adaptated version of Tarjans Strongly
     * Connected Components. Instead of returning the strongly
     * connected components (i.e. cycles in our case), we throw.
     * If nodes are not strongly connected (i.e. resolvable in
     * our case), they get resolved.
     *
     * This algorithm is fast for mass updates through configure().
     * It is not appropriate if just a single alias is added.
     *
     * @see mapAliasToTarget above
     *
     */
    private function mapAliasesToTargets()
    {
        // localize to avoid continous dereferencing
        $aliases = &$this->aliases;

        $tagged = [];

        foreach ($aliases as $alias => $target) {
            if (isset($tagged[$alias])) {
                continue;
            }

            if ($alias === $target) {
                throw CyclicAliasException::fromCyclicAlias($alias, $aliases);
            }

            $tCursor = $target;
            $aCursor = $alias;
            $stack = [];

            while (isset($aliases[$tCursor])) {
                $stack[] = $aCursor;
                if ($aCursor === $aliases[$tCursor]) {
                    throw CyclicAliasException::fromCyclicAlias($alias, $aliases);
                }
                $aCursor = $tCursor;
                $tCursor = $aliases[$tCursor];
            }

            $tagged[$aCursor] = true;

            foreach ($stack as $alias) {
                if ($alias === $tCursor) {
                    throw CyclicAliasException::fromCyclicAlias($alias, $aliases);
                }
                $aliases[$alias] = $tCursor;
                $tagged[$alias] = true;
            }
        }
    }

    /**
     * Instantiate abstract factories in order to avoid checks during service construction.
     *
     * @param string[]|Factory\AbstractFactoryInterface[] $abstractFactories
     */
    private function resolveAbstractFactories(array $abstractFactories = null)
    {
        if ($abstractFactories === null) {
            $abstractFactories = $this->abstractFactories;
            unset($this->abstractFactories);
        }

        foreach ($abstractFactories as $abstractFactory) {
            if (is_string($abstractFactory) && class_exists($abstractFactory)) {
                // cached string
                if (! isset($this->cachedAbstractFactories[$abstractFactory])) {
                    $this->cachedAbstractFactories[$abstractFactory] = new $abstractFactory();
                }

                $abstractFactory = $this->cachedAbstractFactories[$abstractFactory];
            }

            if ($abstractFactory instanceof Factory\AbstractFactoryInterface) {
                $abstractFactoryObjHash = spl_object_hash($abstractFactory);
                $this->abstractFactories[$abstractFactoryObjHash] = $abstractFactory;
                continue;
            }

            throw InvalidArgumentException::fromInvalidAbstractFactory($abstractFactory);
        }
    }
}
