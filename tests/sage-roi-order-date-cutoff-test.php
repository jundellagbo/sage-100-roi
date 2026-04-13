<?php
/**
 * Sage ROI — Order Dates > Testing: simulate cart/checkout dropdown with a chosen “now” and Order Dates post.
 *
 * Loaded automatically from sage-100-roi.php when this file is present under tests/.
 *
 * Set SAGE_ROI_ORDER_DATE_CUTOFF_TEST_ACTIVE below to false to disable the menu and loader without removing the require.
 *
 * @package sage-100-roi
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
const SAGE_ROI_ORDER_DATE_CUTOFF_TEST_ACTIVE = true;

if ( ! SAGE_ROI_ORDER_DATE_CUTOFF_TEST_ACTIVE ) {
	return;
}

/**
 * Default form values when the page first loads (site timezone for interpretation).
 */
function sage_roi_order_date_cutoff_test_config() {
	return array(
		'now_datetime'        => '2026-04-10 09:00:00',
		'order_dates_post_id' => 6740,
	);
}

/**
 * @param string   $ymd_his `Y-m-d H:i:s` in site TZ.
 * @param DateTimeZone $tz Site timezone.
 * @return string Value for HTML `datetime-local`.
 */
function sage_roi_order_date_cutoff_test_to_datetime_local_value( $ymd_his, DateTimeZone $tz ) {
	$dt = DateTime::createFromFormat( 'Y-m-d H:i:s', $ymd_his, $tz );
	return $dt ? $dt->format( 'Y-m-d\TH:i' ) : '';
}

/**
 * Parse `datetime-local` or `Y-m-d H:i:s` string as wall time in site timezone.
 *
 * @param string        $raw Submitted value.
 * @param DateTimeZone $tz  Site timezone.
 * @return DateTime|null
 */
function sage_roi_order_date_cutoff_test_parse_now_input( $raw, DateTimeZone $tz ) {
	$raw = trim( (string) $raw );
	if ( $raw === '' ) {
		return null;
	}
	$dt = DateTime::createFromFormat( 'Y-m-d\TH:i', $raw, $tz );
	if ( $dt instanceof DateTime ) {
		return $dt;
	}
	$dt = DateTime::createFromFormat( 'Y-m-d H:i:s', $raw, $tz );
	if ( $dt instanceof DateTime ) {
		return $dt;
	}
	$dt = DateTime::createFromFormat( 'Y-m-d H:i', $raw, $tz );
	return ( $dt instanceof DateTime ) ? $dt : null;
}

/**
 * Build the same date set as {@see sage_roi_order_date_get_available_dates()} for one CPT post and optional
 * global repeat-day option, but with a fixed `$now` (for testing cutoff / “tomorrow” rules).
 *
 * @param DateTime $now  “Current” instant (timezone-aware).
 * @param int      $post_id Order Dates post ID.
 * @param bool     $apply_user_gate If true, skip the post when the logged-in user is not in `sage_roi_order_date_users`.
 * @return string[] Sorted Y-m-d list.
 */
