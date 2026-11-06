<?php

declare(strict_types=1);

namespace Pam\WhatsApp;

use JsonException;
use Pam\Browser\Browser;
use Pam\Browser\Chromium\LaunchOptions;
use Pam\Browser\Page;
use Pam\WhatsApp\Auth\AuthenticationHandshake;
use Pam\WhatsApp\Bridge\BridgeEvent;
use Pam\WhatsApp\Bridge\BridgeScript;
use Pam\WhatsApp\Contract\MessageData;
use Pam\WhatsApp\Contract\Session;
use Pam\WhatsApp\Exception\BridgeException;
use Pam\WhatsApp\Support\WebVersionCache;
use Pam\WhatsApp\Support\StickerFormatter;

final class BrowserSession implements Session
{
    private ?Page $page = null;

    private ?Client $client = null;

    /** @var null|callable(BridgeEvent): void */
    private $listener = null;

    private function __construct(
        private ?Browser $browser,
        private readonly ClientOptions $options,
        private readonly ?\Pam\WhatsApp\Auth\AuthStrategy $authStrategy,
        private string $profileDirectory = '',
    ) {
    }

    public static function launch(?ClientOptions $options = null): self
    {
        $session = self::create($options);
        $session->launchBrowser();

        return $session;
    }

    public static function create(?ClientOptions $options = null): self
    {
        $options ??= new ClientOptions();
        if ($options->authStrategy !== null && $options->effectiveSessionDirectory() !== null) {
            throw new \InvalidArgumentException('Choose authStrategy or sessionDirectory, not both.');
        }

        return new self(null, $options, $options->authStrategy);
    }

    public function launchBrowser(): void
    {
        if ($this->browser !== null) {
            throw new \LogicException('WhatsApp browser session is already launched.');
        }
        $profileDirectory = $this->authStrategy?->prepare()
            ?? $this->options->effectiveSessionDirectory();
        $this->authStrategy?->beforeBrowserInitialized();
        $this->browser = Browser::launch(new LaunchOptions(
            executable: $this->options->effectiveBrowserExecutable(),
            userDataDirectory: $profileDirectory,
            headless: $this->options->effectiveHeadless(),
            timeoutSeconds: $this->options->effectiveBrowserTimeoutSeconds(),
            arguments: $this->options->effectiveBrowserArguments(),
        ));
        $this->profileDirectory = $profileDirectory ?? $this->browser->profileDirectory();
        $this->authStrategy?->afterBrowserInitialized();
    }

    public function setupAuthStrategy(Client $client): void
    {
        $this->client = $client;
        $this->authStrategy?->setup($client);
    }

    public function browser(): Browser
    {
        return $this->browser ?? throw new \LogicException('WhatsApp browser session is not launched.');
    }

    public function currentBrowser(): ?Browser
    {
        return $this->browser;
    }

    public function currentPage(): ?Page
    {
        return $this->page;
    }

