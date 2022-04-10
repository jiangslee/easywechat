<?php

declare(strict_types=1);

namespace EasyWeChat\Dingtalk\Contracts;

interface Account
{
    public function getAgentId(): string;

    public function getAppKey(): string;

    public function getSecret(): string;

    public function getToken(): string;

    public function getAesKey(): string;
}
