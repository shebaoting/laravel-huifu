<?php

namespace Shebaoting\Huifu\Exceptions;

use Exception;

class HuifuApiException extends Exception
{
    public function __construct(public array $raw)
    {
        $message = $raw['resp_desc'] ?? $raw['msg'] ?? '汇付接口调用失败';
        $code = (int) ($raw['resp_code'] ?? 500);
        parent::__construct($message, $code);
    }
}
