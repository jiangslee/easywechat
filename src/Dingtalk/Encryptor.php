<?php

namespace EasyWeChat\Dingtalk;

use JetBrains\PhpStorm\Pure;

class Encryptor extends \EasyWeChat\Kernel\Encryptor
{
    #[Pure]
    public function __construct(string $appKey, string $token, string $aesKey)
    {
        parent::__construct($appKey, $token, $aesKey, null);
    }
}
