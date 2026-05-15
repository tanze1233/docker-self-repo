<?php
const DEFAULT_TIMEOUT = 900;

function env_string(string $name, string $default): string
{
    $value = getenv($name);
    return $value === false || trim($value) === '' ? $default : trim($value);
}

function is_loopback_registry_host(string $host): bool
{
    $host = strtolower(trim($host));
    return $host === 'localhost'
        || str_starts_with($host, 'localhost:')
        || $host === '127.0.0.1'
        || str_starts_with($host, '127.0.0.1:')
        || $host === '::1'
        || str_starts_with($host, '::1:')
        || str_starts_with($host, '[::1]');
}

function registry_host_for_copy(string $host): string
{
    if (is_loopback_registry_host($host) && file_exists('/.dockerenv')) {
        return env_string('CONTAINER_REGISTRY_HOST', 'hub.self.com:5000');
    }

    return $host;
}

function env_bool(string $name, bool $default): bool
{
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        return $default;
    }

    return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
}

function registry_api_base_url(string $host): string
{
    $scheme = rtrim(env_string('REGISTRY_SCHEME', 'https'), ':/');
    return $scheme . '://' . $host . '/v2';
}

function registry_stream_context(bool $tlsVerify, int $timeout)
{
    return stream_context_create([
        'http' => [
            'ignore_errors' => true,
            'timeout' => $timeout,
        ],
        'ssl' => [
            'verify_peer' => $tlsVerify,
            'verify_peer_name' => $tlsVerify,
        ],
    ]);
}

function registry_get_json(string $url, bool $tlsVerify, int $timeout): array
{
    $body = @file_get_contents($url, false, registry_stream_context($tlsVerify, $timeout));
    $headers = $http_response_header ?? [];
    $statusCode = 0;

    foreach ($headers as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches) === 1) {
            $statusCode = (int) $matches[1];
        }
    }

    if ($body === false) {
        $error = error_get_last();
        return [
            'ok' => false,
            'error' => $error['message'] ?? '无法连接 Registry API。',
            'headers' => $headers,
            'data' => null,
        ];
    }

    if ($statusCode < 200 || $statusCode >= 300) {
        return [
            'ok' => false,
            'error' => 'Registry API 返回 HTTP ' . $statusCode . '。',
            'headers' => $headers,
            'data' => null,
        ];
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        return [
            'ok' => false,
            'error' => 'Registry API 返回了无法解析的 JSON。',
            'headers' => $headers,
            'data' => null,
        ];
    }

    return [
        'ok' => true,
        'error' => '',
        'headers' => $headers,
        'data' => $data,
    ];
}

function registry_next_url(array $headers, string $baseUrl): ?string
{
    foreach ($headers as $header) {
        if (stripos($header, 'Link:') !== 0) {
            continue;
        }

        if (preg_match('/<([^>]+)>;\s*rel="next"/i', $header, $matches) !== 1) {
            continue;
        }

        $next = $matches[1];
        if (str_starts_with($next, 'http://') || str_starts_with($next, 'https://')) {
            return $next;
        }

        $parts = parse_url($baseUrl);
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        if (str_starts_with($next, '/')) {
            return $origin . $next;
        }

        return rtrim($origin . '/' . trim($parts['path'] ?? '/v2', '/'), '/') . '/' . ltrim($next, '/');
    }

    return null;
}

function registry_repository_url(string $baseUrl, string $repository): string
{
    $segments = array_map('rawurlencode', explode('/', $repository));
    return $baseUrl . '/' . implode('/', $segments) . '/tags/list?n=1000';
}

function list_registry_images(): array
{
    $configuredRegistryHost = env_string('REGISTRY_HOST', 'hub.self.com:5000');
    $registryHost = registry_host_for_copy($configuredRegistryHost);
    $publicRegistryHost = env_string('PUBLIC_REGISTRY_HOST', 'hub.self.com:5000');
    $timeout = (int) env_string('REGISTRY_LIST_TIMEOUT', '30');
    $timeout = $timeout > 0 ? $timeout : 30;
    $tlsVerify = env_bool('REGISTRY_LIST_TLS_VERIFY', env_bool('DEST_TLS_VERIFY', true));
    $baseUrl = registry_api_base_url($registryHost);
    $catalogUrl = $baseUrl . '/_catalog?n=1000';
    $repositories = [];

    while ($catalogUrl !== null) {
        $catalog = registry_get_json($catalogUrl, $tlsVerify, $timeout);
        if (!$catalog['ok']) {
            return [
                'ok' => false,
                'error' => $catalog['error'],
                'images' => [],
            ];
        }

        foreach (($catalog['data']['repositories'] ?? []) as $repository) {
            if (is_string($repository)) {
                $repositories[] = $repository;
            }
        }

        $catalogUrl = registry_next_url($catalog['headers'], $baseUrl);
    }

    $repositories = array_values(array_unique($repositories));
    sort($repositories, SORT_NATURAL);

    $images = [];
    foreach ($repositories as $repository) {
        $tagsUrl = registry_repository_url($baseUrl, $repository);
        while ($tagsUrl !== null) {
            $tags = registry_get_json($tagsUrl, $tlsVerify, $timeout);
            if (!$tags['ok']) {
                return [
                    'ok' => false,
                    'error' => $tags['error'],
                    'images' => $images,
                ];
            }

            foreach (($tags['data']['tags'] ?? []) as $tag) {
                if (!is_string($tag)) {
                    continue;
                }

                $reference = $publicRegistryHost . '/' . $repository . ':' . $tag;
                $images[] = [
                    'reference' => $reference,
                    'pullCommand' => 'docker pull ' . $reference,
                ];
            }

            $tagsUrl = registry_next_url($tags['headers'], $baseUrl);
        }
    }

    usort($images, static fn (array $a, array $b): int => strnatcmp($a['reference'], $b['reference']));

    return [
        'ok' => true,
        'error' => '',
        'images' => $images,
    ];
}

