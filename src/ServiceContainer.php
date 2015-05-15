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
 * This class implements a simple, type-hinted container to hold an open set
 * of singleton service objects.
 */
class ServiceContainer
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
     * @var Closure[] map where class-name => `function (&T $service)`
     */
    protected $funcs = array();

    /**
     * @var object[] map where class-name => service object
     */
    protected $services = array();

    /**
     * Register a new service factory function
     *
     * @param Closure $func `function (...$service) : T` creates and initializes the T service
     */
    public function register(Closure $func)
    {
        $type = $this->getReturnType($func);

        if (isset($this->funcs[$type]) || isset($this->services[$type])) {
            throw new RuntimeException("duplicate service registration for: {$type}");
        }

        $this->funcs[$type] = $func;
    }

    /**
     * Registers a new service object directly
     *
     * @param object $object
     */
    public function add($object)
    {
        if (!is_object($object)) {
            $type = gettype($object);

            throw new InvalidArgumentException("unexpected argument type: {$type}");
        }

        $type = get_class($object);

        if (isset($this->funcs[$type]) || isset($this->services[$type])) {
            throw new RuntimeException("duplicate service registration for: {$type}");
        }

        $this->services[$type] = $object;
    }

    /**
     * Call a consumer function, providing all required services as arguments
     *
     * @param Closure $func a function with type-hinted parameters to inject services
     *
     * @return mixed return value from the called function
     */
    public function call(Closure $func)
    {
        $f = new ReflectionFunction($func);

        $args = array();

        foreach ($f->getParameters() as $param) {
            try {
                $type = $param->getClass()->getName();
            } catch (ReflectionException $e) {
                $type = $this->getArgumentType($param);

                throw new RuntimeException("undefined service: {$type}");
            }

            $args[] = $this->getService($type);
        }

        return call_user_func_array($func, $args);
    }

    /**
     * @param string $type service class-name
     *
     * @return object service object
     */
    protected function getService($type)
    {
        if (!isset($this->services[$type])) {
            if (!isset($this->funcs[$type])) {
                throw new RuntimeException("undefined service: {$type}");
            }

            $func = $this->funcs[$type];

            $service = $this->call($func);

            if (!$service instanceof $type) {
                $wrong_type = is_object($service)
                    ? get_class($service)
                    : gettype($service);

                throw new RuntimeException("factory function for {$type} returned wrong type: {$wrong_type}");
            }

            $this->services[$type] = $service;
        }

        return $this->services[$type];
    }

    /**
     * Extract the argument type (class name) from the first argument of a given function
     *
     * Used for diagnostics and error-handling purposes only.
     *
     * @see call()
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
