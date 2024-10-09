<?php
/**
 *
 * 同步订单数据到crm系统
 */

namespace app\job;

use app\store\model\SyncChargeRecord;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use think\Db;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\Env;
use think\Exception;
use think\exception\DbException;
use think\exception\PDOException;
use think\Log;
use think\queue\Job;

class SyncCharge
{
    /** 备款 */
    const CHARGE_TYPE_READY = 1;

    /** 共享钱包 */
    const CHARGE_TYPE_SUB = 2;


    /**
     * fire方法是消息队列默认调用的方法
     * @param Job $job 当前的任务对象
     * @param array|mixed $data 发布任务时自定义的数据
     */
    public function fire(Job $job, $data)
    {
        $jobId = json_decode($job->getRawBody(), true)['id'];
        $queueModel = new \app\common\model\Queue();
        $queueData = $queueModel->where('job_id', $jobId)->find();
        try {
            $beforeSync = $this->beforeSync($data, $queueData);
            if ($beforeSync) {
                $job->delete();
                return;
            }
            list($buildData, $moneyLog) = $this->buildData($data, $queueData);

            $isJobDone = $this->syncCrm($buildData, $queueData, $moneyLog);
            if ($isJobDone) {
                //如果任务执行成功， 记得删除任务
                $job->delete();
            } else {
                if ($job->attempts() > 3) {
                    //通过这个方法可以检查这个任务已经重试了几次了
                    $job->delete();
                }
            }
        } catch (Exception $e) {
            $queueData->save(['id' => $queueData['id'], 'status' => 2, 'msg' => $e->getMessage()]);
            $job->delete();
        }
    }

    /**
     * 检查是否已经同步了相同订单
     * @throws DataNotFoundException
     * @throws ModelNotFoundException
     * @throws DbException
     * @throws GuzzleException
     * @throws Exception
     */
    private function beforeSync($data, $queueData): bool
    {
        $type = self::CHARGE_TYPE_READY;//
        if ($queueData['relation_table'] == 'share_wallet_transfer_log') {
            $type = self::CHARGE_TYPE_SUB;
        }
        $account = Db::name('external_accounts')->where('status', 1)->find();
        $params = [
            'app' => 'charge_controller_dmcapi',
            'act' => 'get',
            'log_id' => $data['log_id'],
            'log_type' => $type,
            'from' => Env::get('crm_config.crm_from_type'),
            'account' => $account['account']
        ];
        $rsp = buildCrmRequest($params);

        //查询crm如果已经同步了则不再同步，任务状态改成完成
        if (isset($rsp['statusCode']) && $rsp['statusCode'] == 200) {
            if (!empty($rsp['data'])) {
                $logId = $rsp['data']['extra_id'];
                $crmId = $rsp['data']['id'];
                Db::startTrans();
                try {
//                    Db::name('queue_record')->where('job_id', $queueData['job_id'])->update(['status' => 1, 'msg' => '同步订单到crm成功,log:' . $logId . "crm_id:" . $crmId])
                    $queueData->save(['status' => 1, 'msg' => '同步订单到crm成功,log_id:' . $logId . ",crm_id:" . $crmId]);
                    $syncChargeRecordModel = new SyncChargeRecord();
                    $syncRecord = $syncChargeRecordModel->where(['log_id' => $logId, 'crm_id' => $crmId])->find();
                    if (!$syncRecord) {
                        $insertData = [
                            'log_id' => $rsp['data']['extra_id'],
                            'crm_id' => $rsp['data']['id'],
                            'type' => $rsp['data']['extra_type'],
                        ];
                        $syncChargeRecordModel->save($insertData);
                    }
                    Db::commit();
                    return true;
                } catch (Exception $e) {
                    Db::rollback();
                    $queueData->save(['msg' => $e->getMessage(), 'status' => 2]);
                    return true;
                }
            }
            return false;
        }
        return false;

    }

