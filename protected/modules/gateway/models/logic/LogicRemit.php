<?php


namespace app\modules\gateway\models\logic;

use app\common\exceptions\InValidRequestException;
use app\common\exceptions\OperationFailureException;
use app\common\models\logic\LogicUser;
use app\common\models\model\BankCardIssuer;
use app\common\models\model\BankCodes;
use app\common\models\model\ChannelAccount;
use app\common\models\model\Financial;
use app\common\models\model\LogApiRequest;
use app\common\models\model\Remit;
use app\common\models\model\SiteConfig;
use app\common\models\model\User;
use app\common\models\model\UserPaymentInfo;
use app\components\Macro;
use app\components\Util;
use app\jobs\RemitNotifyJob;
use app\lib\payment\ChannelPayment;
use app\lib\payment\channels\BasePayment;
use Yii;
use app\lib\helpers\SignatureHelper;
use yii\db\Query;

class LogicRemit
{
    //通知失败后间隔通知时间
    const NOTICE_DELAY = 300;
    const REDIS_CACHE_KEY = 'lt_remit';
    const MAX_TIME_COMMIT_TO_BANK = 1;

    //响应给商户的银行状态
    const RESP_BANK_STATUS = [
        Remit::BANK_STATUS_NONE       => 'pending',
        Remit::BANK_STATUS_PROCESSING => 'processing',
        Remit::BANK_STATUS_SUCCESS    => 'success',
        Remit::BANK_STATUS_FAIL       => 'failed',
    ];

    /*
     * 添加提款记录
     *
     * @param array $request 请求数组
     * @param User $merchant 提款账户
     * @param ChannelAccount $paymentChannelAccount 提款的三方渠道账户
     */
    static public function addRemit(array $request, User $merchant, ChannelAccount $paymentChannelAccount)
    {
        $remitData                      = [];
        $remitData['app_id']              = $request['app_id'] ?? $merchant->id;
        $remitData['merchant_order_no'] = $request['trade_no'];

        $hasRemit = Remit::findOne(['app_id' => $remitData['app_id'], 'merchant_order_no'=>$request['trade_no']]);
        if ($hasRemit) {
            throw new OperationFailureException('请不要重复下单');
            return $hasRemit;
        }

        $remitData['amount']               = $request['order_amount'];
        $remitData['type']                 = $request['type'] ??  Remit::TYPE_API;
        $remitData['bat_order_no']         = $request['bat_order_no'] ?? '';
        $remitData['bat_index']            = $request['bat_index'] ?? 0;
        $remitData['bat_count']            = $request['bat_count'] ?? 0;
        $remitData['bank_province']        = $request['bank_province'] ?? '';
        $remitData['bank_city']            = $request['bank_city'] ?? '';
        $remitData['bank_branch']          = $request['bank_branch'] ?? '';
        $remitData['bank_code']            = $request['bank_code'];
        $remitData['bank_account']         = $request['account_name'];
        $remitData['bank_no']              = $request['account_number'];
        $remitData['client_ip']            = $request['client_ip'] ?? '';
        $remitData['op_uid']               = $request['op_uid'] ?? 0;
        $remitData['op_username']          = $request['op_username'] ?? '';
        $remitData['notify_url']           = $request['notify_url'] ?? '';
        $remitData['commit_to_bank_times'] = 0;

        $remitData['status']           = Remit::STATUS_NONE;
        $remitData['remit_fee']        = $merchant->paymentInfo->remit_fee;
        $remitData['bank_status']      = Remit::BANK_STATUS_NONE;
        $remitData['financial_status'] = Remit::FINANCIAL_STATUS_NONE;
        $remitData['notify_status']    = Remit::NOTICE_STATUS_NONE;

        $remitData['merchant_id']         = $merchant->id;
        $remitData['merchant_account']    = $merchant->username;
        $remitData['all_parent_agent_id'] = $merchant->all_parent_agent_id;

        $remitData['channel_account_id']  = $paymentChannelAccount->id;
        $remitData['channel_id']          = $paymentChannelAccount->channel_id;
        $remitData['channel_merchant_id'] = $paymentChannelAccount->merchant_id;
        $remitData['channel_app_id']      = $paymentChannelAccount->app_id;
        $remitData['created_at']          = time();
        $remitData['order_no']            = self::generateRemitNo($remitData);

        $parentConfigModels = UserPaymentInfo::findAll(['app_id'=>$merchant->getAllParentAgentId()]);
        //把自己也存进去
        $parentConfigModels[] = $merchant->paymentInfo;
        $parentConfigs = [];
        foreach ($parentConfigModels as $pc){
            $parentConfigs[] = [
                'channel_account_id'=>$pc->remit_channel_account_id,
                'fee'=>$pc->remit_fee,
                'fee_rebate'=>$pc->remit_fee_rebate,
                'app_id'=>$pc->app_id,
                'merchant_id'=>$pc->user_id,
            ];
        }
        $remitData['all_parent_remit_config'] = json_encode($parentConfigs);

        $remitData['plat_fee_amount']     = $paymentChannelAccount->remit_fee;
        $remitData['plat_fee_profit']     = 0;//bcsub($topestPrent['fee'], $remitData['plat_fee_amount'],6);
        //如果上级列表不仅有自己
        if(count($parentConfigs)>1){
            $remitData['all_parent_recharge_config'] = json_encode($parentConfigs);
            //上级代理列表第一个为最上级代理
            $topestPrent = array_shift($parentConfigs);
            $remitData['plat_fee_profit']     = bcsub($topestPrent['fee'],$remitData['plat_fee_amount'],6);

            if($topestPrent['fee']<$remitData['plat_fee_amount']){
                Yii::error("商户费率配置错误,小于渠道最低费率: 顶级商户ID:{$topestPrent['merchant_id']},商户渠道账户ID:{$topestPrent['channel_account_id']},商户费率:{$topestPrent['fee']},渠道名:{$paymentChannelAccount->channel_name},渠道费率:{$remitData['plat_fee_amount']}");
                throw new InValidRequestException("商户费率配置错误,小于渠道最低费率!");
            }
        }
        //没有上级,平台利润为商户-渠道
        else{
            $remitData['plat_fee_profit']     = bcsub($remitData['remit_fee'],$remitData['plat_fee_amount'],6);

        }
        unset($parentConfigs);
        unset($parentConfigModels);

        $newRemit = new Remit();
        $newRemit->setAttributes($remitData,false);
        try{
            self::beforeAddRemit($newRemit, $merchant, $paymentChannelAccount);
            $newRemit->save();

            //下单后立即扣款
            $newRemit = self::deduct($newRemit);
        }catch (\Exception $e) {
            Yii::error('beforeAddRemit error:'.$e->getMessage());
            $newRemit->save();
            $newRemit = self::setFail($newRemit, $e->getMessage());

            throw new OperationFailureException($e->getMessage(),Macro::FAIL);
        }

        //接口日志埋点
        Yii::$app->params['apiRequestLog'] = [
            'event_id'=>$newRemit->merchant_order_no,
            'event_type'=> LogApiRequest::EVENT_TYPE_IN_REMIT_ADD,
            'merchant_id'=>$newRemit->merchant_id??$merchant->id,
            'merchant_name'=>$newRemit->merchant_account??$merchant->username,
            'channel_account_id'=>$paymentChannelAccount->id,
            'channel_name'=>$paymentChannelAccount->channel_name,
        ];

        self::updateToRedis($newRemit);

        return $newRemit;
    }

