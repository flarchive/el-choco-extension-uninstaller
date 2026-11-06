<?php
namespace ElChoco\ExtensionUninstaller;

use Flarum\Foundation\Paths;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class UninstallerController implements RequestHandlerInterface
{
    private Paths $paths;
    private SettingsRepositoryInterface $settings;
    private $identity;

    public function __construct(Paths $paths, SettingsRepositoryInterface $settings, ?callable $identity = null) { $this->paths = $paths; $this->settings = $settings; $this->identity = $identity ?: [RuntimeDiagnostics::class, 'uid']; }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertPermission($actor->isAdmin()); $actor->assertAdmin();
        try {
            $store = new JobStore($this->paths->storage);
            if ($request->getMethod() === 'GET') {
                $query = $request->getQueryParams();
                if (isset($query['job'])) {
                    $job = is_string($query['job']) ? $store->read($query['job']) : null;
                    if (!$job) return $this->respond(['message' => 'Job not found.'], 404);
                    if ($job['needsAutoloadRefresh']) $job['opcache'] = RuntimeDiagnostics::invalidateAutoload($this->paths->base);
                    $job['workerSeen'] = $store->workerSeen();
                    return $this->respond($job);
                }
                $enabled = json_decode($this->settings->get('extensions_enabled', '[]'), true, 512, JSON_THROW_ON_ERROR);
                $active = null;
                foreach ($store->all() as $job) if (in_array($job['status'], ['queued', 'running', 'interrupted'], true)) { $active = $job['id']; break; }
                return $this->respond(['extensions' => Inventory::extensions($this->paths->base, $enabled), 'history' => $store->history(),
                    'activeJob' => $active, 'workerSeen' => $store->workerSeen(), 'uid' => RuntimeDiagnostics::uid(), 'version' => '0.1.0-rc.1']);
            }
            if ($request->getMethod() !== 'POST') return $this->respond(['message' => 'Method not allowed.'], 405);
            $uid = ($this->identity)();
            if (!$uid) return $this->respond(['message' => 'Web PHP must run as an identifiable non-root user.'], 503);
            $body = $request->getParsedBody();
            if (!is_array($body)) throw new \InvalidArgumentException('Invalid request.');
            $requestId = is_string($body['requestId'] ?? null) ? $body['requestId'] : '';
            if (!preg_match('/^[a-f0-9]{32}$/D', $requestId)) throw new \InvalidArgumentException('Invalid request ID.');
            $action = $body['action'] ?? '';
            $existing = $store->read($requestId);
            if ($existing) {
                $original = json_decode($existing['command'], true);
                $same = $existing['actor'] === (string) $actor->id && ($original['action'] ?? null) === $action;
                if ($action === 'execute') $same = $same && ($original['plan'] ?? null) === ($body['plan'] ?? null);
                elseif ($action === 'preview') $same = $same && ($original['package'] ?? null) === ($body['package'] ?? null) && ($original['mode'] ?? null) === ($body['mode'] ?? null);
                else $same = false;
                if (!$same) throw new \InvalidArgumentException('Request ID already used.');
                return $this->respond($existing);
            }
            if ($action === 'preview') {
                $command = ['action' => 'preview', 'package' => Inventory::packageName((string) ($body['package'] ?? '')), 'mode' => PlanService::mode((string) ($body['mode'] ?? ''))];
            } elseif ($action === 'execute') {
                $id = is_string($body['plan'] ?? null) ? $body['plan'] : '';
                $plan = $store->record($id);
                if (!$plan || $plan['actor'] !== (string) $actor->id || $plan['expires'] < time() || $plan['usedBy'] !== null) throw new \InvalidArgumentException('Preview expired or unavailable.');
                $confirmation = is_string($body['confirmation'] ?? null) ? trim($body['confirmation']) : '';
                if ($confirmation !== PlanService::confirmationWord($plan['mode']) || (($plan['mode'] === 'purge') && ($body['purgeConfirmed'] ?? false) !== true)) throw new \InvalidArgumentException('Enter '.PlanService::confirmationWord($plan['mode']).' to confirm this action. Database cleanup also requires its separate checkbox.');
                $command = ['action' => 'execute', 'plan' => $id];
            } else throw new \InvalidArgumentException('Unknown request action.');
            $job = $store->enqueue(json_encode($command, JSON_THROW_ON_ERROR), (string) $actor->id, $uid, is_string($body['requestId'] ?? null) ? $body['requestId'] : '');
            return $this->respond($job, 202);
        } catch (\InvalidArgumentException $e) { return $this->respond(['message' => $e->getMessage()], 422); }
        catch (\Throwable $e) { return $this->respond(['message' => $e->getMessage()], 409); }
    }

    private function respond(array $data, int $status = 200): ResponseInterface
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache']);
    }
}
