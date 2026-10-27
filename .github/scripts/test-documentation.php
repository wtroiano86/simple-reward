<?php

declare(strict_types=1);

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Parser\MarkdownParser;
use Symfony\Component\Yaml\Yaml;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

$environment = new Environment;
$environment->addExtension(new CommonMarkCoreExtension);
$parser = new MarkdownParser($environment);
$files = [...glob($root.'/*.md'), $root.'/llms.txt'];
foreach (['docs', 'resources/boost'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory));
    foreach ($iterator as $file) {
        if ($file->isFile() && ($file->getExtension() === 'md' || $file->getFilename() === 'core.blade.php')) {
            $files[] = $file->getPathname();
        }
    }
}
sort($files);
$errors = [];
$links = 0;
$phpSnippets = 0;
$jsonSnippets = 0;
$skills = 0;

// GitHub-style heading IDs for these docs, including repeated headings.
$anchors = static function (string $content): array {
    $ids = [];
    $counts = [];
    $fence = null;
    foreach (explode("\n", $content) as $line) {
        if (preg_match('/^\s*(`{3,}|~{3,})/', $line, $match)) {
            $fence = $fence === null ? $match[1][0] : ($fence === $match[1][0] ? null : $fence);

            continue;
        }
        if ($fence !== null || ! preg_match('/^#{1,6}\s+(.+?)\s*#*$/', $line, $match)) {
            continue;
        }
        $text = preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $match[1]);
        $text = strip_tags((string) $text);
        $slug = str_replace(' ', '-', (string) preg_replace('/[^\p{L}\p{N}_\- ]/u', '', mb_strtolower($text)));
        $count = $counts[$slug] ?? 0;
        $counts[$slug] = $count + 1;
        $ids[] = $slug.($count > 0 ? '-'.$count : '');
    }

    return $ids;
};

foreach ($files as $file) {
    $label = mb_substr($file, mb_strlen($root) + 1);
    $content = file_get_contents($file);
    $openFence = null;
    foreach (explode("\n", $content) as $index => $line) {
        if (! preg_match('/^\s*(`{3,}|~{3,})(.*)$/', $line, $match)) {
            continue;
        }
        if ($openFence === null) {
            $openFence = [$match[1], $index + 1];
        } elseif ($match[1][0] === $openFence[0][0] && mb_strlen($match[1]) >= mb_strlen($openFence[0]) && mb_trim($match[2]) === '') {
            $openFence = null;
        }
    }
    if ($openFence !== null) {
        $errors[] = "$label: unclosed code fence on line {$openFence[1]}";
    }

    if (basename($file) === 'SKILL.md') {
        $skills++;
        try {
            if (! preg_match('/\A---\R(.*?)\R---\R/s', $content, $match)) {
                throw new RuntimeException('missing YAML frontmatter');
            }
            $meta = Yaml::parse($match[1]);
            $name = $meta['name'] ?? null;
            $description = $meta['description'] ?? null;
            if (! is_string($name) || mb_strlen($name) > 64 || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $name) || $name !== basename(dirname($file))) {
                throw new RuntimeException('skill name must match its directory and use lowercase hyphenated words');
            }
            if (! is_string($description) || mb_trim($description) === '' || mb_strlen($description) > 1024 || strpbrk($description, '<>') !== false) {
                throw new RuntimeException('invalid skill description');
            }
        } catch (Throwable $exception) {
            $errors[] = "$label: {$exception->getMessage()}";
        }
    }

    $walker = $parser->parse($content)->walker();
    while ($event = $walker->next()) {
        if (! $event->isEntering()) {
            continue;
        }
        $node = $event->getNode();
        if ($node instanceof Link || $node instanceof Image) {
            $url = $node->getUrl();
            if ($url === '' || preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) || str_starts_with($url, '//')) {
                continue;
            }
            $links++;
            [$path, $fragment] = array_pad(explode('#', $url, 2), 2, null);
            $path = rawurldecode(explode('?', $path, 2)[0]);
            $target = $path === '' ? $file : (str_starts_with($path, '/') ? $root.$path : dirname($file).'/'.$path);
            if (! is_file($target) && ! is_dir($target)) {
                $errors[] = "$label: missing link target $url";
            } elseif ($fragment !== null && $fragment !== '' && is_file($target) && in_array(pathinfo($target, PATHINFO_EXTENSION), ['md', 'txt'], true)) {
                if (! in_array(rawurldecode($fragment), $anchors(file_get_contents($target)), true)) {
                    $errors[] = "$label: missing heading anchor $url";
                }
            }
        }
        if (! $node instanceof FencedCode) {
            continue;
        }
        $info = $node->getInfoWords();
        $language = $info[0] ?? '';
        $code = $node->getLiteral();
        if ($language === 'json') {
            $jsonSnippets++;
            try {
                json_decode($code, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                $errors[] = "$label: invalid JSON snippet: {$exception->getMessage()}";
            }
        }
        if ($language !== 'php') {
            continue;
        }
        $phpSnippets++;
        if (($info[1] ?? '') === 'class-body') {
            // Keep namespace imports outside the synthetic class containing method fragments.
            preg_match_all('/^use [^;]+;\R?/m', $code, $imports);
            $body = preg_replace('/^use [^;]+;\R?/m', '', $code);
            $code = implode("\n", $imports[0])."\nclass DocumentationExample extends \\stdClass {\n".$body."\n}";
        } elseif (($info[1] ?? '') === 'chain') {
            $code = '$builder'.$code.';';
        } elseif (isset($info[1])) {
            $errors[] = "$label: unknown PHP snippet context {$info[1]}";
        }
        if (! str_starts_with(mb_ltrim($code), '<?php')) {
            $code = "<?php\n".$code;
        }
        $temporary = tempnam(sys_get_temp_dir(), 'simple-reward-docs-');
        try {
            file_put_contents($temporary, $code);
            $process = proc_open([PHP_BINARY, '-l', $temporary], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            if (proc_close($process) !== 0) {
                $errors[] = "$label: invalid PHP snippet: ".mb_trim(str_replace($temporary, '<snippet>', $output));
            }
        } finally {
            unlink($temporary);
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode("\n", $errors)."\n");
    exit(1);
}
echo count($files)." documentation files: $links local links, $phpSnippets PHP snippets, $jsonSnippets JSON snippets, $skills skills passed.\n";
