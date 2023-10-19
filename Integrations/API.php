<?php

namespace  FluentFormMautic\Integrations;
use \Symfony\Component\Dotenv\Dotenv;

class API
{
    protected $apiUrl = "";

    protected $clientId = null;

    protected $clientSecret = null;

    protected $callBackUrl = null;

    protected $settings = [];

    public function __construct($apiUrl, $settings, $mauticInstanceNumber = 1)
    {
        $this->envVars = file_exists(FFMAUTIC_DIR.".env") ? (new Dotenv)->parse(file_get_contents(FFMAUTIC_DIR.".env")) : [];
        $this->pluginEnv = isset($this->envVars["PLUGIN_ENVIRONMENT"]) ? $this->envVars["PLUGIN_ENVIRONMENT"] : "production";
        $this->sslVerify = $this->pluginEnv === "local" ? false : true;
        $this->mauticInstanceNumber = $mauticInstanceNumber;

        if (substr($apiUrl, -1) == "/") {
            $apiUrl = substr($apiUrl, 0, -1);
        }

        $this->apiUrl = $apiUrl;
        $this->clientId = $settings["client_id"];
        $this->clientSecret = $settings["client_secret"];
        $this->settings = $settings;
        $this->callBackUrl = admin_url("?ff_mautic-{$this->mauticInstanceNumber}_auth=1");
    }

    public function redirectToAuthServer()
    {
        $url = add_query_arg([
            "client_id" => $this->clientId,
            "grant_type" => "authorization_code",
            "redirect_uri" => $this->callBackUrl,
            "response_type" => "code",
            "state" => md5($this->clientId)
        ], "{$this->apiUrl}/oauth/v2/authorize");

        wp_redirect($url);
        exit();
    }

    public function generateAccessToken($code, $settings)
    {
        $response = wp_remote_post("{$this->apiUrl}/oauth/v2/token", [
            "body" => [
                "client_id"     => $this->clientId,
                "client_secret" => $this->clientSecret,
                "grant_type"    => "authorization_code",
                "redirect_uri"  => $this->callBackUrl,
                "code"          => $code
            ],
            "sslverify" => $this->sslVerify
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $body = \json_decode($body, true);

        if (isset($body["error_description"])) {
            return new \WP_Error("invalid_client", $body["error_description"]);
        }

        $settings["access_token"] = $body["access_token"];
        $settings["refresh_token"] = $body["refresh_token"];
        $settings["expire_at"] = time() + intval($body["expires_in"]);

        return $settings;
    }

    public function makeRequest($action, $data = array(), $method = 'GET')
    {
        $settings = $this->getApiSettings();
        if (is_wp_error($settings)) {
            return $settings;
        }

        $url = "{$this->apiUrl}/api/{$action}";

        $data["access_token"] = $settings["access_token"];

        $response = false;
        if ($method == "GET") {
            $url = add_query_arg($data, $url);
            $response = wp_remote_get($url, ["sslverify" => $this->sslVerify]);
        } else if ($method == "POST") {
            $response = wp_remote_post($url, [
                "body" => $data,
                "sslverify" => $this->sslVerify
            ]);
        }

        if (!$response) {
            return new \WP_Error("invalid", "Request could not be performed");
        }

        if (is_wp_error($response)) {
            return new \WP_Error("wp_error", $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);

        $body = \json_decode($body, true);

        if (isset($body["errrors"])) {
            if (!empty($body["errrors"][0]["description"])) {
                $message = $body["errrors"][0]["description"];
            } else if (!empty($body["error_description"])) {
                $message = $body["error_description"];
            } else {
                $message = "Error when requesting to API Server";
            }

            return new \WP_Error("request_error", $message);
        }

        return $body;
    }

    protected function getApiSettings()
    {
        $this->maybeRefreshToken();

        $apiSettings = $this->settings;

        if (!$apiSettings["status"] || !$apiSettings["expire_at"]) {
            return new \WP_Error("invalid", "API key is invalid");
        }

        return array(
            "baseUrl"          => $this->apiUrl,       // Base URL of the Mautic instance
            "version"          => "OAuth2", // Version of the OAuth can be OAuth2 or OAuth1a. OAuth2 is the default value.
            "clientKey"        => $this->clientId,       // Client/Consumer key from Mautic
            "clientSecret"     => $this->clientSecret,       // Client/Consumer secret key from Mautic
            "callback"         => $this->callBackUrl,        // Redirect URI/Callback URI for this script
            "access_token" => $apiSettings["access_token"],
            "refresh_token" => $apiSettings["refresh_token"],
            "expire_at" => $apiSettings["expire_at"]
        );
    }

    protected function maybeRefreshToken()
    {
        $settings = $this->settings;
        $expireAt = $settings["expire_at"];

        if ($expireAt && $expireAt <= (time() - 10)) {
            // we have to regenerate the tokens
            $response = wp_remote_post("{$this->apiUrl}/oauth/v2/token", [
                "body" => [
                    "client_id"     => $this->clientId,
                    "client_secret" => $this->clientSecret,
                    "grant_type"    => "refresh_token",
                    "refresh_token" => $settings["refresh_token"],
                    "redirect_uri"  => $this->callBackUrl
                ],
                "sslverify" => $this->sslVerify
            ]);

            $body = wp_remote_retrieve_body($response);
            $body = \json_decode($body, true);

            if (!is_wp_error($response) || !isset($body['errors'])) {
                $settings["access_token"] = $body["access_token"];
                $settings["refresh_token"] = $body["refresh_token"];
                $settings["expire_at"] = time() + intval($body["expires_in"]);
                $this->settings = $settings;
                update_option("_fluentform_mautic-{$this->mauticInstanceNumber}_settings", $settings, "no");
            }
        }
    }

    public function listAvailableFields()
    {
        $response = $this->makeRequest("contacts/list/fields", [], "GET");

        if(!is_wp_error ($response)){
            return $response;
        };

        return false;
    }

    public function subscribe($subscriber)
    {
        $response = $this->makeRequest("contacts/new", $subscriber, "POST");

        if (is_wp_error($response)) {
            return new \WP_Error("error", $response->errors);
        }

        if ($response["contact"]["id"]) {
            return $response;
        }

        return new \WP_Error("error", $response->errors);
    }
}
