<?php

declare(strict_types=1);

namespace BlackCat\Config\Runtime;

if (!class_exists(__NAMESPACE__ . '\\Config', false)) {
    final class Config
    {
        private static ?object $repo = null;

        public static function isInitialized(): bool
        {
            return self::$repo !== null;
        }

        public static function repo(): object
        {
            if (self::$repo === null) {
                throw new \RuntimeException('Config is not initialized.');
            }
            return self::$repo;
        }

        public static function _setRepo(object $repo): void
        {
            self::$repo = $repo;
        }

        public static function _clearRepo(): void
        {
            self::$repo = null;
        }
    }
}

if (!class_exists(__NAMESPACE__ . '\\RuntimeDoctor', false)) {
    final class RuntimeDoctor
    {
        /** @var list<array{severity:string,code:string,message:string}> */
        public static array $findings = [];

        /**
         * @return array{findings:list<array{severity:string,code:string,message:string}>}
         */
        public static function inspect(object $repo): array
        {
            return ['findings' => self::$findings];
        }
    }
}

namespace BlackCat\Core\Tests\TrustKernel;

use BlackCat\Config\Runtime\Config as RuntimeConfig;
use BlackCat\Config\Runtime\RuntimeDoctor;
use BlackCat\Core\Tests\TrustKernel\AttackFlows\Support\Abi;
use BlackCat\Core\Tests\TrustKernel\AttackFlows\Support\IntegrityFixture;
use BlackCat\Core\Tests\TrustKernel\AttackFlows\Support\ScenarioTransport;
use BlackCat\Core\TrustKernel\TrustKernel;
use BlackCat\Core\TrustKernel\TrustKernelConfig;
use PHPUnit\Framework\TestCase;

final class LessStrictCgiFixPathInfoWaiverTest extends TestCase
{
    private const FORCE_HARDENING_FLAG = 'BLACKCAT_TRUSTKERNEL_TEST_FORCE_HARDENING';

