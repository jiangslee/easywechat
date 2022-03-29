<?php

declare(strict_types=1);

namespace EasyWeChat\OpenPlatform;

use EasyWeChat\Kernel\Contracts\AccessToken as AccessTokenInterface;
use EasyWeChat\Kernel\Contracts\Server as ServerInterface;
use EasyWeChat\Kernel\Encryptor;
use EasyWeChat\Kernel\Exceptions\HttpException;
use EasyWeChat\Kernel\HttpClient\AccessTokenAwareClient;
use EasyWeChat\Kernel\HttpClient\Response;
use EasyWeChat\Kernel\Support\Arr;
use EasyWeChat\Kernel\Traits\InteractWithCache;
use EasyWeChat\Kernel\Traits\InteractWithClient;
use EasyWeChat\Kernel\Traits\InteractWithConfig;
use EasyWeChat\Kernel\Traits\InteractWithHttpClient;
use EasyWeChat\Kernel\Traits\InteractWithServerRequest;
use EasyWeChat\MiniApp\Application as MiniAppApplication;
use EasyWeChat\OfficialAccount\Application as OfficialAccountApplication;
use EasyWeChat\OfficialAccount\Config as OfficialAccountConfig;
use EasyWeChat\OpenPlatform\Contracts\Account as AccountInterface;
use EasyWeChat\OpenPlatform\Contracts\Application as ApplicationInterface;
use EasyWeChat\OpenPlatform\Contracts\VerifyTicket as VerifyTicketInterface;
use Overtrue\Socialite\Contracts\ProviderInterface as SocialiteProviderInterface;
use Overtrue\Socialite\Providers\WeChat;

class Application implements ApplicationInterface
{
    use InteractWithCache;
    use InteractWithConfig;
    use InteractWithClient;
    use InteractWithHttpClient;
    use InteractWithServerRequest;

    protected ?Encryptor $encryptor = null;
    protected ?ServerInterface $server = null;
    protected ?AccountInterface $account = null;
    protected ?AccessTokenInterface $componentAccessToken = null;
    protected ?VerifyTicketInterface $verifyTicket = null;

    public const ACCESS_TOKEN_CACHE_KEY = "easywechat.open_platform.authorize_app_id.%s";
    public const REFRESH_TOKEN_CACHE_KEY = "easywechat.open_platform.authorize_app_id.refresh_token.%s";

    public function getAccount(): AccountInterface
    {
        if (!$this->account) {
            $this->account = new Account(
                appId: (string) $this->config->get('app_id'),   /** @phpstan-ignore-line */
                secret: (string) $this->config->get('secret'),  /** @phpstan-ignore-line */
                token: (string) $this->config->get('token'),    /** @phpstan-ignore-line */
                aesKey: (string) $this->config->get('aes_key'), /** @phpstan-ignore-line */
            );
        }

        return $this->account;
    }

    public function setAccount(AccountInterface $account): static
    {
        $this->account = $account;

        return $this;
    }

    public function getVerifyTicket(): VerifyTicketInterface
    {
        if (!$this->verifyTicket) {
            $this->verifyTicket = new VerifyTicket(appId: $this->getAccount()->getAppId());
        }

        return $this->verifyTicket;
    }

    public function setVerifyTicket(VerifyTicketInterface $verifyTicket): static
    {
        $this->verifyTicket = $verifyTicket;

        return $this;
    }

    public function getEncryptor(): Encryptor
    {
        if (!$this->encryptor) {
            $this->encryptor = new Encryptor(
                appId: $this->getAccount()->getAppId(),
                token: $this->getAccount()->getToken(),
                aesKey: $this->getAccount()->getAesKey(),
                receiveId: $this->getAccount()->getAppId(),
            );
        }

        return $this->encryptor;
    }

    public function setEncryptor(Encryptor $encryptor): static
    {
        $this->encryptor = $encryptor;

        return $this;
    }