function tag_part(string $reference): string
{
    $slash = strrpos($reference, '/');
    $colon = strrpos($reference, ':');

    if ($colon !== false && ($slash === false || $colon > $slash)) {
        return substr($reference, $colon + 1);
    }

    return 'latest';
}

function strip_tag_or_digest(string $reference): string
{
    $digestPosition = strpos($reference, '@');
    if ($digestPosition !== false) {
        return substr($reference, 0, $digestPosition);
    }

    $slash = strrpos($reference, '/');
    $colon = strrpos($reference, ':');
    if ($colon !== false && ($slash === false || $colon > $slash)) {
        return substr($reference, 0, $colon);
    }

    return $reference;
}

function parse_image(string $input): array
{
    $image = trim($input);
    if ($image === '') {
        throw new InvalidArgumentException('请输入镜像名称，例如 nginx:latest 或 ghcr.io/owner/image:tag。');
    }

    if (strlen($image) > 255 || preg_match('/[^a-zA-Z0-9._:\/@-]/', $image) === 1) {
        throw new InvalidArgumentException('镜像名称包含不允许的字符。');
    }

    $parts = explode('/', $image, 2);
    $first = $parts[0];
    $hasSlash = count($parts) > 1;
    $hasRegistry = $hasSlash && (str_contains($first, '.') || str_contains($first, ':') || $first === 'localhost');
    $registry = $hasRegistry ? strtolower($first) : 'docker.io';

    $allowedRegistries = ['docker.io', 'index.docker.io', 'registry-1.docker.io', 'ghcr.io'];
    if (!in_array($registry, $allowedRegistries, true)) {
        throw new InvalidArgumentException('仅支持从 Docker Hub 或 ghcr.io 拉取镜像。');
    }

    $pathWithTag = $hasRegistry ? substr($image, strlen($first) + 1) : $image;
    if ($pathWithTag === '' || str_starts_with($pathWithTag, '/') || str_contains($pathWithTag, '//')) {
        throw new InvalidArgumentException('镜像名称格式不正确。');
    }

    $repository = strip_tag_or_digest($pathWithTag);
    if (!str_contains($repository, '/') && $registry !== 'ghcr.io') {
        $repository = 'library/' . $repository;
    }

    if (!preg_match('/^[a-z0-9]+(?:(?:[._-]|__|[-]*)[a-z0-9]+)*(\/[a-z0-9]+(?:(?:[._-]|__|[-]*)[a-z0-9]+)*)*$/', strtolower($repository))) {
        throw new InvalidArgumentException('镜像仓库路径格式不正确。');
    }

    $tag = tag_part($pathWithTag);
    if (!preg_match('/^[\w][\w.-]{0,127}$/', $tag)) {
        throw new InvalidArgumentException('镜像标签格式不正确。');
    }

    $normalizedRegistry = $registry === 'ghcr.io' ? 'ghcr.io' : 'docker.io';
    $prefix = $registry === 'ghcr.io' ? 'ghcr.io' : 'dockerhub';

    return [
        'source' => $normalizedRegistry . '/' . strtolower($repository) . ':' . $tag,
        'localPath' => $prefix . '/' . strtolower($repository) . ':' . $tag,
    ];
}

function run_command(array $command, int $timeout): array
{
    $descriptorSpec = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptorSpec, $pipes);
    if (!is_resource($process)) {
        return ['code' => 1, 'output' => '无法启动 skopeo 命令。'];
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $output = '';
    $exitCode = 1;
    $startedAt = time();
    while (true) {
        $status = proc_get_status($process);
        $output .= stream_get_contents($pipes[1]);
        $output .= stream_get_contents($pipes[2]);

        if (!$status['running']) {
            $exitCode = $status['exitcode'];
            break;
        }

        if (time() - $startedAt > $timeout) {
            proc_terminate($process, 15);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
            return ['code' => 124, 'output' => $output . "\n命令执行超时。"];
        }

        usleep(100000);
    }

    foreach ($pipes as $pipe) {
        fclose($pipe);
    }

    proc_close($process);
    return ['code' => $exitCode, 'output' => trim($output)];
}