function sage_roi_order_date_cutoff_test_simulate_dropdown_dates( DateTime $now, $post_id, $apply_user_gate = true ) {
	if (
		! function_exists( 'sage_roi_order_date_parse_ymd' )
		|| ! function_exists( 'sage_roi_order_date_get_holidays_md' )
		|| ! function_exists( 'sage_roi_order_date_get_cutoff_hour' )
		|| ! function_exists( 'sage_roi_order_date_is_date_selectable' )
		|| ! function_exists( 'sage_roi_order_date_is_weekday_open' )
		|| ! function_exists( 'sage_roi_order_date_is_candidate_eligible' )
		|| ! function_exists( 'sage_roi_order_date_expand_monthly_occurrences' )
		|| ! function_exists( 'sage_roi_order_date_expand_weekly_occurrences' )
		|| ! function_exists( 'sage_roi_order_date_expand_yearly_occurrences' )
		|| ! function_exists( 'sage_roi_order_date_get_repeat_months_count' )
		|| ! function_exists( 'sage_roi_order_date_get_repeat_weeks_count' )
		|| ! function_exists( 'sage_roi_order_date_get_repeat_years_count' )
		|| ! function_exists( 'sage_roi_order_date_sort_dates_asc' )
		|| ! defined( 'SAGE_ROI_ORDER_DATE_CPT' )
		|| ! defined( 'SAGE_ROI_ORDER_DATE_REPEAT_OPTION' )
	) {
		return array();
	}

	$post_id = (int) $post_id;
	$p       = get_post( $post_id );
	if ( ! $p || $p->post_type !== SAGE_ROI_ORDER_DATE_CPT || $p->post_status !== 'publish' ) {
		return array();
	}

	$user_id = get_current_user_id();
	if ( $apply_user_gate && ! $user_id ) {
		return array();
	}

	$users = get_post_meta( $p->ID, 'sage_roi_order_date_users', true );
	if ( ! is_array( $users ) ) {
		$users = array();
	}
	if ( $apply_user_gate && ! empty( $users ) && ! in_array( $user_id, array_map( 'intval', $users ), true ) ) {
		return array();
	}

	$holidays_md = sage_roi_order_date_get_holidays_md();
	$cutoff_hour = sage_roi_order_date_get_cutoff_hour();
	$all_dates   = array();
	$today       = clone $now;
	$today->setTime( 0, 0, 0 );

	$items = get_post_meta( $p->ID, 'sage_roi_order_date_dates', true );
	if ( ! is_array( $items ) ) {
		$items = array();
	}

	foreach ( $items as $item ) {
		$d = is_array( $item ) && isset( $item['date'] ) ? $item['date'] : ( is_string( $item ) ? $item : '' );
		if ( empty( $d ) ) {
			continue;
		}

		$dt = sage_roi_order_date_parse_ymd( $d );
		if ( ! $dt ) {
			continue;
		}

		$md = $dt->format( 'm-d' );
		if ( ! in_array( $md, $holidays_md, true ) && sage_roi_order_date_is_date_selectable( $dt, $now, $cutoff_hour ) && sage_roi_order_date_is_weekday_open( $dt ) ) {
			$all_dates[ $d ] = $d;
		}

		$repeat_m = is_array( $item ) && ! empty( $item['repeat_monthly'] );
		$repeat_w = is_array( $item ) && ! empty( $item['repeat_weekly'] );
		$repeat_y = is_array( $item ) && ! empty( $item['repeat_yearly'] );
		if ( ! $repeat_m && ! $repeat_w && ! $repeat_y ) {
			continue;
		}

		$extra_m = sage_roi_order_date_get_repeat_months_count();
		$extra_w = sage_roi_order_date_get_repeat_weeks_count();
		$extra_y = sage_roi_order_date_get_repeat_years_count();

		$candidates = array();
		if ( $repeat_m ) {
			$candidates = array_merge( $candidates, sage_roi_order_date_expand_monthly_occurrences( $d, $extra_m ) );
		}
		if ( $repeat_w ) {
			$candidates = array_merge( $candidates, sage_roi_order_date_expand_weekly_occurrences( $d, $extra_w ) );
		}
		if ( $repeat_y ) {
			$candidates = array_merge( $candidates, sage_roi_order_date_expand_yearly_occurrences( $d, $extra_y ) );
		}
		$candidates = array_unique( $candidates );
		foreach ( $candidates as $cand ) {
			if ( $cand === $d ) {
				continue;
			}
			if ( sage_roi_order_date_is_candidate_eligible( $cand, $holidays_md, $now, $cutoff_hour ) ) {
				$all_dates[ $cand ] = $cand;
			}
		}
	}

	$repeat = get_option( SAGE_ROI_ORDER_DATE_REPEAT_OPTION, array() );
	if ( is_array( $repeat ) && ! empty( $repeat ) ) {
		foreach ( $repeat as $day ) {
			$day = (int) $day;
			if ( $day < 1 || $day > 31 ) {
				continue;
			}
			for ( $m = 0; $m < 12; $m++ ) {
				$check = clone $today;
				$check->modify( "+{$m} months" );
				$max = (int) $check->format( 't' );
				if ( $day <= $max ) {
					$check->setDate( (int) $check->format( 'Y' ), (int) $check->format( 'm' ), $day );
					$str = $check->format( 'Y-m-d' );
					$md  = $check->format( 'm-d' );
					if ( ! in_array( $md, $holidays_md, true ) && sage_roi_order_date_is_date_selectable( $check, $now, $cutoff_hour ) && sage_roi_order_date_is_weekday_open( $check ) ) {
						$all_dates[ $str ] = $str;
					}
				}
			}
		}
	}

	$sorted = sage_roi_order_date_sort_dates_asc( array_values( $all_dates ) );
	if ( ! function_exists( 'sage_roi_order_date_is_sage_submit_date_on_or_after_today' ) ) {
		return $sorted;
	}
	$out = array();
	foreach ( $sorted as $ymd ) {
		if ( sage_roi_order_date_is_sage_submit_date_on_or_after_today( $ymd, $now ) ) {
			$out[] = $ymd;
		}
	}
	return $out;
}

