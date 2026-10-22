<?php

declare(strict_types=1);

namespace Dashboard\Ollama;

use Dashboard\DB\Database;
use Dashboard\Deploy\DeployService;
use Dashboard\Settings\ServiceControl;
use Dashboard\Support\ForbiddenException;
use Dashboard\Support\ValidationException;

final class OllamaService
{
    public function __construct(
        private readonly Database $db,
        private readonly ?ServiceControl $control = null,
        private readonly ?OllamaClient $client = null,
        private readonly string $stateDir = '/var/lib/lifedash/ollama',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function view(): array
    {
        $stored = (new OllamaSettings($this->db))->get();
        $plan = $this->readJson('plan.json');
        $job = $this->readJson('status.json');
        $effective = OllamaSettings::effective($stored, $plan);
        $client = $this->client ?? new OllamaClient();
        $models = $client->models($effective['base_url']);
        $apiOk = $models !== null;
        $names = $apiOk ? array_column($models, 'name') : [];
        $hosts = [];
        if (is_array($job) && isset($job['listen']) && is_array($job['listen'])) {
            foreach ($job['listen'] as $host) {
                if (is_string($host) && $host !== '') {
                    $hosts[] = $host;
                }
            }
        }
        $pullState = is_array($job) ? (string) ($job['state'] ?? '') : '';
        $pullDetail = is_array($job) ? (string) ($job['detail'] ?? '') : '';
        $health = OllamaReport::assess([
            'installed'   => $apiOk || is_array($job) || is_array($plan),
            'systemd'     => $apiOk ? 'active' : 'unknown',
            'api_ok'      => $apiOk,
            'hosts'       => $hosts,
            'models'      => $names,
            'chat'        => $effective['chat_model'],
            'embed'       => $effective['embed_model'],
            'pull_state'  => $pullState,
            'pull_detail' => $pullDetail,
        ]);

        return [
            'settings'  => $stored,
            'effective' => $effective,
            'hardware'  => [
                'accelerator' => is_array($plan) ? (string) ($plan['accelerator'] ?? '') : '',
                'ram_mib'     => is_array($plan) ? (int) ($plan['ram_mib'] ?? 0) : 0,
                'vram_mib'    => is_array($plan) ? (int) ($plan['vram_mib'] ?? 0) : 0,
                'chat_model'  => is_array($plan) ? (string) ($plan['chat_model'] ?? '') : '',
                'embed_model' => is_array($plan) ? (string) ($plan['embed_model'] ?? '') : '',
            ],
            'job' => [
                'state'      => $pullState !== '' ? $pullState : 'idle',
                'detail'     => $pullDetail,
                'model'      => is_array($job) ? (string) ($job['model'] ?? '') : '',
                'updated_at' => is_array($job) ? (string) ($job['updated_at'] ?? '') : '',
            ],
            'models'  => $models ?? [],
            'api_ok'  => $apiOk,
            'version' => $apiOk ? $client->version($effective['base_url']) : null,
            'health'  => $health,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{base_url: string, chat_model: string, embed_model: string}
     */
    public function save(array $input): array
    {
        return (new OllamaSettings($this->db))->save(
            (string) ($input['base_url'] ?? ''),
            (string) ($input['chat_model'] ?? ''),
            (string) ($input['embed_model'] ?? OllamaEndpoint::DEFAULT_EMBED)
        );
    }

    /**
     * @return array{ok: bool, version: ?string, detail: string}
     */
    public function test(): array
    {
        $effective = OllamaSettings::effective((new OllamaSettings($this->db))->get(), $this->readJson('plan.json'));
        $client = $this->client ?? new OllamaClient();
        $version = $client->version($effective['base_url']);
        if ($version === null) {
            return [
                'ok'      => false,
                'version' => null,
                'detail'  => 'Ollama did not answer at ' . $effective['base_url'] . '.',
            ];
        }

        return [
            'ok'      => true,
            'version' => $version,
            'detail'  => 'Ollama API answered.',
        ];
    }

    /**
     * @return array{models: list<array{name: string, size: int, modified_at: string}>}
     */
    public function modelList(): array
    {
        $view = $this->view();

        return ['models' => $view['models']];
    }

    /**
     * @return array{model: string, text: string}
     */
    public function generate(string $prompt, ?string $model): array
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            throw ValidationException::withFields(['prompt' => 'Prompt is required.']);
        }
        if (mb_strlen($prompt) > 4000) {
            throw ValidationException::withFields(['prompt' => 'Prompt must be 4000 characters or less.']);
        }
        $effective = OllamaSettings::effective((new OllamaSettings($this->db))->get(), $this->readJson('plan.json'));
        $chosen = $model === null || trim($model) === ''
            ? $effective['chat_model']
            : OllamaEndpoint::model($model, 'model', true);
        $text = ($this->client ?? new OllamaClient())->generate($effective['base_url'], $chosen, $prompt);

        return ['model' => $chosen, 'text' => $text];
    }

    /**
     * @return array{id: string, action: string, queued: bool, model: string}
     */
    public function queue(string $action, string $model): array
    {
        $name = OllamaEndpoint::model($model, 'model', true);
        $control = $this->control ?? new ServiceControl();
        $queued = $control->request($action, ['model' => $name]);

        return $queued + ['model' => $name];
    }

    public static function assertChange(?int $tokenId, bool $mcpUpdatesEnabled): void
    {
        if (!DeployService::mcpRequestAllowed($mcpUpdatesEnabled, $tokenId)) {
            throw new ForbiddenException(DeployService::MCP_DISABLED);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJson(string $name): ?array
    {
        $path = rtrim($this->stateDir, '/') . '/' . $name;
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($data) ? $data : null;
    }
}
