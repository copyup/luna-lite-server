<?php

namespace App\Common\Logic;

use App\Common\Enum\PayEnum;
use App\Common\Enum\RefundEnum;
use App\Common\Model\Recharge\RechargeOrder;
use App\Common\Model\Refund\RefundLog;
use App\Common\Model\Refund\RefundRecord;
use App\Common\Service\Pay\AliPayService;
use App\Common\Service\Pay\WechatPayService;

/**
 * 订单退款逻辑
 */
class RefundLogic extends BaseLogic
{
    protected static $refundLog;

    /**
     * @notes 发起退款
     */
    public static function refund($order, $refundRecordId, $refundAmount, $handleId)
    {
        // 退款前校验
        self::refundBeforeCheck($refundAmount);

        // 添加退款日志
        self::log($order, $refundRecordId, $refundAmount, $handleId);

        // 根据不同支付方式退款
        try {
            switch ($order['pay_way']) {
                //微信退款
                case PayEnum::WECHAT_PAY:
                    self::wechatPayRefund($order, $refundAmount);
                    break;
                // 支付宝退款
                case PayEnum::ALI_PAY:
                    self::aliPayRefund($refundRecordId, $refundAmount);
                    break;
                default:
                    throw new \Exception('支付方式异常');
            }

            // 此处true并不表示退款成功，仅表示退款请求成功，具体成功与否由定时任务查询或通过退款回调得知
            return true;
        } catch (\Exception $e) {
            // 退款请求失败,标记退款记录及日志为失败.在退款记录处重新退款
            self::$error = $e->getMessage();
            self::refundFailHandle($refundRecordId, $e->getMessage());
            return false;
        }
    }


    /**
     * @notes 退款前校验
     */
    public static function refundBeforeCheck($refundAmount)
    {
        if ($refundAmount <= 0) {
            throw new \Exception('订单金额异常');
        }
    }


    /**
     * @notes 微信支付退款
     */
    public static function wechatPayRefund($order, $refundAmount)
    {
        // 发起退款。 若发起退款请求返回明确错误，退款日志和记录标记状态为退款失败
        // 退款日志及记录的成功状态目前统一由定时任务查询退款结果为退款成功后才标记成功
        // 也可通过设置退款回调，在退款回调时处理退款记录状态为成功
        (new WechatPayService($order['order_terminal']))->refund([
            'transaction_id' => $order['transaction_id'],
            'refund_sn' => self::$refundLog['sn'],
            'refund_amount' => $refundAmount,// 退款金额
            'total_amount' => $order['order_amount'],// 订单金额
        ]);
    }

    /**
     * @notes 支付宝退款
     */
    public static function aliPayRefund($refundRecordId, $refundAmount)
    {
        $refundRecord = RefundRecord::where('id', $refundRecordId)->findOrEmpty()->toArray();

        $result = (new AliPayService())->refund($refundRecord['order_sn'], $refundAmount, self::$refundLog['sn']);
        $result = (array)$result;

        if ($result['code'] == '10000' && $result['msg'] == 'Success' && $result['fundChange'] == 'Y') {
            // 更新日志
            RefundLog::update([
                'refund_status' => RefundEnum::REFUND_SUCCESS,
                'refund_msg' => json_encode($result, JSON_UNESCAPED_UNICODE),
            ], ['id' => self::$refundLog['id']]);

            // 更新记录
            RefundRecord::update([
                'refund_status' => RefundEnum::REFUND_SUCCESS,
            ], ['id' => $refundRecordId]);

            // 更新订单信息
            if ($refundRecord['order_type'] == 'recharge') {
                RechargeOrder::update([
                    'id' => $refundRecord['order_id'],
                    'refund_transaction_id' => $result['tradeNo'] ?? '',
                ]);
            }
        }
    }


    /**
     * @notes 退款请求失败处理
     */
    public static function refundFailHandle($refundRecordId, $msg)
    {
        // 更新退款日志记录
        RefundLog::update([
            'id' => self::$refundLog['id'],
            'refund_status' => RefundEnum::REFUND_ERROR,
            'refund_msg' => $msg,
        ]);

        //  更新退款记录状态为退款失败
        RefundRecord::update([
            'id' => $refundRecordId,
            'refund_status' => RefundEnum::REFUND_ERROR,
            'refund_msg' => $msg,
        ]);
    }


    /**
     * @notes 退款日志
     */
    public static function log($order, $refundRecordId, $refundAmount, $handleId, $refundStatus = RefundEnum::REFUND_ING)
    {
        $sn = generate_sn(RefundLog::class, 'sn');

        self::$refundLog = RefundLog::create([
            'sn' => $sn,
            'record_id' => $refundRecordId,
            'user_id' => $order['user_id'],
            'handle_id' => $handleId,
            'order_amount' => $order['order_amount'],
            'refund_amount' => $refundAmount,
            'refund_status' => $refundStatus
        ]);
    }

}
