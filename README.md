# Docker 自建镜像仓库

这是一个基于 PHP 的轻量控制台，用于把 Docker Hub 或 GitHub Container Registry（GHCR）中的镜像同步到自己的 Docker Registry。页面提交镜像名称后，后台会依次执行 `docker pull`、`docker tag` 和 `docker push`，然后给出可以直接使用的本地 `docker pull` 命令。

## 组件

- `registry`：官方 `registry:2` 镜像，负责保存本地镜像仓库数据。
- `web`：PHP + Apache 前端，内置 Docker CLI，并通过挂载宿主机 `/var/run/docker.sock` 执行 Docker 命令。

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


## Docker socket 权限

Web 容器中的 PHP 进程默认以 `www-data` 用户运行。为了避免执行 Docker CLI 时出现 `permission denied while trying to connect to the docker API at unix:///var/run/docker.sock`，镜像启动脚本会自动读取挂载进容器的 Docker socket 所属 GID，并把 `www-data` 加入对应的容器内用户组。

如果你仍然遇到权限问题，请确认：

- `docker-compose.yml` 中已经挂载 `/var/run/docker.sock:/var/run/docker.sock`。
- 修改 Dockerfile 后已经重新构建 Web 镜像：`docker compose up -d --build`。
- 宿主机的 Docker socket 允许其所属用户组访问。

## 配置

可在 `docker-compose.yml` 中通过环境变量调整：

- `REGISTRY_HOST`：Web 容器内部访问 Registry 的地址，默认 `registry:5000`。
- `PUBLIC_REGISTRY_HOST`：页面展示给用户的 Registry 地址，默认 `localhost:5000`。
- `IMPORT_TIMEOUT`：每个 Docker 命令的超时时间（秒），默认 `900`。

## 注意事项

- 当前页面只允许导入 Docker Hub 与 `ghcr.io` 镜像。
- 如果要让其他机器访问你的 Registry，请把 `PUBLIC_REGISTRY_HOST` 改成服务器 IP 或域名，并确保 5000 端口可达。
- 默认 Registry 使用 HTTP；远程 Docker 客户端可能需要在 Docker daemon 中配置 insecure registry。
- Web 容器挂载了 Docker socket，等价于拥有宿主机 Docker 控制权限，请只在可信网络中使用。
