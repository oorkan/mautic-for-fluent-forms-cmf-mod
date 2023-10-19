<?php

namespace FluentFormMautic\Integrations;

use FluentForm\App\Services\Integrations\IntegrationManager;
use FluentForm\Framework\Foundation\Application;
use FluentForm\Framework\Helpers\ArrayHelper;

class Bootstrap2 extends IntegrationManager
{
    public function __construct(Application $app)
    {
        $this->mauticInstanceNumber = 2;

        parent::__construct(
            $app,
            "Mautic {$this->mauticInstanceNumber}",
            "mautic-{$this->mauticInstanceNumber}",
            "_fluentform_mautic-{$this->mauticInstanceNumber}_settings",
            "mautic-{$this->mauticInstanceNumber}_feed",
            36
        );

        $this->logo = $this->app->url("public/img/integrations/mautic.png");

        $this->description = "Mautic is a fully-featured marketing automation platform that enables organizations of all sizes to send multi-channel communications at scale.";

        $this->registerAdminHooks();


        add_filter("fluentform_notifying_async_mautic", "__return_false");

        add_action("admin_init", function () {
            if (isset($_REQUEST["ff_mautic-{$this->mauticInstanceNumber}_auth"])) {
                $client = $this->getRemoteClient();
                if (isset($_REQUEST["code"])) {
                    // Get the access token now
                    $code = sanitize_text_field($_REQUEST["code"]);
                    $settings = $this->getGlobalSettings([]);
                    $settings = $client->generateAccessToken($code, $settings);

                    if (!is_wp_error($settings)) {
                        $settings["status"] = true;
                        update_option($this->optionKey, $settings, "no");
                    }

                    wp_redirect(admin_url("admin.php?page=fluent_forms_settings#general-mautic-{$this->mauticInstanceNumber}-settings"));
                    exit();
                } else {
                    $client->redirectToAuthServer();
                }
                die();
            }

        });

    }

    public function getGlobalFields($fields)
    {
        return [
            "logo" => $this->logo,
            "menu_title" => __("Mautic {$this->mauticInstanceNumber} Settings", "ffmauticcmfmodaddon"),
            "menu_description" => $this->description,
            "valid_message" => __("Your Mautic API Key is valid", "ffmauticcmfmodaddon"),
            "invalid_message" => __("Your Mautic API Key is not valid", "ffmauticcmfmodaddon"),
            "save_button_text" => __("Save Settings", "ffmauticcmfmodaddon"),
            "config_instruction" => $this->getConfigInstractions(),
            "fields" => [
                "apiUrl" => [
                    "type" => "text",
                    "placeholder" => "Your Mautic Installation URL",
                    "label_tips" => __("Please provide your Mautic Installation URL", "ffmauticcmfmodaddon"),
                    "label" => __("Your Moutic API URL", "ffmauticcmfmodaddon"),
                ],
                "client_id" => [
                    "type" => "text",
                    "placeholder" => "Mautic App Client ID",
                    "label_tips" => __("Enter your Mautic Client ID, if you do not have <br>Please login to your Mautic account and go to<br>Settings -> Integrations -> API key", "ffmauticcmfmodaddon"),
                    "label" => __("Mautic Client ID", "ffmauticcmfmodaddon"),
                ],
                "client_secret" => [
                    "type" => "password",
                    "placeholder" => "Mautic App Client Secret",
                    "label_tips" => __("Enter your Mautic API Key, if you do not have <br>Please login to your Mautic account and go to<br>Settings -> Integrations -> API key", "ffmauticcmfmodaddon"),
                    "label" => __("Mautic Client Secret", "ffmauticcmfmodaddon"),
                ],
            ],
            "hide_on_valid" => true,
            "discard_settings" => [
                "section_description" => "Your Mautic API integration is up and running",
                "button_text" => "Disconnect Mautic",
                "data" => [
                    "apiUrl" => "",
                    "client_id" => "",
                    "client_secret" => ""
                ],
                "show_verify" => true
            ]
        ];
    }

    public function getGlobalSettings($settings)
    {
        $globalSettings = get_option($this->optionKey);
        if (!$globalSettings) {
            $globalSettings = [];
        }
        $defaults = [
            "apiUrl" => "",
            "client_id" => "",
            "client_secret" => "",
            "status" => "",
            "access_token" => "",
            "refresh_token" => "",
            "expire_at" => false
        ];

        return wp_parse_args($globalSettings, $defaults);
    }

