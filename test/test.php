<?php

use mindplay\boxy\ServiceContainer;

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

// Setup coverage:

if (coverage()) {
    $filter = coverage()->filter();

    $filter->addDirectoryToWhitelist(dirname(__DIR__) . '/src');

    coverage()->start('test');
}

// Tests:

$c = new ServiceContainer();

test(
    'Can register services',
    function () use ($c) {
        $c->addFactory(
            /** @return Database */
            function () {
                return new Database();
            }
        );

        $original_factory_called = false;

        $c->addFactory(
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
                $c->addFactory(
                    /** @return Database */
                    function () {
                        return new Database();
                    }
                );
            }
        );

        $replacement_factory_called = false;

        $c->setFactory(
            /** @return Mapper */
            function (Database $db) use (&$replacement_factory_called) {
                $replacement_factory_called = true;

                return new Mapper($db);
            }
        );

        $c->call(function (Mapper $mapper) {});

        ok($original_factory_called === false, 'original factory function never called');

        ok($replacement_factory_called === true, 'replacement factory function was called');
    }
);

test(
    'can resolve service co-dependencies',
    function () use ($c) {
        $called = false;
        $got_mapper = false;
        $got_db = false;

        $c->call(function (Mapper $mapper) use (&$called, &$got_mapper, &$got_db) {
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
    function () use ($c) {
        $first = null;
        $second = null;

        $c->call(function (Database $db) use (&$first) {
            $first = $db;
        });

        $c->call(function (Database $db) use (&$second) {
            $second = $db;
        });

        eq($first, $second, 'provides the same instance on every call');
    }
);

test(
    'can provide multiple dependencies',
    function () use ($c) {
        $got_db = null;
        $got_mapper = null;

        $c->call(function (Database $db, Mapper $mapper) use (&$got_db, &$got_mapper) {
            $got_db = $db;
            $got_mapper = $mapper;
        });

        ok($got_db instanceof Database, 'provides the first dependency');

        ok($got_mapper instanceof Mapper, 'provides the second dependency');
    }
);

test(
    'throws on various error conditions',
    function () use ($c) {
        expect(
            'RuntimeException',
            'should throw for undefined service (and class not found)',
            function () use ($c) {
                $c->call(function (Foo $foo) {});
            }
        );

        $db_c = new ServiceContainer();

        expect(
            'RuntimeException',
            'should throw for undefined service (for existing class)',
            function() use ($db_c) {
                $db_c->call(function (Database $db) {});
            }
        );

        $db_c = new ServiceContainer();

        $db_c->addFactory(
            /** @return Mapper (this type-hint is wrong!) */
            function () {
                return new Database();
            }
        );

        expect(
            'RuntimeException',
            'should throw when factory function returns the wrong type',
            function () use ($db_c) {
                $db_c->call(function (Mapper $mapper) {});
            }
        );

        $db_c = new ServiceContainer();

        expect(
            'RuntimeException',
            'should throw on missing @return annotation in factory functions',
            function () use ($db_c) {
                $db_c->addFactory(
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
        $c = new ServiceContainer();

        $c->addFactory(
            /**
             * @return Bar
             */
            function () {
                return new Bar();
            }
        );

        $got_bar = false;

        $c->call(function (Bar $bar) use (&$got_bar) {
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
        $c = new ServiceContainer();

        $c->add(new Database());

        $got_db = false;

        $c->call(function (Database $db) use (&$got_db) {
            if ($db instanceof Database) {
                $got_db = true;
            }
        });

        ok($got_db, 'can get directly added service object');

        expect(
            'RuntimeException',
            'should throw on conflicting service object registration',
            function () use ($c) {
                $c->add(new Database());
            }
        );

        expect(
            'InvalidArgumentException',
            'should throw on invalid argument',
            function () use ($c) {
                $c->add('FUDGE');
            }
        );
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