    /*
     * 提款前置操作
     * 可进行额度校验的等操作
     *
     * @param Remit $remit remit对象
     * @param User $merchant 提款账户
     * @param ChannelAccount $paymentChannelAccount 提款的三方渠道账户
     */
    static public function beforeAddRemit(Remit &$remit, User $merchant, ChannelAccount $paymentChannelAccount){
        $userPaymentConfig = $merchant->paymentInfo;
        //站点是否允许费率设置为0
        $feeCanBeZero = SiteConfig::cacheGetContent('remit_fee_can_be_zero');

        if(SiteConfig::cacheGetContent('check_remit_bank_no') && !BankCardIssuer::checkBankNoBankCode($remit->bank_no, $remit->bank_code)){
            throw new OperationFailureException($remit->order_no." 银行卡号与银行不匹配",Macro::ERR_PAYMENT_BANK_CODE);
        }

        $bankCode = BankCodes::getChannelBankCode($remit['channel_id'],$remit['bank_code'],'remit');
        if(empty($bankCode)){
            throw new OperationFailureException($remit->order_no." 商户绑定的通道暂不支持此银行出款,银行代码配置错误:".$remit['channel_id'].':'.$remit['bank_code'],Macro::ERR_PAYMENT_BANK_CODE);
        }

        //账户费率检测
        if(!$feeCanBeZero && $userPaymentConfig->remit_fee <= 0){
            throw new OperationFailureException("用户出款费率不能设置为0:".Macro::ERR_MERCHANT_FEE_CONFIG);
        }

        //检测账户单笔限额
        if($userPaymentConfig->remit_quota_pertime && $remit->amount > $userPaymentConfig->remit_quota_pertime){
            throw new OperationFailureException($remit->order_no." 超过账户单笔限额:".$userPaymentConfig->remit_quota_pertime,Macro::ERR_REMIT_REACH_ACCOUNT_QUOTA_PER_TIME);
        }
        //检测账户日限额
        if($userPaymentConfig->remit_quota_perday
            && (($remit->remit_today+$remit->amount) > $userPaymentConfig->remit_quota_perday)
        ){
            throw new OperationFailureException($remit->order_no." 超过账户日限额:".$userPaymentConfig->remit_quota_perday.',当前已使用:'.$remit->remit_today,Macro::ERR_REMIT_REACH_ACCOUNT_QUOTA_PER_DAY);
        }
        //检测是否支持api出款
        if(empty($remit->op_uid) && $userPaymentConfig->allow_api_remit==UserPaymentInfo::ALLOW_API_REMIT_NO){
            throw new OperationFailureException(null,Macro::ERR_PAYMENT_API_NOT_ALLOWED);
        }
        //检测是否支持手工出款
        elseif(!empty($remit->op_uid) && $userPaymentConfig->allow_manual_remit==UserPaymentInfo::ALLOW_MANUAL_REMIT_NO){
            throw new OperationFailureException(null.$userPaymentConfig->remit_quota_pertime,Macro::ERR_PAYMENT_MANUAL_NOT_ALLOWED);
        }

        //渠道费率检测
        if(!$feeCanBeZero && $paymentChannelAccount->remit_fee <= 0){
            throw new OperationFailureException($remit->order_no." 通道出款费率不能设置为0:".Macro::ERR_CHANNEL_FEE_CONFIG);
        }
        //检测渠道单笔最低限额
        if($paymentChannelAccount->min_remit_pertime && $remit->amount < $paymentChannelAccount->min_remit_pertime){
            throw new OperationFailureException("单笔最低限额为:".bcadd(0,$paymentChannelAccount->min_remit_pertime,2));
        }
        //检测渠道单笔限额
        if($paymentChannelAccount->remit_quota_pertime && $remit->amount > $paymentChannelAccount->remit_quota_pertime){
            throw new OperationFailureException($remit->order_no." 超过渠道单笔限额:".$paymentChannelAccount->remit_quota_pertime,Macro::ERR_REMIT_REACH_CHANNEL_QUOTA_PER_TIME);
        }
        //检测渠道日限额
        if($paymentChannelAccount->remit_quota_perday
            && (($paymentChannelAccount->remit_today+$remit->amount) > $paymentChannelAccount->remit_quota_perday)
        ){
            throw new OperationFailureException($remit->order_no." 超过渠道日限额:".$paymentChannelAccount->remit_quota_perday.',当前已使用:'.$paymentChannelAccount->remit_today,Macro::ERR_REMIT_REACH_CHANNEL_QUOTA_PER_DAY);
        }
    }

