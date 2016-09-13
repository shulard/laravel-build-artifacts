<?php

namespace Shulard\BuildArtifacts;

use Illuminate\Support\ServiceProvider;

class BuildArtifactsServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application events.
     *
     * @return void
     *
     * @codeCoverageIgnore
     */
    public function boot()
    {
        //
    }

    /**
     * Register the service provider.
     *
     * @return void
     *
     * @codeCoverageIgnore
     */
    public function register()
    {
        $this->commands(
            new Console\Gitlab\Download
        );
    }
}
