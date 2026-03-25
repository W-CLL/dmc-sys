<?php

namespace app\job\prequalification;

use think\Cache;
use think\Db;
use think\Exception;
use think\queue\Job;

class InsOrDel
{
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
            $isJobDone = $this->doJob($data);
            if ($isJobDone) {
                if ($queueData) {
                    $queueData->save(['id' => $queueData['id'], 'status' => 1, 'msg' => "处理完成"]);
                }
                $job->delete();
            } else {
                if ($job->attempts() > 3) {
                    $job->delete();
                }
            }
        } catch (Exception $e) {
            $insert_data = [
                'job_name' => '素材状态变更处理',
                'job_id' => $jobId,
                'class_name' => 'app\job\prequalification\InsOrDel',
                'queue_name' => 'insOrDel',
                'relation_table' => '',
                'job_data' => json_encode($data),
                'remark' => '',
                'msg' => $e->getMessage(),
                'status' => 2,
            ];
            if ($queueData) {
                $queueData->save(['id' => $queueData['id'], 'status' => 2, 'msg' => $e->getMessage()]);
                $job->delete();
                return ;
            }
            $queueModel->save($insert_data);
            $job->delete();
            return ;
        }
    }


    public function doJob($data){
        $info = json_decode($data['data'],true);
        $content = json_decode($info['content'],true);
        // 不处理图片类型的素材
        if ($content['material_type'] != 'video'){
            return true;
        }
        switch ($info['event']){
            case 'CREATE':
                $subjectList = Cache::get('material_prequalification_subject_list', []);
                $special = Db::name('company')->where(['company_name' => ['in',$subjectList]])->column('advertiser_id');
                if (!in_array($info['account_id'],$special)){
                    // 如果不是关注主体内的直接不存数据库
                    return true;
                }
                $res = Db::name('material_prequalification')->where(['material_id' => ['in',$content['material_ids']]])
                    ->column('status,reason_text,object_id,video_id,filename','material_id');;
                foreach ($content['material_ids'] as $v){
                    $arr = [
                        'material_id' => $v,
                        'advertiser_id' => $info['account_id'],
                        'create_time' => time(),
                    ];
                    if (isset($res[$v])) {
                        $arr['status'] = $res[$v]['status'];
                        $arr['reason_text'] = $res[$v]['reason_text'];
                        $arr['object_id'] = $res[$v]['object_id'];
                        $arr['video_id'] = $res[$v]['video_id'];
                        $arr['filename'] = $res[$v]['filename'];
                        $arr['to_diagnosis'] = $res[$v]['to_diagnosis'];
                    }else{
                        $arr['status'] = 0;
                        $arr['reason_text'] = NULL;
                        $arr['object_id'] = NULL;
                        $arr['video_id'] = NULL;
                        $arr['filename'] = NULL;
                        $arr['to_diagnosis'] = 0;
                    }
                    $insert[] = $arr;

                }
                if (isset($insert)){
                    Db::name('material_prequalification')->insertAll($insert);
                }
                break;
            case 'DELETE':
                Db::name('material_prequalification')->where(['material_id' => ['in',$content['material_ids']]])->delete();
                break;
            default:
                break;
        }
        return true;
    }

}