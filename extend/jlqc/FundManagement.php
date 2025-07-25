<?php

namespace jlqc;

use GuzzleHttp\Client;
use Requests;
use think\Cache;

class FundManagement
{

    public static $auth_return_code = [
        '40102',//access_token已过期
        '40103',//refresh_token已过期
        '40104',//The access_token is empty.
        '40107',//refresh_token无效，请传入最新的refresh_token
        '40115'//授权码无效
    ];

    //获取账户余额
    public static function account_balance($access_token, $advertiser_id)
    {
        $url = "https://api.oceanengine.com/open_api/v1.0/qianchuan/account/balance/get/?advertiser_id=" . $advertiser_id;
        $header = array(
            'Access-Token:' . $access_token,
        );
        return Requests::get($url, $header);
    }

    //获取账户钱包详细信息
    public static function account_balance_wallet($access_token, $advertiser_id)
    {
        $url = "https://api.oceanengine.com/open_api/v1.0/qianchuan/finance/wallet/get/?advertiser_id=" . $advertiser_id;
        $header = array(
            'Access-Token:' . $access_token,
        );
        return Requests::get($url, $header);
    }

    //获取财务流水信息
    public static function finance($access_token, $advertiser_id, $start_date, $end_date, $page, $page_size)
    {
        $url = "https://ad.oceanengine.com/open_api/v1.0/qianchuan/finance/detail/get/?advertiser_id=" . $advertiser_id . "&start_date=" . $start_date . "&end_date=" . $end_date . "&page=" . $page . "&page_size=" . $page_size;
        $header = array(
            'Access-Token:' . $access_token,
        );
        return Requests::get($url, $header);
    }

    /**
     * 获取广告计划操作日志
     * @param $access_token
     * @param $params
     * ['advertiser_id'=>"广告id",
     * 'object_id'=>"操作对象ID，单条",
     * 'object_type'=>"AD",
     * 'start_date'=>"日志查询开始时间，格式 "2019-07-24 21:46:57"",
     * 'end_date'=>"日志查询结束时间，格式 "2019-07-24 21:46:57"",
     * 'page'=>"页码  * 默认值: 1"
     * 'page_size'=>"获取条数  * 默认值: 10允许值:1~20"]
     * @return mixed
     */
    public static function get_opt_log($access_token, $params)
    {
//        $base_url = "https://ad.oceanengine.com/open_api/2/tools/log_search";   // 旧接口
        $base_url = "https://api.oceanengine.com/open_api/v1.0/qianchuan/tools/log_search";    // 新接口
        $url = buildUrlWithParams($base_url, $params);
        $header = array(
            'Access-Token:' . $access_token,
            'Content-Type:' . 'application/json'
        );
        return Requests::get($url, $header);
    }

    /**
     * 获取广告计划数据(还没完善)
     * @param $access_token
     * @param $params
     * ['advertiser_id'=>"广告id",
     * 'fields'=>"需要查询的消耗指标字段，具体文档：https://open.oceanengine.com/labels/12/docs/1697466415173644,该接口有默认值可以不传",
     * 'start_date'=>"开始时间，格式 2021-04-05，开始时间不得早于今日-180天",
     * 'end_date'=>"结束时间，格式 2021-04-05
     * 若不传time_granularity，则时间跨度不能超过180天
     * 若传time_granularity为TIME_GRANULARITY_DAILY 天维度，则时间跨度不能超过30天
     * 若传time_granularity为TIME_GRANULARITY_HOURLY 小时纬度，则时间跨度不能超过7天",
     * 'filtering'=>"过滤条件,类型是object,具体看fields的文档，该接口有默认值，可以不传",
     * 'page'=>"页码  * 默认值: 1"
     * 'page_size'=>"获取条数  * 默认值: 10允许值:1~20"]
     */
    public static function get_ad_report($access_token, $params)
    {
        $params['fields'] = ['cpm_platform', 'stat_cost', 'show_cnt', 'ctr', 'click_cnt'];
        $params['filtering'] = [
            'marketing_goal' => "ALL",
            'time_granularity' => 'TIME_GRANULARITY_DAILY'
        ];
        $base_url = "https://ad.oceanengine.com/open_api/v1.0/qianchuan/report/ad/get";
        $url = buildUrlWithParams($base_url, $params);
        $header = array(
            'Access-Token:' . $access_token,
        );
        return Requests::get($url, $header);
    }

