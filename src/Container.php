<?php

namespace mindplay\boxy;

use mindplay\filereflection\ReflectionFile;

use Closure;
use ReflectionException;
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
     * @var string regular expression to match the `@return` annotation in doc-blocks
     */
    const RETURN_PATTERN = '/@return\s+(?<name>[\w\\\\]+)\b/';

    /**
     * @type string pattern for parsing an argument type from a ReflectionParameter string
     * @see getArgumentType()
     */
    const ARG_PATTERN = '/.*\[\s*(?:\<required\>|\<optional\>)\s*([^\s]+)/';

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

    // TODO provide access to creator functions
    // TODO update documentation!

    /**
     * Register a new singleton service factory function
     *
     * @param Closure $creator `function (...$service) : T` creates and initializes the T service
     *
     * @throws RuntimeException on attempt to duplicate a factory function
     */
    public function registerService(Closure $creator)
    {
        $this->define($creator, true);
    }

    /**
     * Override an existing singleton service factory function
     *
     * @param Closure $creator `function (...$service) : T` creates and initializes the T service
     */
    public function overrideService(Closure $creator)
    {
        $this->override($creator, true);
    }

    /**
     * Register a new component factory function
     *
     * @param Closure $creator `function (...$service) : T` creates and initializes the T component
     *
     * @throws RuntimeException on attempt to duplicate a factory function
     */
    public function registerComponent(Closure $creator)
    {
        $this->define($creator, false);
    }

    /**
     * Override an existing component factory function
     *
     * @param Closure $creator `function (...$service) : T` creates and initializes the T component
     */
    public function overrideComponent(Closure $creator)
    {
        $this->override($creator, false);
    }

    /**
     * Inserts an existing service object directly into the container
     *
     * @param object $service
     * @param bool   $replace true to replace an existing service
     */
    public function insertService($service, $replace = false)
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
            if (isset($this->creators[$type]) || isset($this->services[$type])) {
                throw new RuntimeException("duplicate service/component registration for: {$type}");
            }
        }

        $this->initialize($service);

        $this->services[$type] = $service;

        $this->is_service[$type] = true;
    }

    /**
     * Replace an existing service object directly in the container
     *
     * @param object $service
     */
    public function replaceService($service)
    {
        $this->insertService($service, true);
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
            // TODO support optional arguments (for optional dependencies)

            try {
                $type = $param->getClass()->getName();
            } catch (ReflectionException $e) {
                $type = $this->getArgumentType($param);

                throw new RuntimeException("undefined service/component: {$type}");
            }

            $args[] = $this->resolve($type);
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
     * @param Closure $creator    factory function
     * @param bool    $is_service true to register as a service factory; false to register as a component factory
     * @param bool    $override   true to override an existing service/component
     *
     * @return void
     *
     * @throws RuntimeException on duplicate registration
     */
    protected function define(Closure $creator, $is_service, $override = false)
    {
        $type = $this->getReturnType($creator);

        if (isset($this->services[$type]) || isset($this->creators[$type])) {
            if ($override) {
                if ($this->is_service[$type] !== $is_service) {
                    throw new RuntimeException("conflicing registration for service/component: {$type}");
                }
            } else {
                throw new RuntimeException("duplicate registration for service/component: {$type}");
            }
        }

        $this->creators[$type] = $creator;

        $this->is_service[$type] = $is_service;
    }

    /**
     * Overrides the service/component factory function for a given type
     *
     * @param Closure $creator    factory function
     * @param bool    $is_service true to register as a service factory; false to register as a component factory
     *
     * @return void
     *
     * @throws RuntimeException on conflicting registration
     */
    protected function override(Closure $creator, $is_service)
    {
        $this->define($creator, $is_service, true);
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
     * @return string class name
     */
    protected function getArgumentType(ReflectionParameter $param)
    {
        preg_match(self::ARG_PATTERN, $param->__toString(), $matches);

        return $matches[1];
    }

    /**
     * Reflect on the return-type of a given Closure, by parsing it's doc-block.
     *
     * @param Closure $func
     *
     * @return string return type (resolved as fully-qualified class-name)
     */
    protected function getReturnType(Closure $func)
    {
        $reflection = new ReflectionFunction($func);

        $comment = $reflection->getDocComment();

        if (preg_match_all(self::RETURN_PATTERN, $comment, $matches) !== 1) {
            throw new RuntimeException("missing @return annotation in doc-block for factory function");
        }

        $name = $matches['name'][0];

        $file = new ReflectionFile($reflection->getFileName());

        $type = substr($file->resolveName($name), 1);

        return $type;
    }
}
