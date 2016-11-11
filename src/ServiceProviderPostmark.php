<?php

namespace Diamond\Mail;


use Diamond\Mail\Transport\PostmarkTransport;
use GuzzleHttp\Client as HttpClient;
use Illuminate\Mail\TransportManager;
use Illuminate\Support\Arr;
use Illuminate\Support\ServiceProvider;

class ServiceProviderPostmark extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @param TransportManager $transportManager
     * @return void
     */
    public function boot(TransportManager $transportManager)
    {
        $transportManager->extend('postmark', function ($app){
            $config = $app['config']->get('services.postmark', []);

            return new PostmarkTransport(
                $this->getHttpClient($config),
                $config['secret']
            );
        });
    }

    /**
     * Get a fresh Guzzle HTTP client instance.
     *
     * @param  array  $config
     * @return \GuzzleHttp\Client
     */
    protected function getHttpClient($config)
    {
        $guzzleConfig = Arr::get($config, 'guzzle', []);

        return new HttpClient(Arr::add($guzzleConfig, 'connect_timeout', 60));
    }
}