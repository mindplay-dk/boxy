<?php

use mindplay\boxy\Container;
use mindplay\boxy\Provider;

use foo\Bar;

require __DIR__ . '/header.php';
require __DIR__ . '/case.php';

header('Content-type: text/plain');

// Fixtures:

class Database
{
}

class Mapper
{
    /**
     * @var Database
     */
    public $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }
}

class UserFactory
{
    /**
     * @var Mapper
     */
    public $mapper;

    public function __construct(Mapper $mapper)
    {
        $this->mapper = $mapper;
    }
}

class ServiceProvider implements Provider
{
    public function register(Container $container)
    {
        $container->registerService(
            /** @return Database */
            function () {
                return new Database();
            }
        );

        $container->registerService(
            /** @return Mapper */
            function (Database $db) {
                return new Mapper($db);
            }
        );
    }
}

class Counter
{
    public $count = 0;
}

// Setup coverage:

if (coverage()) {
    $filter = coverage()->filter();

    $filter->addDirectoryToWhitelist(dirname(__DIR__) . '/src');

    coverage()->start('test');
}

// Tests:

test(
    'Can register services',
    function () {
        $c = new Container();

        $c->registerService(
            /** @return Database */
            function () {
                return new Database();
            }
        );

        $original_factory_called = false;

        $c->registerService(
            /** @return Mapper */
            function (Database $db) use (&$original_factory_called) {
                $original_factory_called = true;

                return new Mapper($db);
            }
        );

        ok(true, 'calls to register() succeeded');

        expect(
            'RuntimeException',
            'should throw on duplicate registration',
            function () use ($c) {
                $c->registerService(
                    /** @return Database */
                    function () {
                        return new Database();
                    }
                );
            }
        );

        $replacement_factory_called = false;

        $c->overrideService(
            /** @return Mapper */
            function (Database $db) use (&$replacement_factory_called) {
                $replacement_factory_called = true;

                return new Mapper($db);
            }
        );

        $c->invoke(function (Mapper $mapper) {});

        ok($original_factory_called === false, 'original factory function never called');

        ok($replacement_factory_called === true, 'replacement factory function was called');
    }
);

test(
    'Can register component factory functions',
    function () {
        $c = new Container();

        $c->register(new ServiceProvider);

        $c->registerComponent(
            /** @return UserFactory */
            function (Mapper $mapper) {
                return new UserFactory($mapper);
            }
        );

        ok(true, 'call to addFactory() succeeded');

        $original_factory_called = false;

        expect(
            'RuntimeException',
            'should throw on duplicate registration',
            function () use ($c) {
                $c->registerComponent(
                    /** @return UserFactory */
                    function (Mapper $mapper) {
                        return new UserFactory($mapper);
                    }
                );
            }
        );

        $replacement_factory_called = false;

        $c->overrideComponent(
            /** @return UserFactory */
            function (Mapper $mapper) use (&$replacement_factory_called) {
                $replacement_factory_called = true;

                return new UserFactory($mapper);
            }
        );

        $c->invoke(function (UserFactory $users) {});

        ok($original_factory_called === false, 'original factory function never called');

        ok($replacement_factory_called === true, 'replacement factory function was called');

        expect(
            'RuntimeException',
            'should throw on conflicting registration',
            function () use ($c) {
                $c->overrideComponent(
                    /** @return Database */
                    function () {
                        return new Database(); // already registered as a service
                    }
                );
            }
        );

        $first_result = null;
        $second_result = null;

        $c->invoke(function (UserFactory $users) use (&$first_result) {
            $first_result = $users;
        });

        $c->invoke(function (UserFactory $users) use (&$second_result) {
            $second_result = $users;
        });

        ok($first_result !== $second_result, 'provides a unique component instance on subsequent calls');
    }
);

test(
    'can resolve service co-dependencies',
    function () {
        $c = new Container();

        $c->register(new ServiceProvider);

        $called = false;
        $got_mapper = false;
        $got_db = false;

        $c->invoke(function (Mapper $mapper) use (&$called, &$got_mapper, &$got_db) {
            $called = true;

            if ($mapper instanceof Mapper) {
                $got_mapper = true;
            }

            if ($mapper->db instanceof Database) {
                $got_db = true;
            }
        });

        ok($called, 'callback invoked');

        ok($got_mapper, 'dependency provided');

        ok($got_db, 'co-dependency provided and injected');
    }
);

test(
    'calls factory functions only once',
    function () {
        $c = new Container();

        $c->register(new ServiceProvider);

        $first = null;
        $second = null;

        $c->invoke(function (Database $db) use (&$first) {
            $first = $db;
        });

        $c->invoke(function (Database $db) use (&$second) {
            $second = $db;
        });

        eq($first, $second, 'provides the same instance on every call');
    }
);

test(
    'can provide multiple dependencies',
    function () {
        $c = new Container();

        $c->register(new ServiceProvider);

        $got_db = null;
        $got_mapper = null;

        $c->invoke(function (Database $db, Mapper $mapper) use (&$got_db, &$got_mapper) {
            $got_db = $db;
            $got_mapper = $mapper;
        });

        ok($got_db instanceof Database, 'provides the first dependency');

        ok($got_mapper instanceof Mapper, 'provides the second dependency');
    }
);

