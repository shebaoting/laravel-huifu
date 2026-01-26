<?php

namespace BsPaySdk\request;

use BsPaySdk\enums\FunctionCodeEnum;

/**
 * 申请开票
 *
 * @author sdk-generator
 * @Description
 */
class V2HycInvoiceApplyRequest extends BaseRequest
{

    /**
     * 请求流水号
     */
    private $reqSeqId;
    /**
     * 请求日期
     */
    private $reqDate;
    /**
     * 商户汇付id
     */
    private $huifuId;
    /**
     * 开票类目
     */
    private $invoiceCategory;
    /**
     * 汇付全局流水号集合
     */
    private $hfSeqIds;

    public function getFunctionCode() {
        return FunctionCodeEnum::$V2_HYC_INVOICE_APPLY;
    }


    public function getReqSeqId() {
        return $this->reqSeqId;
    }

    public function setReqSeqId($reqSeqId) {
        $this->reqSeqId = $reqSeqId;
    }

    public function getReqDate() {
        return $this->reqDate;
    }

    public function setReqDate($reqDate) {
        $this->reqDate = $reqDate;
    }

    public function getHuifuId() {
        return $this->huifuId;
    }

    public function setHuifuId($huifuId) {
        $this->huifuId = $huifuId;
    }

    public function getInvoiceCategory() {
        return $this->invoiceCategory;
    }

    public function setInvoiceCategory($invoiceCategory) {
        $this->invoiceCategory = $invoiceCategory;
    }

    public function getHfSeqIds() {
        return $this->hfSeqIds;
    }

    public function setHfSeqIds($hfSeqIds) {
        $this->hfSeqIds = $hfSeqIds;
    }

}
