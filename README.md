Boxy
====

Open, simple, type-hinted service container for singleton service objects.

[![Build Status](https://travis-ci.org/mindplay-dk/boxy.png)](https://travis-ci.org/mindplay-dk/boxy)

[![Code Coverage](https://scrutinizer-ci.com/g/mindplay-dk/boxy/badges/coverage.png)](https://scrutinizer-ci.com/g/mindplay-dk/boxy/)

[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/mindplay-dk/boxy/badges/quality-score.png)](https://scrutinizer-ci.com/g/mindplay-dk/boxy/)


### Usage

Just three public methods:

```PHP
$c = new ServiceContainer();

// providers can directly ("eagerly") inject service objects:

$c->add(new Database());

// or inject them via ("lazy") factory functions:

$c->register(
    /** @return Mapper (this annotation gets parsed and resolved) */
    function (Database $db) {
        // type-hints on arguments are resolved and Database dependency provided

        return new Mapper($db); // return type will be checked
    }
);

// consumers can now ask for services via the call-method:

$c->call(function (Database $db, Mapper $mapper) {
    // type-hints are resolved - the Mapper and Database instance
    // are constructed as needed and injected for you.
}
```

That's all.
