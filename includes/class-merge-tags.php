<?php
/**
 * Gravity Flow Merge Tags Functions
 *
 * @package   GravityFlow
 * @copyright Copyright (c) 2017, Steven Henty S.L.
 * @license   http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since     1.7.1-dev
 */


if ( ! class_exists( 'GFForms' ) ) {
	die();
}

class Gravity_Flow_Merge_Tags {

	/**
	 * @var Gravity_Flow_Step|false $_current_step The current step or false if not available.
	 *
	 * @since 1.7.1-dev
	 */
	private $_current_step = false;

	/**
	 * Gravity_Flow_Merge_Tags constructor.
	 *
	 * @since 1.7.1-dev
	 */
	public function __construct() {
		if ( gravity_flow()->is_gravityforms_supported() ) {
			$this->add_hooks();
		}
	}

	/**
	 * Add the filter where merge tag replacement will occur.
	 *
	 * @since 1.7.1-dev
	 */
	public function add_hooks() {
		add_filter( 'gform_pre_replace_merge_tags', array( $this, 'replace_merge_tags' ), 10, 7 );
	}

	/**
	 * Target for the gform_pre_replace_merge_tags filter. Replaces supported merge tags.
	 *
	 * @since 1.7.1-dev
	 *
	 * @param string $text       The current text in which merge tags are being replaced.
	 * @param array  $form       The current form.
	 * @param array  $entry      The current entry.
	 * @param bool   $url_encode Indicates if URLs found in the replaced value should be encoded.
	 * @param bool   $esc_html   Indicates if HTML found in the replaced value should be escaped.
	 * @param bool   $nl2br      Indicates if newlines should be converted to html <br> tags
	 * @param string $format     Determines how the value should be formatted. HTML or text.
	 *
	 * @return string
	 */
	public function replace_merge_tags( $text, $form, $entry, $url_encode, $esc_html, $nl2br, $format ) {
		if ( strpos( $text, '{' ) === false  || empty( $entry ) ) {
			return $text;
		}

		$text = $this->replace_created_by( $text, $entry );
		$text = $this->replace_workflow_timeline( $text, $entry );

		$current_step = $this->get_current_step( $form, $entry );

		if ( $current_step ) {
			$text = $this->replace_workflow_note( $text, $entry, $current_step );
			$text = $this->replace_assignees( $text, $current_step );
			$text = $current_step->replace_variables( $text, null );
		}

		return $text;
	}

	/**
	 * If applicable get the current step.
	 *
	 * @since 1.7.1-dev
	 *
	 * @param array $form The current form.
	 * @param array $entry The current entry.
	 *
	 * @return Gravity_Flow_Step|false
	 */
	public function get_current_step( $form, $entry ) {
		if ( ! isset( $entry['workflow_step'] ) ) {
			$this->_current_step = false;

			return false;
		}

		if ( ! $this->_current_step || $this->_current_step->get_id() != $entry['workflow_step'] ) {
			$this->_current_step = gravity_flow()->get_current_step( $form, $entry );
		}

		return $this->_current_step;
	}

	/**
	 * Replace the {created_by} merge tags.
	 *
	 * @since 1.7.1-dev
	 *
	 * @param string $text  The text to be processed.
	 * @param array  $entry The current entry.
	 *
	 * @return string
	 */
	public function replace_created_by( $text, $entry ) {
		if ( empty( $entry['created_by'] ) ) {
			return $text;
		}

		preg_match_all( '/{created_by(:(.*?))?}/', $text, $matches, PREG_SET_ORDER );

		if ( ! empty( $matches ) ) {
			$entry_creator = new WP_User( $entry['created_by'] );

			foreach ( $matches as $match ) {
				if ( ! isset( $match[2] ) ) {
					continue;
				}

				$full_tag = $match[0];
				$property = $match[2];

				if ( $property == 'roles' ) {
					$value = implode( ', ', $entry_creator->roles );
				} else {
					$value = $entry_creator->get( $property );
				}

				$text = str_replace( $full_tag, esc_html( $value ), $text );
			}
		}

		return $text;
	}

	/**
	 * Replace the {workflow_timeline} merge tags with the entire timeline for the current entry.
	 *
	 * @since 1.7.1-dev
	 *
	 * @param string $text  The text to be processed.
	 * @param array  $entry The current entry.
	 *
	 * @return string
	 */
	public function replace_workflow_timeline( $text, $entry ) {
		preg_match_all( '/{workflow_timeline(:(.*?))?}/', $text, $matches, PREG_SET_ORDER );

		if ( is_array( $matches ) && isset( $matches[0] ) ) {
			$full_tag = $matches[0][0];
			$timeline = $this->get_timeline( $entry );
			$text     = str_replace( $full_tag, $timeline, $text );
		}

		return $text;
	}

	/**
	 * Get the content which will replace the {workflow_timeline} merge tag.
	 *
	 * @since 1.7.1-dev
	 *
	 * @param array $entry The current entry.
	 *
	 * @return string
	 */
	public function get_timeline( $entry ) {
		$html  = '';
		$notes = Gravity_Flow_Common::get_notes( $entry );

		if ( empty( $notes ) ) {
			return $html;
		}

		foreach ( $notes as $note ) {
			$html .= sprintf(
				'<br>%s: %s<br>%s<br>',
				esc_html( Gravity_Flow_Common::format_date( $note['date_created'] ) ),
				esc_html( $this->get_timeline_display_name( $note ) ),
				nl2br( esc_html( $note['value'] ) )
			);
		}

		return $html;
	}

