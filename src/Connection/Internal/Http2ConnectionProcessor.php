<?php /** @noinspection PhpUnusedPrivateFieldInspection */

namespace Amp\Http\Client\Connection\Internal;

use Amp\ByteStream\IteratorStream;
use Amp\ByteStream\StreamException;
use Amp\CancellationToken;
use Amp\CancellationTokenSource;
use Amp\CancelledException;
use Amp\CombinedCancellationToken;
use Amp\Coroutine;
use Amp\Deferred;
use Amp\Emitter;
use Amp\Failure;
use Amp\Http\Client\Connection\Http2ConnectionException;
use Amp\Http\Client\Connection\Http2StreamException;
use Amp\Http\Client\Connection\HttpStream;
use Amp\Http\Client\Connection\Stream;
use Amp\Http\Client\Connection\UnprocessedRequestException;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\Internal\ResponseBodyStream;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Http\Client\SocketException;
use Amp\Http\Client\Trailers;
use Amp\Http\HPack;
use Amp\Http\InvalidHeaderException;
use Amp\Http\Status;
use Amp\Loop;
use Amp\Promise;
use Amp\Socket\EncryptableSocket;
use Amp\Success;
use Amp\TimeoutCancellationToken;
use League\Uri;
use function Amp\asyncCall;
use function Amp\call;

/** @internal */
final class Http2ConnectionProcessor implements Http2Processor
{
    private const PREFACE = "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n";
    private const DEFAULT_MAX_FRAME_SIZE = 1 << 14;
    private const DEFAULT_WINDOW_SIZE = (1 << 16) - 1;

    private const MINIMUM_WINDOW = (1 << 15) - 1;
    private const MAX_INCREMENT = (1 << 16) - 1;

    // Milliseconds to wait for pong (PING with ACK) frame before closing the connection.
    private const PONG_TIMEOUT = 500;

    private const NO_FLAG = 0x00;

    // SETTINGS / PING Flags - https://http2.github.io/http2-spec/#rfc.section.6.5
    private const ACK = 0x01;

    // HEADERS Flags - https://http2.github.io/http2-spec/#rfc.section.6.2
    private const END_STREAM = 0x01;
    private const END_HEADERS = 0x04;
    private const PADDED = 0x08;
    private const PRIORITY_FLAG = 0x20;

    // Settings - https://http2.github.io/http2-spec/#rfc.section.11.3
    private const HEADER_TABLE_SIZE = 0x1; // 1 << 12
    private const ENABLE_PUSH = 0x2; // 1
    private const MAX_CONCURRENT_STREAMS = 0x3; // INF
    private const INITIAL_WINDOW_SIZE = 0x4; // 1 << 16 - 1
    private const MAX_FRAME_SIZE = 0x5; // 1 << 14
    private const MAX_HEADER_LIST_SIZE = 0x6; // INF

    // Frame Types - https://http2.github.io/http2-spec/#rfc.section.11.2
    private const DATA = 0x00;
    private const HEADERS = 0x01;
    private const PRIORITY = 0x02;
    private const RST_STREAM = 0x03;
    private const SETTINGS = 0x04;
    private const PUSH_PROMISE = 0x05;
    private const PING = 0x06;
    private const GOAWAY = 0x07;
    private const WINDOW_UPDATE = 0x08;
    private const CONTINUATION = 0x09;

    // Error codes
    private const GRACEFUL_SHUTDOWN = 0x0;
    private const PROTOCOL_ERROR = 0x1;
    private const INTERNAL_ERROR = 0x2;
    private const FLOW_CONTROL_ERROR = 0x3;
    private const SETTINGS_TIMEOUT = 0x4;
    private const STREAM_CLOSED = 0x5;
    private const FRAME_SIZE_ERROR = 0x6;
    private const REFUSED_STREAM = 0x7;
    private const CANCEL = 0x8;
    private const COMPRESSION_ERROR = 0x9;
    private const CONNECT_ERROR = 0xa;
    private const ENHANCE_YOUR_CALM = 0xb;
    private const INADEQUATE_SECURITY = 0xc;
    private const HTTP_1_1_REQUIRED = 0xd;

    /** @var string 64-bit for ping. */
    private $counter = "aaaaaaaa";

    /** @var EncryptableSocket */
    private $socket;

    /** @var Http2Stream[] */
    private $streams = [];

    /** @var int */
    private $serverWindow = self::DEFAULT_WINDOW_SIZE;

    /** @var int */
    private $clientWindow = self::DEFAULT_WINDOW_SIZE;

    /** @var int */
    private $initialWindowSize = self::DEFAULT_WINDOW_SIZE;

    /** @var int */
    private $frameSizeLimit = self::DEFAULT_MAX_FRAME_SIZE;

    /** @var int Previous stream ID. */
    private $streamId = -1;

    /** @var int Maximum number of streams that may be opened. Initially unlimited. */
    private $concurrentStreamLimit = \PHP_INT_MAX;

    /** @var int Currently open or reserved streams. Initially unlimited. */
    private $remainingStreams = \PHP_INT_MAX;

