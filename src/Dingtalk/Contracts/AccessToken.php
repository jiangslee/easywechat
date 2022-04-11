<?php

declare(strict_types=1);

namespace EasySdk\Dingtalk\Contracts;

interface AccessToken
{
    public function getToken(): string;

    /**
     * @return array<string,string>
     */
    public function toQuery(): array;

    public function toHeader(): array;
}
