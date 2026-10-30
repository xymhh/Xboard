# Fork 说明（xymhh/Xboard）

本仓库是 [cedar2025/Xboard](https://github.com/cedar2025/Xboard) 的 fork，基线 `master@e68fec5`。
本 fork 只放**我们自己要用的**小改动，保持与上游差异极小，便于 `git fetch upstream && git merge upstream/master`。

## 改动 1：节点 `host` 变更不触发配置推送（1 行）

**现象**：改节点的 `host` 字段（trojan / hysteria 用它做 SNI；其它协议作为 `server_name` 兜底），
节点不会立即收到推送。节点在 **WS 连接期间会跳过 REST 轮询**（`service.go:220-227`），
所以要等 WS 掉线/重连时那一次全量拉取才生效（可能是几小时/几天后）。

**原因**：`app/Observers/ServerObserver.php` 的 `updated()` 只在
`server_port / protocol_settings / type / route_ids / custom_outbounds / custom_routes / cert_config`
变化时调用 `NodeSyncService::notifyConfigUpdated()`，**`host` 不在列表里**。
（`V1/Server/UniProxyController::config()` 的 ETag 是对整个配置做 `sha1`，所以只要真的拉一次就能生效——问题在于 WS 在线时根本不会拉。）

**修复**：把 `host` 加进 `wasChanged([...])` 列表，改 host 立即推送，与 `server_port` 等字段行为一致。

```diff
             'server_port',
             'protocol_settings',
             'type',
+            'host',
             'route_ids',
```

**部署**（一行改动，不需要自建镜像）：

1. 单文件覆盖（推荐）：把本仓库的 `app/Observers/ServerObserver.php` 挂进官方镜像容器

   ```yaml
   services:
     xboard:
       image: ghcr.io/cedar2025/xboard:latest
       volumes:
         - ./patched/ServerObserver.php:/www/app/Observers/ServerObserver.php:ro
   ```

   然后 `docker compose up -d --force-recreate xboard`（Octane/OPcache 需重启进程）。

2. 整仓部署：主机上 clone 本 fork，走官方 `update.sh` 流程。
   注意 `update.sh` 里写死了 `origin/master` —— 用 fork 部署时 `origin` 必须是本 fork，
   **不要**把 upstream 设成 origin，否则 `git reset --hard origin/master` 会把改动覆盖回官方代码。

**回滚**：删掉挂载行（或 `git checkout` 回官方文件）→ 重启容器。

## 与节点 fork 的配套关系

节点侧 `xymhh/Xboard-Node` 的 `hotfix/singbox-reload` 修复的是「面板改自定义出口/路由后节点不生效」，
与本仓库的改动互不依赖，可分别部署。
