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

  public function orders_sync() {
    sage_roi_orders_sync();
    WP_CLI::success( "Orders has been synced" );
  }

  public function item_images_sync() {
    sage_roi_items_images_sync();
    WP_CLI::success( "Items images has been synced" );
  }
}

WP_CLI::add_command(
  'sage_roi',
  SageROICLIClass::class
);