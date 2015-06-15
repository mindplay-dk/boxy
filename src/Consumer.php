<?php

namespace mindplay\boxy;

use Closure;

/**
 * This interface must be implemented by classes that wish to consume
 * dependencies via {@link Container::provide()}
 */
interface Consumer
{
    /**
     * @return Closure consumer function
     */
    public function getInjector();
}
