<?php

namespace app\job\fission;

use app\common\model\viral_fission\FissionMaterialTask;
use think\Queue;
use app\job\fission\BaseJob;

/**
 * 裂变完成后的队列
 */
class  Fission extends BaseJob
{

    public function __construct()
    {
        $this->queueRecordModelName = '\app\common\model\Queue';
    }

    /**
     * 实现具体的任务逻辑
     */
    protected function doJob($data): bool
    {

        $callback_data = $data['data'];

        if ($callback_data['service_label'] == 'hot_material_task_result') {
            $this->hotMaterialHandle($data);

        }
        return true;
    }

    protected function getJobName(): string
    {
        return '裂变任务结果处理';
    }

    protected function getQueueName(): string
    {
        return 'fission';
    }

    private function hotMaterialHandle($queue_data)
    {
        $true_data = json_decode($queue_data['data']['data'], true);
        $content = json_decode($true_data['content'], true);
        $task_id = $content['task_id'];
        $status = $content['status'];
        $adv_id = $content['advertiser_id'];
        $message = $content['message'];
        $update_data = [
            'update_time' => time(),
            'fission_status' => $status,
            'fission_msg' => $message,
        ];
        $material_task = new FissionMaterialTask();
        $res = $material_task->where(['adv_id' => $adv_id, 'task_id' => $task_id])->update($update_data);
        //更新完之后，马上查询把刚裂变好的素材，加入查询状态中，因为spi返回到内容太少，没有链接，只能主动去调查询状态接口
        if ($res) {
            $list = $material_task->where([
                'status_message' => 'success',
                'is_handle' => 0,
//                'adv_id' => $adv_id,
                'task_id'=>['>',0],
                'fission_status' => ['in', ['SUCCESS', 'PART_SUCCESS']]
            ])->select();

            $chunks = [];
            foreach ($list as $item) {
                $chunks[$item['adv_id']][] = $item['task_id'];
            }
            foreach ($chunks as $adv_id=> $chunk) {
                $count = count($chunk);
                if ($count > 50) {
                    $queue_datas = array_chunk($chunks, 50, true);
                    foreach ($queue_datas as $data) {
                        Queue::push('app\job\fission\GetFissionMaterialStatus', [
                            'adv_id'=>$adv_id,
                            'task_id'=>$data,
                        ], 'getFissionMaterialStatus');
                    }
                } else {
                    Queue::push('app\job\fission\GetFissionMaterialStatus', [
                        'adv_id'=>$adv_id,
                        'task_id'=>$chunk,
                    ], 'getFissionMaterialStatus');
                }
            }
        }
    }
}