    public function initialize(callable $listener): void
    {
        if ($this->page !== null) {
            throw new \LogicException('WhatsApp browser session is already initialized.');
        }
        if ($this->browser === null) {
            $this->launchBrowser();
        }

        $this->listener = $listener;
        $bridgeInstallError = null;
        while (true) {
            $authenticationHandshake = new AuthenticationHandshake($this->authStrategy);
            $authenticationRejected = false;
            $restartRequested = false;
            $page = $this->browser()->newPage();
        if ($this->options->userAgent !== null) {
            $page->setUserAgent($this->options->userAgent);
        }
        if ($this->options->bypassCSP) {
            $page->setBypassCsp(true);
        }
        if ($this->options->proxyAuthentication !== null) {
            $page->authenticate(
                $this->options->proxyAuthentication->username,
                $this->options->proxyAuthentication->password,
            );
        }
        $webCache = $this->options->webVersionCache === null
            ? null
            : new WebVersionCache($this->options->webVersionCache);
        $requestedVersion = $this->options->webVersion;
        $cachedHtml = $webCache !== null && $requestedVersion !== null
            ? $webCache->resolve($requestedVersion)
            : null;
        $receivedHtml = null;
        if ($cachedHtml !== null) {
            $page->serveDocument('https://web.whatsapp.com/', $cachedHtml);
        } elseif ($webCache !== null) {
            $page->captureDocument(
                'https://web.whatsapp.com/',
                static function (string $html) use (&$receivedHtml): void {
                    $receivedHtml = $html;
                },
            );
        }
        $page->exposeBinding(
            'pamWhatsAppBridge',
            function (mixed $value) use (
                $listener,
                $authenticationHandshake,
                &$authenticationRejected,
                &$restartRequested,
                &$bridgeInstallError,
            ): void {
                $event = BridgeEvent::fromValue($value);
                if ($event->type === EventType::Error
                    && is_string($event->payload['message'] ?? null)
                ) {
                    $bridgeInstallError = $event->payload['message'];
                }
                $decision = $authenticationHandshake->inspect($event->type);
                if ($decision?->failed === true) {
                    $authenticationRejected = true;
                    $restartRequested = $decision->restart;
                    $listener(new BridgeEvent(EventType::AuthenticationFailure, [
                        'message' => $decision->failureMessage(),
                    ]));

                    return;
                }
                if ($authenticationRejected) return;
                if ($event->type === EventType::Authenticated && $this->authStrategy !== null) {
                    $event = new BridgeEvent($event->type, [
                        ...$event->payload,
                        'authPayload' => $this->authStrategy->getAuthEventPayload(),
                    ]);
                }
                if ($event->type === EventType::Ready && $this->authStrategy !== null) {
                    $this->authStrategy->afterAuthReady();
                }
                $listener($event);
            },
        );
        $page->addScriptOnNewDocument($this->clientConfigurationScript());
        $page->addScriptOnNewDocument(BridgeScript::source());
        if ($this->options->evalOnNewDoc !== null) {
            $page->addScriptOnNewDocument($this->options->evalOnNewDoc);
        }
        $this->page = $page;
        $page->navigate('https://web.whatsapp.com/', $this->options->effectiveBrowserTimeoutSeconds());
        try {
            $page->waitForFunction(
                'globalThis.__pamWhatsAppBridgeState === 2 || globalThis.__pamWhatsAppBridgeState === 3',
                $this->options->effectiveAuthenticationTimeoutSeconds(),
            );
        } catch (\Throwable $e) {
            foreach ([
                'url' => 'location.href',
                'title' => 'document.title',
                'readyState' => 'document.readyState',
                'bridgeState' => 'globalThis.__pamWhatsAppBridgeState',
                'body' => 'document.body?.innerText?.slice(0, 1000)',
            ] as $name => $expression) {
                try {
                    $value = $page->evaluate($expression);
                    fwrite(STDERR, "[DEBUG] $name: ".json_encode($value, JSON_UNESCAPED_UNICODE).PHP_EOL);
                } catch (\Throwable $debugError) {
                    fwrite(STDERR, "[DEBUG] $name: ".$debugError->getMessage().PHP_EOL);
                }
            }

            throw $e;
        }

        $state = $page->evaluate('globalThis.__pamWhatsAppBridgeState');
        if ($state !== 2) {
            $detail = is_string($bridgeInstallError) && $bridgeInstallError !== ''
                ? ' '.$bridgeInstallError
                : '';
            throw new BridgeException('WhatsApp Web bridge failed during installation.'.$detail);
        }
        if ($cachedHtml === null && $receivedHtml !== null && $webCache !== null) {
            $version = $page->evaluate('globalThis.Debug?.VERSION ?? null');
            if (is_string($version) && $version !== '') {
                $webCache->persist($receivedHtml, $version);
            }
        }
            if ($restartRequested) {
                $this->restartBrowser();

                continue;
            }
            if ($authenticationRejected) {
                $this->browser()->close();
                $this->authStrategy?->destroy();
                $this->page = null;
                $this->listener = null;
            }

            return;
        }
    }

