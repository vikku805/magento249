<?php

namespace Order\Orchestration\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;

class OrderConsumer
{
    /**
     * env.php config path (app/etc/env.php -> 'order_orchestration' node).
     */
    private const CONFIG_PATH = 'order_orchestration';

    /**
     * Cache id for the IMS access token.
     */
    private const TOKEN_CACHE_KEY = 'order_orchestration_ims_token';

    private LoggerInterface $logger;
    private DeploymentConfig $deploymentConfig;
    private CacheInterface $cache;

    public function __construct(
        LoggerInterface $logger,
        DeploymentConfig $deploymentConfig,
        CacheInterface $cache
    ) {
        $this->logger = $logger;
        $this->deploymentConfig = $deploymentConfig;
        $this->cache = $cache;
    }

    /**
     * Consume an order.export message and forward it to the App Builder action.
     *
     * @param string $message JSON order payload published by the observer.
     * @return void
     */
    public function process($message)
    {
        $this->logger->info('ORDER RECEIVED : ' . $message);

        $endpoint = (string) $this->deploymentConfig->get(self::CONFIG_PATH . '/endpoint_url');
        if ($endpoint === '') {
            $this->logger->error('Order export: endpoint_url is not configured in env.php.');
            return;
        }

        $token = $this->getAccessToken();
        if ($token === null) {
            $this->logger->error('Order export: no IMS access token, skipping POST (message acked).');
            return;
        }

        $orgId = (string) $this->deploymentConfig->get(self::CONFIG_PATH . '/ims/org_id');

        try {
            $curl = $this->createClient();
            $curl->addHeader('Content-Type', 'application/json');
            $curl->addHeader('Authorization', 'Bearer ' . $token);
            // require-adobe-auth actions validate the token against the IMS org.
            if ($orgId !== '') {
                $curl->addHeader('x-gw-ims-org-id', $orgId);
            }
            // App Builder dev server (aio app dev) uses a self-signed cert.
            $curl->setOption(CURLOPT_SSL_VERIFYHOST, 0);
            $curl->setOption(CURLOPT_SSL_VERIFYPEER, false);
            // host.docker.internal resolves to an unroutable IPv6 here; force IPv4.
            $curl->setOption(CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            $curl->setTimeout(15);

            // $message is already a JSON string from the observer -> post as raw body.
            $curl->post($endpoint, $message);

            $status = $curl->getStatus();
            $body = $curl->getBody();

            if ($status >= 200 && $status < 300) {
                $this->logger->info(sprintf('Order export OK (HTTP %d): %s', $status, $body));
            } else {
                if ($status === 401) {
                    // Token rejected: drop it so the next message re-fetches.
                    $this->cache->remove(self::TOKEN_CACHE_KEY);
                }
                $this->logger->warning(sprintf('Order export failed (HTTP %d): %s', $status, $body));
            }
        } catch (\Throwable $e) {
            $this->logger->error('Order export request error: ' . $e->getMessage());
        }
    }

    /**
     * Obtain an Adobe IMS access token (OAuth Server-to-Server), cached until it
     * nears expiry.
     *
     * @return string|null
     */
    private function getAccessToken(): ?string
    {
        $cached = $this->cache->load(self::TOKEN_CACHE_KEY);
        if ($cached !== false && $cached !== '') {
            return $cached;
        }

        $ims = (array) $this->deploymentConfig->get(self::CONFIG_PATH . '/ims');
        $tokenUrl = (string) ($ims['token_url'] ?? '');
        $clientId = (string) ($ims['client_id'] ?? '');
        $clientSecret = (string) ($ims['client_secret'] ?? '');
        $scopes = (string) ($ims['scopes'] ?? '');

        if ($tokenUrl === '' || $clientId === '' || $clientSecret === '') {
            $this->logger->error('Order export: IMS credentials are not fully configured in env.php.');
            return null;
        }

        try {
            $curl = $this->createClient();
            $curl->addHeader('Content-Type', 'application/x-www-form-urlencoded');
            // Public IMS host with a valid cert -> keep verification ON.
            $curl->setOption(CURLOPT_SSL_VERIFYHOST, 2);
            $curl->setOption(CURLOPT_SSL_VERIFYPEER, true);
            $curl->setTimeout(15);

            $curl->post($tokenUrl, http_build_query([
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'grant_type' => 'client_credentials',
                'scope' => $scopes,
            ]));

            $status = $curl->getStatus();
            $body = (string) $curl->getBody();

            if ($status < 200 || $status >= 300) {
                $this->logger->error(sprintf('IMS token request failed (HTTP %d): %s', $status, $body));
                return null;
            }

            $data = json_decode($body, true);
            $token = is_array($data) ? ($data['access_token'] ?? null) : null;
            if (!$token) {
                $this->logger->error('IMS token request returned no access_token: ' . $body);
                return null;
            }

            // /ims/token/v3 reports expires_in in seconds. Subtract a buffer and
            // cap at 24h so a misread unit can't cache a stale token for days.
            $expiresIn = (int) ($data['expires_in'] ?? 0);
            $lifetime = $expiresIn > 120 ? min($expiresIn - 60, 86400) : 3540;
            $this->cache->save($token, self::TOKEN_CACHE_KEY, [], $lifetime);

            return $token;
        } catch (\Throwable $e) {
            $this->logger->error('IMS token request error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Fresh HTTP client per request so headers/SSL options never leak between
     * the IMS call and the App Builder call (the client retains state).
     *
     * @return Curl
     */
    private function createClient(): Curl
    {
        return new Curl();
    }
}
