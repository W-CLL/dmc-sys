<?php

namespace app\robotapi\model;

use think\Model;

class WechatGroup extends Model
{
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    public function store()
    {
        return $this->belongsTo('Store','bind_store_id','id');
    }

    public function company()
    {
        return $this->hasMany('Company', 'store_id', 'bind_store_id');
    }

    public function wallet()
    {
        return $this->hasMany('QcShareWallet', 'bind_store_id', 'bind_store_id');
    }


    /**
     * 根据群id获取关联千川账户
     * @param $group_id
     * @param $adv_id_list
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getCompanyByStoreId($group_id, $adv_id_list)
    {
        return $this->with(['company' => function($query) use ($adv_id_list) {
            $query->whereIn('advertiser_id', $adv_id_list)
                ->where('adv_status', 1)
                ->field('id, advertiser_id, store_id, account_type, discount_percentage, agent_id');
        }])->where('group_id', $group_id)->find();
    }



    /**
     * 根据群id获取关联DMC账户余额信息
     * @param $group_id
     * @return array
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getDMCBalance($group_id){
        return $this->with(['store' => function($query) {
            $query->field('id, public_money, private_money, public_credit_limit, private_credit_limit, public_discount_percentage, private_discount_percentage');
        }])->where('group_id', $group_id)->field('bind_store_id')->find();
    }


    /**
     * 根据群id获取关联子钱包
     * @param $group_id
     * @return array
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getWalletByStoreId($group_id, $sub_wallet_id_list)
    {
        return $this->with(['wallet' => function($query) use ($sub_wallet_id_list){
            $query->whereIn('sub_wallet_id', $sub_wallet_id_list);
        }])->where('group_id', $group_id)->find();
    }


    public function getStoreId($group_id){
        return $this->where('group_id', $group_id)->value('bind_store_id');
    }


    public function updateGroup($group_id, $data){
        return $this->where('group_id', $group_id)->update($data);
    }

    public function deleteGroup(array $group_ids){
        return $this->where(['group_id'=>['in', $group_ids]])->delete();
    }


}