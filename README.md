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
https://hub.self.com:9443
```

自建 Registry 默认使用同一套证书启用 HTTPS，监听端口保持：

```text
hub.self.com:5000
```

## 导入示例

在网页输入：

```text
nginx:latest
```

成功后页面会返回类似命令：

```bash
docker pull hub.self.com:5000/dockerhub/library/nginx:latest
```

GHCR 示例：

```text
ghcr.io/owner/image:tag
```

对应本地拉取路径会保留 `ghcr.io` 前缀，便于区分来源：

```bash
docker pull hub.self.com:5000/ghcr.io/owner/image:tag
```


## 镜像列表

前端页面会通过 Registry HTTP API 读取 `_catalog` 和每个仓库的 `tags/list`，在“当前可用镜像”栏目中一次性列出自建 Registry 中全部镜像标签以及对应的 `docker pull` 命令。

## HTTPS 前端证书

Web 前端和自建 Registry 都使用宿主机目录 `/root/cert/hub.self.com` 中的同一套证书。Web 容器会把该目录只读挂载到 `/etc/apache2/ssl`，Registry 容器会把该目录只读挂载到 `/certs`。请确保宿主机存在以下两个文件：

```text
/root/cert/hub.self.com/fullchain.pem
/root/cert/hub.self.com/privkey.pem
```

`docker-compose.yml` 默认将 Web 容器的 443 端口映射到宿主机 9443 端口，因此前端页面通过 `https://hub.self.com:9443` 访问；Registry 仍然通过 `https://hub.self.com:5000` 访问。

## 配置

可在 `docker-compose.yml` 中通过环境变量调整：

- `REGISTRY_HOST`：Web 容器内部访问 Registry 的地址，默认 `hub.self.com:5000`。Compose 会把 `hub.self.com` 作为 Registry 服务的网络别名，以便证书域名和访问域名一致。
- `CONTAINER_REGISTRY_HOST`：当 `REGISTRY_HOST` 被误配置成 `localhost`、`127.0.0.1` 或 `::1` 且程序运行在容器内时，实际用于 `skopeo copy` 的兜底地址，默认 `hub.self.com:5000`。
- `PUBLIC_REGISTRY_HOST`：页面展示给用户的 Registry 地址，默认 `hub.self.com:5000`，这是给浏览器和宿主机 Docker 客户端使用的 HTTPS Registry 地址。
- `IMPORT_TIMEOUT`：镜像复制命令的超时时间（秒），默认 `900`。
- `DEST_TLS_VERIFY`：连接本地 Registry 时是否校验证书，默认 `true`，适用于当前 HTTPS Registry。
- `REGISTRY_SCHEME`：读取 Registry API 时使用的协议，默认 `https`。
- `REGISTRY_LIST_TIMEOUT`：读取镜像列表时每个 Registry API 请求的超时时间（秒），默认 `30`。
- `REGISTRY_LIST_TLS_VERIFY`：读取镜像列表时是否校验证书，默认沿用 `DEST_TLS_VERIFY`。

## 注意事项

- 当前页面只允许导入 Docker Hub 与 `ghcr.io` 镜像。
- 如果要让其他机器访问你的 Registry，请把 `PUBLIC_REGISTRY_HOST` 改成服务器 IP 或域名，并确保 5000 端口可达。
- 如果导入日志中出现 `pinging container registry localhost:5000` 和 `connect: connection refused`，说明容器内复制目标被误配置成了 loopback 地址；请使用 Compose 默认的 `REGISTRY_HOST=hub.self.com:5000`，或设置 `CONTAINER_REGISTRY_HOST=hub.self.com:5000`。
- 使用 `skopeo copy --all` 可以避免 Docker CLI 只推送当前机器单平台镜像时出现的 `Not all multiplatform-content is present` 提示。
- Web 页面可以把外部镜像写入你的本地 Registry，请只在可信网络中使用，必要时自行添加认证。
- 如果 Web 或 Registry 的 HTTPS 无法启动，请检查 `/root/cert/hub.self.com/fullchain.pem` 和 `/root/cert/hub.self.com/privkey.pem` 是否存在且 Docker 进程可读取；使用 Docker 客户端拉取时，也要确保客户端信任该证书链。
