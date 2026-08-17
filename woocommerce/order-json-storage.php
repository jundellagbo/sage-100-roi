<?php
/**
 * Sage's sales-order payload is mirrored verbatim onto every WooCommerce order, which
 * grew postmeta + order_itemmeta past 5GB. 99% of each blob is the detail-line array:
 * every line carries a full nested Item with its product-line record and one row per
 * warehouse. Only the fields allowlisted below are ever read back, so writes now store
 * the subset and `wp sage_roi prune_order_json` rewrites the rows already on disk.
 *
 * None of this is a source of truth. Sage is; ticking "Reset Orders Sync" and running
 * orders_sync repopulates every field from the API.
 */

/**
 * Detail-line fields kept in both order_json and order_item_json. Superset of the two
 * API shapes (SalesOrderHistoryDetails and SalesOrderDetails); absent ones are skipped.
 */
function sage_roi_order_json_line_fields() {
    return array(
        'SalesOrderHistoryDetailId', 'SalesOrderDetailId', 'SalesOrderNo', 'SequenceNo',
        'LineKey', 'ItemCode', 'ItemType', 'ItemCodeDesc', 'WarehouseCode', 'PriceLevel',
        'UnitOfMeasure', 'UnitOfMeasureConvFactor', 'QuantityOrdered',
        'QuantityOrderedOriginal', 'QuantityOrderedRevised', 'QuantityShipped',
        'QuantityBackordered', 'UnitPrice', 'OriginalUnitPrice', 'ExtensionAmt',
        'LineDiscountPercent', 'PromiseDate', 'CommentText',
    );
}

/**
 * @param object   $line        Detail line from either API shape.
 * @param string[] $item_fields Fields to keep from the nested Item, if present.
 * @return object
 */
function sage_roi_slim_order_line( $line, $item_fields ) {
    if ( ! is_object( $line ) ) {
        return $line;
    }

    $slim = new stdClass();
    foreach ( sage_roi_order_json_line_fields() as $field ) {
        if ( property_exists( $line, $field ) ) {
            $slim->$field = $line->$field;
        }
    }

    if ( isset( $line->Item ) && is_object( $line->Item ) ) {
        $item = new stdClass();
        foreach ( $item_fields as $field ) {
            if ( property_exists( $line->Item, $field ) ) {
                $item->$field = $line->Item->$field;
            }
        }
        $slim->Item = $item;
    }

    return $slim;
}

/** Admin order-items columns read ProductLine and StandardUnitOfMeasure off the Item. */
function sage_roi_slim_order_item_json( $line ) {
    return sage_roi_slim_order_line( $line, array( 'ItemCode', 'ProductLine', 'StandardUnitOfMeasure' ) );
}

/**
 * The header's own scalars total ~2.7KB and Customer/Salesperson/Warehouse ~6KB, so
 * only the detail lines are reduced. Re-import matches lines by Item->ItemCode.
 */
function sage_roi_slim_order_json( $orderObject ) {
    if ( ! is_object( $orderObject ) ) {
        return $orderObject;
    }

    $slim = clone $orderObject;
    foreach ( array( 'SalesOrderHistoryDetails', 'SalesOrderDetails' ) as $collection ) {
        if ( empty( $slim->$collection ) || ! is_array( $slim->$collection ) ) {
            continue;
        }
        $slim->$collection = array_map(
            function ( $line ) {
                return sage_roi_slim_order_line( $line, array( 'ItemCode' ) );
            },
            $slim->$collection
        );
    }

    return $slim;
}

/** 0 disables the purge; slimming alone already caps growth at ~10KB per order. */
function sage_roi_order_json_retention_days() {
    return max( 0, (int) sage_roi_get_option( 'order_json_retention_days', 0 ) );
}

/**
 * Current on-disk size of the two tables the cleanup touches, for the Tools page readout.
 *
 * @return array<string, int> Bytes keyed by table name.
 */
