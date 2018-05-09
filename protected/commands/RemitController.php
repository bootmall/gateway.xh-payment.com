<?php
namespace app\commands;
use app\common\models\model\Remit;
use app\jobs\RemitCommitJob;
use app\jobs\RemitQueryJob;
use app\modules\gateway\models\logic\LogicChannelAccount;
use app\modules\gateway\models\logic\LogicRemit;
use power\yii2\log\LogHelper;
use Yii;

class RemitController extends BaseConsoleCommand
{
    public function init()
    {
        parent::init();
    }

    public function beforeAction($event)
    {
        Yii::debug('console process: '.implode(' ',$_SERVER['argv']));
        return parent::beforeAction($event);
    }

    /*
     * 检测处于银行处理状态出款订单的最新状态
     */
    public function actionCheckStatusQueueProducer(){
        $doCheck = true;
        while ($doCheck) {
            $remits = Remit::find(['status'=>Remit::STATUS_BANK_PROCESSING])->limit(100)->all();
            Yii::info('find remit to check status: '.count($remits));
            foreach ($remits as $remit){
                Yii::info('remit status check: '.$remit->order_no);
//                LogicRemit::queryChannelRemitStatus($remit);
                $job = new RemitQueryJob([
                    'orderNo'=>$remit->order_no,
                ]);
                Yii::$app->remitQueryQueue->push($job);//->delay(10)
            }

            sleep(mt_rand(5,10));
        }
    }

    /*
     * 取出已审核出款并提交到银行待提交队列
     */
    public function actionBankCommitQueueProducer(){
        $doCheck = true;
        while ($doCheck) {
            if(LogicRemit::canCommitToBank()){
                $remits = Remit::find(['status'=>Remit::STATUS_DEDUCT])->limit(100)->all();
                Yii::info('find remit to commit bank: '.count($remits));
                foreach ($remits as $remit){
                    Yii::info('BankCommitQueueProducer: '.$remit->order_no);

                    $job = new RemitCommitJob([
                        'orderNo'=>$remit->order_no,
                    ]);
                    Yii::$app->remitBankCommitQueue->push($job);//->delay(10)
                }
            }

            sleep(mt_rand(5,10));
        }
    }

    /*
     * 取出失败出款并提交到查询队列
     *
     * 某些出款渠道不稳定，失败情况下需要再次查询核实
     */
    public function actionReCheckFailQueueProducer(){
        $doCheck = true;
        while ($doCheck) {
            $remits = Remit::find(['status'=>Remit::STATUS_BANK_PROCESS_FAIL])->limit(10)->all();
            foreach ($remits as $remit){
                Yii::info('job remit ReCheckFail: '.$remit->order_no);

                $job = new RemitQueryJob([
                    'orderNo'=>$remit->order_no,
                ]);
                Yii::$app->remitQueryQueue->push($job);//->delay(10)
            }

            sleep(mt_rand(5,10));
        }
    }
}