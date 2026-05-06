<?php

/**
 * CopilotSidecarClient
 *
 * Thin HTTP client for the Python sidecar's ``POST /api/copilot/run``
 * endpoint. Used by {@see \OpenEMR\RestControllers\Agent\AgentIntentRestController}
 * (M17) to proxy a validated request through the sidecar and surface
 * a typed {@see CopilotRunResponseDto} to the UI layer.
 *
 * Failures are translated into {@see CopilotSidecarException}, which
 * carries a typed ``reason`` (``context_rejected``, ``sidecar_not_ready``,
 * or ``sidecar_error``) so the controller can map them onto safe
 * user-visible HTTP responses without leaking provider error messages.
 *
 * Uses Guzzle (already a project dependency) so tests can inject a
 * MockHandler without touching the network. Direct ``curl_*`` use is
 * forbidden by project PHPStan rules.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Agent\Sidecar;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Psr\Log\LoggerInterface;
use Throwable;

final class CopilotSidecarClient
{
    private const ENDPOINT = '/api/copilot/run';
    private const CONNECT_TIMEOUT_SECONDS = 5;

    private readonly ClientInterface $httpClient;

    public function __construct(
        private readonly AgentSidecarConfig $config,
        private readonly LoggerInterface $logger,
        ?ClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? new Client();
    }

    /**
     * Invoke the sidecar's ``POST /api/copilot/run`` endpoint.
     *
     * @throws CopilotSidecarException When the sidecar is unreachable,
     *                                 returns a non-2xx status, returns
     *                                 unparseable JSON, or is not
     *                                 configured.
     */
    public function runCopilot(CopilotRunRequestDto $request): CopilotRunResponseDto
    {
        $this->guardConfigured();

        $url = $this->config->getUrl() . self::ENDPOINT;
        $traceId = $request->requestId;

        $this->logger->info('agent.copilot.sidecar.request_started', [
            'request_id' => $traceId,
            'intent_id' => $request->intentId,
            'has_user_goal' => $request->userGoal !== null,
        ]);

        try {
            $response = $this->httpClient->request('POST', $url, [
                'json' => $request->toArray(),
                'headers' => [
                    'X-Agent-Secret' => $this->config->getSharedSecret(),
                    'Accept' => 'application/json',
                ],
                'timeout' => $this->config->getTimeoutSeconds(),
                'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
                'http_errors' => false,
            ]);
        } catch (ConnectException $e) {
            $this->logger->error('agent.copilot.sidecar.connect_failed', [
                'request_id' => $traceId,
                'exception' => $e,
            ]);
            throw new CopilotSidecarException(
                message: 'Copilot sidecar is unreachable.',
                reason: CopilotSidecarException::REASON_SIDECAR_ERROR,
                httpStatus: 0,
                previous: $e,
            );
        } catch (GuzzleException $e) {
            $this->logger->error('agent.copilot.sidecar.transport_failed', [
                'request_id' => $traceId,
                'exception' => $e,
            ]);
            throw new CopilotSidecarException(
                message: 'Copilot sidecar request failed.',
                reason: CopilotSidecarException::REASON_SIDECAR_ERROR,
                httpStatus: 0,
                previous: $e,
            );
        }

        $statusCode = $response->getStatusCode();
        $body = $response->getBody()->getContents();

        if ($statusCode === 401) {
            $this->logger->warning('agent.copilot.sidecar.context_rejected', [
                'request_id' => $traceId,
                'status_code' => $statusCode,
            ]);
            throw new CopilotSidecarException(
                message: 'Copilot sidecar rejected the run context.',
                reason: CopilotSidecarException::REASON_CONTEXT_REJECTED,
                httpStatus: $statusCode,
            );
        }

        if ($statusCode === 501) {
            $this->logger->warning('agent.copilot.sidecar.not_ready', [
                'request_id' => $traceId,
                'status_code' => $statusCode,
            ]);
            throw new CopilotSidecarException(
                message: 'Copilot sidecar agent loop is not yet enabled.',
                reason: CopilotSidecarException::REASON_SIDECAR_NOT_READY,
                httpStatus: $statusCode,
            );
        }

        if ($statusCode >= 400) {
            $this->logger->error('agent.copilot.sidecar.http_error', [
                'request_id' => $traceId,
                'status_code' => $statusCode,
            ]);
            throw new CopilotSidecarException(
                message: 'Copilot sidecar returned an error response.',
                reason: CopilotSidecarException::REASON_SIDECAR_ERROR,
                httpStatus: $statusCode,
            );
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->logger->error('agent.copilot.sidecar.invalid_json', [
                'request_id' => $traceId,
                'status_code' => $statusCode,
            ]);
            throw new CopilotSidecarException(
                message: 'Copilot sidecar returned invalid JSON.',
                reason: CopilotSidecarException::REASON_SIDECAR_ERROR,
                httpStatus: $statusCode,
                previous: $e,
            );
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            $this->logger->error('agent.copilot.sidecar.invalid_shape', [
                'request_id' => $traceId,
                'status_code' => $statusCode,
            ]);
            throw new CopilotSidecarException(
                message: 'Copilot sidecar returned a non-object response.',
                reason: CopilotSidecarException::REASON_SIDECAR_ERROR,
                httpStatus: $statusCode,
            );
        }

        try {
            /** @var array<string, mixed> $decoded */
            $result = CopilotRunResponseDto::fromArray($decoded);
        } catch (Throwable $e) {
            $this->logger->error('agent.copilot.sidecar.parse_failed', [
                'request_id' => $traceId,
                'status_code' => $statusCode,
                'exception' => $e,
            ]);
            throw new CopilotSidecarException(
                message: 'Copilot sidecar response could not be parsed.',
                reason: CopilotSidecarException::REASON_SIDECAR_ERROR,
                httpStatus: $statusCode,
                previous: $e,
            );
        }

        $this->logger->info('agent.copilot.sidecar.request_succeeded', [
            'request_id' => $traceId,
            'status_code' => $statusCode,
            'verification_status' => $result->verificationStatus,
            'citation_count' => count($result->citations),
            'answer_block_count' => count($result->answerBlocks),
        ]);

        return $result;
    }

    /**
     * @throws CopilotSidecarException When the sidecar URL or shared secret is missing.
     */
    private function guardConfigured(): void
    {
        if ($this->config->isConfigured()) {
            return;
        }

        $issue = $this->config->getConfigurationIssue() ?? 'unknown';
        $this->logger->error('agent.copilot.sidecar.not_configured', [
            'issue' => $issue,
        ]);
        throw new CopilotSidecarException(
            message: 'Copilot sidecar is not configured.',
            reason: CopilotSidecarException::REASON_SIDECAR_ERROR,
            httpStatus: 0,
        );
    }
}
