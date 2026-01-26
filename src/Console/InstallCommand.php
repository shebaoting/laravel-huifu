<?php

namespace Shebaoting\Huifu\Console;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'huifu:install';
    protected $description = '安装并初始化 shebaoting/laravel-huifu';

    public function handle()
    {
        $this->call('vendor:publish', ['--tag' => 'huifu-config']);
        $this->info('汇付配置文件已发布。请在 .env 中设置相关参数。');
    }
}
