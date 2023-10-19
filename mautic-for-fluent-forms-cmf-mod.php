<?php
/**
 * Plugin Name: Mautic For Fluent Forms CMF Mod
 * Plugin URI:  https://github.com/oorkan/mautic-for-fluent-forms-cmf-mod
 * Description: Integrate your Mautic (multiple instances) with Fluentform.
 * Author: oorkan
 * Author URI:  https://oorkan.dev
 * Version: 1.0.3
 * Text Domain: ffmauticcmfmodaddon
 */

defined("ABSPATH") or die;
define("FFMAUTIC_DIR", plugin_dir_path(__FILE__));

class FluentFormMautic
{

    public function boot()
    {
        if (!defined("FLUENTFORM")) {
            return $this->injectDependency();
        }

        $this->includeFiles();

        if (function_exists("wpFluentForm")) {
            return $this->registerHooks(wpFluentForm());
        }
    }

    protected function includeFiles()
    {
        include_once FFMAUTIC_DIR."vendor/autoload.php";
        include_once FFMAUTIC_DIR."Integrations/API.php";
        include_once FFMAUTIC_DIR."Integrations/Bootstrap1.php";
        include_once FFMAUTIC_DIR."Integrations/Bootstrap2.php";
    }

    protected function registerHooks($fluentForm)
    {
        new \FluentFormMautic\Integrations\Bootstrap1($fluentForm);
        new \FluentFormMautic\Integrations\Bootstrap2($fluentForm);
    }


    /**
     * Notify the user about the FluentForm dependency and instructs to install it.
     */
    protected function injectDependency()
    {
        add_action("admin_notices", function () {
            $pluginInfo = $this->getFluentFormInstallationDetails();

            $class = "notice notice-error";

            $install_url_text = "Click Here to Install the Plugin";

            if ($pluginInfo->action == "activate") {
                $install_url_text = "Click Here to Activate the Plugin";
            }

            $message = "FluentForm Mautic Add-On Requires Fluent Forms Add On Plugin, <b><a href='{$pluginInfo->url}'>{$install_url_text}</a></b>";

            printf("<div class='%1$s'><p>%2$s</p></div>", esc_attr($class), $message);
        });
    }

    protected function getFluentFormInstallationDetails()
    {
        $activation = (object)[
            "action" => "install",
            "url"    => ""
        ];

        $allPlugins = get_plugins();

        if (isset($allPlugins["fluentform/fluentform.php"])) {
            $url = wp_nonce_url(
                self_admin_url("plugins.php?action=activate&plugin=fluentform/fluentform.php"),
                "activate-plugin_fluentform/fluentform.php"
            );

            $activation->action = "activate";
        } else {
            $api = (object)[
                "slug" => "fluentform"
            ];

            $url = wp_nonce_url(
                self_admin_url("update.php?action=install-plugin&plugin={$api->slug}"),
                "install-plugin_{$api->slug}"
            );
        }

        $activation->url = $url;

        return $activation;
    }
}

register_activation_hook(__FILE__, function () {
    $globalModules = get_option("fluentform_global_modules_status");
    if (!$globalModules || !is_array($globalModules)) {
        $globalModules = [];
    }

    $globalModules["mautic-1"] = "yes";
    $globalModules["mautic-2"] = "yes";
    update_option("fluentform_global_modules_status", $globalModules);
});

add_action("plugins_loaded", function () {
    (new FluentFormMautic())->boot();
});