function sage_roi_prune_table_sizes() {
    global $wpdb;

    $names = array();
    foreach ( sage_roi_prune_targets() as $target ) {
        $names[] = $target['table'];
    }

    $placeholders = implode( ', ', array_fill( 0, count( $names ), '%s' ) );
    $rows         = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT TABLE_NAME, DATA_LENGTH + INDEX_LENGTH AS bytes
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ( {$placeholders} )",
            $names
        )
    );

    $sizes = array();
    foreach ( (array) $rows as $row ) {
        $sizes[ $row->TABLE_NAME ] = (int) $row->bytes;
    }
    return $sizes;
}

/** Where a run's progress lives between passes. Not autoloaded — only the Tools page reads it. */
const SAGE_ROI_PRUNE_STATE_OPTION = 'sage_roi_prune_state';

/** Action Scheduler hook that advances a run one pass at a time. */
const SAGE_ROI_PRUNE_STEP_HOOK = 'sage_roi_prune_order_json_step';

/**
 * @return array<string, mixed>|null Null when no run has ever been started.
 */
function sage_roi_prune_get_state() {
    $state = get_option( SAGE_ROI_PRUNE_STATE_OPTION, null );
    return is_array( $state ) ? $state : null;
}

function sage_roi_prune_save_state( array $state ) {
    update_option( SAGE_ROI_PRUNE_STATE_OPTION, $state, false );
}

/** True while a run still has work queued. */
function sage_roi_prune_is_running() {
    $state = sage_roi_prune_get_state();
    return $state && 'done' !== $state['phase'] && empty( $state['cancelled'] );
}

/**
 * Begins a run and queues its first pass. Totals are counted up front so the Tools page can
 * show real progress rather than a spinner of unknown length.
 *
 * @param bool $dry_run Measure only, writing nothing.
 * @return array<string, mixed> The new state.
 */
function sage_roi_prune_start( $dry_run ) {
    global $wpdb;

    $totals = array();
    foreach ( sage_roi_prune_targets() as $name => $target ) {
        $totals[ $name ] = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$target['table']} WHERE meta_key = %s", $target['meta_key'] )
        );
    }

    $state = array(
        'phase'          => 'dedupe',
        'cursor'         => 0,
        'dry_run'        => (bool) $dry_run,
        'retention_days' => sage_roi_order_json_retention_days(),
        'started'        => time(),
        'updated'        => time(),
        'finished'       => null,
        'cancelled'      => false,
        'totals'         => $totals,
        'processed'      => array_fill_keys( array_keys( $totals ), 0 ),
        'stats'          => array(
            'duplicates_removed' => 0,
            'orders_slimmed'     => 0,
            'items_slimmed'      => 0,
            'purged_orders'      => 0,
            'bytes_before'       => 0,
            'bytes_after'        => 0,
        ),
    );
    sage_roi_prune_save_state( $state );
    sage_roi_prune_queue_next();

    return $state;
}

function sage_roi_prune_cancel() {
    $state = sage_roi_prune_get_state();
    if ( $state ) {
        $state['cancelled'] = true;
        $state['phase']     = 'done';
        $state['finished']  = time();
        sage_roi_prune_save_state( $state );
    }
    if ( function_exists( 'as_unschedule_all_actions' ) ) {
        as_unschedule_all_actions( SAGE_ROI_PRUNE_STEP_HOOK );
    }
}

/**
 * Queues the next pass.
 *
 * @param bool $force Skip the already-queued check. A pass requeueing itself must force:
 *                    while it runs, it is still its own "next scheduled action", so the
 *                    check would see itself and the chain would stop after one pass.
 */
function sage_roi_prune_queue_next( $force = false ) {
    if ( ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_next_scheduled_action' ) ) {
        return false;
    }
    if ( ! $force && as_next_scheduled_action( SAGE_ROI_PRUNE_STEP_HOOK ) ) {
        return true;
    }
    as_schedule_single_action( time(), SAGE_ROI_PRUNE_STEP_HOOK, array(), 'sage-100-roi' );
    return true;
}

