<?php
/**
 * Payment plugin general description
 */
return array(
    'name'        => /*_wp*/('MOLPay'),
    'description' => /*_wp*/('MOLPay Payments Standard Integration'),

    # plugin icon
    'icon'        => 'img/molpay-logo-100x50.jpg',

    # default payment gateway logo
    'logo'        => 'img/molpay-logo-100x50.jpg',

    # plugin vendor ID (for 3rd parties vendors it's a number)
    'vendor'      => 'webasyst',
    # plugin version
    'version'     => '1.0.2',
    'type'        => waPayment::TYPE_ONLINE,
);