test(
    'throws on various error conditions',
    function () {
        $c = new Container();

        $c->register(new ServiceProvider);

        expect(
            'RuntimeException',
            'should throw for undefined service (and class not found)',
            function () use ($c) {
                $c->invoke(function (Foo $foo) {});
            }
        );

        $db_c = new Container();

        expect(
            'RuntimeException',
            'should throw for undefined service (for existing class)',
            function() use ($db_c) {
                $db_c->invoke(function (Database $db) {});
            }
        );

        $db_c = new Container();

        $db_c->registerService(
            /** @return Mapper (this type-hint is wrong!) */
            function () {
                return new Database();
            }
        );

        expect(
            'RuntimeException',
            'should throw when factory function returns the wrong type',
            function () use ($db_c) {
                $db_c->invoke(function (Mapper $mapper) {});
            }
        );

        $db_c = new Container();

        expect(
            'RuntimeException',
            'should throw on missing @return annotation in factory functions',
            function () use ($db_c) {
                $db_c->registerService(
                    function () {
                        return new Database();
                    }
                );
            }
        );

        expect(
            'RuntimeException',
            'should throw on missing type-hint in consumer functions',
            function () use ($c) {
                $c->invoke(function ($foo) {});
            }
        );
    }
);

test(
    'can handle namespace aliases',
    function () {
        $c = new Container();

        $c->register(new ServiceProvider);

        $c->registerService(
            /**
             * @return Bar
             */
            function () {
                return new Bar();
            }
        );

        $got_bar = false;

        $c->invoke(function (Bar $bar) use (&$got_bar) {
            if ($bar instanceof Bar) {
                $got_bar = true;
            }
        });

        ok($got_bar, 'correctly resolves local namespace alias Bar as full-qualified name foo\\Bar');
    }
);

test(
    'can add and replace service objects directly',
    function () {
        $c = new Container();

        $c->insertService(new Database());

        $got_db = null;

        $c->invoke(function (Database $db) use (&$got_db) {
            $got_db = $db;
        });

        ok($got_db instanceof Database, 'can get directly added service object');

        expect(
            'RuntimeException',
            'should throw on conflicting service object registration',
            function () use ($c) {
                $c->insertService(new Database());
            }
        );

        expect(
            'InvalidArgumentException',
            'should throw on invalid argument to insertService()',
            function () use ($c) {
                $c->insertService('FUDGE');
            }
        );

        expect(
            'InvalidArgumentException',
            'should throw on invalid argument to replaceService()',
            function () use ($c) {
                $c->replaceService('FUDGE');
            }
        );

        $c->replaceService(new Database());

        $new_db = null;

        $c->invoke(function (Database $db) use (&$new_db) {
            $new_db = $db;
        });

        ok($new_db !== $got_db, 'can directly replace service object');

        $c->registerComponent(
            /** @return Mapper */
            function (Database $db) {
                return new Mapper($db);
            }
        );

        expect(
            'RuntimeException',
            'should throw on conflicting component registration',
            function () use ($c) {
                $c->replaceService(new Mapper(new Database()));
            }
        );
    }
);

test(
    'Prevents service updates after initialization',
    function () {
        $c = new Container();

        $c->register(new ServiceProvider);

        $c->invoke(function (Database $db) {});

        expect(
            'RuntimeException',
            'should throw on attempted service override after initialization',
            function () use ($c) {
                $c->overrideService(
                    /** @return Database */
                    function () {
                        return new Database();
                    }
                );
            }
        );

        $c->registerComponent(
            /** @return Counter */
            function () {
                return new Counter();
            }
        );

        $c->invoke(function (Counter $counter) {});

        $c->overrideComponent(
            /** @return Counter */
            function () {
                return new Counter();
            }
        );

        ok(true, 'should not throw on component override');
    }
);

test(
    'Can configure services',
    function () {
        $c = new Container();

        $c->registerService(
            /** @return Counter */
            function () {
                return new Counter();
            }
        );

        $c->configure(function (Counter $counter) {
            $counter->count += 1;
        });

        $count = 0;

        $c->invoke(function (Counter $counter) use (&$count) {
            $count = $counter->count;
        });

        eq($count, 1, 'can configure service before initialization');

        $c->configure(function (Counter $counter) {
            $counter->count += 1;
        });

        $c->invoke(function (Counter $counter) use (&$count) {
            $count = $counter->count;
        });

        eq($count, 2, 'can configure service after initialization');

        expect(
            'InvalidArgumentException',
            'should throw on function with more than one argument',
            function () use ($c) {
                $c->configure(function (Counter $counter, Database $db) {});
            }
        );
    }
);

test(
    'Can configure components',
    function () {
        $c = new Container();

        $c->registerComponent(
            /** @return Counter */
            function () {
                return new Counter();
            }
        );

        $c->configure(function (Counter $counter) {
            $counter->count += 1;
        });

        $count = 0;

        $c->invoke(function (Counter $counter) use (&$count) {
            $count = $counter->count;
        });

        eq($count, 1, 'can configure component before initialization');

        $c->configure(function (Counter $counter) {
            $counter->count += 1;
        });

        $c->invoke(function (Counter $counter) use (&$count) {
            $count = $counter->count;
        });

        eq($count, 2, 'can configure component after initialization');
    }
);

test(
    'Can handle optional dependencies',
    function () {
        $c = new Container();

        $got_null = false;

        $c->invoke(function (Counter $counter = null) use (&$got_null) {
            if ($counter === null) {
                $got_null = true;
            }
        });

        ok($got_null, 'provides null argument for optional, undefined dependency');
    }
);

// Report coverage:

if (coverage()) {
    coverage()->stop();

    $report = new PHP_CodeCoverage_Report_Text(10, 90, false, false);

    echo $report->process(coverage(), false);

    // output code coverage report for integration with CI tools:

    $report = new PHP_CodeCoverage_Report_Clover();

    $report->process(coverage(), __DIR__ . '/build/logs/clover.xml');
}

exit(status());
