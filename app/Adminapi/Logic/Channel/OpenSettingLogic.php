<?php

namespace App\Adminapi\Logic\Channel;

use App\Common\Logic\BaseLogic;
use App\Common\Service\ConfigService;

/**
 * 微信开放平台
 */
class OpenSettingLogic extends BaseLogic
{

    /**
     * @notes 获取微信开放平台设置
     */
    public static function getConfig()
    {
        $config = [
            'app_id' => ConfigService::get('open_platform', 'app_id', ''),
            'app_secret' => ConfigService::get('open_platform', 'app_secret', ''),
        ];

        return $config;
    }


    /**
     * @notes 微信开放平台设置
     */
    public static function setConfig($params)
    {
        ConfigService::set('open_platform', 'app_id', $params['app_id'] ?? '');
        ConfigService::set('open_platform', 'app_secret', $params['app_secret'] ?? '');
    }
}