/**
 * Sage API OrderDate (Ymd) and final Y-m-d for a chosen delivery date — same computation as
 * {@see sage_roi_order_date_get_sage_submit_order_date()} when `sage_final_order_date` meta is not set
 * (see `woocommerce/orders.php` `$final_order_date_ymd` from `order_date_ymd`).
 *
 * @param string $delivery_ymd Customer delivery date Y-m-d.
 * @return array{ order_date_ymd: string, final_ymd: string } Eight-digit Ymd and Y-m-d (empty strings if unavailable).
 */
function sage_roi_order_date_cutoff_test_sage_dates_for_delivery( $delivery_ymd ) {
	$delivery_ymd = is_string( $delivery_ymd ) ? trim( $delivery_ymd ) : '';
	$out          = array(
		'order_date_ymd' => '',
		'final_ymd'      => '',
	);
	if ( $delivery_ymd === '' || ! function_exists( 'sage_roi_order_date_calculate_final_order_date' ) ) {
		return $out;
	}
	$ymd = sage_roi_order_date_calculate_final_order_date( $delivery_ymd, null, 'Ymd' );
	if ( is_string( $ymd ) && $ymd !== '' ) {
		$out['order_date_ymd'] = $ymd;
	}
	$final = sage_roi_order_date_calculate_final_order_date( $delivery_ymd, null, 'Y-m-d' );
	if ( is_string( $final ) && $final !== '' ) {
		$out['final_ymd'] = $final;
	}
	return $out;
}

/**
 * @param string[] $dates Y-m-d delivery options.
 */
function sage_roi_order_date_cutoff_test_render_delivery_sage_table( array $dates ) {
	if ( empty( $dates ) ) {
		return;
	}
	echo '<table class="widefat striped" style="max-width:960px;margin-top:8px;"><thead><tr>';
	echo '<th>' . esc_html__( 'Delivery (dropdown)', 'sage-100-roi' ) . '</th>';
	echo '<th>' . esc_html__( 'Display label', 'sage-100-roi' ) . '</th>';
	echo '<th>' . esc_html__( 'Sage OrderDate (Ymd)', 'sage-100-roi' ) . '</th>';
	echo '<th>' . esc_html__( 'Final order date (Y-m-d)', 'sage-100-roi' ) . '</th>';
	echo '</tr></thead><tbody>';
	foreach ( $dates as $d ) {
		$sage = sage_roi_order_date_cutoff_test_sage_dates_for_delivery( $d );
		echo '<tr>';
		echo '<td><code>' . esc_html( $d ) . '</code></td>';
		echo '<td>' . esc_html( function_exists( 'sage_roi_order_date_format_delivery_display_label' ) ? sage_roi_order_date_format_delivery_display_label( $d ) : '' ) . '</td>';
		echo '<td><code>' . esc_html( $sage['order_date_ymd'] !== '' ? $sage['order_date_ymd'] : '—' ) . '</code></td>';
		echo '<td><code>' . esc_html( $sage['final_ymd'] !== '' ? $sage['final_ymd'] : '—' ) . '</code></td>';
		echo '</tr>';
	}
	echo '</tbody></table>';
}

/**
 * @param DateTime     $now_manual Parsed “now” in site TZ.
 * @param int          $post_id    Order Dates CPT ID.
 * @param DateTimeZone $tz         Site timezone (for labels).
 */
