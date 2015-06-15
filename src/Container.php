<?php

namespace mindplay\boxy;

use Closure;
use ReflectionParameter;
use RuntimeException;
use InvalidArgumentException;
use ReflectionFunction;

/**
 * This class implements a type-hinted container to hold an open set of
 * singleton service objects and/or component factory functions.
 */
class Container
{
    /**
     * @var string pattern for parsing an argument type from a ReflectionParameter string
     *
     * @see getArgumentType()
     */
    const ARG_PATTERN = '/.*\[\s*(?:\<required\>|\<optional\>)\s*([^\s\$]*)\s/';

    /**
     * @var Closure[] map where component index => `function (...$service) : T`
     */
    protected $creators = array();

    /**
     * @var bool[] map where component index => flag indicating whether a function is a component factory
     */
    protected $is_service = array();

    /**
     * @var (Closure[])[] map where component index => `function ($component)`
     */
    protected $initializers = array();

    /**
     * @var object[] map where component index => singleton service object
     */
    protected $services = array();

    /**
     * Register a new singleton service factory function
     *
     * @param string  $type    class or interface name
     * @param Closure $creator `function (...$service) : T` creates and initializes the T service
     *
     * @return void
     */
    public function registerService($type, Closure $creator)
    {
        $this->define(null, $type, $creator, true);
    }

    /**
     * Register a new, named singleton service factory function
     *
     * @param string  $name    service/component name
     * @param string  $type    class or interface name
     * @param Closure $creator `function (...$service) : T` creates and initializes the T service
     *
     * @return void
     */
    public function registerNamedService($name, $type, Closure $creator)
    {
        $this->define($name, $type, $creator, true);
    }

    /**
     * Override an existing singleton service factory function
     *
     * @param string  $type    class or interface name
     * @param Closure $creator `function (...$service) : T` creates and initializes the T service
     *
     * @return void
     */
    public function overrideService($type, Closure $creator)
    {
        $this->override(null, $type, $creator, true);
    }

    /**
     * Override an existing named singleton service factory function
     *
     * @param string  $name    service/component name
     * @param string  $type    class or interface name
     * @param Closure $creator `function (...$service) : T` creates and initializes the T service
     *
     * @return void
     */
    public function overrideNamedService($name, $type, Closure $creator)
    {
        $this->override($name, $type, $creator, true);
    }

    /**
     * Register a new component factory function
     *
     * @param string      $type    class or interface name
     * @param Closure     $creator `function (...$service) : T` creates and initializes the T component
     * @param string|null $name    optional service/component name
     *
     * @return void
     */
    public function registerComponent($type, Closure $creator)
    {
        $this->define(null, $type, $creator, false);
    }

    /**
     * Register a new named component factory function
     *
     * @param string  $name    service/component name
     * @param string  $type    class or interface name
     * @param Closure $creator `function (...$service) : T` creates and initializes the T component
     *
     * @return void
     */
    public function registerNamedComponent($name, $type, Closure $creator)
    {
        $this->define($name, $type, $creator, false);
    }

    /**
     * Override an existing component factory function
     *
     * @param string      $type    class or interface name
     * @param Closure     $creator `function (...$service) : T` creates and initializes the T component
     *
     * @return void
     */
    public function overrideComponent($type, Closure $creator)
    {
        $this->override(null, $type, $creator, false);
    }

    /**
     * Override an existing named component factory function
     *
     * @param string  $name    service/component name
     * @param string  $type    class or interface name
     * @param Closure $creator `function (...$service) : T` creates and initializes the T component
     *
     * @return void
     */
    public function overrideNamedComponent($name, $type, Closure $creator)
    {
        $this->override($name, $type, $creator, false);
    }

    /**
     * Inserts an existing service object directly into the container
     *
     * @param object      $service service instance
     * @param string|null $name    optional service name
     *
     * @return void
     */
    public function insertService($service, $name = null)
    {
        $this->setService($service, $name, false);
    }

    /**
     * Replace an existing service object directly in the container
     *
     * @param object      $service service instance
     * @param string|null $name    optional service name
     *
     * @return void
     */
    public function replaceService($service, $name = null)
    {
        $this->setService($service, $name, true);
    }

