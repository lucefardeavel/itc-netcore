<?php

class RadiusRest {
    // WP9 release profile: disabled unless $legacy_devices_enabled = true (Reseller\Domain\ReleaseProfile)
    public function __construct()
    {
        \Reseller\Domain\ReleaseProfile::assertDeviceEnabled(static::class);
    }


    // show Description
    function description()
    {
        return [
            'title' => 'Radius Rest API',
            'description' => 'Handles the RADIUS connection through a REST API',
            'author' => 'ITC',
            'url' => []
        ];
    }

    // Add Customer to Mikrotik/Device
    function add_customer($customer, $plan)
    {
        // WP5B §4.3: device default deny for reseller-managed customers (only LegacyReprovisionService may call)
        \Reseller\Domain\LegacyReprovisionService::assertDeviceAllowed($customer);
    }
	
	function sync_customer($customer, $plan)
    {	
        // WP5B §4.3: device default deny for reseller-managed customers (only LegacyReprovisionService may call)
        \Reseller\Domain\LegacyReprovisionService::assertDeviceAllowed($customer);
        $this->add_customer($customer, $plan);
    }

    // Remove Customer to Mikrotik/Device
    function remove_customer($customer, $plan)
    {
        // set zero data usage
        if ($plan['typebp'] == "Limited" && ($plan['limit_type'] == "Data_Limit" || $plan['limit_type'] == "Both_Limit")) {
            // WP4: local rlm_rest counters (rad_acct) back to zero for the next period; rows are kept
            \Reseller\Radius\RestCounter::connection()->resetUsage((string) $customer['username']);
        }
    }

    // customer change username
    public function change_username($plan, $from, $to)
    {
        // WP5B §4.3: device default deny for reseller-managed customers (only LegacyReprovisionService may call)
        \Reseller\Domain\LegacyReprovisionService::assertDeviceAllowed(null, $from, $to);
    }

    // Add Plan to Mikrotik/Device
    function add_plan($plan)
    {
    }

    // Update Plan to Mikrotik/Device
    function update_plan($old_name, $plan)
    {
    }

    // Remove Plan from Mikrotik/Device
    function remove_plan($plan)
    {
    }

    // check if customer is online
    function online_customer($customer, $router_name)
    {
    }

    // make customer online
    function connect_customer($customer, $ip, $mac_address, $router_name)
    {
    }

    // make customer disconnect
    function disconnect_customer($customer, $router_name)
    {
    }

}