function sage_roi_order_date_cutoff_test_render_results( DateTime $now_manual, $post_id, DateTimeZone $tz ) {
	$post_id = (int) $post_id;

	echo '<hr style="margin:24px 0;" />';
	echo '<h2>' . esc_html__( 'Results', 'sage-100-roi' ) . '</h2>';

	echo '<table class="widefat" style="max-width:720px;"><tbody>';
	echo '<tr><th scope="row">' . esc_html__( 'Timezone', 'sage-100-roi' ) . '</th><td><code>' . esc_html( $tz->getName() ) . '</code></td></tr>';
	echo '<tr><th scope="row">' . esc_html__( 'Simulated “now”', 'sage-100-roi' ) . '</th><td><code>' . esc_html( $now_manual->format( 'Y-m-d H:i:s T' ) ) . '</code></td></tr>';
	echo '<tr><th scope="row">' . esc_html__( 'Order Dates post ID', 'sage-100-roi' ) . '</th><td><code>' . esc_html( (string) $post_id ) . '</code></td></tr>';

	if ( defined( 'SAGE_ROI_ORDER_DATE_CPT' ) && $post_id > 0 ) {
		$po = get_post( $post_id );
		if ( $po && $po->post_type === SAGE_ROI_ORDER_DATE_CPT ) {
			echo '<tr><th scope="row">' . esc_html__( 'Post', 'sage-100-roi' ) . '</th><td>' . esc_html( get_the_title( $po ) ) . ' <code>(' . esc_html( $po->post_status ) . ')</code></td></tr>';
		} else {
			echo '<tr><th scope="row">' . esc_html__( 'Post', 'sage-100-roi' ) . '</th><td><em>' . esc_html__( 'Not found or wrong post type.', 'sage-100-roi' ) . ' <code>' . esc_html( SAGE_ROI_ORDER_DATE_CPT ) . '</code></em></td></tr>';
		}
	}

	if ( function_exists( 'sage_roi_order_date_get_cutoff_hour' ) ) {
		echo '<tr><th scope="row">' . esc_html__( 'Next-day cutoff hour (settings)', 'sage-100-roi' ) . '</th><td><code>' . esc_html( (string) (int) sage_roi_order_date_get_cutoff_hour() ) . '</code></td></tr>';
	}

	echo '</tbody></table>';

	if ( ! function_exists( 'sage_roi_order_date_format_delivery_display_label' ) ) {
		echo '<p>' . esc_html__( 'Order-date helpers are not fully loaded.', 'sage-100-roi' ) . '</p>';
		return;
	}

	$for_user = sage_roi_order_date_cutoff_test_simulate_dropdown_dates( $now_manual, $post_id, true );
	$preview  = sage_roi_order_date_cutoff_test_simulate_dropdown_dates( $now_manual, $post_id, false );

	$users = get_post_meta( $post_id, 'sage_roi_order_date_users', true );
	if ( ! is_array( $users ) ) {
		$users = array();
	}
	$uid   = get_current_user_id();
	$gated = ! empty( $users ) && ! in_array( $uid, array_map( 'intval', $users ), true );

	echo '<p style="margin-top:12px;">' . esc_html__( 'Same rules as the cart/checkout Order Dates dropdown (holidays, open weekdays, cutoff for “tomorrow,” repeats on the post, plus global repeat days of month from Order Dates settings). The simulated “now” above is used instead of the server clock.', 'sage-100-roi' ) . '</p>';
	echo '<p><em>' . esc_html__( 'This simulation uses only the post ID you entered plus global repeat days. The live dropdown merges all published Order Dates posts.', 'sage-100-roi' ) . '</em></p>';
	echo '<p class="description">' . esc_html__( 'Sage columns: OrderDate (Ymd) is what WooCommerce sends to Sage on submit when the order has no stored sage_final_order_date meta — in woocommerce/orders.php that is $final_order_date_ymd from $sage_submit_dates[\'order_date_ymd\']. Final order date (Y-m-d) is the hyphenated calendar date for the same business-day offset.', 'sage-100-roi' ) . '</p>';

	echo '<h3 style="margin-top:1.5em;">' . esc_html__( 'Effective for your user', 'sage-100-roi' ) . '</h3>';
	echo '<p class="description">' . esc_html__( 'Respects per-post user assignment (as if this were the only published Order Dates post).', 'sage-100-roi' ) . '</p>';
	if ( empty( $for_user ) ) {
		echo '<p><em>' . esc_html__( 'None.', 'sage-100-roi' ) . '</em>';
		if ( $gated ) {
			echo ' ' . esc_html__( 'Your user is not assigned on this post; the live dropdown would not include these dates for you.', 'sage-100-roi' );
		}
		echo '</p>';
	} else {
		echo '<div style="max-height:360px;overflow:auto;">';
		sage_roi_order_date_cutoff_test_render_delivery_sage_table( $for_user );
		echo '</div>';
	}

	if ( $gated && ! empty( $preview ) ) {
		echo '<h3 style="margin-top:1.5em;">' . esc_html__( 'Admin preview (ignoring user assignment)', 'sage-100-roi' ) . '</h3>';
		echo '<div style="max-height:360px;overflow:auto;">';
		sage_roi_order_date_cutoff_test_render_delivery_sage_table( $preview );
		echo '</div>';
	}

	if ( function_exists( 'sage_roi_order_date_get_available_dates' ) ) {
		$live = sage_roi_order_date_get_available_dates();
		echo '<h3 style="margin-top:1.5em;">' . esc_html__( 'Live dropdown (reference)', 'sage-100-roi' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Server clock; all published Order Dates posts merged.', 'sage-100-roi' ) . '</p>';
		if ( empty( $live ) ) {
			echo '<p><em>' . esc_html__( 'Empty.', 'sage-100-roi' ) . '</em></p>';
		} else {
			echo '<div style="max-height:360px;overflow:auto;">';
			sage_roi_order_date_cutoff_test_render_delivery_sage_table( $live );
			echo '</div>';
		}
	}
}

add_action(
	'admin_menu',
	static function () {
		if ( ! defined( 'SAGE_ROI_ORDER_DATE_CPT' ) ) {
			return;
		}
		add_submenu_page(
			'edit.php?post_type=' . SAGE_ROI_ORDER_DATE_CPT,
			__( 'Order Dates — Testing', 'sage-100-roi' ),
			__( 'Testing', 'sage-100-roi' ),
			'manage_options',
			'sage-roi-order-date-testing',
			'sage_roi_order_date_cutoff_test_admin_page'
		);
	},
	21
);

/**
 * Admin page: Order Dates > Testing.
 */
function sage_roi_order_date_cutoff_test_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'sage-100-roi' ) );
	}

	$tz  = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
	$cfg = sage_roi_order_date_cutoff_test_config();

	$default_local = sage_roi_order_date_cutoff_test_to_datetime_local_value( $cfg['now_datetime'], $tz );
	$post_default  = (int) $cfg['order_dates_post_id'];

	$now_input_value = $default_local;
	$post_id_value   = $post_default;
	$parse_error     = '';

	if ( isset( $_POST['sage_roi_order_date_testing_submit'] ) ) {
		check_admin_referer( 'sage_roi_order_date_testing' );

		$raw_now = isset( $_POST['sage_roi_order_date_test_now'] ) ? sanitize_text_field( wp_unslash( $_POST['sage_roi_order_date_test_now'] ) ) : '';
		$raw_pid = isset( $_POST['sage_roi_order_date_test_post_id'] ) ? sanitize_text_field( wp_unslash( $_POST['sage_roi_order_date_test_post_id'] ) ) : '';

		$now_input_value = $raw_now !== '' ? $raw_now : $default_local;
		$post_id_value   = $raw_pid !== '' ? absint( $raw_pid ) : $post_default;
	}

	$now_manual = sage_roi_order_date_cutoff_test_parse_now_input( $now_input_value, $tz );
	if ( ! $now_manual ) {
		$parse_error  = __( 'Could not parse the simulated “now” value. Use the picker or enter a time like 2026-04-10 09:00:00.', 'sage-100-roi' );
		$now_manual = DateTime::createFromFormat( 'Y-m-d H:i:s', $cfg['now_datetime'], $tz );
	}
	if ( ! $now_manual instanceof DateTime ) {
		wp_die( esc_html__( 'Invalid default datetime in test config.', 'sage-100-roi' ) );
	}

	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Order Dates — Testing', 'sage-100-roi' ); ?></h1>
		<p class="description">
			<?php esc_html_e( 'Simulate which delivery dates would appear in the cart/checkout dropdown for a fixed point in time. Times are interpreted in the site timezone.', 'sage-100-roi' ); ?>
		</p>

		<form method="post" action="" style="max-width:640px;margin-top:16px;">
			<?php wp_nonce_field( 'sage_roi_order_date_testing' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="sage_roi_order_date_test_now"><?php esc_html_e( 'Simulated “now”', 'sage-100-roi' ); ?></label>
					</th>
					<td>
						<input
							type="datetime-local"
							id="sage_roi_order_date_test_now"
							name="sage_roi_order_date_test_now"
							value="<?php echo esc_attr( $now_input_value ); ?>"
							style="max-width:100%;"
						/>
						<p class="description">
							<?php esc_html_e( 'Browser datetime picker; stored as wall time in the site timezone.', 'sage-100-roi' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="sage_roi_order_date_test_post_id"><?php esc_html_e( 'Order Dates post ID', 'sage-100-roi' ); ?></label>
					</th>
					<td>
						<input
							type="number"
							id="sage_roi_order_date_test_post_id"
							name="sage_roi_order_date_test_post_id"
							value="<?php echo esc_attr( (string) $post_id_value ); ?>"
							class="small-text"
							min="1"
							step="1"
						/>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Run simulation', 'sage-100-roi' ), 'primary', 'sage_roi_order_date_testing_submit' ); ?>
		</form>

		<?php if ( $parse_error !== '' ) : ?>
			<div class="notice notice-warning"><p><?php echo esc_html( $parse_error ); ?></p></div>
		<?php endif; ?>

		<?php sage_roi_order_date_cutoff_test_render_results( $now_manual, $post_id_value, $tz ); ?>
	</div>
	<?php
}
