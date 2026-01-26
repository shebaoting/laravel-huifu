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
     * 执行底层请求并处理响应
     *
     * @param mixed $requestObj 请求对象
     * @return array
     * @throws HuifuApiException
     */
    protected function exec($requestObj): array
    {
        // 1. 初始化官方客户端
        $client = new BsPayClient();

        // 2. 记录请求日志
        $seqId = method_exists($requestObj, 'getReqSeqId') ? $requestObj->getReqSeqId() : 'N/A';
        Log::info('[Huifu] Request Start: ' . $seqId, [
            'class' => get_class($requestObj),
            // 'params' => $requestObj->getExtendInfos() // 如有需要可开启详细参数日志
        ]);

        // 3. 发送请求
        $result = $client->postRequest($requestObj);

        // 4. 检查网络或系统级错误
        if (empty($result) || !is_array($result)) {
            Log::error('[Huifu] System Error: Empty or Invalid Response', ['seq_id' => $seqId]);
            throw new HuifuApiException([
                'resp_code' => 'SYSTEM_ERROR',
                'resp_desc' => '汇付接口无响应或返回格式错误'
            ]);
        }

        // 5. 【核心修复】检查业务状态码
        $respCode = $result['resp_code'] ?? '';

        // 允许 '00000000' (成功) 和 '00000100' (处理中/预下单成功)
        if ($respCode !== '00000000' && $respCode !== '00000100') {

            // 记录业务错误日志
            Log::error('[Huifu] Business Error', [
                'req_id' => $seqId,
                'resp'   => $result
            ]);

            // 【核心修复】抛出异常时必须传数组，原代码可能传了字符串导致 TypeError
            throw new HuifuApiException($result);
        }

        // 6. 记录成功/处理中日志（可选）
        Log::info('[Huifu] Request Success', ['req_id' => $seqId, 'code' => $respCode]);

        return $result;
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
     * 处理回调
     *
     * @param callable $callback 业务逻辑回调
     * @return mixed
     * @throws HuifuApiException
     */
    public function handleCallback(callable $callback)
    {
        $request = request();
        $data = $request->all();

        // [Octane 修复] 显式获取 Raw Body，解决 php://input 为空的问题
        $rawContent = $request->getContent();

        Log::info('[Huifu] Notify Data:', $data);

        // 验证签名 (传入 rawContent)
        $this->verifySign($data, $rawContent);

        // 执行业务回调
        $result = $callback($data);

        if (is_bool($result) && $result) {
            return 'success';
        }

        if ($result === 'success') {
            return 'success';
        }

        return 'fail';
    }
    /**
     * 验证签名 (修复 Octane 兼容性与配置读取)
     *
     * @param array $data 回调数组
     * @param string|null $body 原始请求体
     * @return void
     * @throws HuifuApiException
     */
    /**
     * 验证签名 (深度调试版)
     *
     * @param array $data 回调数据
     * @param string|null $body 原始请求体
     * @return void
     * @throws HuifuApiException
     */
    protected function verifySign(array $data, $body = null): void
    {
        // 1. 获取公钥
        $publicKey = config('huifu.rsa_huifu_public_key');
        $publicKey = (string) $publicKey;

        if (!str_contains($publicKey, 'BEGIN PUBLIC KEY')) {
            $publicKey = "-----BEGIN PUBLIC KEY-----\n" .
                chunk_split($publicKey, 64, "\n") .
                "-----END PUBLIC KEY-----";
        }

        // 2. 准备数据
        if ($body === null) {
            $body = request()->getContent();
        }
        $dataStr = (string) $body;
        $sign = $data['sign'] ?? '';

        if (empty($sign)) {
            Log::warning('[Huifu Debug] No sign');
            return;
        }

        $decodedSign = base64_decode($sign);

        // 3. 构建所有可能的待签名字符串 (Debug Candidates)
        $candidates = [];

        // Candidate 1: 原始 Body
        $candidates['raw_body'] = $dataStr;

        // Candidate 2: 去转义的 Body
        $candidates['stripped_body'] = stripslashes($dataStr);

        // Candidate 3: 仅 resp_data 的值 (如果是字符串)
        if (isset($data['resp_data']) && is_string($data['resp_data'])) {
            $candidates['resp_data_string'] = $data['resp_data'];
        }

        // Candidate 4: 仅 resp_data 的值 (去转义)
        if (isset($data['resp_data']) && is_string($data['resp_data'])) {
            $candidates['resp_data_stripped'] = stripslashes($data['resp_data']);
        }

        // Candidate 5: SDK 排序逻辑 (如果可用)
        if (class_exists(BsPayTools::class)) {
            $dataForSort = $data;
            unset($dataForSort['sign']);
            ksort($dataForSort);
            // 尝试不同的 JSON 编码选项
            $candidates['sdk_sort_unescaped'] = json_encode($dataForSort, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $candidates['sdk_sort_std'] = json_encode($dataForSort);
        }

        // 4. 逐个尝试并记录日志
        $successStrategy = null;
        foreach ($candidates as $name => $content) {
            $verifyResult = openssl_verify($content, $decodedSign, $publicKey, OPENSSL_ALGO_SHA256);

            // 记录详细的调试日志
            Log::info("[Huifu Debug] Testing Strategy: {$name}", [
                'verify_result' => $verifyResult, // 1=Success, 0=Fail, -1=Error
                'content_sample' => substr($content, 0, 50) . '...',
                'content_len' => strlen($content)
            ]);

            if ($verifyResult === 1) {
                $successStrategy = $name;
                break;
            } elseif ($verifyResult === -1) {
                // 如果 OpenSSL 报错，记录错误信息
                while ($msg = openssl_error_string()) {
                    Log::error("[Huifu Debug] OpenSSL Error ({$name}): {$msg}");
                }
            }
        }

        if ($successStrategy) {
            Log::info("[Huifu Debug] Verify Success using: {$successStrategy}");
            return;
        }

        // 失败时抛出异常
        Log::error('[Huifu Debug] All strategies failed', ['public_key_sample' => substr($publicKey, 0, 30)]);
        throw new HuifuApiException(['resp_code' => 'FAIL', 'resp_desc' => '验签失败 (Debug Mode)']);
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
