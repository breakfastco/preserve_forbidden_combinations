<?php

if (!defined('BOOTSTRAP')) {
    die('Access denied');
}

/**
 * Rows core is about to delete for the current fn_delete_product_option() call.
 *
 * @return array<int, array{product_id: int, combination: array<int|string, int|string>}>
 */
function &fn_preserve_forbidden_combinations_snapshot()
{
    static $snapshot = [];

    return $snapshot;
}

/**
 * Copy forbidden combinations that include the option being removed.
 *
 * Core deletes those rows inside fn_delete_product_option(). This hook runs first.
 * $can_continue is left alone so the option is still removed.
 *
 * @param int   $option_id
 * @param int   $pid
 * @param int   $product_id
 * @param array $product_link
 * @param bool  $can_continue
 */
function fn_preserve_forbidden_combinations_delete_product_option_before_delete($option_id, $pid, $product_id, $product_link, $can_continue)
{
    $snapshot = &fn_preserve_forbidden_combinations_snapshot();
    $snapshot = fn_preserve_forbidden_combinations_rows_core_will_delete($option_id, $pid);
}

/**
 * Write the snapshotted rules back without the removed option.
 *
 * A rule is not restored unless at least two fields are still specified.
 * One specified field and the rest disregard would hide that value in every case.
 *
 * @param int  $option_id
 * @param int  $pid
 * @param bool $option_deleted
 * @param int  $product_id
 */
function fn_preserve_forbidden_combinations_delete_product_option_post($option_id, $pid, $option_deleted, $product_id)
{
    $snapshot = &fn_preserve_forbidden_combinations_snapshot();
    $rows = $snapshot;
    $snapshot = [];

    if (!$option_deleted || empty($rows)) {
        return;
    }

    foreach ($rows as $row) {
        $combination = $row['combination'];
        unset($combination[(int) $option_id], $combination[(string) $option_id]);

        if (!fn_preserve_forbidden_combinations_combination_still_constrains($combination)) {
            continue;
        }

        db_query('INSERT INTO ?:product_options_exceptions ?e', [
            'product_id' => $row['product_id'],
            'combination' => serialize($combination),
        ]);
    }
}

/**
 * Same rows fn_delete_product_option() selects, limited to combinations that contain this option.
 *
 * @param int $option_id
 * @param int $pid Product id when unlinking from one product, otherwise 0
 *
 * @return array<int, array{product_id: int, combination: array}>
 */
function fn_preserve_forbidden_combinations_rows_core_will_delete($option_id, $pid)
{
    if ($pid) {
        $rows = db_get_hash_array(
            'SELECT exception_id, product_id, combination FROM ?:product_options_exceptions WHERE product_id = ?i',
            'exception_id',
            $pid
        );
    } else {
        $rows = db_get_hash_array(
            'SELECT ?:product_options_exceptions.exception_id, ?:product_options_exceptions.product_id, ?:product_options_exceptions.combination'
            . ' FROM ?:product_options_exceptions'
            . ' LEFT JOIN ?:product_global_option_links'
            . ' ON ?:product_options_exceptions.product_id = ?:product_global_option_links.product_id'
            . ' WHERE ?:product_global_option_links.option_id = ?i',
            'exception_id',
            $option_id
        );
    }

    $matched = [];

    foreach ($rows as $exception_id => $row) {
        $combination = unserialize($row['combination']);

        if (!is_array($combination) || !fn_preserve_forbidden_combinations_combination_has_option($combination, $option_id)) {
            continue;
        }

        $matched[$exception_id] = [
            'product_id' => $row['product_id'],
            'combination' => $combination,
        ];
    }

    return $matched;
}

/**
 * @param array $combination
 * @param int   $option_id
 *
 * @return bool
 */
function fn_preserve_forbidden_combinations_combination_has_option(array $combination, $option_id)
{
    return array_key_exists((int) $option_id, $combination) || array_key_exists((string) $option_id, $combination);
}

/**
 * True when at least two fields are still a variant or disabled.
 *
 * Disregard is -1, stored as an integer or a string. One specified field with the rest
 * disregard is not a combination anymore.
 *
 * @param array $combination
 *
 * @return bool
 */
function fn_preserve_forbidden_combinations_combination_still_constrains(array $combination)
{
    $specified = 0;

    foreach ($combination as $variant_id) {
        if ((string) $variant_id === (string) OPTION_EXCEPTION_VARIANT_ANY) {
            continue;
        }

        $specified++;

        if ($specified >= 2) {
            return true;
        }
    }

    return false;
}
