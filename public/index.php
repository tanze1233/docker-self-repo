<?php
const DEFAULT_TIMEOUT = 900;

function env_string(string $name, string $default): string
{
    $value = getenv($name);
    return $value === false || trim($value) === '' ? $default : trim($value);
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
    $registryHost = env_string('REGISTRY_HOST', 'registry:5000');
    $publicRegistryHost = env_string('PUBLIC_REGISTRY_HOST', 'localhost:5000');
    $timeout = (int) env_string('IMPORT_TIMEOUT', (string) DEFAULT_TIMEOUT);
    $timeout = $timeout > 0 ? $timeout : DEFAULT_TIMEOUT;
    $destTlsVerify = env_string('DEST_TLS_VERIFY', 'false');

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

        <section class="card docs">
            <h2>使用方式</h2>
            <ol>
                <li>运行 <code>docker compose up -d --build</code>。</li>
                <li>打开 <code>http://localhost:8080</code>，提交要同步的镜像。</li>
                <li>同步成功后，使用页面展示的 <code>docker pull localhost:5000/...</code> 命令拉取。</li>
            </ol>
        </section>
    </main>
</body>
</html>
