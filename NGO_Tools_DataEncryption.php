<?php

class NGO_Tools_DataEncryption
{
    private $key;

    public function __construct() {
        if(!defined('LOGGED_IN_SALT')){
            throw new Exception('Logged In Salt is required, please check the following website:'
                . ' https://developer.wordpress.org/reference/functions/wp_salt/');
        }
        // Use a strong secret key from WordPress salts
        $this->key = LOGGED_IN_SALT;
    }

    public function encrypt($data) {
        if (empty($data)) {
            return '';
        }
        $ivlen = openssl_cipher_iv_length($cipher = "AES-256-CBC");
        $iv = openssl_random_pseudo_bytes($ivlen);
        $ciphertext_raw = openssl_encrypt($data, $cipher, $this->key, OPENSSL_RAW_DATA, $iv);
        // Store iv with ciphertext for decryption
        return base64_encode($iv . $ciphertext_raw);
    }

    public function decrypt($data) {
        if (empty($data)) {
            return '';
        }
        $c = base64_decode($data);
        $ivlen = openssl_cipher_iv_length($cipher = "AES-256-CBC");
        $iv = substr($c, 0, $ivlen);
        $ciphertext_raw = substr($c, $ivlen);
        $original_plaintext = openssl_decrypt($ciphertext_raw, $cipher, $this->key, OPENSSL_RAW_DATA, $iv);
        return $original_plaintext;
    }

}