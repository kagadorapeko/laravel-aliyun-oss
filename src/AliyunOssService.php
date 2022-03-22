<?php

namespace KagaDorapeko\Laravel\Aliyun\Oss;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Http;

class AliyunOssService
{
    protected array $config;

    protected PendingRequest $apiClient;

    protected string $apiDomain = 'aliyuncs.com';

    public function __construct()
    {
        $this->refreshConfig();
    }

    public function refreshConfig(array|null $config = null)
    {
        $this->config = $config ?: config('aliyun-oss');

        $apiDomain = "{$this->config['region_id']}.$this->apiDomain";

        $this->apiClient = Http::baseUrl("https://{$this->config['bucket']}.$apiDomain")->withHeaders([
            'Content-Type' => 'application/json',
        ]);
    }

    public function copyTempToPrivate(string $privateFileName, string $tempObjectName): array|null
    {
        $privateObjectName = $this->getPrivateObjectName($privateFileName);

        $request = $this->apiClient->withHeaders([
            'X-Oss-Copy-Source' => "/{$this->config['bucket']}/" . rawurlencode($tempObjectName),
            'Date' => gmdate('D, d M Y H:i:s \G\M\T'),
        ]);

        $headers = $this->apiClient->getOptions()['headers'];

        $signContent = "PUT\n\n{$headers['Content-Type']}\n{$headers['Date']}\n"
            . "x-oss-copy-source:{$headers['X-Oss-Copy-Source']}\n"
            . "/{$this->config['bucket']}/$privateObjectName";

        $sign = base64_encode(hash_hmac('sha1', $signContent, $this->config['access_key_secret'], true));

        $this->apiClient->withHeaders([
            'Authorization' => "OSS {$this->config['access_key_id']}:$sign",
        ]);

        $response = $request->put("/$privateObjectName");

        if ($response->successful() and $responseData = simplexml_load_string($response->body())) {
            return ['etag' => json_decode(((array)$responseData)['ETag'])];
        }

        return null;
    }

    public function getOriginDownloadUrl(string $objectName, int|null $timeout, array $queries = []): string
    {
        $expires = time() + ($timeout ?? 300);

        $sign = $this->getPrivateDownloadSign($objectName, $expires, $queries);

        foreach ($queries as $key => &$value) {
            $value = rawurlencode($key) . '=' . rawurlencode($value);
        }

        $queries = array_merge($queries, [
            "OSSAccessKeyId={$this->config['access_key_id']}",
            "Expires=$expires",
            "Signature=$sign",
        ]);

        return "https://{$this->config['bucket']}.{$this->config['region_id']}.$this->apiDomain/$objectName?"
            . implode('&', $queries);
    }

    public function getPrivateDownloadUrl(string $etag, int|null $timeout, array $queries = []): string
    {
        return $this->getOriginDownloadUrl($this->getPrivateObjectName($etag), $timeout, $queries);
    }

    public function getUploadPayload(int $expires, string|int|null $fileName, Carbon $tempDate, string $callbackUrl, array $callbackBody = []): array
    {
        $expiredAt = now()->addSeconds($expires);

        return [
            'callback' => $callbackStr = $this->getCallbackString($callbackUrl, $callbackBody),
            'key' => ($tempObjectPrefix = $this->getTempObjectPrefix($tempDate)) . $fileName,
            'upload_url' => "https://{$this->config['bucket']}.{$this->config['region_id']}.$this->apiDomain",
            'policy' => $policy = $this->getTempUploadPolicy($fileName, $tempObjectPrefix, $callbackStr, $expiredAt),
            'access_key_id' => $this->config['access_key_id'],
            'signature' => $this->getTempUploadSign($policy),
            'expired_at' => $expiredAt->getTimestamp(),
        ];
    }

    public function getPrivateObjectName(string|int $fileName): string
    {
        return (App::isProduction() ? '' : 'develop/') . "resources/privates/$fileName";
    }

    protected function getTempUploadSign(string $policy): string
    {
        return base64_encode(hash_hmac('sha1', $policy, $this->config['access_key_secret'], true));
    }

    protected function getTempUploadPolicy(string|int|null $fileName, string $tempObjectPrefix, string $callbackStr, Carbon $expiredAt): string
    {
        return base64_encode(json_encode([
            'expiration' => $expiredAt->toIso8601ZuluString('millisecond'),
            'conditions' => [
                ['eq', '$callback', "$callbackStr"],
                is_null($fileName) ? ['starts-with', '$key', "$tempObjectPrefix$fileName"]
                    : ['eq', '$key', "$tempObjectPrefix$fileName"],
            ],
        ]));
    }

    protected function getPrivateDownloadSign(string $objectName, int $expires, array $queries): string
    {
        $signContent = "GET\n\n\n$expires\n/{$this->config['bucket']}/$objectName";

        if (!empty($queries)) {
            foreach ($queries as $key => &$value) {
                $value = "$key=$value";
            }

            $signContent .= "?" . implode('&', $queries);
        }

        return rawurlencode(base64_encode(hash_hmac(
            'sha1', $signContent, $this->config['access_key_secret'], true
        )));
    }

    protected function getTempObjectPrefix(Carbon $tempDate): string
    {
        $dateFormat = $tempDate->format('Y-m-d');
        return (App::isProduction() ? '' : 'develop/') . "resources/temps/$dateFormat/";
    }

    protected function getCallbackString(string $callbackUrl, array $callbackBody): string
    {
        $callbackBody = array_merge($callbackBody, ['etag=${etag}']);

        return base64_encode(json_encode([
            'callbackUrl' => $callbackUrl,
            'callbackBody' => implode('&', $callbackBody),
            'callbackBodyType' => 'application/x-www-form-urlencoded',
        ]));
    }
}