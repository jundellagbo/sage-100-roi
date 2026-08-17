<?php

class SageROICLIClass extends WP_CLI_COMMAND {

  public function items_inprocess_sync() {
    sage_roi_items_inprocess_sync_api();
    WP_CLI::success( "Items in process has been synced" );
  }

  public function items_sync() {
    sage_roi_items_all_sync_api();
    WP_CLI::success( "Items has been synced" );
  }

  public function customers_sync() {
    sage_roi_customers_sync();
    WP_CLI::success( "Customers has been synced" );
  }

  public function customers_inprocess_sync() {
    sage_roi_customers_inprocess_sync_api();
    WP_CLI::success( "Customers in process has been synced" );
  }

  public function orders_sync() {
    sage_roi_orders_sync();
    WP_CLI::success( "Orders has been synced" );
  }

  public function item_images_sync() {
    sage_roi_items_images_sync();
    WP_CLI::success( "Items images has been synced" );
  }

  /**
   * Shrinks the stored Sage order JSON to the fields the plugin reads back.
   *
   * Take a mysqldump first if you want a byte-for-byte undo; otherwise Sage is the
   * source of truth and a reset + orders_sync restores everything this drops.
   *
   * ## OPTIONS
   *
   * [--dry-run]
   * : Measure the saving without writing anything.
   *
   * [--batch=<rows>]
   * : Rows per pass. Default 2000.
   *
   * [--optimize]
   * : Rebuild the two tables afterwards so InnoDB returns the freed pages to disk.
   * Slow, and needs free space equal to the table being rebuilt.
   */
  public function prune_order_json( $args, $assoc_args ) {
    global $wpdb;

    $dry_run = ! empty( $assoc_args['dry-run'] );
    $stats   = sage_roi_prune_order_json( array(
      'dry_run'  => $dry_run,
      'max_rows' => (int) ( $assoc_args['batch'] ?? 2000 ),
      'progress' => array( 'WP_CLI', 'log' ),
    ) );

    $saved = $stats['bytes_before'] - $stats['bytes_after'];
    WP_CLI::log( sprintf(
      '%d duplicate row(s), %d order payload(s), %d line payload(s), %d purged order(s).',
      $stats['duplicates_removed'], $stats['orders_slimmed'], $stats['items_slimmed'], $stats['purged_orders']
    ) );
    WP_CLI::log( sprintf( 'Payloads: %s -> %s (%s freed).',
      size_format( $stats['bytes_before'] ), size_format( $stats['bytes_after'] ), size_format( $saved )
    ) );

    if ( $dry_run ) {
      WP_CLI::success( 'Dry run: nothing was written. Re-run without --dry-run to apply.' );
      return;
    }

    if ( ! empty( $assoc_args['optimize'] ) ) {
      foreach ( array( $wpdb->postmeta, $wpdb->prefix . 'woocommerce_order_itemmeta' ) as $table ) {
        WP_CLI::log( "Optimizing {$table}..." );
        $wpdb->query( "OPTIMIZE TABLE {$table}" );
      }
    } else {
      WP_CLI::log( 'Disk is not reclaimed until the tables are rebuilt; re-run with --optimize for that.' );
    }

    WP_CLI::success( 'Order JSON pruned.' );
  }
}

WP_CLI::add_command(
  'sage_roi',
  SageROICLIClass::class
);