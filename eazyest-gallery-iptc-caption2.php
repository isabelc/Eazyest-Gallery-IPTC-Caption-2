<?php
/*
Plugin Name: Eazyest Gallery IPTC Caption 2
Plugin URI: http://isabelcastillo.com/free-plugins/eazyest-gallery-iptc-caption
Description: Use the IPTC Title as your image captions for Eazyest Gallery images.
Version: 1.0-beta-24
Author: Isabel Castillo
Author URI: http://isabelcastillo.com
License: GPL2
Text Domain: eazyest-gallery-iptc-caption
Domain Path: languages
*
* Copyright 2014 - 2015 Isabel Castillo

* Eazyest Gallery IPTC Caption 2 is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 2 of the License, or
* any later version.
*
* Eazyest Gallery IPTC Caption 2 is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with Eazyest Gallery IPTC Caption 2. If not, see <http://www.gnu.org/licenses/>.
*/

class Eazyest_Gallery_IPTC_Caption_2 {

	private static $instance = null;
	public static function get_instance() {
		if ( null == self::$instance ) {
			self::$instance = new self;
		}
		return self::$instance;
	}

	private function __construct() {

		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'admin_menu', array($this, 'add_plugin_page' ) );
		add_action('admin_enqueue_scripts', array( $this, 'enqueue' ) ) ;
		add_action( 'wp_ajax_egiptc_caption_update', array( $this, 'ajax_process_image' ) );

	}
	
	public function load_textdomain() {
		load_plugin_textdomain( 'eazyest-gallery-iptc-caption', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
	}

	/**
	 * Get all post ids of type galleryfolder
	 * @return array of galleryfolder post ids
	 */
	private function get_galleryfolder_ids() {

		// Get all posts of type galleryfolder
		$query_results = new WP_Query(
			array(
				'post_type' => 'galleryfolder',
				'post_status' => array( 'publish', 'private' ),
				'cache_results' => false,// speeds up query since we bypass the extra caching queries
				'no_found_rows' => true,// bypass counting the results to see if we need pagination or not,
				'nopaging' => true,
				'fields' => 'ids'
			)
		);
		return $query_results->posts;
	}

	/**
	 * Get all image attachments of Eazyest Gallery galleryfolders.
	 * @return array of image ids
	 */
	private function get_eg_image_ids() {

		$galleryfolders = $this->get_galleryfolder_ids();
		if ( ( ! $galleryfolders ) || ( ! is_array($galleryfolders) ) ) {
			return false;
		}

		// get all images of EG galleryfolders
		$image_query = new WP_Query(
			array(
			'post_type' => 'attachment',
			'post_status' => 'inherit',
			'post_mime_type' => 'image',
			'cache_results' => false,// speeds up query since we bypass the extra caching queries
			'no_found_rows' => true,// bypass counting the results to see if we need pagination or not
			'nopaging' => true,
			'post_parent__in' => $galleryfolders,
			// 'meta_query' => array(
			// 		array(
			// 			'key'     => 'egiptc_complete',// @test exclude completed images
			// 			'compare' => 'NOT EXISTS',
			// 		),
			// ),
			'fields' => 'ids',
			'orderby' => 'none'
			)
		);
		return $image_query->posts;
	}

	/**
	* Add the plugin page under the Eazyest Gallery menu
	* @since 0.1
	*/
	function add_plugin_page(){
		add_submenu_page( 'edit.php?post_type=galleryfolder', __('Eazyest Gallery IPTC Caption', 'eazyest-gallery-iptc-caption'), __('IPTC Caption', 'eazyest-gallery-iptc-caption'), 'manage_options', 'eg-iptc-caption', array($this, 'create_admin_page') );

    }

	/**
	* HTML for the plugin page
	* @since 0.1
	*/
	function create_admin_page(){ ?>
		<div class="wrap">
		<div id="message" class="updated fade" style="display:none"></div>
		<?php screen_icon(); ?>
		<h2><?php _e( 'Eazyest Gallery IPTC Title', 'eazyest-gallery-iptc-caption'); ?></h2>
		<?php 
		// If the button was clicked
		if ( ! empty( $_POST['eg-iptc-caption'] ) || ! empty( $_REQUEST['ids'] ) ) {


			check_admin_referer( 'eg_iptc_caption_nonce', 'eg_iptc_caption_nonce' );

			// Create the list of image IDs
			if ( ! empty( $_REQUEST['ids'] ) ) {
				$ids_array = array_map( 'intval', explode( ',', trim( $_REQUEST['ids'], ',' ) ) );
				$ids = implode( ',', $ids_array );
			} else {

				$ids_array = $this->get_eg_image_ids();

				if ( ! $ids_array ) {
					echo '	<p>' . sprintf( __( "Unable to find any images. Are you sure <a href='%s'>some exist</a>?", 'eazyest-gallery-iptc-caption' ), admin_url( 'upload.php?post_mime_type=image' ) ) . "</p></div>";

					return;
				}

				$ids = implode( ',', $ids_array );
			}

			echo '	<p>' . __( "Please be patient while the image captions are updated. This can take a while if your server is slow (inexpensive hosting) or if you have many images. Do not navigate away from this page until this script is done or the image captions will not be updated. You will be notified via this page when the updates are completed.", 'eazyest-gallery-iptc-caption' ) . '</p>';

			$count = count( $ids_array );

			$text_goback = ( ! empty( $_GET['goback'] ) ) ? sprintf( __( 'To go back to the previous page, <a href="%s">click here</a>.', 'eazyest-gallery-iptc-caption' ), 'javascript:history.go(-1)' ) : '';

			$text_abort = ( ! empty( $_POST['egiptc-caption-stop'] ) ) ? sprintf( __( 'Aborted! To continue updating the incomplete images, <a href="%1$s">click here</a>.', 'eazyest-gallery-iptc-caption' ), esc_url( wp_nonce_url( admin_url( 'edit.php?post_type=galleryfolder&page=eg-iptc-caption&goback=1' ), 'eg_iptc_caption_nonce', 'eg_iptc_caption_nonce' ) . '&ids=' ) . "' + egiptc_failedlist + ',' + egiptc_images + '" ) : '';

			$text_failures = sprintf( __( '%6$s All done! %1$s image(s) were successfully updated in %2$s seconds and there were %3$s failure(s). To try updating the failed images again, <a href="%4$s">click here</a>. %5$s', 'eazyest-gallery-iptc-caption' ), "' + egiptc_successes + '", "' + egiptc_totaltime + '", "' + egiptc_errors + '", esc_url( wp_nonce_url( admin_url( 'edit.php?post_type=galleryfolder&page=eg-iptc-caption&goback=1' ), 'eg_iptc_caption_nonce', 'eg_iptc_caption_nonce' ) . '&ids=' ) . "' + egiptc_failedlist + ',' + egiptc_images + '", $text_goback, $text_abort );


			$text_nofailures = sprintf( __( '%4$s All done! %1$s image(s) were successfully updated in %2$s seconds and there were 0 failures. %3$s', 'eazyest-gallery-iptc-caption' ), "' + egiptc_successes + '", "' + egiptc_totaltime + '", $text_goback, $text_abort );


			?>			
			<noscript><p><em><?php _e( 'You must enable Javascript in order to proceed!', 'eazyest-gallery-iptc-caption' ) ?></em></p></noscript>

			<div id="egiptc-caption-bar" style="position:relative;height:25px;">
				<div id="egiptc-caption-bar-percent" style="position:absolute;left:50%;top:50%;width:300px;margin-left:-150px;height:25px;margin-top:-9px;font-weight:bold;text-align:center;"></div>
			</div>
			<form method="post" action="">
			<p><input type="submit" class="button hide-if-no-js" name="egiptc-caption-stop" id="egiptc-caption-stop" value="<?php _e( 'Abort Resizing Images', 'eazyest-gallery-iptc-caption' ) ?>" /></p>
			</form>
			<h3 class="title"><?php _e( 'Debugging Information', 'eazyest-gallery-iptc-caption' ) ?></h3>

			<p>
			<?php printf( __( 'Total Eazyest Gallery Images: %s', 'eazyest-gallery-iptc-caption' ), $count ); ?><br />
			<?php printf( __( 'Image Captions Updated: %s', 'eazyest-gallery-iptc-caption' ), '<span id="egiptc-caption-debug-successcount">0</span>' ); ?><br />
			<?php printf( __( 'Caption Update Failures: %s', 'eazyest-gallery-iptc-caption' ), '<span id="egiptc-caption-debug-failurecount">0</span>' ); ?>
			</p>

			<ol id="egiptc-caption-debuglist">
				<li style="display:none"></li>
			</ol>


		<script type="text/javascript">
		jQuery(document).ready(function($){
			var i;
			var egiptc_images = [<?php echo $ids; ?>];
			var egiptc_total = egiptc_images.length;
			var egiptc_count = 1;
			var egiptc_percent = 0;
			var egiptc_successes = 0;
			var egiptc_errors = 0;
			var egiptc_failedlist = '';
			var egiptc_resulttext = '';
			var egiptc_timestart = new Date().getTime();
			var egiptc_timeend = 0;
			var egiptc_totaltime = 0;
			var egiptc_continue = true;
			// Create the progress bar
			$("#egiptc-caption-bar").progressbar();
			$("#egiptc-caption-bar-percent").html( "0%" );
			
			// Stop button
			$("#egiptc-caption-stop").click(function() {
				egiptc_continue = false;
				$('#egiptc-caption-stop').val("<?php echo $this->esc_quotes( __( 'Stopping...', 'eazyest-gallery-iptc-caption' ) ); ?>");

			});

			// Clear out the empty list element that's there for HTML validation purposes
			$("#egiptc-caption-debuglist li").remove();
			
			// Called after each batch of image caption updates. Updates debug information and the progress bar.
			function EGiptcCaptionUpdateStatus( response, success ) {

				$("#egiptc-caption-bar").progressbar( "value", ( egiptc_count / egiptc_total ) * 100 );
				$("#egiptc-caption-bar-percent").html( Math.round( ( egiptc_count / egiptc_total ) * 1000 ) / 10 + "%" );

				// always increment status bar counter
				egiptc_count = egiptc_count + 50;

				// increment failure count
				egiptc_errors = response.failure_count ? egiptc_errors + response.failure_count : egiptc_errors;


				// add failed ids to list for retrying
				egiptc_failedlist = response.failed_ids ? egiptc_failedlist + ',' + response.failed_ids : egiptc_failedlist;

				// increment success count
								
				if (success) {
					egiptc_successes = response.success_count ? egiptc_successes + response.success_count : egiptc_successes;
				} else {

					// ajax response error, count all 50 images as failures.
					egiptc_errors = egiptc_errors + 50;

				}

				$("#egiptc-caption-debug-successcount").html(egiptc_successes);
				if(response.success) {
					$("#egiptc-caption-debuglist").append("<li>" + response.success + "</li>");
				}

				$("#egiptc-caption-debug-failurecount").html(egiptc_errors);
				if (response.error) {
					$("#egiptc-caption-debuglist").append("<li>" + response.error + "</li>");	
				}
				
				
			}
			// Called when all images have been processed. Shows the results and cleans up.
			function EGiptcCaptionFinishUp() {

				egiptc_timeend = new Date().getTime();
				egiptc_totaltime = Math.round( ( egiptc_timeend - egiptc_timestart ) / 1000 );
				$('#egiptc-caption-stop').hide();
				if ( egiptc_errors > 0 ) {
					egiptc_resulttext = '<?php echo $text_failures; ?>';
				} else {
					egiptc_resulttext = '<?php echo $text_nofailures; ?>';
				}
				$("#message").html("<p><strong>" + egiptc_resulttext + "</strong></p>");
				$("#message").show();
			}

			// Update the caption for a specified image batch via AJAX
			function UpdateCaptions( batch ) {

				// convert array to comma list
				var ids_batch = batch.join();

				$.ajax({
					type: 'POST',
					url: ajaxurl,
					data: { action: "egiptc_caption_update", ids_batch: ids_batch },
					success: function( response ) {
						if ( response === null ) {
							response = new Object;
							response.success = false;
							response.error = "The caption update request was abnormally terminated. This is likely due to the image exceeding available memory.";
						}
						
						EGiptcCaptionUpdateStatus( response, true );
						
						if ( egiptc_images.length && egiptc_continue ) {
							UpdateCaptions( egiptc_images.splice(0, 50) );
						} else {
							EGiptcCaptionFinishUp();
						}
					},
					error: function( response ) {
						EGiptcCaptionUpdateStatus( response, false );
						if ( egiptc_images.length && egiptc_continue ) {
							UpdateCaptions( egiptc_images.splice(0, 50) );
						} else {
							EGiptcCaptionFinishUp();
						}
					}
				});
			}
			UpdateCaptions( egiptc_images.splice(0, 50) );		
		});
		</script>

	<?php
		}
		// No button click? Display the form.
		else {
			?>

		<p><?php _e('Here you can update the caption for all the image atttachments in your Eazyest Gallery. If an image has an IPTC title, then that will become the new caption. If an image does not have an IPTC title, then it will keep its existing caption.', 'eazyest-gallery-iptc-caption' ); ?></p>
		
		<p><?php _e('When you are ready for the plugin to update the captions, click "Update Captions".', 'eazyest-gallery-iptc-caption' ); ?></p>

		<p><?php _e('Please be patient after you click the button below. It could take a while if you have many images.', 'eazyest-gallery-iptc-caption' ); ?></p>		

		<p><?php printf( __('%sNote:%s if you later add new images to Eazyest Gallery, they will not be affected by this update. If you want your new images to use the IPTC title as caption, then you will have to "Update Captions" again after you add the new pictures.', 'eazyest-gallery-iptc-caption' ), '<strong>', '</strong>' ); ?></p>

			<form method="post" action="">
			<?php wp_nonce_field('eg_iptc_caption_nonce', 'eg_iptc_caption_nonce' ); ?>

			<p><input type="submit" class="button hide-if-no-js" name="eg-iptc-caption" id="eg-iptc-caption" value="<?php _e( 'Update Captions', 'eazyest-gallery-iptc-caption' ) ?>" /></p>

				<noscript><p><em><?php _e( 'You must enable Javascript in order to proceed!', 'eazyest-gallery-iptc-caption' ) ?></em></p></noscript>

			</form>
			<?php



		} // End if button
		?>
		</div>
		<?php
    }

	function enqueue( $hook ) {
		if ('galleryfolder_page_eg-iptc-caption' != $hook) {
			return;
		}
		wp_enqueue_script( 'jquery-ui-progressbar' );
		wp_enqueue_style( 'jquery-ui-egiptc', plugins_url( 'jquery-ui/redmond/jquery-ui-1.7.2.custom.css', __FILE__ ), array(), '1.7.2' );

	}

	/**
	 * Ajax Handler to Process an array of image IDs.
	 * Loop through image IDs and if the image has an IPTC/exif title, the caption will be updated with it.
	 */
	public function ajax_process_image() {
		@error_reporting( 0 ); // Don't break the JSON result
		header( 'Content-type: application/json' );

		@set_time_limit( 900 );

		$ids = array_map( 'intval', explode( ',', trim( $_REQUEST['ids_batch'], ',' ) ) );

		$errors = '';
		$failed_ids = array();
		$updated_images = array();
		$no_exif_titles = array();

		foreach ($ids as $id) {
		
			// get image metadata
			$metadata = wp_get_attachment_metadata( $id );
			if ( $metadata ) {
				$img_meta = isset($metadata['image_meta']) ? $metadata['image_meta'] : '';
				if ( $img_meta ) {
					$iptc_title = isset($img_meta['title']) ? $img_meta['title'] : '';
					
					// only update image if iptc title exists
					if ($iptc_title) {

							// update the caption with the IPTC/exif title
							$new_post_data = array(
								'ID'           => $id,
								'post_excerpt' => $iptc_title
							);
							$the_image = wp_update_post( $new_post_data, true );

							if ( is_wp_error( $the_image ) ) {

								$errors .= ' ' . sprintf( __( 'Image ID %1$s failed to update. The error message was: %1$s', 'eazyest-gallery-iptc-caption' ), $id, $the_image->get_error_message() );
								$failed_ids[] = $id;

							} elseif ( empty( $the_image ) ) {


								$errors .= ' ' . sprintf( __( 'Image ID %1$s failed to update. The error message was: Unknown failure reason.', 'eazyest-gallery-iptc-caption' ), $id );

								$failed_ids[] = $id;
															
							} else {

								$updated_images[] = array($id, esc_html( get_the_title( $id ) ) );

								// add post meta to remove this from array 'to be updated' for next time
								// update_post_meta($id, 'egiptc_complete', 'complete');// @test

							}

	 				} else {

	 					// no iptc/exif title
	 					$no_exif_titles[] = array($id);

						// add post meta to remove this from array 'to be updated' for next time
						// update_post_meta($id, 'egiptc_complete', 'complete');// @test			
	 				}
				} else {

					// does not have image meta
 					$no_exif_titles[] = array($id);

					// add post meta to remove this from array 'to be updated' for next time
					// update_post_meta($id, 'egiptc_complete', 'complete');// @test
				}
			
			} else {

				// does not have attachment metadata
				$no_exif_titles[] = array($id);

				// add post meta to remove this from array 'to be updated' for next time
				// update_post_meta($id, 'egiptc_complete', 'complete');// @test
			}

		}

		$failure_count = count( $failed_ids );
		$success_count = count( $updated_images );
		$failed_ids = implode( ',', $failed_ids );

		$error_addon = $errors ? sprintf( __( '%1$s image(s) failed to update due to errors which are listed here:  %2$s.', 'eazyest-gallery-iptc-caption' ), $failure_count, $errors ) : '';

		$msg = sprintf( __('Batch: %1$s image(s) were updated, %2$s did not have IPTC/EXIF titles. %3$s', 'eazyest-gallery-iptc-caption' ), $success_count, count( $no_exif_titles ), $error_addon );

		die( json_encode( array( 'success' => $msg, 'failure_count' => $failure_count, 'success_count' => $success_count, 'failed_ids' => $failed_ids ) ) );
	}

	// Escape quotes in strings for use in Javascript
	public function esc_quotes( $string ) {
		return str_replace( '"', '\"', $string );
	}	

}
$eg_iptc_caption = Eazyest_Gallery_IPTC_Caption_2::get_instance();
