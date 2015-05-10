<?php

require dirname(__DIR__) . '/vendor/autoload.php';

// https://gist.github.com/mindplay-dk/4260582

/**
 * @param string   $name     test description
 * @param callable $function test implementation
 */
function test($name, $function)
{
    echo "\n=== $name ===\n\n";

    try {
        call_user_func($function);
    } catch (Exception $e) {
        ok(false, "UNEXPECTED EXCEPTION", $e);
    }
}

/**
 * @param bool   $result result of assertion
 * @param string $why    description of assertion
 * @param mixed  $value  optional value (displays on failure)
 */
function ok($result, $why = null, $value = null)
{
    if ($result === true) {
        echo "- PASS: " . ($why === null ? 'OK' : $why) . ($value === null ? '' : ' (' . format($value) . ')') . "\n";
    } else {
        echo "# FAIL: " . ($why === null ? 'ERROR' : $why) . ($value === null ? '' : ' - ' . format($value,
                    true)) . "\n";
        status(false);
    }
}

/**
 * @param mixed  $value    value
 * @param mixed  $expected expected value
 * @param string $why      description of assertion
 */
function eq($value, $expected, $why = null)
{
    $result = $value === $expected;

    $info = $result
        ? format($value)
        : "expected: " . format($expected, true) . ", got: " . format($value, true);

    ok($result, ($why === null ? $info : "$why ($info)"));
}

/**
 * @param string   $exception_type Exception type name
 * @param string   $why            description of assertion
 * @param callable $function       function expected to throw
 */
function expect($exception_type, $why, $function)
{
    try {
        call_user_func($function);
    } catch (Exception $e) {
        if ($e instanceof $exception_type) {
            ok(true, $why, $e);

            return;
        } else {
            $actual_type = get_class($e);
            ok(false, "$why (expected $exception_type but $actual_type was thrown)");

            return;
        }
    }

    ok(false, "$why (expected exception $exception_type was NOT thrown)");
}

/**
 * @param mixed $value
 * @param bool  $verbose
 *
 * @return string
 */
function format($value, $verbose = false)
{
    if ($value instanceof Exception) {
        return get_class($value) . ": \"" . $value->getMessage() . "\"";
    }

    if (!$verbose && is_array($value)) {
        return 'array[' . count($value) . ']';
    }

    if (is_bool($value)) {
        return $value ? 'TRUE' : 'FALSE';
    }

    if (is_object($value) && !$verbose) {
        return get_class($value);
    }

    return print_r($value, true);
}

/**
 * @param bool|null $status test status
 *
 * @return int number of failures
 */
function status($status = null)
{
    static $failures = 0;

    if ($status === false) {
        $failures += 1;
    }

    return $failures;
}

/**
 * @return PHP_CodeCoverage|null code coverage service, if available
 */
function coverage()
{
    static $coverage = null;

    if ($coverage === false) {
        return null; // code coverage unavailable
    }

    if ($coverage === null) {
        try {
            $coverage = new PHP_CodeCoverage;
        } catch (PHP_CodeCoverage_Exception $e) {
            echo "# Notice: no code coverage run-time available\n";
            $coverage = false;

            return null;
        }
    }

    return $coverage;
}

/**
 * Invoke a protected or private method (by means of reflection)
 *
 * @param object $object      the object on which to invoke a method
 * @param string $method_name the name of the method
 * @param array  $arguments   arguments to pass to the function
 *
 * @return mixed the return value from the function call
 */
function invoke($object, $method_name, $arguments = array())
{
    $class = new ReflectionClass(get_class($object));

    $method = $class->getMethod($method_name);

    $method->setAccessible(true);

    return $method->invokeArgs($object, $arguments);
}

/**
 * Inspect a protected or private property (by means of reflection)
 *
 * @param object $object        the object from which to retrieve a property
 * @param string $property_name the property name
 *
 * @return mixed the property value
 */
function inspect($object, $property_name)
{
    $property = new ReflectionProperty(get_class($object), $property_name);

    $property->setAccessible(true);

    return $property->getValue($object);
}
