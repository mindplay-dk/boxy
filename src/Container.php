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
     * @var Closure[] map where class-name => `function (...$service) : T`
     */
    protected $creators = array();

    /**
     * @var bool[] map where class-name => flag indicating whether a function is a component factory
     */
    protected $is_service = array();

    /**
     * @var (Closure[])[] map where class-name => `function ($component)`
     */
    protected $initializers = array();

    /**
     * @var object[] map where class-name => singleton service object
     */
    protected $services = array();

    /**
     * Register a new singleton service factory function
     *
     * @param string  $type    class or interface name
     * @param Closure $creator `function (...$service) : T` creates and initializes the T service
     */
    public function registerService($type, Closure $creator)
    {
        $this->define($type, $creator, true);
    }

    /**
     * Override an existing singleton service factory function
     *
     * @param string  $type    class or interface name
     * @param Closure $creator `function (...$service) : T` creates and initializes the T service
     */
    public function overrideService($type, Closure $creator)
    {
        $this->override($type, $creator, true);
    }

    /**
     * Register a new component factory function
     *
     * @param string  $type    class or interface name
     * @param Closure $creator `function (...$service) : T` creates and initializes the T component
     */
    public function registerComponent($type, Closure $creator)
    {
        $this->define($type, $creator, false);
    }

    /**
     * Override an existing component factory function
     *
     * @param string  $type    class or interface name
     * @param Closure $creator `function (...$service) : T` creates and initializes the T component
     */
    public function overrideComponent($type, Closure $creator)
    {
        $this->override($type, $creator, false);
    }

    /**
     * Inserts an existing service object directly into the container
     *
     * @param object $service
     */
    public function insertService($service)
    {
        $this->setService($service, false);
    }

    /**
     * Replace an existing service object directly in the container
     *
     * @param object $service
     */
    public function replaceService($service)
    {
        $this->setService($service, true);
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
            $type = $this->getArgumentType($param);

            if ($type === null) {
                throw new RuntimeException("missing type-hint for argument: {$param->getName()}");
            }

            if ($param->isOptional() && !$this->defined($type)) {
                $args[] = null; // skip optional, undefined component
            } else {
                $args[] = $this->resolve($type);
            }
        }

        return call_user_func_array($func, $args);
    }

    /**
     * Register service and component definitions packaged by a given Provider
     *
     * @param Provider $provider
     */
    public function register(Provider $provider)
    {
        $provider->register($this);
    }

    /**
     * Register a configuration function which will be run when a service/component is created.
     *
     * @param callable $initializer `function ($component)` initializes/configures a service/component
     *
     * @return void
     */
    public function configure(Closure $initializer)
    {
        $reflection = new ReflectionFunction($initializer);

        if ($reflection->getNumberOfParameters() !== 1) {
            throw new InvalidArgumentException("configuration function must accept precisely one argument");
        }

        $params = $reflection->getParameters();

        $type = $this->getArgumentType($params[0]);

        $this->initializers[$type][] = $initializer;

        if (isset($this->services[$type])) {
            // service already created - initialize immediately:

            $this->initialize($this->services[$type]);
        }
    }

    /**
     * Dispatch any registered configuration functions for a given service/component instance.
     *
     * @param object $component
     *
     * @return void
     */
    protected function initialize($component)
    {
        $type = get_class($component);

        do {
            if (isset($this->initializers[$type])) {
                foreach ($this->initializers[$type] as $initialize) {
                    call_user_func($initialize, $component);
                }

                if ($this->is_service[$type]) {
                    // service initialization only happens once.

                    unset($this->initializers[$type]);
                }
            }
        } while ($type = get_parent_class($type));
    }

    /**
     * Resolve a service or component by class-name
     *
     * @param string $type service/component class-name
     *
     * @return object service object
     */
    protected function resolve($type)
    {
        /**
         * @var object $component
         */

        if (isset($this->services[$type])) {
            return $this->services[$type];
        }

        if (!isset($this->creators[$type])) {
            throw new RuntimeException("undefined service/component: {$type}");
        }

        $func = $this->creators[$type];

        $component = $this->invoke($func);

        if (!$component instanceof $type) {
            $wrong_type = is_object($component)
                ? get_class($component)
                : gettype($component);

            throw new RuntimeException("factory function for {$type} returned wrong type: {$wrong_type}");
        }

        $this->initialize($component);

        if ($this->is_service[$type]) {
            $this->services[$type] = $component; // register component as a service
        }

        return $component;
    }

    /**
     * Defines the service/component factory function for a given type
     *
     * @param string  $type       class or interface name
     * @param Closure $creator    factory function
     * @param bool    $is_service true to register as a service factory; false to register as a component factory
     */
    protected function define($type, Closure $creator, $is_service)
    {
        $this->setCreator($type, $creator, $is_service, false);
    }

    /**
     * Overrides the service/component factory function for a given type
     *
     * @param string  $type       class or interface name
     * @param Closure $creator    factory function
     * @param bool    $is_service true to register as a service factory; false to register as a component factory
     */
    protected function override($type, Closure $creator, $is_service)
    {
        $this->setCreator($type, $creator, $is_service, true);
    }

    /**
     * @param string $type
     *
     * @return bool true, if a creator or service has been registered
     */
    protected function defined($type)
    {
        return isset($this->creators[$type]) || isset($this->services[$type]);
    }

    /**
     * Insert or replace an existing service object directly in the container
     *
     * @param object $service
     * @param bool   $replace true to replace an existing service; false to throw on duplicate registration
     */
    protected function setService($service, $replace)
    {
        if (!is_object($service)) {
            $type = gettype($service);

            throw new InvalidArgumentException("unexpected argument type: {$type}");
        }

        $type = get_class($service);

        if ($replace) {
            if (isset($this->creators[$type]) && !$this->is_service[$type]) {
                throw new RuntimeException("conflicing service/component registration for: {$type}");
            }
        } else {
            if ($this->defined($type)) {
                throw new RuntimeException("duplicate service/component registration for: {$type}");
            }
        }

        $this->initialize($service);

        $this->services[$type] = $service;

        $this->is_service[$type] = true;
    }

    /**
     * Set the creator function for a service/component of a given type
     *
     * @param string  $type       class or interface name
     * @param Closure $creator    factory function
     * @param bool    $is_service true to register as a service factory; false to register as a component factory
     * @param bool    $override   true to override an existing service/component
     */
    protected function setCreator($type, Closure $creator, $is_service, $override)
    {
        if ($this->defined($type)) {
            if ($override) {
                if ($this->is_service[$type] !== $is_service) {
                    throw new RuntimeException("conflicing registration for service/component: {$type}");
                }

                if ($is_service && isset($this->services[$type])) {
                    throw new RuntimeException("unable to redefine a service - already initialized: {$type}");
                }
            } else {
                throw new RuntimeException("duplicate registration for service/component: {$type}");
            }
        }

        $this->creators[$type] = $creator;

        $this->is_service[$type] = $is_service;
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
    protected function getArgumentType(ReflectionParameter $param)
    {
        preg_match(self::ARG_PATTERN, $param->__toString(), $matches);

        return $matches[1] ?: null;
    }
}
