<?php

declare(strict_types=1);

namespace EasySdk\Dingtalk;

class Config extends \EasyWeChat\Kernel\Config
{
    /**
     * @var array<string>
     */
    protected array $requiredKeys = [
        'app_key',
        'secret',
        'token',
        'aes_key',
        'agent_id'
    ];
}
