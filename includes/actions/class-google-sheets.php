<?php
/**
 * Google Sheets action.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks\Actions;

use BRN\Webhooks\Action;
use BRN\Webhooks\Action_Error;
use BRN\Webhooks\Connections\Google;
use BRN\Webhooks\Context;
use BRN\Webhooks\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Append a row to a Google Sheet (or update the row matching a lookup column).
 */
class Google_Sheets extends Action {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'google_sheets';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Google Sheets — add row', 'brn-webhooks' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Add a row to a spreadsheet, or update the row that matches a value.', 'brn-webhooks' );
	}

	/**
	 * Group.
	 *
	 * @return string
	 */
	public function group() {
		return 'Google';
	}

	/**
	 * Connection.
	 *
	 * @return string
	 */
	public function connection_type() {
		return 'google';
	}

	/**
	 * Fields.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			array(
				'key'         => 'spreadsheet',
				'type'        => 'url',
				'label'       => __( 'Spreadsheet URL or ID', 'brn-webhooks' ),
				'placeholder' => 'https://docs.google.com/spreadsheets/d/…/edit',
				'required'    => true,
				'help'        => __( 'Remember to share the spreadsheet with the service account email (Editor).', 'brn-webhooks' ),
			),
			array(
				'key'            => 'sheet',
				'type'           => 'select',
				'label'          => __( 'Sheet (tab)', 'brn-webhooks' ),
				'options_source' => 'sheets',
				'depends_on'     => array( 'spreadsheet', 'connection_id' ),
				'allow_custom'   => true,
				'required'       => true,
			),
			array(
				'key'     => 'mode',
				'type'    => 'choice',
				'label'   => __( 'Mode', 'brn-webhooks' ),
				'default' => 'append',
				'options' => self::options(
					array(
						'append' => __( 'Add a new row', 'brn-webhooks' ),
						'upsert' => __( 'Update matching row, or add', 'brn-webhooks' ),
					)
				),
			),
			array(
				'key'            => 'columns',
				'type'           => 'mapping',
				'label'          => __( 'Columns', 'brn-webhooks' ),
				'columns_source' => 'columns',
				'depends_on'     => array( 'spreadsheet', 'sheet', 'connection_id' ),
				'template'       => true,
				'help'           => __( 'Columns are read from the first row of the sheet (headers).', 'brn-webhooks' ),
			),
			array(
				'key'            => 'lookup_column',
				'type'           => 'select',
				'label'          => __( 'Match rows by column', 'brn-webhooks' ),
				'options_source' => 'column_names',
				'depends_on'     => array( 'spreadsheet', 'sheet', 'connection_id' ),
				'show_if'        => array( 'mode' => 'upsert' ),
				'help'           => __( 'If a row already has the same value in this column (e.g. email or lead ID) it is updated instead of adding a new one.', 'brn-webhooks' ),
			),
			array(
				'key'     => 'value_input',
				'type'    => 'select',
				'label'   => __( 'Values are', 'brn-webhooks' ),
				'default' => 'USER_ENTERED',
				'options' => self::options(
					array(
						'USER_ENTERED' => __( 'Parsed like typed by a user (numbers, dates, formulas)', 'brn-webhooks' ),
						'RAW'          => __( 'Stored exactly as text', 'brn-webhooks' ),
					)
				),
			),
		);
	}

	/**
	 * Extract the spreadsheet id.
	 *
	 * @param string $s URL or id.
	 * @return string
	 */
	public static function spreadsheet_id( $s ) {
		$s = trim( (string) $s );
		if ( preg_match( '#/spreadsheets/d/([a-zA-Z0-9_-]+)#', $s, $m ) ) {
			return $m[1];
		}
		return preg_match( '/^[a-zA-Z0-9_-]{20,}$/', $s ) ? $s : '';
	}

	/**
	 * A1 range for a sheet.
	 *
	 * @param string $sheet Sheet title.
	 * @param string $range Range.
	 * @return string
	 */
	private static function a1( $sheet, $range ) {
		return "'" . str_replace( "'", "''", $sheet ) . "'!" . $range;
	}

	/**
	 * API call.
	 *
	 * @param string     $method Method.
	 * @param string     $path   Path after /v4/spreadsheets/.
	 * @param array      $creds  Credentials.
	 * @param array|null $json   Body.
	 * @param array      $query  Query.
	 * @return array
	 */
	private static function api( $method, $path, array $creds, $json = null, array $query = array() ) {
		$opts = array(
			'headers' => array( 'Authorization' => 'Bearer ' . Google::token( $creds ) ),
			'label'   => 'Google Sheets API',
			'query'   => $query,
			'timeout' => 30,
		);
		if ( null !== $json ) {
			$opts['json'] = $json;
		}
		$res = Http::request( $method, 'https://sheets.googleapis.com/v4/spreadsheets/' . $path, $opts );
		return is_array( $res['json'] ) ? $res['json'] : array();
	}

	/**
	 * Header row.
	 *
	 * @param array  $creds Credentials.
	 * @param string $id    Spreadsheet id.
	 * @param string $sheet Sheet.
	 * @return string[]
	 */
	private static function headers( array $creds, $id, $sheet ) {
		$res = self::api( 'GET', rawurlencode( $id ) . '/values/' . rawurlencode( self::a1( $sheet, '1:1' ) ), $creds );
		return isset( $res['values'][0] ) ? array_map( 'strval', $res['values'][0] ) : array();
	}

	/**
	 * Execute.
	 *
	 * @param array      $config     Config.
	 * @param Context    $ctx        Context.
	 * @param array|null $connection Connection.
	 * @return array
	 * @throws Action_Error On failure.
	 */
	public function execute( array $config, Context $ctx, $connection ) {
		$creds = $this->creds( $connection );
		$id    = self::spreadsheet_id( self::cfg( $config, 'spreadsheet' ) );
		$sheet = trim( (string) self::cfg( $config, 'sheet' ) );
		if ( '' === $id ) {
			throw new Action_Error( __( 'Spreadsheet URL / ID is missing or invalid.', 'brn-webhooks' ), false );
		}
		if ( '' === $sheet ) {
			throw new Action_Error( __( 'Choose a sheet (tab).', 'brn-webhooks' ), false );
		}
		$row = self::build_row( (array) self::cfg( $config, 'columns', array() ), $ctx );
		if ( ! $row ) {
			throw new Action_Error( __( 'No columns are mapped.', 'brn-webhooks' ), false );
		}
		$input = 'RAW' === self::cfg( $config, 'value_input' ) ? 'RAW' : 'USER_ENTERED';

		if ( 'upsert' === self::cfg( $config, 'mode', 'append' ) && '' !== (string) self::cfg( $config, 'lookup_column' ) ) {
			$col_index = (int) self::cfg( $config, 'lookup_column' );
			$needle    = isset( $row[ $col_index ] ) ? (string) $row[ $col_index ] : '';
			if ( '' !== $needle ) {
				$letter = self::col_letter( $col_index );
				$values = self::api( 'GET', rawurlencode( $id ) . '/values/' . rawurlencode( self::a1( $sheet, $letter . ':' . $letter ) ), $creds );
				foreach ( isset( $values['values'] ) ? $values['values'] : array() as $i => $cells ) {
					if ( 0 === $i ) {
						continue; // Header.
					}
					if ( isset( $cells[0] ) && trim( (string) $cells[0] ) === trim( $needle ) ) {
						$row_num = $i + 1;
						// Keep existing cells for unmapped columns (null = unchanged).
						$range = self::a1( $sheet, 'A' . $row_num . ':' . self::col_letter( count( $row ) - 1 ) . $row_num );
						$res   = self::api( 'PUT', rawurlencode( $id ) . '/values/' . rawurlencode( $range ), $creds, array( 'values' => array( $row ) ), array( 'valueInputOption' => $input ) );
						return array(
							'action'       => 'updated',
							'row'          => $row_num,
							'updatedRange' => isset( $res['updatedRange'] ) ? $res['updatedRange'] : '',
						);
					}
				}
			}
		}

		$res = self::api(
			'POST',
			rawurlencode( $id ) . '/values/' . rawurlencode( self::a1( $sheet, 'A1' ) ) . ':append',
			$creds,
			array( 'values' => array( array_map( array( __CLASS__, 'cell' ), $row ) ) ),
			array(
				'valueInputOption' => $input,
				'insertDataOption' => 'INSERT_ROWS',
			)
		);
		$range = isset( $res['updates']['updatedRange'] ) ? $res['updates']['updatedRange'] : '';
		$num   = preg_match( '/!A?(\d+)/', $range, $m ) ? (int) $m[1] : null;
		return array(
			'action'       => 'added',
			'row'          => $num,
			'updatedRange' => $range,
		);
	}

	/**
	 * Null cells stay unchanged on update but must be empty strings on append.
	 *
	 * @param mixed $v Value.
	 * @return mixed
	 */
	public static function cell( $v ) {
		return null === $v ? '' : $v;
	}

	/**
	 * Build a row array from the column mapping (index => template).
	 *
	 * @param array   $columns Mapping rows [{column, index, value}].
	 * @param Context $ctx     Context.
	 * @return array
	 */
	public static function build_row( array $columns, Context $ctx ) {
		$cells = array();
		$max   = -1;
		foreach ( $columns as $col ) {
			if ( ! is_array( $col ) || ! isset( $col['index'] ) ) {
				continue;
			}
			$tpl = isset( $col['value'] ) ? (string) $col['value'] : '';
			if ( '' === $tpl ) {
				continue;
			}
			$i           = (int) $col['index'];
			$value       = $ctx->render( $tpl );
			$cells[ $i ] = $value;
			$max         = max( $max, $i );
		}
		if ( $max < 0 ) {
			return array();
		}
		$row = array();
		for ( $i = 0; $i <= $max; $i++ ) {
			$row[] = array_key_exists( $i, $cells ) ? $cells[ $i ] : null;
		}
		return $row;
	}

	/**
	 * Column index → letter (0 → A).
	 *
	 * @param int $i Index.
	 * @return string
	 */
	public static function col_letter( $i ) {
		$s = '';
		$i = (int) $i + 1;
		while ( $i > 0 ) {
			$m = ( $i - 1 ) % 26;
			$s = chr( 65 + $m ) . $s;
			$i = intdiv( $i - $m - 1, 26 );
		}
		return $s;
	}

	/**
	 * Dynamic options.
	 *
	 * @param string     $source     Source.
	 * @param array      $config     Config.
	 * @param array|null $connection Connection.
	 * @return array|\WP_Error
	 */
	public function get_options( $source, array $config, $connection = null ) {
		if ( ! $connection ) {
			return new \WP_Error( 'brnwh_no_conn', __( 'Choose a Google connection first.', 'brn-webhooks' ) );
		}
		$id = self::spreadsheet_id( isset( $config['spreadsheet'] ) ? $config['spreadsheet'] : '' );
		if ( '' === $id ) {
			return new \WP_Error( 'brnwh_no_sheet', __( 'Paste the spreadsheet URL first.', 'brn-webhooks' ) );
		}
		try {
			$creds = $connection['credentials'];
			if ( 'sheets' === $source ) {
				$res = self::api( 'GET', rawurlencode( $id ), $creds, null, array( 'fields' => 'properties.title,sheets.properties.title' ) );
				$out = array();
				foreach ( isset( $res['sheets'] ) ? $res['sheets'] : array() as $s ) {
					$out[] = array(
						'value' => $s['properties']['title'],
						'label' => $s['properties']['title'],
					);
				}
				return $out;
			}
			$sheet = isset( $config['sheet'] ) ? (string) $config['sheet'] : '';
			if ( '' === $sheet ) {
				return new \WP_Error( 'brnwh_no_tab', __( 'Choose a sheet (tab) first.', 'brn-webhooks' ) );
			}
			$headers = self::headers( $creds, $id, $sheet );
			if ( 'columns' === $source ) {
				$cols = array();
				foreach ( $headers as $i => $h ) {
					$cols[] = array(
						'index'  => $i,
						'column' => '' !== trim( $h ) ? $h : self::col_letter( $i ),
						'letter' => self::col_letter( $i ),
					);
				}
				if ( ! $cols ) {
					return new \WP_Error( 'brnwh_no_headers', __( 'The first row of this sheet is empty. Add column headers (e.g. Name, Email, Phone) and load again.', 'brn-webhooks' ) );
				}
				return array( 'columns' => $cols );
			}
			if ( 'column_names' === $source ) {
				$out = array();
				foreach ( $headers as $i => $h ) {
					$out[] = array(
						'value' => (string) $i,
						'label' => self::col_letter( $i ) . ' · ' . $h,
					);
				}
				return $out;
			}
		} catch ( \Throwable $e ) {
			$msg = $e->getMessage();
			if ( false !== strpos( $msg, '403' ) || false !== stripos( $msg, 'permission' ) ) {
				$email = isset( $connection['meta']['client_email'] ) ? $connection['meta']['client_email'] : '';
				/* translators: %s: service account email */
				$msg = sprintf( __( 'No access. Share the spreadsheet with %s as Editor.', 'brn-webhooks' ), $email );
			}
			return new \WP_Error( 'brnwh_google', $msg );
		}
		return array();
	}
}