add_action( SAGE_ROI_PRUNE_STEP_HOOK, 'sage_roi_prune_run_step' );

/**
 * One bounded pass: work the current phase, advance when it is exhausted, requeue if there is
 * more to do. Sized so a pass finishes well inside a worker's time limit even when rows are
 * megabyte-scale.
 *
 * @param int  $max_rows   Rows to rewrite in this pass.
 * @param bool $queue_next Queue the following pass. False when a caller drives the passes
 *                         itself, so WP-CLI does not fill the queue with work it already did.
 * @return array<string, mixed> The state after the pass.
 */
function sage_roi_prune_run_step( $max_rows = 400, $queue_next = true ) {
    $state = sage_roi_prune_get_state();
    if ( ! $state || 'done' === $state['phase'] || ! empty( $state['cancelled'] ) ) {
        return $state ?: array();
    }

    $noop    = static function () {};
    $targets = sage_roi_prune_targets();

    if ( 'dedupe' === $state['phase'] ) {
        $state['stats']['duplicates_removed'] = sage_roi_dedupe_plugin_postmeta( $state['dry_run'], $noop );
        $state['phase']                       = 'orders';
        $state['cursor']                      = 0;
    } elseif ( isset( $targets[ $state['phase'] ] ) ) {
        $target = $targets[ $state['phase'] ];
        $run    = sage_roi_slim_meta_table(
            $target['table'],
            $target['column'],
            $target['cache'],
            $target['meta_key'],
            $target['slimmer'],
            array(
                'dry_run'  => $state['dry_run'],
                'batch'    => 200,
                'after_id' => (int) $state['cursor'],
                'max_rows' => (int) $max_rows,
            )
        );

        $state['stats'][ $target['stat'] ] += $run['rows'];
        $state['stats']['bytes_before']    += $run['bytes_before'];
        $state['stats']['bytes_after']     += $run['bytes_after'];
        $state['processed'][ $state['phase'] ] += $run['seen'];
        $state['cursor']                        = $run['last_id'];

        if ( $run['done'] ) {
            $state['processed'][ $state['phase'] ] = $state['totals'][ $state['phase'] ];
            $state['phase']  = 'orders' === $state['phase'] ? 'items' : 'retention';
            $state['cursor'] = 0;
        }
    } elseif ( 'retention' === $state['phase'] ) {
        if ( $state['retention_days'] > 0 ) {
            $purge = sage_roi_purge_order_json_beyond_retention( $state['retention_days'], $state['dry_run'], $noop );
            $state['stats']['purged_orders'] += $purge['purged'];
            if ( ! $purge['more'] ) {
                $state['phase'] = 'done';
            }
        } else {
            $state['phase'] = 'done';
        }
    }

    $state['updated'] = time();
    if ( 'done' === $state['phase'] ) {
        $state['finished'] = time();
    }
    sage_roi_prune_save_state( $state );

    if ( $queue_next && 'done' !== $state['phase'] ) {
        sage_roi_prune_queue_next( true );
    }

    return $state;
}

/**
 * Runs a whole cleanup to completion in this process. Used by WP-CLI, where there is no time
 * limit to work around; the Tools page uses the queued passes above instead.
 *
 * @param array $args {
 *     @type bool     $dry_run  Measure only. Default true.
 *     @type int      $max_rows Rows per pass. Default 2000.
 *     @type callable $progress Called with a status string.
 * }
 * @return array Stats for the run.
 */
