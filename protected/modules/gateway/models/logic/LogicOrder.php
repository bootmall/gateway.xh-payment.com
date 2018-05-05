<?php


namespace app\modules\gateway\models\logic;

use app\common\exceptions\InValidRequestException;
use app\common\models\logic\LogicUser;
use app\common\models\model\ChannelAccount;
use app\common\models\model\Financial;
use app\common\models\model\UserPaymentInfo;
use app\jobs\PaymentNotifyJob;
use app\lib\helpers\SignatureHelper;
use app\lib\payment\ObjectNoticeResult;
use power\yii2\exceptions\ParameterValidationExpandException;
use Yii;
use app\common\models\model\User;
use app\common\models\model\Order;
use app\components\Macro;
use Exception;

class LogicOrder
{
    //通知失败后间隔通知时间
    const NOTICE_DELAY = 300;

    /*
     * 添加充值记录
     *
     * @param array $request 请求数组
     * @param User $merchant 充值账户
     * @param ChannelAccount $paymentChannelAccount 充值的三方渠道账户
     */
    static public function addOrder(array $request, User $merchant, UserPaymentInfo $paymentChannelAccount){

        $orderData = [];
        $orderData['order_no'] = self::generateOrderNo();
        $orderData['pay_method_code'] = $request['pay_type'];
        $orderData['notify_url'] = $request['notify_url'];
        $orderData['return_url'] = $request['return_url'];
        $orderData['bank_code'] = $request['bank_code'];
        $orderData['merchant_id'] = $request['merchant_code'];
        $orderData['merchant_user_id'] = $merchant->id;
        $orderData['merchant_order_no'] = $request['order_no'];
        $orderData['merchant_order_time'] = strtotime($request['order_time']);
        $orderData['amount'] = $request['order_amount'];
        $orderData['client_ip'] = Yii::$app->request->userIP;
        $orderData['return_params'] = $request['return_params'];

        $orderData['app_id'] = $request['merchant_code'];
        $orderData['status'] = Order::STATUS_NOTPAY;
        $orderData['financial_status'] = Order::FINANCIAL_STATUS_NONE;
        $orderData['notify_status'] = Order::NOTICE_STATUS_NONE;

        $orderData['merchant_account'] = $merchant->username;
        $channelAccount = $paymentChannelAccount->channelAccount;
        $payMethods = $paymentChannelAccount->getPayMethodById($orderData['pay_method_code']);

        $orderData['channel_id'] = $channelAccount->channel_id;
        $orderData['channel_account_id'] = $channelAccount->id;
        $orderData['channel_merchant_id'] = $channelAccount->merchant_id;
        $orderData['channel_app_id'] = $channelAccount->app_id;
        $orderData['fee_rate'] = $payMethods['rate'];

        $orderData['fee_amount'] = bcmul($payMethods['rate'],$orderData['amount'],9);
        $orderData['created_at'] = time();

        $hasOrder = Order::findOne(['app_id'=>$orderData['app_id'],'merchant_order_no'=>$request['order_no']]);
        if($hasOrder){
//            throw new InValidRequestException('请不要重复下单');
            return $hasOrder;
        }

        $newOrder = new Order();
        self::beforeAddRemit($request,$merchant,$paymentChannelAccount);

        $newOrder->setAttributes($orderData,false);
        $newOrder->save();

        return $newOrder;
    }

    /*
     * 充值前置操作
     * 可进行额度校验的等操作
     *
     * @param array $request 请求数组
     * @param User $merchant 提款账户
     * @param ChannelAccount $paymentChannelAccount 提款的三方渠道账户
     */
    static public function beforeAddOrder(Order $order, User $merchant, ChannelAccount $paymentChannelAccount){
        $userPaymentConfig = $merchant->paymentInfo;
        //检测账户单笔限额
        if($userPaymentConfig->recharge_quota_pertime && $order->amount > $userPaymentConfig->recharge_quota_pertime){
            throw new Exception(null,Macro::ERR_PAYMENT_REACH_ACCOUNT_QUOTA_PER_TIME);
        }
        //检测账户日限额
        if($userPaymentConfig->recharge_quota_perday && $order->recharge_today > $userPaymentConfig->recharge_quota_perday){
            throw new Exception(null,Macro::ERR_PAYMENT_REACH_ACCOUNT_QUOTA_PER_DAY);
        }

        //检测渠道单笔限额
        if($paymentChannelAccount->recharge_quota_pertime && $order->amount > $paymentChannelAccount->recharge_quota_pertime){
            throw new Exception(null,Macro::ERR_PAYMENT_REACH_CHANNEL_QUOTA_PER_TIME);
        }
        //检测渠道日限额
        if($paymentChannelAccount->recharge_quota_perday && $paymentChannelAccount->recharge_today > $paymentChannelAccount->recharge_quota_perday){
            throw new Exception(null,Macro::ERR_PAYMENT_REACH_CHANNEL_QUOTA_PER_DAY);
        }

    }

