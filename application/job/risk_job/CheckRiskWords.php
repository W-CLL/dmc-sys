<?php

namespace app\job\risk_job;

use app\admin\model\Keyword;
use app\common\model\AdvStats;
use app\common\model\ObjProduct;
use think\Db;
use think\Exception;
use think\queue\Job;


class CheckRiskWords
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
                'job_name' => '计划商品敏感词检测',
                'job_id' => $jobId,
                'class_name' => 'app\job\risk_job\CheckRiskWords',
                'queue_name' => 'checkRiskWords',
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
            $this->checkWords($data);
            return true;
        } catch (Exception $exception) {
            dump($exception->getMessage());
            throw new Exception($exception->getMessage());
        }

    }

    /**
     * 敏感词检测
     */
    protected function checkWords1($data)
    {
        $key_words_model = new Keyword();
        $obj_product_model = new ObjProduct();
        $adv_stats_model = new AdvStats();
        $key_words = $key_words_model->field('tag_id,keyword,sort')->order('sort desc')->select();
        $results = [];
        $adv_save_data = [];
        foreach ($data as $key => $product_name) {
            foreach ($key_words as $item) {
                $tag_id = $item['tag_id'];
                $key_word = $item['keyword'];
                $sort = $item['sort'];
                $arr_keywords = explode(',', $key_word);
                $is_hit = $this->findMatchedKeyword($product_name, $arr_keywords);
                $adv_id = $obj_product_model->where(['product_id' => $key])->value('adv_id');
                $risk_adv_info = $adv_stats_model->where(['adv_id' => $adv_id])->find();
                $adv_save_data[$adv_id] = [
                    'adv_id' => $adv_id,
                    'sys_tag' => $tag_id,
                    'keywords' => $is_hit,
                    'sort' => $sort
                ];
                if($risk_adv_info){
                    $adv_save_data[$adv_id]['sys_tag'] =$risk_adv_info['sys_tag'];
                    $adv_save_data[$adv_id]['sort'] = $risk_adv_info['sort'];
                }
                if ($is_hit) {
                    if (!isset($results[$tag_id])) {
                        $results[$tag_id] = [];
                    }
                    $results[$tag_id][] = [
                        'product_id' => $key,
                        'keywords' => $is_hit
                    ];
                    if ($adv_save_data[$adv_id]['sort'] < $sort) {//旧权重小于当前的重新记录标签
                        $adv_save_data[$adv_id]['sys_tag'] = $tag_id;
                    }
                    break;
                } else {
                    $adv_save_data[$adv_id]['sys_tag'] = 0;
                    $adv_save_data[$adv_id]['sort'] = 0;
                }
            }
        }

        foreach ($adv_save_data as $key => &$adv_item) {
            $info = $adv_stats_model->where(['adv_id' => $key])->find();
            if ($info) {
                $adv_item['id'] = $info['id'];
            }
        }
        $adv_stats_model->saveAll($adv_save_data);

        if ($results) {
            foreach ($results as $sys_tag => $items) {
                $productIds = array_column($items, 'product_id');
                $updates = [];
                foreach ($items as $item) {
                    $productId = (int)$item['product_id'];
                    $keyword = "'" . addslashes($item['keywords']) . "'";
                    $updates[] = "WHEN {$productId} THEN {$keyword}";
                }
                $caseSql = "CASE product_id " . implode(' ', $updates) . " END";
                $sql = "UPDATE `" . config('database.prefix') . "risk_obj_product` 
                    SET 
                        sys_tag = '{$sys_tag}', 
                        key_words = {$caseSql}, 
                        update_time = " . time() . "
                    WHERE product_id IN (" . implode(',', $productIds) . ")";

                // 执行原生 SQL 更新
                Db::execute($sql);
            }
        }
        return true;
    }

    protected function checkWords($data)
    {
        $key_words_model = new Keyword();
        $obj_product_model = new ObjProduct();

        $key_words = $key_words_model->field('tag_id, keyword, sort')->order('sort desc')  ->select();
        $product_ids = array_keys($data);
        $product_adv_map = $obj_product_model->whereIn('product_id', $product_ids)->column('adv_id', 'product_id');

        $results = [];
        $now = time();

        foreach ($data as $product_id => $product_name) {
            $adv_id = $product_adv_map[$product_id] ?? null;
            if (!$adv_id) continue;
            foreach ($key_words as $item) {
                $tag_id = $item['tag_id'];
                $keywords = explode(',', $item['keyword']);
                $hit = $this->findMatchedKeyword($product_name, $keywords);
                if ($hit) {
                    $results[$tag_id][] = [
                        'product_id' => $product_id,
                        'keywords' => $hit
                    ];
                    break; // 命中就不再检测后续关键词
                }
            }
        }
        foreach ($results as $tag_id => $items) {
            $productIds = array_column($items, 'product_id');
            $updates = [];
            foreach ($items as $item) {
                $pid = (int)$item['product_id'];
                $kw = "'" . addslashes($item['keywords']) . "'";
                $updates[] = "WHEN {$pid} THEN {$kw}";
            }

            $caseSql = "CASE product_id " . implode(' ', $updates) . " END";
            $sql = "UPDATE `" . config('database.prefix') . "risk_obj_product` 
                SET 
                    sys_tag = '{$tag_id}', 
                    key_words = {$caseSql}, 
                    update_time = {$now}
                WHERE product_id IN (" . implode(',', $productIds) . ")";
            Db::execute($sql);
        }

        return true;
    }



    protected function findMatchedKeyword($str, $keywords)
    {
        if (empty($keywords)) {
            return false;
        }
        $pattern = '/(' . implode('|', array_map('preg_quote', $keywords)) . ')/i';
        $matches = [];
        preg_match_all($pattern, $str, $matches);
        if (!empty($matches[0])) {
            return implode(';', array_unique($matches[0]));
        }
        return false;
    }


}
