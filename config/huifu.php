<?php

return [
    'sys_id'                => env('HUIFU_SYS_ID', ''),
    'product_id'            => env('HUIFU_PRODUCT_ID', 'PAYUN'),
    'huifu_id'              => env('HUIFU_MCH_ID', ''),
    'rsa_merch_private_key' => env('HUIFU_MERCH_PRIVATE_KEY', ''),
    'rsa_huifu_public_key'  => env('HUIFU_PUBLIC_KEY', ''),
    'debug'                 => env('HUIFU_DEBUG', false),
    'log_path'              => 'huifu',

    // 默认的小程序配置，也可通过 services.php 读取
    'sub_appid'             => env('WECHAT_MINI_APP_ID', ''),
];