    //查询财务流水明细
    public static function fund_transaction($access_token, $advertiser_id, $start_date, $end_date, $page, $page_size, $transaction_type)
    {
        if ($transaction_type == 1) {
            //充值
            $transaction_type = "RECHARGE";
        } else {
            //转账
            $transaction_type = "TRANSFER";
        }
        $url = "https://api.oceanengine.com/open_api/2/advertiser/fund/transaction/get/?advertiser_id=" . $advertiser_id . "&start_date=" . $start_date . "&end_date=" . $end_date . "&page=" . $page . "&page_size=" . $page_size . "&transaction_type=" . $transaction_type;
        $header = array(
            'Access-Token:' . $access_token,
        );
        return Requests::get($url, $header);
    }

    //创建转账交易号（方舟）
    public static function fund_create($access_token, $agent_id, $account_id, $transfer_type, $amount)
    {
        $url = "https://api.oceanengine.com/open_api/2/agent/fund/transfer_seq/create/";
        $header = array(
            'Access-Token:' . $access_token,
            'Content-Type:application/json',
        );
        $data = array(
            "agent_id" => $agent_id,
            "account_id" => $account_id,
            "transfer_type" => $transfer_type,
            "amount" => $amount,
        );
        return Requests::post($url, $data, $header);
    }

    //提交转账交易号（方舟）
    public static function fund_commit($access_token, $transfer_seq, $agent_id)
    {
        $url = "https://api.oceanengine.com/open_api/2/agent/fund/transfer_seq/commit/";
        $header = array(
            'Access-Token:' . $access_token,
            'Content-Type:application/json',
        );
        $data = array(
            "agent_id" => $agent_id,
            "transfer_seq" => $transfer_seq,
        );
        return Requests::post($url, $data, $header);
    }

    //创建退款交易号（方舟）
    public static function refund_create($access_token, $agent_id, $account_id, $transfer_type, $amount)
    {
        $url = "https://api.oceanengine.com/open_api/2/agent/refund/transfer_seq/create/";
        $header = array(
            'Access-Token:' . $access_token,
            'Content-Type:application/json',
        );
        $data = array(
            "agent_id" => $agent_id,
            "account_id" => $account_id,
            "transfer_type" => $transfer_type,
            "amount" => $amount,
        );
        return Requests::post($url, $data, $header);
    }

    //提交退款交易号（方舟）
    public static function refund_commit($access_token, $agent_id, $refund_seq)
    {
        $url = "https://api.oceanengine.com/open_api/2/agent/refund/transfer_seq/commit/";
        $header = array(
            'Access-Token:' . $access_token,
            'Content-Type:application/json',
        );
        $data = array(
            "agent_id" => $agent_id,
            "refund_seq" => $refund_seq,
        );
        return Requests::post($url, $data, $header);
    }

    //获取最大可转余额
//    文档地址：https://open.oceanengine.com/labels/7/docs/1789754975045699
    public static function can_transfer_balance($access_token, $biz_request_no, $agent_id, $account_id, $target_account_id_list, $transfer_direction)
    {

        $header = array(
            'Access-Token:' . $access_token
        );
        if ($transfer_direction == 1) {
            //转入
            $transfer_direction = "TRANSFER_IN";
        } else {
            //转出
            $transfer_direction = "TRANSFER_OUT";
        }
        $url = "https://api.oceanengine.com/open_api/v3.0/cg_transfer/query_can_transfer_balance/?biz_request_no=" . $biz_request_no . "&agent_id=" . $agent_id . "&account_id=" . $account_id . "&target_account_id_list=" . $target_account_id_list . "&transfer_direction=" . $transfer_direction;
        return Requests::get($url, $header);
    }

    //发起转账
//    文档地址：https://open.oceanengine.com/labels/7/docs/1789755060558916
    public static function create_transfer($access_token, $biz_request_no, $agent_id, $account_id, $target_account_detail_list, $transfer_direction, $remark)
    {
        $url = "https://api.oceanengine.com/open_api/v3.0/cg_transfer/create_transfer/";
        $header = array(
            'Access-Token:' . $access_token,
            'Content-Type:application/json',
        );
        $randomStr = generate_random_string(10, true);
        $data = array(
            "biz_request_no" => $randomStr,
            "agent_id" => (int)$agent_id,
            "account_id" => (int)$account_id,
            "target_account_detail_list" => $target_account_detail_list,
            "transfer_direction" => $transfer_direction,
            "remark" => $remark,
        );
        return [Requests::post($url, json_encode($data, JSON_UNESCAPED_UNICODE), $header), $randomStr];
    }

