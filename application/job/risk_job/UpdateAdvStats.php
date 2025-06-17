<?php

namespace app\job\risk_job;

use app\admin\model\Company;
use app\admin\model\Keyword;
use app\admin\model\Tag;
use app\common\model\AdvScore;
use app\common\model\AdvStats;
use app\common\model\ObjProduct;
use app\common\model\ScoreLog;
use think\Db;
use think\Exception;
use think\queue\Job;


class UpdateAdvStats
{

    public function fire(Job $job, $data)
    {
        $jobId = json_decode($job->getRawBody(), true)['id'];
        $queueModel = new \app\common\model\Queue();
        $queueData = $queueModel->where('job_id', $jobId)->find();
        try {
            $isJobDone = $this->doJob($data);
            if ($isJobDone) {
                if ($queueData) {
                    $queueData->save(['id' => $queueData['id'], 'status' => 1, 'msg' => "处理完成"]);
                }
                $job->delete();
                return '';
            } else {
                if ($job->attempts() > 3) {
                    $job->delete();
                    return '';
                }
            }
        } catch (Exception|\Exception $e) {
            $insert_data = [
                'job_name' => '账户风控统计',
                'job_id' => $jobId,
                'class_name' => 'app\job\risk_job\UpdateAdvStats',
                'queue_name' => 'updateAdvStats',
                'relation_table' => '',
                'job_data' => json_encode($data),
                'remark' => '',
                'msg' => $e->getMessage(),
                'status' => 2,
            ];
            if ($queueData) {
                $queueData->save(['id' => $queueData['id'], 'status' => 2, 'msg' => $e->getMessage()]);
                $job->delete();
                return '';
            }
            $queueModel->save($insert_data);
            $job->delete();
            return '';
        }
    }

    /**
     * @throws Exception
     */
    protected function doJob($data): bool
    {
        try {
            $this->saveAdvStats($data);
            return true;
        } catch (Exception $exception) {
            dump($exception->getMessage());
            throw new Exception($exception->getMessage());
        }

    }

    /**
     * 敏感词检测
     */
    protected function saveAdvStats($data)
    {
        $risk_adv_model = new AdvStats();
        $keyword_model = new Keyword();
        $tag_id = $keyword_model->order('sort desc')->column( 'tag_id');
        $advProductDb = Db::name('risk_obj_product');
        $save_data =[];
        foreach ($data as  $adv_id) {
            $keyword = '';
            $advProductDb->field('adv_id, key_words');
            foreach ($tag_id as $id ) {
                $advProductDb->field("COUNT(CASE WHEN sys_tag = {$id} THEN 1 END) AS tag_count_{$id}");
                $advProductDb->field("MAX(CASE WHEN sys_tag = {$id} THEN key_words END) AS tag_keyword_{$id}");
            }
            $result = $advProductDb->where('adv_id', $adv_id)->group('adv_id')->find();
            $sys_tag = 0;
            foreach ($tag_id as $id) {
                if ($result['tag_count_' . $id] > 0) {
                    $sys_tag = $id;
                    $keyword = $result['tag_keyword_' . $id];
                    break;
                }
            }
            $save_data[] = [
                'adv_id' => $adv_id,
                'sys_tag' => $sys_tag,
                'keywords' => $keyword,
            ];

        }
        if($save_data){
            foreach ($save_data as &$data){
                $info = $risk_adv_model->where(['adv_id'=>$data['adv_id']])->find();
                if($info){
                    $data['id'] = $info['id'];
                }
            }
            $risk_adv_model->saveAll($save_data);
        }
        return true;
    }

}