	/**
	 * Get the timeline note display name.
	 *
	 * @since 1.7.1-dev
	 *
	 * @param array $note The note properties.
	 *
	 * @return string
	 */
	public function get_timeline_display_name( $note ) {
		if ( $note['assignee_key'] ) {
			$assignee     = new Gravity_Flow_Assignee( $note['assignee_key'] );
			$display_name = $assignee->get_display_name();
		} else {
			$step         = is_numeric( $note['step_id'] ) ? gravity_flow()->get_step( $note['step_id'] ) : Gravity_Flow_Steps::get( $note['step_id'] );
			$display_name = $step ? $step->get_label() : gravity_flow()->translate_navigation_label( 'Workflow' );
		}

		return $display_name;
	}

	/**
	 * Replace the {workflow_note} merge tags with the user submitted notes.
	 *
	 * @since 1.7.1-dev
	 *
	 * @param string            $text         The text to be processed.
	 * @param array             $entry        The current entry.
	 * @param Gravity_Flow_Step $current_step The current step for this entry.
	 *
	 * @return string
	 */
	public function replace_workflow_note( $text, $entry, $current_step ) {
		preg_match_all( '/{workflow_note(:(.*?))?}/', $text, $matches, PREG_SET_ORDER );

		if ( ! empty( $matches ) ) {
			foreach ( $matches as $match ) {
				$full_tag  = $match[0];
				$modifiers = rgar( $match, 2 );

				$a = Gravity_Flow_Common::get_string_attributes( $modifiers, array(
					'step_id'      => null,
					'display_name' => false,
					'display_date' => false
				) );

				$replacement = '';
				$notes       = $this->get_user_notes( $entry['id'], $a['step_id'] );

				if ( ! empty( $notes ) ) {
					$replacement_array = array();

					foreach ( $notes as $note ) {

						if ( $a['display_name'] ) {
							$assignee = $current_step->get_assignee( $note['assignee_key'] );
							$name     = $assignee->get_display_name();
						} else {
							$name = '';
						}

						$date = $a['display_date'] ? Gravity_Flow_Common::format_date( $note['date_created'] ) : '';

						$replacement = '';

						if ( $name || $date ) {
							$sep = $name && $date ? ': ' : '';

							$replacement .= sprintf( '<div class="gravityflow-note-header">%s%s%s</div>', esc_html( $name ), $sep, esc_html( $date ) );
						}

						$replacement .= sprintf( '<div class="gravityflow-note-value">%s</div>', nl2br( esc_html( $note['value'] ) ) );

						$replacement_array[] = $replacement;
					}

					$replacement = implode( '<br>', $replacement_array );
				}

				$text = str_replace( $full_tag, $replacement, $text );
			}
		}

		return $text;
	}

	/**
	 * Get the user submitted notes for a specific step.
	 *
	 * @since 1.7.1-dev
	 *
	 * @param int      $entry_id The current entry ID.
	 * @param int|null $step_id  The step ID or null to return the most recent note.
	 *
	 * @return array
	 */
	public function get_user_notes( $entry_id, $step_id ) {
		$notes      = Gravity_Flow_Common::get_workflow_notes( $entry_id, true );
		$user_notes = array();

		foreach ( $notes as $note ) {
			if ( ! $note['user_submitted'] || ( $step_id && $step_id != $note['step_id'] ) ) {
				continue;
			}

			$user_notes[] = $note;

			if ( is_null( $step_id ) ) {
				break;
			}
		}

		return $user_notes;
	}

	/**
	 * Replace the {assignees} merge tags.
	 *
	 * @since 1.7.1-dev
	 *
	 * @param string            $text         The text being processed.
	 * @param Gravity_Flow_Step $current_step The current step for this entry.
	 *
	 * @return string
	 */
	public function replace_assignees( $text, $current_step ) {
		preg_match_all( '/{assignees(:(.*?))?}/', $text, $assignees_matches, PREG_SET_ORDER );

		if ( ! empty( $assignees_matches ) ) {
			foreach ( $assignees_matches as $assignees_match ) {
				$full_tag       = $assignees_match[0];
				$options_string = isset( $assignees_match[2] ) ? $assignees_match[2] : '';

				$a = Gravity_Flow_Common::get_string_attributes( $options_string, array(
					'status'       => true,
					'user_email'   => true,
					'display_name' => true,
				) );

				$assignees          = $current_step->get_assignees();
				$assignees_text_arr = array();

				/** @var Gravity_Flow_Assignee $step_assignee */
				foreach ( $assignees as $step_assignee ) {
					$assignee_line = '';
					if ( $a['display_name'] ) {
						$assignee_line .= $step_assignee->get_display_name();
					}
					if ( $a['user_email'] && $step_assignee->get_type() == 'user_id' ) {
						if ( $assignee_line ) {
							$assignee_line .= ', ';
						}
						$assignee_user = new WP_User( $step_assignee->get_id() );
						$assignee_line .= $assignee_user->user_email;
					}
					if ( $a['status'] ) {
						$assignee_line .= ' (' . $step_assignee->get_status() . ')';
					}
					$assignees_text_arr[] = $assignee_line;
				}
				$assignees_text = join( "\n", $assignees_text_arr );
				$text           = str_replace( $full_tag, $assignees_text, $text );
			}
		}

		return $text;
	}

}

new Gravity_Flow_Merge_Tags();