    public function sendText(string $chatId, string $body, ?string $quotedMessageId = null): MessageData
    {
        return $this->sendContent($chatId, [
            'kind' => ContentKind::Text->value,
            'text' => $body,
        ], ['quotedMessageId' => $quotedMessageId]);
    }

    /**
     * @param array<string, mixed> $content
     * @param array<string, mixed> $options
     */
    public function sendContent(string $chatId, array $content, array $options = []): MessageData
    {
        if ($chatId === '') {
            throw new \InvalidArgumentException('Chat id must be non-empty.');
        }
        $media = $content['media'] ?? $options['media'] ?? null;
        if (($options['sendMediaAsSticker'] ?? false) === true
            && is_array($media)
            && is_string($media['mimetype'] ?? null)
            && str_starts_with($media['mimetype'], 'video/')
        ) {
            $formatted = (new StickerFormatter($this->options->ffmpegPath))->formatVideo(new MessageMedia(
                $media['mimetype'],
                is_string($media['data'] ?? null) ? $media['data'] : '',
                is_string($media['filename'] ?? null) ? $media['filename'] : null,
                is_int($media['filesize'] ?? null) ? $media['filesize'] : null,
            ));
            $formattedBinary = base64_decode($formatted->data, true);
            if (!is_string($formattedBinary)) {
                throw new BridgeException('FFmpeg returned invalid sticker data.');
            }
            $formattedBinary = Support\WebpStickerMetadata::apply(
                $formattedBinary,
                is_string($options['stickerName'] ?? null) ? $options['stickerName'] : null,
                is_string($options['stickerAuthor'] ?? null) ? $options['stickerAuthor'] : null,
                is_array($options['stickerCategories'] ?? null)
                    ? array_values(array_filter($options['stickerCategories'], 'is_string'))
                    : [],
            );
            $formatted = new MessageMedia(
                'image/webp',
                base64_encode($formattedBinary),
                $formatted->filename,
                strlen($formattedBinary),
            );
            if (isset($content['media'])) {
                $content['media'] = $formatted->toBridge()['media'];
            } else {
                $options['media'] = $formatted->toBridge()['media'];
            }
            $options['stickerName'] = null;
            $options['stickerAuthor'] = null;
            $options['stickerCategories'] = [];
        }

        try {
            $arguments = json_encode([$chatId, $content, $options], JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new BridgeException('Unable to encode send-content arguments.', previous: $exception);
        }
        try {
            $value = $this->page()->evaluate(sprintf(
                <<<'JAVASCRIPT'
(async () => {
    try {
        return await globalThis.PamWhatsApp.sendContent(...%s);
    } catch (error) {
        const detail = error instanceof Error
            ? `${error.name}: ${error.message}${error.stack ? `\n${error.stack}` : ''}`
            : String(error);
        throw new Error(`PamWhatsApp.sendContent failed: ${detail}`);
    }
})()
JAVASCRIPT,
                $arguments,
            ));
        } catch (\Throwable $exception) {
            throw new BridgeException(
                sprintf('Bridge sendContent failed for chat %s: %s', $chatId, $exception->getMessage()),
                previous: $exception,
            );
        }
        if (!is_array($value)) {
            throw new BridgeException('WhatsApp Web returned invalid sent-message data.');
        }

        $payload = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new BridgeException('Sent-message data must be an object.');
            }
            $payload[$key] = $item;
        }