    public function saveGlobalSettings($settings)
    {
        if (empty($settings["apiUrl"])) {
            $integrationSettings = [
                "apiUrl" => "",
                "client_id" => "",
                "client_secret" => "",
                "status" => false
            ];
            // Update the details with siteKey & secretKey.
            update_option($this->optionKey, $integrationSettings, "no");
            wp_send_json_success([
                "message" => __("Your settings has been updated", "ffmauticcmfmodaddon"),
                "status" => false
            ], 200);
        }

        // Verify API key now
        try {
            $oldSettings = $this->getGlobalSettings([]);
            $oldSettings["apiUrl"] = esc_url_raw($settings["apiUrl"]);
            $oldSettings["client_id"] = sanitize_text_field($settings["client_id"]);
            $oldSettings["client_secret"] = sanitize_text_field($settings["client_secret"]);
            $oldSettings["status"] = false;

            update_option($this->optionKey, $oldSettings, "no");
            wp_send_json_success([
                "message" => "You are redirect to athenticate",
                "redirect_url" => admin_url("?ff_mautic-{$this->mauticInstanceNumber}_auth=1")
            ], 200);
        } catch (\Exception $exception) {
            wp_send_json_error([
                "message" => $exception->getMessage()
            ], 400);
        }
    }

    public function pushIntegration($integrations, $formId)
    {
        $integrations[$this->integrationKey] = [
            "title" => "{$this->title} Integration",
            "logo" => $this->logo,
            "is_active" => $this->isConfigured(),
            "configure_title" => "Configuration required!",
            "global_configure_url" => admin_url("admin.php?page=fluent_forms_settings#general-mautic-{$this->mauticInstanceNumber}-settings"),
            "configure_message" => "Mautic is not configured yet! Please configure your Mautic api first",
            "configure_button_text" => "Set Mautic API"
        ];
        return $integrations;
    }

    public function getIntegrationDefaults($settings, $formId)
    {
        return [
            "name" => "",
            "list_id" => "",
            "fields" => (object)[],
            "other_fields_mapping" => [
                [
                    "item_value" => "",
                    "label" => ""
                ]
            ],
            "conditionals" => [
                "conditions" => [],
                "status" => false,
                "type" => "all"
            ],
            "resubscribe" => false,
            "enabled" => true
        ];
    }

    public function getSettingsFields($settings, $formId)
    {
        return [
            "fields" => [
                [
                    "key" => "name",
                    "label" => "Feed Name",
                    "required" => true,
                    "placeholder" => "Your Feed Name",
                    "component" => "text"
                ],
                [
                    "key" => "fields",
                    "label" => "Map Fields",
                    "tips" => "Select which Fluent Form fields pair with their<br /> respective Mautic fields.",
                    "component" => "map_fields",
                    "field_label_remote" => "Mautic Fields",
                    "field_label_local" => "Form Field",
                    "primary_fileds" => [
                        [
                            "key" => "email",
                            "label" => "Email Address",
                            "required" => true,
                            "input_options" => "emails"
                        ]
                    ]
                ],
                [
                    "key" => "other_fields_mapping",
                    "require_list" => false,
                    "label" => "Other Fields",
                    "tips" => "Select which Fluent Form fields pair with their<br /> respective Mautic fields.",
                    "component" => "dropdown_many_fields",
                    "field_label_remote" => "Mautic Field",
                    "field_label_local" => "Mautic Field",
                    "options" => $this->otherFields()
                ],
                [
                    "key" => "tags",
                    "label" => "Lead Tags",
                    "required" => false,
                    "placeholder" => "Tags",
                    "component" => "value_text",
                    "inline_tip" => "Use comma separated value. You can use smart tags here"
                ],
                [
                    "key" => "last_active",
                    "label" => "Last Active",
                    "tips" => "When this option is enabled, FluentForm will pass the lead creation time to Mautic lead",
                    "component" => "checkbox-single",
                    "checkbox_label" => "Enable Last Active"
                ],
                [
                    "key" => "last_seen_ip",
                    "label" => "Push IP Address",
                    "tips" => "When this option is enabled, FluentForm will pass the ipAddress to Mautic",
                    "component" => "checkbox-single",
                    "checkbox_label" => "Enable IP address"
                ],
                [
                    "key" => "conditionals",
                    "label" => "Conditional Logics",
                    "tips" => "Allow Mautic integration conditionally based on your submission values",
                    "component" => "conditional_block"
                ],
                [
                    "key" => "enabled",
                    "label" => "Status",
                    "component" => "checkbox-single",
                    "checkbox_label" => "Enable This feed"
                ]
            ],
            "integration_title" => $this->title
        ];
    }

