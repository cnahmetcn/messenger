<?php

namespace RTippin\Messenger\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class InstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'messenger:install';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install all of the Messenger resources';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle(): void
    {
        $this->info('Publishing Messenger Configuration...');

        $this->callSilent('vendor:publish', [
            '--tag' => 'messenger.config',
        ]);

        $this->info('Publishing Messenger Service Provider...');

        $this->callSilent('vendor:publish', [
            '--tag' => 'messenger.provider',
        ]);

        $this->registerMessengerServiceProvider();

        $this->info('Messenger scaffolding successfully installed!');
    }

    /**
     * Register the Telescope service provider in the application configuration file.
     */
    private function registerMessengerServiceProvider(): void
    {
        $namespace = Str::replaceLast('\\', '', $this->laravel->getNamespace());

        $appConfig = file_get_contents(config_path('app.php'));

        if (Str::contains($appConfig, $namespace.'\\Providers\\MessengerServiceProvider::class')) {
            return;
        }

        $lineEndingCount = [
            "\r\n" => substr_count($appConfig, "\r\n"),
            "\r" => substr_count($appConfig, "\r"),
            "\n" => substr_count($appConfig, "\n"),
        ];

        $eol = array_keys($lineEndingCount, max($lineEndingCount))[0];

        file_put_contents(config_path('app.php'), str_replace(
            "{$namespace}\\Providers\EventServiceProvider::class,".$eol,
            "{$namespace}\\Providers\EventServiceProvider::class,".$eol."        {$namespace}\Providers\MessengerServiceProvider::class,".$eol,
            $appConfig
        ));

        file_put_contents(app_path('Providers/MessengerServiceProvider.php'), str_replace(
            "namespace App\Providers;",
            "namespace {$namespace}\Providers;",
            file_get_contents(app_path('Providers/MessengerServiceProvider.php'))
        ));
    }
}