    /**
     * @throws DataNotFoundException
     * @throws DbException
     * @throws PDOException
     * @throws ModelNotFoundException
     * @throws GuzzleException
     * @throws Exception
     */
    private function syncCrm($data, $queueData, $moneyLog)
    {
        $account = Db::name('external_accounts')->where('status', 1)->find();
        $enData = openssl_encrypt(json_encode($data), 'AES-128-ECB', $account['secret'], 0);
        $params = [
            'app' => 'charge_controller_dmcapi',
            'data' => $enData,
            'account' => $account['account'],
            'act' => 'post',
        ];
        $res = buildCrmRequest($params, 'POST');

        if (isset($res['statusCode'])) {
            if ($res['statusCode'] != 200) {

                $status = 2;
                $msg = $res['msg'] . json_encode($data);
                $queueData->save(['status' => 2, 'msg' => $res['msg'] . json_encode($data)]);
//                Db::name('queue_record')->where('job_id', $job_id)->update(['status' => 2, 'msg' => $res['msg'] . json_encode($data)]);
                Log::error('同步订单到crm失败：' . json_encode($res['msg']));
                $job_status = false;
            } else {
                $syncChargeRecordModel = new SyncChargeRecord();
                $type = self::CHARGE_TYPE_READY;
                if ($queueData['relation_table'] == 'share_wallet_transfer_log') {
                    $type = self::CHARGE_TYPE_SUB;
                }
                $insertData = [
                    'log_id' => $moneyLog['id'],
                    'crm_id' => $res['data'],
                    'type' => $type
                ];
                $record = $syncChargeRecordModel->where($insertData)->find();
                if (!$record) {
                    $syncChargeRecordModel->save($insertData);
                }
                $status = 1;
                $msg = '同步订单到crm成功,log_id:' . $moneyLog['id'] . ",crm_id:" . $res['data'];
                Log::info('同步订单到crm成功：' . json_encode($res['msg']));
                $job_status = true;
            }
        } else {
            $status = 2;
            $msg = $res . json_encode($data);
            Log::error('同步订单到crm失败：' . json_encode($res['msg']));
            $job_status = false;
        }
        $queueData->save(['status' => $status, 'msg' => $msg]);
//        Db::name('queue_record')->where('job_id', $job_id)->update(['status' => $status, 'msg' => $msg]);
        return $job_status;

    }

    /**
     * 构建数据
     * 客户名字 customer_name
     * 录单员(dmc业务员) adduser
     * 实际金额   sales_price
     * 客户返点   customer_back
     * 账号类型   account_type
     * 备注      note
     * 来源      from  dmc为1
     * 账户，千川广告id/子钱包账号      account
     */

    private function buildData($jobData, $queueData)
    {

        $transferLog = Db::name($queueData['relation_table'])
            ->alias('log')
            ->join('store s', 'log.store_id = s.id')
            ->join('store_admin_access sa', 'log.store_id = sa.store_id', 'left')
            ->join('admin a', 'sa.admin_id = a.id', 'left')
            ->where('log.id', $jobData['log_id']);

        if ($queueData['relation_table'] == 'share_wallet_transfer_log') {
            $field = 'log.money,log.account_type,log.id,log.discount_percentage,log.remark,log.sub_wallet_id, log.transfer_direction,log.create_time,
             sa.admin_id, 
             a.nickname as adduser,
             s.username';
            $transferData = $transferLog->field($field)->find();
            $account = $transferData['sub_wallet_id'];
            $note = $transferData['remark'];
            $extra_type = self::CHARGE_TYPE_SUB;//crm标识 1备款 2共享
            $addTime = $transferData['create_time'];
        } else {
            $field = 'log.money,log.account_type,log.id,log.discount_percentage,log.remark,log.advertiser_id, log.transfer_direction,log.create_time,
             sa.admin_id, 
             a.nickname as adduser,
             s.username';
            $transferData = $transferLog->field($field)->find();
            $account = $transferData['advertiser_id'];
            $note = $transferData['remark'];
            $extra_type = self::CHARGE_TYPE_READY;//crm标识 1备款 2共享
            $addTime = $transferData['create_time'];
        }
        $money = $transferData['actual_money'];
        // 如果为退款账单，则金额取负
        if ($transferData['transfer_direction'] == 2) {
            $money = -$transferData['money'];
        }

        $data['customer_name'] = $transferData['username'];
        $data['adduser'] = $transferData['adduser'];
        $data['sales_price'] = $money;
        $data['customer_back'] = $transferData['discount_percentage'];
        $data['account_type'] = $transferData['account_type'];
        $data['account'] = $account;
        $data['note'] = $note ?: '';
        $data['from'] = Env::get('crm_config.crm_from_type', 2);
        $data['addtime'] = $addTime;
        $data['extra_id'] = $transferData['id'];
        $data['extra_type'] = $extra_type;
        return [$data, $transferData];
    }


    // 消息队列执行失败后会自动执行该方法
    public function failed($data)
    {
        Log::error('消息队列达到最大重复执行次数后失败：' . json_encode($data));
    }
}
