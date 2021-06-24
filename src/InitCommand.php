<?php

namespace WP_CLI\Init;

use WP_CLI;
use WP_CLI_Command;
use WP_CLI\Utils;
use WP_CLI\SynopsisParser;

class InitCommand extends WP_CLI_Command {

	/**
	 * Init from scaffold.
	 *
	 * ## OPTIONS
	 *
	 * --scaffold=<scaffold>
	 * : Scaffold to generate.
	 *
	 * ## EXAMPLES
	 *
	 *     # Generate underscores scaffold.
	 *     $ wp init --scaffold=underscores
	 *     wp scaffold underscores
	 *     1/1 <slug>: sample
	 *     Success: Created theme 'Sample'.
	 *
	 * @when before_wp_load
	 *
	 * @param array $args       Indexed array of positional arguments.
	 * @param array $assoc_args Associative array of associative arguments.
	 */
	public function __invoke( $args, $assoc_args ) {
		$scaffold = Utils\get_flag_value( $assoc_args, 'scaffold' );
		$args = array( 'scaffold', $scaffold );
		$this->maybe_load_wordpress( $args );
		if ( WP_CLI::get_config( 'prompt' ) ) {
			$command = implode( ' ', $args ) . ' --prompt';
			WP_CLI::log( "wp $command" );
			WP_CLI::runcommand( $command );
		} else {
			WP_CLI::log( 'wp ' . implode( ' ', $args ) );
			$arguments = $args;
			list( $args, $assoc_args ) = $this->prompt_required_args( $args );
			$args = array_merge( $arguments, $args );
			WP_CLI::run_command( $args, $assoc_args );
		}
	}

	private function maybe_load_wordpress( $args ) {
		$when = $this->when( $args );
		if ( 'before_wp_load' !== $when ) {
			WP_CLI::get_runner()->load_wordpress();
		}
	}

	private function when( $args ) {
		$runner = WP_CLI::get_runner();
		$when = '';
		$r = $runner->find_command_to_run( $args );
		if ( is_array( $r ) ) {
			list( $command, $final_args, $cmd_path ) = $r;
			foreach ( $runner->early_invoke as $_when => $_path ) {
				foreach ( $_path as $cmd ) {
					if ( $cmd === $cmd_path ) {
						$when = $_when;
					}
				}
			}
		}
		return $when;
	}

	/**
	 * Wrapper for CLI Tools' prompt() method.
	 *
	 * @param string $question
	 * @param string $default
	 * @return string|false
	 */
	private function prompt( $question, $default ) {

		$question .= ': ';
		if ( function_exists( 'readline' ) ) {
			return readline( $question );
		}

		echo $question;

		$ret = stream_get_line( STDIN, 1024, "\n" );
		if ( Utils\is_windows() && "\r" === substr( $ret, -1 ) ) {
			$ret = substr( $ret, 0, -1 );
		}
		return $ret;
	}

	private function prompt_required_args( $args ) {

		$runner = WP_CLI::get_runner();
		$r = $runner->find_command_to_run( $args );
		$args = array();
		$assoc_args = array();
		if ( is_array( $r ) ) {
			list( $command ) = $r;

			$synopsis = $command->get_synopsis();
			$required = array();
			foreach ( explode( ' ', $synopsis ) as $positional ) {
				if ( trim( $positional, '[]' ) === $positional ) {
					$required[] = $positional;
				}
			}
			$synopsis = implode( ' ', $required );

			if ( ! $synopsis ) {
				return array( $args, $assoc_args );
			}

			// To skip the already provided positional arguments, we need to count
			// how many we had already received.
			$arg_index = 0;

			$spec = array_filter(
				SynopsisParser::parse( $synopsis ),
				function( $spec_arg ) use ( $args, $assoc_args, &$arg_index ) {
					switch ( $spec_arg['type'] ) {
						case 'positional':
						// Only prompt for the positional arguments that are not
						// yet provided, based purely on number.
						return $arg_index++ >= count( $args );
						case 'generic':
						// Always prompt for generic arguments.
						return true;
						case 'assoc':
						case 'flag':
						default:
						// Prompt for the specific flags that were not provided
						// yet, based on name.
						return ! isset( $assoc_args[ $spec_arg['name'] ] );
					}
				}
			);

			$spec = array_values( $spec );

			$prompt_args = true;
			if ( true !== $prompt_args ) {
				$prompt_args = explode( ',', $prompt_args );
			}

			// 'positional' arguments are positional (aka zero-indexed)
			// so $args needs to be reset before prompting for new arguments
			$args = array();

			foreach ( $spec as $key => $spec_arg ) {

				// When prompting for specific arguments (e.g. --prompt=user_pass),
				// ignore all arguments that don't match.
				if ( is_array( $prompt_args ) ) {
					if ( 'assoc' !== $spec_arg['type'] ) {
						continue;
					}
					if ( ! in_array( $spec_arg['name'], $prompt_args, true ) ) {
						continue;
					}
				}

				$current_prompt = ( $key + 1 ) . '/' . count( $spec ) . ' ';
				$default        = $spec_arg['optional'] ? '' : false;

				// 'generic' permits arbitrary key=value (e.g. [--<field>=<value>] )
				if ( 'generic' === $spec_arg['type'] ) {

					list( $key_token, $value_token ) = explode( '=', $spec_arg['token'] );

					$repeat = false;
					do {
						if ( ! $repeat ) {
							$key_prompt = $current_prompt . $key_token;
						} else {
							$key_prompt = str_repeat( ' ', strlen( $current_prompt ) ) . $key_token;
						}

						$key = $this->prompt( $key_prompt, $default );
						if ( false === $key ) {
							return array( $args, $assoc_args );
						}

						if ( $key ) {
							$key_prompt_count = strlen( $key_prompt ) - strlen( $value_token ) - 1;
							$value_prompt     = str_repeat( ' ', $key_prompt_count ) . '=' . $value_token;

							$value = $this->prompt( $value_prompt, $default );
							if ( false === $value ) {
								return [ $args, $assoc_args ];
							}

							$assoc_args[ $key ] = $value;

							$repeat = true;
						} else {
							$repeat = false;
						}
					} while ( $repeat );

				} else {
					$prompt = $current_prompt . $spec_arg['token'];
					if ( 'flag' === $spec_arg['type'] ) {
						$prompt .= ' (Y/n)';
					}

					$response = $this->prompt( $prompt, $default );
					if ( false === $response ) {
						return [ $args, $assoc_args ];
					}

					if ( $response ) {
						switch ( $spec_arg['type'] ) {
							case 'positional':
								if ( $spec_arg['repeating'] ) {
									$response = explode( ' ', $response );
								} else {
									$response = [ $response ];
								}
								$args = array_merge( $args, $response );
								break;
							case 'assoc':
								$assoc_args[ $spec_arg['name'] ] = $response;
								break;
							case 'flag':
								if ( 'Y' === strtoupper( $response ) ) {
									$assoc_args[ $spec_arg['name'] ] = true;
								}
								break;
						}
					}
				}
			}
		}

		return array( $args, $assoc_args );
	}
}
