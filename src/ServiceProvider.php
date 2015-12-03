<?php

namespace Sofa\Searchable;

use Illuminate\Support\ServiceProvider as BaseProvider;

class ServiceProvider extends BaseProvider
{
    /**
     * Boot the searchable extension.
     *
     * @param  \Sofa\Eloquence\Searchable $searchable
     * @return void
     */
    public function boot(Searchable $searchable)
    {
        $searchable->boot();
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind(Contracts\Parser::class, function () {
            $weight   = $app['config']->get('searchable.weight', 1);
            $wildcard = $app['config']->get('searchable.wildcard', '*');

            return new Parser($weight, $wildcard);
        });
    }
}
