Boxy
====

Open, simple, type-hinted (and type-checked) dependency injection container
for PHP 5.5 and up.

Definitely inspired by [Pimple](http://pimple.sensiolabs.org/) but optimized for full
IDE support, e.g. design-time and run-time type-checking, both on the provider and
consumer side, in modern IDEs such as [Php Storm](https://www.jetbrains.com/phpstorm/) :v:

[![Build Status](https://travis-ci.org/mindplay-dk/boxy.png)](https://travis-ci.org/mindplay-dk/boxy)

[![Code Coverage](https://scrutinizer-ci.com/g/mindplay-dk/boxy/badges/coverage.png)](https://scrutinizer-ci.com/g/mindplay-dk/boxy/)

[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/mindplay-dk/boxy/badges/quality-score.png)](https://scrutinizer-ci.com/g/mindplay-dk/boxy/)


### Basic Usage

Create an instance of the Container:

```PHP
use mindplay\boxy\Container;

$container = new Container();
```

Service objects can be inserted directly (eaglerly) into the container:

```PHP
$container->insertService(new Database());
```

Or you can register factory functions to create services as late as possible:

```PHP
$container->registerService(
    Mapper::class,
    function (Database $db) {
        // type-hinted argument gets resolved and Database instance gets provided

        return new Mapper($db); // return type will be checked
    }
);
```

Consumers can then ask for services by providing a function to be invoked:

```PHP
$container->invoke(function (Database $db, Mapper $mapper) {
    // type-hinted arguments are resolved - the Mapper and Database instance
    // are constructed as needed and provided for the consumer function.
});
```

You can also define optional dependencies, by using optional arguments:

```PHP
$container->invoke(function (Optional $stuff = null) {
    if ($stuff) {
        // ...
    }
});
```

In this example, if class `Optional` has not been registered in the container, the
function will still be invoked, but will be passed a `null` argument - be sure to
check for presence of optional arguments.

May be obvious, but note that, even if all the arguments are optional, and all the
services/components are unavailable, the function will still be invoked.


### Component Factory Usage

You can register factory functions to create components on demand: 

```PHP
$container->registerComponent(
    ArticleFinder::class,
    function (Database $db) {
        return new ArticleFinder($db);
    }
);
```

Consumers can then ask for a new component instance the same way they ask for services:

```PHP
$container->invoke(function (ArticleFinder $finder) {
    // a new ArticleFinder component is created every time you call invoke
});
```

In other words, the syntax is the same; whoever populates the container decides
whether a given type should be registered as a service (same every time) or as
a component (new instance every time.)


### Configuring Services and Components 

You can register additional configuration functions for a service or component:

```PHP
$container->configure(function (Database $db) {
    $db->exec("set names utf8");
});
```

Configuration functions will be executed as late as possible, e.g. the first
time you call `invoke()` and ask for the service or component. (If a service
has already been initialized, the configuration function will execute immediately.)


### Overriding Services

You can override a previously registered service creation function:

```PHP
$container->overrideService(
    Database::class,
    function () {
        return new Database();
    }
);
```

You can override component factory functions as well, at any time; note that
overriding a service creation function is only possible until the service
is initialized - an attempted override after initialization will cause
an exception.


### Packaged Service Providers

You can package service/component definitions for easy reuse by implementing
the `Provider` interface:

```PHP
use mindplay\boxy\Provider;

class ServiceProvider implements Provider
{
    public function register(Container $container)
    {
        $container->registerService(
            Database::class,
            function () {
                return new Database();
            }
        );
    }
}
```

Then register your custom provider with your container instance:

```PHP
$container->register(new ServiceProvider);
```

Note that providers get registered immediately - which means you should
still use `registerService()` rather than `insertService()` if you want
lazy initialization. 