function sage_roi_prune_order_json( $args = array() ) {
    $args = wp_parse_args( $args, array( 'dry_run' => true, 'max_rows' => 2000, 'progress' => null ) );

    $report = static function ( $message ) use ( $args ) {
        if ( is_callable( $args['progress'] ) ) {
            call_user_func( $args['progress'], $message );
        }
    };

    sage_roi_prune_start( $args['dry_run'] );
    if ( function_exists( 'as_unschedule_all_actions' ) ) {
        as_unschedule_all_actions( SAGE_ROI_PRUNE_STEP_HOOK );
    }

    $phase = '';
    do {
        $state = sage_roi_prune_run_step( $args['max_rows'], false );
        if ( $state['phase'] !== $phase ) {
            $phase = $state['phase'];
            $report( 'Phase: ' . $phase );
        }
    } while ( 'done' !== $state['phase'] );

    if ( function_exists( 'as_unschedule_all_actions' ) ) {
        as_unschedule_all_actions( SAGE_ROI_PRUNE_STEP_HOOK );
    }

    return $state['stats'];
}

/**
 * Every wp_xookerdev_sage_roi_* postmeta key is single-valued, but orders synced before
 * WooCommerce 11 accumulated one extra row per sync run — up to 95 copies of the same
 * 450KB blob. Keeps the highest meta_id, which is the most recent mirror.
 *
 * @return int Rows removed.
 */
function sage_roi_dedupe_plugin_postmeta( $dry_run, $report ) {
    global $wpdb;

    $prefix = $wpdb->esc_like( sage_roi_option_key( '' ) ) . '%';
    $keys   = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT meta_key FROM {$wpdb->postmeta} WHERE meta_key LIKE %s
             GROUP BY meta_key HAVING COUNT(*) > COUNT(DISTINCT post_id)",
            $prefix
        )
    );

    $removed = 0;
    foreach ( $keys as $key ) {
        if ( $dry_run ) {
            $extra = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) - COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s",
                    $key
                )
            );
            $report( sprintf( '  %s: %d duplicate row(s) would be removed', $key, $extra ) );
            $removed += $extra;
            continue;
        }

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE stale FROM {$wpdb->postmeta} stale
                 JOIN ( SELECT post_id, MAX(meta_id) AS keep_id FROM {$wpdb->postmeta}
                        WHERE meta_key = %s GROUP BY post_id ) newest
                   ON newest.post_id = stale.post_id AND stale.meta_id < newest.keep_id
                 WHERE stale.meta_key = %s",
                $key,
                $key
            )
        );
        $report( sprintf( '  %s: %d duplicate row(s) removed', $key, (int) $deleted ) );
        $removed += (int) $deleted;
    }

    if ( ! $dry_run && $removed ) {
        wp_cache_flush_group( 'post_meta' );
    }

    return $removed;
}

/**
 * Slims one bounded run of rows, starting after `$after_id`.
 *
 * Pages by primary key and re-reads each value on its own so a run never holds more than one
 * decoded payload — the largest single blob on this site is 1.25MB. Bounded so the caller can
 * spread a multi-gigabyte rewrite across background passes instead of one long request.
 *
 * @return array{rows:int, seen:int, bytes_before:int, bytes_after:int, last_id:int, done:bool}
 *         `rows` counts what was rewritten; `seen` counts what was examined, which is what
 *         progress should advance by — most rows are already slim on a second run.
 */
