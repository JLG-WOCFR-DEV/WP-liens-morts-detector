<?php

namespace JLG\BrokenLinks\Scanner;

interface HttpClientInterface
{
    /**
     * Execute an HTTP HEAD request.
     *
     * @param string               $url
     * @param array<string, mixed> $args
     *
     * @return array|\WP_Error
     */
    public function head($url, array $args = []);

    /**
     * Execute an HTTP GET request.
     *
     * @param string               $url
     * @param array<string, mixed> $args
     *
     * @return array|\WP_Error
     */
    public function get($url, array $args = []);

    /**
     * Extract the HTTP response code from a WordPress HTTP response.
     *
     * @param mixed $response
     *
     * @return int
     */
    public function responseCode($response);
}