    /** @var HPack */
    private $hpack;

    /** @var Deferred|null */
    private $settings;

    /** @var bool */
    private $initializeStarted = false;

    /** @var bool */
    private $initialized = false;

    /** @var string|null */
    private $pongWatcher;

    /** @var Deferred|null */
    private $pongDeferred;

    /** @var string|null */
    private $idleWatcher;

    /** @var int */
    private $idlePings = 0;

    /** @var callable[]|null */
    private $onClose = [];

    public function __construct(EncryptableSocket $socket)
    {
        $this->socket = $socket;
        $this->hpack = new HPack;
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    /**
     * Returns a promise that is resolved once the connection has been initialized. A stream cannot be obtained from the
     * connection until the promise returned by this method resolves.
     *
     * @return Promise
     */
    public function initialize(): Promise
    {
        if ($this->initializeStarted) {
            throw new \Error('Connection may only be initialized once');
        }

        $this->initializeStarted = true;

        if ($this->socket->isClosed()) {
            return new Failure(new UnprocessedRequestException(
                new SocketException('The socket closed before the connection could be initialized')
            ));
        }

        $this->settings = new Deferred;
        $promise = $this->settings->promise();

        Promise\rethrow(new Coroutine($this->run()));

        return $promise;
    }

    public function onClose(callable $onClose): void
    {
        if ($this->onClose === null) {
            asyncCall($onClose, $this);
            return;
        }

        $this->onClose[] = $onClose;
    }

    public function close(): Promise
    {
        $this->socket->close();

        if ($this->onClose !== null) {
            $onClose = $this->onClose;
            $this->onClose = null;

            foreach ($onClose as $callback) {
                asyncCall($callback, $this);
            }
        }

        return new Success;
    }

    public function handlePong(string $data): void
    {
        $this->writeFrame($data, self::PING, self::ACK);
    }

    public function handlePing(string $data): void
    {
        if ($this->pongDeferred !== null) {
            if ($this->pongWatcher !== null) {
                Loop::cancel($this->pongWatcher);
                $this->pongWatcher = null;
            }

            $deferred = $this->pongDeferred;
            $this->pongDeferred = null;
            $deferred->resolve(true);
        }
    }

    public function handleShutdown(int $lastId, int $error): void
    {
        $message = \sprintf(
            "Received GOAWAY frame from %s with error code %d",
            $this->socket->getRemoteAddress(),
            $error
        );

        $this->shutdown($lastId, new Http2ConnectionException($message, $error));
    }

    public function handleStreamWindowIncrement(int $streamId, int $windowSize): void
    {
        if (!isset($this->streams[$streamId])) {
            return;
        }

        $stream = $this->streams[$streamId];

        if ($stream->clientWindow + $windowSize > (2 << 30) - 1) {
            $this->handleStreamException(new Http2StreamException(
                "Current window size plus new window exceeds maximum size",
                $streamId,
                self::FLOW_CONTROL_ERROR
            ));

            return;
        }

        $stream->clientWindow += $windowSize;

        $this->writeBufferedData($stream);
    }

    public function handleConnectionWindowIncrement($windowSize): void
    {
        if ($this->clientWindow + $windowSize > (2 << 30) - 1) {
            $this->handleConnectionException(new Http2ConnectionException(
                "Current window size plus new window exceeds maximum size",
                self::FLOW_CONTROL_ERROR
            ));

            return;
        }

        $this->clientWindow += $windowSize;

        foreach ($this->streams as $stream) {
            if ($this->clientWindow <= 0) {
                return;
            }

            if ($stream->buffer === '' || $stream->clientWindow <= 0) {
                continue;
            }

            $this->writeBufferedData($stream);
        }
    }

    public function handleHeaders(int $streamId, array $pseudo, array $headers): void
    {
        if (!isset($this->streams[$streamId])) {
            return;
        }

        $stream = $this->streams[$streamId];

        if ($stream->trailers) {
            if ($stream->expectedLength && $stream->received !== $stream->expectedLength) {
                $diff = $stream->expectedLength - $stream->received;
                $this->handleStreamException(new Http2StreamException(
                    "Content length mismatch: " . \abs($diff) . ' bytes ' . ($diff > 0 ? ' missing' : 'too much'),
                    $streamId,
                    self::PROTOCOL_ERROR
                ));

                return;
            }

            if (!empty($pseudo)) {
                $this->handleStreamException(new Http2StreamException(
                    "Trailers must not contain pseudo headers",
                    $streamId,
                    self::PROTOCOL_ERROR
                ));

                return;
            }

            try {
                // Constructor checks for any disallowed fields
                $parsedTrailers = new Trailers($headers);
            } catch (InvalidHeaderException $exception) {
                $this->handleStreamException(new Http2StreamException(
                    "Disallowed field names in trailer",
                    $streamId,
                    self::PROTOCOL_ERROR,
                    $exception
                ));

                return;
            }

            $trailers = $stream->trailers;
            $stream->trailers = null;
            $trailers->resolve($parsedTrailers);

            asyncCall(function () use ($stream, $streamId) {
                try {
                    foreach ($stream->request->getEventListeners() as $eventListener) {
                        yield $eventListener->completeReceivingResponse($stream->request, $stream->stream);
                    }
                } catch (\Throwable $e) {
                    $this->handleStreamException(new Http2StreamException(
                        "Event listener error",
                        $streamId,
                        self::CANCEL
                    ));
                }
            });

            $this->setupPingIfIdle();

            return;
        }

        if (!isset($pseudo[":status"])) {
            $this->handleConnectionException(new Http2ConnectionException(
                "No status pseudo header in response",
                self::PROTOCOL_ERROR
            ));

            return;
        }

        if (!\preg_match("/^[1-5]\d\d$/", $pseudo[":status"])) {
            $this->handleStreamException(new Http2StreamException(
                "Invalid response status code",
                $streamId,
                self::PROTOCOL_ERROR
            ));

            return;
        }

        if ($stream->response !== null) {
            $this->handleStreamException(new Http2StreamException(
                "Stream headers already received",
                $streamId,
                self::PROTOCOL_ERROR
            ));

            return;
        }

        $status = (int) $pseudo[":status"];

        if ($status === Status::SWITCHING_PROTOCOLS) {
            $this->handleConnectionException(new Http2ConnectionException(
                "Switching Protocols (101) is not part of HTTP/2",
                self::PROTOCOL_ERROR
            ));

            return;
        }

        if ($status < 200) {
            return; // ignore 1xx responses
        }

        asyncCall(function () use ($stream, $streamId) {
            try {
                foreach ($stream->request->getEventListeners() as $eventListener) {
                    yield $eventListener->startReceivingResponse($stream->request, $stream->stream);
                }
            } catch (\Throwable $e) {
                $this->handleStreamException(new Http2StreamException("Event listener error", $streamId, self::CANCEL));
            }
        });

        $stream->body = new Emitter;
        $stream->trailers = new Deferred;

        $bodyCancellation = new CancellationTokenSource;
        $cancellationToken = new CombinedCancellationToken(
            $stream->cancellationToken,
            $bodyCancellation->getToken()
        );

        $response = new Response(
            '2',
            $status,
            Status::getReason($status),
            $headers,
            new ResponseBodyStream(
                new IteratorStream($stream->body->iterate()),
                $bodyCancellation
            ),
            $stream->request,
            $stream->trailers->promise()
        );

        $pendingResponse = $stream->pendingResponse;
        $stream->pendingResponse = null;
        $pendingResponse->resolve($response);

        if ($this->serverWindow <= $stream->request->getBodySizeLimit() >> 1) {
            $increment = $stream->request->getBodySizeLimit() - $this->serverWindow;
            $this->serverWindow = $stream->request->getBodySizeLimit();

            $this->writeFrame(\pack("N", $increment), self::WINDOW_UPDATE);
        }

        if (isset($headers["content-length"])) {
            if (\count($headers['content-length']) !== 1) {
                $this->handleStreamException(new Http2StreamException(
                    "Multiple content-length header values",
                    $streamId,
                    self::PROTOCOL_ERROR
                ));

                return;
            }

            $contentLength = $headers["content-length"][0];
            if (!\preg_match('/^(0|[1-9][0-9]*)$/', $contentLength)) {
                $this->handleStreamException(new Http2StreamException(
                    "Invalid content-length header value",
                    $streamId,
                    self::PROTOCOL_ERROR
                ));

                return;
            }

            $stream->expectedLength = (int) $contentLength;
        }

        $cancellationToken->subscribe(function (CancelledException $exception) use ($streamId): void {
            if (!isset($this->streams[$streamId])) {
                return;
            }

            $this->writeFrame(\pack("N", self::CANCEL), self::RST_STREAM, self::NO_FLAG, $streamId);
            $this->releaseStream($streamId, $exception);
        });

        unset($bodyCancellation, $cancellationToken); // Remove reference to cancellation token.
    }

    public function handlePushPromise(int $parentId, int $streamId, array $pseudo, array $headers): void
    {
        if (!isset($pseudo[":method"], $pseudo[":path"], $pseudo[":scheme"], $pseudo[":authority"])
            || isset($headers["connection"])
            || $pseudo[":path"] === ''
            || (isset($headers["te"]) && \implode($headers["te"]) !== "trailers")
        ) {
            $this->handleStreamException(new Http2StreamException(
                "Invalid header values",
                $streamId,
                self::PROTOCOL_ERROR
            ));

            return;
        }

        $method = $pseudo[":method"];
        $target = $pseudo[":path"];
        $scheme = $pseudo[":scheme"];
        $host = $pseudo[":authority"];
        $query = null;

        if ($method !== 'GET' && $method !== 'HEAD') {
            $this->handleStreamException(new Http2StreamException(
                "Pushed request method must be a safe method",
                $streamId,
                self::PROTOCOL_ERROR
            ));

            return;
        }

        if (!\preg_match("#^([A-Z\d.\-]+|\[[\d:]+])(?::([1-9]\d*))?$#i", $host, $matches)) {
            $this->handleStreamException(new Http2StreamException(
                "Invalid pushed authority (host) name",
                $streamId,
                self::PROTOCOL_ERROR
            ));

            return;
        }

        $host = $matches[1];
        $port = isset($matches[2]) ? (int) $matches[2] : $this->socket->getRemoteAddress()->getPort();

        if (!isset($this->streams[$parentId])) {
            $this->handleStreamException(new Http2StreamException(
                "Parent stream {$parentId} is no longer open",
                $streamId,
                self::PROTOCOL_ERROR
            ));

            return;
        }

        /** @var Http2Stream $parentStream */
        $parentStream = $this->streams[$parentId];

        if (\strcasecmp($host, $parentStream->request->getUri()->getHost()) !== 0) {
            $this->handleStreamException(new Http2StreamException(
                "Authority does not match original request authority",
                $streamId,
                self::PROTOCOL_ERROR
            ));

            return;
        }

        if ($position = \strpos($target, "#")) {
            $target = \substr($target, 0, $position);
        }

        if ($position = \strpos($target, "?")) {
            $query = \substr($target, $position + 1);
            $target = \substr($target, 0, $position);
        }

        try {
            $uri = Uri\Http::createFromComponents([
                "scheme" => $scheme,
                "host" => $host,
                "port" => $port,
                "path" => $target,
                "query" => $query,
            ]);
        } catch (\Exception $exception) {
            $this->handleConnectionException(new Http2ConnectionException("Invalid push URI", self::PROTOCOL_ERROR));

            return;
        }

        $request = new Request($uri, $method);
        $request->setHeaders($headers);
        $request->setProtocolVersions(['2']);
        $request->setPushHandler($parentStream->request->getPushHandler());
        $request->setHeaderSizeLimit($parentStream->request->getHeaderSizeLimit());
        $request->setBodySizeLimit($parentStream->request->getBodySizeLimit());

        $stream = new Http2Stream(
            $streamId,
            $request,
            HttpStream::fromStream(
                $parentStream->stream,
                static function () {
                    throw new \Error('Calling Stream::request() on a pushed request is forbidden');
                },
                static function () {
                    // nothing to do
                }
            ),
            $parentStream->cancellationToken,
            self::DEFAULT_WINDOW_SIZE,
            0
        );

        $stream->dependency = $parentId;

        $this->streams[$streamId] = $stream;

        if ($parentStream->request->getPushHandler() === null) {
            $this->handleStreamException(new Http2StreamException("Push promise refused", $streamId, self::CANCEL));

            return;
        }

        asyncCall(function () use ($streamId, $stream): \Generator {
            $tokenSource = new CancellationTokenSource;
            $cancellationToken = new CombinedCancellationToken(
                $stream->cancellationToken,
                $tokenSource->getToken()
            );

            $cancellationId = $cancellationToken->subscribe(function (
                CancelledException $exception
            ) use ($streamId): void {
                if (!isset($this->streams[$streamId])) {
                    return;
                }

                $this->writeFrame(\pack("N", self::CANCEL), self::RST_STREAM, self::NO_FLAG, $streamId);
                $this->releaseStream($streamId, $exception);
            });

            $onPush = $stream->request->getPushHandler();

            try {
                yield call($onPush, $stream->request, $stream->pendingResponse->promise());
            } catch (HttpException | StreamException | CancelledException $exception) {
                $tokenSource->cancel($exception);
            } catch (\Throwable $exception) {
                $tokenSource->cancel($exception);
                throw $exception;
            } finally {
                $cancellationToken->unsubscribe($cancellationId);
            }
        });
    }

    public function handlePriority(int $streamId, int $parentId, int $weight): void
    {
        if (!isset($this->streams[$streamId])) {
            return;
        }

        $stream = $this->streams[$streamId];

        $stream->dependency = $parentId;
        $stream->weight = $weight;
    }

    public function handleStreamReset(int $streamId, int $errorCode): void
    {
        if (!isset($this->streams[$streamId])) {
            return;
        }

        $this->handleStreamException(new Http2StreamException("Stream closed by server", $streamId, $errorCode));
    }

    public function handleStreamException(Http2StreamException $exception): void
    {
        $id = $exception->getStreamId();
        $code = $exception->getCode();

        if ($code === self::REFUSED_STREAM) {
            $exception = new UnprocessedRequestException($exception);
        }

        $this->writeFrame(\pack("N", $code), self::RST_STREAM, self::NO_FLAG, $id);

        if (isset($this->streams[$id])) {
            $this->releaseStream($id, $exception);
        }
    }

    public function handleConnectionException(Http2ConnectionException $exception): void
    {
        $this->shutdown(null, $exception);
    }

    public function handleData(int $streamId, string $data): void
    {
        if (!isset($this->streams[$streamId])) {
            return;
        }

        $stream = $this->streams[$streamId];

        if (!$stream->body) {
            $this->handleStreamException(new Http2StreamException(
                "Stream headers not complete or body already complete",
                $streamId,
                self::PROTOCOL_ERROR
            ));

            return;
        }

        $length = \strlen($data);

        $this->serverWindow -= $length;
        $stream->serverWindow -= $length;
        $stream->received += $length;

        if ($stream->received >= $stream->request->getBodySizeLimit()) {
            $this->handleStreamException(new Http2StreamException("Body size limit exceeded", $streamId, self::CANCEL));

            return;
        }

        if ($stream->expectedLength !== null && $stream->received > $stream->expectedLength) {
            $this->handleStreamException(new Http2StreamException(
                "Body size exceeded content-length in header",
                $streamId,
                self::CANCEL
            ));

            return;
        }

        if ($this->serverWindow <= self::MINIMUM_WINDOW) {
            $this->serverWindow += self::MAX_INCREMENT;
            $this->writeFrame(\pack("N", self::MAX_INCREMENT), self::WINDOW_UPDATE);
        }

        $promise = $stream->body->emit($data);

        if ($stream->serverWindow <= self::MINIMUM_WINDOW) {
            $promise->onResolve(function (?\Throwable $exception) use ($streamId): void {
                if ($exception || !isset($this->streams[$streamId])) {
                    return;
                }

                $stream = $this->streams[$streamId];

                if ($stream->serverWindow > self::MINIMUM_WINDOW) {
                    return;
                }

                $increment = \min(
                    $stream->request->getBodySizeLimit() - $stream->received - $stream->serverWindow,
                    self::MAX_INCREMENT
                );

                if ($increment <= 0) {
                    return;
                }

                $stream->serverWindow += $increment;

                $this->writeFrame(\pack("N", $increment), self::WINDOW_UPDATE, self::NO_FLAG, $streamId);
            });
        }
    }

    public function handleSettings(array $settings): void
    {
        foreach ($settings as $setting => $value) {
            $this->applySetting($setting, $value);
        }

        $this->writeFrame('', self::SETTINGS, self::ACK);

        if ($this->settings) {
            $deferred = $this->settings;
            $this->settings = null;
            $this->initialized = true;
            $deferred->resolve($this->remainingStreams);
        }
    }

    public function handleStreamEnd(int $streamId): void
    {
        if (!isset($this->streams[$streamId])) {
            return;
        }

        $stream = $this->streams[$streamId];

        if ($stream->expectedLength !== null && $stream->received !== $stream->expectedLength) {
            $this->handleStreamException(new Http2StreamException(
                "Body length does not match content-length header",
                $streamId,
                self::PROTOCOL_ERROR
            ));

            return;
        }

        $body = $stream->body;
        $stream->body = null;
        $body->complete();

        $trailers = $stream->trailers;
        $stream->trailers = null;
        /** @noinspection PhpUnhandledExceptionInspection */
        $trailers->resolve(new Trailers([]));

        asyncCall(function () use ($stream, $streamId) {
            try {
                foreach ($stream->request->getEventListeners() as $eventListener) {
                    yield $eventListener->completeReceivingResponse($stream->request, $stream->stream);
                }
            } catch (\Throwable $e) {
                $this->handleStreamException(new Http2StreamException("Event listener error", $streamId, self::CANCEL));
            }
        });

        $this->setupPingIfIdle();

        $this->releaseStream($streamId);
    }

    public function reserveStream(): void
    {
        --$this->remainingStreams;
    }

    public function unreserveStream(): void
    {
        ++$this->remainingStreams;
    }

    public function getRemainingStreams(): int
    {
        return $this->remainingStreams;
    }

    public function request(Request $request, CancellationToken $cancellationToken, Stream $stream): Promise
    {
        $this->idlePings = 0;
        $this->cancelIdleWatcher();

        // Remove defunct HTTP/1.x headers.
        $request->removeHeader('host');
        $request->removeHeader('connection');
        $request->removeHeader('keep-alive');
        $request->removeHeader('transfer-encoding');
        $request->removeHeader('upgrade');

        $request->setProtocolVersions(['2']);

        if ($this->socket->isClosed()) {
            return new Failure(new UnprocessedRequestException(
                new SocketException(\sprintf(
                    "Socket to '%s' closed before the request could be sent",
                    $this->socket->getRemoteAddress()
                ))
            ));
        }

        $streamId = $this->streamId += 2; // Client streams should be odd-numbered, starting at 1.

        $this->streams[$streamId] = $http2stream = new Http2Stream(
            $streamId,
            $request,
            $stream,
            $cancellationToken,
            self::DEFAULT_WINDOW_SIZE,
            $this->initialWindowSize
        );

        if ($request->getTransferTimeout() > 0) {
            // Cancellation token combined with timeout token should not be stored in $stream->cancellationToken,
            // otherwise the timeout applies to the body transfer and pushes.
            $cancellationToken = new CombinedCancellationToken(
                $cancellationToken,
                new TimeoutCancellationToken($request->getTransferTimeout())
            );
        }

        return call(function () use ($streamId, $request, $cancellationToken, $stream, $http2stream): \Generator {
            $this->socket->reference();

            $onCancel = function (CancelledException $exception) use ($streamId): void {
                if (!isset($this->streams[$streamId])) {
                    return;
                }

                $this->writeFrame(\pack("N", self::CANCEL), self::RST_STREAM, self::NO_FLAG, $streamId);
                $this->releaseStream($streamId, $exception);
            };

            $cancellationId = $cancellationToken->subscribe($onCancel);

            try {
                $headers = yield from $this->generateHeaders($request);
                $headers = $this->hpack->encode($headers);
                $body = $request->getBody()->createBodyStream();

                foreach ($request->getEventListeners() as $eventListener) {
                    yield $eventListener->startSendingRequest($request, $stream);
                }

                $chunk = yield $body->read();

                if (!isset($this->streams[$streamId])) {
                    foreach ($request->getEventListeners() as $eventListener) {
                        yield $eventListener->completeSendingRequest($request, $stream);
                    }

                    return yield $http2stream->pendingResponse->promise();
                }

                $flag = self::END_HEADERS | ($chunk === null ? self::END_STREAM : self::NO_FLAG);

                if (\strlen($headers) > $this->frameSizeLimit) {
                    $split = \str_split($headers, $this->frameSizeLimit);

                    $firstChunk = \array_shift($split);
                    $lastChunk = \array_pop($split);

                    // no yield, because there must not be other frames in between
                    $this->writeFrame($firstChunk, self::HEADERS, self::NO_FLAG, $streamId);

                    foreach ($split as $headerChunk) {
                        // no yield, because there must not be other frames in between
                        $this->writeFrame($headerChunk, self::CONTINUATION, self::NO_FLAG, $streamId);
                    }

                    yield $this->writeFrame($lastChunk, self::CONTINUATION, $flag, $streamId);
                } else {
                    yield $this->writeFrame($headers, self::HEADERS, $flag, $streamId);
                }

                if ($chunk === null) {
                    foreach ($request->getEventListeners() as $eventListener) {
                        yield $eventListener->completeSendingRequest($request, $stream);
                    }

                    return yield $http2stream->pendingResponse->promise();
                }

                $buffer = $chunk;
                while (null !== $chunk = yield $body->read()) {
                    if (!isset($this->streams[$streamId])) {
                        foreach ($request->getEventListeners() as $eventListener) {
                            yield $eventListener->completeSendingRequest($request, $stream);
                        }

                        return yield $http2stream->pendingResponse->promise();
                    }

                    yield $this->writeData($buffer, $streamId);

                    $buffer = $chunk;
                }

                if (!isset($this->streams[$streamId])) {
                    foreach ($request->getEventListeners() as $eventListener) {
                        yield $eventListener->completeSendingRequest($request, $stream);
                    }

                    return yield $http2stream->pendingResponse->promise();
                }

                $http2stream->bufferComplete = true;

                yield $this->writeData($buffer, $streamId);

                foreach ($request->getEventListeners() as $eventListener) {
                    yield $eventListener->completeSendingRequest($request, $stream);
                }

                return yield $http2stream->pendingResponse->promise();
            } catch (\Throwable $exception) {
                if (isset($this->streams[$streamId])) {
                    $this->releaseStream($streamId, $exception);
                }

                if ($exception instanceof StreamException) {
                    $exception = new SocketException('Failed to write request to socket: ' . $exception->getMessage());
                }

                throw $exception;
            } finally {
                $cancellationToken->unsubscribe($cancellationId);
            }
        });
    }

    public function isClosed(): bool
    {
        return $this->onClose === null;
    }

    private function run(): \Generator
    {
        try {
            yield $this->socket->write(self::PREFACE);

            yield $this->writeFrame(
                \pack(
                    "nNnNnNnN",
                    self::ENABLE_PUSH,
                    1,
                    self::MAX_CONCURRENT_STREAMS,
                    256,
                    self::INITIAL_WINDOW_SIZE,
                    self::DEFAULT_WINDOW_SIZE,
                    self::MAX_FRAME_SIZE,
                    self::DEFAULT_MAX_FRAME_SIZE
                ),
                self::SETTINGS
            );

            $parser = (new Http2Parser($this))->parse();

            while (null !== $chunk = yield $this->socket->read()) {
                $promise = $parser->send($chunk);

                \assert($promise === null || $promise instanceof Promise);

                while ($promise instanceof Promise) {
                    yield $promise; // Wait for promise to resolve before resuming parser and reading more data.
                    $promise = $parser->send(null);

                    \assert($promise === null || $promise instanceof Promise);
                }
            }

            $this->shutdown(null);
        } catch (\Throwable $exception) {
            $this->shutdown(null, $exception);
        }
    }

    private function writeFrame(string $data, int $type, int $flags = self::NO_FLAG, int $stream = 0): Promise
    {
        /** @noinspection PhpUnhandledExceptionInspection */
        return $this->socket->write(\substr(\pack("NccN", \strlen($data), $type, $flags, $stream), 1) . $data);
    }

    private function applySetting(int $setting, int $value): void
    {
        switch ($setting) {
            case self::INITIAL_WINDOW_SIZE:
                if ($value >= 1 << 31) {
                    $this->handleConnectionException(new Http2ConnectionException(
                        "Invalid window size: {$value}",
                        self::FLOW_CONTROL_ERROR
                    ));

                    return;
                }

                $priorWindowSize = $this->initialWindowSize;
                $this->initialWindowSize = $value;
                $difference = $this->initialWindowSize - $priorWindowSize;

                foreach ($this->streams as $stream) {
                    $stream->clientWindow += $difference;
                }

                // Settings ACK should be sent before HEADER or DATA frames.
                if ($difference > 0) {
                    Loop::defer(function () {
                        foreach ($this->streams as $stream) {
                            if ($this->clientWindow <= 0) {
                                return;
                            }

                            if ($stream->buffer === '' || $stream->clientWindow <= 0) {
                                continue;
                            }

                            $this->writeBufferedData($stream);
                        }
                    });
                }

                return;

            case self::MAX_FRAME_SIZE:
                if ($value < 1 << 14 || $value >= 1 << 24) {
                    $this->handleConnectionException(new Http2ConnectionException(
                        "Invalid maximum frame size: {$value}",
                        self::PROTOCOL_ERROR
                    ));

                    return;
                }

                $this->frameSizeLimit = $value;
                return;

            case self::MAX_CONCURRENT_STREAMS:
                if ($value >= 1 << 31) {
                    $this->handleConnectionException(new Http2ConnectionException(
                        "Invalid concurrent streams value: {$value}",
                        self::PROTOCOL_ERROR
                    ));

                    return;
                }

                $priorUsedStreams = $this->concurrentStreamLimit - $this->remainingStreams;

                $this->concurrentStreamLimit = $value;
                $this->remainingStreams = $this->concurrentStreamLimit - $priorUsedStreams;
                return;

            case self::HEADER_TABLE_SIZE: // TODO Respect this setting from the server
            case self::MAX_HEADER_LIST_SIZE: // TODO Respect this setting from the server
            case self::ENABLE_PUSH: // No action needed.
            default: // Unknown setting, ignore (6.5.2).
                return;
        }
    }

    private function writeBufferedData(Http2Stream $stream): Promise
    {
        $windowSize = \min($this->clientWindow, $stream->clientWindow);
        $length = \strlen($stream->buffer);

        if ($length <= $windowSize) {
            if ($stream->windowSizeIncrease) {
                $deferred = $stream->windowSizeIncrease;
                $stream->windowSizeIncrease = null;
                $deferred->resolve();
            }

            $this->clientWindow -= $length;
            $stream->clientWindow -= $length;

            if ($length > $this->frameSizeLimit) {
                $chunks = \str_split($stream->buffer, $this->frameSizeLimit);
                $stream->buffer = \array_pop($chunks);

                foreach ($chunks as $chunk) {
                    $this->writeFrame($chunk, self::DATA, self::NO_FLAG, $stream->id);
                }
            }

            if ($stream->bufferComplete) {
                $promise = $this->writeFrame($stream->buffer, self::DATA, self::END_STREAM, $stream->id);
            } else {
                $promise = $this->writeFrame($stream->buffer, self::DATA, self::NO_FLAG, $stream->id);
            }

            $stream->buffer = "";

            return $promise;
        }

        if ($windowSize > 0) {
            // Read next body chunk if less than 8192 bytes will remain in the buffer
            if ($length - 8192 < $windowSize && $stream->windowSizeIncrease) {
                $deferred = $stream->windowSizeIncrease;
                $stream->windowSizeIncrease = null;
                $deferred->resolve();
            }

            $data = $stream->buffer;
            $end = $windowSize - $this->frameSizeLimit;

            $stream->clientWindow -= $windowSize;
            $this->clientWindow -= $windowSize;

            for ($off = 0; $off < $end; $off += $this->frameSizeLimit) {
                $this->writeFrame(\substr($data, $off, $this->frameSizeLimit), self::DATA, self::NO_FLAG, $stream->id);
            }

            $promise = $this->writeFrame(
                \substr($data, $off, $windowSize - $off),
                self::DATA,
                self::NO_FLAG,
                $stream->id
            );

            $stream->buffer = \substr($data, $windowSize);

            return $promise;
        }

        if ($stream->windowSizeIncrease === null) {
            $stream->windowSizeIncrease = new Deferred;
        }

        return $stream->windowSizeIncrease->promise();
    }

    private function releaseStream(int $streamId, ?\Throwable $exception = null): void
    {
        \assert(isset($this->streams[$streamId]));

        $stream = $this->streams[$streamId];

        if ($stream->pendingResponse) {
            $pendingResponse = $stream->pendingResponse;
            $stream->pendingResponse = null;
            $pendingResponse->fail($exception ?? new Http2StreamException(
                "Stream closed unexpectedly",
                $streamId,
                self::INTERNAL_ERROR
            ));
        }

        if ($stream->body) {
            $body = $stream->body;
            $stream->body = null;
            $body->fail($exception ?? new Http2StreamException(
                "Stream closed unexpectedly",
                $streamId,
                self::INTERNAL_ERROR
            ));
        }

        if ($stream->trailers) {
            $trailers = $stream->trailers;
            $stream->trailers = null;
            $trailers->fail($exception ?? new Http2StreamException(
                "Stream closed unexpectedly",
                $streamId,
                self::INTERNAL_ERROR
            ));
        }

        unset($this->streams[$streamId]);

        if ($streamId & 1) { // Client-initiated stream.
            $this->remainingStreams++;
        }

        if (!$this->streams && !$this->socket->isClosed()) {
            $this->socket->unreference();
        }
    }

    private function setupPingIfIdle(): void
    {
        if ($this->idleWatcher !== null) {
            return;
        }

        $this->idleWatcher = Loop::defer(function ($watcher) {
            \assert($this->idleWatcher === null || $this->idleWatcher === $watcher);

            $this->idleWatcher = null;
            if (!empty($this->streams)) {
                return;
            }

            $this->idleWatcher = Loop::delay(300000, function ($watcher) {
                \assert($this->idleWatcher === null || $this->idleWatcher === $watcher);
                \assert(empty($this->streams));

                $this->idleWatcher = null;

                // Connection idle for 10 minutes
                if ($this->idlePings >= 1) {
                    $this->shutdown();
                    return;
                }

                if (yield $this->ping()) {
                    $this->setupPingIfIdle();
                }
            });

            Loop::unreference($this->idleWatcher);
        });

        Loop::unreference($this->idleWatcher);
    }

    private function cancelIdleWatcher(): void
    {
        if ($this->idleWatcher !== null) {
            Loop::cancel($this->idleWatcher);
            $this->idleWatcher = null;
        }
    }

    /**
     * @return Promise<bool> Fulfilled with true if a pong is received within the timeout, false if none is received.
     */
    private function ping(): Promise
    {
        if ($this->onClose === null) {
            return new Success(false);
        }

        if ($this->pongDeferred !== null) {
            return $this->pongDeferred->promise();
        }

        $this->pongDeferred = new Deferred;
        $this->idlePings++;

        $this->writeFrame($this->counter++, self::PING);

        $this->pongWatcher = Loop::delay(self::PONG_TIMEOUT, [$this, 'close']);

        return $this->pongDeferred->promise();
    }

    /**
     * @param int|null        $lastId ID of last processed frame. Null to use the last opened frame ID or 0 if no
     *                        streams have been opened.
     * @param \Throwable|null $reason
     *
     * @return Promise
     */
    private function shutdown(?int $lastId = null, ?\Throwable $reason = null): Promise
    {
        if ($this->onClose === null) {
            return new Success;
        }

        return call(function () use ($lastId, $reason) {
            $code = $reason ? $reason->getCode() : self::GRACEFUL_SHUTDOWN;
            $lastId = $lastId ?? ($this->streamId > 0 ? $this->streamId : 0);
            $goawayPromise = $this->writeFrame(\pack("NN", $lastId, $code), self::GOAWAY, self::NO_FLAG);

            if ($this->settings !== null) {
                $settings = $this->settings;
                $this->settings = null;
                $settings->fail($reason ?? new UnprocessedRequestException(new SocketException("Connection closed")));
            }

            if ($this->streams) {
                $reason = $reason ?? new SocketException("Connection closed");
                foreach ($this->streams as $id => $stream) {
                    $this->releaseStream($id, $id > $lastId ? new UnprocessedRequestException($reason) : $reason);
                }
            }

            if ($this->pongDeferred !== null) {
                $this->pongDeferred->resolve(false);
            }

            if ($this->pongWatcher !== null) {
                Loop::cancel($this->pongWatcher);
            }

            $this->cancelIdleWatcher();

            if ($this->onClose !== null) {
                $onClose = $this->onClose;
                $this->onClose = null;

                foreach ($onClose as $callback) {
                    asyncCall($callback, $this);
                }
            }

            yield $goawayPromise;

            $this->socket->close();
        });
    }

    private function generateHeaders(Request $request): \Generator
    {
        $uri = $request->getUri();

        $path = $uri->getPath();
        if ($path === '') {
            $path = '/';
        }

        $query = $uri->getQuery();
        if ($query !== '') {
            $path .= '?' . $query;
        }

        $headers = yield $request->getBody()->getHeaders();
        foreach ($headers as $name => $header) {
            if (!$request->hasHeader($name)) {
                $request->setHeaders([$name => $header]);
            }
        }

        $authority = $uri->getHost();
        if ($port = $uri->getPort()) {
            $authority .= ':' . $port;
        }

        $headers = \array_merge([
            ":authority" => [$authority],
            ":path" => [$path],
            ":scheme" => [$uri->getScheme()],
            ":method" => [$request->getMethod()],
        ], $request->getHeaders());

        return $headers;
    }

    private function writeData(Http2Stream $stream, string $data): Promise
    {
        $stream->buffer .= $data;

        return $this->writeBufferedData($stream);
    }
}