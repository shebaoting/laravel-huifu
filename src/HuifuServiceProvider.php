<?php

namespace Shebaoting\Huifu;

use Illuminate\Support\ServiceProvider;
use BsPaySdk\core\BsPay;

class HuifuServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/huifu.php', 'huifu');
        $this->app->singleton(\Shebaoting\Huifu\Services\HuifuService::class, fn() => new \Shebaoting\Huifu\Services\HuifuService());
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../config/huifu.php' => config_path('huifu.php')], 'huifu-config');
            $this->commands([\Shebaoting\Huifu\Console\InstallCommand::class]);
        }
        $this->initializeSdk();
    }

    private function initializeSdk(): void
    {
        // 1. 恢复汇付官方标准版本号，避免请求被拦截
        if (!defined('SDK_VERSION')) define('SDK_VERSION', 'v3.0.0');
        if (!defined('PROD_MODE'))   define('PROD_MODE', true);
        if (!defined('DEBUG'))       define('DEBUG', config('huifu.debug'));
        if (!defined('LOG'))         define('LOG', storage_path('logs/' . config('huifu.log_path')));

        // 2. 引入 SDK init
        require_once __DIR__ . '/Sdk/BsPaySdk/init.php';

        // 3. 内存初始化，不产生 config.json 文件
        $config = [
            'sys_id'                => config('huifu.sys_id'),
            'product_id'            => config('huifu.product_id'),
            'rsa_merch_private_key' => config('huifu.rsa_merch_private_key'),
            'rsa_huifu_public_key'  => config('huifu.rsa_huifu_public_key'),
            'huifu_id'              => config('huifu.huifu_id'),
        ];

        BsPay::init($config, true, 'default');
    }
}