    static public function generateOrderNo(){
        return 'P'.date('ymdHis').mt_rand(10000,99999);
    }

    static public function getOrderByOrderNo($orderNo){
        $order = Order::findOne(['order_no'=>$orderNo]);
        if(empty($order)){
            throw new InValidRequestException('订单不存在');
        }
        return $order;
    }

    static public function getPaymentChannelAccount(Order $order)
    {
        $channel = ChannelAccount::findOne([
            'channel_id'=>$order->channel_id,
            'merchant_id'=>$order->channel_merchant_id,
            'app_id'=>$order->channel_app_id,
        ]);

        if(empty($channel)){
            throw new InValidRequestException('无法根据订单查找支付渠道信息');
        }

        return $channel;
    }

    static public function processChannelNotice(ObjectNoticeResult $noticeResult){
        if(
//            $noticeResult->status !== Macro::SUCCESS
            !$noticeResult->order
//            || !$noticeResult->amount
        ){
            throw new InValidRequestException('支付结果对象错误',Macro::ERR_PAYMENT_NOTICE_RESULT_OBJECT);
        }

        $order = $noticeResult->order;
        //未处理
        if( $noticeResult->status === Macro::SUCCESS && $order->status !== Order::STATUS_PAID){
            $order = self::paySuccess($order,$noticeResult->amount,$noticeResult->channelOrderNo);

            $order = self::bonus($order);
        }
        elseif( $noticeResult->status === Macro::FAIL){
            $order = self::payFail($order,$noticeResult->msg);
            Yii::debug([__FUNCTION__,'order not paid',$noticeResult->orderNo]);
        }

        if($order->notify_status != Order::NOTICE_STATUS_SUCCESS){
            self::notify($order);
        }
    }

    /*
     * 订单支付失败
     *
     * @param Order $order 订单对象
     * @param String $failMsg 失败描述信息
     */
    static public function payFail(Order $order, $failMsg='')
    {
        if ($order->status === Order::STATUS_FAIL) {
            return $order;
        }

        $order->status = Order::STATUS_FAIL;
        $order->fail_msg = $failMsg;
        $order->save();

        return $order;
    }

    /*
     * 订单支付成功
     *
     * @param Order $order 订单对象
     * @param Decimal $paidAmount 实际支付金额
     * @param String $channelOrderNo 第三方流水号
     */
    static public function paySuccess(Order $order,$paidAmount,$channelOrderNo){
        Yii::debug([__FUNCTION__.' '.$order->order_no.','.$paidAmount.','.$channelOrderNo]);
        if($order->status === Order::STATUS_PAID){
            return $order;
        }

        //更改订单状态
        $order->paid_amount = $paidAmount;
        if($order->amount>$order->paid_amount){
            $order->amount = $order->paid_amount;
        }
        $order->channel_order_no = $channelOrderNo;
        $order->status = Order::STATUS_PAID;
        $order->paid_at = time();
        $order->save();

        $logicUser = new LogicUser($order->merchant);
        //更新充值金额
        bcscale(9);
        $logicUser->changeUserBalance($order->paid_amount, Financial::EVENT_TYPE_RECHARGE, $order->order_no, Yii::$app->request->userIP);

        //需扣除充值手续费
        $logicUser->changeUserBalance(0-$order->fee_amount, Financial::EVENT_TYPE_RECHARGE_FEE, $order->order_no, Yii::$app->request->userIP);

        return $order;
    }