    public function testLessStrictRequiresLockedNoExecProbeAttestationWhenCgiFixPathInfoEnabled(): void
    {
        $fixture = IntegrityFixture::create(['app.txt' => 'ok'], null);

        $prevRequestTime = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
        $prevMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $prevUri = $_SERVER['REQUEST_URI'] ?? null;
        $prevForce = $_SERVER[self::FORCE_HARDENING_FLAG] ?? null;

        $repoRef = new \ReflectionProperty(RuntimeConfig::class, 'repo');
        $repoRef->setAccessible(true);
        $prevRepo = $repoRef->getValue();
        $prevFindings = RuntimeDoctor::$findings;

        try {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/';
            $_SERVER['REQUEST_TIME_FLOAT'] = 1000.000001;
            $_SERVER[self::FORCE_HARDENING_FLAG] = '1';

            RuntimeConfig::_setRepo(new \stdClass());
            RuntimeDoctor::$findings = [
                [
                    'severity' => 'warn',
                    'code' => 'php_cgi_fix_pathinfo_enabled',
                    'message' => 'cgi.fix_pathinfo is enabled',
                ],
            ];

            $cfg = new TrustKernelConfig(
                chainId: 4207,
                rpcEndpoints: ['https://a', 'https://b'],
                rpcQuorum: 2,
                maxStaleSec: 60,
                mode: 'root_uri',
                instanceController: '0x1111111111111111111111111111111111111111',
                releaseRegistry: null,
                integrityRootDir: $fixture->rootDir,
                integrityManifestPath: $fixture->manifestPath,
                rpcTimeoutSec: 1,
            );

            $snapshot = Abi::snapshotResult(
                version: 1,
                paused: false,
                activeRoot: $fixture->rootBytes32,
                activeUriHash: '0x' . str_repeat('00', 32),
                activePolicyHash: $cfg->policyHashV2LessStrict,
                pendingRoot: '0x' . str_repeat('00', 32),
                pendingUriHash: '0x' . str_repeat('00', 32),
                pendingPolicyHash: '0x' . str_repeat('00', 32),
                pendingCreatedAt: 0,
                pendingTtlSec: 0,
                genesisAt: 0,
                lastUpgradeAt: 0,
            );

            $key = strtolower($cfg->cgiFixPathInfoProbeAttestationKeyV1);
            $expectedValue = strtolower($cfg->cgiFixPathInfoProbeNoExecAttestationValueV1);

            $attCall = '0x940992a3' . substr($key, 2);
            $lockedCall = '0xa93a4e86' . substr($key, 2);

            $mkKernel = static function (string $attValueBytes32, bool $locked) use ($cfg, $snapshot, $attCall, $lockedCall): TrustKernel {
                $transport = new ScenarioTransport(static function (string $url, array $req, int $timeoutSec, int $callIndex) use ($snapshot, $attValueBytes32, $locked, $attCall, $lockedCall): string {
                    $method = $req['method'] ?? null;
                    $params = $req['params'] ?? null;

                    // Trigger Web3RpcQuorumClient sequential fallback for batch-incompatible transports.
                    if (!is_string($method)) {
                        return json_encode(['jsonrpc' => '2.0', 'id' => 1, 'error' => ['message' => 'batch not supported']], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                    }

                    if ($method === 'eth_chainId') {
                        return json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x106f'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                    }

                    if ($method === 'eth_call' && is_array($params) && isset($params[0]) && is_array($params[0])) {
                        $data = $params[0]['data'] ?? null;
                        if (is_string($data)) {
                            $data = strtolower($data);
                            if ($data === '0x9711715a') { // snapshot()
                                return json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => $snapshot], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                            }
                            if ($data === '0x19ee073e') { // releaseRegistry()
                                return json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => Abi::addressResult('0x0000000000000000000000000000000000000000')], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                            }
                            if ($data === $attCall) { // attestations(key)
                                return json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => strtolower($attValueBytes32)], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                            }
                            if ($data === $lockedCall) { // attestationLocked(key)
                                return json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => Abi::boolResult($locked)], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                            }
                        }
                    }

                    return json_encode(['jsonrpc' => '2.0', 'id' => 1, 'error' => ['message' => 'unexpected method']], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                });

                return new TrustKernel($cfg, null, $transport);
            };

            // 1) Missing waiver (unset -> 0x00..): should fail-closed in less-strict.
            $kernel1 = $mkKernel('0x' . str_repeat('00', 32), true);
            $st1 = $kernel1->check();
            self::assertFalse($st1->trustedNow);
            self::assertContains('cgi_fix_pathinfo_probe_missing', $st1->errorCodes);

            // 2) Wrong value: should fail-closed.
            $kernel2 = $mkKernel('0x' . str_repeat('11', 32), true);
            $st2 = $kernel2->check();
            self::assertFalse($st2->trustedNow);
            self::assertContains('cgi_fix_pathinfo_probe_mismatch', $st2->errorCodes);

            // 3) Correct value but unlocked: should fail-closed.
            $kernel3 = $mkKernel($expectedValue, false);
            $st3 = $kernel3->check();
            self::assertFalse($st3->trustedNow);
            self::assertContains('cgi_fix_pathinfo_probe_unlocked', $st3->errorCodes);

            // 4) Correct value + locked: should be trusted.
            $kernel4 = $mkKernel($expectedValue, true);
            $st4 = $kernel4->check();
            self::assertTrue($st4->trustedNow, implode(' | ', $st4->errors));
            self::assertNotContains('cgi_fix_pathinfo_probe_missing', $st4->errorCodes);
            self::assertNotContains('cgi_fix_pathinfo_probe_mismatch', $st4->errorCodes);
            self::assertNotContains('cgi_fix_pathinfo_probe_unlocked', $st4->errorCodes);
        } finally {
            $repoRef->setValue($prevRepo);
            RuntimeDoctor::$findings = $prevFindings;

            if ($prevRequestTime === null) {
                unset($_SERVER['REQUEST_TIME_FLOAT']);
            } else {
                $_SERVER['REQUEST_TIME_FLOAT'] = $prevRequestTime;
            }
            if ($prevMethod === null) {
                unset($_SERVER['REQUEST_METHOD']);
            } else {
                $_SERVER['REQUEST_METHOD'] = $prevMethod;
            }
            if ($prevUri === null) {
                unset($_SERVER['REQUEST_URI']);
            } else {
                $_SERVER['REQUEST_URI'] = $prevUri;
            }
            if ($prevForce === null) {
                unset($_SERVER[self::FORCE_HARDENING_FLAG]);
            } else {
                $_SERVER[self::FORCE_HARDENING_FLAG] = $prevForce;
            }

            $fixture->cleanup();
        }
    }
}