    /*
     * 订单分润
     */
    static public function bonus(Remit &$remit)
    {
        Yii::info(__CLASS__ . ':' . __FUNCTION__ . ' ' . $remit->order_no);
        if ($remit->financial_status === Remit::FINANCIAL_STATUS_SUCCESS) {
            Yii::warning(__FUNCTION__ . ' remit has been bonus,will return, ' . $remit->order_no);
            Yii::info(print_r(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS),true));
            return $remit;
        }

        //所有上级代理UID
        $parentIds = $remit->merchant->getAllParentAgentId();
        //从自己开始算
        $parentIds[] = $remit->merchant->id;

        bcscale(9);
        $parentRemitConfig = $remit->getAllParentRemitConfig();
        $parentRemitConfigMaxIdx = count($parentRemitConfig)-1;
        for($i=$parentRemitConfigMaxIdx; $i>=0; $i--){
            $remitConfig = $parentRemitConfig[$i];
            Yii::info(["remit bonus, find config",json_encode($remitConfig)]);

            $pUser      = User::findActive($remitConfig['merchant_id']);

            //有上级的才返
            if ($remitConfig['fee_rebate']<=0) {
                Yii::info(["remit bonus, parent fee empty", $pUser->id, $pUser->username,$remitConfig['fee_rebate']]);
                continue;
            }

            //没有上级可以直接中断了
            if (!$pUser->parentAgent) {
                Yii::info(["remit bonus, has no parent", $pUser->id, $pUser->username]);
                break;
            }

            //有上级的才返，余额操作对象是上级代理
            Yii::info(["remit bonus parent", $pUser->id, $pUser->username, $remitConfig['fee_rebate'],$pUser->parentAgent->id, $pUser->parentAgent->username]);
            $logicUser   = new LogicUser($pUser->parentAgent);
            $logicUser->changeUserBalance($remitConfig['fee_rebate'], Financial::EVENT_TYPE_REMIT_BONUS, $remit->order_no, $remit->amount,
                Yii::$app->request->userIP??'');
        }

        //更新订单账户处理状态
        $remit->financial_status = Remit::FINANCIAL_STATUS_SUCCESS;
        $remit->save();