    protected function getLists()
    {
        return [];
    }

    public function getMergeFields($list = false, $listId = false, $formId = false)
    {
        return [];
    }

    public function otherFields()
    {
        $api    = $this->getRemoteClient();
        $fields = $api->listAvailableFields();  //get available fields from mautic including custom fields

        if( !$fields ){
            return [];
        }

        //sorting by id for standard ordered list
        usort($fields, function($a, $b) {
            return $a["id"] - $b["id"];
        });
        $fieldsFormatted = [];
        foreach ($fields as $field) {
            $fieldsFormatted[$field["alias"]] = $field["label"] ;
        }

        unset($fieldsFormatted["email"]);
        return $fieldsFormatted;
    }

    /*
     * Form Submission Hooks Here
     */
    public function notify($feed, $formData, $entry, $form)
    {
        $feedData = $feed["processedValues"];


        $subscriber = [
            "name" => ArrayHelper::get($feedData, "lead_name"),
            "email" => ArrayHelper::get($feedData, "email"),
            "phone" => ArrayHelper::get($feedData, "phone"),
            "created_at" => time(),
            "last_seen_at" => time()
        ];

        $tags = ArrayHelper::get($feedData, "tags");
        if ($tags) {
            $tags = explode(",", $tags);
            $formtedTags = [];
            foreach ($tags as $tag) {
                $formtedTags[] = wp_strip_all_tags(trim($tag));
            }
            $subscriber["tags"] = $formtedTags;
        }

        if (ArrayHelper::isTrue($feedData, "last_active")) {
            $subscriber["lastActive"] = $entry->created_at;
        }

        if (ArrayHelper::isTrue($feedData, "last_seen_ip")) {
            $subscriber["ipAddress"] = $entry->ip;
        }

        $subscriber = array_filter($subscriber);

        if (!empty($subscriber["email"]) && !is_email($subscriber["email"])) {
            $subscriber["email"] = ArrayHelper::get($formData, $subscriber["email"]);
        }

        foreach (ArrayHelper::get($feedData, "other_fields_mapping") as $item) {
            $subscriber[$item["label"]] = $item["item_value"];
        }


        if (!is_email($subscriber["email"])) {
            return;
        }

        $api = $this->getRemoteClient();
        $response = $api->subscribe($subscriber);

        if (is_wp_error($response)) {
            // it's failed
            do_action("ff_log_data", [
                "parent_source_id" => $form->id,
                "source_type" => "submission_item",
                "source_id" => $entry->id,
                "component" => $this->integrationKey,
                "status" => "failed",
                "title" => $feed["settings"]["name"],
                "description" => $response->errors["error"][0][0]["message"]
            ]);
        } else {
            // It's success
            do_action("ff_log_data", [
                "parent_source_id" => $form->id,
                "source_type" => "submission_item",
                "source_id" => $entry->id,
                "component" => $this->integrationKey,
                "status" => "success",
                "title" => $feed["settings"]["name"],
                "description" => "Mautic feed has been successfully initialed and pushed data"
            ]);
        }
    }

    protected function getConfigInstractions()
    {
        ob_start();
        ?>
        <div><h4>To Authenticate Mautic you have to enable your API first</h4>
            <ol>
                <li>Go to Your Mautic account dashboard, Click on the gear icon next to the username on top right
                    corner.
                    Click on Configuration settings >> Api settings and enable the Api
                </li>
                <li>Then go to "Api Credentials" and create a new oAuth 2 credentials with a redirect url (Your site
                    dashboard url with this slug /?ff_mautic-<?= $this->mauticInstanceNumber ?>_auth=1)<br/>
                    Your app redirect url will be <b><?= admin_url("?ff_mautic-{$this->mauticInstanceNumber}_auth=1") ?></b>

                </li>
                <li>Paste your Mautic account URL on Mautic API URL, also paste the Client Id and Secret Id. Then click
                    save settings.
                </li>
            </ol>
        </div>
        <?php
        return ob_get_clean();
    }

    public function getRemoteClient()
    {
        $settings = $this->getGlobalSettings([]);
        return new API(
            $settings["apiUrl"],
            $settings,
            $this->mauticInstanceNumber
        );
    }
}
