<?php

declare(strict_types=1);

namespace EasyWeChat\Dingtalk;

use EasyWeChat\Dingtalk\Contracts\Account as AccountInterface;

class Account implements AccountInterface
{
    public function __construct(
        protected string $appKey,
        protected string $secret,
        protected string $token,
        protected string $aesKey,
        protected string $agentId,
    ) {
    }

    public function getAgentId(): string
    {
        return $this->agentId;
    }

    public function getAppKey(): string
    {
        return $this->appKey;
    }

    public function getSecret(): string
    {
        return $this->secret;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getAesKey(): string
    {
        return $this->aesKey;
    }
}
