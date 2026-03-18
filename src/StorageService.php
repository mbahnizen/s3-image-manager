<?php
// src/StorageService.php

namespace App;

class StorageService {
    private $accessKey;
    private $secretKey;
    private $region;
    private $bucket;
    private $endpoint;
    private $acl;

    public function __construct() {
        $this->accessKey = S3_ACCESS_KEY;
        $this->secretKey = S3_SECRET_KEY;
        $this->region = S3_REGION;
        $this->bucket = S3_BUCKET;
        $this->endpoint = S3_ENDPOINT;
        $this->acl = defined('S3_ACL') ? S3_ACL : 'public-read';

        if ($this->accessKey === '' || $this->secretKey === '') {
            throw new \RuntimeException('S3 credentials are not configured');
        }
    }

    private function sign($key, $msg) {
        return hash_hmac('sha256', $msg, $key, true);
    }

    private function getSignatureKey($key, $dateStamp, $regionName, $serviceName) {
        $kDate = $this->sign("AWS4" . $key, $dateStamp);
        $kRegion = $this->sign($kDate, $regionName);
        $kService = $this->sign($kRegion, $serviceName);
        $kSigning = $this->sign($kService, "aws4_request");
        return $kSigning;
    }

    public function upload($tmpFile, $filename, $mimeType, $customPath = '') {
        $shortDate = gmdate('Ymd');
        $amzDate = gmdate('Ymd\THis\Z');
        $region = $this->region;
        $service = 's3';
        $credentialScope = "$shortDate/$region/$service/aws4_request";

        $date = date('Y/m/d');
        $uniqueId = bin2hex(random_bytes(4));
        $key = $customPath ? trim($customPath, '/') . '/' . ltrim($filename, '/') : "$date/" . time() . '_' . $uniqueId . '_' . ltrim($filename, '/');
        
        // If customPath is used, we should still ensure uniqueness if possible
        if ($customPath) {
            $pathParts = pathinfo($filename);
            $key = trim($customPath, '/') . '/' . $pathParts['filename'] . '_' . $uniqueId . '.' . ($pathParts['extension'] ?? '');
        }

        $policyArray = [
            'expiration' => gmdate('Y-m-d\TH:i:s\Z', strtotime('+1 hours')),
            'conditions' => [
                ['bucket' => $this->bucket],
                ['acl' => $this->acl],
                ['starts-with', '$key', ''],
                ['starts-with', '$Content-Type', ''],
                ['x-amz-algorithm' => 'AWS4-HMAC-SHA256'],
                ['x-amz-credential' => $this->accessKey . '/' . $credentialScope],
                ['x-amz-date' => $amzDate],
            ],
        ];
        $policy = base64_encode(json_encode($policyArray));
        $signingKey = $this->getSignatureKey($this->secretKey, $shortDate, $region, $service);
        $signature = hash_hmac('sha256', $policy, $signingKey);

        $postfields = [
            'key' => $key,
            'acl' => $this->acl,
            'Content-Type' => $mimeType,
            'x-amz-algorithm' => 'AWS4-HMAC-SHA256',
            'x-amz-credential' => $this->accessKey . '/' . $credentialScope,
            'x-amz-date' => $amzDate,
            'policy' => $policy,
            'x-amz-signature' => $signature,
            'file' => new \CURLFile($tmpFile, $mimeType, $filename),
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->endpoint . '/' . $this->bucket,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postfields,
            CURLOPT_RETURNTRANSFER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 204) {
            return [
                'success' => true,
                'url' => PUBLIC_URL_BASE . ltrim($key, '/'),
                'key' => $key
            ];
        }

        return ['success' => false, 'error' => "S3 Upload failed ($httpCode)"];
    }

    public function getPresignedUrl($key, $expires = 3600) {
        $shortDate = gmdate('Ymd');
        $amzDate = gmdate('Ymd\THis\Z');
        $region = $this->region;
        $service = 's3';
        $credentialScope = "$shortDate/$region/$service/aws4_request";
        
        $host = parse_url($this->endpoint, PHP_URL_HOST);
        $canonicalUri = "/" . $this->bucket . "/" . str_replace('%2F', '/', rawurlencode($key));
        
        $params = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $this->accessKey . '/' . $credentialScope,
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => $expires,
            'X-Amz-SignedHeaders' => 'host',
        ];
        ksort($params);
        