        return $remit;
    }

    /*
     * 提款扣款
     */
    static public function deduct(Remit &$remit){
        Yii::info(__CLASS__.':'.__FUNCTION__.' '.$remit->order_no);
        //账户余额扣款
        if($remit->status == Remit::STATUS_NONE){
            try{
                $balanceNeed = bcadd($remit->amount,$remit->remit_fee);
                if($remit->merchant->balance < $balanceNeed){
                    throw new \Exception("账户余额不足：需要{$balanceNeed}，当前余额{$remit->merchant->balance}");
                }

                //账户扣款
                $logicUser = new LogicUser($remit->merchant);
                $amount =  0-$remit->amount;
                $ip = Yii::$app->request->userIP??'';
                $logicUser->changeUserBalance($amount, Financial::EVENT_TYPE_REMIT, $remit->order_no, $remit->amount, $ip);
                //手续费
                $amount =  0-$remit->remit_fee;
                $logicUser->changeUserBalance($amount, Financial::EVENT_TYPE_REMIT_FEE, $remit->order_no, $remit->amount, $ip);

                $remit->status = Remit::STATUS_DEDUCT;

                if ($remit->type == Remit::TYPE_API && $remit->amount <= $remit->userPaymentInfo->allow_api_fast_remit) {
                    $remit->status = Remit::STATUS_CHECKED;
                }
                if ($remit->type == Remit::TYPE_BACKEND && $remit->amount <= $remit->userPaymentInfo->allow_manual_fast_remit) {
                    $remit->status = Remit::STATUS_CHECKED;
                }
                $remit->bank_ret = $remit->bank_ret.date('Ymd H:i:s')." 账户已扣款\n";
                $remit->save();

                return $remit;
            }catch (\Exception $ex){
                $remit->status = Remit::STATUS_REFUND;
                $remit->bank_status =  Remit::BANK_STATUS_FAIL;
                $remit->save();
                $remit->bank_ret = $remit->bank_ret.date('Ymd H:i:s')." 账户扣款失败:".$ex->getMessage()."\n";
                self::updateToRedis($remit);

                throw $ex;
            }

            return $remit;
        }else{
            throw new OperationFailureException('订单状态不为'.Remit::STATUS_NONE.'，无法扣款 '.$remit->order_no);
        }
    }

    /*
     * 提交提款请求到银行
     */
    static public function commitToBank(Remit &$remit){
        Yii::info(__CLASS__.':'.__FUNCTION__.' '.$remit->order_no);
        //接口日志埋点
        Yii::$app->params['apiRequestLog'] = [
            'event_id'=>$remit->order_no,
            'event_type'=> LogApiRequest::EVENT_TYPE_OUT_REMIT_ADD,
            'merchant_id'=>$remit->channel_merchant_id,
            'merchant_name'=>$remit->channelAccount->merchant_account,
            'channel_account_id'=>$remit->channel_account_id,
            'channel_name'=>$remit->channelAccount->channel_name,
        ];
        //账户未扣款的先扣款
        if($remit->status == Remit::STATUS_NONE){
            $remit = self::deduct($remit);
        }

        if($remit->status == Remit::STATUS_CHECKED){
            Yii::info('commit_to_bank_times '.$remit->order_no.' '.$remit->commit_to_bank_times);
            //最大出款提交次数检测
            if($remit->commit_to_bank_times>=self::MAX_TIME_COMMIT_TO_BANK){
                $remit->status = Remit::STATUS_NOT_REFUND;
                $remit->bank_status =  Remit::BANK_STATUS_FAIL;
                $remit->bank_ret = $remit->bank_ret.date('Ymd H:i:s')." 超过银行最大提交次数:".self::MAX_TIME_COMMIT_TO_BANK."\n";

                return $remit;
            }

            //刷新提交次数
            $remit->updateCounters(['commit_to_bank_times' => 1]);

            //提交到银行
            //银行状态说明：00处理中，04成功，05失败或拒绝
            $payment = new ChannelPayment($remit, $remit->channelAccount);
            try{
                $ret = $payment->remit();
            }catch (\Exception $e){
                $ret = BasePayment::REMIT_RESULT;
                $ret['status'] = Macro::INTERNAL_SERVER_ERROR;
                $ret['message'] = $e->getMessage();
            }

            Yii::info('remit commitToBank ret: '.$remit->order_no.' '.json_encode($ret,JSON_UNESCAPED_UNICODE));
            $remit->last_commit_to_bank_at = time();
            if($ret['status'] === Macro::SUCCESS){
                switch ($ret['data']['bank_status']){
                    case Remit::BANK_STATUS_PROCESSING:
                        $remit->status = Remit::STATUS_BANK_PROCESSING;
                        $remit->bank_status =  Remit::BANK_STATUS_PROCESSING;
                        break;
                    case Remit::BANK_STATUS_SUCCESS:
                        $remit->status = Remit::STATUS_SUCCESS;
                        $remit->bank_status =  Remit::BANK_STATUS_SUCCESS;
                        $remit->remit_at =  time();
                        break;
                    case  Remit::BANK_STATUS_FAIL:
                        $remit->status = Remit::STATUS_NOT_REFUND;
                        $remit->bank_status =  Remit::BANK_STATUS_FAIL;
                        $remit->bank_ret.date('Ymd H:i:s').'银行提交失败:'.($ret['message']??'上游无返回');
                        $remit->fail_msg = date('Ymd H:i:s').'银行提交失败:'.($ret['message']??'上游无返回');
                        break;
                    default:
                        throw new OperationFailureException('错误的银行返回值:'.$remit->order_no.' '.$ret['data']['bank_status']);
                        break;
                }

                if(!empty($ret['data']['channel_order_no']) && empty($remit->channel_order_no)){
                    $remit->channel_order_no = $ret['data']['channel_order_no'];
                }

                if($remit->bank_status != Remit::BANK_STATUS_FAIL){
                    $remit->bank_ret = $remit->bank_ret.date('Ymd H:i:s')." 已提交到银行\n";
                }
            }
            //提交失败订单标记为失败未退款，银行状态为处理中
            else{
                $remit->status = Remit::STATUS_NOT_REFUND;
                $remit->bank_status =  Remit::BANK_STATUS_PROCESSING;
                $remit->bank_ret = $remit->bank_ret.date('Ymd H:i:s').' 银行提交失败，请手工处理('.($ret['message']??'上游无返回').")\n";
                if($ret['message'] && strpos(strtolower($ret['message']),'curl')!=='false'){
                    $ret['message'] = '网络超时错误';
                }
                $remit->fail_msg = '银行提交失败:'.($ret['message']??'上游无返回');
            }

            $remit->save();

            if($ret['status'] === Macro::ERR_THIRD_CHANNEL_BALANCE_NOT_ENOUGH){
                Yii::error("上游渠道:{$remit->channelAccount->channel_name},商户ID:{$remit->channelAccount->merchant_id}余额不足！");
            }

            return $remit;

        }else{
            Yii::error(__CLASS__.':'.__FUNCTION__.' '.$remit->order_no." 订单状态错误，无法提交到银行:".$remit->status);
            throw new OperationFailureException('订单状态错误，无法提交到银行');
        }
    }

    static public function queryChannelRemitStatus(Remit &$remit){
        Yii::info(__CLASS__ . ':' . __FUNCTION__ . ' ' . $remit->order_no);
        if($remit->status == Remit::STATUS_SUCCESS
            || $remit->status == Remit::STATUS_REFUND
            || $remit->status == Remit::STATUS_NOT_REFUND
            || $remit->bank_status == Remit::BANK_STATUS_SUCCESS
        ){
            Yii::info("remit {$remit->order_no}(status $remit->status) is already success, will not queryChannelRemitStatus");
            return $remit;
        }
        //接口日志埋点
        Yii::$app->params['apiRequestLog'] = [
            'event_id'=>$remit->order_no,
            'event_type'=> LogApiRequest::EVENT_TYPE_OUT_REMIT_QUERY,
            'merchant_id'=>$remit->channel_merchant_id,
            'merchant_name'=>$remit->channelAccount->merchant_account,
            'channel_account_id'=>$remit->channel_account_id,
            'channel_name'=>$remit->channelAccount->channel_name,
        ];
        $paymentChannelAccount = $remit->channelAccount;
        $payment = new ChannelPayment($remit, $paymentChannelAccount);
        $ret = $payment->remitStatus();

        Yii::info('remit status check: '.json_encode($ret,JSON_UNESCAPED_UNICODE));
        $remit = self::processRemitQueryStatus($ret);

        return $remit;
    }

    /**
     * 根据订单查询结果对订单做相应处理
     *
     * @param array $remitRet app\lib\payment\channels\BasePayment::REMIT_QUERY_RESULT
     * @return mixed
     * @throws OperationFailureException
     */
    static public function processRemitQueryStatus($remitRet){
        Yii::info(__CLASS__ . ':' . __FUNCTION__ . ' ' . json_encode($remitRet));

        if(
            isset($remitRet['data']['bank_status'])
            && !empty($remitRet['data']['remit'])
        ){
            if($remitRet['data']['remit']->status == Remit::STATUS_SUCCESS
              || $remitRet['data']['remit']->status == Remit::STATUS_REFUND
              || $remitRet['data']['remit']->status == Remit::STATUS_NOT_REFUND
              || $remitRet['data']['remit']->bank_status == Remit::BANK_STATUS_SUCCESS
            ){
                Yii::info("remit {$remitRet['data']['remit']->order_no}(status {$remitRet['data']['remit']->status}) is already success, will not processRemitQueryStatus");
                return $remitRet['data']['remit'];
            }

            if($remitRet['status'] === Macro::SUCCESS){
                switch ($remitRet['data']['bank_status']){
                    case Remit::BANK_STATUS_PROCESSING:
                        $remitRet['data']['remit']->status = Remit::STATUS_BANK_PROCESSING;
                        $remitRet['data']['remit']->bank_status =  Remit::BANK_STATUS_PROCESSING;
//                      //$remitRet['data']['remit']->bank_ret.=date('Ymd H:i:s')." 银行处理中"."\n";
                        break;
                    case Remit::BANK_STATUS_SUCCESS:
                        if(!empty($remitRet['data']['amount'])
                            && bccomp($remitRet['data']['amount'],$remitRet['data']['remit']->amount,2)!==0
                        ){
                            $remitRet['data']['remit']->status = Remit::STATUS_BANK_PROCESSING;//Remit::STATUS_NOT_REFUND;
                            $remitRet['data']['remit']->bank_status =  Remit::BANK_STATUS_PROCESSING;//Remit::BANK_STATUS_FAIL;
                            $msg = date('Y-m-d H:i:s')." 实际出款金额({$remitRet['data']['amount']})与订单金额({$remitRet['data']['remit']->amount})不符合，请手工确认。\n";
                            Yii::error($remitRet['data']['remit']->order_no.' '.$msg);
                            if(strpos($remitRet['data']['remit']->fail_msg,"实际出款金额({$remitRet['data']['amount']})与订单金额")===false){
                                $remitRet['data']['remit']->fail_msg .= $msg;
                                $remitRet['data']['remit']->bank_ret .= $msg;
                            }
                        }else{
                            $remitRet['data']['remit']->status = Remit::STATUS_SUCCESS;
                            $remitRet['data']['remit']->bank_status =  Remit::BANK_STATUS_SUCCESS;
                            $remitRet['data']['remit']->remit_at =  time();
                        }
                        break;
                    case  Remit::BANK_STATUS_FAIL:
                        $remitRet['data']['remit']->status = Remit::STATUS_NOT_REFUND;
                        $remitRet['data']['remit']->bank_status =  Remit::BANK_STATUS_FAIL;
                        if($remitRet['message']){
                            $remitRet['data']['remit']->bank_ret = date('Y-m-d H:i:s').' '.$remitRet['message']."\n";
                            $remitRet['data']['remit']->fail_msg = date('Y-m-d H:i:s').' '.$remitRet['message'];
                        }
                        break;
                }

                if(!empty($ret['data']['channel_order_no']) && empty($remitRet['data']['remit']->channel_order_no)){
                    $remitRet['data']['remit']->channel_order_no = $ret['data']['channel_order_no'];
                }

                $remitRet['data']['remit']->save();

                if($remitRet['data']['remit']->bank_status == Remit::BANK_STATUS_SUCCESS){
                    self::afterSuccess($remitRet['data']['remit']);
                }

                self::updateToRedis($remitRet['data']['remit']);

                return $remitRet['data']['remit'];
            }
        }else{
//            Yii::warning(__CLASS__ . ':' . __FUNCTION__ . ' error ret:' . json_encode($remitRet));
            throw new OperationFailureException('订单查询结果错误');
        }
    }

    static public function refund(&$remit, $reason = ''){
        Yii::info(__CLASS__ . ':' . __FUNCTION__ . ' ' . $remit->order_no);
        if(
            $remit->status == Remit::STATUS_BANK_PROCESS_FAIL
            || $remit->status == Remit::STATUS_BANK_NET_FAIL
            || $remit->status == Remit::STATUS_NOT_REFUND
            || $remit->status == Remit::STATUS_CHECKED
            || $remit->status == Remit::STATUS_DEDUCT
        ){
            //退回账户扣款
            $logicUser = new LogicUser($remit->merchant);
            $amount =  $remit->amount;
            $ip = Yii::$app->request->userIP??'';
            $logicUser->changeUserBalance($amount, Financial::EVENT_TYPE_REFUND_REMIT, $remit->order_no, $remit->amount,$ip);

            //退回手续费
            $amount =  $remit->remit_fee;
            $logicUser->changeUserBalance($amount, Financial::EVENT_TYPE_REFUND_REMIT_FEE, $remit->order_no, $remit->amount, $ip);

            //退回分润
            //!!!!不需退回，因为目前为成功后才分润
//            $parentRebate = Financial::findAll(['event_id'=>$remit->id,'event_type'=>Financial::EVENT_TYPE_REMIT_BONUS,'status'=>Financial::STATUS_FINISHED]);
//            foreach ($parentRebate as $pr){
//                $logicUser->changeUserBalance((0-$remit->amount), Financial::EVENT_TYPE_REFUND_REMIT_BONUS,$remit->order_no, $remit->amount, $ip, $reason);
//            }

            $remit->status = Remit::STATUS_REFUND;
            $remit->bank_ret.=date('Ymd H:i:s')." 订单失败已退款"."\n";
            $remit->save();

            return $remit;
        }else{
            Yii::error([__CLASS__.':'.__FUNCTION__,$remit->order_no,"订单状态错误，无法退款:".$remit->status]);
            throw new OperationFailureException('订单状态错误，无法退款:'.$remit->status);
        }
    }

    static public function generateRemitNo($remitData){
        return '2'.date('ymdHis').mt_rand(10000,99999);
    }

    static public function generateMerchantRemitNo(){
        return 'Rsys'.date('ymdHis').mt_rand(10000,99999);
    }

    static public function generateBatRemitNo(){
        return 'RB'.date('ymdHis').mt_rand(10000,99999);
    }

    static public function getRemitByRemitNo($orderNo){
        $order = Remit::findOne(['order_no'=>$orderNo]);
        if(empty($order)){
            throw new InValidRequestException('订单不存在');
        }
        return $order;
    }

    /*
     * 订单成功
     *
     * @param Remit $remit 订单对象
     */
    public static function setSuccess(Remit &$remit, $opUid=0, $opUsername='',$bak='')
    {
        Yii::info(__CLASS__ . ':' . __FUNCTION__ . ' ' . $remit->order_no);

        $remit->status = Remit::STATUS_SUCCESS;
        $remit->bank_status =  Remit::BANK_STATUS_SUCCESS;
        $remit->remit_at =  time();
        if($opUsername) $bak.=date('Ymd H:i:s')." {$opUsername} 设置为成功状态\n";
        $remit->bak .=$bak;
        $remit->bank_ret.=date('Ymd H:i:s')." 管理员设置为成功状态\n";
        $remit->save();

        self::afterSuccess($remit);

        self::updateToRedis($remit);

        return $remit;
    }

    /*
     * 订单成功后续处理事件
     *
     * @param Remit $remit 订单对象
     */
    public static function afterSuccess(Remit &$remit)
    {
        //出款分润
        self::bonus($remit);

        //更新用户及渠道当天充值计数
        self::updateTodayQuota($remit);

        $remit->bank_ret.=date('Ymd H:i:s')." 出款已成功"."\n";
        $remit->save();
    }


    /*
     * 更新订单对应商户及通道的当日金额计数
     *
     * @param Remit $remit 订单对象
     */
    static public function updateTodayQuota(Remit $remit){
        $remit->merchant->paymentInfo->updateCounters(['remit_today' => $remit->amount]);
        $remit->channelAccount->updateCounters(['remit_today' => $remit->amount]);
    }

    /*
     * 设置订单失败并退款
     *
     * @param Remit $remit 订单对象
     * @param String $failMsg 失败描述信息
     */
    public static function setFailAndRefund(Remit &$remit, $failMsg='', $opUid=0, $opUsername='')
    {
        Yii::info(__CLASS__ . ':' . __FUNCTION__ . ' ' . $remit->order_no);

        self::setFail($remit, $failMsg, $opUid, $opUsername);

        $remit = self::refund($remit);

        return $remit;
    }

    /*
     * 设置订单失败
     *
     * @param Remit $remit 订单对象
     * @param String $failMsg 失败描述信息
     */
    public static function setFail(Remit &$remit, $failMsg='', $opUid=0, $opUsername='')
    {
        Yii::info(__CLASS__ . ':' . __FUNCTION__ . ' ' . $remit->order_no);

        if($failMsg) $remit->fail_msg = $remit->fail_msg."; ".date('Ymd H:i:s').' '.$failMsg;
        $remit->status = Remit::STATUS_BANK_PROCESS_FAIL;
        $remit->bank_status =  Remit::BANK_STATUS_FAIL;
        if($opUsername) $failMsg=date('Ymd H:i:s')." {$opUsername}设置为失败状态\n";
        $remit->bak .=$failMsg;
        if($opUsername){
            $remit->bank_ret .=date('Ymd H:i:s')." 管理员设置为失败状态\n";
        }else{
            $remit->bank_ret .=date('Ymd H:i:s')." 订单已失败：{$failMsg}\n";
        }

        $remit->save();

        self::updateToRedis($remit);

        return $remit;
    }

    /*
     * 订单更新为已审核
     *
     * @param Remit $remit 订单对象
     * @param String $failMsg 失败描述信息
     */
    public static function setChecked(Remit &$remit, $opUid=0, $opUsername='')
    {
        Yii::info(__CLASS__ . ':' . __FUNCTION__ . ' ' . $remit->order_no);

        //账户未扣款的先扣款
        if($remit->status == Remit::STATUS_NONE){
            $remit = self::deduct($remit);
        }

        $remit->status = Remit::STATUS_CHECKED;
        if($opUsername) $bak=date('Ymd H:i:s')." {$opUsername}审核通过\n";
        $remit->bak .=$bak;
        $remit->save();

        self::updateToRedis($remit);

        return $remit;
    }

    /**
     * 更新订单信息到redis
     *
     * @param Remit $remit 订单对象
     * @return
     */
    public static function updateToRedis(Remit $remit)
    {
        $data = [
            'merchant_order_no'=>$remit->merchant_order_no,
            'order_no'=>$remit->order_no,
            'bank_status'=>$remit->bank_status,
            'fail_msg'=>$remit->fail_msg,
        ];
        $json = \GuzzleHttp\json_encode($data);
        Yii::$app->redis->hmset(self::REDIS_CACHE_KEY, $remit->merchant_id.'-'.$remit->merchant_order_no, $json);
    }

    /**
     * 获取订单状态
     *
     */
    public static function getStatus($orderNo = '',$merchantOrderNo = '', User $merchant)
    {
        $statusJson = Yii::$app->redis->hmget(self::REDIS_CACHE_KEY, $merchant->id.'-'.$merchantOrderNo);

        $statusArr = [];
        if(!empty($statusJson[0])){
            $statusArr = json_decode($statusJson[0], true);
        }

        if(!$statusArr){
            if(!$merchantOrderNo && $orderNo){
                $statusArr = (new Query())->select(['order_no','merchant_id','merchant_order_no','bank_status'])
                    ->andWhere(['order_no'=>$orderNo,'merchant_id'=>$merchant->id])
                    ->from(Remit::tableName())
                    ->one();
            }elseif($merchantOrderNo && !$orderNo){
                $statusArr = (new Query())->select(['order_no','merchant_id','merchant_order_no','bank_status'])
                    ->andWhere(['merchant_order_no'=>$merchantOrderNo,'merchant_id'=>$merchant->id])
                    ->from(Remit::tableName())
                    ->one();
            }
        }

        //接口日志埋点
        Yii::$app->params['apiRequestLog'] = [
            'event_id'=>$merchantOrderNo?$merchantOrderNo:$orderNo,
            'event_type'=> LogApiRequest::EVENT_TYPE_IN_REMIT_QUERY,
            'merchant_id'=>$merchant->id,
            'merchant_name'=>$merchant->username,
            'channel_account_id'=>Yii::$app->params['merchantPayment']->remitChannel->id,
            'channel_name'=>Yii::$app->params['merchantPayment']->remitChannel->channel_name,
        ];

        if(!$statusArr){
            throw new OperationFailureException("订单不存在('platform_order_no:{$orderNo}','merchant_order_no:{$merchantOrderNo}')");
        }

        return $statusArr;
    }

    static public function getOrderByOrderNo($orderNo){
        $order = Remit::findOne(['order_no'=>$orderNo]);
        if(empty($order)){
            throw new InValidRequestException('订单不存在');
        }
        return $order;
    }

    /**
     * 当前是否可以提交到银行
     *
     * @param $remit
     * @return boolean
     * @author bootmall@gmail.com
     */
    public static function canCommitToBank($remit = null)
    {
        $enable = SiteConfig::cacheGetContent('enable_remit_commit');
        return $enable==1;
    }

    /*
     * 生成通知参数
     */
    static public function createNotifyParameters(Remit $remit){
        $arrParams = [
            'merchant_code'=>$remit->merchant_id,
            'order_no'=>$remit->merchant_order_no,
            'order_amount'=>$remit->amount,
            'order_time'=>$remit->created_at,
            'trade_no'=>$remit->order_no,
            'bank_status'=>self::RESP_BANK_STATUS[$remit->bank_status]??'error:'.$remit->bank_status,
        ];

        $signType = Yii::$app->params['paymentGateWayApiDefaultSignType'];
        $key = $remit->merchant->paymentInfo->app_key_md5;
        $arrParams['sign'] = SignatureHelper::calcSign($arrParams, $key, $signType);

        return $arrParams;
    }


    /*
     * 异步通知商户
     */
    static public function notify(Remit $order){
        Yii::trace((new \ReflectionClass(__CLASS__))->getShortName().'-'.__FUNCTION__.' '.$order->order_no);
        if(!$order->notify_url
            || $order->status != Remit::STATUS_SUCCESS
        ){
            return true;
        }

        $arrParams = self::createNotifyParameters($order);
        $job = new RemitNotifyJob([
            'orderNo'=>$order->order_no,
            'url' => $order->notify_url,
            'data' => $arrParams,
        ]);
        Yii::$app->remitNotifyQueue->push($job);//->delay(10)
    }

    /*
     * 更新通知结果
     */
    static public function updateNotifyResult($orderNo, $retCode, $retContent){
        $order = self::getOrderByOrderNo($orderNo);
        if(!$order){
            throw new OperationFailureException("remit updateNotifyResult 订单不存在：{$orderNo}");
        }

        $order->notify_at = time();
        $order->notify_status = $retCode;
        $order->notify_ret = $retContent;
        $order->next_notify_time = time()+self::NOTICE_DELAY;

        $bak=date('Ymd H:i:s')."出款结果通知商户：{$retContent}({$retCode})\n";
        $order->bak .=$bak;
        $order->bank_ret.=$bak;

        $order->save();
        $order->updateCounters(['notify_times' => 1]);

        return $order;
    }

}