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

        return $this->request(\BsPaySdk\request\V2TradePaymentJspayRequest::class, [
            'req_seq_id'      => $orderNo,
            'trans_amt'       => $amount,
            'goods_desc'      => $desc,
            'trade_type'      => 'T_MINIAPP',
            // 以下参数 SDK 类中没有定义 Setter，会自动进入 extendInfo
            'delay_acct_flag' => $extra['delay_acct_flag'] ?? 'Y',
            'notify_url'      => route('api.v1.weapp.payment.huifu_notify'),
            'wx_data'         => json_encode($wxData), // 必须是 JSON 字符串
            ...$extra
        ]);
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
        $request = new $requestClass();

        // 自动填充日期
        if (method_exists($request, 'setReqDate')) $request->setReqDate(date('Ymd'));
        // 自动生成流水号
        if (method_exists($request, 'setReqSeqId') && empty($params['req_seq_id'])) {
            $params['req_seq_id'] = date('YmdHis') . Str::random(8);
        }
        // 自动填充主商户号
        if (method_exists($request, 'setHuifuId') && empty($params['huifu_id'])) {
            $request->setHuifuId(config('huifu.huifu_id'));
        }

        // 自动进行金额格式化
        $this->formatAmounts($params);
        $extendInfos = [];

        foreach ($params as $key => $value) {
            // 将下划线转驼峰 (如 trans_amt -> setTransAmt)
            $method = 'set' . str_replace('_', '', ucwords($key, '_'));

            // 1. 如果 SDK 类中有 set 方法，直接调用
            if (method_exists($request, $method)) {
                $request->$method($value);
            }
            // 2. 如果没有 set 方法，说明这是扩展参数 (如 wx_data, notify_url)，放入 extendInfo
            else {
                $extendInfos[$key] = $value;
            }
        }

        // 如果有扩展参数，统一注入到 SDK 对象中
        if (!empty($extendInfos)) {
            // SDK 的 BaseRequest 有 setExtendInfo 方法
            $request->setExtendInfo($extendInfos);
        }

        return $this->exec($request);
    }

    /**
     * 8. 执行并解析
     */
    public function exec(object $request): array
    {
        Log::withContext(['huifu_req_id' => $request->getReqSeqId()]);

        $client = new BsPayClient();
        $result = $client->postRequest($request);

        $rawResponse = method_exists($result, 'getRspDatas') ? $result->getRspDatas() : [];

        if (!$result || (method_exists($result, 'isError') && $result->isError())) {
            $errorInfo = method_exists($result, 'getErrorInfo') ? $result->getErrorInfo() : ['msg' => 'Unknown Error'];
            // 记录详细错误日志
            Log::error('[Huifu] API Error', [
                'request' => (array)$request,
                'error'   => $errorInfo
            ]);
            throw new HuifuApiException($errorInfo);
        }

        return $rawResponse['data'] ?? $rawResponse;
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
     * 回调验签助手
     */
    public function handleCallback(callable $callback)
    {
        $dataStr = request()->input('resp_data');
        $sign = request()->input('sign');

        if (!$dataStr || !$sign || !$this->verifySign($dataStr, $sign)) {
            Log::error('[Huifu] Callback Signature Invalid');
            return response('fail', 400);
        }

        if ($callback(json_decode($dataStr, true))) {
            return response('success');
        }
        return response('fail');
    }

    public function verifySign(string $dataStr, string $sign): bool
    {
        $publicKey = config('huifu.rsa_huifu_public_key');
        if (BsPayTools::verifySign($sign, $dataStr, $publicKey)) {
            return true;
        }

        $data = json_decode($dataStr, true);
        if (is_array($data) && BsPayTools::verifySign_sort($sign, $data, $publicKey)) {
            return true;
        }

        Log::error('[Huifu] All signature verification methods failed.', [
            'data_sample' => Str::limit($dataStr, 100),
            'sign_sample' => Str::limit($sign, 20)
        ]);

        return false;
    }

    private function formatAmounts(array &$params): void
    {
        $keys = ['trans_amt', 'ord_amt', 'div_amt', 'cash_amt', 'refund_amt'];
        foreach ($keys as $k) {
            if (isset($params[$k])) $params[$k] = number_format((float)$params[$k], 2, '.', '');
        }
    }
}