    /**
     * @throws \ReflectionException
     * @throws \EasyWeChat\Kernel\Exceptions\InvalidArgumentException
     * @throws \Throwable
     */
    public function getServer(): Server|ServerInterface
    {
        if (!$this->server) {
            $this->server = new Server(
                encryptor: $this->getEncryptor(),
                request: $this->getRequest()
            );
        }

        if ($this->server instanceof Server) {
            $this->server->withDefaultVerifyTicketHandler(
                function (Message $message, \Closure $next): mixed {
                    $ticket = $this->getVerifyTicket();
                    if (\is_callable([$ticket, 'setTicket'])) {
                        $ticket->setTicket($message->ComponentVerifyTicket);
                    }
                    return $next($message);
                }
            );
        }

        return $this->server;
    }

    public function setServer(ServerInterface $server): static
    {
        $this->server = $server;

        return $this;
    }

    public function getAccessToken(): AccessTokenInterface
    {
        return $this->getComponentAccessToken();
    }

    public function getComponentAccessToken(): AccessTokenInterface
    {
        if (!$this->componentAccessToken) {
            $this->componentAccessToken = (new ComponentAccessToken(
                appId: $this->getAccount()->getAppId(),
                secret: $this->getAccount()->getSecret(),
                verifyTicket: $this->getVerifyTicket(),
            ))
            ->setCache($this->getCache())
            ->setHttpClient($this->getHttpClient());
        }

        return $this->componentAccessToken;
    }

    public function setComponentAccessToken(AccessTokenInterface $componentAccessToken): static
    {
        $this->componentAccessToken = $componentAccessToken;

        return $this;
    }

    /**
     * 开放平台代公众号调用API
     * 用法：$app->getOfficalAccountClient($appid)->postJson($url, $data);
     *
     *
     * @param string $appid
     * @return \EasyWeChat\Kernel\HttpClient\AccessTokenAwareClient
     * @author jiangsili@qq.com
     * @since  2022-03-29
     */
    public function getOfficialAccountClient(string $appid)
    {
        $authorizerAccessToken = $this->getAuthorizerAccessToken($appid);
        $app = $this->getOfficialAccount(new AuthorizerAccessToken($appid, $authorizerAccessToken));
        return $app->getClient();
    }

    /**
     * 开放平台代小程序调用API
     * 用法：$app->getMiniAppClient($appid)->postJson($url, $data);
     *
     *
     * @param string $appid
     * @return \EasyWeChat\Kernel\HttpClient\AccessTokenAwareClient
     * @author jiangsili@qq.com
     * @since  2022-03-29
     */
    public function getMiniAppClient(string $appid)
    {
        $authorizerAccessToken = $this->getAuthorizerAccessToken($appid);
        $app = $this->getMiniApp(new AuthorizerAccessToken($appid, $authorizerAccessToken));
        return $app->getClient();
    }

    /**
     * 获取授权公众号/小程序的接口调用凭据（令牌）.
     *
     * @param string $appid
     * @return string
     * @author jiangsili@qq.com
     * @since  2022-03-29
     */
    public function getAuthorizerAccessToken(string $appid): string
    {
        $key = sprintf(self::ACCESS_TOKEN_CACHE_KEY, $appid);
        $authorizerAccessToken = $this->getCache()->get($key);
        if (empty($authorizerAccessToken)) {
            $authRefreshToken = $this->getCache()->get(sprintf(self::REFRESH_TOKEN_CACHE_KEY, $appid));
            if (empty($authRefreshToken)) {
                throw new \RuntimeException(sprintf("appid: %s, 可能未授权，或扫码重新授权", $appid));
            }
            $tokenResult = $this->refreshAuthorizerToken($appid, $authRefreshToken);

            $authorizerAccessToken = Arr::get($tokenResult, 'authorizer_access_token');
            $authAccessTokenExpiresIn = Arr::get($tokenResult, 'expires_in', 7200);
            $this->getCache()->set($key, $authorizerAccessToken, $authAccessTokenExpiresIn);
        }
        return $authorizerAccessToken;
    }

    /**
     * @throws \EasyWeChat\Kernel\Exceptions\HttpException
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface
     */
    public function getAuthorization(string $authorizationCode): Authorization
    {
        $response = $this->createClient()->request(
            'POST',
            'cgi-bin/component/api_query_auth',
            [
                'json' => [
                    'component_appid' => $this->getAccount()->getAppId(),
                    'authorization_code' => $authorizationCode,
                ],
            ]
        )->toArray(false);

        if (empty($response['authorization_info'])) {
            throw new HttpException('Failed to get authorization_info: '.json_encode($response, JSON_UNESCAPED_UNICODE));
        }

        return new Authorization($response);
    }

