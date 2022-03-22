<?php

namespace KagaDorapeko\Laravel\Aliyun\Oss;

use Illuminate\Support\ServiceProvider;

class AliyunOssServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/aliyun-oss.php', 'aliyun-oss');

        $this->app->singleton(AliyunOssService::class, function () {
            return new AliyunOssService;
        });
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/aliyun-oss.php' => config_path('aliyun-oss.php'),
            ], 'laravel-aliyun-oss-config');
        }
    }
}