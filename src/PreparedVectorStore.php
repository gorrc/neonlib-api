<?php
declare(strict_types=1);
namespace NeonLib;

final class PreparedVectorStore
{
    public const MAX_BYTES = 25 * 1024 * 1024;
    private readonly string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? ($_ENV['PREPARED_VECTORS_DIR'] ?? dirname(__DIR__) . '/storage/prepared-vectors');
    }

    private function path(string $package, int $version): string
    {
        return $this->directory . '/' . hash('sha256', $package) . '.' . $version . '.json';
    }

    private static function encode(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function attach(array $source): array
    {
        $path = $this->path($source['packageId'], $source['version']);
        if (!is_file($path)) return $source;
        try {
            $raw = file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);
            if ($raw === false || strlen($raw) > self::MAX_BYTES) return $source;
            $stored = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            if (!hash_equals($stored['sourceHash'] ?? '', hash('sha256', self::encode($source)))) return $source;
            return $this->validate($source, $stored['prepared']);
        } catch (\Throwable $error) {
            error_log('NeonLib: prepared vectors unavailable; serving text only');
            return $source;
        }
    }

    public function save(array $source, array $prepared): array
    {
        $enriched = $this->validate($source, $prepared);
        $stored = self::encode(['sourceHash' => hash('sha256', self::encode($source)), 'prepared' => $prepared]);
        if (strlen($stored) > self::MAX_BYTES) throw new ApiException(413, 'vectors_too_large', 'Prepared vectors exceed 25 MiB.');
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Unable to create vector storage');
        }
        $temporary = tempnam($this->directory, 'vectors-');
        try {
            if ($temporary === false || file_put_contents($temporary, $stored, LOCK_EX) !== strlen($stored)) {
                throw new \RuntimeException('Unable to write prepared vectors');
            }
            chmod($temporary, 0600);
            if (!rename($temporary, $this->path($source['packageId'], $source['version']))) {
                throw new \RuntimeException('Unable to commit prepared vectors');
            }
        } finally { if ($temporary !== false && is_file($temporary)) unlink($temporary); }
        return ['packageId' => $source['packageId'], 'version' => $source['version'],
            'sizeBytes' => strlen(self::encode($enriched)), 'prepared' => true];
    }

    public function validateTransfer(array $source, array $prepared): array
    {
        return $this->validate($source, $prepared);
    }

    private function validate(array $source, array $prepared): array
    {
        $fail = static fn() => throw new ApiException(422, 'invalid_vectors', 'Prepared vectors do not match this version or have invalid values.');
        $profile = $prepared['profile'] ?? null;
        if (!is_array($profile)) $fail();
        foreach (['modelSha256', 'tokenizerSha256'] as $key) {
            if (!is_string($profile[$key] ?? null) || !preg_match('/^[a-f0-9]{64}$/', $profile[$key])) $fail();
        }
        foreach (['dimensions'=>768,'sequenceLength'=>1024,'bos'=>2,'pad'=>0,'chunkMaxTokens'=>450,'chunkOverlapTokens'=>60,
            'queryPrefix'=>'task: search result | query: ', 'documentPrefix'=>'title: none | text: '] as $key=>$value) {
            if (($profile[$key] ?? null) !== $value) $fail();
        }
        $documents = $prepared['documents'] ?? null;
        if (!is_array($documents) || !array_is_list($documents) || count($documents) !== count($source['documents'])) $fail();
        $result = $source;
        $result['profile'] = $profile;
        foreach ($source['documents'] as $index=>$document) {
            $item = $documents[$index];
            foreach (['id','title','content'] as $field) if (($item[$field] ?? null) !== $document[$field]) $fail();
            $chunks = $item['chunks'] ?? null;
            if (!is_array($chunks) || !array_is_list($chunks) || (!$chunks && trim($document['content'])!=='') || count($chunks)>10000) $fail();
            foreach ($chunks as $chunkIndex=>$chunk) {
                if (($chunk['chunkIndex'] ?? null) !== $chunkIndex || !is_string($chunk['text'] ?? null) ||
                    $chunk['text']==='' || !str_contains($document['content'], str_replace("\n\n", " ", $chunk['text'])) &&
                    !str_contains($document['content'], $chunk['text'])) {
                    // Chunking normalizes whitespace, so verify the normalized source below.
                    $normalizedSource = preg_replace('/\s+/u', ' ', trim($document['content']));
                    $normalizedChunk = isset($chunk['text']) && is_string($chunk['text']) ? preg_replace('/\s+/u', ' ', trim($chunk['text'])) : '';
                    if (($chunk['chunkIndex'] ?? null)!==$chunkIndex || $normalizedChunk==='' || !str_contains($normalizedSource, $normalizedChunk)) $fail();
                }
                $vector = $chunk['vector'] ?? null;
                if (!is_array($vector) || !array_is_list($vector) || count($vector)!==768) $fail();
                $nonzero = false;
                foreach ($vector as $value) {
                    if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || abs($value)>3.402823466e38) $fail();
                    $nonzero = $nonzero || $value!=0;
                }
                if (!$nonzero) $fail();
            }
            $result['documents'][$index]['chunks'] = $chunks;
        }
        if (strlen(self::encode($result))>self::MAX_BYTES) throw new ApiException(413,'vectors_too_large','Prepared content exceeds 25 MiB.');
        return $result;
    }
}