    /**
     * @return array<string, mixed>
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     * @throws \EasyWeChat\Kernel\Exceptions\HttpException
     * @throws \Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface
     */
    public function refreshAuthorizerToken(string $authorizerAppId, string $authorizerRefreshToken): array
    {
        $response = $this->createClient()->request(
            'POST',
            'cgi-bin/component/api_authorizer_token',
            [
                'json' => [
                    'component_appid' => $this->getAccount()->getAppId(),
                    'authorizer_appid' => $authorizerAppId,
                    'authorizer_refresh_token' => $authorizerRefreshToken,
                ],
            ]
        )->toArray(false);

        if (empty($response['authorizer_access_token'])) {
            throw new HttpException('Failed to get authorizer_access_token: '.json_encode($response, JSON_UNESCAPED_UNICODE));
        }

        return $response;
    }

    /**
     * @throws \Overtrue\Socialite\Exceptions\InvalidArgumentException
     */
    public function getOAuth(): SocialiteProviderInterface
    {
        return (new WeChat(
            [
                'client_id' => $this->getAccount()->getAppId(),
                'client_secret' => $this->getAccount()->getSecret(),
                'redirect_url' => $this->config->get('oauth.redirect_url'),
            ]
        ))->scopes((array) $this->config->get('oauth.scopes', ['snsapi_userinfo']));
    }

    /**
     * @throws \EasyWeChat\Kernel\Exceptions\InvalidArgumentException
     */
    public function getOfficialAccount(AuthorizerAccessToken $authorizerAccessToken, array $config = []): OfficialAccountApplication
    {
        $config = new OfficialAccountConfig(
            \array_merge(
                [
                    'app_id' => $authorizerAccessToken->getAppId(),
                    'token' => $this->config->get('token'),
                    'aes_key' => $this->config->get('aes_key'),
                    'logging' => $this->config->get('logging'),
                    'http' => $this->config->get('http'),
                ],
                $config
            )
        );

        $app = new OfficialAccountApplication($config);

        $app->setAccessToken($authorizerAccessToken);
        $app->setEncryptor($this->getEncryptor());
        $app->setOAuthFactory($this->createAuthorizerOAuthFactory($authorizerAccessToken->getAppId(), $config));

        return $app;
    }

    /**
     * @throws \EasyWeChat\Kernel\Exceptions\InvalidArgumentException
     */
    public function getMiniApp(AuthorizerAccessToken $authorizerAccessToken, array $config = []): MiniAppApplication
    {
        $app = new MiniAppApplication(
            \array_merge(
                [
                    'app_id' => $authorizerAccessToken->getAppId(),
                    'token' => $this->config->get('token'),
                    'aes_key' => $this->config->get('aes_key'),
                    'logging' => $this->config->get('logging'),
                    'http' => $this->config->get('http'),
                ],
                $config
            )
        );

        $app->setAccessToken($authorizerAccessToken);
        $app->setEncryptor($this->getEncryptor());

        return $app;
    }

    protected function createAuthorizerOAuthFactory(string $authorizerAppId, OfficialAccountConfig $config): \Closure
    {
        return fn () => (new WeChat(
            [
                'client_id' => $authorizerAppId,

                'component' => [
                    'component_app_id' => $this->getAccount()->getAppId(),
                    'component_access_token' => fn () => $this->getComponentAccessToken()->getToken(),
                ],

                'redirect_url' => $this->config->get('oauth.redirect_url'),
            ]
        ))->scopes((array) $config->get('oauth.scopes', ['snsapi_userinfo']));
    }

    public function createClient(): AccessTokenAwareClient
    {
        return new AccessTokenAwareClient(
            client: $this->getHttpClient(),
            accessToken: $this->getComponentAccessToken(),
            failureJudge: fn (Response $response) => !!($response->toArray()['errcode'] ?? 0)
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function getHttpClientDefaultOptions(): array
    {
        return \array_merge(
            ['base_uri' => 'https://api.weixin.qq.com/',],
            (array)$this->config->get('http', [])
        );
    }
}
