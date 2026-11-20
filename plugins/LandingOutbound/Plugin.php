<?php

namespace Plugin\LandingOutbound;

use App\Models\Server;
use App\Models\User;
use App\Services\Plugin\AbstractPlugin;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class Plugin extends AbstractPlugin
{
    /**
     * 出站引用：自定义出站条目里带 node_id 且不带 protocol 时，
     * 视为对面板内落地节点的引用，下发配置时解析为完整出站。
     *
     * 写法示例（节点高级设置 → 自定义出站）：
     *   [{"node_id": 60, "tag": "sg-ss"}]
     *
     * 解析后下发给节点的是标准 Shadowsocks 出站（与手工写的
     * wa-ss 同格式），落地节点的地址、端口、加密方式、密码
     * 改动后自动跟随，无需再手工同步。
     */
    public function boot(): void
    {
        $this->filter('server.config.build', [$this, 'resolveLandingOutbounds'], 10);

        // Octane 下 boot() 每个请求都会跑，模型监听器只注册一次，
        // 否则同一个 worker 里请求越多，保存时校验就重复跑越多遍。
        if (!self::$savingListenerRegistered) {
            Server::saving(function (Server $server) {
                $this->validateOutboundReferences($server);
            });
            self::$savingListenerRegistered = true;
        }
    }

    protected static bool $savingListenerRegistered = false;

    /**
     * server.config.build 过滤器：解析自定义出站里的落地节点引用。
     */
    public function resolveLandingOutbounds(array $response, Server $node): array
    {
        $outbounds = $response['custom_outbounds'] ?? null;
        if (empty($outbounds) || !is_array($outbounds)) {
            return $response;
        }

        $relayUser = $this->relayUser();
        $droppedTags = [];
        $resolved = [];

        foreach ($outbounds as $index => $entry) {
            if (!$this->isLandingReference($entry)) {
                $resolved[] = $entry;
                continue;
            }

            $built = $relayUser ? $this->buildOutbound($entry, $node, $relayUser) : null;
            if ($built === null) {
                $tag = $this->referenceTag($entry);
                if ($tag !== '') {
                    $droppedTags[] = strtolower($tag);
                }
                Log::warning('[landing-outbound] 无法解析出站引用，已跳过该出站', [
                    'node_id' => $node->id,
                    'node_name' => $node->name ?? null,
                    'index' => $index,
                    'entry' => $entry,
                ]);
                continue;
            }

            $resolved[] = $built;
        }

        if ($resolved === []) {
            unset($response['custom_outbounds']);
        } else {
            $response['custom_outbounds'] = array_values($resolved);
        }

        // 引用解析失败时，指向该出站的路由规则也会让节点整份配置校验失败，
        // 一并去掉（面板日志里留了 warning，见上）。
        if ($droppedTags !== []) {
            $response['custom_routes'] = $this->dropRoutesForTags(
                $response['custom_routes'] ?? null,
                $droppedTags,
                $node
            );
            if (($response['custom_routes'] ?? []) === []) {
                unset($response['custom_routes']);
            }
        }

        return $response;
    }

    /**
     * 保存节点时校验出站引用，错了当场报错，不让坏引用存进库。
     */
    public function validateOutboundReferences(Server $server): void
    {
        $outbounds = $server->custom_outbounds;
        if (empty($outbounds) || !is_array($outbounds)) {
            return;
        }

        $seenTags = [];
        foreach ($outbounds as $index => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            // 全量出站与引用共用 tag 命名空间：先收 tag，再判重。
            $tag = isset($entry['tag']) ? trim((string) $entry['tag']) : '';
            if ($tag === '' && $this->isLandingReference($entry)) {
                $tag = $this->referenceTag($entry);
            }
            if ($tag !== '') {
                $lower = strtolower($tag);
                if (in_array($lower, ['direct', 'block'], true)) {
                    $this->fail("自定义出站第 {$index} 条：tag 不能用系统保留的 direct/block。");
                }
                if (isset($seenTags[$lower])) {
                    $this->fail("自定义出站第 {$index} 条：tag \"{$tag}\" 与第 {$seenTags[$lower]} 条重复。");
                }
                $seenTags[$lower] = $index;
            }

            if (!$this->isLandingReference($entry)) {
                continue;
            }

            $this->validateReference($entry, $server, $index);
        }

        // 有引用时才需要中转用户；没有引用就不碰插件配置。
        if ($this->hasLandingReference($outbounds) && $this->relayUser() === null) {
            $this->fail('自定义出站引用了落地节点，但插件配置的中转用户不存在，请先在插件配置里改成有效的用户 ID。');
        }
    }

    protected function validateReference(array $entry, Server $server, int $index): void
    {
        $landingId = (int) ($entry['node_id'] ?? 0);
        if ($landingId <= 0) {
            $this->fail("自定义出站第 {$index} 条：node_id 须为正整数（面板内的节点 ID）。");
        }
        if ($server->id && $landingId === (int) $server->id) {
            $this->fail("自定义出站第 {$index} 条：不能引用节点自己作为落地。");
        }

        $landing = Server::find($landingId);
        if ($landing === null) {
            $this->fail("自定义出站第 {$index} 条：节点 {$landingId} 不存在。");
        }
        if ($landing->type !== Server::TYPE_SHADOWSOCKS) {
            $this->fail("自定义出站第 {$index} 条：节点 {$landingId}（{$landing->name}）不是 SS 协议，v1 只支持 SS 落地。");
        }
        if (trim((string) ($landing->host ?? '')) === '' || (int) ($landing->server_port ?? 0) <= 0) {
            $this->fail("自定义出站第 {$index} 条：节点 {$landingId}（{$landing->name}）没有可用的地址或端口。");
        }
        if (empty(data_get($landing->protocol_settings, 'cipher'))) {
            $this->fail("自定义出站第 {$index} 条：节点 {$landingId}（{$landing->name}）没有配置加密方式。");
        }
    }

    /**
     * 把一条引用解析为完整 Shadowsocks 出站，解析不了返回 null。
     */
    protected function buildOutbound(array $entry, Server $node, User $relayUser): ?array
    {
        $landingId = (int) ($entry['node_id'] ?? 0);
        if ($landingId <= 0 || ($node->id && $landingId === (int) $node->id)) {
            return null;
        }

        $landing = Server::find($landingId);
        if ($landing === null || $landing->type !== Server::TYPE_SHADOWSOCKS) {
            return null;
        }

        $host = trim((string) ($landing->host ?? ''));
        $port = (int) ($landing->server_port ?? 0);
        $cipher = data_get($landing->protocol_settings, 'cipher');
        if ($host === '' || $port <= 0 || empty($cipher)) {
            return null;
        }

        $settings = [
            'server' => $host,
            'server_port' => $port,
            'method' => $cipher,
            // 与订阅下发的用户密码同一来源：serverKey:userKey，
            // 落地节点以该用户身份认证这条出站。
            'password' => $landing->generateServerPassword($relayUser),
        ];
        $plugin = data_get($landing->protocol_settings, 'plugin');
        $pluginOpts = data_get($landing->protocol_settings, 'plugin_opts');
        if (!empty($plugin) && !empty($pluginOpts)) {
            $settings['plugin'] = $plugin;
            $settings['plugin_opts'] = $pluginOpts;
        }

        $outbound = [
            'tag' => $this->referenceTag($entry),
            'protocol' => 'shadowsocks',
            'settings' => $settings,
        ];
        if (!empty($entry['proxy_tag'])) {
            $outbound['proxy_tag'] = $entry['proxy_tag'];
        }

        return $outbound;
    }

    /**
     * 去掉指向已丢弃出站的路由规则，避免节点整份配置校验失败。
     */
    protected function dropRoutesForTags($routes, array $droppedTags, Server $node): array
    {
        if (empty($routes) || !is_array($routes)) {
            return [];
        }

        $kept = [];
        foreach ($routes as $index => $rule) {
            $isRoute = is_array($rule)
                && strtolower(trim((string) data_get($rule, 'action.type'))) === 'route';
            $target = $isRoute ? strtolower(trim((string) data_get($rule, 'action.target'))) : '';
            if ($isRoute && in_array($target, $droppedTags, true)) {
                Log::warning('[landing-outbound] 出站引用解析失败，相关路由规则已一并跳过', [
                    'node_id' => $node->id,
                    'node_name' => $node->name ?? null,
                    'index' => $index,
                    'target' => $target,
                ]);
                continue;
            }
            $kept[] = $rule;
        }

        return array_values($kept);
    }

    protected function isLandingReference(mixed $entry): bool
    {
        return is_array($entry)
            && isset($entry['node_id'])
            && !isset($entry['protocol']);
    }

    protected function hasLandingReference(array $outbounds): bool
    {
        foreach ($outbounds as $entry) {
            if ($this->isLandingReference($entry)) {
                return true;
            }
        }

        return false;
    }

    protected function referenceTag(array $entry): string
    {
        $tag = isset($entry['tag']) ? trim((string) $entry['tag']) : '';

        return $tag !== '' ? $tag : 'landing-' . (int) ($entry['node_id'] ?? 0);
    }

    protected function relayUser(): ?User
    {
        $userId = (int) $this->getConfig('relay_user_id', 1);
        if ($userId <= 0) {
            return null;
        }

        return User::find($userId);
    }

    protected function fail(string $message): never
    {
        throw ValidationException::withMessages(['custom_outbounds' => $message]);
    }
}
