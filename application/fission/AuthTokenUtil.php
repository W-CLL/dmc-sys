<?php
namespace  app\fission;
class AuthTokenUtil {
    private $secret_key;

    public function __construct($secret_key) {
        $this->secret_key = $secret_key;
    }

    public function is_valid_token($body, $signature) {
        if (strlen($this->secret_key) == 0 || strlen($signature) == 0) {
            return false;
        }

        $re_signature = $this->signature($body);
        return $signature == $re_signature;
    }

    public function signature($body) {
        return hash_hmac('sha256', $body, $this->secret_key);
    }

};

