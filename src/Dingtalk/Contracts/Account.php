<?php

declare(strict_types=1);

namespace EasySdk\Dingtalk\Contracts;

interface Account
{
    public function getAgentId(): string;

    public function getAppKey(): string;

    public function getSecret(): string;

    public function getToken(): string;

    public function getAesKey(): string;
}