    //查询转账单信息
    //文档地址：https://open.oceanengine.com/labels/7/docs/1789755120706634
    public static function transfer_detail($access_token, $biz_request_no, $agent_id, $transfer_serial)
    {

        $header = array(
            'Access-Token:' . $access_token
        );

        $url = "https://api.oceanengine.com/open_api/v3.0/cg_transfer/query_transfer_detail/?biz_request_no=" . $biz_request_no . "&agent_id=" . $agent_id . "&transfer_serial=" . $transfer_serial;
        return Requests::get($url, $header);
    }

    //查询账户转账余额
//    文档地址：https://open.oceanengine.com/labels/7/docs/1789754859486282
    public static function transfer_balance($access_token, $biz_request_no, $agent_id, $account_id_list)
    {

        $header = array(
            'Access-Token:' . $access_token
        );
        $url = "https://api.oceanengine.com/open_api/v3.0/cg_transfer/query_transfer_balance/?biz_request_no=" . $biz_request_no . "&agent_id=" . $agent_id . "&account_id_list=" . json_encode($account_id_list, JSON_UNESCAPED_UNICODE);
        return Requests::get($url, $header);
    }

    // 获取钱包id信息
    // https://open.oceanengine.com/labels/7/docs/1798907322782729?origin=left_nav
    public static function get_wallet_info($access_token, $account_id, $account_type)
    {

        $header = array(
            'Access-Token:' . $access_token
        );
        $url = "https://api.oceanengine.com/open_api/v3.0/shared_wallet/account_relation/get/?account_id=" . $account_id . "&account_type=" . $account_type;
        return Requests::get($url, $header);
    }

    // 获取钱包信息列表
    // https://open.oceanengine.com/labels/7/docs/1798465839055872?origin=left_nav
    public static function get_wallet_info_list($access_token, $account_id, $wallet_id_list, $account_type)
    {

        $header = array(
            'Access-Token:' . $access_token
        );
        $url = "https://api.oceanengine.com/open_api/v3.0/shared_wallet/wallet_info/get/?account_id=" . $account_id . "&wallet_id_list=" . $wallet_id_list . "&account_type=" . $account_type;
        return Requests::get($url, $header);
    }

    // 获取最大可转余额
    // https://open.oceanengine.com/labels/7/docs/1799667820221452?origin=left_nav
    public static function get_max_transfer($access_token, $account_id, $account_type, $biz_request_no, $main_wallet_id, $sub_wallet_list, $transfer_direction)
    {
        $header = array(
            'Access-Token:' . $access_token
        );
        $url = "https://api.oceanengine.com/open_api/v3.0/cg_transfer/wallet/transfer/can_transfer_balance/?account_id=" . $account_id . "&account_type=" . $account_type . "&biz_request_no=" . $biz_request_no . "&main_wallet_id=" . $main_wallet_id . "&sub_wallet_list=" . $sub_wallet_list . "&transfer_direction=" . $transfer_direction;
        return Requests::get($url, $header);
    }


    // 共享钱包转账
    // https://open.oceanengine.com/labels/7/docs/1799669807408128?origin=left_nav
    public static function wallet_transfer($access_token, $account_id, $account_type, $biz_request_no, $main_wallet_id, $target_wallet_detail_list, $transfer_direction, $remark)
    {
        $url = "https://api.oceanengine.com/open_api/v3.0/cg_transfer/wallet/transfer/create/";
        $header = array(
            'Access-Token:' . $access_token,
            'Content-Type:application/json',
        );
        $data = array(
            "account_id" => (int)$account_id,
            "account_type" => strval($account_type),
            "biz_request_no" => strval($biz_request_no),
            "main_wallet_id" => (int)$main_wallet_id,
            "target_wallet_detail_list" => $target_wallet_detail_list,
            "transfer_direction" => $transfer_direction,
            "remark" => $remark,
        );
        return Requests::post($url, json_encode($data, JSON_UNESCAPED_UNICODE), $header);
    }

