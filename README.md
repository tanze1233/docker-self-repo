# Docker 自建镜像仓库

这是一个基于 PHP 的轻量控制台，用于把 Docker Hub 或 GitHub Container Registry（GHCR）中的镜像同步到自己的 Docker Registry。页面提交镜像名称后，后台会通过 `skopeo copy --all` 复制完整多架构镜像清单和所有可用平台镜像，然后给出可以直接使用的本地 `docker pull` 命令。

## 组件

- `registry`：官方 `registry:2` 镜像，负责保存本地镜像仓库数据。
- `web`：PHP + Apache 前端，内置 `skopeo`，不需要挂载宿主机 Docker socket。

## 启动

```bash
docker compose up -d --build
```

启动后访问：

```text
http://localhost:8080
```

本地 Registry 默认监听：

```text
localhost:5000
```

## 导入示例

在网页输入：

```text
nginx:latest
```

成功后页面会返回类似命令：

```bash
docker pull localhost:5000/dockerhub/library/nginx:latest
```

GHCR 示例：

```text
ghcr.io/owner/image:tag
```

对应本地拉取路径会保留 `ghcr.io` 前缀，便于区分来源：

```bash
docker pull localhost:5000/ghcr.io/owner/image:tag
```


## 配置

可在 `docker-compose.yml` 中通过环境变量调整：

- `REGISTRY_HOST`：Web 容器内部访问 Registry 的地址，默认 `registry:5000`。
- `PUBLIC_REGISTRY_HOST`：页面展示给用户的 Registry 地址，默认 `localhost:5000`。
- `IMPORT_TIMEOUT`：镜像复制命令的超时时间（秒），默认 `900`。
- `DEST_TLS_VERIFY`：连接本地 Registry 时是否校验证书，默认 `false`，适用于 Compose 中的 HTTP Registry。

## 注意事项

- 当前页面只允许导入 Docker Hub 与 `ghcr.io` 镜像。
- 如果要让其他机器访问你的 Registry，请把 `PUBLIC_REGISTRY_HOST` 改成服务器 IP 或域名，并确保 5000 端口可达。
- 默认 Registry 使用 HTTP；远程 Docker 客户端可能需要在 Docker daemon 中配置 insecure registry。
- 使用 `skopeo copy --all` 可以避免 Docker CLI 只推送当前机器单平台镜像时出现的 `Not all multiplatform-content is present` 提示。
- Web 页面可以把外部镜像写入你的本地 Registry，请只在可信网络中使用，必要时自行添加认证。
