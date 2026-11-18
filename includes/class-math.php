<?php
/**
 * Safe arithmetic evaluator.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks;

defined( 'ABSPATH' ) || exit;

/**
 * Evaluates + - * / % and parentheses without eval().
 */
class Math {

	/**
	 * Tokens.
	 *
	 * @var array
	 */
	private $tokens = array();

	/**
	 * Position.
	 *
	 * @var int
	 */
	private $pos = 0;

	/**
	 * Evaluate an expression.
	 *
	 * @param string $expr Expression (fields already rendered).
	 * @return float
	 * @throws \InvalidArgumentException On syntax errors / division by zero.
	 */
	public static function evaluate( $expr ) {
		$expr = (string) $expr;
		// "1,200" -> "1200"; drop currency signs and other decoration.
		$expr = preg_replace( '/(?<=\d),(?=\d{3}\b)/', '', $expr );
		$expr = preg_replace( '/[^0-9.+\-*\/%()\s]/u', '', $expr );
		if ( '' === trim( $expr ) ) {
			throw new \InvalidArgumentException( __( 'the expression is empty', 'brn-webhooks' ) );
		}
		preg_match_all( '/\d*\.?\d+|[+\-*\/%()]/', $expr, $m );
		$self         = new self();
		$self->tokens = $m[0];
		$value        = $self->expr();
		if ( $self->pos < count( $self->tokens ) ) {
			throw new \InvalidArgumentException( __( 'unexpected symbol', 'brn-webhooks' ) );
		}
		return $value;
	}

	/**
	 * Current token.
	 *
	 * @return string|null
	 */
	private function peek() {
		return isset( $this->tokens[ $this->pos ] ) ? $this->tokens[ $this->pos ] : null;
	}

	/**
	 * Sum.
	 *
	 * @return float
	 */
	private function expr() {
		$v = $this->term();
		while ( in_array( $this->peek(), array( '+', '-' ), true ) ) {
			$op = $this->tokens[ $this->pos++ ];
			$r  = $this->term();
			$v  = '+' === $op ? $v + $r : $v - $r;
		}
		return $v;
	}

	/**
	 * Product.
	 *
	 * @return float
	 * @throws \InvalidArgumentException Division by zero.
	 */
	private function term() {
		$v = $this->factor();
		while ( in_array( $this->peek(), array( '*', '/', '%' ), true ) ) {
			$op = $this->tokens[ $this->pos++ ];
			$r  = $this->factor();
			if ( '*' === $op ) {
				$v *= $r;
				continue;
			}
			if ( 0.0 === (float) $r ) {
				throw new \InvalidArgumentException( __( 'division by zero', 'brn-webhooks' ) );
			}
			$v = '/' === $op ? $v / $r : fmod( $v, $r );
		}
		return $v;
	}

	/**
	 * Number, sign or parenthesis.
	 *
	 * @return float
	 * @throws \InvalidArgumentException Syntax.
	 */
	private function factor() {
		$t = $this->peek();
		if ( null === $t ) {
			throw new \InvalidArgumentException( __( 'the expression ends too early (an empty field?)', 'brn-webhooks' ) );
		}
		if ( '+' === $t || '-' === $t ) {
			++$this->pos;
			$v = $this->factor();
			return '-' === $t ? -$v : $v;
		}
		if ( '(' === $t ) {
			++$this->pos;
			$v = $this->expr();
			if ( ')' !== $this->peek() ) {
				throw new \InvalidArgumentException( __( 'missing ")"', 'brn-webhooks' ) );
			}
			++$this->pos;
			return $v;
		}
		if ( is_numeric( $t ) ) {
			++$this->pos;
			return (float) $t;
		}
		throw new \InvalidArgumentException( __( 'unexpected symbol', 'brn-webhooks' ) );
	}
}