    // 查询转账单信息
    public static function check_transfer_detail($access_token, $account_id, $account_type, $biz_request_no, $transfer_serial)
    {
        $header = array(
            'Access-Token:' . $access_token
        );
        $url = "https://api.oceanengine.com/open_api/v3.0/cg_transfer/wallet/transfer/detail/?account_id=" . $account_id . "&account_type=" . $account_type . "&biz_request_no=" . $biz_request_no . "&transfer_serial=" . $transfer_serial;
        return Requests::get($url, $header);
    }


    public static function get_agent_statement($access_token, $agent_id, $start_date, $end_date, $page, $page_size, $advertiser_id)
    {
        $url = "https://api.oceanengine.com/open_api/2/agent/adv/cost_report/list/query/";
        $header = array(
            'Access-Token:' . $access_token,
            'Content-Type:' . 'application/json'
        );
        $data = array(
            "agent_id" => (int)$agent_id,
            "start_date" => $start_date,
            "end_date" => $end_date,
            "page" => (int)$page,
            "page_size" => (int)$page_size,
            "order_type" => "DESC",
            "filtering" => array(
                "advertiser_ids" => array(
                    $advertiser_id
                )
            )
        );
        return Requests::post($url, json_encode($data, JSON_UNESCAPED_UNICODE), $header);
    }


    public static function get_ad_id_list($access_token, $advertiser_id, $page, $page_size)
    {
        $header = array(
            'Access-Token:' . $access_token,
        );
        $url = "https://ad.oceanengine.com/open_api/2/agent/advertiser/select/?advertiser_id=" . $advertiser_id . "&page=" . $page . "&page_size=" . $page_size;
        return Requests::get($url, $header);

    }

    public static function get_ad_info($access_token, $account_ids)
    {
        $header = array(
            'Access-Token:' . $access_token,
        );
        $url = "https://api.oceanengine.com/open_api/2/agent/advertiser_info/query/?account_ids=" . $account_ids;
        return Requests::get($url, $header);
    }


    /**
     * 获取计划详情
     * @param $access_token
     * @param $advertiser_id
     * 广告主id
     * @param $ad_id
     * 计划id
     * @param bool $creative_url
     * 是否需要获取创意素材url
     * @return mixed
     */
    public static function get_ad_detail($access_token, $advertiser_id, $ad_id, bool $creative_url = false)
    {
        $header = array(
            'Access-Token:' . $access_token,
        );
        $url = "https://ad.oceanengine.com/open_api/v1.0/qianchuan/ad/detail/get/?advertiser_id=" . $advertiser_id . "&ad_id=" . $ad_id . "&request_material_url=" . $creative_url;
        return Requests::get($url, $header);
    }


    // 广告商下的计划获取  yes！ https://open.oceanengine.com/labels/12/docs/1697467558690816?origin=left_nav
    public static function get_ad_list($access_token, $params)
    {
        $base_url = "https://ad.oceanengine.com/open_api/v1.0/qianchuan/ad/get";
        $url = buildUrlWithParams($base_url, $params);
        $header = array(
            'Access-Token:' . $access_token,
        );
        return Requests::get($url, $header);
    }


    //获取流水信息
    public static function get_flow_info($access_token, $advertiser_id, $page, $page_size, $start_date = '', $end_date = '')
    {
        $header = array(
            'Access-Token:' . $access_token,
        );
        $url = "https://ad.oceanengine.com/open_api/v1.0/qianchuan/finance/detail/get/?advertiser_id=" . $advertiser_id . "&page=" . $page . "&page_size=" . $page_size . "&start_date=" . $start_date . "&$end_date=" . $end_date;
        return Requests::get($url, $header);
    }

    /**
     * 获取千川广告账户全量信息
     * 如果包含没有访问权限的ID,将返回no permission error
     * @param array $advertiser_ids
     * @return mixed
     */
    public static function get_adv_info(array $advertiser_ids)
    {
        $header = array(
            'Access-Token:' . Cache::get("qc_access_token"),
        );

        $url = "https://ad.oceanengine.com/open_api/2/advertiser/info?advertiser_ids=" . json_encode($advertiser_ids);
//        dump($url);
//        die;
        return Requests::get($url, $header);
    }

