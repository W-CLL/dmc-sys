<?php

use think\Route;

// 千川资金接口
Route::get('account-funds', 'robotapi/QcAccountFunds/get');
Route::post('account-funds', 'robotapi/QcAccountFunds/post');
Route::put('account-funds', 'robotapi/QcAccountFunds/put');
Route::delete('account-funds', 'robotapi/QcAccountFunds/delete');

// 微信群接口
Route::get('wechat-groups', 'robotapi/WechatGroups/get');
Route::post('wechat-groups', 'robotapi/WechatGroups/post');
Route::put('wechat-groups', 'robotapi/WechatGroups/put');
Route::delete('wechat-groups', 'robotapi/WechatGroups/delete');

// 同级互转接口
Route::get('peer-transfer', 'robotapi/QcPeerTransfer/get');
Route::post('peer-transfer', 'robotapi/QcPeerTransfer/post');
Route::put('peer-transfer', 'robotapi/QcPeerTransfer/put');
Route::delete('peer-transfer', 'robotapi/QcPeerTransfer/delete');

// 共享钱包接口
Route::get('shared-wallet', 'robotapi/QcSharedWallet/get');
Route::post('shared-wallet', 'robotapi/QcSharedWallet/post');
Route::put('shared-wallet', 'robotapi/QcSharedWallet/put');
Route::delete('shared-wallet', 'robotapi/QcSharedWallet/delete');

// 备款接口
Route::get('reserve', 'robotapi/Reserve/get');
Route::post('reserve', 'robotapi/Reserve/post');
Route::put('reserve', 'robotapi/Reserve/put');
Route::delete('reserve', 'robotapi/Reserve/delete');

// 企业微信接口
Route::get('work-wechat', 'robotapi/workWechat/get');
Route::post('work-wechat', 'robotapi/workWechat/post');
Route::put('work-wechat', 'robotapi/workWechat/put');
Route::delete('work-wechat', 'robotapi/workWechat/delete');

// DMC获取余额接口
Route::get('dmc-surplus', 'robotapi/DMCSurplus/get');
Route::post('dmc-surplus', 'robotapi/DMCSurplus/post');
Route::put('dmc-surplus', 'robotapi/DMCSurplus/put');
Route::delete('dmc-surplus', 'robotapi/DMCSurplus/delete');

// 备款网址
Route::get('recharge/[:token]', 'index/Reserve/index');
Route::post('recharge/[:token]', 'index/Reserve/index');


// 腾讯广告转账接口
Route::get('tencent-account', 'robotapi/TencentAccount/get');
Route::post('tencent-account', 'robotapi/TencentAccount/post');
Route::put('tencent-account', 'robotapi/TencentAccount/put');
Route::delete('tencent-account', 'robotapi/TencentAccount/delete');


// 腾讯广告共享钱包转账接口
Route::get('tencent-wallet', 'robotapi/TencentWallet/get');
Route::post('tencent-wallet', 'robotapi/TencentWallet/post');
Route::put('tencent-wallet', 'robotapi/TencentWallet/put');
Route::delete('tencent-wallet', 'robotapi/TencentWallet/delete');


// 腾讯广告全额转出接口
Route::get('tencent-refund-all', 'robotapi/TencentRefundAll/get');
Route::post('tencent-refund-all', 'robotapi/TencentRefundAll/post');
Route::put('tencent-refund-all', 'robotapi/TencentRefundAll/put');
Route::delete('tencent-refund-all', 'robotapi/TencentRefundAll/delete');
