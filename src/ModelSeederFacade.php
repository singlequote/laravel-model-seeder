<?php

namespace SingleQuote\ModelSeeder;

use Illuminate\Support\Facades\Facade;

class ModelSeederFacade extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'ModelSeeder';
    }
}