    /**
     * 获取全域推广账户维度数据
     * @param $access_token
     * @param $params
     * [参数全部必填,lab_ad_type有默认值
     * 'advertiser_id'=>"广告id",
     * 'fields'=>"需要查询的消耗指标字段，具体文档：https://open.oceanengine.com/labels/12/docs/1770675169146947?origin=left_nav",
     * 'start_date'=>"开始时间，开始时间，格式 2021-04-05 00:00:00",
     * 'end_date'=>"结束时间，结束时间，格式 2021-04-05 23:59:59
     * 'marketing_goal'=>"按营销目标过滤，允许值:LIVE_PROM_GOODS(直播全域)  VIDEO_PROM_GOODS(商品全域)"
     * 'lab_ad_type'=>"推广方式，允许值：LAB_AD 托管"
     * ]
     * @return mixed
     */
    public static function get_global_adv_cost($access_token, $params)
    {
        $base_url = "https://api.oceanengine.com/open_api/v1.0/qianchuan/report/uni_promotion/get";
        $base_params = [
            'lab_ad_type' => 'LAB_AD',
            'fields' => [
                'stat_cost'
            ]
        ];
        $url = buildUrlWithParams($base_url, array_merge($params, $base_params));
        $header = array(
            'Access-Token:' . $access_token,
        );
        return Requests::get($url, $header);
    }


    public static function get_global_obj_list($params)
    {
        $access_token = Cache::get("qc_access_token");
        $url = "https://api.oceanengine.com/open_api/v1.0/qianchuan/uni_promotion/list/";
        $res = new Client();
        $rep = $res->get($url, [
            'headers' => [
                'Access-Token' => $access_token, // 替换为实际的 token
                'Content-Type' => 'application/json', // 可以根据需要添加其他头信息
            ],
            'query' => $params]);

        $contents = $rep->getBody()->getContents();
        return json_decode($contents, true);
    }

    public static function get_global_obj_detail($advertiser_id, $ad_id)
    {
        $access_token = Cache::get("qc_access_token");

        $header = array(
            'Access-Token:' . $access_token,
        );
        $url = "https://api.oceanengine.com/open_api/v1.0/qianchuan/uni_promotion/ad/detail/?advertiser_id=" . $advertiser_id . "&ad_id=" . $ad_id;
        return Requests::get($url, $header);
    }


    /**
     * 查询账户累计积分
     * @param $params
     * 参数参考：https://open.oceanengine.com/labels/12/docs/1809254968749066?origin=left_nav
     * @return mixed
     */
    public static function get_adv_score($params)
    {
        $access_token = Cache::get("qc_access_token");
        $url = "https://api.oceanengine.com/open_api/v3.0/security/score_total/get/";
        $params = array_merge($params, ['business_line' => "QIANCHUAN"]);
        return sendApiRes($url, $params, 'GET', ['Access-Token' => $access_token])['data'];
    }


    /**
     * 获取建议预算
     * 文档：https://open.oceanengine.com/labels/12/docs/1828257556490251?origin=left_nav
     * @param $advertiser_id
     * @param $aweme_id
     * @param $marketing_goal
     * @param $product_ids
     * @param $ad_id
     * @return mixed
     */
    public static function get_global_proposed_estimates($advertiser_id, $aweme_id, $marketing_goal, $product_ids, $ad_id)
    {
        $access_token = Cache::get("qc_access_token");
        $header = array(
            'Access-Token:' . $access_token,
        );
        $url = "https://api.oceanengine.com/open_api/v1.0/qianchuan/uni_aweme/suggest/budget/?advertiser_id=" . $advertiser_id . "&aweme_id=" . $aweme_id . "&marketing_goal=" . $marketing_goal . "&product_ids=" . json_encode($product_ids) . "&ad_id=" . $ad_id;
        return Requests::get($url, $header);
    }

    /**
     * 查询账户违规积分明细
     * @param array $params
     * 参数参考：https://open.oceanengine.com/labels/12/docs/1809254532005028?origin=left_nav
     * @return mixed
     */
    public static function get_adv_score_list(array $params)
    {
        $access_token = Cache::get("qc_access_token");
        $url = "https://api.oceanengine.com/open_api/v3.0/security/score_violation_event/get/";
        $params = array_merge($params, ['business_line' => "QIANCHUAN"]);
        return sendApiRes($url, $params, 'GET', ['Access-Token' => $access_token])['data'];
    }

    /**
     * 获取标准账户下素材列表和数据
     * @param array $params
     * 参数参考：https://open.oceanengine.com/labels/12/docs/1810701753348235?origin=left_nav
     * @return mixed
     */
    public static function get_stand_adv_material_list(array $params)
    {
        $access_token = Cache::get("qc_access_token");
        $url = "https://api.oceanengine.com/open_api/v1.0/qianchuan/material/get/";
        return sendApiRes($url, $params, 'GET', ['Access-Token' => $access_token])['data'];
    }

