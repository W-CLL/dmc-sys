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

        $key_words = $key_words_model->field('tag_id, keyword, sort')->order('sort desc')->select();
        $product_ids = array_keys($data);
        $product_adv_map = $obj_product_model->whereIn('product_id', $product_ids)->column('adv_id', 'product_id');

        // 预处理所有关键词，构建高效的匹配模式
        $keyword_patterns = [];
        $tag_sort_map = [];
        foreach ($key_words as $item) {
            $tag_id = $item['tag_id'];
            $keywords = array_filter(explode(',', $item['keyword'])); // 过滤空值
            if (!empty($keywords)) {
                $keyword_patterns[$tag_id] = $keywords;
                $tag_sort_map[$tag_id] = $item['sort'];
            }
        }

        $product_results = []; // 存储每个商品的检测结果
        $now = time();

        // 对每个商品进行全面检测
        foreach ($data as $product_id => $product_name) {
            $adv_id = $product_adv_map[$product_id] ?? null;
            if (!$adv_id) continue;

            $all_hits = []; // 存储该商品所有命中的关键词
            $highest_tag = 0;
            $highest_sort = 0;

            // 检测所有关键词组
            foreach ($keyword_patterns as $tag_id => $keywords) {
                $hit = $this->findMatchedKeyword($product_name, $keywords);
                if ($hit) {
                    $all_hits[] = $hit;
                    // 记录最高权重的标签
                    if ($tag_sort_map[$tag_id] > $highest_sort) {
                        $highest_sort = $tag_sort_map[$tag_id];
                        $highest_tag = $tag_id;
                    }
                }
            }

            // 如果有命中，记录结果
            if (!empty($all_hits)) {
                // 合并所有命中的关键词并去重
                $all_keywords = [];
                foreach ($all_hits as $hit) {
                    $keywords = explode(',', $hit);
                    $all_keywords = array_merge($all_keywords, $keywords);
                }
                $unique_keywords = array_unique(array_filter($all_keywords));

                $product_results[$product_id] = [
                    'tag_id' => $highest_tag,
                    'keywords' => implode(',', $unique_keywords)
                ];
            }
        }

        // 批量更新数据库
        if (!empty($product_results)) {
            $this->batchUpdateProducts($product_results, $now);
        }

        return true;
    }

    /**
     * 批量更新商品风险信息
     */
    private function batchUpdateProducts($product_results, $now)
    {
        // 按标签分组进行批量更新
        $tag_groups = [];
        foreach ($product_results as $product_id => $result) {
            $tag_id = $result['tag_id'];
            $tag_groups[$tag_id][] = [
                'product_id' => $product_id,
                'keywords' => $result['keywords']
            ];
        }

        foreach ($tag_groups as $tag_id => $items) {
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
    }



    protected function findMatchedKeyword($str, $keywords)
    {
        if (empty($keywords)) {
            return false;
        }

        // 过滤空关键词并去重
        $keywords = array_unique(array_filter($keywords, function($keyword) {
            return !empty(trim($keyword));
        }));

        if (empty($keywords)) {
            return false;
        }

        // 构建正则表达式，使用非捕获组提高性能
        $escaped_keywords = array_map('preg_quote', $keywords);
        $pattern = '/(' . implode('|', $escaped_keywords) . ')/iu'; // 添加u修饰符支持UTF-8

        $matches = [];
        if (preg_match_all($pattern, $str, $matches, PREG_SET_ORDER)) {
            $found_keywords = [];
            foreach ($matches as $match) {
                $found_keywords[] = $match[1];
            }
            return implode(',', array_unique($found_keywords));
        }

        return false;
    }


}
