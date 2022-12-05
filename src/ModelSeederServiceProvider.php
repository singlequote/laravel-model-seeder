<?php
namespace SingleQuote\ModelSeeder;

use Illuminate\Support\ServiceProvider;
use SingleQuote\ModelSeeder\Commands\FindAndAddLanguageKeysCommand;

class ModelSeederServiceProvider extends ServiceProvider
{

    /**
     * Commands.
     *
     * @var array
     */
    protected $commands = [
        FindAndAddLanguageKeysCommand::class,
    ];

    
    /**
     * Bootstrap the application services.
     */
    public function boot()
    {
        $this->publishes([
            __DIR__.'/config/config.php' => config_path('locale-finder.php')
        ], 'locale-finder');
    }

    /**
     * Register the application services.
     */
    public function register()
    {
        //config
        $this->mergeConfigFrom(
            __DIR__ . '/config/config.php',
            'locale-finder'
        );

        app()->config["filesystems.disks.ModelSeeder"] = [
            'driver' => 'local',
            'root' => config('locale-finder.paths.lang_folder'),
        ];
                
        $this->commands($this->commands);
    }
}
