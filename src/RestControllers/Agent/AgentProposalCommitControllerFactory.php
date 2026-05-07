<?php

/**
 * AgentProposalCommitControllerFactory
 *
 * Production wiring for {@see AgentProposalCommitController}. The route
 * registration in ``apis/routes/_rest_routes_standard.inc.php`` invokes
 * {@see self::create()} so the controller can be assembled with the same
 * shared-secret / dispatcher / repository wiring used by every other
 * sidecar entry point.
 *
 * Tests construct the controller directly with stub collaborators (see
 * ``tests/Tests/Isolated/RestControllers/Agent/AgentProposalCommitControllerTest``)
 * so this factory is intentionally side-effect-only.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    OpenEMR Clinical Co-Pilot Sidecar Migration
 * @copyright Copyright (c) 2026 OpenEMR contributors
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\RestControllers\Agent;

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Services\Agent\Copilot\CopilotRunContextVerifier;
use OpenEMR\Services\Agent\Proposals\CommittedProposalRepository;
use OpenEMR\Services\Agent\Sidecar\Dispatcher\LabPdfDispatcher;
use OpenEMR\Services\Agent\Sidecar\Dispatcher\QueryUtilsSqlExecutor;

final class AgentProposalCommitControllerFactory
{
    /**
     * Default key version used for HMAC signing of CopilotRunContext tokens.
     * Mirrors the constant used by {@see AgentIntentRestController}; the
     * Python sidecar (M3/M4) maps ``v1`` to the shared secret configured via
     * ``OPENEMR_AGENT_SIDECAR_SECRET`` / ``AGENT_SHARED_SECRET``.
     */
    private const RUN_CONTEXT_KEY_VERSION = 'v1';

    /**
     * Site-relative storage directory for the JSON-file backed
     * idempotency cache. The constant in
     * {@see CommittedProposalRepository} documents the same path.
     */
    private const STORAGE_SUBDIR = 'documents/agent_proposals';

    public static function create(): AgentProposalCommitController
    {
        $logger = new SystemLogger();

        $secretResolver = static function (string $keyVersion): ?string {
            if ($keyVersion !== self::RUN_CONTEXT_KEY_VERSION) {
                return null;
            }
            $secret = getenv('OPENEMR_AGENT_SIDECAR_SECRET');
            if (is_string($secret) && $secret !== '') {
                return $secret;
            }
            $fallback = getenv('AGENT_SHARED_SECRET');
            return is_string($fallback) && $fallback !== '' ? $fallback : null;
        };

        $clock = static fn (): int => time();

        $verifier = new CopilotRunContextVerifier(
            secretResolver: $secretResolver,
            clock: $clock,
        );

        $dispatcher = new LabPdfDispatcher(
            sql: new QueryUtilsSqlExecutor(),
            logger: $logger,
            clock: ServiceContainer::getClock(),
        );

        $repository = new CommittedProposalRepository(
            storageDirectory: self::resolveStorageDirectory(),
            logger: $logger,
        );

        return new AgentProposalCommitController(
            runContextVerifier: $verifier,
            labPdfDispatcher: $dispatcher,
            committedProposalRepository: $repository,
            logger: $logger,
        );
    }

    private static function resolveStorageDirectory(): string
    {
        if (defined('OE_SITE_DIR')) {
            $base = (string) constant('OE_SITE_DIR');
            if ($base !== '') {
                return rtrim($base, "/\\") . DIRECTORY_SEPARATOR . self::STORAGE_SUBDIR;
            }
        }

        $sitesRoot = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'sites'
            . DIRECTORY_SEPARATOR . 'default';
        return $sitesRoot . DIRECTORY_SEPARATOR . self::STORAGE_SUBDIR;
    }
}
