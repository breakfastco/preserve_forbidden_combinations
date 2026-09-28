<?php

if (!defined('BOOTSTRAP')) {
    die('Access denied');
}

fn_register_hooks(
    'delete_product_option_before_delete',
    'delete_product_option_post'
);
