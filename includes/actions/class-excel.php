<?php
/**
 * Microsoft 365 Excel action.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks\Actions;

use BRN\Webhooks\Action;
use BRN\Webhooks\Action_Error;
use BRN\Webhooks\Connections\Microsoft;
use BRN\Webhooks\Context;

defined( 'ABSPATH' ) || exit;

/**
 * Add a row to an Excel workbook in OneDrive for Business / SharePoint.
 */
class Excel extends Action {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'excel';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Excel 365 — add row', 'brn-webhooks' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Add a row to an Excel workbook in OneDrive or SharePoint.', 'brn-webhooks' );
	}

	/**
	 * Group.
	 *
	 * @return string
	 */
	public function group() {
		return 'Microsoft 365';
	}

	/**
	 * Connection.
	 *
	 * @return string
	 */
	public function connection_type() {
		return 'microsoft';
	}

	/**
	 * Fields.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			array(
				'key'         => 'file_url',
				'type'        => 'url',
				'label'       => __( 'Workbook link', 'brn-webhooks' ),
				'placeholder' => 'https://contoso.sharepoint.com/:x:/s/…',
				'required'    => true,
				'help'        => __( 'In Excel click Share → Copy link, and paste it here.', 'brn-webhooks' ),
			),
			array(
				'key'     => 'target',
				'type'    => 'choice',
				'label'   => __( 'Write to', 'brn-webhooks' ),
				'default' => 'table',
				'options' => self::options(
					array(
						'table'     => __( 'Table (recommended)', 'brn-webhooks' ),
						'worksheet' => __( 'Worksheet', 'brn-webhooks' ),
					)
				),
				'help'    => __( 'Tables are safest: select your data in Excel → Insert → Table. Rows are added at the end of the table.', 'brn-webhooks' ),
			),
			array(
				'key'            => 'table',
				'type'           => 'select',
				'label'          => __( 'Table', 'brn-webhooks' ),
				'options_source' => 'tables',
				'depends_on'     => array( 'file_url', 'connection_id' ),
				'allow_custom'   => true,
				'show_if'        => array( 'target' => 'table' ),
			),
			array(
				'key'            => 'worksheet',
				'type'           => 'select',
				'label'          => __( 'Worksheet', 'brn-webhooks' ),
				'options_source' => 'worksheets',
				'depends_on'     => array( 'file_url', 'connection_id' ),
				'allow_custom'   => true,
				'show_if'        => array( 'target' => 'worksheet' ),
			),
			array(
				'key'            => 'columns',
				'type'           => 'mapping',
				'label'          => __( 'Columns', 'brn-webhooks' ),
				'columns_source' => 'columns',
				'depends_on'     => array( 'file_url', 'target', 'table', 'worksheet', 'connection_id' ),
				'template'       => true,
			),
		);
	}

	/**
	 * Resolve a sharing link to drive + item ids (cached for a day).
	 *
	 * @param string $url   Link.
	 * @param string $token Token.
	 * @return array [drive_id, item_id]
	 * @throws Action_Error On failure.
	 */
	private static function resolve( $url, $token ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			throw new Action_Error( __( 'Workbook link is missing.', 'brn-webhooks' ), false );
		}
		$key = 'brnwh_xl_' . md5( $url );
		$hit = get_transient( $key );
		if ( is_array( $hit ) && ! empty( $hit[0] ) ) {
			return $hit;
		}
		$encoded = 'u!' . rtrim( strtr( base64_encode( $url ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$item    = Microsoft::graph( 'GET', 'shares/' . $encoded . '/driveItem?$select=id,name,parentReference', $token );
		if ( empty( $item['id'] ) || empty( $item['parentReference']['driveId'] ) ) {
			throw new Action_Error( __( 'Could not open the workbook from this link.', 'brn-webhooks' ), false );
		}
		$out = array( $item['parentReference']['driveId'], $item['id'] );
		set_transient( $key, $out, DAY_IN_SECONDS );
		return $out;
	}

	/**
	 * Workbook base path.
	 *
	 * @param array  $creds Credentials.
	 * @param string $url   Link.
	 * @return array [token, base]
	 */
	private static function base( array $creds, $url ) {
		$token = Microsoft::token( $creds );
		list( $drive, $item ) = self::resolve( $url, $token );
		return array( $token, 'drives/' . rawurlencode( $drive ) . '/items/' . rawurlencode( $item ) . '/workbook/' );
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
		$row   = Google_Sheets::build_row( (array) self::cfg( $config, 'columns', array() ), $ctx );
		if ( ! $row ) {
			throw new Action_Error( __( 'No columns are mapped.', 'brn-webhooks' ), false );
		}
		$row = array_map( array( Google_Sheets::class, 'cell' ), $row );
		list( $token, $base ) = self::base( $creds, self::cfg( $config, 'file_url' ) );

		if ( 'worksheet' === self::cfg( $config, 'target', 'table' ) ) {
			$ws = trim( (string) self::cfg( $config, 'worksheet' ) );
			if ( '' === $ws ) {
				throw new Action_Error( __( 'Choose a worksheet.', 'brn-webhooks' ), false );
			}
			$wsp   = 'worksheets/' . rawurlencode( $ws ) . '/';
			$used  = Microsoft::graph( 'GET', $base . $wsp . 'usedRange(valuesOnly=true)?$select=address,rowCount,rowIndex', $token );
			$next  = isset( $used['rowIndex'], $used['rowCount'] ) ? (int) $used['rowIndex'] + (int) $used['rowCount'] + 1 : 2;
			// An empty sheet reports A1 with one row.
			$range = 'A' . $next . ':' . Google_Sheets::col_letter( count( $row ) - 1 ) . $next;
			Microsoft::graph( 'PATCH', $base . $wsp . "range(address='" . $range . "')", $token, array( 'values' => array( $row ) ) );
			return array(
				'action' => 'added',
				'row'    => $next,
				'range'  => $range,
			);
		}

		$table = trim( (string) self::cfg( $config, 'table' ) );
		if ( '' === $table ) {
			throw new Action_Error( __( 'Choose a table.', 'brn-webhooks' ), false );
		}
		// Pad to the table width.
		$header = Microsoft::graph( 'GET', $base . 'tables/' . rawurlencode( $table ) . '/headerRowRange?$select=values,columnCount', $token );
		$width  = isset( $header['columnCount'] ) ? (int) $header['columnCount'] : count( $row );
		$row    = array_slice( array_pad( $row, $width, '' ), 0, max( $width, 1 ) );
		$res    = Microsoft::graph( 'POST', $base . 'tables/' . rawurlencode( $table ) . '/rows', $token, array( 'values' => array( $row ) ) );
		return array(
			'action' => 'added',
			'index'  => isset( $res['index'] ) ? $res['index'] : null,
		);
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
			return new \WP_Error( 'brnwh_no_conn', __( 'Choose a Microsoft 365 connection first.', 'brn-webhooks' ) );
		}
		if ( empty( $config['file_url'] ) ) {
			return new \WP_Error( 'brnwh_no_file', __( 'Paste the workbook link first.', 'brn-webhooks' ) );
		}
		try {
			list( $token, $base ) = self::base( $connection['credentials'], $config['file_url'] );
			switch ( $source ) {
				case 'tables':
					$res = Microsoft::graph( 'GET', $base . 'tables?$select=name', $token );
					$out = array();
					foreach ( isset( $res['value'] ) ? $res['value'] : array() as $t ) {
						$out[] = array(
							'value' => $t['name'],
							'label' => $t['name'],
						);
					}
					return $out;
				case 'worksheets':
					$res = Microsoft::graph( 'GET', $base . 'worksheets?$select=name', $token );
					$out = array();
					foreach ( isset( $res['value'] ) ? $res['value'] : array() as $w ) {
						$out[] = array(
							'value' => $w['name'],
							'label' => $w['name'],
						);
					}
					return $out;
				case 'columns':
					$headers = array();
					if ( 'worksheet' === ( isset( $config['target'] ) ? $config['target'] : 'table' ) ) {
						if ( empty( $config['worksheet'] ) ) {
							return new \WP_Error( 'brnwh_no_ws', __( 'Choose a worksheet first.', 'brn-webhooks' ) );
						}
						$res     = Microsoft::graph( 'GET', $base . 'worksheets/' . rawurlencode( $config['worksheet'] ) . "/range(address='A1:AZ1')?\$select=values", $token );
						$headers = isset( $res['values'][0] ) ? $res['values'][0] : array();
						while ( $headers && '' === trim( (string) end( $headers ) ) ) {
							array_pop( $headers );
						}
					} else {
						if ( empty( $config['table'] ) ) {
							return new \WP_Error( 'brnwh_no_table', __( 'Choose a table first.', 'brn-webhooks' ) );
						}
						$res     = Microsoft::graph( 'GET', $base . 'tables/' . rawurlencode( $config['table'] ) . '/headerRowRange?$select=values', $token );
						$headers = isset( $res['values'][0] ) ? $res['values'][0] : array();
					}
					$cols = array();
					foreach ( $headers as $i => $h ) {
						$cols[] = array(
							'index'  => $i,
							'column' => '' !== trim( (string) $h ) ? (string) $h : Google_Sheets::col_letter( $i ),
							'letter' => Google_Sheets::col_letter( $i ),
						);
					}
					if ( ! $cols ) {
						return new \WP_Error( 'brnwh_no_headers', __( 'No header row found. Add column headers in the first row.', 'brn-webhooks' ) );
					}
					return array( 'columns' => $cols );
			}
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'brnwh_excel', $e->getMessage() );
		}
		return array();
	}
}
