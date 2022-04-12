<?php

namespace EasySdk\Dingtalk;

use JetBrains\PhpStorm\Pure;
use EasyWeChat\Kernel\Support\Pkcs7;
use EasyWeChat\Kernel\Support\Str;
use EasyWeChat\Kernel\Exceptions\RuntimeException;

class Encryptor extends \EasyWeChat\Kernel\Encryptor
{
    #[Pure]
    public function __construct(string $appKey, string $token, string $aesKey)
    {
        parent::__construct($appKey, $token, $aesKey, null);
    }


    /**
     * @throws \EasyWeChat\Kernel\Exceptions\RuntimeException
     * @throws \Exception
     */
    public function encrypt(string $plaintext, string | null $nonce = null, int | string $timestamp = null): string
    {
        try {
            $plaintext = Pkcs7::padding(\random_bytes(16).\pack('N', \strlen($plaintext)).$plaintext.$this->appId, 32);
            $ciphertext = \base64_encode(
                \openssl_encrypt(
                    $plaintext,
                    "aes-256-cbc",
                    $this->aesKey,
                    \OPENSSL_NO_PADDING,
                    \substr($this->aesKey, 0, 16)
                ) ?: ''
            );
        } catch (\Throwable $e) {
            throw new RuntimeException($e->getMessage(), self::ERROR_ENCRYPT_AES);
        }

        $nonce ??= Str::random();
        $timestamp ??= \time();

        $response = [
            'encrypt' => $ciphertext,
            'msg_signature' => $this->createSignature($this->token, $timestamp, $nonce, $ciphertext),
            'timeStamp' => $timestamp,
            'nonce' => $nonce,
        ];

        return \strval(\json_encode($response, JSON_UNESCAPED_UNICODE));
    }
}