    /**
     * 获取全域推广计划下素材
     * @param array $params
     * advertiser_id     必填     number     千川广告账户ID
     * ad_id             必填     number     计划id
     * filtering         必填     object     过滤条件
     * 参数参考：https://open.oceanengine.com/labels/12/docs/1804363488115850
     * @return mixed
     */
    public static function get_global_adv_material_list(array $params)
    {
        $access_token = Cache::get("qc_access_token");
        $url = "https://api.oceanengine.com/open_api/v1.0/qianchuan/uni_promotion/ad/material/get/";
        return sendApiRes($url, $params, 'GET', ['Access-Token' => $access_token])['data'];
    }

    /**
     * 生成爆款裂变任务
     * @param array $params
     * @return mixed
     */
    public static function gen_material_derive_task(array $params)
    {
        $access_token = Cache::get("qc_access_token");
        $url = "https://api.oceanengine.com/open_api/v3.0/tools/hot_material_derive/submit/";
        return sendApiRes($url, $params, 'POST', ['Access-Token' => $access_token])['data'];
    }

    /**
     * 查询爆款裂变任务详情
     * @param array $params
     * @return mixed
     */
    public static function get_material_derive_task_status(array $params)
    {
        $access_token = Cache::get("qc_access_token");
        $url = "https://api.oceanengine.com/open_api/v3.0/tools/hot_material_derive/get/";
        return sendApiRes($url, $params, 'GET', ['Access-Token' => $access_token])['data'];
    }

    /**
     * 采纳裂变后的素材
     * @param array $params
     * @return mixed
     */
    public static function adopt_material(array $params)
    {
        $access_token = Cache::get("qc_access_token");
        $url = "https://api.oceanengine.com/open_api/v3.0/tools/hot_material_derive/adopt/";
        return sendApiRes($url, $params, 'POST', ['Access-Token' => $access_token])['data'];
    }






    /**
     * $params = [
     * 'advertiser_id' => 1826807488376899,
     * 'data_topic' => 'SITE_PROMOTION_PRODUCT_AD',
     * 'dimensions' => json_encode(['ad_id']),  // 有其他值，自行查询 https://open.oceanengine.com/labels/12/docs/1823296280645708
     * 'metrics' => json_encode(['stat_cost']),  // 同上
     * 'filters' => json_encode([]),   // 详情看文档
     * 'start_time' => $start_time,   // 格式为 yyyy-MM-dd HH:mm:ss
     * 'end_time' => $end_time,    // 格式为 yyyy-MM-dd HH:mm:ss
     * 'order_by' => json_encode($order_by),   // 详情看文档
     * 'page' => 1,
     * 'page_size' => 200
     * ];
     * 文档：https://open.oceanengine.com/labels/12/docs/1823297941140569?origin=left_nav
     * 获取全域数据
     */
    public static function obtain_global_data($params){
        $access_token = Cache::get("qc_access_token");
        $url = "https://api.oceanengine.com/open_api/v1.0/qianchuan/report/uni_promotion/data/get/";
        return sendApiRes($url, $params, 'GET', ['Access-Token' => $access_token])['data'];
    }


    public static function get_wallet_balance($params){
        $access_token = Cache::get("qc_access_token");
        $url = "https://api.oceanengine.com/open_api/v3.0/shared_wallet/wallet_balance/get/";
        return sendApiRes($url, $params, 'GET', ['Access-Token' => $access_token])['data'];
    }

    /**
     * 获取代理商素材详情
     * @param array $params
     * @return mixed
     */
    public static function get_agent_material_info(array $params)
    {
        $access_token = Cache::get("qc_access_token");
        $url = "https://api.oceanengine.com/open_api/2/file/video/agent/get/";
        return sendApiRes($url, $params, 'GET', ['Access-Token' => $access_token])['data'];
    }

    /**
     * 获取千川素材库视频
     * @param array $params
     * @return mixed
     */
    public static function get_adv_material_info(array $params)
    {
        $access_token = Cache::get("qc_access_token");
        $url = "https://ad.oceanengine.com/open_api/v1.0/qianchuan/video/get/";
        return sendApiRes($url, $params, 'GET', ['Access-Token' => $access_token])['data'];
    }


}







