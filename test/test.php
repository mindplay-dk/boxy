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
        $container->addService(
            /** @return Database */
            function () {
                return new Database();
            }
        );

        $container->addService(
            /** @return Mapper */
            function (Database $db) {
                return new Mapper($db);
            }
        );
    }
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

        $c->addService(
            /** @return Database */
            function () {
                return new Database();
            }
        );

        $original_factory_called = false;

        $c->addService(
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
                $c->addService(
                    /** @return Database */
                    function () {
                        return new Database();
                    }
                );
            }
        );

        $replacement_factory_called = false;

        $c->setService(
            /** @return Mapper */
            function (Database $db) use (&$replacement_factory_called) {
                $replacement_factory_called = true;

                return new Mapper($db);
            }
        );

        $c->provide(function (Mapper $mapper) {});

        ok($original_factory_called === false, 'original factory function never called');

        ok($replacement_factory_called === true, 'replacement factory function was called');
    }
);

test(
    'Can register component factory functions',
    function () {
        $c = new Container();

        $c->register(new ServiceProvider);

        $c->addFactory(
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
                $c->addFactory(
                    /** @return UserFactory */
                    function (Mapper $mapper) {
                        return new UserFactory($mapper);
                    }
                );
            }
        );

        $replacement_factory_called = false;

        $c->setFactory(
            /** @return UserFactory */
            function (Mapper $mapper) use (&$replacement_factory_called) {
                $replacement_factory_called = true;

                return new UserFactory($mapper);
            }
        );

        $c->provide(function (UserFactory $users) {});

        ok($original_factory_called === false, 'original factory function never called');

        ok($replacement_factory_called === true, 'replacement factory function was called');

        expect(
            'RuntimeException',
            'should throw on conflicting registration',
            function () use ($c) {
                $c->setFactory(
                    /** @return Database */
                    function () {
                        return new Database(); // already registered as a service
                    }
                );
            }
        );
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

        $c->provide(function (Mapper $mapper) use (&$called, &$got_mapper, &$got_db) {
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

        $c->provide(function (Database $db) use (&$first) {
            $first = $db;
        });

        $c->provide(function (Database $db) use (&$second) {
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

        $c->provide(function (Database $db, Mapper $mapper) use (&$got_db, &$got_mapper) {
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
                $c->provide(function (Foo $foo) {});
            }
        );

        $db_c = new Container();

        expect(
            'RuntimeException',
            'should throw for undefined service (for existing class)',
            function() use ($db_c) {
                $db_c->provide(function (Database $db) {});
            }
        );

        $db_c = new Container();

        $db_c->addService(
            /** @return Mapper (this type-hint is wrong!) */
            function () {
                return new Database();
            }
        );

        expect(
            'RuntimeException',
            'should throw when factory function returns the wrong type',
            function () use ($db_c) {
                $db_c->provide(function (Mapper $mapper) {});
            }
        );

        $db_c = new Container();

        expect(
            'RuntimeException',
            'should throw on missing @return annotation in factory functions',
            function () use ($db_c) {
                $db_c->addService(
                    function () {
                        return new Database();
                    }
                );
            }
        );
    }
);

test(
    'can handle namespace aliases',
    function () {
        $c = new Container();

        $c->register(new ServiceProvider);

        $c->addService(
            /**
             * @return Bar
             */
            function () {
                return new Bar();
            }
        );

        $got_bar = false;

        $c->provide(function (Bar $bar) use (&$got_bar) {
            if ($bar instanceof Bar) {
                $got_bar = true;
            }
        });

        ok($got_bar, 'correctly resolves local namespace alias Bar as full-qualified name foo\\Bar');
    }
);

test(
    'can add service objects directly',
    function () {
        $c = new Container();

        $c->add(new Database());

        $got_db = null;

        $c->provide(function (Database $db) use (&$got_db) {
            $got_db = $db;
        });

        ok($got_db instanceof Database, 'can get directly added service object');

        expect(
            'RuntimeException',
            'should throw on conflicting service object registration',
            function () use ($c) {
                $c->add(new Database());
            }
        );

        expect(
            'InvalidArgumentException',
            'should throw on invalid argument to add()',
            function () use ($c) {
                $c->add('FUDGE');
            }
        );

        expect(
            'InvalidArgumentException',
            'should throw on invalid argument to replace()',
            function () use ($c) {
                $c->replace('FUDGE');
            }
        );

        $c->replace(new Database());

        $new_db = null;

        $c->provide(function (Database $db) use (&$new_db) {
            $new_db = $db;
        });

        ok($new_db !== $got_db, 'can directly replace service object');
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