    /*
     * 订单分红
     */
    static public function bonus(Order $order){
        Yii::debug([__CLASS__.':'.__FUNCTION__.' '.$order->order_no]);
        if($order->financial_status === Order::FINANCIAL_STATUS_SUCCESS){
            Yii::warning([__FUNCTION__.' has been bonus,will return, '.$order->order_no]);
            return $order;
        }

        //所有上级代理UID
        $parentIds = $order->merchant->getAllParentAgentId();
        //从自己开始算
        $parentIds[] = $order->merchant->id;

        bcscale(9);
        $parentIdLen = count($parentIds)-1;
        for($i=$parentIdLen;$i>=0;$i--){
            $pUser = User::findActive($parentIds[$i]);
            $payMethods = $pUser->paymentInfo->getPayMethodById($order->pay_method_code);

            if(!empty($payMethods)){
                //parent_recharge_rebate_rate
                if(empty($payMethods['parent_recharge_rebate_rate'])){
                    Yii::debug(["order bonus, recharge_parent_rebate_rate empty",$pUser->id,$pUser->username]);
                    continue;
                }
                Yii::debug(["order bonus, find config",\GuzzleHttp\json_encode($payMethods)]);

                if(!$pUser->parentAgent){
                    Yii::debug(["order bonus, has no parent",$pUser->id,$pUser->username]);
                }
                //有上级的才返，余额操作对象是上级代理
                if($pUser->parentAgent){
                    Yii::debug(["order bonus parent",$pUser->id,$pUser->username,$pUser->parentAgent->id,$pUser->parentAgent->username]);
                    $logicUser =  new LogicUser($pUser->parentAgent);
                    $rechargeFee =  bcmul($payMethods['parent_recharge_rebate_rate'],$order->paid_amount);
                    $logicUser->changeUserBalance($rechargeFee, Financial::EVENT_TYPE_BONUS, $order->order_no, Yii::$app->request->userIP);
                }else{

                }

            }
        }

        //更新订单账户处理状态
        $order->financial_status = Order::FINANCIAL_STATUS_SUCCESS;
        $order->save();

        return $order;
    }

    static public function createNotifyParameters(Order $order){

        switch ($order->status){
            case Order::STATUS_PAID:
                $tradeStatus = 'success';
                break;
            case Order::STATUS_PAYING:
                $tradeStatus = 'paying';
                break;
            case Order::STATUS_FAIL:
                $tradeStatus = 'failed';
                break;
            default:
                $tradeStatus = 'failed';
        }

        $notifyType = 'back_notify';
        if (php_sapi_name() != "cli" && Yii::$app->request->isGet) {
            $notifyType = 'bank_page';
        }

        $arrParams = [
            'merchant_code'=>$order->merchant_id,
            'order_no'=>$order->merchant_order_no,
            'order_amount'=>$order->paid_amount,
            'order_time'=>date('Y-m-d H:i:s',$order->created_at),
            'return_params'=>$order->return_params,
            'trade_no'=>$order->order_no,
            'trade_time'=>date('Y-m-d H:i:s',$order->paid_at),
            'trade_status'=>$tradeStatus,
            'notify_type'=>$notifyType,//back_notify
        ];
        //'sign'=>$order['xxxx'],
        $signType = Yii::$app->params['paymentGateWayApiDefaultSignType'];
        $key = $order->merchant->paymentInfo->app_key_md5;
        $arrParams['sign'] = SignatureHelper::calcSign($arrParams, $key, $signType);

        return $arrParams;
    }

    /*
     * 生成订单同步通知跳转连接
     */
    static public function createReturnUrl(Order $order){

        $arrParams = self::createNotifyParameters($order);
        $url = $order->reutrn_url.'?'.http_build_query($arrParams);
        return $url;
    }

    /*
     * 生成订单同步通知跳转连接
     */
    static public function updateNotifyResult($orderNo, $retCode, $retContent){
        $order = self::getOrderByOrderNo($orderNo);

        $order->notify_at = time();
        $order->notify_status = $retCode;
        $order->next_notify_time = time()+self::NOTICE_DELAY;
        $order->save();
        $order->updateCounters(['notify_times' => 1]);

        return $order;
    }


    /*
     * 异步通知商户
     */
    static public function notify(Order $order){
        //TODO: add task queue

        $arrParams = self::createNotifyParameters($order);
        $job = new PaymentNotifyJob([
            'orderNo'=>$order->order_no,
            'url' => $order->notify_url,
            'data' => $arrParams,
        ]);
        Yii::$app->paymentNotifyQueue->push($job);//->delay(10)
    }

    /**
     * 获取订单状态
     *
     */
    public static function getStatus($orderNo = '',$merchantOrderNo = '', $merchant)
    {
        if($merchantOrderNo && !$orderNo){
            $order = Order::findOne(['merchant_order_no'=>$merchantOrderNo,'merchant_id'=>$merchant->id]);
        }
        elseif($orderNo){
            $order = Order::findOne(['order_no'=>$merchantOrderNo]);
        }

        if(!$order){
            throw new \Exception("订单不存在('platform_order_no:{$orderNo}','merchant_order_no:{$merchantOrderNo}')");
        }

        return $order;
    }
}