    /**
     * Invoke a consumer function, providing all required services as arguments
     *
     * @param Closure $func a function with type-hinted parameters to inject services/components
     *
     * @return object return value from the called function
     */
    public function invoke(Closure $func)
    {
        $f = new ReflectionFunction($func);

        $args = array();

        foreach ($f->getParameters() as $param) {
            $name = $param->getName();
            $type = $this->getArgumentType($param);

            if ($type === null) {
                throw new RuntimeException("missing type-hint for argument: {$name}");
            }

            if ($param->isOptional() && !$this->canResolve($name, $type)) {
                $args[] = null;
            } else {
                $args[] = $this->resolve($name, $type);
            }
        }

        return call_user_func_array($func, $args);
    }

    /**
     * Register service and component definitions packaged by a given Provider
     *
     * @param Provider $provider
     *
     * @return void
     */
    public function register(Provider $provider)
    {
        $provider->register($this);
    }

    /**
     * Register a configuration function which will be run when a service/component is created.
     *
     * @param Closure $initializer `function ($component)` initializes/configures a service/component
     *
     * @return void
     */
    public function configure(Closure $initializer)
    {
        $this->addConfiguration($initializer, false);
    }

    /**
     * Register a configuration function which will be run when a named service/component is created.
     *
     * @param Closure $initializer `function ($component)` initializes/configures a named service/component
     *
     * @return void
     */
    public function configureNamed(Closure $initializer)
    {
        $this->addConfiguration($initializer, true);
    }

    /**
     * Register a configuration function which will be run when a service/component is created.
     *
     * @param Closure $initializer `function ($component)` initializes/configures a service/component
     * @param bool $named
     *
     * @return void
     */
    protected function addConfiguration(Closure $initializer, $named)
    {
        $reflection = new ReflectionFunction($initializer);

        if ($reflection->getNumberOfParameters() !== 1) {
            throw new InvalidArgumentException("configuration function must accept precisely one argument");
        }

        $params = $reflection->getParameters();

        $param = $params[0];

        $type = $this->getArgumentType($param);

        if ($named) {
            $name = $param->getName();
            $index = $this->index($name, $type);
        } else {
            $name = null;
            $index = $type;
        }

        if (!isset($this->creators[$index])) {
            throw new RuntimeException("undefined service/component: {$index}");
        }

        $this->initializers[$index][] = $initializer;

        if (isset($this->services[$index])) {
            // service already created - initialize immediately:

            $this->initialize($this->services[$index], $name);
        }
    }

    /**
     * Dispatch any registered configuration functions for a given service/component instance.
     *
     * @param object      $component service/component to initialize
     * @param string|null $name      optional component name
     *
     * @return void
     */
    protected function initialize($component, $name)
    {
        $type = get_class($component);

        do {
            $index = $this->index($name, $type);

            if (isset($this->initializers[$index])) {
                foreach ($this->initializers[$index] as $initialize) {
                    call_user_func($initialize, $component);
                }

                if ($this->is_service[$index]) {
                    // service initialization only happens once.

                    unset($this->initializers[$index]);
                }
            }
        } while ($type = get_parent_class($type));
    }

    /**
     * Resolve a service or component by class-name
     *
     * @param string|null $name optional component name
     * @param string      $type service/component class-name
     *
     * @return object service object
     */
    protected function resolve($name, $type)
    {
        /**
         * @var object $component
         */

        $index = $this->index($name, $type);

        if (isset($this->services[$index])) {
            return $this->services[$index];
        }

        if (isset($this->services[$type])) {
            return $this->services[$type];
        }

        if (isset($this->creators[$index])) {
            // nothing to do here - already got the best matching index.
        } elseif (isset($this->creators[$type])) {
            $index = $type;
            $name = null;
        } else {
            throw new RuntimeException("undefined service/component: {$index}");
        }

        $component = $this->invoke($this->creators[$index]);

        if (!$component instanceof $type) {
            $wrong_type = is_object($component)
                ? get_class($component)
                : gettype($component);

            throw new RuntimeException("factory function for {$index} returned wrong type: {$wrong_type}");
        }

        $this->initialize($component, $name);

        if ($this->is_service[$index]) {
            $this->services[$index] = $component; // register component as a service
        }

        return $component;
    }

