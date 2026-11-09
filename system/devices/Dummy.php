<?php

class Dummy {

    // show Description
    function description()
    {
        return [
            'title' => 'Billing only',
            'description' => 'Billing without a network device: no change is sent to any router.',
            'author' => 'ITC',
            'url' => []
        ];
    }

    // Add Customer to Mikrotik/Device
    function add_customer($customer, $plan)
    {
    }

    // Remove Customer to Mikrotik/Device
    function remove_customer($customer, $plan)
    {
    }

    // customer change username
    public function change_username($from, $to)
    {
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