function sage_roi_slim_meta_table( $table, $object_column, $cache_group, $meta_key, $slimmer, $args ) {
    global $wpdb;

    $result    = array( 'rows' => 0, 'seen' => 0, 'bytes_before' => 0, 'bytes_after' => 0, 'last_id' => 0, 'done' => false );
    $last_id   = isset( $args['after_id'] ) ? (int) $args['after_id'] : 0;
    $batch     = max( 1, (int) $args['batch'] );
    $max_rows  = isset( $args['max_rows'] ) ? (int) $args['max_rows'] : 0;
    $seen      = 0;

    while ( true ) {
        $limit = $batch;
        if ( $max_rows > 0 ) {
            $limit = min( $batch, $max_rows - $seen );
            if ( $limit < 1 ) {
                break;
            }
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_id, {$object_column} AS object_id FROM {$table}
                 WHERE meta_key = %s AND meta_id > %d ORDER BY meta_id ASC LIMIT %d",
                $meta_key,
                $last_id,
                $limit
            )
        );
        if ( ! $rows ) {
            $result['done'] = true;
            break;
        }

        foreach ( $rows as $row ) {
            $last_id = (int) $row->meta_id;
            $seen++;

            $value = $wpdb->get_var(
                $wpdb->prepare( "SELECT meta_value FROM {$table} WHERE meta_id = %d", $row->meta_id )
            );
            $decoded = json_decode( (string) $value );
            if ( ! is_object( $decoded ) ) {
                continue;
            }

            $slim = wp_json_encode( call_user_func( $slimmer, $decoded ) );
            unset( $decoded );
            if ( false === $slim || strlen( $slim ) >= strlen( (string) $value ) ) {
                continue;
            }

            $result['rows']++;
            $result['bytes_before'] += strlen( (string) $value );
            $result['bytes_after']  += strlen( $slim );

            if ( ! $args['dry_run'] ) {
                $wpdb->update( $table, array( 'meta_value' => $slim ), array( 'meta_id' => $row->meta_id ) );
                wp_cache_delete( (int) $row->object_id, $cache_group );
            }
        }
    }

    $result['last_id'] = $last_id;
    $result['seen']    = $seen;
    return $result;
}

/**
 * The two tables the cleanup rewrites, in the order it works through them.
 *
 * @return array<string, array<string, mixed>>
 */
function sage_roi_prune_targets() {
    global $wpdb;

    return array(
        'orders' => array(
            'table'    => $wpdb->postmeta,
            'column'   => 'post_id',
            'cache'    => 'post_meta',
            'meta_key' => sage_roi_option_key( 'order_json' ),
            'slimmer'  => 'sage_roi_slim_order_json',
            'stat'     => 'orders_slimmed',
            'label'    => 'order payloads',
        ),
        'items'  => array(
            'table'    => $wpdb->prefix . 'woocommerce_order_itemmeta',
            'column'   => 'order_item_id',
            'cache'    => 'order_item_meta',
            'meta_key' => sage_roi_option_key( 'order_item_json' ),
            'slimmer'  => 'sage_roi_slim_order_item_json',
            'stat'     => 'items_slimmed',
            'label'    => 'line payloads',
        ),
    );
}

/**
 * Drops the Sage JSON for one page of orders past the retention window.
 *
 * @return array{purged:int, more:bool} `more` is false once the window is clear. A dry run
 *                                      cannot consume its own queue, so it reports one page.
 */
function sage_roi_purge_order_json_beyond_retention( $retention_days, $dry_run, $report ) {
    $cutoff = time() - ( $retention_days * DAY_IN_SECONDS );

    $order_ids = wc_get_orders(
        array(
            'limit'        => 100,
            'return'       => 'ids',
            'orderby'      => 'date',
            'order'        => 'ASC',
            'date_created' => '<' . $cutoff,
            'meta_key'     => sage_roi_option_key( 'order_json' ),
            'meta_compare' => 'EXISTS',
        )
    );
    if ( ! $order_ids ) {
        return array( 'purged' => 0, 'more' => false );
    }

    if ( $dry_run ) {
        $report( sprintf( '  at least %d order(s) older than %d days would be purged', count( $order_ids ), $retention_days ) );
        return array( 'purged' => count( $order_ids ), 'more' => false );
    }

    foreach ( $order_ids as $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            continue;
        }
        // Not $order->delete_meta_data(): WooCommerce's CRUD filters out `wp_`-prefixed keys,
        // so it would never find this one and the purge would silently do nothing.
        delete_post_meta( $order->get_id(), sage_roi_option_key( 'order_json' ) );
        foreach ( $order->get_items() as $item_id => $item ) {
            wc_delete_order_item_meta( $item_id, sage_roi_option_key( 'order_item_json' ) );
        }
    }

    return array( 'purged' => count( $order_ids ), 'more' => true );
}
