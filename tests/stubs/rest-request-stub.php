<?php

namespace {
    if (!class_exists('WP_REST_Request')) {
        class WP_REST_Request
        {
            /**
             * @var array<string,mixed>
             */
            private array $params = [];

            /**
             * @var array<string,mixed>|null
             */
            private ?array $jsonParams = null;

            /**
             * @param array<string,mixed>      $params
             * @param array<string,mixed>|null $jsonParams
             */
            public function __construct(array $params = [], ?array $jsonParams = null)
            {
                $this->params = $params;
                $this->jsonParams = $jsonParams;
            }

            /**
             * @param string $key
             * @param mixed  $value
             *
             * @return void
             */
            public function set_param($key, $value)
            {
                $this->params[(string) $key] = $value;
            }

            /**
             * @param string $key
             *
             * @return mixed
             */
            public function get_param($key)
            {
                $key = (string) $key;

                return $this->params[$key] ?? null;
            }

            /**
             * @param string $key
             *
             * @return bool
             */
            public function has_param($key)
            {
                $key = (string) $key;

                return array_key_exists($key, $this->params);
            }

            /**
             * @return array<string,mixed>|null
             */
            public function get_json_params()
            {
                if ($this->jsonParams !== null) {
                    return $this->jsonParams;
                }

                return $this->params;
            }

            /**
             * @param array<string,mixed>|null $params
             *
             * @return void
             */
            public function set_json_params(?array $params)
            {
                $this->jsonParams = $params;
            }
        }
    }
}

