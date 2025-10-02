<?php

namespace JLG\BrokenLinks\Scanner;

class RemoteRequestClient
{
    public function head($url, array $args = [])
    {
        return \wp_safe_remote_head($url, $args);
    }

    public function get($url, array $args = [])
    {
        return \wp_safe_remote_get($url, $args);
    }

    public function responseCode($response)
    {
        return \wp_remote_retrieve_response_code($response);
    }
}

