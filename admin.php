<?php

class Tyrone_Admin_Page extends scbAdminPage {

	function setup() {
		$this->args = array(
			'page_title' => 'Tyrone Options',
			'parent' => 'edit.php?post_type=site',
		);
	}

	function page_content() {
		
		echo html( 'h3', 'Alert Settings' );
		echo $this->form_table( array(
			array(
				'title' => 'Admin Email',
				'type' => 'text',
				'name' => 'tyrone_admin_email',
				'desc' => 'Send alerts to this address',
			),
			array(
				'title' => 'Alerts?',
				'type' => 'select',
				'name' => 'tyrone_alerts_setting',
				'value' => array( 'Yes', 'No' ),
				'desc' => 'Send alerts?',				
			),
			array(
				'title' => 'Messages From',
				'type' => 'text',
				'name' => 'tyrone_emailer',
				'desc' => 'From address for alerts',
			),
			array(
				'title' => 'Messages From Name',
				'type' => 'text',
				'name' => 'tyrone_message_from',
				'desc' => 'From name for alerts',				
			),
		) );
		
		
		echo html( 'h3', 'Site Scanning' );
		echo $this->form_table( array(
			array(
				'title' => 'Enable Cron Job?',
				'type' => 'select',
				'name' => 'tyrone_cron_setting',
				'value' => array( 'Yes', 'No' ),
			),
			array(
				'title' => 'Look for Changes?',
				'type' => 'select',
				'name' => 'tyrone_diff_setting',
				'value' => array( 'Yes', 'No' ),
			),
			array(
				'title' => 'Scan for Spam Words?',
				'type' => 'select',
				'name' => 'tyrone_juniper_setting',
				'value' => array( 'Yes', 'No' ),
			),
			array(
				'title' => 'Spam Words',
				'type' => 'textarea',
				'name' => 'tyrone_terms',
				'extra' => array( 'rows' => 10, 'cols' => 100 ),
				'desc'=> 'If any of these terms are found in the site content it will be flagged',
			),
		) );
		
		echo html( 'h3', 'Backend Style' );
		echo $this->form_table( array(
			array(
				'title' => 'Admin Style',
				'type' => 'select',
				'name' => 'tyrone_admin_css',
				'value' => array( 'simplify', 'normal' ),
				'desc' => 'Setting this to simplify will hide Posts, Pages, Media and Links menus',
			),
		) );
		
	}

	function page_footer() {
		parent::page_footer();

		// Reset all forms
?>
		<script type="text/javascript">
		(function() {
			var forms = document.getElementsByTagName('form');
			for (var i = 0; i < forms.length; i++) {
				forms[i].reset();
			}
		}());
		</script>
<?php
	}
}