function import_image(string $input): array
{
    $configuredRegistryHost = env_string('REGISTRY_HOST', 'hub.self.com:5000');
    $registryHost = registry_host_for_copy($configuredRegistryHost);
    $publicRegistryHost = env_string('PUBLIC_REGISTRY_HOST', 'hub.self.com:5000');
    $timeout = (int) env_string('IMPORT_TIMEOUT', (string) DEFAULT_TIMEOUT);
    $timeout = $timeout > 0 ? $timeout : DEFAULT_TIMEOUT;
    $destTlsVerify = env_string('DEST_TLS_VERIFY', 'true');

    $parsed = parse_image($input);
    $target = $registryHost . '/' . $parsed['localPath'];
    $publicTarget = $publicRegistryHost . '/' . $parsed['localPath'];

    $steps = [
        [
            'title' => '复制多架构镜像到本地仓库',
            'command' => [
                'skopeo',
                'copy',
                '--all',
                '--dest-tls-verify=' . $destTlsVerify,
                'docker://' . $parsed['source'],
                'docker://' . $target,
            ],
        ],
    ];

    $logs = [];
    foreach ($steps as $step) {
        $result = run_command($step['command'], $timeout);
        $logs[] = '$ ' . implode(' ', array_map('escapeshellarg', $step['command'])) . "\n" . $result['output'];
        if ($result['code'] !== 0) {
            return [
                'ok' => false,
                'message' => $step['title'] . '失败，请检查网络、镜像名称或 Registry 连接配置。',
                'logs' => implode("\n\n", $logs),
                'target' => $publicTarget,
            ];
        }
    }

    return [
        'ok' => true,
        'message' => '镜像已导入到本地仓库。',
        'logs' => implode("\n\n", $logs),
        'target' => $publicTarget,
    ];
}

$result = null;
$imageValue = '';
$registryImages = [
    'ok' => true,
    'error' => '',
    'images' => [],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $imageValue = (string) ($_POST['image'] ?? '');
    try {
        $result = import_image($imageValue);
    } catch (Throwable $exception) {
        $result = [
            'ok' => false,
            'message' => $exception->getMessage(),
            'logs' => '',
            'target' => '',
        ];
    }
}

$registryImages = list_registry_images();
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Docker 自建镜像仓库</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
    <main class="container">
        <section class="hero">
            <p class="eyebrow">Docker Registry 控制台</p>
            <h1>把 Docker Hub 或 GHCR 镜像同步到你的本地仓库</h1>
            <p class="lead">提交镜像名称后，后台会用 skopeo copy --all 同步完整多架构镜像，并给出可直接使用的 docker pull 地址。</p>
        </section>

        <section class="card">
            <form method="post">
                <label for="image">源镜像名称</label>
                <div class="form-row">
                    <input id="image" name="image" type="text" required placeholder="nginx:latest 或 ghcr.io/owner/image:tag" value="<?= htmlspecialchars($imageValue, ENT_QUOTES) ?>">
                    <button type="submit">拉取到本地仓库</button>
                </div>
                <p class="hint">支持 Docker Hub 简写（如 ubuntu:22.04）和 ghcr.io 完整地址；默认标签为 latest。</p>
            </form>
        </section>

        <?php if ($result !== null): ?>
            <section class="card result <?= $result['ok'] ? 'success' : 'error' ?>">
                <h2><?= htmlspecialchars($result['message']) ?></h2>
                <?php if ($result['target'] !== ''): ?>
                    <p>本地拉取命令：</p>
                    <pre><code>docker pull <?= htmlspecialchars($result['target']) ?></code></pre>
                <?php endif; ?>
                <?php if ($result['logs'] !== ''): ?>
                    <details open>
                        <summary>执行日志</summary>
                        <pre><code><?= htmlspecialchars($result['logs']) ?></code></pre>
                    </details>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="card registry-list">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">Registry 镜像列表</p>
                    <h2>当前可用镜像</h2>
                </div>
                <?php if ($registryImages['ok']): ?>
                    <span class="badge"><?= count($registryImages['images']) ?> 个标签</span>
                <?php endif; ?>
            </div>

            <?php if (!$registryImages['ok']): ?>
                <p class="empty-state">无法读取 Registry 镜像列表：<?= htmlspecialchars($registryImages['error']) ?></p>
            <?php elseif (count($registryImages['images']) === 0): ?>
                <p class="empty-state">Registry 中还没有可用镜像。导入成功后会在这里一次性列出全部镜像标签。</p>
            <?php else: ?>
                <div class="image-table-wrap">
                    <table class="image-table">
                        <thead>
                            <tr>
                                <th>镜像</th>
                                <th>docker pull 命令</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($registryImages['images'] as $image): ?>
                                <tr>
                                    <td><code><?= htmlspecialchars($image['reference']) ?></code></td>
                                    <td><pre><code><?= htmlspecialchars($image['pullCommand']) ?></code></pre></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="card docs">
            <h2>使用方式</h2>
            <ol>
                <li>运行 <code>docker compose up -d --build</code>。</li>
                <li>打开 <code>http://localhost:8080</code>，提交要同步的镜像。</li>
                <li>同步成功后，使用页面展示或镜像列表中的 <code>docker pull hub.self.com:5000/...</code> 命令拉取。</li>
            </ol>
        </section>
    </main>
</body>
</html>