    /**
     * Resolve a service or component by class-name
     *
     * @param string|null $name optional component name
     * @param string      $type service/component class-name
     *
     * @return bool true, if the given name and type can be resolved
     */
    protected function canResolve($name, $type)
    {
        $index = $this->index($name, $type);

        return isset($this->services[$index])
        || isset($this->creators[$index])
        || isset($this->services[$type])
        || isset($this->creators[$type]);
    }

    /**
     * Defines the service/component factory function for a given type
     *
     * @param string|null $name       optional component name
     * @param string      $type       class or interface name
     * @param Closure     $creator    factory function
     * @param bool        $is_service true to register as a service factory; false to register as a component factory
     *
     * @return void
     */
    protected function define($name, $type, Closure $creator, $is_service)
    {
        $this->setCreator($name, $type, $creator, $is_service, false);
    }

    /**
     * Overrides the service/component factory function for a given type
     *
     * @param string|null $name       optional component name
     * @param string      $type       class or interface name
     * @param Closure     $creator    factory function
     * @param bool        $is_service true to register as a service factory; false to register as a component factory
     *
     * @return void
     */
    protected function override($name, $type, Closure $creator, $is_service)
    {
        $this->setCreator($name, $type, $creator, $is_service, true);
    }

    /**
     * Check if a service/component with a given name and type has been defined.
     *
     * @param string $index component index
     *
     * @return bool true, if a creator or service has been registered
     */
    protected function defined($index)
    {
        return isset($this->creators[$index]) || isset($this->services[$index]);
    }

    /**
     * Insert or replace an existing service object directly in the container
     *
     * @param object      $service service instance
     * @param string|null $name    optional service/component name
     * @param bool        $replace true to replace an existing service; false to throw on duplicate registration
     *
     * @return void
     */
    protected function setService($service, $name, $replace)
    {
        if (!is_object($service)) {
            $type = gettype($service);

            throw new InvalidArgumentException("unexpected argument type: {$type}");
        }

        $index = $this->index($name, get_class($service));

        if ($replace) {
            if (isset($this->creators[$index]) && !$this->is_service[$index]) {
                throw new RuntimeException("conflicing service/component registration for: {$index}");
            }
        } else {
            if ($this->defined($index)) {
                throw new RuntimeException("duplicate service/component registration for: {$index}");
            }
        }

        $this->initialize($service, $name);

        $this->services[$index] = $service;

        $this->is_service[$index] = true;
    }

    /**
     * Set the creator function for a service/component of a given type
     *
     * @param string|null $name       optional component name
     * @param string      $type       component index
     * @param Closure     $creator    factory function
     * @param bool        $is_service true to register as a service factory; false to register as a component factory
     * @param bool        $override   true to override an existing service/component
     *
     * @return void
     */
    protected function setCreator($name, $type, Closure $creator, $is_service, $override)
    {
        $index = $this->index($name, $type);

        if ($this->defined($index)) {
            if ($override) {
                if ($this->is_service[$index] !== $is_service) {
                    throw new RuntimeException("conflicing registration for service/component: {$index}");
                }

                if ($is_service && isset($this->services[$index])) {
                    throw new RuntimeException("unable to redefine a service - already initialized: {$index}");
                }
            } else {
                throw new RuntimeException("duplicate registration for service/component: {$index}");
            }
        }

        $this->creators[$index] = $creator;

        $this->is_service[$index] = $is_service;
    }

    /**
     * Extract the argument type (class name) from the first argument of a given function
     *
     * Used for diagnostics and error-handling purposes only.
     *
     * @see invoke()
     *
     * @param ReflectionParameter $param
     *
     * @return string|null class name (or null on failure)
     */
    private function getArgumentType(ReflectionParameter $param)
    {
        preg_match(self::ARG_PATTERN, $param->__toString(), $matches);

        return $matches[1] ?: null;
    }

    /**
     * @param string|null $name optional component name
     * @param string      $type class or interface name
     *
     * @return string
     */
    private function index($name, $type)
    {
        return $name === null
            ? $type
            : "{$type}#{$name}";
    }
}
