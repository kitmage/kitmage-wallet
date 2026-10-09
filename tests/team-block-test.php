<?php
/**
 * Regression tests for filtering Fluent Booking Team Gutenberg block events.
 *
 * Run: php tests/team-block-test.php
 */

namespace FluentBooking\App\Models {
    class CalendarSlot {
        public static $rows = array();

        public static function where( $field, $value ) {
            return (new MockEventQuery())->where( $field, $value );
        }
    }

    class MockEventQuery {
        private $where = array();
        private $in = array();

        public function where( $field, $value ) {
            $this->where[ $field ] = $value;
            return $this;
        }

        public function whereIn( $field, $values ) {
            $this->in[ $field ] = $values;
            return $this;
        }

        public function get() {
            $events = array();
            foreach ( CalendarSlot::$rows as $event ) {
                foreach ( $this->where as $field => $value ) {
                    if ( $event[ $field ] !== $value ) {
                        continue 2;
                    }
                }
                foreach ( $this->in as $field => $values ) {
                    if ( ! in_array( $event[ $field ], $values, false ) ) {
                        continue 2;
                    }
                }
                $events[] = (object) $event;
            }
            return $events;
        }
    }
}

namespace {
    define( 'ABSPATH', __DIR__ );
    $GLOBALS['wallet_test_is_admin'] = false;
    $GLOBALS['wallet_test_user_id'] = 7;
    $GLOBALS['wallet_test_allow'] = array();
    $GLOBALS['wallet_test_seen'] = array();
    $GLOBALS['wallet_test_hooks'] = array();

    function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
        $GLOBALS['wallet_test_hooks'][] = array( $hook, $callback, $priority, $args );
    }
    function is_admin() {
        return $GLOBALS['wallet_test_is_admin'];
    }
    function absint( $value ) {
        return abs( (int) $value );
    }
    function get_current_user_id() {
        return $GLOBALS['wallet_test_user_id'];
    }
    function kitmage_wallet_fluent_booking_affordability( $event_id, $user_id ) {
        $GLOBALS['wallet_test_seen'][] = array( $event_id, $user_id );
        return array( 'allowed' => $GLOBALS['wallet_test_allow'][ $event_id ] ?? false );
    }
    function assert_same( $expected, $actual, $description ) {
        if ( $expected !== $actual ) {
            fwrite( STDERR, $description . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n" );
            exit( 1 );
        }
        $GLOBALS['wallet_test_passed'] = ( $GLOBALS['wallet_test_passed'] ?? 0 ) + 1;
    }
    function block( $hosts, $block_name = 'fluent-booking/team-management' ) {
        return array( 'blockName' => $block_name, 'attrs' => array( 'calendarHosts' => $hosts ) );
    }

    \FluentBooking\App\Models\CalendarSlot::$rows = array(
        array( 'id' => 11, 'calendar_id' => 3, 'status' => 'active' ),
        array( 'id' => 12, 'calendar_id' => 3, 'status' => 'active' ),
        array( 'id' => 13, 'calendar_id' => 3, 'status' => 'inactive' ),
        array( 'id' => 21, 'calendar_id' => 4, 'status' => 'active' ),
        array( 'id' => 22, 'calendar_id' => 4, 'status' => 'active' ),
    );
    $GLOBALS['wallet_test_allow'] = array( 11 => true, 12 => false, 21 => false, 22 => true );

    require dirname( __DIR__ ) . '/includes/fluent-booking-team-block.php';
    kitmage_wallet_register_fluent_booking_team_block_hooks();

    assert_same(
        array( array( 'render_block_data', 'kitmage_wallet_filter_fluent_booking_team_block_data', 20, 1 ) ),
        $GLOBALS['wallet_test_hooks'],
        'filter registered'
    );

    $first = block( array( array( 'id' => '3', 'events' => array( 'all' ), 'custom' => 'keep me' ) ) );
    $filtered = kitmage_wallet_filter_fluent_booking_team_block_data( $first );
    assert_same( array( 11 ), $filtered['attrs']['calendarHosts'][0]['events'], 'all resolves and filters' );
    assert_same( 'keep me', $filtered['attrs']['calendarHosts'][0]['custom'], 'host metadata preserved' );
    assert_same( array( 'all' ), $first['attrs']['calendarHosts'][0]['events'], 'input block not changed' );
    assert_same( array( array( 11, 7 ), array( 12, 7 ) ), $GLOBALS['wallet_test_seen'], 'current user used in affordability checks' );

    $filtered = kitmage_wallet_filter_fluent_booking_team_block_data(
        block( array( array( 'id' => 3, 'events' => array( 12, 11, 13, 21, 11, 'bad' ) ) ) )
    );
    assert_same( array( 11 ), $filtered['attrs']['calendarHosts'][0]['events'], 'specific event selection validates host and active status' );

    $filtered = kitmage_wallet_filter_fluent_booking_team_block_data(
        block( array( array( 'id' => 3, 'events' => array( 12 ) ), array( 'id' => 4, 'events' => array( 'all' ) ) ) )
    );
    assert_same( array( array( 'id' => 4, 'events' => array( 22 ) ) ), $filtered['attrs']['calendarHosts'], 'host removed when no eligible events' );

    $filtered = kitmage_wallet_filter_fluent_booking_team_block_data(
        block( array( array( 'id' => 3, 'events' => array( 12 ) ), array( 'id' => 4, 'events' => array( 21 ) ) ) )
    );
    assert_same( array(), $filtered['attrs']['calendarHosts'], 'all hosts removed when all events unaffordable' );

    $filtered = kitmage_wallet_filter_fluent_booking_team_block_data(
        block( array( array( 'id' => 3, 'events' => array( 11, 12 ) ) ), 'core/paragraph' )
    );
    assert_same( block( array( array( 'id' => 3, 'events' => array( 11, 12 ) ) ), 'core/paragraph' ), $filtered, 'other block unchanged' );

    $GLOBALS['wallet_test_is_admin'] = true;
    assert_same( $first, kitmage_wallet_filter_fluent_booking_team_block_data( $first ), 'editor unchanged' );
    $GLOBALS['wallet_test_is_admin'] = false;

    assert_same( array( 'blockName' => 'fluent-booking/team-management', 'attrs' => array() ),
        kitmage_wallet_filter_fluent_booking_team_block_data( array( 'blockName' => 'fluent-booking/team-management', 'attrs' => array() ) ), 'missing hosts unchanged' );

    $GLOBALS['wallet_test_allow'] = array( 11 => true, 12 => true, 21 => true, 22 => true );
    $filtered = kitmage_wallet_filter_fluent_booking_team_block_data( $first );
    assert_same( array( 11, 12 ), $filtered['attrs']['calendarHosts'][0]['events'], 'all allowed when affordable' );

    assert_same( array( 'id' => 3, 'events' => array( 12 ) ),
        kitmage_wallet_filter_fluent_booking_team_block_data( block( array( array( 'id' => 3, 'events' => array( 12 ) ) ) ) )['attrs']['calendarHosts'][0],
        'allowed explicit event unchanged except normalization'
    );

    echo 'team-block tests passed (' . $GLOBALS['wallet_test_passed'] . " assertions)\n";
}
