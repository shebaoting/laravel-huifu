<?php

namespace Shebaoting\Huifu\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array miniAppPay(string $orderNo, float|string $amount, string $desc, string $openid, array $extra = [])
 * @method static array splitByRatio(string $orderNo, string $orderDate, float $totalAmount, array $splits)
 * @method static array splitByAmount(string $orderNo, string $orderDate, array $amounts)
 * @method static float getBalance(string $huifuId = null)
 * @method static string uploadImage(string $localPath, string $fileType = 'F55')
 * @method static array request(string $requestClass, array $params = [])
 * @method static mixed handleCallback(callable $callback)
 *
 * @see \Shebaoting\Huifu\Services\HuifuService
 */
class Huifu extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Shebaoting\Huifu\Services\HuifuService::class;
    }
}
