<?php

declare(strict_types=1);

namespace EasyWeChat\Work;

use EasyWeChat\Kernel\Contracts\Server as ServerInterface;
use EasyWeChat\Kernel\Encryptor;
use EasyWeChat\Kernel\HttpClient\RequestUtil;
use EasyWeChat\Kernel\ServerResponse;
use EasyWeChat\Kernel\Traits\DecryptXmlMessage;
use EasyWeChat\Kernel\Traits\InteractWithHandlers;
use EasyWeChat\Kernel\Traits\RespondXmlMessage;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class Server implements ServerInterface
{
    use DecryptXmlMessage;
    use RespondXmlMessage;
    use InteractWithHandlers;

    protected ServerRequestInterface $request;

    /**
     * @throws \Throwable
     */
    public function __construct(
        protected Encryptor $encryptor,
        ?ServerRequestInterface $request = null,
    ) {
        $this->request = $request ?? RequestUtil::createDefaultServerRequest();
    }

    /**
     * @throws \EasyWeChat\Kernel\Exceptions\InvalidArgumentException
     * @throws \EasyWeChat\Kernel\Exceptions\RuntimeException|\EasyWeChat\Kernel\Exceptions\BadRequestException|\Throwable
     */
    public function serve(): ResponseInterface
    {
        $query = $this->request->getQueryParams();

        if (!empty($query['echostr'])) {
            $response = $this->encryptor->decrypt(
                $query['echostr'],
                $query['msg_signature'] ?? '',
                $query['nonce'] ?? '',
                $query['timestamp'] ?? ''
            );

            return new Response(200, [], $response);
        }

        $message = Message::createFromRequest($this->request);

        $this->prepend($this->decryptRequestMessage());

        $response = $this->handle(new Response(200, [], 'SUCCESS'), $message);

        if (!($response instanceof ResponseInterface)) {
            $response = $this->transformToReply($response, $message, $this->encryptor);
        }

        return ServerResponse::make($response);
    }

    /**
     * @throws \EasyWeChat\Kernel\Exceptions\InvalidArgumentException
     */
    public function handleContactChanged(callable $handler): static
    {
        $this->with(function (Message $message, \Closure $next) use ($handler): mixed {
            return $message->Event === 'change_contact' ? $handler($message, $next) : $next($message);
        });

        return $this;
    }

    /**
     * @throws \EasyWeChat\Kernel\Exceptions\InvalidArgumentException
     */
    public function handleUserTagUpdated(callable $handler): static
    {
        $this->with(function (Message $message, \Closure $next) use ($handler): mixed {
            return $message->Event === 'change_contact' && $message->ChangeType === 'update_tag' ? $handler($message, $next) : $next($message);
        });

        return $this;
    }

    /**
     * @throws \EasyWeChat\Kernel\Exceptions\InvalidArgumentException
     */
    public function handleUserCreated(callable $handler): static
    {
        $this->with(function (Message $message, \Closure $next) use ($handler): mixed {
            return $message->Event === 'change_contact' && $message->ChangeType === 'create_user' ? $handler($message, $next) : $next($message);
        });

        return $this;
    }

    /**
     * @throws \EasyWeChat\Kernel\Exceptions\InvalidArgumentException
     */
    public function handleUserUpdated(callable $handler): static
    {
        $this->with(function (Message $message, \Closure $next) use ($handler): mixed {
            return $message->Event === 'change_contact' && $message->ChangeType === 'update_user' ? $handler($message, $next) : $next($message);
        });

        return $this;
    }

    /**
     * @throws \EasyWeChat\Kernel\Exceptions\InvalidArgumentException
     */
    public function handleUserDeleted(callable $handler): static
    {
        $this->with(function (Message $message, \Closure $next) use ($handler): mixed {
            return $message->Event === 'change_contact' && $message->ChangeType === 'delete_user' ? $handler($message, $next) : $next($message);
        });

        return $this;
    }

    /**
     * @throws \EasyWeChat\Kernel\Exceptions\InvalidArgumentException
     */
    public function handlePartyCreated(callable $handler): static
    {
        $this->with(function (Message $message, \Closure $next) use ($handler): mixed {
            return $message->InfoType === 'change_contact' && $message->ChangeType === 'create_party' ? $handler($message, $next) : $next($message);
        });

        return $this;
    }

    /**
     * @throws \EasyWeChat\Kernel\Exceptions\InvalidArgumentException
     */
    public function handlePartyUpdated(callable $handler): static
    {
        $this->with(function (Message $message, \Closure $next) use ($handler): mixed {
            return $message->InfoType === 'change_contact' && $message->ChangeType === 'update_party' ? $handler($message, $next) : $next($message);
        });

        return $this;
    }

    /**
     * @throws \EasyWeChat\Kernel\Exceptions\InvalidArgumentException
     */
    public function handlePartyDeleted(callable $handler): static
    {
        $this->with(function (Message $message, \Closure $next) use ($handler): mixed {
            return $message->InfoType === 'change_contact' && $message->ChangeType === 'delete_party' ? $handler($message, $next) : $next($message);
        });

        return $this;
    }

    /**
     * @throws \EasyWeChat\Kernel\Exceptions\InvalidArgumentException
     */
    public function handleBatchJobsFinished(callable $handler): static
    {
        $this->with(function (Message $message, \Closure $next) use ($handler): mixed {
            return $message->Event === 'batch_job_result' ? $handler($message, $next) : $next($message);
        });

        return $this;
    }

    /**
     * @throws \Throwable
     */
    public function addMessageListener(string $type, callable $handler): static
    {
        $this->withHandler(
            function (Message $message, \Closure $next) use ($type, $handler): mixed {
                return $message->MsgType === $type ? $handler($message, $next) : $next($message);
            }
        );

        return $this;
    }

    /**
     * @throws \Throwable
     */
    public function addEventListener(string $event, callable $handler): static
    {
        $this->withHandler(
            function (Message $message, \Closure $next) use ($event, $handler): mixed {
                return $message->Event === $event ? $handler($message, $next) : $next($message);
            }
        );

        return $this;
    }

    protected function validateUrl(): \Closure
    {
        return function (Message $message, \Closure $next): Response {
            $query = $this->request->getQueryParams();
            $response = $this->encryptor->decrypt(
                $query['echostr'],
                $query['msg_signature'] ?? '',
                $query['nonce'] ?? '',
                $query['timestamp'] ?? ''
            );

            return new Response(200, [], $response);
        };
    }

    protected function decryptRequestMessage(): \Closure
    {
        return function (Message $message, \Closure $next): mixed {
            $query = $this->request->getQueryParams();
            $this->decryptMessage(
                $message,
                $this->encryptor,
                $query['msg_signature'] ?? '',
                $query['timestamp'] ?? '',
                $query['nonce'] ?? ''
            );

            return $next($message);
        };
    }
}
