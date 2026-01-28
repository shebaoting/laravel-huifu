<?php

declare(strict_types=1);

namespace Shebaoting\Huifu\Services;

use BsPaySdk\core\BsPayClient;
use BsPaySdk\core\BsPayTools;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Shebaoting\Huifu\Exceptions\HuifuApiException;
use BsPaySdk\request\V2TradePaymentJspayRequest;
use BsPaySdk\request\V2TradePaymentDelaytransConfirmRequest;
use BsPaySdk\request\V2TradeAcctpaymentBalanceQueryRequest;
use BsPaySdk\request\V2SupplementaryPictureRequest;
use BsPaySdk\request\V2TradePaymentScanpayRefundRequest;
use BsPaySdk\request\V3TradePaymentScanpayQueryRequest;
use BsPaySdk\request\V2MerchantBasicdataQueryRequest;
use BsPaySdk\request\V2UserBasicdataQueryRequest;

readonly class HuifuService
{
    /**
     * 1. 小程序下单
     */
    public function miniAppPay(string $orderNo, float|string $amount, string $desc, string $openid, array $extra = []): array
    {
        $wxData = [
            'sub_appid' => config('huifu.sub_appid') ?: config('services.wechat.mini_app_id'),
            'openid'    => $openid,
        ];

        return $this->request(V2TradePaymentJspayRequest::class, [
            'req_seq_id'      => $orderNo,
            'trans_amt'       => $amount,
            'goods_desc'      => $desc,
            'trade_type'      => 'T_MINIAPP',
            'delay_acct_flag' => $extra['delay_acct_flag'] ?? 'Y',
            'notify_url'      => route('api.v1.weapp.payment.huifu_notify'),
            'wx_data'         => json_encode($wxData),
            ...$extra
        ]);
    }

    /**
     * 2. 按比例分账 (更新：适配 percentage_flag = Y)
     *
     * @param string $orderNo 原交易流水号
     * @param string $orderDate 原交易日期
     * @param float $totalAmount 原交易总金额（暂时未使用，但保留参数位置）
     * @param array $splits 比例数组 ['商户号' => '100.00', '商户号2' => '50.00'] (注意：传字符串百分比，如 100.00 代表 100%)
     */
    public function splitByRatio(string $orderNo, string $orderDate, float $totalAmount, array $splits): array
    {
        $acctInfos = [];
        foreach ($splits as $huifuId => $percentage) {
            $acctInfos[] = [
                'huifu_id'       => (string)$huifuId,
                'percentage_div' => (string)$percentage . '%', // 根据文档示例，如果不需要%请自行移除
            ];
        }

        // 构造分账对象
        $splitBunch = [
            'percentage_flag' => 'Y', // 按百分比
            'acct_infos'      => $acctInfos,
        ];

        return $this->confirmAllocation($orderNo, $orderDate, $splitBunch);
    }

    /**
     * 3. 按固定金额分账 (更新：适配 percentage_flag = N)
     *
     * @param string $orderNo 原交易流水号
     * @param string $orderDate 原交易日期
     * @param array $amounts 金额数组 ['商户号' => 10.50]
     */
    public function splitByAmount(string $orderNo, string $orderDate, array $amounts): array
    {
        $acctInfos = [];
        $totalDivAmt = 0.00;

        foreach ($amounts as $huifuId => $amt) {
            $floatAmt = (float)$amt;
            $totalDivAmt += $floatAmt;

            $acctInfos[] = [
                'huifu_id' => (string)$huifuId,
                'div_amt'  => number_format($floatAmt, 2, '.', ''),
            ];
        }

        // 构造分账对象
        $splitBunch = [
            'percentage_flag' => 'N', // 按金额
            'total_div_amt'   => number_format($totalDivAmt, 2, '.', ''), // 本次分账总金额
            'acct_infos'      => $acctInfos,
        ];

        return $this->confirmAllocation($orderNo, $orderDate, $splitBunch);
    }

    /**
     * 4. 确认分账 (底层更新)
     *
     * @param string $orderNo 原交易流水号
     * @param string $orderDate 原交易日期
     * @param array $splitBunch 构造好的分账对象结构
     */
    public function confirmAllocation(string $orderNo, string $orderDate, array $splitBunch): array
    {
        // 这里的 huifu_id 是平台商户号，request 方法会自动通过 config 注入
        return $this->request(V2TradePaymentDelaytransConfirmRequest::class, [
            'org_req_seq_id'   => $orderNo,
            'org_req_date'     => $orderDate,
            'acct_split_bunch' => json_encode($splitBunch, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * 5. 余额查询
     */
    public function getBalance(string $huifuId = null): float
    {
        $res = $this->request(V2TradeAcctpaymentBalanceQueryRequest::class, [
            'huifu_id' => $huifuId ?? config('huifu.huifu_id')
        ]);
        return (float) ($res['acct_bal'] ?? 0);
    }

    /**
     * 6. 图片上传
     */
    public function uploadImage(string $localPath, string $fileType = 'F55'): string
    {
        $res = $this->request(V2SupplementaryPictureRequest::class, [
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

        if (method_exists($request, 'setReqDate')) $request->setReqDate(date('Ymd'));
        if (method_exists($request, 'setReqSeqId') && empty($params['req_seq_id'])) {
            $params['req_seq_id'] = date('YmdHis') . Str::random(8);
        }
        // 自动填充主商户号 (API文档中的顶级 huifu_id，通常是平台号)
        if (method_exists($request, 'setHuifuId') && empty($params['huifu_id'])) {
            $request->setHuifuId(config('huifu.huifu_id'));
        }

        $this->formatAmounts($params);

        $extendInfos = [];

        foreach ($params as $key => $value) {
            $method = 'set' . str_replace('_', '', ucwords($key, '_'));
            if (method_exists($request, $method)) {
                $request->$method($value);
            } else {
                $extendInfos[$key] = $value;
            }
        }

        if (!empty($extendInfos)) {
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

        $data = $rawResponse['data'] ?? [];
        $respCode = $data['resp_code'] ?? ($rawResponse['resp_code'] ?? '');

        if (!in_array($respCode, ['00000000', '00000100'])) {
            Log::error('[Huifu] API Business Failure', $rawResponse);
            throw new HuifuApiException($rawResponse);
        }

        return $data;
    }

    /**
     * 9. 极简退款
     */
    public function refund(string $orgOrderNo, string $orgOrderDate, float|string $amount, string $reason = '用户申请退款'): array
    {
        return $this->request(V2TradePaymentScanpayRefundRequest::class, [
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
        return $this->request(V3TradePaymentScanpayQueryRequest::class, [
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

    /**
     * 增强版验签方法
     */
    public function verifySign(string $dataStr, string $sign): bool
    {
        $publicKey = config('huifu.rsa_huifu_public_key');
        if (BsPayTools::verifySign($sign, $dataStr, $publicKey)) {
            return true;
        }

        $data = json_decode($dataStr, true);
        $forceObjectFields = [
            'risk_check_data',
            'risk_check_info',
            'wx_response',
            'alipay_response',
            'unionpay_response',
            'acct_split_bunch',
            'terminal_device_data',
            'card_info',
            'bank_info_data'
        ];

        foreach ($forceObjectFields as $field) {
            if (isset($data[$field]) && is_array($data[$field]) && empty($data[$field])) {
                $data[$field] = (object)[];
            }
        }

        return (bool) BsPayTools::verifySign_sort($sign, $data, $publicKey);
    }

    private function formatAmounts(array &$params): void
    {
        $keys = ['trans_amt', 'ord_amt', 'div_amt', 'cash_amt', 'refund_amt'];
        foreach ($keys as $k) {
            if (isset($params[$k])) $params[$k] = number_format((float)$params[$k], 2, '.', '');
        }
    }

    /**
     * 获取商户详细信息（实时）
     */
    public function getMerchantDetail(string $huifuId): array
    {
        try {
            return $this->request(V2MerchantBasicdataQueryRequest::class, [
                'huifu_id' => $huifuId,
            ]);
        } catch (\Exception $e) {
            \Log::error("查询汇付商户详情失败: " . $e->getMessage());
            return [];
        }
    }

    /**
     * 获取汇付用户信息（基于 v2/user/basicdata/query 接口）
     */
    public function getUserDetail(string $huifuId): array
    {
        try {
            return $this->request(V2UserBasicdataQueryRequest::class, [
                'huifu_id' => $huifuId,
            ]);
        } catch (\Exception $e) {
            \Log::error("查询汇付用户信息失败: " . $e->getMessage());
            return ['error' => '接口调用失败: ' . $e->getMessage()];
        }
    }
}