        return MessageData::fromPayload($payload);
    }

    public function pump(float $timeoutSeconds): bool
    {
        $handled = $this->page()->pump($timeoutSeconds);
        if ($this->authStrategy?->onPump($this->profileDirectory) === true && $this->listener !== null) {
            ($this->listener)(new BridgeEvent(EventType::RemoteSessionSaved, [
                'timestamp' => time(),
            ]));

            return true;
        }

        return $handled;
    }

    public function invoke(string $method, array $arguments = []): mixed
    {
        if ($method === '' || preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $method) !== 1) {
            throw new \InvalidArgumentException('Bridge method name is invalid.');
        }
        try {
            $encodedMethod = json_encode($method, JSON_THROW_ON_ERROR);
            $encodedArguments = json_encode($arguments, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new BridgeException('Unable to encode bridge invocation.', previous: $exception);
        }

        try {
            return $this->page()->evaluate(sprintf(
                <<<'JAVASCRIPT'
(async () => {
    try {
        return await globalThis.PamWhatsApp.invoke(%s, %s);
    } catch (error) {
        const detail = error instanceof Error
            ? `${error.name}: ${error.message}${error.stack ? `\n${error.stack}` : ''}`
            : String(error);
        throw new Error(`PamWhatsApp.invoke failed: ${detail}`);
    }
})()
JAVASCRIPT,
                $encodedMethod,
                $encodedArguments,
            ));
        } catch (\Throwable $exception) {
            throw new BridgeException(
                sprintf('Bridge invocation %s failed: %s', $method, $exception->getMessage()),
                previous: $exception,
            );
        }
    }

    public function close(): void
    {
        $this->browser?->close();
        $this->authStrategy?->destroy();
        $this->page = null;
        $this->listener = null;
    }

    public function logout(): void
    {
        $this->page()->evaluate("globalThis.require('WAWebSocketModel').Socket.logout()");
        $this->browser()->close();
        try {
            $this->authStrategy?->logout();
        } finally {
            $this->authStrategy?->destroy();
            $this->page = null;
            $this->listener = null;
        }
    }

    public function disconnect(DisconnectionReason $reason): void
    {
        $this->browser()->close();
        try {
            match ($reason) {
                DisconnectionReason::Logout => $this->authStrategy?->logout(),
                DisconnectionReason::Conflict, DisconnectionReason::Unlaunched => $this->authStrategy?->disconnect(),
                DisconnectionReason::QrRetryLimit => null,
            };
        } finally {
            $this->authStrategy?->destroy();
            $this->page = null;
            $this->listener = null;
        }
    }

    /** @param callable(BridgeEvent): void $listener */
    public function reconnect(callable $listener): void
    {
        $this->restartBrowser();
        $this->initialize($listener);
    }

    private function page(): Page
    {
        return $this->page ?? throw new \LogicException('WhatsApp browser session is not initialized.');
    }

    private function restartBrowser(): void
    {
        $this->browser()->close();
        $this->authStrategy?->destroy();
        if ($this->client !== null) {
            $this->authStrategy?->setup($this->client);
        }
        $profileDirectory = $this->authStrategy?->prepare()
            ?? $this->options->effectiveSessionDirectory();
        $this->authStrategy?->beforeBrowserInitialized();
        $this->browser = Browser::launch(new LaunchOptions(
            executable: $this->options->effectiveBrowserExecutable(),
            userDataDirectory: $profileDirectory,
            headless: $this->options->effectiveHeadless(),
            timeoutSeconds: $this->options->effectiveBrowserTimeoutSeconds(),
            arguments: $this->options->effectiveBrowserArguments(),
        ));
        $this->profileDirectory = $profileDirectory ?? $this->browser->profileDirectory();
        $this->authStrategy?->afterBrowserInitialized();
        $this->page = null;
    }

    private function clientConfigurationScript(): string
    {
        try {
            $configuration = json_encode([
                'browserName' => $this->options->browserName,
                'deviceName' => $this->options->deviceName,
                'pairWithPhoneNumber' => $this->options->pairWithPhoneNumber === null ? null : [
                    'phoneNumber' => $this->options->pairWithPhoneNumber->phoneNumber,
                    'showNotification' => $this->options->pairWithPhoneNumber->showNotification,
                    'intervalMs' => $this->options->pairWithPhoneNumber->intervalMs,
                ],
                'qrMaxRetries' => $this->options->qrMaxRetries,
                'takeoverOnConflict' => $this->options->takeoverOnConflict,
                'takeoverTimeoutMs' => $this->options->takeoverTimeoutMs,
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new BridgeException('Unable to encode client configuration.', previous: $exception);
        }

        return 'globalThis.__pamWhatsAppClientOptions = Object.freeze('.$configuration.');';
    }
}
