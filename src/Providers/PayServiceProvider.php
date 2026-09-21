<?php

namespace NoriaLabs\Pay\Providers;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;
use NoriaLabs\Pay\Pay;
use NoriaLabs\Pay\WebhookVerifier;

class PayServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/noria-pay.php', 'noria-pay');

        $this->app->singleton(Pay::class, function (): Pay {
            /** @var array{key: string, url: string, timeout: int, retries: int} $config */
            $config = $this->app->make('config')->get('noria-pay');

            return new Pay(
                $this->app->make(Factory::class),
                $config['key'],
                $config['url'],
                $config['timeout'],
                $config['retries'],
            );
        });

        $this->app->singleton(WebhookVerifier::class, function (): WebhookVerifier {
            /** @var array{webhook_secret: string, webhook_tolerance: int} $config */
            $config = $this->app->make('config')->get('noria-pay');

            return new WebhookVerifier($config['webhook_secret'], $config['webhook_tolerance']);
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../../config/noria-pay.php' => $this->app->configPath('noria-pay.php'),
        ], 'noria-pay-config');
    }

    /**
     * @return list<string>
     */
    public function provides(): array
    {
        return [Pay::class, WebhookVerifier::class];
    }
}
