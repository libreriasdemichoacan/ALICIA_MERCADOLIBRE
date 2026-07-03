<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

final class MeliClient
{
    /** @param array<string,string> $config */
    public function __construct(private readonly array $config = [])
    {
    }

    private const BASE_URL = 'https://api.mercadolibre.com';

    /** @var array<string,string> */
    private const AUTH_HOSTS = [
        'MLA' => 'https://auth.mercadolibre.com.ar/authorization',
        'MLB' => 'https://auth.mercadolivre.com.br/authorization',
        'MCO' => 'https://auth.mercadolibre.com.co/authorization',
        'MCR' => 'https://auth.mercadolibre.co.cr/authorization',
        'MEC' => 'https://auth.mercadolibre.com.ec/authorization',
        'MLC' => 'https://auth.mercadolibre.cl/authorization',
        'MLM' => 'https://auth.mercadolibre.com.mx/authorization',
        'MLU' => 'https://auth.mercadolibre.com.uy/authorization',
        'MLV' => 'https://auth.mercadolibre.com.ve/authorization',
        'MPA' => 'https://auth.mercadolibre.com.pa/authorization',
        'MPE' => 'https://auth.mercadolibre.com.pe/authorization',
        'MRD' => 'https://auth.mercadolibre.com.do/authorization',
    ];

    public function authorizationUrl(?string $state = null): string
    {
        $query = [
            'response_type' => 'code',
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
        ];

        if ($state !== null && $state !== '') {
            $query['state'] = $state;
        }

        return $this->authorizationEndpoint() . '?' . http_build_query($query);
    }

    /** @return array<string,mixed> */
    public function exchangeCode(string $code): array
    {
        return $this->request('POST', '/oauth/token', null, [
            'grant_type' => 'authorization_code',
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
        ], true);
    }

    /** @return array<string,mixed> */
    public function refreshToken(string $refreshToken): array
    {
        return $this->request('POST', '/oauth/token', null, [
            'grant_type' => 'refresh_token',
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'refresh_token' => $refreshToken,
        ], true);
    }

    /** @return array<string,mixed> */
    public function get(string $path, string $token, array $query = [], array $extraHeaders = []): array
    {
        $suffix = $query === [] ? '' : '?' . http_build_query($query);
        return $this->request('GET', $path . $suffix, $token, [], false, $extraHeaders);
    }

    /** @return array<string,mixed> */
    public function put(string $path, string $token, array $payload): array
    {
        return $this->request('PUT', $path, $token, $payload);
    }

    /** @return array{body:string,content_type:string,filename:string} */
    public function downloadShippingLabel(int $shipmentId, string $token): array
    {
        $query = http_build_query([
            'shipment_ids' => $shipmentId,
            'savePdf' => 'Y',
            'response_type' => 'pdf',
        ]);

        return $this->requestBinary('GET', '/shipment_labels?' . $query, $token, 'guia-' . $shipmentId . '.pdf');
    }

    /** @return array<string,mixed> */
    private function request(string $method, string $path, ?string $token = null, array $payload = [], bool $form = false, array $extraHeaders = []): array
    {
        $curl = curl_init(self::BASE_URL . $path);
        $headers = array_merge(['Accept: application/json'], $extraHeaders);

        if ($token !== null) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ]);

        if ($payload !== []) {
            if ($form) {
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
                curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($payload));
            } else {
                $headers[] = 'Content-Type: application/json';
                curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
            }
        }

        $body = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($body === false) {
            throw new RuntimeException('Error cURL Mercado Libre: ' . $error);
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Respuesta inválida de Mercado Libre: ' . substr($body, 0, 200));
        }

        if ($status >= 400) {
            $message = $decoded['message'] ?? $decoded['error_description'] ?? $decoded['error'] ?? 'Error API Mercado Libre';
            throw new RuntimeException((string) $message . " (HTTP {$status})");
        }

        return $decoded;
    }

    /** @return array{body:string,content_type:string,filename:string} */
    private function requestBinary(string $method, string $path, string $token, string $defaultFilename): array
    {
        $curl = curl_init(self::BASE_URL . $path);
        $headers = [
            'Accept: application/pdf, application/octet-stream, application/json',
            'Authorization: Bearer ' . $token,
        ];

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HEADER => true,
        ]);

        $response = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $contentType = (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
        curl_close($curl);

        if ($response === false) {
            throw new RuntimeException('Error cURL Mercado Libre: ' . $error);
        }

        $headersText = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        if ($status >= 400) {
            $decoded = json_decode($body, true);
            $message = is_array($decoded)
                ? ($decoded['message'] ?? $decoded['error_description'] ?? $decoded['error'] ?? 'Error al descargar guía')
                : 'Error al descargar guía';
            throw new RuntimeException((string) $message . " (HTTP {$status})");
        }

        return [
            'body' => $body,
            'content_type' => $contentType ?: 'application/pdf',
            'filename' => $this->filenameFromHeaders($headersText, $defaultFilename),
        ];
    }

    private function filenameFromHeaders(string $headers, string $default): string
    {
        if (preg_match('/filename="?([^";]+)"?/i', $headers, $matches)) {
            return basename($matches[1]);
        }

        return $default;
    }

    private function authorizationEndpoint(): string
    {
        $siteId = strtoupper($this->configValue('MELI_SITE_ID', 'MLM') ?: 'MLM');

        return self::AUTH_HOSTS[$siteId] ?? self::AUTH_HOSTS['MLM'];
    }


    private function configValue(string $key, ?string $default = null): ?string
    {
        $value = $this->config[$key] ?? null;
        if ($value !== null && $value !== '') {
            return $value;
        }

        return Settings::get($key, $default);
    }

    private function clientId(): string
    {
        return $this->configValue('MELI_CLIENT_ID', '') ?: '';
    }

    private function clientSecret(): string
    {
        return $this->configValue('MELI_CLIENT_SECRET', '') ?: '';
    }

    public function redirectUri(): string
    {
        return $this->configValue('MELI_REDIRECT_URI', Config::get('APP_URL', 'http://localhost:8000') . '/auth_callback.php') ?: '';
    }
}