        $canonicalQuerystring = http_build_query($params);
        $canonicalHeaders = "host:$host\n";
        $signedHeaders = 'host';
        $payloadHash = 'UNSIGNED-PAYLOAD';

        $canonicalRequest = "GET\n$canonicalUri\n$canonicalQuerystring\n$canonicalHeaders\n$signedHeaders\n$payloadHash";
        $stringToSign = "AWS4-HMAC-SHA256\n$amzDate\n$credentialScope\n" . hash('sha256', $canonicalRequest);
        $signingKey = $this->getSignatureKey($this->secretKey, $shortDate, $region, $service);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        return $this->endpoint . $canonicalUri . '?' . $canonicalQuerystring . '&X-Amz-Signature=' . $signature;
    }

    public function delete($key) {
        $host = parse_url($this->endpoint, PHP_URL_HOST);
        $shortDate = gmdate('Ymd');
        $amzDate = gmdate('Ymd\THis\Z');
        $region = $this->region;
        $service = 's3';
        $credentialScope = "$shortDate/$region/$service/aws4_request";

        $canonicalUri = "/" . $this->bucket . "/" . str_replace('%2F', '/', rawurlencode($key));
        $canonicalQuerystring = '';
        $canonicalHeaders = "host:$host\nx-amz-date:$amzDate\n";
        $signedHeaders = 'host;x-amz-date';
        $payloadHash = hash('sha256', '');

        $canonicalRequest = "DELETE\n$canonicalUri\n$canonicalQuerystring\n$canonicalHeaders\n$signedHeaders\n$payloadHash";
        $stringToSign = "AWS4-HMAC-SHA256\n$amzDate\n$credentialScope\n" . hash('sha256', $canonicalRequest);
        $signingKey = $this->getSignatureKey($this->secretKey, $shortDate, $region, $service);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $authorization = "AWS4-HMAC-SHA256 Credential=" . $this->accessKey . "/$credentialScope, SignedHeaders=$signedHeaders, Signature=$signature";

        $url = $this->endpoint . "/" . $this->bucket . "/" . $key;
        $headers = [
            "Authorization: $authorization",
            "x-amz-date: $amzDate",
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($httpCode === 204 || $httpCode === 200);
    }

    public function copy($sourceKey, $destKey) {
        $host = parse_url($this->endpoint, PHP_URL_HOST);
        $shortDate = gmdate('Ymd');
        $amzDate = gmdate('Ymd\THis\Z');
        $region = $this->region;
        $service = 's3';
        $credentialScope = "$shortDate/$region/$service/aws4_request";

        $canonicalUri = "/" . $this->bucket . "/" . str_replace('%2F', '/', rawurlencode($destKey));
        $canonicalQuerystring = '';
        $copySource = "/" . $this->bucket . "/" . str_replace('%2F', '/', rawurlencode($sourceKey));

        $canonicalHeaders = "host:$host\nx-amz-acl:$this->acl\nx-amz-copy-source:$copySource\nx-amz-date:$amzDate\n";
        $signedHeaders = 'host;x-amz-acl;x-amz-copy-source;x-amz-date';
        $payloadHash = hash('sha256', '');

        $canonicalRequest = "PUT\n$canonicalUri\n$canonicalQuerystring\n$canonicalHeaders\n$signedHeaders\n$payloadHash";
        $stringToSign = "AWS4-HMAC-SHA256\n$amzDate\n$credentialScope\n" . hash('sha256', $canonicalRequest);
        $signingKey = $this->getSignatureKey($this->secretKey, $shortDate, $region, $service);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $authorization = "AWS4-HMAC-SHA256 Credential=" . $this->accessKey . "/$credentialScope, SignedHeaders=$signedHeaders, Signature=$signature";

        $url = $this->endpoint . "/" . $this->bucket . "/" . $destKey;
        $headers = [
            "Authorization: $authorization",
            "x-amz-date: $amzDate",
            "x-amz-copy-source: $copySource",
            "x-amz-acl: $this->acl",
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($httpCode === 200);
    }
}
