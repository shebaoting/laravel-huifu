<?php

declare(strict_types=1);

namespace Shebaoting\Huifu\Services;

use BsPaySdk\core\BsPayClient;
use BsPaySdk\core\BsPayTools;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Shebaoting\Huifu\Exceptions\HuifuApiException;

readonly class HuifuService
{
    /**
     * 1. 小程序下单 (傻瓜式)
     */
    public function miniAppPay(string $orderNo, float|string $amount, string $desc, string $openid, array $extra = []): array
    {
        // 构造微信参数，注意 key 为 openid
        $wxData = [
            'sub_appid' => config('huifu.sub_appid') ?: config('services.wechat.mini_app_id'),
            'openid'    => $openid,
        ];

        return $this->request(\BsPaySdk\request\V2TradePaymentJspayRequest::class, array_merge([
            'req_seq_id'      => $orderNo,
            'trans_amt'       => $amount,
            'goods_desc'      => $desc,
            'trade_type'      => 'T_MINIAPP',
            // 默认参数，可被 $extra 覆盖
            'delay_acct_flag' => 'Y',
            'notify_url'      => route('api.v1.weapp.payment.huifu_notify'),
            'wx_data'         => json_encode($wxData),
        ], $extra));
    }

    /**
     * 2. 按比例分账
     */
    public function splitByRatio(string $orderNo, string $orderDate, float $totalAmount, array $splits): array
    {
        $acctInfos = [];
        foreach ($splits as $huifuId => $ratio) {
            $acctInfos[] = [
                'huifu_id' => $huifuId,
                'div_amt'  => number_format(round($totalAmount * $ratio, 2), 2, '.', ''),
            ];
        }
        return $this->confirmAllocation($orderNo, $orderDate, $totalAmount, $acctInfos);
    }

    /**
     * 3. 按固定金额分账
     */
    public function splitByAmount(string $orderNo, string $orderDate, array $amounts): array
    {
        $acctInfos = [];
        foreach ($amounts as $huifuId => $amt) {
            $acctInfos[] = ['huifu_id' => $huifuId, 'div_amt' => $amt];
        }
        return $this->confirmAllocation($orderNo, $orderDate, (float)array_sum($amounts), $acctInfos);
    }

    /**
     * 4. 确认分账 (底层)
     */
    public function confirmAllocation(string $orderNo, string $orderDate, float $totalAmt, array $acctInfos): array
    {
        return $this->request(\BsPaySdk\request\V2TradePaymentDelaytransConfirmRequest::class, [
            'org_req_seq_id'   => $orderNo,
            'org_req_date'     => $orderDate,
            'trans_amt'        => $totalAmt,
            'acct_split_bunch' => json_encode(['acct_infos' => $acctInfos]),
        ]);
    }

    /**
     * 5. 余额查询
     */
    public function getBalance(string $huifuId = null): float
    {
        $res = $this->request(\BsPaySdk\request\V2TradeAcctpaymentBalanceQueryRequest::class, [
            'huifu_id' => $huifuId ?? config('huifu.huifu_id')
        ]);
        return (float) ($res['acct_bal'] ?? 0);
    }

    /**
     * 6. 图片上传
     */
    public function uploadImage(string $localPath, string $fileType = 'F55'): string
    {
        $res = $this->request(\BsPaySdk\request\V2SupplementaryPictureRequest::class, [
            'file_type' => $fileType,
            'file'      => new \CURLFile($localPath),
        ]);
        return $res['file_id'] ?? '';
    }

    /**
     * 7. 自动纠错的万能请求工厂
     */
    public function request(string $requestClass, array $params = []): array
    {
        if (!class_exists($requestClass)) {
            throw new HuifuApiException("SDK Request class not found: {$requestClass}");
        }

        $request = new $requestClass();

        // 1. 自动填充公共参数
        if (method_exists($request, 'setReqDate') && empty($params['req_date'])) {
            $request->setReqDate(date('Ymd'));
        }
        if (method_exists($request, 'setReqSeqId') && empty($params['req_seq_id'])) {
            $request->setReqSeqId(date('YmdHis') . Str::random(8));
        }
        if (method_exists($request, 'setHuifuId') && empty($params['huifu_id'])) {
            $request->setHuifuId(config('huifu.huifu_id'));
        }

        // 2. 格式化金额
        $this->formatAmounts($params);

        // 3. 动态设值
        $extendInfos = [];
        foreach ($params as $key => $value) {
            // 将下划线转驼峰 (trans_amt -> setTransAmt)
            $method = 'set' . Str::studly($key);

            if (method_exists($request, $method)) {
                $request->$method($value);
            } else {
                // 如果没有对应 Setter，则视为扩展参数
                $extendInfos[$key] = $value;
            }
        }

        // 4. 注入扩展参数
        if (!empty($extendInfos)) {
            $request->setExtendInfo($extendInfos);
        }

        return $this->exec($request);
    }

    /**
     * 8. 执行并解析
     */
    /**
     * 8. 执行并解析
     */
    public function exec(object $request): array
    {
        $seqId = method_exists($request, 'getReqSeqId') ? $request->getReqSeqId() : 'N/A';
        Log::info("[Huifu] Request Start: {$seqId}", ['class' => get_class($request)]);

        try {
            $client = new BsPayClient();
            $result = $client->postRequest($request);

            // 检查网络错误或SDK内部错误
            if (!$result || (method_exists($result, 'isError') && $result->isError())) {
                $errorInfo = method_exists($result, 'getErrorInfo') ? $result->getErrorInfo() : ['msg' => 'Unknown Error'];

                // 【修复核心】：兼容 getErrorInfo 返回字符串的情况
                if (is_string($errorInfo)) {
                    $errorInfo = ['msg' => $errorInfo, 'resp_desc' => $errorInfo];
                }

                Log::error('[Huifu] API Error', ['req_id' => $seqId, 'error' => $errorInfo]);
                throw new HuifuApiException($errorInfo);
            }

            // 获取响应数据
            $rawResponse = method_exists($result, 'getRspDatas') ? $result->getRspDatas() : [];
            $data = $rawResponse['data'] ?? $rawResponse; // 兼容不同接口返回结构

            // 检查业务逻辑错误 (resp_code 非 00000000)
            if (isset($data['resp_code']) && $data['resp_code'] !== '00000000') {
                Log::error('[Huifu] Business Error', ['req_id' => $seqId, 'resp' => $data]);
                throw new HuifuApiException($data);
            }

            return $data;
        } catch (\Exception $e) {
            // 如果捕获的是我们自己抛出的异常，直接向上抛
            if ($e instanceof HuifuApiException) {
                throw $e;
            }

            Log::error('[Huifu] Exec Exception: ' . $e->getMessage());
            // 包装其他异常
            throw new HuifuApiException(['msg' => $e->getMessage(), 'resp_desc' => $e->getMessage()]);
        }
    }

    /**
     * 9. 极简退款
     */
    public function refund(string $orgOrderNo, string $orgOrderDate, float|string $amount, string $reason = '用户申请退款'): array
    {
        return $this->request(\BsPaySdk\request\V2TradePaymentScanpayRefundRequest::class, [
            'org_req_seq_id' => $orgOrderNo,
            'org_req_date'   => $orgOrderDate,
            'ord_amt'        => $amount,
            'remark'         => $reason,
        ]);
    }

    /**
     * 10. 订单查询
     */
    public function queryOrder(string $orderNo, string $orderDate): array
    {
        return $this->request(\BsPaySdk\request\V3TradePaymentScanpayQueryRequest::class, [
            'org_req_seq_id' => $orderNo,
            'org_req_date'   => $orderDate,
        ]);
    }

    /**
     * 回调处理助手 (包含混合验签)
     * * @param callable $callback 业务闭包，接收解码后的数据数组，返回 bool
     * @return string 直接返回 'success' 或 'fail' 字符串给汇付
     */
    public function handleCallback(callable $callback): string
    {
        $request = request();
        $rawContent = $request->getContent(); // 获取最原始的内容

        // 解析 JSON
        $json = json_decode($rawContent, true);
        if (!$json) {
            // 尝试从 POST 字段获取 (兼容 application/x-www-form-urlencoded)
            $json = $request->all();
        }

        $dataStr = $json['resp_data'] ?? ''; // 原始字符串
        $sign = $json['sign'] ?? '';

        // 验签
        if (!$dataStr || !$sign || !$this->verifySign($dataStr, $sign)) {
            Log::error('[Huifu] Callback Signature Invalid');
            return 'fail';
        }

        // 验签通过，执行业务回调
        // 这里需要再次 json_decode resp_data，因为它通常是一个 JSON 字符串
        $bizData = is_string($dataStr) ? json_decode($dataStr, true) : $dataStr;

        try {
            if ($callback($bizData) === true) {
                return 'success';
            }
        } catch (\Throwable $e) {
            Log::error('[Huifu] Callback Logic Error: ' . $e->getMessage());
        }

        return 'fail';
    }

    /**
     * 混合验签策略 (核心修正)
     */
    public function verifySign(string $dataStr, string $sign): bool
    {
        $publicKey = config('huifu.sys_plat_puk'); // 务必确保读取的是汇付平台公钥

        // 补全公钥头尾 (如果配置里只填了中间那串)
        if (!str_contains($publicKey, 'BEGIN PUBLIC KEY')) {
            $publicKey = "-----BEGIN PUBLIC KEY-----\n" .
                chunk_split($publicKey, 64, "\n") .
                "-----END PUBLIC KEY-----";
        }

        // 【策略A】优先验证原始字符串 (解决浮点数精度、空对象转义问题)
        // 这是最稳的方式，只要汇付发过来的字符串没被改动，这里必过。
        if (openssl_verify($dataStr, base64_decode($sign), $publicKey, OPENSSL_ALGO_SHA256) === 1) {
            Log::debug('[Huifu] Verify Strategy A (Raw String) Passed');
            return true;
        }

        // 【策略B】降级到 SDK 标准验签 (解码 -> 排序 -> 编码)
        // 解决因 JSON 键值对顺序不一致导致的问题
        $dataArr = json_decode($dataStr, true);
        if (is_array($dataArr)) {
            if (BsPayTools::verifySign_sort($sign, $dataArr, $publicKey)) {
                Log::debug('[Huifu] Verify Strategy B (Sorted Array) Passed');
                return true;
            }
        }

        // 【策略C】处理反斜杠转义 (极端情况兜底)
        $strippedDataStr = stripslashes($dataStr);
        if ($strippedDataStr !== $dataStr) {
            if (openssl_verify($strippedDataStr, base64_decode($sign), $publicKey, OPENSSL_ALGO_SHA256) === 1) {
                Log::debug('[Huifu] Verify Strategy C (Stripped Slashes) Passed');
                return true;
            }
        }

        Log::error('[Huifu] All Verification Strategies Failed', [
            'sign_sample' => substr($sign, 0, 20) . '...',
            'data_sample' => substr($dataStr, 0, 100) . '...'
        ]);

        return false;
    }

    /**
     * 格式化金额 (保留2位小数)
     */
    private function formatAmounts(array &$params): void
    {
        $moneyKeys = ['trans_amt', 'ord_amt', 'div_amt', 'cash_amt', 'refund_amt'];
        foreach ($moneyKeys as $k) {
            if (isset($params[$k])) {
                $params[$k] = number_format((float)$params[$k], 2, '.', '');
            }
        }
    }
}
